<?php
/**
 * Error and event logger for Woo Meta Catalog Feed.
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_Logger
 */
class Feed_Logger {

	const LOG_OPTION = 'woo_meta_catalog_error_logs';
	const MAX_LOGS   = 50;

	/**
	 * Log a message with level and optional context.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level: 'error', 'warning', 'info', 'critical'.
	 * @param array  $context Additional debug context (run_id, step, product_id, etc.).
	 * @return void
	 */
	public static function log( $message, $level = 'error', $context = array() ) {
		$mem_bytes = memory_get_usage( true );
		$mem_peak  = memory_get_peak_usage( true );

		$entry = array(
			'id'          => 'log_' . microtime( true ) . '_' . wp_generate_password( 4, false, false ),
			'timestamp'   => time(),
			'date'        => current_time( 'Y-m-d H:i:s' ),
			'level'       => sanitize_key( $level ),
			'message'     => wp_strip_all_tags( (string) $message ),
			'memory'      => size_format( $mem_bytes, 2 ),
			'memory_peak' => size_format( $mem_peak, 2 ),
			'context'     => $context,
		);

		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		// Prepend newest first.
		array_unshift( $logs, $entry );

		// Cap to MAX_LOGS.
		if ( count( $logs ) > self::MAX_LOGS ) {
			$logs = array_slice( $logs, 0, self::MAX_LOGS );
		}

		update_option( self::LOG_OPTION, $logs, false );

		// Also mirror to PHP system error log if debug is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $level, array( 'error', 'critical', 'warning' ), true ) ) {
			$extra = ! empty( $context ) ? ' | ' . wp_json_encode( $context ) : '';
			error_log( sprintf( '[Woo Meta Catalog][%s] %s (RAM: %s)%s', strtoupper( $level ), $message, $entry['memory'], $extra ) ); // phpcs:ignore
		}
	}

	/**
	 * Log a throwable exception with backtrace info.
	 *
	 * @param \Throwable $e       Exception or Error.
	 * @param array      $context Context.
	 * @return void
	 */
	public static function log_exception( \Throwable $e, $context = array() ) {
		$context['file'] = $e->getFile() . ':' . $e->getLine();
		$context['code'] = $e->getCode();

		self::log(
			$e->getMessage(),
			'error',
			$context
		);
	}

	/**
	 * Retrieve all recorded logs.
	 *
	 * @param int $limit Max items to return.
	 * @return array
	 */
	public static function get_logs( $limit = 50 ) {
		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}
		return array_slice( $logs, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Clear all recorded logs.
	 *
	 * @return bool
	 */
	public static function clear_logs() {
		return update_option( self::LOG_OPTION, array(), false );
	}

	/**
	 * Get total count of recorded logs.
	 *
	 * @return int
	 */
	public static function get_count() {
		$logs = get_option( self::LOG_OPTION, array() );
		return is_array( $logs ) ? count( $logs ) : 0;
	}

	/**
	 * Get latest error log if available.
	 *
	 * @return array|null
	 */
	public static function get_last_error() {
		$logs = self::get_logs( 1 );
		return ! empty( $logs[0] ) ? $logs[0] : null;
	}
}
