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
  protected function setDrupalProfile(): ?ResultData {
    $profile = getenv('SHEPHERD_INSTALL_PROFILE');
    if (empty($profile)) {
      throw new \RuntimeException('SHEPHERD_INSTALL_PROFILE environment variable not defined.');
    }

    $this->drupalProfile = $profile;

    return new ResultData(ResultData::EXITCODE_OK);
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
  public function build(): ResultData {
    $this->devXdebugDisable();
    $this->taskComposerValidate()->noCheckPublish();

    $result = $this->buildInstall();
    if (!$result->wasSuccessful()) {
      return $result;
    }

    // If the SITE_UUID is set, set the newly built site to have the same id.
    if ($uuid = $this->getSiteUuid()) {
      $this->drush('config:set')
        ->arg('system.site')
        ->arg('uuid')
        ->arg($uuid)
        ->option('yes')
        ->run();
      $this->devCacheRebuild();

      // Unless IMPORT_CONFIG=false is set, import the config-sync dir.
      if (getenv('IMPORT_CONFIG') !== 'false') {
        $this->drush('config:import')
          ->arg('--partial')
          ->option('yes')
          ->run();
      }
    }

    $this->devXdebugEnable();

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * Clean config and files, then install Drupal and module dependencies.
   */
  public function buildInstall(): ResultData {
    return $this->drush('site:install')
      ->arg($this->drupalProfile)
      ->option('yes')
      ->arg('install_configure_form.enable_update_status_module=NULL')
      ->arg('install_configure_form.enable_update_status_emails=NULL')
      ->option('account-mail', $this->config['site']['admin_email'])
      ->option('account-name', $this->config['site']['admin_user'])
      ->option('account-pass', $this->config['site']['admin_password'])
      ->option('site-name', $this->config['site']['title'])
      ->option('site-mail', $this->config['site']['mail'])
      ->run();
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
      throw new \RuntimeException('Build clean failed.');
    }

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * Run all the drupal updates against a build.
   */
  public function buildUpdate(): ResultData {
    // Run the module updates.
    return $this->drush('updatedb')
      ->option('yes')
      ->run();
  }

  /**
   * Turns on twig debug mode, auto reload on and caching off.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function devTwigDebugEnable(): ResultData {
    $this->devAggregateAssetsDisable(FALSE);
    $this->devUpdateServices(TRUE);
    $this->devCacheRebuild();

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * Turn off twig debug mode, autoreload off and caching on.
   */
  public function devTwigDebugDisable(): ResultData {
    $this->devUpdateServices(FALSE);
    $this->devCacheRebuild();

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * Perform cache clear in the app directory.
   */
  public function devCacheRebuild(): ResultData {
    $result = $this->drush('cache:rebuild')->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Cache rebuild failed.');
    }

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * CLI debug enable.
   */
  public function devXdebugEnable(): ?ResultData {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      $this->say('Enabling xdebug.');
      if (!$this->_exec('sudo phpenmod -v ALL -s cli xdebug')) {
        throw new \RuntimeException('Unable to enable xdebug.');
      }
    }

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * CLI debug disable.
   */
  public function devXdebugDisable(): ?ResultData {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      $this->say('Disabling xdebug.');
      if (!$this->_exec('sudo phpdismod -v ALL -s cli xdebug')) {
        throw new \RuntimeException('Unable to disable xdebug.');
      }
    }

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * Replace strings in the services yml.
   *
   * @param bool $enable
   *   Whether to disable or enable debug parameters.
   */
  private function devUpdateServices(bool $enable = TRUE): ResultData {
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
      if (!$this->taskReplaceInFile($this->servicesYml)
        ->from($values[$old])
        ->to($values[$new])
        ->run()
        ->wasSuccessful()) {
        throw new \RuntimeException('Unable to update services.yml');
      }
    }

    return new ResultData(ResultData::EXITCODE_OK);
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
  public function devAggregateAssetsDisable(bool $cacheClear = TRUE): ResultData {
    $stack = $this->taskExecStack()
      ->stopOnFail()
      ->drush('config:set')->args('system.performance js.preprocess 0')->option('yes')
      ->drush('config:set')->args('system.performance css.preprocess 0 -y')->option('yes');

    $result = $stack->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Aggregate disable failed.');
    }

    if ($cacheClear) {
      $this->devCacheRebuild();
    }

    return new ResultData(ResultData::EXITCODE_OK);
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
  public function devAggregateAssetsEnable(bool $cacheClear = TRUE): ResultData {
    $result = $this->drush('config:set')
      ->args('system.performance js.preprocess 1')
      ->option('yes')
      ->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Config set failed.');
    }

    $result = $this->drush('config:set')
      ->args('system.performance css.preprocess 1')
      ->option('yes')
      ->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Config set failed.');
    }

    if ($cacheClear) {
      $this->devCacheRebuild();
    }
    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * Imports a database, updates the admin user password and applies updates.
   *
   * @param string $sqlFile
   *   Path to sql file to import.
   */
  public function devImportDb(string $sqlFile): array {
    $this->drush('sql:drop')
      ->option('yes')
      ->run();

    $this->drush('sql:query')
      ->option('file', $sqlFile)
      ->run();

    $this->devCacheRebuild();
    $this->devResetAdminPass();
  }

  /**
   * Find the username of user 1 which is the 'admin' user for Drupal.
   */
  public function devResetAdminPass(): ResultData {
    // Retrieve the name of the admin user, it might not be 'admin'.
    $result = $this->drush('sql:query')
      ->arg('SELECT name FROM users u LEFT JOIN users_field_data ud ON u.uid = ud.uid WHERE u.uid = 1')
      ->printOutput(FALSE)
      ->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('No user with uid 1, this is probably bad.');
    }

    $adminUser = trim($result->getMessage());

    // Perform the password reset.
    $result = $this->drush('user:password')
      ->arg($adminUser)
      ->arg('password')
      ->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Failed resetting password.');
    }

    return new ResultData(ResultData::EXITCODE_OK);
  }

  /**
   * Exports a database and gzips the sql file.
   *
   * @param string $name
   *   Name of sql file to be exported.
   */
  public function devExportDb(string $name = 'dump'): Tasks {
    $this->drush('sql:dump')
      ->option('gzip')
      ->option('result-file', "$name.sql")
      ->run();
  }

  /**
   * Run coding standards checks for PHP files on the project.
   *
   * @param string $path
   *   An optional path to lint.
   */
  public function lintPhp(string $path = ''): void {
    $this->_exec('phpcs ' . $path);
    $this->_exec('phpstan analyze --no-progress');
  }

  /**
   * Fix coding standards violations for PHP files on the project.
   *
   * @param string $path
   *   An optional path to fix.
   */
  public function lintFix(string $path = ''): void {
    $this->_exec('phpcbf ' . $path);
  }

  /**
   * Provide drush wrapper.
   *
   * @param string $command
   *   The command to run.
   *
   * @return \Robo\Collection\CollectionBuilder|\Robo\Task\Base\Exec
   *   The task to exec.
   */
  protected function drush(string $command) {
    $task = $this->taskExec('drush');
    $task->arg($command);

    return $task;
  }

}
