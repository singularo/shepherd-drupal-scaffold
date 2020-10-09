<?php

declare(strict_types=1);

namespace Singularo\ShepherdDrupalScaffold;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem as ComposerFilesystem;
use Symfony\Component\Filesystem\Filesystem;

class Shepherd {

  /**
   * @var \Composer\Composer
   */
  protected Composer $composer;

  /**
   * @var string $eventName
   */
  protected string $eventName;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected IOInterface $io;

  /**
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected Filesystem $filesystem;

  /**
   * @var string
   */
  protected string $projectPath;

  /**
   * @var string $root
   */
  protected string $root;

  /**
   * @var string $settings
   */
  protected string $settings;

  /**
   * Construct a Config object.
   *
   * @param \Composer\Composer $composer
   *   Composer package object.
   * @param \Composer\IO\IOInterface $io
   *   IO Interface object.
   * @param string $event_name
   *   The event name.
   */
  public function __construct(Composer $composer, IOInterface $io, string $event_name) {
    $this->composer = $composer;
    $this->io = $io;
    $this->eventName = $event_name;
    $this->filesystem = new Filesystem();

    $this->projectPath = $this->getProjectPath();
    $this->root = $this->projectPath . '/web';
    $this->settings = $this->root . '/sites/default/settings.php';
  }

  /**
   * Create settings.php file and inject Shepherd-specific settings.
   *
   * Note: does nothing if the file already exists.
   *
   * @throws \Exception
   */
  public function populateSettingsFile(): void {
    // Check if settings.php exists, create it if not.
    if (!file_exists($this->settings)) {
      $this->filesystem->copy($this->root . '/sites/default/default.settings.php', $this->settings);
    }

    // If we haven't already written to settings.php.
    if (!(strpos(file_get_contents($this->settings), 'START SHEPHERD CONFIG') !== FALSE)) {
      // Append Shepherd-specific environment variable settings to settings.php.
      file_put_contents(
        $this->settings,
        $this->generateSettings(),
        FILE_APPEND
      );
    }
  }

  /**
   * Generates the "template" settings.php configuration.
   *
   * @return string
   *   PHP code.
   * @throws \Exception
   */
  public function generateSettings(): string {
    // Generate a hash salt with some characters removed.
    $hash_salt = str_replace(['+', '/', '='], ['-', '_', ''],
      base64_encode(random_bytes(55)));

    // Return the settings template with the hash salt embedded.
    return str_replace('{{ HASH_SALT }}', $hash_salt,
      file_get_contents($this->getPackagePath() . '/../assets/settings.template'));
  }

  /**
   * Remove all write permissions on Drupal configuration files and folders.
   */
  public function makeReadOnly(): void {
    $this->checkExistsSetPerm([
      $this->root . '/sites/default' => 0555,
      $this->root . '/sites/default/default.services.yml' => 0664,
      $this->settings => 0444,
      $this->projectPath . '/dsh' => 0755,
      $this->projectPath . '/dsh_bash' => 0755,
    ]);
  }

  /**
   * Restore write permissions on Drupal configuration files and folders.
   */
  public function makeReadWrite(): void {
    $this->checkExistsSetPerm([
      $this->root . '/sites/default' => 0755,
      $this->root . '/sites/default/default.services.yml' => 0664,
      $this->settings => 0664,
    ]);
  }

  /**
   * Ensure that the shared directory exists and is writable.
   */
  public function ensureShared(): void {
    $this->filesystem->mkdir([
      $this->projectPath . '/shared',
      $this->projectPath . '/shared/public',
      $this->projectPath . '/shared/private',
      $this->projectPath . '/shared/tmp',
    ], 0755);
  }

  /**
   * Check file exists before trying to set permission.
   *
   * @param array $files
   *   Array of file paths and octal permissions to set on the files.
   */
  private function checkExistsSetPerm(array $files): void {
    foreach ($files as $file => $permission) {
      if ($this->filesystem->exists($file)) {
        $this->filesystem->chmod($file, $permission);
      }
      else {
        $this->io->writeError($file . ': file does not exist');
      }
    }
  }

  /**
   * Return the path for this package.
   *
   * @return string
   *   The file path.
   */
  protected function getPackagePath(): string {
    return __DIR__;
  }

  /**
   * Get the path to the vendor directory.
   *
   * E.g. /home/user/code/project/vendor
   *
   * @return string
   */
  public function getVendorPath(): string {
    // Load ComposerFilesystem to get access to path normalisation.
    $composerFilesystem = new ComposerFilesystem();

    $config = $this->composer->getConfig();
    $composerFilesystem->ensureDirectoryExists($config->get('vendor-dir'));
    return $composerFilesystem->normalizePath(realpath($config->get('vendor-dir')));
  }

  /**
   * Get the path to the project directory.
   *
   * E.g. /home/user/code/project
   *
   * @return string
   */
  public function getProjectPath(): string {
    return dirname($this->getVendorPath());
  }

}
