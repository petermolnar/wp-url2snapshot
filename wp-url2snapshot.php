<?php
/*
Plugin Name: wp-url2snapshot
Plugin URI: https://github.com/petermolnar/wp-url2snapshot
Description: reversible automatic short slug based on post pubdate epoch for WordPress
Version: 0.2.1
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
Required minimum PHP version: 5.3
*/

/*  Copyright 2015 Peter Molnar ( hello@petermolnar.eu )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if (!class_exists('WP_URL2SNAPSHOT')):

class WP_URL2SNAPSHOT {
	const expire = 10;
	const timeout = 5;
	const redirection = 5;

	private $looping = 0;

	public function __construct() {

		add_action( 'init', array( &$this, 'init'));

		// this is mostly for debugging reasons
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( &$this, 'plugin_deactivate' ) );
		// TODO register uninstall hook & db cleanup

		// register the action for the cron hook
		add_action( __CLASS__, array( &$this, 'worker' ) );
		add_action( __CLASS__ . '_single', array( &$this, 'standalone' ) );

		$statuses = array ('new', 'draft', 'auto-draft', 'pending', 'private', 'future' );
		foreach ($statuses as $status) {
			add_action("{$status}_to_publish", array( &$this,'standalone_event' ));
		}
		add_action( 'publish_future_post', array( &$this,'standalone_event' ));

	}

	public static function init() {
		if (!wp_get_schedule( __CLASS__ )) {
			wp_schedule_event( time(), 'daily', __CLASS__ );
		}
	}

	/**
	 * activation hook function
	 */
	public function plugin_activate() {
		if ( version_compare( phpversion(), 5.3, '<' ) ) {
			die( 'The minimum PHP version required for this plugin is 5.3' );
		}
		$this->init_db();
	}

	/**
	 * deactivation hook function; clears schedules
	 */
	public function plugin_deactivate () {
		static::debug('deactivating');
		wp_unschedule_event( time(), __CLASS__ );
		wp_clear_scheduled_hook( __CLASS__ );
	}

	public function standalone_event ( $post ) {
		wp_schedule_single_event( time() + 2*60, __CLASS__ . '_single' , $post );
	}

	/**
	 *
	 */
	public function worker () {
		static::debug('worker started');
		global $wpdb;

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'post',
			'post_status' => 'any',
		);
		$posts = get_posts( $args );

		foreach ( $posts as $post ) {
			setup_postdata($post);
			$this->standalone($post);
		}
		wp_reset_postdata();

		static::debug("worker finished" );
	}


	public function standalone ( $post ) {
		if (!static::is_post($post))
			return false;

		static::debug('standalone started');

		static::debug(" processing post #{$post->ID}");
		$content = static::get_the_content($post);
		$urls = static::extract_urls($content);
		$urls = apply_filters ( 'wp_url2snapshot_urls', $urls, $post );
		$urls = array_unique ( $urls );
		foreach ($urls as $url) {
			$url = esc_url_raw($url);

			if (empty($url))
				continue;

			$domain = parse_url(get_bloginfo('url'), PHP_URL_HOST);

			if (preg_match("/^https?:\/\/{$domain}.*$/", $url))
				continue;

			if (preg_match('/^https?:\/\/127\.0\.0\.1.*$/', $url ))
				continue;

			static::debug("  found url {$url}" );

			if (!$this->hash_exists($url)) {

				static::debug("   not yet snapshotted, doing it now" );
				$r = $this->get_url($url);

				if (!empty($r) && is_array($r) && isset($r['headers']) && isset($r['body'])) {
					$this->snapshot( $url, $r );
				}
				else {
					static::debug("   getting url failed :(" );
					continue;
				}
			}
			else {
				static::debug("   is already done" );
			}
		}

		static::debug("standalone finished" );
		return true;
	}


	/**
	 *
	 */
	private function try_archive ( &$url ) {

		static::debug('     trying to get archive.org version');
		$aurl = 'https://archive.org/wayback/available?url=' . $url;

		$archive = $this->get_url($aurl);

		if (($archive === false) )
			return false;

		if (!is_array($archive) || !isset($archive['headers']) || !isset($archive['body']))
			return false;

		try {
			$json = json_decode($archive['body']);
		}
		catch (Exception $e) {
			static::debug("     something went wrong: " . $e->getMessage());
		}

		if (!isset($json->archived_snapshots)) {
			static::debug("     archive.org version not found");
			return false;
		}

		if (!isset($json->archived_snapshots->closest)) {
			static::debug("     closest archive.org version not found");
			return false;
		}

		if (!isset($json->archived_snapshots->closest->available)) {
			static::debug("     closest available archive.org version not found");
			return false;
		}

		if ($json->archived_snapshots->closest->available != 'true') {
			static::debug("     closest archive.org version not available");
			return false;
		}

		if ($json->archived_snapshots->closest->status != 200 ) {
			static::debug("     closest archive.org version not 200");
			return false;
		}

		$wurl = $json->archived_snapshots->closest->url;
		$wurl = str_replace( $json->archived_snapshots->closest->timestamp, $json->archived_snapshots->closest->timestamp . 'id_', $wurl );
		static::debug("     trying {$wurl}");

		return $this->get_url($wurl);
	}

	/**
	 *
	 */
	private function hash_exists ( &$url ) {
		if (empty($url))
			return false;

		global $wpdb;
		$dbname = "{$wpdb->prefix}urlsnapshots";

		$db_command = "SELECT `url_url` FROM `{$dbname}` WHERE `url_hash` = UNHEX(SHA1('{$url}')) LIMIT 1";
		$r = false;

		try {
			$q = $wpdb->get_row($db_command);
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage());
		}

		if (!empty($q) && is_object($q) && isset($q->url_url) && !empty($q->url_url))
			$r = true;

		return $r;
	}

	/**
	 *
	 */
	private function get_url ( &$url ) {

		if (empty($url))
			return false;

		$args = array(
			'timeout' => static::timeout,
			'redirection' => static::redirection,
			'httpversion' => '1.1',
			'user-agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:42.0) Gecko/20100101 Firefox/42.0',
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			static::debug("   retrieving URL ${url} failed: " . $response->get_error_message());
			return false;
		}

		if (!isset($response['headers']) || empty($response['headers']) || !isset($response['response']) || empty($response['response']) || !isset($response['response']['code']) || empty($response['response']['code'])) {
			static::debug("   WHAT? No or empty headers? Get out of here.");
			return false;
		}

		if (!isset($response['headers']['content-type']) || empty($response['headers']['content-type'])) {
			static::debug("   Empty content type, I don't want this link");
			return false;
		}

		// 400s: client error. Yeah, sure.
		if ($response['response']['code'] < 500 && $response['response']['code'] >= 400 ) {
			return $this->try_archive($url);
		}
		// try next time
		elseif ($response['response']['code'] >= 500 ) {
			return false;
		}
		// redirects, follow redirect, but keep counting to avoid infinity
		elseif ($response['response']['code'] < 400 && $response['response']['code'] >= 300 && isset($response['headers']['location']) && !empty($response['headers']['location'])) {
			if ($this->looping < 6) {
				$this->looping = $this->looping + 1;
				return $this->get_url($response['headers']['location']);
			}
			else {
				$this->looping = 0;
				return false;
			}
		}
		elseif ($response['response']['code'] == 200) {
			$mime_ok = false;
			$mimes = array ('text/', 'application/json', 'application/javascript');
			foreach ( $mimes as $mime ) {
				if (stristr( $response['headers']['content-type'], $mime)) {
					$mime_ok = true;
				}
			}

			if (!$mime_ok) {
				static::debug("    {$response['headers']['content-type']} is not text, we don't want it.");
				return true;
			}
		}
		else {
			static::debug("   Response was {$response['headers']['code']}. This is not yet handled.");
			return false;
		}

		$this->looping = 0;
		return $response;
	}

	/**
	 *
	 */
	private function snapshot ( &$url, &$r ) {
		global $wpdb;
		$dbname = "{$wpdb->prefix}urlsnapshots";
		$req = false;

		$q = $wpdb->prepare( "INSERT INTO `{$dbname}` (`url_hash`,`url_date`,`url_url`, `url_response`,`url_headers`, `url_cookies`,`url_body`) VALUES (UNHEX(SHA1('{$url}')), NOW(), '%s', '%s', '%s', '%s', '%s' );", $url, json_encode($r['response']), json_encode($r['headers']), json_encode($r['cookies']), $r['body'] );

		try {
			$req = $wpdb->query( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage());
		}

		return $req;
	}

	/**
	 *
	 */
	private function init_db () {
		global $wpdb;
		$dbname = "{$wpdb->prefix}urlsnapshots";

		//Use the character set and collation that's configured for WP tables
		$charset_collate = '';

		if ( !empty($wpdb->charset) ){
			$charset = str_replace('-', '', $wpdb->charset);
			$charset_collate = "DEFAULT CHARACTER SET {$charset}";
		}

		if ( !empty($wpdb->collate) ){
			$charset_collate .= " COLLATE {$wpdb->collate}";
		}


		$db_command = "CREATE TABLE IF NOT EXISTS `{$dbname}` (
		`url_hash` binary(20),
		`url_date` datetime NOT NULL DEFAULT NOW(),
		`url_url` text COLLATE {$wpdb->collate},
		`url_response` text COLLATE {$wpdb->collate},
		`url_headers` text COLLATE {$wpdb->collate},
		`url_cookies` text COLLATE {$wpdb->collate},
		`url_body` longtext COLLATE {$wpdb->collate},

		PRIMARY KEY (`url_hash`)
		) {$charset_collate};";

		static::debug("Initiating DB {$dbname}");
		try {
			$wpdb->query( $db_command );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage());
		}

	}

	/**
	 *
	 */
	private function delete_db () {
		global $wpdb;
		$dbname = "{$wpdb->prefix}urlsnapshots";

		$db_command = "DROP TABLE IF EXISTS `{$dbname}`;";

		static::debug("Deleting DB {$dbname}");
		try {
			$wpdb->query( $db_command );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage());
		}
	}

	/**
	 *
	 */
	public static function get_the_content( &$_post ){
		if (empty($_post) || !static::is_post($_post))
			return false;

		if ( $cached = wp_cache_get ( $_post->ID, __CLASS__ . __FUNCTION__ ) )
			return $cached;

		global $post;
		$prevpost = $post;

		$post = $_post;

		ob_start();
		the_content();
		$r = ob_get_clean();

		wp_cache_set ( $_post->ID, $r, __CLASS__ . __FUNCTION__, static::expire );

		$post = $prevpost;

		return $r;
	}


	/**
	 *
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 *
	 * @output log to syslog | wp_die on high level
	 * @return false on not taking action, true on log sent
	 */
	public static function debug( $message, $level = LOG_NOTICE ) {
		if ( empty( $message ) )
			return false;

		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);

		$levels = array (
			LOG_EMERG => 0, // system is unusable
			LOG_ALERT => 1, // Alert 	action must be taken immediately
			LOG_CRIT => 2, // Critical 	critical conditions
			LOG_ERR => 3, // Error 	error conditions
			LOG_WARNING => 4, // Warning 	warning conditions
			LOG_NOTICE => 5, // Notice 	normal but significant condition
			LOG_INFO => 6, // Informational 	informational messages
			LOG_DEBUG => 7, // Debug 	debug-level messages
		);

		// number for number based comparison
		// should work with the defines only, this is just a make-it-sure step
		$level_ = $levels [ $level ];

		// in case WordPress debug log has a minimum level
		if ( defined ( 'WP_DEBUG_LEVEL' ) ) {
			$wp_level = $levels [ WP_DEBUG_LEVEL ];
			if ( $level_ < $wp_level ) {
				return false;
			}
		}

		// ERR, CRIT, ALERT and EMERG
		if ( 3 >= $level_ ) {
			wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
			exit;
		}

		$trace = debug_backtrace();
		$caller = $trace[1];
		$parent = $caller['function'];

		if (isset($caller['class']))
			$parent = $caller['class'] . '::' . $parent;

		return error_log( "{$parent}: {$message}" );
	}

	/**
	 *
	 */
	public static function extract_urls( &$text ) {
		$matches = array();
		preg_match_all("/\b(?:http|https)\:\/\/?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\.\/\?\:@\-_=#]*/i", $text, $matches);

		$matches = $matches[0];
		return $matches;
	}

	/**
	 *
	 */
	public static function is_post ( &$post ) {
		if ( !empty($post) && is_object($post) && isset($post->ID) && !empty($post->ID) )
			return true;

		return false;
	}

}

$WP_URL2SNAPSHOT = new WP_URL2SNAPSHOT();

endif;
