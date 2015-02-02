<?php

/**
 * @file
 * Contains \Drupal\Core\Url.
 */

namespace Drupal\Core;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines an object that holds information about a URL.
 */
class Url {
  use DependencySerializationTrait;

  /**
   * The URL generator.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * The unrouted URL assembler.
   *
   * @var \Drupal\Core\Utility\UnroutedUrlAssemblerInterface
   */
  protected $urlAssembler;

  /**
   * The access manager
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected $accessManager;

  /**
   * The route name.
   *
   * @var string
   */
  protected $routeName;

  /**
   * The route parameters.
   *
   * @var array
   */
  protected $routeParameters = array();

  /**
   * The URL options.
   *
   * @var array
   */
  protected $options = array();

  /**
   * Indicates whether this object contains an external URL.
   *
   * @var bool
   */
  protected $external = FALSE;

  /**
   * Indicates whether this URL is for a URI without a Drupal route.
   *
   * @var bool
   */
  protected $unrouted = FALSE;

  /**
   * The non-route URI.
   *
   * Only used if self::$unrouted is TRUE.
   *
   * @var string
   */
  protected $uri;

  /**
   * Stores the internal path, if already requested by getInternalPath
   *
   * @var string
   */
  protected $internalPath;

  /**
   * Constructs a new Url object.
   *
   * In most cases, use Url::fromRoute() or Url::fromUri() rather than
   * constructing Url objects directly in order to avoid ambiguity and make your
   * code more self-documenting.
   *
   * @param string $route_name
   *   The name of the route
   * @param array $route_parameters
   *   (optional) An associative array of parameter names and values.
   * @param array $options
   *   (optional) An associative array of additional options, with the following
   *   elements:
   *   - 'query': An array of query key/value-pairs (without any URL-encoding)
   *     to append to the URL. Merged with the parameters array.
   *   - 'fragment': A fragment identifier (named anchor) to append to the URL.
   *     Do not include the leading '#' character.
   *   - 'absolute': Defaults to FALSE. Whether to force the output to be an
   *     absolute link (beginning with http:). Useful for links that will be
   *     displayed outside the site, such as in an RSS feed.
   *   - 'language': An optional language object used to look up the alias
   *     for the URL. If $options['language'] is omitted, it defaults to the
   *     current language for the language type LanguageInterface::TYPE_URL.
   *   - 'https': Whether this URL should point to a secure location. If not
   *     defined, the current scheme is used, so the user stays on HTTP or HTTPS
   *     respectively. TRUE enforces HTTPS and FALSE enforces HTTP.
   *
   * @see static::fromRoute()
   * @see static::fromUri()
   *
   * @todo Update this documentation for non-routed URIs in
   *   https://www.drupal.org/node/2346787
   */
  public function __construct($route_name, $route_parameters = array(), $options = array()) {
    $this->routeName = $route_name;
    $this->routeParameters = $route_parameters;
    $this->options = $options;
  }

  /**
   * Creates a new Url object for a URL that has a Drupal route.
   *
   * This method is for URLs that have Drupal routes (that is, most pages
   * generated by Drupal). For non-routed local URIs relative to the base
   * path (like robots.txt) use Url::fromUri() with the base: scheme.
   *
   * @param string $route_name
   *   The name of the route
   * @param array $route_parameters
   *   (optional) An associative array of route parameter names and values.
   * @param array $options
   *   (optional) An associative array of additional URL options, with the
   *   following elements:
   *   - 'query': An array of query key/value-pairs (without any URL-encoding)
   *     to append to the URL. Merged with the parameters array.
   *   - 'fragment': A fragment identifier (named anchor) to append to the URL.
   *     Do not include the leading '#' character.
   *   - 'absolute': Defaults to FALSE. Whether to force the output to be an
   *     absolute link (beginning with http:). Useful for links that will be
   *     displayed outside the site, such as in an RSS feed.
   *   - 'language': An optional language object used to look up the alias
   *     for the URL. If $options['language'] is omitted, it defaults to the
   *     current language for the language type LanguageInterface::TYPE_URL.
   *   - 'https': Whether this URL should point to a secure location. If not
   *     defined, the current scheme is used, so the user stays on HTTP or HTTPS
   *     respectively. TRUE enforces HTTPS and FALSE enforces HTTP.
   *
   * @return \Drupal\Core\Url
   *   A new Url object for a routed (internal to Drupal) URL.
   *
   * @see static::fromUri()
   */
  public static function fromRoute($route_name, $route_parameters = array(), $options = array()) {
    return new static($route_name, $route_parameters, $options);
  }

  /**
   * Creates a new URL object from a route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return $this
   */
  public static function fromRouteMatch(RouteMatchInterface $route_match) {
    if ($route_match->getRouteObject()) {
      return new static($route_match->getRouteName(), $route_match->getRawParameters()->all());
    }
    else {
      throw new \InvalidArgumentException('Route required');
    }
  }

  /**
   * Creates a new Url object from a URI.
   *
   * This method is for generating URLs for URIs that:
   * - do not have Drupal routes: both external URLs and unrouted local URIs
   *   like base:robots.txt
   * - do have a Drupal route but have a custom scheme to simplify linking.
   *   Currently, there is only the entity: scheme (This allows URIs of the
   *   form entity:{entity_type}/{entity_id}. For example: entity:node/1
   *   resolves to the entity.node.canonical route with a node parameter of 1.)
   *
   * For URLs that have Drupal routes (that is, most pages generated by Drupal),
   * use Url::fromRoute().
   *
   * @param string $uri
   *   The URI of the resource including the scheme. For user input that may
   *   correspond to a Drupal route, use user-path: for the scheme. For paths
   *   that are known not to be handled by the Drupal routing system (such as
   *   static files), use base: for the scheme to get a link relative to the
   *   Drupal base path (like the <base> HTML element). For a link to an entity
   *   you may use entity:{entity_type}/{entity_id} URIs.
   * @param array $options
   *   (optional) An associative array of additional URL options, with the
   *   following elements:
   *   - 'query': An array of query key/value-pairs (without any URL-encoding)
   *     to append to the URL.
   *   - 'fragment': A fragment identifier (named anchor) to append to the URL.
   *     Do not include the leading '#' character.
   *   - 'absolute': Defaults to FALSE. Whether to force the output to be an
   *     absolute link (beginning with http:). Useful for links that will be
   *     displayed outside the site, such as in an RSS feed.
   *   - 'language': An optional language object used to look up the alias
   *     for the URL. If $options['language'] is omitted, it defaults to the
   *     current language for the language type LanguageInterface::TYPE_URL.
   *   - 'https': Whether this URL should point to a secure location. If not
   *     defined, the current scheme is used, so the user stays on HTTP or HTTPS
   *     respectively. TRUE enforces HTTPS and FALSE enforces HTTP.
   *
   * Note: the user-path: scheme should be avoided except when processing actual
   * user input that may or may not correspond to a Drupal route. Normally use
   * Url::fromRoute() for code linking to any any Drupal page.
   *
   * You can call access() on the returned object to do access checking.
   *
   * @return \Drupal\Core\Url
   *   A new Url object with properties depending on the URI scheme.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the passed in path has no scheme.
   *
   * @see static::fromRoute()
   */
  public static function fromUri($uri, $options = []) {
    $uri_parts = parse_url($uri);
    if ($uri_parts === FALSE) {
      throw new \InvalidArgumentException(String::format('The URI "@uri" is malformed.', ['@uri' => $uri]));
    }
    if (empty($uri_parts['scheme'])) {
      throw new \InvalidArgumentException(String::format('The URI "@uri" is invalid. You must use a valid URI scheme. Use base: for items like a static file that needs the base path. Use user-path: for user input without a scheme. Use entity: for referencing the canonical route of a content entity. Use route: for directly representing a route name and parameters.', ['@uri' => $uri]));
    }
    $uri_parts += ['path' => ''];
    // Extract query parameters and fragment and merge them into $uri_options,
    // but preserve the original $options for the fallback case.
    $uri_options = $options;
    if (!empty($uri_parts['fragment'])) {
      $uri_options += ['fragment' => $uri_parts['fragment']];
    }
    unset($uri_parts['fragment']);
    if (!empty($uri_parts['query'])) {
      $uri_query = [];
      parse_str($uri_parts['query'], $uri_query);
      $uri_options['query'] = isset($uri_options['query']) ? $uri_options['query'] + $uri_query : $uri_query;
      unset($uri_parts['query']);
    }

    if ($uri_parts['scheme'] === 'entity') {
      $url = static::fromEntityUri($uri_parts, $uri_options, $uri);
    }
    elseif ($uri_parts['scheme'] === 'user-path') {
      $url = static::fromUserPathUri($uri_parts, $uri_options);
    }
    elseif ($uri_parts['scheme'] === 'route') {
      $url = static::fromRouteUri($uri_parts, $uri_options, $uri);
    }
    else {
      $url = new static($uri, array(), $options);
      if ($uri_parts['scheme'] !== 'base') {
        $url->external = TRUE;
        $url->setOption('external', TRUE);
      }
      $url->setUnrouted();
    }

    return $url;
  }

  /**
   * Create a new Url object for entity URIs.
   *
   * @param array $uri_parts
   *   Parts from an URI of the form entity:{entity_type}/{entity_id} as from
   *   parse_url().
   * @param array $options
   *   An array of options, see static::fromUri() for details.
   * @param string $uri
   *   The original entered URI.
   *
   * @return \Drupal\Core\Url
   *   A new Url object for an entity's canonical route.
   *
   * @throws \InvalidArgumentException
   *   Thrown if the entity URI is invalid.
   */
  protected static function fromEntityUri(array $uri_parts, $options, $uri) {
    list($entity_type_id, $entity_id) = explode('/', $uri_parts['path'], 2);
    if ($uri_parts['scheme'] != 'entity' || $entity_id === '') {
      throw new \InvalidArgumentException(String::format('The entity URI "@uri" is invalid. You must specify the entity id in the URL. e.g., entity:node/1 for loading the canonical path to node entity with id 1.', ['@uri' => $uri]));
    }

    return new static("entity.$entity_type_id.canonical", [$entity_type_id => $entity_id], $options);
  }

  /**
   * Creates a new Url object for 'user-path:' URIs.
   *
   * @param array $uri_parts
   *   Parts from an URI of the form user-path:{path} as from parse_url().
   * @param array $options
   *   An array of options, see static::fromUri() for details.
   *
   * @return \Drupal\Core\Url
   *   A new Url object for a 'user-path:' URI.
   */
  protected static function fromUserPathUri(array $uri_parts, array $options) {
    $url = \Drupal::pathValidator()
      ->getUrlIfValidWithoutAccessCheck($uri_parts['path']) ?: static::fromUri('base:' . $uri_parts['path'], $options);
    // Allow specifying additional options.
    $url->setOptions($options + $url->getOptions());

    return $url;
  }

  /**
   * Creates a new Url object for 'route:' URIs.
   *
   * @param array $uri_parts
   *   Parts from an URI of the form route:{route_name};{route_parameters} as
   *   from parse_url(), where the path is the route name optionally followed by
   *   a ";" followed by route parameters in key=value format with & separators.
   * @param array $options
   *   An array of options, see static::fromUri() for details.
   * @param string $uri
   *   The original passed in URI.
   *
   * @return \Drupal\Core\Url
   *   A new Url object for a 'route:' URI.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the route URI does not have a route name.
   */
  protected static function fromRouteUri(array $uri_parts, array $options, $uri) {
    $route_parts = explode(';', $uri_parts['path'], 2);
    $route_name = $route_parts[0];
    if ($route_name === '') {
      throw new \InvalidArgumentException(String::format('The route URI "@uri" is invalid. You must have a route name in the URI. e.g., route:system.admin', ['@uri' => $uri]));
    }
    $route_parameters = [];
    if (!empty($route_parts[1])) {
      parse_str($route_parts[1], $route_parameters);
    }

    return new static($route_name, $route_parameters, $options);
  }

  /**
   * Returns the Url object matching a request.
   *
   * SECURITY NOTE: The request path is not checked to be valid and accessible
   * by the current user to allow storing and reusing Url objects by different
   * users. The 'path.validator' service getUrlIfValid() method should be used
   * instead of this one if validation and access check is desired. Otherwise,
   * 'access_manager' service checkNamedRoute() method should be used on the
   * router name and parameters stored in the Url object returned by this
   * method.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   A request object.
   *
   * @return static
   *   A Url object. Warning: the object is created even if the current user
   *   would get an access denied running the same request via the normal page
   *   flow.
   *
   * @throws \Drupal\Core\Routing\MatchingRouteNotFoundException
   *   Thrown when the request cannot be matched.
   */
  public static function createFromRequest(Request $request) {
    // We use the router without access checks because URL objects might be
    // created and stored for different users.
    $result = \Drupal::service('router.no_access_checks')->matchRequest($request);
    $route_name = $result[RouteObjectInterface::ROUTE_NAME];
    $route_parameters = $result['_raw_variables']->all();
    return new static($route_name, $route_parameters);
  }

  /**
   * Sets this Url to encapsulate an unrouted URI.
   *
   * @return $this
   */
  protected function setUnrouted() {
    $this->unrouted = TRUE;
    // What was passed in as the route name is actually the URI.
    // @todo Consider fixing this in https://www.drupal.org/node/2346787.
    $this->uri = $this->routeName;
    // Set empty route name and parameters.
    $this->routeName = NULL;
    $this->routeParameters = array();
  }

  /**
   * Return a URI string that represents tha data in the Url object.
   *
   * The URI will typically have the scheme of route: even if the object was
   * constructed using an entity: or user-path: scheme.  A user-path: URI
   * that does not match a Drupal route with be returned here with the base:
   * scheme, and external URLs will be returned in their original form.
   *
   * @return string
   *   A URI representation of the Url object data.
   */
  public function toUriString() {
    if ($this->isRouted()) {
      $uri = 'route:' . $this->routeName;
      if ($this->routeParameters) {
        $uri .= ';' . UrlHelper::buildQuery($this->routeParameters);
      }
    }
    else {
      $uri = $this->uri;
    }
    $query = !empty($this->options['query']) ? ('?' . UrlHelper::buildQuery($this->options['query'])) : '';
    $fragment = !empty($this->options['fragment']) ? '#' . $this->options['fragment'] : '';
    return $uri . $query . $fragment;
  }

  /**
   * Indicates if this Url is external.
   *
   * @return bool
   */
  public function isExternal() {
    return $this->external;
  }

  /**
   * Indicates if this Url has a Drupal route.
   *
   * @return bool
   */
  public function isRouted() {
    return !$this->unrouted;
  }

  /**
   * Returns the route name.
   *
   * @return string
   *
   * @throws \UnexpectedValueException.
   *   If this is a URI with no corresponding route.
   */
  public function getRouteName() {
    if ($this->unrouted) {
      throw new \UnexpectedValueException('External URLs do not have an internal route name.');
    }

    return $this->routeName;
  }

  /**
   * Returns the route parameters.
   *
   * @return array
   *
   * @throws \UnexpectedValueException.
   *   If this is a URI with no corresponding route.
   */
  public function getRouteParameters() {
    if ($this->unrouted) {
      throw new \UnexpectedValueException('External URLs do not have internal route parameters.');
    }

    return $this->routeParameters;
  }

  /**
   * Sets the route parameters.
   *
   * @param array $parameters
   *   The array of parameters.
   *
   * @return $this
   *
   * @throws \UnexpectedValueException.
   *   If this is a URI with no corresponding route.
   */
  public function setRouteParameters($parameters) {
    if ($this->unrouted) {
      throw new \UnexpectedValueException('External URLs do not have route parameters.');
    }
    $this->routeParameters = $parameters;
    return $this;
  }

  /**
   * Sets a specific route parameter.
   *
   * @param string $key
   *   The key of the route parameter.
   * @param mixed $value
   *   The route parameter.
   *
   * @return $this
   *
   * @throws \UnexpectedValueException.
   *   If this is a URI with no corresponding route.
   */
  public function setRouteParameter($key, $value) {
    if ($this->unrouted) {
      throw new \UnexpectedValueException('External URLs do not have route parameters.');
    }
    $this->routeParameters[$key] = $value;
    return $this;
  }

  /**
   * Returns the URL options.
   *
   * @return array
   */
  public function getOptions() {
    return $this->options;
  }

  /**
   * Gets a specific option.
   *
   * @param string $name
   *   The name of the option.
   *
   * @return mixed
   *   The value for a specific option, or NULL if it does not exist.
   */
  public function getOption($name) {
    if (!isset($this->options[$name])) {
      return NULL;
    }

    return $this->options[$name];
  }

  /**
   * Sets the URL options.
   *
   * @param array $options
   *   The array of options.
   *
   * @return $this
   */
  public function setOptions($options) {
    $this->options = $options;
    return $this;
  }

  /**
   * Sets a specific option.
   *
   * @param string $name
   *   The name of the option.
   * @param mixed $value
   *   The option value.
   *
   * @return $this
   */
  public function setOption($name, $value) {
    $this->options[$name] = $value;
    return $this;
  }

  /**
   * Returns the URI of the URL.
   *
   * Only to be used if self::$unrouted is TRUE.
   *
   * @return string
   *   A URI not connected to a route. May be an external URL.
   *
   * @throws \UnexpectedValueException
   *   Thrown when the URI was requested for a routed URL.
   */
  public function getUri() {
    if (!$this->unrouted) {
      throw new \UnexpectedValueException('This URL has a Drupal route, so the canonical form is not a URI.');
    }

    return $this->uri;
  }

  /**
   * Sets the absolute value for this Url.
   *
   * @param bool $absolute
   *   (optional) Whether to make this Url absolute or not. Defaults to TRUE.
   *
   * @return $this
   */
  public function setAbsolute($absolute = TRUE) {
    $this->options['absolute'] = $absolute;
    return $this;
  }

  /**
   * Generates the URI for this Url object.
   */
  public function toString() {
    if ($this->unrouted) {
      return $this->unroutedUrlAssembler()->assemble($this->getUri(), $this->getOptions());
    }

    return $this->urlGenerator()->generateFromRoute($this->getRouteName(), $this->getRouteParameters(), $this->getOptions());
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->toString();
  }

  /**
   * Returns the route information for a render array.
   *
   * @return array
   *   An associative array suitable for a render array.
   */
  public function toRenderArray() {
    $render_array = [
      '#url' => $this,
      '#options' => $this->getOptions(),
    ];
    if (!$this->unrouted) {
      $render_array['#access_callback'] = [get_class(), 'renderAccess'];
    }
    return $render_array;
  }

  /**
   * Returns the internal path (system path) for this route.
   *
   * This path will not include any prefixes, fragments, or query strings.
   *
   * @return string
   *   The internal path for this route.
   *
   * @throws \UnexpectedValueException.
   *   If this is a URI with no corresponding system path.
   *
   * @deprecated in Drupal 8.x-dev, will be removed before Drupal 8.0.
   *   System paths should not be used - use route names and parameters.
   */
  public function getInternalPath() {
    if ($this->unrouted) {
      throw new \UnexpectedValueException('Unrouted URIs do not have internal representations.');
    }

    if (!isset($this->internalPath)) {
      $this->internalPath = $this->urlGenerator()->getPathFromRoute($this->getRouteName(), $this->getRouteParameters());
    }
    return $this->internalPath;
  }

  /**
   * Checks this Url object against applicable access check services.
   *
   * Determines whether the route is accessible or not.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) Run access checks for this account. Defaults to the current
   *   user.
   *
   * @return bool
   *   Returns TRUE if the user has access to the url, otherwise FALSE.
   */
  public function access(AccountInterface $account = NULL) {
    if ($this->isRouted()) {
      return $this->accessManager()->checkNamedRoute($this->getRouteName(), $this->getRouteParameters(), $account);
    }
    return TRUE;
  }

  /**
   * Checks a Url render element against applicable access check services.
   *
   * @param array $element
   *   A render element as returned from \Drupal\Core\Url::toRenderArray().
   *
   * @return bool
   *   Returns TRUE if the current user has access to the url, otherwise FALSE.
   */
  public static function renderAccess(array $element) {
    return $element['#url']->access();
  }

  /**
   * @return \Drupal\Core\Access\AccessManagerInterface
   */
  protected function accessManager() {
    if (!isset($this->accessManager)) {
      $this->accessManager = \Drupal::service('access_manager');
    }
    return $this->accessManager;
  }

  /**
   * Gets the URL generator.
   *
   * @return \Drupal\Core\Routing\UrlGeneratorInterface
   *   The URL generator.
   */
  protected function urlGenerator() {
    if (!$this->urlGenerator) {
      $this->urlGenerator = \Drupal::urlGenerator();
    }
    return $this->urlGenerator;
  }

  /**
   * Gets the unrouted URL assembler for non-Drupal URLs.
   *
   * @return \Drupal\Core\Utility\UnroutedUrlAssemblerInterface
   *   The unrouted URL assembler.
   */
  protected function unroutedUrlAssembler() {
    if (!$this->urlAssembler) {
      $this->urlAssembler = \Drupal::service('unrouted_url_assembler');
    }
    return $this->urlAssembler;
  }

  /**
   * Sets the URL generator.
   *
   * @param \Drupal\Core\Routing\UrlGeneratorInterface
   *   (optional) The URL generator, specify NULL to reset it.
   *
   * @return $this
   */
  public function setUrlGenerator(UrlGeneratorInterface $url_generator = NULL) {
    $this->urlGenerator = $url_generator;
    $this->internalPath = NULL;
    return $this;
  }

  /**
   * Sets the unrouted URL assembler.
   *
   * @param \Drupal\Core\Utility\UnroutedUrlAssemblerInterface
   *   The unrouted URL assembler.
   *
   * @return $this
   */
  public function setUnroutedUrlAssembler(UnroutedUrlAssemblerInterface $url_assembler) {
    $this->urlAssembler = $url_assembler;
    return $this;
  }

}
