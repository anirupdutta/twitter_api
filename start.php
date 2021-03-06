<?php
/**
 * Elgg Twitter Service
 * This service plugin allows users to authenticate their Elgg account with Twitter.
 *
 * @package TwitterAPI
 */

elgg_register_event_handler('init', 'system', 'twitter_api_init');

function twitter_api_init() {

	// require libraries
	$base = elgg_get_plugins_path() . 'twitter_api';
        
	elgg_register_library('TwitterOAuth', "$base/vendors/twitteroauth/tmhOAuth.php");
        elgg_register_library('TwitterUtilities',"$base/vendors/twitteroauth/tmhUtilities.php");
        
	elgg_register_library('twitter_api', "$base/lib/twitter_api.php");
	elgg_load_library('twitter_api');

	// extend site views
	//elgg_extend_view('metatags', 'twitter_api/metatags');
	elgg_extend_view('css/elgg', 'twitter_api/css');
	elgg_extend_view('css/admin', 'twitter_api/css');

	// sign on with twitter
	if (twitter_api_allow_sign_on_with_twitter()) {
		elgg_extend_view('login/extend', 'twitter_api/login');
	}

	// register page handler
	elgg_register_page_handler('twitter_api', 'twitter_api_pagehandler');
	// backward compatibility
	elgg_register_page_handler('twitterservice', 'twitter_api_pagehandler_deprecated');

	// register Walled Garden public pages
	elgg_register_plugin_hook_handler('public_pages', 'walled_garden', 'twitter_api_public_pages');

	// push status messages to twitter
	elgg_register_plugin_hook_handler('status', 'user', 'twitter_api_tweet');        

	// allow plugin authors to hook into this service
	elgg_register_plugin_hook_handler('tweet', 'twitter_service', 'twitter_api_tweet');
	elgg_register_plugin_hook_handler('tweet_fetch', 'twitter_service', 'twitter_api_tweet_fetch');
	elgg_register_plugin_hook_handler('photo_tweet', 'twitter_service', 'twitter_api_photo_tweet');        
        
	$actions = dirname(__FILE__) . '/actions/twitter_api';
	elgg_register_action('twitter_api/interstitial_settings', "$actions/interstitial_settings.php", 'logged_in');
}

/**
 * Handles old pg/twitterservice/ handler
 *
 * @param array $page
 * @return bool
 */
function twitter_api_pagehandler_deprecated($page) {
	$url = elgg_get_site_url() . 'pg/twitter_api/authorize';
	$msg = elgg_echo('twitter_api:deprecated_callback_url', array($url));
	register_error($msg);

	return twitter_api_pagehandler($page);
}


/**
 * Serves pages for twitter.
 *
 * @param array $page
 * @return void
 */
function twitter_api_pagehandler($page) {
	if (!isset($page[0])) {
		return false;
	}

	switch ($page[0]) {
		case 'authorize':
			twitter_api_authorize();
			break;
		case 'revoke':
			twitter_api_revoke();
			break;
		case 'forward':
			twitter_api_forward();
			break;
		case 'login':
			twitter_api_login();
			break;
		case 'interstitial':
			gatekeeper();
			// only let twitter users do this.
			$guid = elgg_get_logged_in_user_guid();
			$twitter_name = elgg_get_plugin_user_setting('twitter_name', $guid, 'twitter_api');
			if (!$twitter_name) {
				register_error(elgg_echo('twitter_api:invalid_page'));
				forward();
			}
			$pages = dirname(__FILE__) . '/pages/twitter_api';
			include "$pages/interstitial.php";
			break;
		default:
			return false;
	}
	return true;
}

/**
* Get tweets for a user.
* Backward Compability
* @param int $user_id The Elgg user GUID
* @param array $options
*/
function twitter_api_fetch_tweets($user_guid, $options = array()) {

    elgg_load_library('TwitterOAuth');
    elgg_load_library('TwitterUtilities');
        
    // check admin settings
    $consumer_key = elgg_get_plugin_setting('consumer_key', 'twitter_api');
    $consumer_secret = elgg_get_plugin_setting('consumer_secret', 'twitter_api');

    if (!($consumer_key && $consumer_secret)) {
        return FALSE;
    }

    // check user settings

    $access_key = elgg_get_plugin_user_setting('access_key', $user_guid, 'twitter_api');
    $access_secret = elgg_get_plugin_user_setting('access_secret', $user_guid, 'twitter_api');
    
    if (!($access_key && $access_secret)) {
        return FALSE;
    }

    $tmhOAuth = new tmhOAuth(array(
           'consumer_key' => $consumer_key,
           'consumer_secret' => $consumer_secret,
           'user_token' => $access_key,
           'user_secret' => $access_secret,
    ));
        
    // fetch tweets

    $code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/user_timeline'),$options);
    
    if ($code == 200) {
        $user_timeline = json_decode($tmhOAuth->response['response']);
    }
    else {
        outputError($tmhOAuth);
    }
    
    return $user_timeline;
 
}


/**
 * Push a tweet to twitter.
 *
 * @param string $hook
 * @param string $type
 * @param null   $returnvalue
 * @param array  $params
 */
function twitter_api_tweet($hook, $type, $returnvalue, $params) {

    	elgg_load_library('TwitterOAuth');
        elgg_load_library('TwitterUtilities');
        
	if (!elgg_instanceof($params['user'])) {
		return;
	}

	// @todo - allow admin to select origins?

	// check admin settings
	$consumer_key = elgg_get_plugin_setting('consumer_key', 'twitter_api');
	$consumer_secret = elgg_get_plugin_setting('consumer_secret', 'twitter_api');
	if (!($consumer_key && $consumer_secret)) {
		return;
	}

	// check user settings
	$user_id = $params['user']->getGUID();
	$access_key = elgg_get_plugin_user_setting('access_key', $user_id, 'twitter_api');
	$access_secret = elgg_get_plugin_user_setting('access_secret', $user_id, 'twitter_api');
	if (!($access_key && $access_secret)) {
		return;
	}

        
        $tmhOAuth = new tmhOAuth(array(
            'consumer_key'    => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'user_token'      => $access_key,
            'user_secret'     => $access_secret,
        ));
        
	// send tweet
        
        $code = $tmhOAuth->request('POST', $tmhOAuth->url('1/statuses/update'), array(
            'status' => $params['message'],
        ));
        
}

/**
 * Fetch tweets from twitter
 *
 * @param string $hook
 * @param string $type
 * @param array  $return_value
 * @param array  $params
 */

function twitter_api_tweet_fetch($hook, $entity_type, $returnvalue, $params) {

        elgg_load_library('TwitterOAuth');
        elgg_load_library('TwitterUtilities');
    
        // check admin settings
	$consumer_key = elgg_get_plugin_setting('consumer_key', 'twitter_api');
	$consumer_secret = elgg_get_plugin_setting('consumer_secret', 'twitter_api');
	if (!($consumer_key && $consumer_secret)) {
		return FALSE;
	}

	// check user settings
	$user_id = $params['userid'];
	$access_key = elgg_get_plugin_user_setting('access_key', $user_id, 'twitter_api');
	$access_secret = elgg_get_plugin_user_setting('access_secret', $user_id, 'twitter_api');
	if (!($access_key && $access_secret)) {
		return FALSE;
	}

	if(!$params['count'])
	{
		$params['count'] = 20;
	}
	if(!$params['page'])
	{
		$params['page'] = 1;	
	}
	if(!$params['include_rts'])
	{
		$params['page'] = false;	
	}

	// fetch tweets
	$options = array(
			'count' => $params['count'],
			'page' => $params['page'],
			'include_rts' => $params['include_rts'],
	);

        $tmhOAuth = new tmhOAuth(array(
            'consumer_key'    => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'user_token'      => $access_key,
            'user_secret'     => $access_secret,
        ));          
        
     	switch ($params['choice']) {
		case "hometimeline":
			$code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/home_timeline'),$options);
		break;
		case "mentions":
			$code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/mentions'),$options);
		break;
		case "publictimeline":
			$code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/public_timeline'),$options);
		break;
		case "retweetsofme":
			$code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/retweets_of_me'),$options);
		break;
		case "usertimeline":
			$code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/user_timeline'),$options);
		break;
		default:
			$code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/user_timeline'),$options);
		break;
	}
        
        if ($code == 200) {
            $fetch_tweets = json_decode($tmhOAuth->response['response']);
        }
        else {
            outputError($tmhOAuth);
        }
    
        return $fetch_tweets;
}


/**
 * Tweet a photo to twitter.
 *
 * @param string $hook
 * @param string $type
 * @param null   $returnvalue
 * @param array  $params
 */
function twitter_api_photo_tweet($hook, $type, $returnvalue, $params) {

    	elgg_load_library('TwitterOAuth');
        elgg_load_library('TwitterUtilities');
        
	if (!elgg_instanceof($params['user'])) {
		return;
	}

	// @todo - allow admin to select origins?

	// check admin settings
	$consumer_key = elgg_get_plugin_setting('consumer_key', 'twitter_api');
	$consumer_secret = elgg_get_plugin_setting('consumer_secret', 'twitter_api');
	if (!($consumer_key && $consumer_secret)) {
		return;
	}

	// check user settings
	$user_id = $params['user']->getGUID();
	$access_key = elgg_get_plugin_user_setting('access_key', $user_id, 'twitter_api');
	$access_secret = elgg_get_plugin_user_setting('access_secret', $user_id, 'twitter_api');
	if (!($access_key && $access_secret)) {
		return;
	}
        
        $tmhOAuth = new tmhOAuth(array(
            'consumer_key'    => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'user_token'      => $access_key,
            'user_secret'     => $access_secret,
        ));
        
	// post a photo as tweet
        
        $image = $params['image'];
        $mime = $params['mime'];
        
        $code = $tmhOAuth->request('POST',
            'https://upload.twitter.com/1/statuses/update_with_media.json',
            array(
                'media[]'  => "@{$image};type={$mime};filename={$image}",
                'status'   => 'Picture time',
            ),
            true, // use auth
            true  // multipart
        );
        
}


/**
 * Register as public pages for walled garden.
 *
 * @param string $hook
 * @param string $type
 * @param array  $return_value
 * @param array  $params
 */

function twitter_api_public_pages($hook, $type, $return_value, $params) {
	$return_value[] = 'twitter_api/forward';
	$return_value[] = 'twitter_api/login';

	return $return_value;
}
