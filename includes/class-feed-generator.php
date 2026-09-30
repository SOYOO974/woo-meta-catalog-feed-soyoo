<?php
/**
 * Feed Generator engine using WooCommerce Action Scheduler for chunked processing.
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_Generator
 */
class Feed_Generator {

	const LOCK_TRANSIENT     = 'woo_meta_catalog_lock';
	const JOB_STATE_OPTION   = 'woo_meta_catalog_job_state';
	const FEED_STATUS_OPTION = 'woo_meta_catalog_feed_status';
	const DAILY_HOOK         = 'woo_meta_catalog_daily_generation';
	const CHUNK_HOOK         = 'woo_meta_catalog_process_chunk';
	const FINALIZE_HOOK      = 'woo_meta_catalog_finalize_feed';

	/**
	 * Single instance.
	 *
	 * @var Feed_Generator|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Feed_Generator
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::DAILY_HOOK, array( $this, 'run_daily_generation' ) );
		add_action( self::CHUNK_HOOK, array( $this, 'process_chunk' ), 10, 2 );
		add_action( self::FINALIZE_HOOK, array( $this, 'finalize_feed' ), 10, 1 );
	}

	/**
	 * Get the feed output directory path.
	 *
	 * @return string
	 */
	public static function get_feed_dir() {
		$upload_dir = wp_upload_dir();
		$feed_dir   = trailingslashit( $upload_dir['basedir'] ) . 'feeds';

		if ( ! file_exists( $feed_dir ) ) {
			wp_mkdir_p( $feed_dir );
			// Write security files in directory.
			@file_put_contents( $feed_dir . '/index.html', '' );
			@file_put_contents( $feed_dir . '/.htaccess', "Options -Indexes\n<Files \"*.php\">\nDeny from all\n</Files>\n" );
		}

		return $feed_dir;
	}

	/**
	 * Get final XML feed file path.
	 *
	 * @return string
	 */
	public static function get_feed_file_path() {
		return trailingslashit( self::get_feed_dir() ) . 'meta-catalog.xml';
	}

	/**
	 * Get temporary XML feed file path.
	 *
	 * @return string
	 */
	public static function get_temp_file_path() {
		return trailingslashit( self::get_feed_dir() ) . 'meta-catalog.xml.tmp';
	}

	/**
	 * Get public direct URL to feed XML.
	 *
	 * @return string
	 */
	public static function get_feed_file_url() {
		$upload_dir = wp_upload_dir();
		$base_url   = trailingslashit( $upload_dir['baseurl'] ) . 'feeds/meta-catalog.xml';
		return set_url_scheme( $base_url, 'https' );
	}

	/**
	 * Ensure the daily Action Scheduler job is enqueued.
	 */
	public static function schedule_daily_event() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$options    = get_option( 'woo_meta_catalog_settings', array() );
		$daily_time = ! empty( $options['daily_time'] ) ? $options['daily_time'] : '03:30';

		list( $target_hour, $target_minute ) = array_map( 'intval', explode( ':', $daily_time ) );

		// Compute next execution timestamp in site local timezone.
		$now_timestamp = current_time( 'timestamp' );
		$target_today  = mktime( $target_hour, $target_minute, 0, (int) date( 'n', $now_timestamp ), (int) date( 'j', $now_timestamp ), (int) date( 'Y', $now_timestamp ) );

		if ( $target_today <= $now_timestamp ) {
			$next_run = $target_today + DAY_IN_SECONDS;
		} else {
			$next_run = $target_today;
		}

		// Convert local timestamp to UTC timestamp for Action Scheduler.
		$time_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$next_run_utc = $next_run - $time_offset;

		// If existing action is scheduled, clear it first if time changed.
		if ( as_has_scheduled_action( self::DAILY_HOOK ) ) {
			as_unschedule_all_actions( self::DAILY_HOOK );
		}

		as_schedule_recurring_action( $next_run_utc, DAY_IN_SECONDS, self::DAILY_HOOK, array(), 'woo-meta-catalog' );
	}

	/**
	 * Daily generation job run by Action Scheduler.
	 * Executes all chunks sequentially within a single background process to avoid loopback blocks.
	 */
	public function run_daily_generation() {
		$init = $this->init_generation( false );
		if ( empty( $init['success'] ) ) {
			return;
		}

		$this->process_all_synchronously( $init['run_id'] );
	}

	/**
	 * Initialize the XML feed generation process (create temp file, write XML headers, chunk IDs).
	 *
	 * @param bool $manual True if triggered manually.
	 * @return array Status array with success, message, run_id, total_chunks, total_products.
	 */
	public function init_generation( $manual = false ) {
		// 1. Anti-collision lock check (20 minutes).
		$current_lock = get_transient( self::LOCK_TRANSIENT );
		if ( $current_lock && ! $manual ) {
			return array(
				'success' => false,
				'message' => __( 'A generation job is already running.', 'woo-meta-catalog' ),
			);
		}

		$run_id = 'run_' . time() . '_' . wp_generate_password( 6, false, false );
		set_transient( self::LOCK_TRANSIENT, $run_id, 20 * MINUTE_IN_SECONDS );

		// 2. Fetch all published product IDs via lightweight SQL.
		global $wpdb;
		$product_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} 
			 WHERE post_type = 'product' 
			 AND post_status = 'publish' 
			 ORDER BY ID ASC"
		);

		if ( empty( $product_ids ) ) {
			delete_transient( self::LOCK_TRANSIENT );
			return array(
				'success' => false,
				'message' => __( 'No published products found to export.', 'woo-meta-catalog' ),
			);
		}

		// 3. Prepare temporary file and write RSS / Channel header.
		$temp_file = self::get_temp_file_path();
		$handle    = @fopen( $temp_file, 'wb' );
		if ( ! $handle ) {
			delete_transient( self::LOCK_TRANSIENT );
			return array(
				'success' => false,
				'message' => sprintf( __( 'Unable to open temporary file for writing: %s', 'woo-meta-catalog' ), $temp_file ),
			);
		}

		$store_name = get_bloginfo( 'name' );
		$store_url  = home_url( '/' );
		$store_desc = get_bloginfo( 'description' );
		$date_rfc   = date( \DATE_RFC822 );

		$header  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$header .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
		$header .= "\t<channel>\n";
		$header .= "\t\t<title><![CDATA[" . Feed_Item::sanitize_cdata( $store_name ) . "]]></title>\n";
		$header .= "\t\t<link>" . Feed_Item::escape_xml( $store_url ) . "</link>\n";
		$header .= "\t\t<description><![CDATA[" . Feed_Item::sanitize_cdata( $store_desc ) . "]]></description>\n";
		$header .= "\t\t<lastBuildDate>" . $date_rfc . "</lastBuildDate>\n";

		fwrite( $handle, $header );
		fclose( $handle );

		// 4. Chunk products into batches.
		$options    = get_option( 'woo_meta_catalog_settings', array() );
		$batch_size = ! empty( $options['batch_size'] ) ? max( 50, (int) $options['batch_size'] ) : 200;
		$chunks     = array_chunk( $product_ids, $batch_size );

		$job_state = array(
			'run_id'         => $run_id,
			'total_products' => count( $product_ids ),
			'chunks'         => $chunks,
			'total_chunks'   => count( $chunks ),
			'current_step'   => 0,
			'items_written'  => 0,
			'start_time'     => microtime( true ),
			'manual'         => $manual,
		);

		update_option( self::JOB_STATE_OPTION, $job_state, false );

		// Update public status to running.
		$feed_status = array(
			'status'         => 'running',
			'run_id'         => $run_id,
			'progress'       => 0,
			'total_chunks'   => count( $chunks ),
			'current_step'   => 0,
			'total_products' => count( $product_ids ),
			'items_written'  => 0,
			'started_at'     => current_time( 'mysql' ),
			'message'        => sprintf( __( 'Job started: 0 / %d products processed', 'woo-meta-catalog' ), count( $product_ids ) ),
		);
		update_option( self::FEED_STATUS_OPTION, $feed_status, false );

		return array(
			'success'        => true,
			'run_id'         => $run_id,
			'total_products' => count( $product_ids ),
			'total_chunks'   => count( $chunks ),
			'batch_size'     => $batch_size,
			'message'        => __( 'Feed generation initialized successfully.', 'woo-meta-catalog' ),
		);
	}

	/**
	 * Start the XML feed generation process via Action Scheduler.
	 *
	 * @param bool $manual True if triggered manually.
	 * @return array Status array with success, message, run_id.
	 */
	public function start_generation( $manual = false ) {
		$init = $this->init_generation( $manual );
		if ( empty( $init['success'] ) ) {
			return $init;
		}

		$run_id = $init['run_id'];

		// Enqueue first chunk via Action Scheduler if available.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::CHUNK_HOOK,
				array( 'run_id' => $run_id, 'step' => 0 ),
				'woo-meta-catalog'
			);
		} else {
			// Fallback: process synchronously if Action Scheduler missing.
			$this->process_all_synchronously( $run_id );
		}

		return array(
			'success'        => true,
			'run_id'         => $run_id,
			'total_products' => $init['total_products'],
			'total_chunks'   => $init['total_chunks'],
			'message'        => __( 'Feed generation started in background.', 'woo-meta-catalog' ),
		);
	}

	/**
	 * Process a single batch chunk of products.
	 *
	 * @param string $run_id Run ID.
	 * @param int    $step   Chunk index.
	 * @return array
	 */
	public function process_chunk( $run_id, $step ) {
		$job_state = get_option( self::JOB_STATE_OPTION, array() );

		// Validation check.
		if ( empty( $job_state ) || empty( $job_state['run_id'] ) || $job_state['run_id'] !== $run_id ) {
			return array(
				'success' => false,
				'message' => __( 'Identifiant de lot invalide ou expiré.', 'woo-meta-catalog' ),
			);
		}

		$chunks = ! empty( $job_state['chunks'] ) ? $job_state['chunks'] : array();
		if ( ! isset( $chunks[ $step ] ) ) {
			return array(
				'success' => false,
				'message' => sprintf( __( 'Étape introuvable : %d', 'woo-meta-catalog' ), $step ),
			);
		}

		$temp_file = self::get_temp_file_path();
		$handle    = @fopen( $temp_file, 'ab' );
		if ( ! $handle ) {
			$this->fail_job( $run_id, sprintf( __( 'Cannot append to temp file at step %d', 'woo-meta-catalog' ), $step ) );
			return array(
				'success' => false,
				'message' => __( 'Échec d\'ouverture du fichier temporaire.', 'woo-meta-catalog' ),
			);
		}

		$options       = get_option( 'woo_meta_catalog_settings', array() );
		$product_ids   = $chunks[ $step ];
		$items_written = 0;

		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! is_a( $product, '\WC_Product' ) ) {
				continue;
			}

			// If variable product, export its individual variations.
			if ( $product->is_type( 'variable' ) ) {
				$children_ids = $product->get_children();
				if ( ! empty( $children_ids ) ) {
					foreach ( $children_ids as $child_id ) {
						$variation = wc_get_product( $child_id );
						if ( $variation && is_a( $variation, '\WC_Product_Variation' ) ) {
							$xml_item = Feed_Item::build( $variation, $product, $options );
							if ( ! empty( $xml_item ) ) {
								fwrite( $handle, $xml_item );
								$items_written++;
							}
							unset( $variation );
						}
					}
				}
			} else {
				// Standard product (simple, external, grouped).
				$xml_item = Feed_Item::build( $product, null, $options );
				if ( ! empty( $xml_item ) ) {
					fwrite( $handle, $xml_item );
					$items_written++;
				}
			}

			// Free memory cache for this product.
			wp_cache_delete( $pid, 'posts' );
			wp_cache_delete( $pid, 'post_meta' );
			clean_post_cache( $pid );
			unset( $product );
		}

		fclose( $handle );

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		// Update job state.
		$job_state['items_written'] += $items_written;
		$job_state['current_step']   = $step + 1;
		update_option( self::JOB_STATE_OPTION, $job_state, false );

		$total_chunks = (int) $job_state['total_chunks'];
		$next_step    = $step + 1;
		$progress     = (int) round( ( $next_step / $total_chunks ) * 100 );

		$feed_status = array(
			'status'         => 'running',
			'run_id'         => $run_id,
			'progress'       => min( 99, $progress ),
			'total_chunks'   => $total_chunks,
			'current_step'   => $next_step,
			'total_products' => (int) $job_state['total_products'],
			'items_written'  => (int) $job_state['items_written'],
			'message'        => sprintf( __( 'Processing batch %1$d / %2$d (%3$d%%)', 'woo-meta-catalog' ), $next_step, $total_chunks, $progress ),
		);
		update_option( self::FEED_STATUS_OPTION, $feed_status, false );

		// Determine next action for background non-AJAX execution.
		if ( ! wp_doing_ajax() ) {
			if ( $next_step < $total_chunks ) {
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action(
						self::CHUNK_HOOK,
						array( 'run_id' => $run_id, 'step' => $next_step ),
						'woo-meta-catalog'
					);
				}
			} else {
				// All chunks done! Enqueue finalization.
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action(
						self::FINALIZE_HOOK,
						array( 'run_id' => $run_id ),
						'woo-meta-catalog'
					);
				} else {
					$this->finalize_feed( $run_id );
				}
			}
		}

		return array(
			'success'        => true,
			'run_id'         => $run_id,
			'step'           => $step,
			'next_step'      => $next_step,
			'total_chunks'   => $total_chunks,
			'items_written'  => $items_written,
			'total_items'    => (int) $job_state['items_written'],
			'progress'       => $progress,
			'message'        => sprintf( __( 'Batch %1$d / %2$d processed (%3$d%%)', 'woo-meta-catalog' ), $next_step, $total_chunks, $progress ),
		);
	}

	/**
	 * Finalize XML feed, close root tags, atomic rename, and record stats.
	 *
	 * @param string $run_id Run ID.
	 * @return array
	 */
	public function finalize_feed( $run_id ) {
		$job_state = get_option( self::JOB_STATE_OPTION, array() );

		if ( empty( $job_state ) || empty( $job_state['run_id'] ) || $job_state['run_id'] !== $run_id ) {
			return array(
				'success' => false,
				'message' => __( 'Identifiant de lot introuvable pour la finalisation.', 'woo-meta-catalog' ),
			);
		}

		$temp_file  = self::get_temp_file_path();
		$final_file = self::get_feed_file_path();

		// Write XML footer.
		$handle = @fopen( $temp_file, 'ab' );
		if ( $handle ) {
			$footer = "\t</channel>\n</rss>\n";
			fwrite( $handle, $footer );
			fclose( $handle );
		}

		// Atomic file rename.
		$renamed = @rename( $temp_file, $final_file );
		if ( ! $renamed ) {
			// Fallback copy & unlink for filesystems where rename locks.
			if ( @copy( $temp_file, $final_file ) ) {
				@unlink( $temp_file );
				$renamed = true;
			}
		}

		if ( ! $renamed || ! file_exists( $final_file ) ) {
			$this->fail_job( $run_id, __( 'Failed to replace the final feed XML file.', 'woo-meta-catalog' ) );
			return array(
				'success' => false,
				'message' => __( 'Échec d\'écriture du fichier final XML.', 'woo-meta-catalog' ),
			);
		}

		// Compute metrics.
		$duration   = round( microtime( true ) - (float) $job_state['start_time'], 2 );
		$file_size  = filesize( $final_file );
		$total_item = (int) $job_state['items_written'];

		$feed_status = array(
			'success'        => true,
			'status'         => 'completed',
			'run_id'         => $run_id,
			'progress'       => 100,
			'last_generated' => current_time( 'timestamp' ),
			'duration'       => $duration,
			'total_items'    => $total_item,
			'file_size'      => $file_size,
			'file_size_human'=> size_format( $file_size, 2 ),
			'file_path'      => $final_file,
			'file_url'       => self::get_feed_file_url(),
			'canonical_url'  => Feed_Server::get_public_url(),
			'message'        => sprintf(
				__( 'Feed successfully generated with %1$d items in %2$s seconds (%3$s).', 'woo-meta-catalog' ),
				$total_item,
				$duration,
				size_format( $file_size, 2 )
			),
		);

		update_option( self::FEED_STATUS_OPTION, $feed_status, false );

		// Clean state & lock.
		delete_transient( self::LOCK_TRANSIENT );
		delete_option( self::JOB_STATE_OPTION );

		// Trigger hook for external caches (WP Agent Bridge, Cloudflare, etc.).
		do_action( 'woo_meta_catalog_feed_generated', $final_file, $total_item, $duration );

		return $feed_status;
	}

	/**
	 * Mark a job as failed and clean transient locks.
	 *
	 * @param string $run_id Run ID.
	 * @param string $error_message Error message.
	 */
	public function fail_job( $run_id, $error_message ) {
		$feed_status = array(
			'status'        => 'failed',
			'run_id'        => $run_id,
			'failed_at'     => current_time( 'mysql' ),
			'error_message' => $error_message,
			'message'       => sprintf( __( 'Generation failed: %s', 'woo-meta-catalog' ), $error_message ),
		);
		update_option( self::FEED_STATUS_OPTION, $feed_status, false );
		delete_transient( self::LOCK_TRANSIENT );
		delete_option( self::JOB_STATE_OPTION );

		$temp_file = self::get_temp_file_path();
		if ( file_exists( $temp_file ) ) {
			@unlink( $temp_file );
		}
	}

	/**
	 * Reset lock manually if stuck.
	 */
	public static function reset_lock() {
		delete_transient( self::LOCK_TRANSIENT );
		delete_option( self::JOB_STATE_OPTION );
		$status = get_option( self::FEED_STATUS_OPTION, array() );
		if ( isset( $status['status'] ) && 'running' === $status['status'] ) {
			$status['status']  = 'failed';
			$status['message'] = __( 'Process manually reset by administrator.', 'woo-meta-catalog' );
			update_option( self::FEED_STATUS_OPTION, $status, false );
		}
	}

	/**
	 * Synchronous fallback if Action Scheduler is unavailable or for CLI / daily cron execution.
	 *
	 * @param string $run_id Run ID.
	 * @return array|false
	 */
	public function process_all_synchronously( $run_id ) {
		$job_state = get_option( self::JOB_STATE_OPTION, array() );
		if ( empty( $job_state['chunks'] ) ) {
			return false;
		}

		if ( function_exists( 'set_time_limit' ) && false === strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		foreach ( array_keys( $job_state['chunks'] ) as $step ) {
			$this->process_chunk( $run_id, $step );
		}

		return $this->finalize_feed( $run_id );
	}
}
