<?php

/**
 * @file
 * Definition of Drupal\rest\test\DeleteTest.
 */

namespace Drupal\rest\Tests;

use Drupal\rest\Tests\RESTTestBase;

/**
 * Tests resource deletion on user, node and test entities.
 */
class DeleteTest extends RESTTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('rest', 'entity_test');

  public static function getInfo() {
    return array(
      'name' => 'Delete resource',
      'description' => 'Tests the deletion of resources.',
      'group' => 'REST',
    );
  }

  /**
   * Tests several valid and invalid delete requests on all entity types.
   */
  public function testDelete() {
    foreach (entity_get_info() as $entity_type => $info) {
      // Enable web API for this entity type.
      $config = config('rest');
      $config->set('resources', array(
        'entity:' . $entity_type => 'entity:' . $entity_type,
      ));
      $config->save();

      // Rebuild routing cache, so that the web API paths are available.
      drupal_container()->get('router.builder')->rebuild();
      // Reset the Simpletest permission cache, so that the new resource
      // permissions get picked up.
      drupal_static_reset('checkPermissions');
      // Create a user account that has the required permissions to delete
      // resources via the web API.
      $account = $this->drupalCreateUser(array('restful delete entity:' . $entity_type));
      // Reset cURL here because it is confused from our previously used cURL
      // options.
      unset($this->curlHandle);
      $this->drupalLogin($account);

      // Create an entity programmatically.
      $entity = $this->entityCreate($entity_type);
      $entity->save();
      // Delete it over the web API.
      $response = $this->httpRequest('entity/' . $entity_type . '/' . $entity->id(), 'DELETE');
      // Clear the static cache with entity_load(), otherwise we won't see the
      // update.
      $entity = entity_load($entity_type, $entity->id(), TRUE);
      $this->assertFalse($entity, $entity_type . ' entity is not in the DB anymore.');
      $this->assertResponse('204', 'HTTP response code is correct.');
      $this->assertEqual($response, '', 'Response body is empty.');

      // Try to delete an entity that does not exist.
      $response = $this->httpRequest('entity/' . $entity_type . '/9999', 'DELETE');
      $this->assertResponse(404);
      $this->assertEqual($response, 'Entity with ID 9999 not found', 'Response message is correct.');

      // Try to delete an entity without proper permissions.
      $this->drupalLogout();
      // Re-save entity to the database.
      $entity = $this->entityCreate($entity_type);
      $entity->save();
      $this->httpRequest('entity/' . $entity_type . '/' . $entity->id(), 'DELETE');
      $this->assertResponse(403);
      $this->assertNotIdentical(FALSE, entity_load($entity_type, $entity->id(), TRUE), 'The ' . $entity_type . ' entity is still in the database.');
    }
    // Try to delete a resource which is not web API enabled.
    $account = $this->drupalCreateUser();
    // Reset cURL here because it is confused from our previously used cURL
    // options.
    unset($this->curlHandle);
    $this->drupalLogin($account);
    $this->httpRequest('entity/user/' . $account->id(), 'DELETE');
    $user = entity_load('user', $account->id(), TRUE);
    $this->assertEqual($account->id(), $user->id());
    $this->assertResponse(404);
  }

  /**
   * Creates entity objects based on their types.
   *
   * Required properties differ from entity type to entity type, so we keep a
   * minimum mapping here.
   *
   * @param string $entity_type
   *   The type of the entity that should be created..
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The new entity object.
   */
  protected function entityCreate($entity_type) {
    switch ($entity_type) {
      case 'entity_test':
        return entity_create('entity_test', array('name' => 'test', 'user_id' => 1));
      case 'node':
        return entity_create('node', array('title' => $this->randomString()));
      case 'user':
        return entity_create('user', array('name' => $this->randomName()));
      default:
        return entity_create($entity_type, array());
    }
  }
}
