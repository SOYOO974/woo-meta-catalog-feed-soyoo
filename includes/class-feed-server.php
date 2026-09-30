<?php
/**
 * Feed Server and HTTP Delivery endpoint.
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_Server
 */
class Feed_Server {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_feed' ), 1 );
	}

	/**
	 * Register rewrite rule for pretty feed URL (/feed/meta-catalog.xml).
	 */
	public function register_rewrite_rules() {
		add_rewrite_rule( '^feed/meta-catalog\.xml$', 'index.php?meta_catalog_feed=1', 'top' );
	}

	/**
	 * Register query variable.
	 *
	 * @param array $vars Public query variables.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'meta_catalog_feed';
		return $vars;
	}

	/**
	 * Build public feed URL with optional security token and rewrite format.
	 *
	 * @param bool $secure_only Force secured endpoint with key.
	 * @return string Full feed URL.
	 */
	public static function get_public_url( $secure_only = false ) {
		$options       = get_option( 'woo_meta_catalog_settings', array() );
		$is_key_active = ! empty( $options['enable_security_key'] );
		$token         = ! empty( $options['security_key'] ) ? trim( $options['security_key'] ) : '';

		// Canonical pretty rewrite URL: /feed/meta-catalog.xml
		$using_permalinks = (bool) get_option( 'permalink_structure' );
		if ( $using_permalinks ) {
			$base_url = home_url( '/feed/meta-catalog.xml' );
		} else {
			$base_url = add_query_arg( 'meta_catalog_feed', '1', home_url( '/' ) );
		}

		if ( ( $is_key_active || $secure_only ) && ! empty( $token ) ) {
			$base_url = add_query_arg( 'feed_key', $token, $base_url );
		}

		return set_url_scheme( $base_url, 'https' );
	}

	/**
	 * Intercept feed requests and stream XML with optimal HTTP caching headers.
	 */
	public function serve_feed() {
		$is_feed_req = false;

		if ( get_query_var( 'meta_catalog_feed' ) ) {
			$is_feed_req = true;
		} elseif ( isset( $_GET['meta_catalog_feed'] ) && '1' === (string) $_GET['meta_catalog_feed'] ) {
			$is_feed_req = true;
		} elseif ( isset( $_GET['feed'] ) && 'meta-catalog' === $_GET['feed'] ) {
			$is_feed_req = true;
		}

		if ( ! $is_feed_req ) {
			return;
		}

		// Security Token Check.
		$options       = get_option( 'woo_meta_catalog_settings', array() );
		$is_key_active = ! empty( $options['enable_security_key'] );
		$expected_key  = ! empty( $options['security_key'] ) ? trim( $options['security_key'] ) : '';

		if ( $is_key_active && ! empty( $expected_key ) ) {
			$provided_key = isset( $_GET['feed_key'] ) ? sanitize_text_field( wp_unslash( $_GET['feed_key'] ) ) : '';
			if ( ! hash_equals( $expected_key, $provided_key ) ) {
				status_header( 403 );
				header( 'Content-Type: text/plain; charset=UTF-8' );
				echo esc_html__( 'Forbidden: Invalid or missing security token for catalog feed.', 'woo-meta-catalog' );
				exit;
			}
		}

		// Check file existence.
		$file_path = Feed_Generator::get_feed_file_path();
		if ( ! file_exists( $file_path ) || filesize( $file_path ) === 0 ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo esc_html__( 'Catalog feed XML has not been generated yet. Please trigger generation from WooCommerce Admin.', 'woo-meta-catalog' );
			exit;
		}

		$file_time = filemtime( $file_path );
		$file_size = filesize( $file_path );
		$etag      = '"' . md5( $file_time . $file_size ) . '"';

		// HTTP 304 Not Modified validation (bandwidth & CPU saver for Meta scraper).
		$if_none_match     = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : false;

		if ( ( $if_none_match && $if_none_match === $etag ) || ( $if_modified_since && $if_modified_since >= $file_time ) ) {
			status_header( 304 );
			header( 'ETag: ' . $etag );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $file_time ) . ' GMT' );
			header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=86400' );
			exit;
		}

		// Disable all output buffering.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Set delivery headers.
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Length: ' . $file_size );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $file_time ) . ' GMT' );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=86400' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'X-Content-Type-Options: nosniff' );

		// Stream directly from disk.
		readfile( $file_path );
		exit;
	}
}
