<?php
/*
Plugin Name: wp-url2snapshot
Plugin URI: https://github.com/petermolnar/wp-url2snapshot
Description: reversible automatic short slug based on post pubdate epoch for WordPress
Version: 0.2
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

	public function __construct() {

		add_action( 'init', array( &$this, 'init'));

		// this is mostly for debugging reasons
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		// clear schedules if there's any on deactivation
		register_deactivation_hook( __FILE__ , array( &$this, 'plugin_deactivate' ) );
		// TODO register uninstall hook & db cleanup

		// register the action for the cron hook
		add_action( __CLASS__, array( &$this, 'worker' ) );
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
		static::debug('activating');
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


	public function worker () {
		static::debug('worker started');
		global $wpdb;

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'post',
			'post_status' => 'publish',
		);
		$posts = get_posts( $args );

		foreach ( $posts as $post ) {
			setup_postdata($post);
			static::debug(" processing post #{$post->ID}");
			$content = static::get_the_content($post);
			$urls = static::extract_urls($content);
			foreach ($urls as $url) {
				$url = esc_url_raw($url);
				if (empty($url)) {
					continue;
				}

				$domain = parse_url(get_bloginfo('url'), PHP_URL_HOST);
				if (preg_match("/^https?:\/\/{$domain}.*$/", $url)) {
					continue;
				}
				elseif (preg_match('/^https?:\/\/127\.0\.0\.1.*$/', $url )) {
					continue;
				}

				static::debug("  found url {$url}" );

				if (!$this->hash_exists($url)) {

					static::debug("   not yet snapshotted, doing it now" );
					$status = true;
					$content = $this->get_url($url, $status);

					if (($content !== false && $status === true) || $status == 'e_nottext' ) { // all clear or not text
						$s = $this->snapshot( $url, $content );
					}
					elseif ( $status == 'e_not200' ) {

						// dead content, try archive.org
						if ($content == '404') {
							$acontent = $this->try_archive($url);
							if (!empty($acontent))
								$s = $this->snapshot( $url, $acontent );
						}

					}
				}
				else {
					static::debug("   is already done" );
				}
			}
		}
		wp_reset_postdata();
	}

	/**
	 *
	 */
	private function try_archive ( &$url ) {

		static::debug('     trying to get archive.org version instead');
		$astatus = true;
		$wstatus = true;
		$aurl = 'https://archive.org/wayback/available?url=' . $url;

		$archive = $this->get_url($aurl, $astatus);

		if (($archive == false || $astatus != true) ) {
				static::debug("     archive.org version failed");
				return false;
		}

		try {
			$json = json_decode($archive);
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

		$wget = $this->get_url($wurl, $wstatus);
		if (($wget !== false && $wstatus === true) ) {
			static::debug("     success! Found archive.org version at {$wurl}");
			return $wget;
		}

		return false;
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
	private static function get_url ( &$url, &$status ) {
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
			$status = 'e_notfound';
			return false;
		}

		if (!isset($response['headers']) || empty($response['headers']) || !isset($response['response']) || empty($response['response']) || !isset($response['response']['code']) || empty($response['response']['code'])) {
			static::debug("   WHAT? No or empty headers? Get out of here.");
			$status = 'e_noresponseheaders';
			return false;
		}

		if (!isset($response['headers']['content-type']) || empty($response['headers']['content-type'])) {
			static::debug("   Empty content type, I don't want this link");
			$status = 'e_nomime';
			return false;
		}

		if ($response['response']['code'] != 200) {
			static::debug("   Response was {$response['response']['code']}.");
			$status = 'e_not200';
			return $response['response']['code'];
		}

		$mime_ok = false;
		$mimes = array ('text/', 'application/json', 'application/javascript');
		foreach ( $mimes as $mime ) {
			if (stristr( $response['headers']['content-type'], $mime)) {
				$mime_ok = true;
			}
		}

		if (!$mime_ok) {
			static::debug("    {$response['headers']['content-type']} is probably not text");
			$status = 'e_nottext';
			return false;
		}

		$contents = wp_remote_retrieve_body( $response );

		if (is_wp_error($contents)) {
			static::debug("    retrieving contents of URL ${url} failed: " . $response->get_error_message());
			$status = 'e_content';
			return false;
		}

		return $contents;
	}

	/**
	 *
	 */
	private function snapshot ( &$url, &$content ) {
		global $wpdb;
		$dbname = "{$wpdb->prefix}urlsnapshots";
		$r = false;

		$q = $wpdb->prepare( "INSERT INTO `{$dbname}` (`url_hash`,`url_date`,`url_url`,`url_content`) VALUES (UNHEX(SHA1('{$url}')), NOW(), '%s', '%s' );", $url, $content );

		try {
			$r = $wpdb->query( $q );
		}
		catch (Exception $e) {
			static::debug('Something went wrong: ' . $e->getMessage());
		}

		return $r;
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
		`url_content` longtext COLLATE {$wpdb->collate},
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
	 */
	public static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true )
					return;
				break;
		}

		error_log(  __CLASS__ . ": " . $message );
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