<?php

/**
 * @file
 * Definition of Drupal\Core\TypedData\ListInterface.
 */

namespace Drupal\Core\TypedData;

use ArrayAccess;
use Countable;
use Traversable;

/**
 * Interface for a list of typed data.
 *
 * A list of typed data contains only items of the same type, is ordered and may
 * contain duplicates.
 *
 * When implementing this interface which extends Traversable, make sure to list
 * IteratorAggregate or Iterator before this interface in the implements clause.
 */
interface ListInterface extends ArrayAccess, Countable, Traversable {

  /**
   * Determines whether the list contains any non-empty items.
   *
   * @return boolean
   *   TRUE if the list is empty, FALSE otherwise.
   */
  public function isEmpty();
}
