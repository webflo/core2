<?php

/**
 * @file
 * Definition of Drupal\search\Tests\SearchNumbersTest.
 */

namespace Drupal\search\Tests;

/**
 * Tests that numbers can be searched.
 */
class SearchNumbersTest extends SearchTestBase {
  protected $test_user;
  protected $numbers;
  protected $nodes;

  public static function getInfo() {
    return array(
      'name' => 'Search numbers',
      'description' => 'Check that numbers can be searched',
      'group' => 'Search',
    );
  }

  function setUp() {
    parent::setUp();

    $this->test_user = $this->drupalCreateUser(array('search content', 'access content', 'administer nodes', 'access site reports'));
    $this->drupalLogin($this->test_user);

    // Create content with various numbers in it.
    // Note: 50 characters is the current limit of the search index's word
    // field.
    $this->numbers = array(
      'ISBN' => '978-0446365383',
      'UPC' => '036000 291452',
      'EAN bar code' => '5901234123457',
      'negative' => '-123456.7890',
      'quoted negative' => '"-123456.7890"',
      'leading zero' => '0777777777',
      'tiny' => '111',
      'small' => '22222222222222',
      'medium' => '333333333333333333333333333',
      'large' => '444444444444444444444444444444444444444',
      'gigantic' => '5555555555555555555555555555555555555555555555555',
      'over fifty characters' => '666666666666666666666666666666666666666666666666666666666666',
      'date', '01/02/2009',
      'commas', '987,654,321',
    );

    foreach ($this->numbers as $doc => $num) {
      $info = array(
        'body' => array(LANGUAGE_NOT_SPECIFIED => array(array('value' => $num))),
        'type' => 'page',
        'language' => LANGUAGE_NOT_SPECIFIED,
        'title' => $doc . ' number',
      );
      $this->nodes[$doc] = $this->drupalCreateNode($info);
    }

    // Run cron to ensure the content is indexed.
    $this->cronRun();
    $this->drupalGet('admin/reports/dblog');
    $this->assertText(t('Cron run completed'), 'Log shows cron run completed');
  }

  /**
   * Tests that all the numbers can be searched.
   */
  function testNumberSearching() {
    $types = array_keys($this->numbers);

    foreach ($types as $type) {
      $number = $this->numbers[$type];
      // If the number is negative, remove the - sign, because - indicates
      // "not keyword" when searching.
      $number = ltrim($number, '-');
      $node = $this->nodes[$type];

      // Verify that the node title does not appear on the search page
      // with a dummy search.
      $this->drupalPost('search/node',
        array('keys' => 'foo'),
        t('Search'));
      $this->assertNoText($node->title, $type . ': node title not shown in dummy search');

      // Verify that the node title does appear as a link on the search page
      // when searching for the number.
      $this->drupalPost('search/node',
        array('keys' => $number),
        t('Search'));
      $this->assertText($node->title, $type . ': node title shown (search found the node) in search for number ' . $number);
    }
  }
}
