<?php
/**
 * Tweet Display Back Module for Joomla!
 *
 * @package    TweetDisplayBack
 *
 * @copyright  Copyright (C) 2010-2013 Michael Babker. All rights reserved.
 * @license    GNU/GPL - http://www.gnu.org/copyleft/gpl.html
 */

defined('JPATH_PLATFORM') or die;

/**
 * Bearer class for Tweet Display Back
 *
 * @package  TweetDisplayBack
 * @since    3.0.4-routinet-fork
 */
class BDBearer
{
	/**
	 * The name of the cache file to use to store the bearer token
	 *
	 * @var    string
	 * @since  3.0.4-routinet-fork
	 */
	private $cache_file = 'tweetdisplayback_bearer.json';

	/**
	 * The time in seconds to cache the bearer token
	 *
	 * @var    integer
	 * @since  3.0.4-routinet-fork
	 */
	private $cache_time = -1;

	/**
	 * BDHttp connector
	 *
	 * @var    BDHttp
	 * @since  3.0.4-routinet-fork
	 */
	protected $connector = null;

	/**
	 * The bearer token
	 *
	 * @var    string
	 * @since  3.0.4-routinet-fork
	 */
	public $token = null;

	/**
	 * Constructor
	 *
	 * @param   JRegistry  $params  The module parameters
	 *
	 * @since   3.0.4-routinet-fork
	 */
	public function __construct($params,BDHttp $connector) {
		// Store the module params
		$this->params = $params;
		// Store the connector
		$this->connector = $connector;
		// Prepare the token
		$this->prepareToken();
	}

	/**
	 * Function to create the bearer authentication value for use in HTTP headers
	 *
	 * @return  string
	 *
   * @since   3.0.4-routinet-fork
   */
  protected function prepareBearerAuth() {
    $ckey = rawurlencode($this->params->get('consumer_key',''));
    $csec = rawurlencode($this->params->get('consumer_secret',''));
    return ($ckey && $csec) ? base64_encode("{$ckey}:{$csec}") : '';
  }

	/**
	 * Function to convert the bearer_cache_time_unit and _qty into epoch seconds
	 *
	 * @return  void
	 *
   * @since   3.0.4-routinet-fork
   */
  protected function cacheTime() {
    if ($this->cache_time < 0) { $this->prepareCacheTime(); }
    return (int)$this->cache_time;
  }

  /**
   * Function to retrieve a bearer token from Twitter's API using a consumer key
   * 
   * @return  void
   *
   * @since   3.0.4-routinet-fork
   */
  protected function callConsumer() {
    $auth = $this->prepareBearerAuth();
    if ($auth) {
      $url = "https://api.twitter.com/oauth2/token";
			$headers = array(
				'Authorization' => "Basic {$auth}",
			);
			$data = "grant_type=client_credentials";
      $response = $this->connector->post($url, $data, $headers);
      if ($response->code == 200) {
        $this->token = json_decode($response->body)->access_token;
      } else {
        throw new RuntimeException('Could not retrieve bearer token (consumer)');
      }
      $this->writeCache();
    } else {
      throw new RuntimeException('Invalid consumer key/secret in configuration');
    }
  }

  /**
   * Function to retrieve a bearer token from a remote URL
   * 
   * @return  void
   *
   * @since   3.0.4-routinet-fork
   */
  protected function callRemoteUrl() {
    $url = $this->params->get('remote_url','http://tdbtoken.gopagoda.com/tokenRequest.php');
	  // call consumer or RemoteURL
		$response = $this->connector->get($url);

		if ($response->code == 200) {
			$this->token = str_replace('Bearer ','',base64_decode($response->body));
		} else {
			throw new RuntimeException('Could not retrieve bearer token (remote)');
		}
    $this->writeCache();
  }

	/**
	 * Function to convert the bearer_cache_time_unit and _qty into epoch seconds
	 *
	 * @return  void
	 *
   * @since   3.0.4-routinet-fork
   */
  protected function prepareCacheTime() {
		$cacheTime = (int)$this->params->get('bearer_cache_time_qty', 1);
		$cacheUnit = $this->params->get('bearer_cache_time_unit','');
		if (!$cacheUnit) {
		  $cacheUnit = 'day';
		  $cacheTime = 1;
		}
		switch ($cacheUnit) {
		  case 'hour':  $cacheTime *= 3600; break;
		  case 'min':   $cacheTime *= 60; break;
		  case 'week':  $cacheTime *= 86400*7; break;
		  case 'noexp': $cacheTime  = 0; break;
		  case 'day':   
		  default:      $cacheTime *= 86400; break;
		}
		$this->cache_time = $cacheTime;
  }

  /**
   * Function to obtain a bearer token, if none is cached
   *
   * @return  boolean  true if a bearer token is available, otherwise false
   *
   * @since   3.0.4-routinet-fork
   */
  protected function prepareToken() {
		// If we haven't retrieved the bearer yet, get it if in the site application
		if (($this->token == null) && JFactory::getApplication()->isSite())
		{
  		$cacheTime = $this->cacheTime();
			$cacheFile = JPATH_CACHE . DIRECTORY_SEPARATOR . $this->cache_file;

			// Check if we have cached data and use it if unexpired
			if (!file_exists($cacheFile) || ($cacheTime && (time() - @filemtime($cacheFile) > $cacheTime)))
			{
			  // call consumer or RemoteURL
			  switch ($this->params->get('token_source','consumer')) {
			    case 'remote':   $this->callRemoteUrl(); break;
			    case 'consumer': 
			    default:         $this->callConsumer(); break;
			  }
				// Write the cache
				$this->writeCache();
			}
			else
			{
				// Render from the cached data
				$this->token = $this->readCache();
			}

		}
		return !empty($this->token);

  }

  /**
   * Function to read the bearer token from cache
   * 
   * @return  string
   *
   * @since   3.0.4-routinet-fork
   */
  protected function readCache() {
    $cacheFile = JPATH_CACHE . DIRECTORY_SEPARATOR . $this->cache_file;
    $ret='';
    if (file_exists($cacheFile)) { $ret = file_get_contents($cacheFile); }
    return $ret;
  }

  /**
   * Function to cache the bearer token
   * 
   * @return  void
   *
   * @since   3.0.4-routinet-fork
   */
  protected function writeCache() {
    $cacheFile = JPATH_CACHE . DIRECTORY_SEPARATOR . $this->cache_file;
		// Write the cache
		file_put_contents($cacheFile, $this->token);
  }

}