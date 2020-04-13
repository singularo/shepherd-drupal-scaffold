<?php
/**
 * @file
 * Contains Singularo\ShepherdDrupalScaffold\ShepherdPlugin.
 */

namespace Singularo\ShepherdDrupalScaffold;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\Script;
use Composer\Script\ScriptEvents;
use Singularo\ShepherdDrupalScaffold\Shepherd;

/**
 * Composer plugin for handling Shepherd Drupal scaffold.
 */
class ShepherdPlugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Composer object.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * IO object.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::PRE_UPDATE_CMD => 'removeWritePermission',
      ScriptEvents::PRE_INSTALL_CMD => 'removeWritePermission',
      ScriptEvents::POST_UPDATE_CMD => 'updateSettings',
      ScriptEvents::POST_INSTALL_CMD => 'updateSettings',
    ];
  }

  /**
   * Post update command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   */
  public function updateSettings(Event $event) {
    $settingsUpdate = new Shepherd(
      $this->composer, $event->getName()
    );
    $event->getIO()->write('Creating settings.php file if not present.');
    $settingsUpdate->populateSettingsFile();
    // $event->getIO()->write("Removing write permissions on settings files.");
    // $settingsUpdate->removeWritePermission();
  }

  public function removeWritePermission(Event $event) {
    $settingsUpdate = new Shepherd(
      $this->composer, $event->getName()
    );
    $event->getIO()->write('Restoring write permissions on settings files.');
    $settingsUpdate->restoreWritePermission();
  }

}
