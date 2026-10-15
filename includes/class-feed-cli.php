<?php
/**
 * WP-CLI Commands for Meta Catalog Feed.
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_CLI
 */
class Feed_CLI {

	/**
	 * Register commands with WP-CLI.
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'meta-catalog', __CLASS__ );
		}
	}

	/**
	 * Generate or trigger the Meta Catalog XML feed.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Process the entire feed generation synchronously right now instead of enqueueing in Action Scheduler.
	 *
	 * ## EXAMPLES
	 *
	 *     wp meta-catalog generate
	 *     wp meta-catalog generate --sync
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate( $args, $assoc_args ) {
		$sync = isset( $assoc_args['sync'] );

		if ( $sync ) {
			\WP_CLI::log( 'Starting synchronous XML feed generation...' );
			$generator = Feed_Generator::instance();
			$init      = $generator->init_generation( true );

			if ( empty( $init['success'] ) ) {
				\WP_CLI::error( $init['message'] ?? 'Failed to start generation.' );
				return;
			}

			$total_chunks = max( 1, (int) ( $init['total_chunks'] ?? 1 ) );
			$progress     = \WP_CLI\Utils\make_progress_bar( 'Processing product batches', $total_chunks );

			$generator->process_all_synchronously(
				$init['run_id'],
				function() use ( $progress ) {
					$progress->tick();
				}
			);

			$progress->finish();

			$status = get_option( Feed_Generator::FEED_STATUS_OPTION, array() );
			\WP_CLI::success( sprintf( 'Feed generated successfully with %d items in %s s (%s)!', $status['total_items'] ?? 0, $status['duration'] ?? 0, $status['file_size_human'] ?? '0 KB' ) );
		} else {
			$res = Feed_Generator::instance()->start_generation( true );
			if ( ! empty( $res['success'] ) ) {
				\WP_CLI::success( sprintf( 'Enqueued %d products (%d chunks) into Action Scheduler.', $res['total_products'], $res['total_chunks'] ) );
			} else {
				\WP_CLI::error( $res['message'] ?? 'Error enqueueing feed generation.' );
			}
		}
	}

	/**
	 * Display current status and metrics of the Meta Catalog feed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp meta-catalog status
	 */
	public function status() {
		$status    = get_option( Feed_Generator::FEED_STATUS_OPTION, array() );
		$feed_path = Feed_Generator::get_feed_file_path();
		$exists    = file_exists( $feed_path );

		\WP_CLI::log( '=== Meta Catalog Feed Status ===' );
		\WP_CLI::log( 'File Path      : ' . $feed_path );
		\WP_CLI::log( 'File Exists    : ' . ( $exists ? 'Yes (' . size_format( filesize( $feed_path ), 2 ) . ')' : 'No' ) );
		\WP_CLI::log( 'Public URL     : ' . Feed_Server::get_public_url() );
		\WP_CLI::log( 'Current State  : ' . ( $status['status'] ?? 'idle' ) );
		\WP_CLI::log( 'Last Generated : ' . ( ! empty( $status['last_generated'] ) ? date( 'Y-m-d H:i:s', $status['last_generated'] ) : 'Never' ) );
		\WP_CLI::log( 'Total Items    : ' . ( $status['total_items'] ?? 0 ) );
		\WP_CLI::log( 'Last Duration  : ' . ( $status['duration'] ?? 0 ) . ' s' );
	}

	/**
	 * Reset stuck lock if an old job was interrupted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp meta-catalog reset-lock
	 */
	public function reset_lock() {
		Feed_Generator::reset_lock();
		\WP_CLI::success( 'Feed lock and temporary job states have been cleared.' );
	}
}
