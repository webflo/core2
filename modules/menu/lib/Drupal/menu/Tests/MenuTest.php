<?php

/**
 * @file
 * Definition of Drupal\menu\Tests\MenuTest.
 */

namespace Drupal\menu\Tests;

use Drupal\simpletest\WebTestBase;

class MenuTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('menu');

  protected $profile = 'standard';

  protected $big_user;
  protected $std_user;
  protected $menu;
  protected $items;

  public static function getInfo() {
    return array(
      'name' => 'Menu link creation/deletion',
      'description' => 'Add a custom menu, add menu links to the custom menu and Navigation menu, check their data, and delete them using the menu module UI.',
      'group' => 'Menu'
    );
  }

  function setUp() {
    parent::setUp();

    // Create users.
    $this->big_user = $this->drupalCreateUser(array('access administration pages', 'administer blocks', 'administer menu', 'create article content'));
    $this->std_user = $this->drupalCreateUser(array());
  }

  /**
   * Login users, add menus and menu links, and test menu functionality through the admin and user interfaces.
   */
  function testMenu() {
    // Login the user.
    $this->drupalLogin($this->big_user);
    $this->items = array();

    // Do standard menu tests.
    $this->doStandardMenuTests();

    // Do custom menu tests.
    $this->doCustomMenuTests();

    // Do standard user tests.
    // Login the user.
    $this->drupalLogin($this->std_user);
    $this->verifyAccess(403);
    foreach ($this->items as $item) {
      $node = node_load(substr($item['link_path'], 5)); // Paths were set as 'node/$nid'.
      $this->verifyMenuLink($item, $node);
    }

    // Login the user.
    $this->drupalLogin($this->big_user);

    // Delete menu links.
    foreach ($this->items as $item) {
      $this->deleteMenuLink($item);
    }

    // Delete custom menu.
    $this->deleteCustomMenu($this->menu);

    // Modify and reset a standard menu link.
    $item = $this->getStandardMenuLink();
    $old_title = $item['link_title'];
    $this->modifyMenuLink($item);
    $item = menu_link_load($item['mlid']);
    // Verify that a change to the description is saved.
    $description = $this->randomName(16);
    $item['options']['attributes']['title']  = $description;
    menu_link_save($item);
    $saved_item = menu_link_load($item['mlid']);
    $this->assertEqual($description, $saved_item['options']['attributes']['title'], 'Saving an existing link updates the description (title attribute)');
    $this->resetMenuLink($item, $old_title);
  }

  /**
   * Test standard menu functionality using navigation menu.
   *
   */
  function doStandardMenuTests() {
    $this->doMenuTests();
    $this->addInvalidMenuLink();
  }

  /**
   * Test custom menu functionality using navigation menu.
   *
   */
  function doCustomMenuTests() {
    $this->menu = $this->addCustomMenu();
    $this->doMenuTests($this->menu['menu_name']);
    $this->addInvalidMenuLink($this->menu['menu_name']);
    $this->addCustomMenuCRUD();
  }

  /**
   * Add custom menu using CRUD functions.
   */
  function addCustomMenuCRUD() {
    // Add a new custom menu.
    $menu_name = substr(hash('sha256', $this->randomName(16)), 0, MENU_MAX_MENU_NAME_LENGTH_UI);
    $title = $this->randomName(16);

    $menu = array(
      'menu_name' => $menu_name,
      'title' => $title,
      'description' => 'Description text',
    );
    menu_save($menu);

    // Assert the new menu.
    $this->drupalGet('admin/structure/menu/manage/' . $menu_name . '/edit');
    $this->assertRaw($title, 'Custom menu was added.');

    // Edit the menu.
    $new_title = $this->randomName(16);
    $menu['title'] = $new_title;
    menu_save($menu);
    $this->drupalGet('admin/structure/menu/manage/' . $menu_name . '/edit');
    $this->assertRaw($new_title, 'Custom menu was edited.');
  }

  /**
   * Add custom menu.
   */
  function addCustomMenu() {
    // Add custom menu.

    // Try adding a menu using a menu_name that is too long.
    $this->drupalGet('admin/structure/menu/add');
    $menu_name = substr(hash('sha256', $this->randomName(16)), 0, MENU_MAX_MENU_NAME_LENGTH_UI + 1);
    $title = $this->randomName(16);
    $edit = array(
      'menu_name' => $menu_name,
      'description' => '',
      'title' =>  $title,
    );
    $this->drupalPost('admin/structure/menu/add', $edit, t('Save'));

    // Verify that using a menu_name that is too long results in a validation message.
    $this->assertRaw(t('!name cannot be longer than %max characters but is currently %length characters long.', array(
      '!name' => t('Menu name'),
      '%max' => MENU_MAX_MENU_NAME_LENGTH_UI,
      '%length' => drupal_strlen($menu_name),
    )));

    // Change the menu_name so it no longer exceeds the maximum length.
    $menu_name = substr(hash('sha256', $this->randomName(16)), 0, MENU_MAX_MENU_NAME_LENGTH_UI);
    $edit['menu_name'] = $menu_name;
    $this->drupalPost('admin/structure/menu/add', $edit, t('Save'));

    // Verify that no validation error is given for menu_name length.
    $this->assertNoRaw(t('!name cannot be longer than %max characters but is currently %length characters long.', array(
      '!name' => t('Menu name'),
      '%max' => MENU_MAX_MENU_NAME_LENGTH_UI,
      '%length' => drupal_strlen($menu_name),
    )));
    // Unlike most other modules, there is no confirmation message displayed.

    $this->drupalGet('admin/structure/menu');
    $this->assertText($title, 'Menu created');

    // Enable the custom menu block.
    $menu_name = 'menu-' . $menu_name; // Drupal prepends the name with 'menu-'.
    $edit = array();
    $edit['blocks[menu_' . $menu_name . '][region]'] = 'sidebar_first';
    $this->drupalPost('admin/structure/block', $edit, t('Save blocks'));
    $this->assertResponse(200);
    $this->assertText(t('The block settings have been updated.'), 'Custom menu block was enabled');

    return menu_load($menu_name);
  }

  /**
   * Delete custom menu.
   *
   * @param string $menu_name Custom menu name.
   */
  function deleteCustomMenu($menu) {
    $menu_name = $this->menu['menu_name'];
    $title = $this->menu['title'];

    // Delete custom menu.
    $this->drupalPost("admin/structure/menu/manage/$menu_name/delete", array(), t('Delete'));
    $this->assertResponse(200);
    $this->assertRaw(t('The custom menu %title has been deleted.', array('%title' => $title)), 'Custom menu was deleted');
    $this->assertFalse(menu_load($menu_name), 'Custom menu was deleted');
    // Test if all menu links associated to the menu were removed from database.
    $result = db_query("SELECT menu_name FROM {menu_links} WHERE menu_name = :menu_name", array(':menu_name' => $menu_name))->fetchField();
    $this->assertFalse($result, 'All menu links associated to the custom menu were deleted.');
  }

  /**
   * Test menu functionality using navigation menu.
   *
   */
  function doMenuTests($menu_name = 'navigation') {
    // Add nodes to use as links for menu links.
    $node1 = $this->drupalCreateNode(array('type' => 'article'));
    $node2 = $this->drupalCreateNode(array('type' => 'article'));
    $node3 = $this->drupalCreateNode(array('type' => 'article'));
    $node4 = $this->drupalCreateNode(array('type' => 'article'));
    $node5 = $this->drupalCreateNode(array('type' => 'article'));

    // Add menu links.
    $item1 = $this->addMenuLink(0, 'node/' . $node1->nid, $menu_name);
    $item2 = $this->addMenuLink($item1['mlid'], 'node/' . $node2->nid, $menu_name, FALSE);
    $item3 = $this->addMenuLink($item2['mlid'], 'node/' . $node3->nid, $menu_name);
    $this->assertMenuLink($item1['mlid'], array('depth' => 1, 'has_children' => 1, 'p1' => $item1['mlid'], 'p2' => 0));
    $this->assertMenuLink($item2['mlid'], array('depth' => 2, 'has_children' => 1, 'p1' => $item1['mlid'], 'p2' => $item2['mlid'], 'p3' => 0));
    $this->assertMenuLink($item3['mlid'], array('depth' => 3, 'has_children' => 0, 'p1' => $item1['mlid'], 'p2' => $item2['mlid'], 'p3' => $item3['mlid'], 'p4' => 0));

    // Verify menu links.
    $this->verifyMenuLink($item1, $node1);
    $this->verifyMenuLink($item2, $node2, $item1, $node1);
    $this->verifyMenuLink($item3, $node3, $item2, $node2);

    // Add more menu links.
    $item4 = $this->addMenuLink(0, 'node/' . $node4->nid, $menu_name);
    $item5 = $this->addMenuLink($item4['mlid'], 'node/' . $node5->nid, $menu_name);
    $this->assertMenuLink($item4['mlid'], array('depth' => 1, 'has_children' => 1, 'p1' => $item4['mlid'], 'p2' => 0));
    $this->assertMenuLink($item5['mlid'], array('depth' => 2, 'has_children' => 0, 'p1' => $item4['mlid'], 'p2' => $item5['mlid'], 'p3' => 0));

    // Modify menu links.
    $this->modifyMenuLink($item1);
    $this->modifyMenuLink($item2);

    // Toggle menu links.
    $this->toggleMenuLink($item1);
    $this->toggleMenuLink($item2);

    // Move link and verify that descendants are updated.
    $this->moveMenuLink($item2, $item5['mlid'], $menu_name);
    $this->assertMenuLink($item1['mlid'], array('depth' => 1, 'has_children' => 0, 'p1' => $item1['mlid'], 'p2' => 0));
    $this->assertMenuLink($item4['mlid'], array('depth' => 1, 'has_children' => 1, 'p1' => $item4['mlid'], 'p2' => 0));
    $this->assertMenuLink($item5['mlid'], array('depth' => 2, 'has_children' => 1, 'p1' => $item4['mlid'], 'p2' => $item5['mlid'], 'p3' => 0));
    $this->assertMenuLink($item2['mlid'], array('depth' => 3, 'has_children' => 1, 'p1' => $item4['mlid'], 'p2' => $item5['mlid'], 'p3' => $item2['mlid'], 'p4' => 0));
    $this->assertMenuLink($item3['mlid'], array('depth' => 4, 'has_children' => 0, 'p1' => $item4['mlid'], 'p2' => $item5['mlid'], 'p3' => $item2['mlid'], 'p4' => $item3['mlid'], 'p5' => 0));

    // Add 102 menu links with increasing weights, then make sure the last-added
    // item's weight doesn't get changed because of the old hardcoded delta=50
    $items = array();
    for ($i = -50; $i <= 51; $i++) {
      $items[$i] = $this->addMenuLink(0, 'node/' . $node1->nid, $menu_name, TRUE, strval($i));
    }
    $this->assertMenuLink($items[51]['mlid'], array('weight' => '51'));

    // Enable a link via the overview form.
    $this->disableMenuLink($item1);
    $edit = array();

    // Note in the UI the 'mlid:x[hidden]' form element maps to enabled, or
    // NOT hidden.
    $edit['mlid:' . $item1['mlid'] . '[hidden]'] = TRUE;
    $this->drupalPost('admin/structure/menu/manage/' . $item1['menu_name'], $edit, t('Save configuration'));

    // Verify in the database.
    $this->assertMenuLink($item1['mlid'], array('hidden' => 0));

    // Save menu links for later tests.
    $this->items[] = $item1;
    $this->items[] = $item2;
  }

  /**
   * Add and remove a menu link with a query string and fragment.
   */
  function testMenuQueryAndFragment() {
    $this->drupalLogin($this->big_user);

    // Make a path with query and fragment on.
    $path = 'node?arg1=value1&arg2=value2';
    $item = $this->addMenuLink(0, $path);

    $this->drupalGet('admin/structure/menu/item/' . $item['mlid'] . '/edit');
    $this->assertFieldByName('link_path', $path, 'Path is found with both query and fragment.');

    // Now change the path to something without query and fragment.
    $path = 'node';
    $this->drupalPost('admin/structure/menu/item/' . $item['mlid'] . '/edit', array('link_path' => $path), t('Save'));
    $this->drupalGet('admin/structure/menu/item/' . $item['mlid'] . '/edit');
    $this->assertFieldByName('link_path', $path, 'Path no longer has query or fragment.');
  }

  /**
   * Add a menu link using the menu module UI.
   *
   * @param integer $plid Parent menu link id.
   * @param string $link Link path.
   * @param string $menu_name Menu name.
   * @param string $weight Menu weight
   * @return array Menu link created.
   */
  function addMenuLink($plid = 0, $link = '<front>', $menu_name = 'navigation', $expanded = TRUE, $weight = '0') {
    // View add menu link page.
    $this->drupalGet("admin/structure/menu/manage/$menu_name/add");
    $this->assertResponse(200);

    $title = '!link_' . $this->randomName(16);
    $edit = array(
      'link_path' => $link,
      'link_title' => $title,
      'description' => '',
      'enabled' => TRUE, // Use this to disable the menu and test.
      'expanded' => $expanded, // Setting this to true should test whether it works when we do the std_user tests.
      'parent' =>  $menu_name . ':' . $plid,
      'weight' => $weight,
    );

    // Add menu link.
    $this->drupalPost(NULL, $edit, t('Save'));
    $this->assertResponse(200);
    // Unlike most other modules, there is no confirmation message displayed.
    $this->assertText($title, 'Menu link was added');

    $item = db_query('SELECT * FROM {menu_links} WHERE link_title = :title', array(':title' => $title))->fetchAssoc();
    $this->assertTrue(t('Menu link was found in database.'));
    $this->assertMenuLink($item['mlid'], array('menu_name' => $menu_name, 'link_path' => $link, 'has_children' => 0, 'plid' => $plid));

    return $item;
  }

  /**
   * Attempt to add menu link with invalid path or no access permission.
   *
   * @param string $menu_name Menu name.
   */
  function addInvalidMenuLink($menu_name = 'navigation') {
    foreach (array('-&-', 'admin/people/permissions', '#') as $link_path) {
      $edit = array(
        'link_path' => $link_path,
        'link_title' => 'title',
      );
      $this->drupalPost("admin/structure/menu/manage/$menu_name/add", $edit, t('Save'));
      $this->assertRaw(t("The path '@path' is either invalid or you do not have access to it.", array('@path' => $link_path)), 'Menu link was not created');
    }
  }

  /**
   * Verify a menu link using the menu module UI.
   *
   * @param array $item Menu link.
   * @param object $item_node Menu link content node.
   * @param array $parent Parent menu link.
   * @param object $parent_node Parent menu link content node.
   */
  function verifyMenuLink($item, $item_node, $parent = NULL, $parent_node = NULL) {
    // View home page.
    $this->drupalGet('');
    $this->assertResponse(200);

    // Verify parent menu link.
    if (isset($parent)) {
      // Verify menu link.
      $title = $parent['link_title'];
      $this->assertLink($title, 0, 'Parent menu link was displayed');

      // Verify menu link link.
      $this->clickLink($title);
      $title = $parent_node->label();
      $this->assertTitle(t("@title | Drupal", array('@title' => $title)), 'Parent menu link link target was correct');
    }

    // Verify menu link.
    $title = $item['link_title'];
    $this->assertLink($title, 0, 'Menu link was displayed');

    // Verify menu link link.
    $this->clickLink($title);
    $title = $item_node->label();
    $this->assertTitle(t("@title | Drupal", array('@title' => $title)), 'Menu link link target was correct');
  }

  /**
   * Change the parent of a menu link using the menu module UI.
   */
  function moveMenuLink($item, $plid, $menu_name) {
    $mlid = $item['mlid'];

    $edit = array(
      'parent' => $menu_name . ':' . $plid,
    );
    $this->drupalPost("admin/structure/menu/item/$mlid/edit", $edit, t('Save'));
    $this->assertResponse(200);
  }

  /**
   * Modify a menu link using the menu module UI.
   *
   * @param array $item Menu link passed by reference.
   */
  function modifyMenuLink(&$item) {
    $item['link_title'] = $this->randomName(16);

    $mlid = $item['mlid'];
    $title = $item['link_title'];

    // Edit menu link.
    $edit = array();
    $edit['link_title'] = $title;
    $this->drupalPost("admin/structure/menu/item/$mlid/edit", $edit, t('Save'));
    $this->assertResponse(200);
    // Unlike most other modules, there is no confirmation message displayed.

    // Verify menu link.
    $this->drupalGet('admin/structure/menu/manage/' . $item['menu_name']);
    $this->assertText($title, 'Menu link was edited');
  }

  /**
   * Reset a standard menu link using the menu module UI.
   *
   * @param array $item Menu link.
   * @param string $old_title Original title for menu link.
   */
  function resetMenuLink($item, $old_title) {
    $mlid = $item['mlid'];
    $title = $item['link_title'];

    // Reset menu link.
    $this->drupalPost("admin/structure/menu/item/$mlid/reset", array(), t('Reset'));
    $this->assertResponse(200);
    $this->assertRaw(t('The menu link was reset to its default settings.'), 'Menu link was reset');

    // Verify menu link.
    $this->drupalGet('');
    $this->assertNoText($title, 'Menu link was reset');
    $this->assertText($old_title, 'Menu link was reset');
  }

  /**
   * Delete a menu link using the menu module UI.
   *
   * @param array $item Menu link.
   */
  function deleteMenuLink($item) {
    $mlid = $item['mlid'];
    $title = $item['link_title'];

    // Delete menu link.
    $this->drupalPost("admin/structure/menu/item/$mlid/delete", array(), t('Confirm'));
    $this->assertResponse(200);
    $this->assertRaw(t('The menu link %title has been deleted.', array('%title' => $title)), 'Menu link was deleted');

    // Verify deletion.
    $this->drupalGet('');
    $this->assertNoText($title, 'Menu link was deleted');
  }

  /**
   * Alternately disable and enable a menu link.
   *
   * @param $item
   *   Menu link.
   */
  function toggleMenuLink($item) {
    $this->disableMenuLink($item);

    // Verify menu link is absent.
    $this->drupalGet('');
    $this->assertNoText($item['link_title'], 'Menu link was not displayed');
    $this->enableMenuLink($item);

    // Verify menu link is displayed.
    $this->drupalGet('');
    $this->assertText($item['link_title'], 'Menu link was displayed');
  }

  /**
   * Disable a menu link.
   *
   * @param $item
   *   Menu link.
   */
  function disableMenuLink($item) {
    $mlid = $item['mlid'];
    $edit['enabled'] = FALSE;
    $this->drupalPost("admin/structure/menu/item/$mlid/edit", $edit, t('Save'));

    // Unlike most other modules, there is no confirmation message displayed.
    // Verify in the database.
    $this->assertMenuLink($mlid, array('hidden' => 1));
  }

  /**
   * Enable a menu link.
   *
   * @param $item
   *   Menu link.
   */
  function enableMenuLink($item) {
    $mlid = $item['mlid'];
    $edit['enabled'] = TRUE;
    $this->drupalPost("admin/structure/menu/item/$mlid/edit", $edit, t('Save'));

    // Verify in the database.
    $this->assertMenuLink($mlid, array('hidden' => 0));
  }

  /**
   * Fetch the menu item from the database and compare it to the specified
   * array.
   *
   * @param $mlid
   *   Menu item id.
   * @param $item
   *   Array containing properties to verify.
   */
  function assertMenuLink($mlid, array $expected_item) {
    // Retrieve menu link.
    $item = db_query('SELECT * FROM {menu_links} WHERE mlid = :mlid', array(':mlid' => $mlid))->fetchAssoc();
    $options = unserialize($item['options']);
    if (!empty($options['query'])) {
      $item['link_path'] .= '?' . drupal_http_build_query($options['query']);
    }
    if (!empty($options['fragment'])) {
      $item['link_path'] .= '#' . $options['fragment'];
    }
    foreach ($expected_item as $key => $value) {
      $this->assertEqual($item[$key], $value, format_string('Parameter %key had expected value.', array('%key' => $key)));
    }
  }

  /**
   * Get standard menu link.
   */
  private function getStandardMenuLink() {
    // Retrieve menu link id of the Log out menu link, which will always be on the front page.
    $mlid = db_query("SELECT mlid FROM {menu_links} WHERE module = 'system' AND router_path = 'user/logout'")->fetchField();
    $this->assertTrue($mlid > 0, 'Standard menu link id was found');
    // Load menu link.
    // Use api function so that link is translated for rendering.
    $item = menu_link_load($mlid);
    $this->assertTrue((bool) $item, 'Standard menu link was loaded');
    return $item;
  }

  /**
   * Verify the logged in user has the desired access to the various menu nodes.
   *
   * @param integer $response HTTP response code.
   */
  private function verifyAccess($response = 200) {
    // View menu help node.
    $this->drupalGet('admin/help/menu');
    $this->assertResponse($response);
    if ($response == 200) {
      $this->assertText(t('Menu'), 'Menu help was displayed');
    }

    // View menu build overview node.
    $this->drupalGet('admin/structure/menu');
    $this->assertResponse($response);
    if ($response == 200) {
      $this->assertText(t('Menus'), 'Menu build overview node was displayed');
    }

    // View navigation menu customization node.
    $this->drupalGet('admin/structure/menu/manage/navigation');
        $this->assertResponse($response);
    if ($response == 200) {
      $this->assertText(t('Navigation'), 'Navigation menu node was displayed');
    }

    // View menu edit node.
    $item = $this->getStandardMenuLink();
    $this->drupalGet('admin/structure/menu/item/' . $item['mlid'] . '/edit');
    $this->assertResponse($response);
    if ($response == 200) {
      $this->assertText(t('Edit menu item'), 'Menu edit node was displayed');
    }

    // View menu settings node.
    $this->drupalGet('admin/structure/menu/settings');
    $this->assertResponse($response);
    if ($response == 200) {
      $this->assertText(t('Menus'), 'Menu settings node was displayed');
    }

    // View add menu node.
    $this->drupalGet('admin/structure/menu/add');
    $this->assertResponse($response);
    if ($response == 200) {
      $this->assertText(t('Menus'), 'Add menu node was displayed');
    }
  }
}
