<?php

/**
 * @file
 * Definition of Drupal\system\Tests\Plugin\PluginTestBase.
 */

namespace Drupal\system\Tests\Plugin;

use Drupal\simpletest\UnitTestBase;
use Drupal\plugin_test\Plugin\TestPluginManager;
use Drupal\plugin_test\Plugin\MockBlockManager;

/**
 * Base class for Plugin API unit tests.
 */
abstract class PluginTestBase extends UnitTestBase {
  protected $testPluginManager;
  protected $testPluginExpectedDefinitions;
  protected $mockBlockManager;
  protected $mockBlockExpectedDefinitions;

  public function setUp() {
    parent::setUp();

    // Real modules implementing plugin types may expose a module-specific API
    // for retrieving each type's plugin manager, or make them available in
    // Drupal's dependency injection container, but for unit testing, we get
    // the managers directly.
    // - TestPluginManager is a bare bones manager with no support for
    //   derivatives, and uses DefaultFactory for plugin instantiation.
    // - MockBlockManager is used for testing more advanced functionality such
    //   as derivatives and ReflectionFactory.
    $this->testPluginManager = new TestPluginManager();
    $this->mockBlockManager = new MockBlockManager();

    // The expected plugin definitions within each manager. Several tests assert
    // that these plugins and their definitions are found and returned by the
    // necessary API functions.
    // @see TestPluginManager::_construct().
    // @see MockBlockManager::_construct().
    $this->testPluginExpectedDefinitions = array(
      'user_login' => array(
        'label' => 'User login',
        'class' => 'Drupal\plugin_test\Plugin\plugin_test\mock_block\MockUserLoginBlock',
      ),
    );
    $this->mockBlockExpectedDefinitions = array(
      'user_login' => array(
        'label' => 'User login',
        'class' => 'Drupal\plugin_test\Plugin\plugin_test\mock_block\MockUserLoginBlock',
      ),
      'menu:main_menu' => array(
        'label' => 'Main menu',
        'class' => 'Drupal\plugin_test\Plugin\plugin_test\mock_block\MockMenuBlock',
      ),
      'menu:navigation' => array(
        'label' => 'Navigation',
        'class' => 'Drupal\plugin_test\Plugin\plugin_test\mock_block\MockMenuBlock',
      ),
      'layout' => array(
        'label' => 'Layout',
        'class' => 'Drupal\plugin_test\Plugin\plugin_test\mock_block\MockLayoutBlock',
      ),
      'layout:foo' => array(
        'label' => 'Layout Foo',
        'class' => 'Drupal\plugin_test\Plugin\plugin_test\mock_block\MockLayoutBlock',
      ),
    );
  }
}
