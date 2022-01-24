<?php

declare(strict_types=1);

namespace Singularo\ShepherdDrupalScaffold;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Composer plugin for handling Shepherd Drupal scaffold.
 *
 * @package Singularo\ShepherdDrupalScaffold
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class ShepherdPlugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Composer object.
   *
   * @var \Composer\Composer
   */
  protected Composer $composer;

  /**
   * IO object.
   *
   * @var \Composer\IO\IOInterface
   */
  protected IOInterface $io;

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io): void {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      // Run the post installs after everything else.
      ScriptEvents::POST_CREATE_PROJECT_CMD => ['postInstall', -99],
      ScriptEvents::POST_INSTALL_CMD => ['postInstall', -99],
      ScriptEvents::POST_UPDATE_CMD => ['postInstall', -99],
    ];
  }

  /**
   * Post update command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   *
   * @throws \Exception
   */
  public function postInstall(Event $event): void {
    $shepherd = new Shepherd($this->composer, $this->io, $event->getName());
    $event->getIO()->write('Creating settings.php file if not present.');
    $shepherd->populateSettingsFile();

    // Some things are only really required for dev.
    if (getenv('SHEPHERD_ENVIRONMENT') !== 'live') {
      $event->getIO()->write('Ensuring shared filesystem folder exists.');
      $shepherd->ensureShared();
      $event->getIO()->write('Ensuring dsh utility scripts are executable.');
      $shepherd->makeExecutable();
    }
  }

}
