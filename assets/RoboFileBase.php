<?php

/**
 * @file
 * Contains \Robo\RoboFileBase.
 *
 * Implementation of class for Robo - http://robo.li/
 */

declare(strict_types=1);

use Robo\Tasks;
use Robo\ResultData;

/**
 * Class RoboFileBase.
 */
abstract class RoboFileBase extends Tasks {

  /**
   * The drupal profile to install.
   *
   * @var string
   */
  protected $drupalProfile;

  /**
   * The current user.
   *
   * @var string
   */
  protected $localUser;

  /**
   * The web server user.
   *
   * @var string
   */
  protected $webUser = 'www-data';

  /**
   * The application root.
   *
   * @var string
   */
  protected $applicationRoot = '/code/web';

  /**
   * The path to the public files directory.
   *
   * @var string
   */
  protected $filePublic = '/shared/public';

  /**
   * The path to the private files directory.
   *
   * @var string
   */
  protected $filePrivate = '/shared/private';

  /**
   * The path to the temporary files directory.
   *
   * @var string
   */
  protected $fileTemp = '/shared/tmp';

  /**
   * The path to the services file.
   *
   * @var string
   */
  protected $servicesYml = 'web/sites/default/services.yml';

  /**
   * The config we're going to export.
   *
   * @var array
   */
  protected $config = [];

  /**
   * The path to the config dir.
   *
   * @var string
   */
  protected $configDir = '/code/config-export';

  /**
   * The path to the config install dir.
   *
   * @var string
   */
  protected $configInstallDir = '/code/config-install';

  /**
   * The path to the config delete list.
   *
   * @var string
   */
  protected $configDeleteList = '/code/drush/config-delete.yml';

  /**
   * The path to the config delete list.
   *
   * @var string
   */
  protected $configIgnoreList = '/code/drush/config-ignore.yml';

  /**
   * Initialize config variables and apply overrides.
   */
  public function __construct() {
    // Retrieve current username.
    $this->localUser = $this->getLocalUser();

    // Read config from environment variables.
    $environmentConfig = $this->readConfigFromEnv();
    $this->config = array_merge($this->config, $environmentConfig);

    // Get the drupal profile.
    $this->getDrupalProfile();
  }

  /**
   * Force projects to declare which install profile to use.
   */
  protected function getDrupalProfile(): ?ResultData {
    $profile = getenv('SHEPHERD_INSTALL_PROFILE');
    if (empty($profile)) {
      return new ResultData(1, "Install profile environment variable is not set.");
    }

    $this->drupalProfile = $profile;
    return new ResultData(TRUE);
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

    // Clean up NULL values and empty arrays.
    $array_clean = static function (&$item) use (&$array_clean) {
      foreach ($item as $key => $value) {
        if (is_array($value)) {
          $array_clean($item[$key]);
        }
        if (empty($item[$key]) && $value !== '0') {
          unset($item[$key]);
        }
      }
    };

    $array_clean($config);

    return $config;
  }

  /**
   * Perform a full build on the project.
   */
  public function build(): void {
    $start = new DateTime();
    $this->devXdebugDisable();
    $this->devComposerValidate();
    $this->buildComposerInstall();
    $this->buildSetFilesOwner();
    $this->buildInstall();
    $this->devCacheRebuild();
    $this->buildSetFilesOwner();
    $this->devXdebugEnable();
    $this->say('Total build duration: ' . date_diff(new DateTime(), $start)->format('%im %Ss'));
  }

  /**
   * Perform a build for automated deployments.
   *
   * Don't install anything, just build the code base.
   */
  public function distributionBuild(): void {
    $this->devComposerValidate();
    $this->buildComposerInstall('--prefer-dist --no-suggest --no-dev --optimize-autoloader');
    $this->setSitePath();
  }

  /**
   * Validate composer files and installed dependencies with strict mode off.
   */
  public function devComposerValidate(): void {
    $this->taskComposerValidate()
      ->noCheckPublish()
      ->run()
      ->stopOnFail();
  }

  /**
   * Run composer install to fetch the application code from dependencies.
   *
   * @param string $flags
   *   Additional flags to pass to the composer install command.
   *
   * @return \Robo\ResultData|null
   *   Return whether the install succeeded.
   */
  public function buildComposerInstall($flags = ''): ?ResultData {
    try {
      $command = $this->taskExec('composer')
        ->arg('install')
        ->option('no-progress')
        ->option('no-interaction');

      if (!empty($flags)) {
        $command->arg($flags);
      }
      $command->run();
    }
    catch (Exception $e) {
      return new ResultData(1, 'Composer install failed.');
    }

    return new ResultData(TRUE);
  }

  /**
   * Set the owner and group of all files in the files dir to the web user.
   */
  public function buildSetFilesOwner(): ?ResultData {
    $this->say('Setting ownership and permissions.');
    foreach ([$this->filePublic, $this->filePrivate, $this->fileTemp] as $path) {
      try {
        $this->taskFilesystemStack()
          ->mkdir($path)
          ->chown($path, $this->webUser)
          ->chgrp($path, $this->localUser)
          ->chmod($path, 0755, 0000)
          ->run();
      }
      catch (Exception $e) {
        return new ResultData(1, 'File ownership failed.');
      }
    }

    return new ResultData(TRUE);
  }

  /**
   * Clean config and files, then install Drupal and module dependencies.
   */
  public function buildInstall(): ?ResultData {
    // Ensure configuration is writable.
    $this->devConfigWriteable();

    try {
      $this->taskExec('drush')
        ->arg('site-install')
        ->arg($this->drupalProfile)
        ->arg('-y')
        ->arg('install_configure_form.enable_update_status_module=NULL')
        ->arg('install_configure_form.enable_update_status_emails=NULL')
        ->option('account-mail', $this->config['site']['admin_email'])
        ->option('account-name', $this->config['site']['admin_user'])
        ->option('account-pass', $this->config['site']['admin_password'])
        ->option('site-name', $this->config['site']['title'])
        ->option('site-mail', $this->config['site']['mail'])
        ->run();
      $this->devConfigReadOnly();
    }
    catch (Exception $e) {
      $this->devConfigReadOnly();
      return new ResultData(1, 'drush site-install failed.');
    }

    $this->devCacheRebuild();

    return new ResultData(TRUE);
  }

  /**
   * Set the RewriteBase value in .htaccess appropriate for the site.
   */
  public function setSitePath(): ?ResultData {
    if (!empty($this->config['site']['path'])) {
      $this->say("Setting site path.");
      $successful = $this->taskReplaceInFile("$this->applicationRoot/.htaccess")
        ->from('# RewriteBase /drupal')
        ->to("\n  RewriteBase /" . ltrim($this->config['site']['path'], '/') . "\n")
        ->run();

      return $this->checkFail($successful, "Couldn't update .htaccess file with path.");
    }

    return new ResultData(TRUE);
  }

  /**
   * Clean the application root in preparation for a new build.
   */
  public function buildClean(): ?ResultData {
    $this->setPermissions("$this->applicationRoot/sites/default", 0755);
    try {
      $this->taskExecStack()
        ->exec("rm -fR $this->applicationRoot/core")
        ->exec("rm -fR $this->applicationRoot/modules/contrib")
        ->exec("rm -fR $this->applicationRoot/profiles/contrib")
        ->exec("rm -fR $this->applicationRoot/themes/contrib")
        ->exec("rm -fR $this->applicationRoot/sites/all")
        ->exec('rm -fR bin')
        ->exec('rm -fR vendor')
        ->run();
    }
    catch (Exception $e) {
      return new ResultData(1, 'Build failed.');
    }

    return new ResultData(TRUE);
  }

  /**
   * Run all the drupal updates against a build.
   */
  public function buildUpdate(): ResultData {
    // Run the module updates.
    return $this->checkFail($this->_exec('drush -y updatedb')->wasSuccessful(), 'Running drupal updates failed.');
  }

  /**
   * Perform cache clear in the app directory.
   */
  public function devCacheRebuild(): ResultData {
    return $this->checkFail($this->_exec('drush cr')->wasSuccessful(), 'Running cache-rebuild failed.');
  }

  /**
   * CLI debug enable.
   */
  public function devXdebugEnable(): ResultData {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      return $this->checkFail($this->_exec('sudo phpenmod -v ALL -s cli xdebug')->wasSuccessful(), 'Running xdebug enable failed.');
    }

    return new ResultData(TRUE);
  }

  /**
   * CLI debug disable.
   */
  public function devXdebugDisable(): ResultData {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      return $this->checkFail($this->_exec('sudo phpdismod -v ALL -s cli xdebug')->wasSuccessful(), 'Running xdebug disable failed.');
    }
    return new ResultData(TRUE);
  }

  /**
   * Turns on twig debug mode, auto reload on and caching off.
   */
  public function devTwigDebugEnable(): void {
    $this->devConfigWriteable();
    $this->devAggregateAssetsDisable(FALSE);
    $this->devUpdateServices(TRUE);
    $this->devConfigReadOnly();
    $this->devCacheRebuild();
  }

  /**
   * Turn off twig debug mode, autoreload off and caching on.
   */
  public function devTwigDebugDisable(): void {
    $this->devConfigWriteable();
    $this->devUpdateServices(FALSE);
    $this->devConfigReadOnly();
    $this->devCacheRebuild();
  }

  /**
   * Replace strings in the services yml.
   *
   * @param bool $enable
   *   Whether to disable or enable debug parameters.
   */
  private function devUpdateServices($enable = TRUE): void {
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
   */
  public function devAggregateAssetsDisable($cacheClear = TRUE): ResultData {
    try {
      $this->taskExecStack()
        ->exec('drush cset system.performance js.preprocess 0 -y')
        ->exec('drush cset system.performance css.preprocess 0 -y')
        ->run();
    }
    catch (Exception $e) {
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
   */
  public function devAggregateAssetsEnable($cacheClear = TRUE): ResultData {
    try {
      $this->taskExecStack()
        ->exec('drush cset system.performance js.preprocess 1 -y')
        ->exec('drush cset system.performance css.preprocess 1 -y')
        ->run();
    }
    catch (Exception $e) {
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
  public function devConfigWriteable(): void {
    $this->setPermissions("$this->applicationRoot/sites/default/services.yml", 0664);
    $this->setPermissions("$this->applicationRoot/sites/default/settings.php", 0664);
    $this->setPermissions("$this->applicationRoot/sites/default/settings.local.php", 0664);
    $this->setPermissions("$this->applicationRoot/sites/default", 0775);
  }

  /**
   * Make config files read only.
   */
  public function devConfigReadOnly(): void {
    $this->setPermissions("$this->applicationRoot/sites/default/services.yml", 0444);
    $this->setPermissions("$this->applicationRoot/sites/default/settings.php", 0444);
    $this->setPermissions("$this->applicationRoot/sites/default/settings.local.php", 0444);
    $this->setPermissions("$this->applicationRoot/sites/default", 0555);
  }

  /**
   * Imports a database, updates the admin user password and applies updates.
   *
   * @param string $sql_file
   *   Path to sql file to import.
   *
   * @return \Robo\ResultData
   *   The result of the command.
   */
  public function devImportDb($sql_file): ResultData {
    $start = new DateTime();
    try {
      $this->taskExecStack()
        ->exec('drush -y sql-drop')
        ->exec("drush sqlq --file=$sql_file")
        ->exec('drush cr')
        ->exec('drush upwd admin --password=password')
        ->exec('drush updb --entity-updates -y')
        ->run();
    }
    catch (Exception $e) {
      return new ResultData(1, 'Database import failed.');
    }

    $this->say('Duration: ' . date_diff(new DateTime(), $start)->format('%im %Ss'));
    $this->say('Database imported, admin user password is : password');

    return new ResultData(TRUE);
  }

  /**
   * Exports a database and gzips the sql file.
   *
   * @param string $name
   *   Name of sql file to be exported.
   */
  public function devExportDb($name = 'dump'): void {
    $start = new DateTime();
    $this->_exec("drush sql-dump --gzip --result-file=$name.sql");
    $this->say("Duration: " . date_diff(new DateTime(), $start)->format('%im %Ss'));
    $this->say("Database $name.sql.gz exported");
  }

  /**
   * Run coding standards checks for PHP files on the project.
   *
   * @param string $path
   *   An optional path to lint.
   */
  public function lintPhp($path = ''): void {
    $this->checkFail($this->_exec("phpcs $path")->wasSuccessful(), 'Code linting failed.');
    $this->checkFail($this->_exec("phpstan analyze --no-progress")->wasSuccessful(), 'Code analyzing failed.');
  }

  /**
   * Fix coding standards violations for PHP files on the project.
   *
   * @param string $path
   *   An optional path to fix.
   */
  public function lintFix($path = ''): void {
    $this->checkFail($this->_exec("phpcbf $path")->wasSuccessful(), 'Code fixing failed.');
  }

  /**
   * Check if file exists and set permissions.
   *
   * @param string $file
   *   File to modify.
   * @param string $permission
   *   Permissions. E.g. '0644'.
   */
  protected function setPermissions($file, $permission): void {
    if (file_exists($file)) {
      $this->taskFilesystemStack()
        ->chmod($file, (int) $permission, 0000)
        ->run();
    }
  }

  /**
   * Return the name of the local user.
   *
   * @return string
   *   Returns the current user.
   */
  protected function getLocalUser(): string {
    $user = posix_getpwuid(posix_getuid());
    return $user['name'];
  }

  /**
   * Helper function to check whether a task has completed successfully.
   *
   * @param bool $successful
   *   Task ran successfully or not.
   * @param string $message
   *   Optional: A helpful message to print.
   *
   * @return \Robo\ResultData
   *   The result of the command.
   */
  protected function checkFail($successful, $message = ''): ResultData {
    if (!$successful) {
      $this->say('APP_ERROR: ' . $message);
      // Prevent any other tasks from executing.
      return new ResultData(1, $message);
    }

    return new ResultData(TRUE);
  }

}
