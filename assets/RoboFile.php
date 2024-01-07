<?php

/**
 * @file
 * Contains \Robo\RoboFile.
 *
 * Implementation of class for Robo - http://robo.li/
 *
 * You may override methods provided by RoboFileBase.php in this file.
 * Configuration overrides should be made in the constructor.
 */

declare(strict_types=1);

include_once 'RoboFileBase.php';

/**
 * Class RoboFile.
 */
class RoboFile extends RoboFileBase {

  // @codingStandardsIgnoreStart
  /**
   * Example of overriding the constructor.
   *
   * Coding standards ignored as its empty.
   */
  public function __construct() {
    parent::__construct();
    // Put project specific overrides here, below the parent constructor.
  }
  // @codingStandardsIgnoreEnd

  /**
   * Example of how to install from existing config.
   * Uncomment to use.
   *
  public function buildInstall(): void {
    $this->drush('site:install')
      ->arg($this->drupalProfile)
      ->option('existing-config')
      ->option('account-mail', $this->config['site']['admin_email'])
      ->option('account-name', $this->config['site']['admin_user'])
      ->option('account-pass', $this->config['site']['admin_password'])
      ->option('site-name', $this->config['site']['title'])
      ->option('site-mail', $this->config['site']['mail'])
      ->option('yes')
      ->run();
    }
    */

  }
