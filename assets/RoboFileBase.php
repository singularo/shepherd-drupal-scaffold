<?php

/**
 * @file
 * The base Robo file with common functionality.
 *
 * Implementation of base class for Robo - http://robo.li/
 */

declare(strict_types=1);

/**
 * @file
 * Contains \Robo\RoboFileBase.
 *
 * Implementation of class for Robo - http://robo.li/
 */

use Robo\Tasks;

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
  protected function getDrupalProfile(): void {
    $profile = getenv('SHEPHERD_INSTALL_PROFILE');
    if (empty($profile)) {
      $this->say("Install profile environment variable is not set.\n");
      exit(1);
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
    //$this->buildMake();
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
    $this->buildMake('--prefer-dist --no-suggest --no-dev --optimize-autoloader');
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
   */
  public function buildMake($flags = ''): void {
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
      $this->say('Composer install failed.');
      exit(1);
    }
  }

  /**
   * Set the owner and group of all files in the files dir to the web user.
   */
  public function buildSetFilesOwner(): void {
    foreach ([$this->filePublic, $this->filePrivate, $this->fileTemp] as $path) {
      try {
        $this->say('Setting ownership and permissions.');
        $this->taskFilesystemStack()
          ->mkdir($path, 0755)
          ->chown($path, $this->webUser)
          ->chgrp($path, $this->localUser)
          ->run();
      }
      catch (Exception $e) {
        $this->say('File ownership failed.');
        exit(1);
      }
    }
  }

  /**
   * Clean config and files, then install Drupal and module dependencies.
   */
  public function buildInstall(): void {
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
      $this->say('drush site-install failed.');
      exit(1);
    }

    $this->devCacheRebuild();
  }

  /**
   * Set the RewriteBase value in .htaccess appropriate for the site.
   */
  public function setSitePath(): void {
    if (!empty($this->config['site']['path'])) {
      $this->say("Setting site path.");
      $successful = $this->taskReplaceInFile("$this->applicationRoot/.htaccess")
        ->from('# RewriteBase /drupal')
        ->to("\n  RewriteBase /" . ltrim($this->config['site']['path'], '/') . "\n")
        ->run();

      $this->checkFail($successful, "Couldn't update .htaccess file with path.");
    }
  }

  /**
   * Clean the application root in preparation for a new build.
   */
  public function buildClean(): void {
    $this->setPermissions("$this->applicationRoot/sites/default", '0755');
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
      $this->say('Build failed.');
      exit(1);
    }
  }

  /**
   * Run all the drupal updates against a build.
   */
  public function buildApplyUpdates(): void {
    // Run the module updates.
    $this->checkFail($this->_exec('drush -y updatedb')->wasSuccessful(), 'running drupal updates failed.');
  }

  /**
   * Perform cache clear in the app directory.
   */
  public function devCacheRebuild(): void {
    $this->checkFail($this->_exec('drush cr')->wasSuccessful(), 'drush cache-rebuild failed.');
  }

  /**
   * CLI debug enable.
   */
  public function devXdebugEnable(): void {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      $this->checkFail($this->_exec('sudo phpenmod -v ALL -s cli xdebug')->wasSuccessful(), 'xdebug enabling failed.');
    }
  }

  /**
   * CLI debug disable.
   */
  public function devXdebugDisable(): void {
    // Only run this on environments configured with xdebug.
    if (getenv('XDEBUG_CONFIG')) {
      $this->checkFail($this->_exec('sudo phpdismod -v ALL -s cli xdebug')->wasSuccessful(), 'xdebug disabling failed.');
    }
  }

  /**
   * Turns on twig debug mode, autoreload on and caching off.
   */
  public function devTwigDebugEnable(): void {
    $this->devConfigWriteable();
    $this->taskReplaceInFile($this->servicesYml)
      ->from('debug: false')
      ->to('debug: true')
      ->run();
    $this->taskReplaceInFile($this->servicesYml)
      ->from('auto_reload: null')
      ->to('auto_reload: true')
      ->run();
    $this->taskReplaceInFile($this->servicesYml)
      ->from('cache: true')
      ->to('cache: false')
      ->run();
    $this->devAggregateAssetsDisable();
    $this->devConfigReadOnly();
    $this->devCacheRebuild();
  }

  /**
   * Turn off twig debug mode, autoreload off and caching on.
   */
  public function devTwigDebugDisable(): void {
    $this->devConfigWriteable();
    $this->taskReplaceInFile($this->servicesYml)
      ->from('debug: true')
      ->to('debug: false')
      ->run();
    $this->taskReplaceInFile($this->servicesYml)
      ->from('auto_reload: true')
      ->to('auto_reload: null')
      ->run();
    $this->taskReplaceInFile($this->servicesYml)
      ->from('c: false')
      ->to('cache: true')
      ->run();
    $this->devConfigReadOnly();
    $this->devCacheRebuild();
  }

  /**
   * Disable asset aggregation.
   */
  public function devAggregateAssetsDisable(): void {
    try {
      $this->taskExecStack()
        ->exec('drush cset system.performance js.preprocess 0 -y')
        ->exec('drush cset system.performance css.preprocess 0 -y')
        ->run();
    }
    catch (Exception $e) {
      $this->say('Aggregate disable failed.');
      exit(1);
    }
    $this->devCacheRebuild();
  }

  /**
   * Enable asset aggregation.
   */
  public function devAggregateAssetsEnable(): void {
    try {
      $this->taskExecStack()
        ->exec('drush cset system.performance js.preprocess 1 -y')
        ->exec('drush cset system.performance css.preprocess 1 -y')
        ->run();
    }
    catch (Exception $e) {
      $this->say('Aggregate enable failed.');
      exit(1);
    }
    $this->devCacheRebuild();
  }

  /**
   * Make config files write-able.
   */
  public function devConfigWriteable(): void {
    $this->setPermissions("$this->applicationRoot/sites/default/services.yml", '0664');
    $this->setPermissions("$this->applicationRoot/sites/default/settings.php", '0664');
    $this->setPermissions("$this->applicationRoot/sites/default/settings.local.php", '0664');
    $this->setPermissions("$this->applicationRoot/sites/default", '0775');
  }

  /**
   * Make config files read only.
   */
  public function devConfigReadOnly(): void {
    $this->setPermissions("$this->applicationRoot/sites/default/services.yml", '0444');
    $this->setPermissions("$this->applicationRoot/sites/default/settings.php", '0444');
    $this->setPermissions("$this->applicationRoot/sites/default/settings.local.php", '0444');
    $this->setPermissions("$this->applicationRoot/sites/default", '0555');
  }

  /**
   * Imports a database, updates the admin user password and applies updates.
   *
   * @param string $sql_file
   *   Path to sql file to import.
   */
  public function devImportDb($sql_file): void {
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
      $this->say('Database import failed.');
      exit(1);
    }
    $this->say('Duration: ' . date_diff(new DateTime(), $start)->format('%im %Ss'));
    $this->say('Database imported, admin user password is : password');
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
        ->chmod($file, (int) $permission)
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
   */
  protected function checkFail($successful, $message = ''): void {
    if (!$successful) {
      $this->say('APP_ERROR: ' . $message);
      // Prevent any other tasks from executing.
      exit(1);
    }
  }

}
