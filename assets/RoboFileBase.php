<?php

/**
 * @file
 * Contains \Robo\RoboFileBase.
 *
 * Implementation of class for Robo - http://robo.li/
 */

declare(strict_types=1);

use Robo\Tasks;
use Robo\Result;
use Robo\ResultData;

/**
 * Implement specific functions to enable Drupal install builds.
 */
abstract class RoboFileBase extends Tasks {

  /**
   * The drupal profile to install.
   *
   * @var string
   */
  protected string $drupalProfile;

  /**
   * The application root.
   *
   * @var string
   */
  protected string $applicationRoot = '/code/web';

  /**
   * The path to the public files directory.
   *
   * @var array
   */
  protected array $filePaths = [
    '/shared/public',
    '/shared/private',
    '/shared/tmp',
  ];

  /**
   * Shared filesystem prefix for tmp dir/testing.
   *
   * @var string
   */
  protected string $sharedPrefix = '';

  /**
   * The path to the services file.
   *
   * @var string
   */
  protected string $servicesYml = 'web/sites/default/services.yml';

  /**
   * The config we're going to export.
   *
   * @var array
   */
  protected array $config = [];

  /**
   * Initialize config variables and apply overrides.
   */
  public function __construct() {
    // Read config from environment variables.
    $environmentConfig = $this->readConfigFromEnv();
    $this->config = array_merge($this->config, $environmentConfig);

    // Get the drupal profile.
    return $this->setDrupalProfile();
  }

  /**
   * Load the install profile.
   */
  protected function setDrupalProfile() {
    $profile = getenv('SHEPHERD_INSTALL_PROFILE');
    if (empty($profile)) {
      return new ResultData(1, 'SHEPHERD_INSTALL_PROFILE environment variable not defined.');
    }

    $this->drupalProfile = $profile;
  }

  /**
   * Retrieve the site UUID.
   *
   * Once config is set, its likely that the UUID will need to be consistent
   * across builds. This function is used as part of the build to get that
   * from an environment var, often defined in the docker-compose.*.yml file.
   *
   * @return string|bool
   *   Return either a valid site uuid, or false if there is none.
   */
  protected function getSiteUuid() {
    return getenv('SITE_UUID');
  }

  /**
   * Returns known configuration from environment variables.
   *
   * Runs during the constructor; be careful not to use Robo methods.
   *
   * @return array
   *   The sanitised config array.
   */
  protected function readConfigFromEnv(): array {
    $config = [];

    // Site.
    $config['site']['title']          = getenv('SITE_TITLE');
    $config['site']['mail']           = getenv('SITE_MAIL');
    $config['site']['admin_email']    = getenv('SITE_ADMIN_EMAIL');
    $config['site']['admin_user']     = getenv('SITE_ADMIN_USERNAME');
    $config['site']['admin_password'] = getenv('SITE_ADMIN_PASSWORD');

    // Environment.
    $config['environment']['hash_salt'] = getenv('HASH_SALT');

    // Allow for shared file prefix.
    $this->sharedPrefix = getenv("SHARED_PREFIX") ?: '';

    // Clean up NULL values and empty arrays.
    $arrayClean = static function (&$item) use (&$arrayClean) {
      foreach ($item as $key => $value) {
        if (is_array($value)) {
          $arrayClean($value);
        }
        if (empty($value) && $value !== '0') {
          unset($item[$key]);
        }
      }
    };

    $arrayClean($config);

    return $config;
  }

  /**
   * Perform a full build on the project.
   */
  public function build(): Result {
    $collection = $this->collectionBuilder();
    $collection->addTaskList($this->buildTasks());
    return $collection->run();
  }

  /**
   * Return the list of tasks to build.
   *
   * @return array The tasks required to build.
   *   The tasks required to build.
   */
  public function buildTasks(): array {
    $tasks = [];

    $tasks[] = $this->devXdebugDisable();
    $tasks[] = $this->taskComposerValidate()->noCheckPublish();
    $tasks[] = $this->taskComposerInstall()->noInteraction();
    $tasks[] = $this->buildSetFilesOwner();
    $tasks[] = $this->buildInstall();

    // If the SITE_UUID is set, set the newly built site to have the same id.
    if ($uuid = $this->getSiteUuid()) {
      $tasks[] = $this->_exec("drush config-set system.site uuid $uuid -y");
      $tasks[] = $this->devCacheRebuild();

      // Unless IMPORT_CONFIG=false is set, import the config-sync dir.
      if (getenv('IMPORT_CONFIG') !== 'false') {
        $tasks[] = $this->_exec("drush cim -y --partial");
      }
    }

    $tasks[] = $this->devCacheRebuild();
    $tasks[] = $this->buildSetFilesOwner();
    $tasks[] = $this->devXdebugEnable();

    return $tasks;
  }

  /**
   * Set the owner and group of all files in the files dir to the web user.
   */
  public function buildSetFilesOwner(): ResultData {
    $this->say('Setting ownership and permissions.');
    foreach ($this->filePaths as $path) {
      $path = $this->sharedPrefix . $path;
      $stack = $this->taskFilesystemStack()
        ->stopOnFail()
        ->mkdir($path)
        ->chmod($path, 0755, 0000);

      $result = $stack->run();
      if (!$result->wasSuccessful()) {
        return new ResultData(1, 'File ownership failed.');
      }
    }

    return new ResultData(TRUE);
  }

  /**
   * Clean config and files, then install Drupal and module dependencies.
   */
  public function buildInstall(): ResultData {
    // Ensure configuration is writable.
    $this->devConfigWriteable();

    $stack = $this->taskExec('drush')
      ->arg('site-install')
      ->arg($this->drupalProfile)
      ->arg('-y')
      ->arg('install_configure_form.enable_update_status_module=NULL')
      ->arg('install_configure_form.enable_update_status_emails=NULL')
      ->option('account-mail', $this->config['site']['admin_email'])
      ->option('account-name', $this->config['site']['admin_user'])
      ->option('account-pass', $this->config['site']['admin_password'])
      ->option('site-name', $this->config['site']['title'])
      ->option('site-mail', $this->config['site']['mail']);

    $result = $stack->run();
    $this->devConfigReadOnly();
    if (!$result->wasSuccessful()) {
      return new ResultData(1, 'drush site-install failed.');
    }

    $this->devCacheRebuild();

    return new ResultData(TRUE);
  }

  /**
   * Set the RewriteBase value in .htaccess appropriate for the site.
   */
  public function setSitePath() {
    if (!empty($this->config['site']['path'])) {
      $this->say("Setting site path.");
      return $this->taskReplaceInFile("$this->applicationRoot/.htaccess")
        ->from('# RewriteBase /drupal')
        ->to("\n  RewriteBase /" . ltrim($this->config['site']['path'], '/') . "\n");
    }
  }

  /**
   * Clean the application root in preparation for a new build.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function buildClean(): ResultData {
    $this->setPermissions("$this->applicationRoot/sites/default", 0755);
    $stack = $this->taskExecStack()
      ->stopOnFail()
      ->exec("rm -fR $this->applicationRoot/core")
      ->exec("rm -fR $this->applicationRoot/modules/contrib")
      ->exec("rm -fR $this->applicationRoot/profiles/contrib")
      ->exec("rm -fR $this->applicationRoot/themes/contrib")
      ->exec("rm -fR $this->applicationRoot/sites/all")
      ->exec('rm -fR bin')
      ->exec('rm -fR vendor');

    $result = $stack->run();
    if (!$result->wasSuccessful()) {
      return new ResultData(1, 'Build clean failed.');
    }

    return new ResultData(TRUE);
  }

  /**
   * Run all the drupal updates against a build.
   */
  public function buildUpdate() {
    // Run the module updates.
    return $this->checkFail($this->_exec('drush -y updatedb')->wasSuccessful(), 'Running drupal updates failed.');
  }

  /**
   * Perform cache clear in the app directory.
   */
  public function devCacheRebuild() {
    return $this->checkFail($this->_exec('drush cr')->wasSuccessful(), 'Running cache-rebuild failed.');
  }

  /**
   * CLI debug enable.
   */
  public function devXdebugEnable() {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      return $this->taskExec('sudo phpenmod -v ALL -s cli xdebug');
    }
  }

  /**
   * CLI debug disable.
   */
  public function devXdebugDisable() {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      return $this->taskExec('sudo phpdismod -v ALL -s cli xdebug');
    }
  }

  /**
   * Turns on twig debug mode, auto reload on and caching off.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function devTwigDebugEnable() {
    $tasks = [];

    $tasks[] = $this->devConfigWriteable();
    $tasks[] = $this->devAggregateAssetsDisable(FALSE);
    $tasks[] = $this->devUpdateServices(TRUE);
    $tasks[] = $this->devConfigReadOnly();
    $tasks[] = $this->devCacheRebuild();

    return $tasks;
  }

  /**
   * Turn off twig debug mode, autoreload off and caching on.
   */
  public function devTwigDebugDisable() {
    $tasks = [];

    $tasks[] = $this->devConfigWriteable();
    $tasks[] = $this->devUpdateServices(FALSE);
    $tasks[] = $this->devConfigReadOnly();
    $tasks[] = $this->devCacheRebuild();

    return $tasks;
  }

  /**
   * Replace strings in the services yml.
   *
   * @param bool $enable
   *   Whether to disable or enable debug parameters.
   */
  private function devUpdateServices($enable = TRUE) {
    $replacements = [
      ['debug: false', 'debug: true'],
      ['auto_reload: null', 'auto_reload: true'],
      ['cache: true', 'cache: false'],
    ];

    if ($enable) {
      $new = 1;
      $old = 0;
    }
    else {
      $new = 0;
      $old = 1;
    }

    foreach ($replacements as $values) {
      $this->taskReplaceInFile($this->servicesYml)
        ->from($values[$old])
        ->to($values[$new])
        ->run();
    }
  }

  /**
   * Disable asset aggregation.
   *
   * @param bool $cacheClear
   *   Whether to clear the cache after changes.
   *
   * @return \Robo\ResultData
   *   The result of the command.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function devAggregateAssetsDisable($cacheClear = TRUE) {
    $stack = $this->taskExecStack()
      ->stopOnFail()
      ->exec('drush cset system.performance js.preprocess 0 -y')
      ->exec('drush cset system.performance css.preprocess 0 -y');

    $result = $stack->run();
    if (!$result->wasSuccessful()) {
      return new ResultData(1, 'Aggregate disable failed.');
    }

    if ($cacheClear) {
      $this->devCacheRebuild();
    }
    return new ResultData(TRUE);
  }

  /**
   * Enable asset aggregation.
   *
   * @param bool $cacheClear
   *   Whether to clear the cache after changes.
   *
   * @return \Robo\ResultData
   *   The result of the command.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function devAggregateAssetsEnable($cacheClear = TRUE) {
    $stack = $this->taskExecStack()
      ->stopOnFail()
      ->exec('drush cset system.performance js.preprocess 1 -y')
      ->exec('drush cset system.performance css.preprocess 1 -y');

    $result = $stack->run();
    if (!$result->wasSuccessful()) {
      return new ResultData(1, 'Aggregate enable failed.');
    }

    if ($cacheClear) {
      $this->devCacheRebuild();
    }
    return new ResultData(TRUE);
  }

  /**
   * Make config files write-able.
   */
  public function devConfigWriteable() {
    $tasks = [];

    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default/services.yml", 0664);
    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default/settings.php", 0664);
    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default/settings.local.php", 0664);
    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default", 0775);

    return $tasks;
  }

  /**
   * Make config files read only.
   */
  public function devConfigReadOnly() {
    $tasks = [];

    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default/services.yml", 0444);
    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default/settings.php", 0444);
    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default/settings.local.php", 0444);
    $tasks[] = $this->setPermissions("$this->applicationRoot/sites/default", 0555);

    return $tasks;
  }

  /**
   * Imports a database, updates the admin user password and applies updates.
   *
   * @param string $sqlFile
   *   Path to sql file to import.
   */
  public function devImportDb(string $sqlFile) {
    $tasks = [];

    $tasks[] = $this->taskExec('drush -y sql-drop');
    $tasks[] = $this->taskExec("drush sqlq --file=$sqlFile");
    $tasks[] = $this->taskExec('drush cr');
    $tasks[] = $this->devResetAdminPass();

    return $tasks;
  }

  /**
   * Find the userame of user 1 which is the 'admin' user for Drupal.
   */
  public function devResetAdminPass() {
    // Retrieve the name of the user.
    $command = $this->taskExec('drush sqlq "SELECT name from users u LEFT JOIN users_field_data ud ON u.uid = ud.uid WHERE u.uid = 1"')
      ->printOutput(FALSE)
      ->run();
    $adminUser = trim($command->getMessage());

    // Perform the password reset.
    $command = $this->taskExec("drush upwd $adminUser password");
    return $command->run();
  }

  /**
   * Exports a database and gzips the sql file.
   *
   * @param string $name
   *   Name of sql file to be exported.
   */
  public function devExportDb(string $name = 'dump'): Tasks {
    $tasks = [];

    $tasks[] = $this->taskExec("drush sql-dump --gzip --result-file=$name.sql");

    return $tasks;
  }

  /**
   * Run coding standards checks for PHP files on the project.
   *
   * @param string $path
   *   An optional path to lint.
   */
  public function lintPhp($path = '') {
    $this->_exec("phpcs $path")->wasSuccessful();
    $this->_exec("phpstan analyze --no-progress")->wasSuccessful();
  }

  /**
   * Fix coding standards violations for PHP files on the project.
   *
   * @param string $path
   *   An optional path to fix.
   */
  public function lintFix($path = '') {
    $this->_exec("phpcbf $path")->wasSuccessful();
  }

  /**
   * Check if file exists and set permissions.
   *
   * @param string $file
   *   File to modify.
   * @param int $permission
   *   Permissions. E.g. '0644'.
   */
  protected function setPermissions(string $file, int $permission) {
    if (file_exists($file)) {
      return $this->taskFilesystemStack()
        ->stopOnFail()
        ->chmod($file, (int) $permission, 0000)
        ->run();
    }
  }

}
