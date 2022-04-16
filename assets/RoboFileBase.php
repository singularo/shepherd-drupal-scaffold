<?php

/**
 * @file
 * Contains \Robo\RoboFileBase.
 *
 * Implementation of class for Robo - http://robo.li/
 */

declare(strict_types=1);

use Robo\Tasks;

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
   * The path to the services file.
   *
   * @var string
   */
  protected string $servicesYml = '/code/web/sites/default/services.yml';

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
    // Default to stopping on failures.
    $this->stopOnFail(TRUE);

    // Read config from environment variables.
    $environmentConfig = $this->readConfigFromEnv();
    $this->config = array_merge($this->config, $environmentConfig);

    // Get the drupal profile.
    $this->setDrupalProfile();
  }

  /**
   * Confirm the installation profile is set.
   *
   * Runs during the constructor; be careful not to use Robo methods.
   */
  protected function setDrupalProfile(): void {
    $profile = getenv('SHEPHERD_INSTALL_PROFILE');
    if (empty($profile)) {
      throw new \RuntimeException('SHEPHERD_INSTALL_PROFILE environment variable not defined.');
    }

    $this->drupalProfile = $profile;
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
  public function build(): void {
    $this->taskComposerValidate()->noCheckPublish();

    $this->buildInstall();

    // If the SITE_UUID is set, set the newly built site to have the same id.
    if ($uuid = $this->getSiteUuid()) {
      $this->drush('config:set')
        ->args('system.site', 'uuid', $uuid)
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
  }

  /**
   * Clean config and files, then install Drupal and module dependencies.
   */
  public function buildInstall(): void {
    $this->drush('site:install')
      ->arg($this->drupalProfile)
      ->option('account-mail', $this->config['site']['admin_email'])
      ->option('account-name', $this->config['site']['admin_user'])
      ->option('account-pass', $this->config['site']['admin_password'])
      ->option('site-name', $this->config['site']['title'])
      ->option('site-mail', $this->config['site']['mail'])
      ->option('yes')
      ->run();
  }

  /**
   * Set the RewriteBase value in .htaccess appropriate for the site.
   */
  public function setSitePath(): void {
    if (!empty($this->config['site']['path'])) {
      $this->say("Setting site path.");
      $this->taskReplaceInFile("$this->applicationRoot/.htaccess")
        ->from('# RewriteBase /drupal')
        ->to("\n  RewriteBase /" . ltrim($this->config['site']['path'], '/') . "\n");
    }
  }

  /**
   * Clean the application root in preparation for a new build.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function buildClean(): void {
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
  }

  /**
   * Run all the drupal updates against a build.
   */
  public function buildUpdate(): void {
    // Run the module updates.
    $this->drush('updatedb')
      ->option('yes')
      ->run();
  }

  /**
   * Retrieve the site UUID.
   *
   * Once config is set, it's likely that the UUID will need to be consistent
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
   * Turns on twig debug mode, auto reload on and caching off.
   */
  public function devTwigDebugEnable(): void {
    $this->devAggregateAssetsDisable(FALSE);
    $this->devUpdateServices(TRUE);
    $this->devCacheRebuild();
  }

  /**
   * Turn off twig debug mode, autoreload off and caching on.
   */
  public function devTwigDebugDisable(): void {
    $this->devUpdateServices(FALSE);
    $this->devCacheRebuild();
  }

  /**
   * Perform cache clear in the app directory.
   */
  public function devCacheRebuild(): void {
    $this->drush('cache:rebuild')->run();
  }

  /**
   * CLI debug enable.
   */
  public function devXdebugEnable(): void {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      $this->say('Enabling xdebug.');
      if (!$this->_exec('sudo phpenmod -v ALL -s cli xdebug')->wasSuccessful()) {
        throw new \RuntimeException('Unable to enable xdebug.');
      }
    }
  }

  /**
   * CLI debug disable.
   */
  public function devXdebugDisable(): void {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      $this->say('Disabling xdebug.');
      if (!$this->_exec('sudo phpdismod -v ALL -s cli xdebug')->wasSuccessful()) {
        throw new \RuntimeException('Unable to enable xdebug.');
      }
    }
  }

  /**
   * Replace strings in the services yml.
   *
   * @param bool $enable
   *   Whether to disable or enable debug parameters.
   */
  private function devUpdateServices(bool $enable = TRUE): void {
    $replacements = [
      ['debug: false', 'debug: true'],
      ['auto_reload: null', 'auto_reload: true'],
      ['cache: true', 'cache: false'],
      [
        'http.response.debug_cacheability_headers: true',
        'http.response.debug_cacheability_headers: false',
      ],
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
  }

  /**
   * Disable asset aggregation.
   *
   * @param bool $cacheClear
   *   Whether to clear the cache after changes.
   */
  public function devAggregateAssetsDisable(bool $cacheClear = TRUE): void {
    $result = $this->drush('config:set')
      ->rawArg('system.performance js.preprocess 0')
      ->option('yes')
      ->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Aggregate disable failed.');
    }

    $result = $this->drush('config:set')
      ->rawArg('system.performance css.preprocess 0 -y')
      ->option('yes')
      ->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Aggregate disable failed.');
    }

    if ($cacheClear) {
      $this->devCacheRebuild();
    }
  }

  /**
   * Enable asset aggregation.
   *
   * @param bool $cacheClear
   *   Whether to clear the cache after changes.
   */
  public function devAggregateAssetsEnable(bool $cacheClear = TRUE): void {
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
  }

  /**
   * Imports a database, updates the admin user password and applies updates.
   *
   * @param string $sqlFile
   *   Path to sql file to import.
   */
  public function devImportDb(string $sqlFile): void {
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
   *
   * @param string $password
   *   Password for admin user, defaults to 'password'.
   */
  public function devResetAdminPass(string $password = 'password'): void {
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
      ->args($adminUser, $password)
      ->run();
    if (!$result->wasSuccessful()) {
      throw new \RuntimeException('Failed resetting password.');
    }
  }

  /**
   * Exports a database and gzips the sql file.
   *
   * @param string $name
   *   Name of sql file to be exported.
   */
  public function devExportDb(string $name = 'dump'): void {
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
  public function devLintPhp(string $path = ''): void {
    $this->_exec('phpcs ' . $path);
    $this->_exec('phpstan analyze --no-progress');
  }

  /**
   * Fix coding standards violations for PHP files on the project.
   *
   * @param string $path
   *   An optional path to fix.
   */
  public function devLintFix(string $path = ''): void {
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
    $task = $this->taskExec('vendor/bin/drush');
    $task->rawArg($command);

    return $task;
  }

}
