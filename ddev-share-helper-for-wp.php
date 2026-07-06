<?php
// #ddev-generated: installed by the ddev-share-helper-for-wp DDEV add-on.
// Re-running `ddev add-on get` updates this file; `ddev add-on remove` deletes it.
/**
 * Plugin Name: DDEV Share Helper for WP
 * Description: Makes WordPress work seamlessly through `ddev share` tunnels (cloudflared/ngrok) by rewriting URLs at runtime. No database changes. Inspired by LocalWP's Live Link Helper.
 * Version: 1.0.1
 * License: GPLv2 or later
 *
 * How it works:
 * - A normal local request (Host = <project>.ddev.site) does nothing at all.
 * - A tunneled request arrives with the tunnel hostname (e.g. xyz.trycloudflare.com)
 *   in the Host header. When that host differs from DDEV_PRIMARY_URL, this plugin:
 *     1. marks the request as HTTPS so is_ssl(), cookies, and redirects behave;
 *     2. filters every URL WordPress generates, swapping the local host for the
 *        tunnel host (same filter list LocalWP uses);
 *     3. buffers the final output and rewrites any remaining local URLs — this
 *        catches URLs hardcoded in post content, srcset, inline JS, and JSON;
 *     4. reverse-swaps on option/post saves so the tunnel URL never reaches the DB;
 *     5. disables canonical redirects and marks responses as privately cacheable.
 */

class DDEV_Share_Helper_For_WP {

	/** @var string Host[:port] of the DDEV primary URL, e.g. "mobile.ddev.site" */
	private $local_host;

	/** @var string Host of the incoming tunneled request, e.g. "xyz.trycloudflare.com" */
	private $tunnel_host;

	public function __construct() {
		if ( getenv( 'IS_DDEV_PROJECT' ) !== 'true' || empty( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}

		$primary = getenv( 'DDEV_PRIMARY_URL' );
		if ( ! $primary ) {
			return;
		}

		$this->local_host  = parse_url( $primary, PHP_URL_HOST );
		$port              = parse_url( $primary, PHP_URL_PORT );
		if ( $port && ! in_array( (int) $port, array( 80, 443 ), true ) ) {
			$this->local_host .= ':' . $port;
		}

		$this->tunnel_host = $_SERVER['HTTP_HOST'];

		// The Host header is reflected into every rewritten URL, so refuse
		// anything that doesn't look like a plain hostname[:port].
		if ( ! preg_match( '/^[a-z0-9.-]+(:\d+)?$/i', $this->tunnel_host ) ) {
			return;
		}

		// Not a tunneled request — stay completely inert.
		if ( $this->tunnel_host === $this->local_host || ! $this->is_tunnel_request() ) {
			return;
		}

		// Tunnels terminate TLS upstream and forward plain HTTP. Tell WordPress
		// the visitor is on HTTPS so is_ssl(), auth cookies, and generated
		// schemes are correct and we avoid redirect loops.
		$_SERVER['HTTPS'] = 'on';

		ob_start( array( $this, 'rewrite_output' ) );

		$this->add_filters();
	}

	/**
	 * A request is considered tunneled when the proxy says it forwarded HTTPS,
	 * or the Host is a known tunnel domain. Requests for the real local host
	 * never reach this check.
	 */
	private function is_tunnel_request() {
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
			return true;
		}

		return (bool) preg_match( '/\.(trycloudflare\.com|cfargotunnel\.com|ngrok(-free)?\.(app|dev)|ngrok\.io)$/i', $this->tunnel_host );
	}

	private function add_filters() {
		add_action( 'send_headers', array( $this, 'send_private_cache_control_header' ), 9999 );

		// Canonical redirect would bounce the visitor back to the local URL.
		remove_action( 'template_redirect', 'redirect_canonical' );

		$local_to_tunnel_filters = array(
			'option_home',
			'option_siteurl',
			'site_option_siteurl',
			'blog_option_siteurl',
			'home_url',
			'site_url',
			'get_site_url',
			'network_home_url',
			'network_site_url',
			'admin_url',
			'get_admin_url',
			'network_admin_url',
			'includes_url',
			'content_url',
			'plugins_url',
			'get_rest_url',
			'wp_redirect',
			'login_url',
			'logout_url',
			'lostpassword_url',
			'the_permalink',
			'post_link',
			'post_type_link',
			'page_link',
			'attachment_link',
			'term_link',
			'get_shortlink',
			'post_type_archive_link',
			'get_pagenum_link',
			'get_comments_pagenum_link',
			'get_comment_link',
			'search_link',
			'day_link',
			'month_link',
			'year_link',
			'author_link',
			'script_loader_src',
			'style_loader_src',
			'stylesheet_directory_uri',
			'template_directory_uri',
			'get_stylesheet_uri',
			'get_locale_stylesheet_uri',
			'theme_root_uri',
			'wp_get_attachment_url',
			'wp_get_attachment_thumb_url',
			'upload_dir',
			'preview_post_link',
		);

		// PHP_INT_MAX so we run after WP applies the WP_HOME/WP_SITEURL
		// constants via its own option_home/option_siteurl filters.
		foreach ( $local_to_tunnel_filters as $filter ) {
			add_filter( $filter, array( $this, 'make_link_tunnel' ), PHP_INT_MAX );
		}

		// Anything being written to the database gets the tunnel host swapped
		// back to the local host, so the DB stays clean.
		add_filter( 'pre_update_option', array( $this, 'make_link_local' ), 9999 );
		add_filter( 'wp_insert_post_data', array( $this, 'make_link_local_in_posts' ), 9999 );
	}

	/**
	 * The host was replaced per-visitor, so shared caches must not store it.
	 */
	public function send_private_cache_control_header() {
		header( 'Cache-Control: private' );
	}

	/**
	 * local host → tunnel host, for generated URLs. Handles strings, arrays
	 * (upload_dir), and nested values.
	 */
	public function make_link_tunnel( $value ) {
		return $this->map_replace( $value, array( $this, 'to_tunnel' ) );
	}

	/**
	 * tunnel host → local host, for values being persisted.
	 */
	public function make_link_local( $value ) {
		return $this->map_replace( $value, array( $this, 'to_local' ) );
	}

	public function make_link_local_in_posts( $data ) {
		if ( isset( $data['post_content'] ) ) {
			$data['post_content'] = $this->to_local( $data['post_content'] );
		}
		if ( isset( $data['post_excerpt'] ) ) {
			$data['post_excerpt'] = $this->to_local( $data['post_excerpt'] );
		}

		return $data;
	}

	/**
	 * Final pass over the whole response body. Replacing just the bare host
	 * string covers http://, https://, protocol-relative //host, srcset lists,
	 * and JSON-escaped URLs (https:\/\/host) in one go, then upgrades any
	 * http:// reference to the tunnel to https:// to avoid mixed content.
	 */
	public function rewrite_output( $html ) {
		foreach ( headers_list() as $header ) {
			if ( stripos( $header, 'Content-Type:' ) === 0
				&& ! preg_match( '#text/|json|xml|javascript#i', $header ) ) {
				return $html; // Binary response — leave untouched.
			}
		}

		$html = str_replace( $this->local_host, $this->tunnel_host, $html );
		$html = str_replace(
			array( 'http://' . $this->tunnel_host, 'http:\/\/' . $this->tunnel_host ),
			array( 'https://' . $this->tunnel_host, 'https:\/\/' . $this->tunnel_host ),
			$html
		);

		return $html;
	}

	private function to_tunnel( $str ) {
		$str = str_replace( 'www.' . $this->local_host, $this->tunnel_host, $str );
		$str = str_replace( $this->local_host, $this->tunnel_host, $str );
		$str = str_replace( 'http://' . $this->tunnel_host, 'https://' . $this->tunnel_host, $str );

		return $str;
	}

	private function to_local( $str ) {
		return str_replace( $this->tunnel_host, $this->local_host, $str );
	}

	/**
	 * Apply a string replacement across strings, arrays, and objects without
	 * the serialize/unserialize round-trip LocalWP uses (which corrupts
	 * serialized string lengths).
	 */
	private function map_replace( $value, $callback ) {
		if ( is_string( $value ) ) {
			return call_user_func( $callback, $value );
		}

		if ( function_exists( 'map_deep' ) && ( is_array( $value ) || is_object( $value ) ) ) {
			return map_deep(
				$value,
				function ( $item ) use ( $callback ) {
					return is_string( $item ) ? call_user_func( $callback, $item ) : $item;
				}
			);
		}

		return $value;
	}
}

new DDEV_Share_Helper_For_WP();
