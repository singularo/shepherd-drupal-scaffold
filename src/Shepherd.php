<?php

declare(strict_types=1);

namespace Singularo\ShepherdDrupalScaffold;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem as ComposerFilesystem;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Provide composer install functions for the shepherd hosting system.
 *
 * @package Singularo\ShepherdDrupalScaffold
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Shepherd {

  /**
   * Composer object.
   *
   * @var \Composer\Composer
   */
  protected Composer $composer;

  /**
   * Triggered event.
   *
   * @var string
   */
  protected string $eventName;

  /**
   * IO interface.
   *
   * @var \Composer\IO\IOInterface
   */
  protected IOInterface $io;

  /**
   * Filesystem.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected Filesystem $filesystem;

  /**
   * Project path.
   *
   * @var string
   */
  protected string $projectPath;

  /**
   * Root directory.
   *
   * @var string
   */
  protected string $root;

  /**
   * Settings file.
   *
   * @var string
   */
  protected string $settings;

  /**
   * Construct a Config object.
   *
   * @param \Composer\Composer $composer
   *   Composer package object.
   * @param \Composer\IO\IOInterface $io
   *   IO Interface object.
   * @param string $eventName
   *   The event name.
   */
  public function __construct(Composer $composer, IOInterface $io, string $eventName) {
    $this->composer = $composer;
    $this->io = $io;
    $this->eventName = $eventName;
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
   *
   * @throws \Exception
   */
  public function generateSettings(): string {
    // Generate a hash salt with some characters removed.
    $hashSalt = str_replace(['+', '/', '='], ['-', '_', ''],
      base64_encode(random_bytes(55)));

    // Return the settings template with the hash salt embedded.
    return str_replace('{{ HASH_SALT }}', $hashSalt,
      file_get_contents($this->getPackagePath() . '/../assets/settings.template'));
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
      $this->root . '/sites/default/files',
    ], 0755);
  }

  /**
   * Return the path for this package.
   *
   * @return string
   *   Return the file path.
   */
  protected function getPackagePath(): string {
    return __DIR__;
  }

  /**
   * Get the path to the vendor directory.
   *
   * E.g. /home/user/code/project/vendor.
   *
   * @return string
   *   Return the vendor path.
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
   * E.g. /home/user/code/project.
   *
   * @return string
   *   Return the project path.
   */
  public function getProjectPath(): string {
    return dirname($this->getVendorPath());
  }

}
