<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\d6\MigrateSystemLoggingTest.
 */

namespace Drupal\migrate_drupal\Tests\d6;

use Drupal\migrate\MigrateExecutable;
use Drupal\config\Tests\SchemaCheckTestTrait;
use Drupal\migrate_drupal\Tests\MigrateDrupalTestBase;

/**
 * Tests migration of system error_level variables to configuration.
 */
class MigrateSystemLoggingTest extends MigrateDrupalTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name'  => 'Migrate error_level variable to system.logging.yml',
      'description'  => 'Upgrade error_level variable to system.logging.yml',
      'group' => 'Migrate Drupal',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $migration = entity_load('migration', 'd6_system_logging');
    $dumps = array(
      $this->getDumpDirectory() . '/Drupal6SystemLogging.php',
    );
    $this->prepare($migration, $dumps);
    $executable = new MigrateExecutable($migration, $this);
    $executable->import();
  }

  /**
   * Tests migration of system error_level variables to system.logging.yml.
   */
  public function testSystemLogging() {
    $config = \Drupal::config('system.logging');
    $this->assertIdentical($config->get('error_level'), 'some');
    $this->assertConfigSchema(\Drupal::service('config.typed'), 'system.logging', $config->get());
  }

}
