<?php

/**
 * @file
 * Contains \Drupal\Core\Render\Element\Html.
 */

namespace Drupal\Core\Render\Element;

/**
 * Provides a render element for <html>.
 *
 * @RenderElement("html")
 */
class Html extends RenderElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return array(
      '#theme' => 'html',
      // HTML5 Shiv
      '#attached' => array(
        'library' => array('core/html5shiv'),
      ),
    );
  }

}
