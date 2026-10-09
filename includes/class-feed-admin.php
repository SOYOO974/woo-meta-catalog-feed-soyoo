<?php
/**
 * Admin interface and settings page for WooCommerce.
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_Admin
 */
class Feed_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 65 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 999 );
		add_action( 'in_admin_header', array( $this, 'suppress_admin_notices' ), 999 );
		add_action( 'wp_ajax_woo_meta_catalog_init_generation', array( $this, 'ajax_init_generation' ) );
		add_action( 'wp_ajax_woo_meta_catalog_process_chunk', array( $this, 'ajax_process_chunk' ) );
		add_action( 'wp_ajax_woo_meta_catalog_finalize_feed', array( $this, 'ajax_finalize_feed' ) );
		add_action( 'wp_ajax_woo_meta_catalog_trigger_generation', array( $this, 'ajax_trigger_generation' ) );
		add_action( 'wp_ajax_woo_meta_catalog_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_woo_meta_catalog_reset_lock', array( $this, 'ajax_reset_lock' ) );
		add_action( 'wp_ajax_woo_meta_catalog_test_cdn', array( $this, 'ajax_test_cdn' ) );
		add_action( 'wp_ajax_woo_meta_catalog_run_diagnostic_test', array( $this, 'ajax_run_diagnostic_test' ) );
		add_action( 'wp_ajax_woo_meta_catalog_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_woo_meta_catalog_recalculate_trending', array( $this, 'ajax_recalculate_trending' ) );
		add_action( 'admin_init', array( $this, 'save_settings' ) );
	}

	/**
	 * Register submenu under WooCommerce.
	 */
	public function register_menu() {
		$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'Flux Meta Catalog', 'woo-meta-catalog' ),
			__( 'Flux Meta Catalog', 'woo-meta-catalog' ),
			'manage_woocommerce',
			'woo-meta-catalog-feed',
			array( $this, 'render_page' )
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'on_page_load' ) );
		}
	}

	/**
	 * Actions executed when the plugin settings page is loaded.
	 */
	public function on_page_load() {
		add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 999 );
		add_action( 'in_admin_header', array( $this, 'suppress_admin_notices' ), 999 );
	}

	/**
	 * Check whether current admin screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function is_feed_admin_screen() {
		if ( isset( $_GET['page'] ) && 'woo-meta-catalog-feed' === $_GET['page'] ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ( 'woocommerce_page_woo-meta-catalog-feed' === $screen->id || false !== strpos( $screen->id, 'woo-meta-catalog-feed' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Suppress all third-party admin notices to keep our settings interface clean and focused.
	 */
	public function suppress_admin_notices() {
		if ( ! $this->is_feed_admin_screen() ) {
			return;
		}

		remove_all_actions( 'wp_admin_notices' );
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Enqueue admin stylesheet and scripts.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'woo-meta-catalog-feed' ) ) {
			return;
		}

		wp_enqueue_style(
			'woo-meta-catalog-admin',
			plugins_url( 'assets/css/admin.css', WOO_META_CATALOG_FEED_FILE ),
			array(),
			WOO_META_CATALOG_FEED_VERSION
		);

		wp_enqueue_script(
			'woo-meta-catalog-admin',
			plugins_url( 'assets/js/admin.js', WOO_META_CATALOG_FEED_FILE ),
			array( 'jquery' ),
			WOO_META_CATALOG_FEED_VERSION,
			true
		);

		wp_localize_script(
			'woo-meta-catalog-admin',
			'wooMetaCatalogVars',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'woo_meta_catalog_admin_nonce' ),
				'copiedTxt' => __( 'Copié dans le presse-papier !', 'woo-meta-catalog' ),
			)
		);
	}

	/**
	 * Handle AJAX init generation.
	 */
	public function ajax_init_generation() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		$result = Feed_Generator::instance()->init_generation( true );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle AJAX process chunk.
	 */
	public function ajax_process_chunk() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$step   = isset( $_POST['step'] ) ? intval( $_POST['step'] ) : 0;

		$result = Feed_Generator::instance()->process_chunk( $run_id, $step );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle AJAX finalize feed.
	 */
	public function ajax_finalize_feed() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

		$result = Feed_Generator::instance()->finalize_feed( $run_id );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle AJAX trigger generation (Action Scheduler fallback).
	 */
	public function ajax_trigger_generation() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		$result = Feed_Generator::instance()->start_generation( true );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle AJAX status check.
	 */
	public function ajax_check_status() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		$status = get_option( Feed_Generator::FEED_STATUS_OPTION, array() );
		wp_send_json_success( $status );
	}

	/**
	 * Handle AJAX reset lock.
	 */
	public function ajax_reset_lock() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error();
		}

		Feed_Generator::reset_lock();
		wp_send_json_success( array( 'message' => __( 'Verrou réinitialisé avec succès.', 'woo-meta-catalog' ) ) );
	}

	/**
	 * Handle AJAX test CDN connection.
	 */
	public function ajax_test_cdn() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		$endpoint    = isset( $_POST['endpoint'] ) ? esc_url_raw( trim( wp_unslash( $_POST['endpoint'] ) ) ) : '';
		$provider    = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'imagekit';
		$auto_square = ! empty( $_POST['auto_square'] ) ? 1 : 0;
		$force_jpeg  = ! empty( $_POST['force_jpeg'] ) ? 1 : 0;

		if ( empty( $endpoint ) ) {
			wp_send_json_error( array( 'message' => __( 'Veuillez saisir une URL Endpoint CDN.', 'woo-meta-catalog' ) ) );
		}

		// Find a sample product with an image.
		$sample_image_url = '';
		$sample_title     = '';

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => '_thumbnail_id',
					'value'   => 0,
					'compare' => '>',
				),
			),
		);
		$products = get_posts( $query_args );

		if ( ! empty( $products ) ) {
			$product          = wc_get_product( $products[0]->ID );
			$sample_title     = $product ? $product->get_name() : '';
			$thumb_id         = $product ? $product->get_image_id() : 0;
			$sample_image_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '';
		}

		// Fallback: check any attachment in media library.
		if ( empty( $sample_image_url ) ) {
			$attachments = get_posts( array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => 1,
				'post_status'    => 'inherit',
			) );
			if ( ! empty( $attachments ) ) {
				$sample_image_url = wp_get_attachment_image_url( $attachments[0]->ID, 'full' );
				$sample_title     = $attachments[0]->post_title;
			}
		}

		if ( empty( $sample_image_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucune image produit trouvée dans la médiathèque pour effectuer le test.', 'woo-meta-catalog' ) ) );
		}

		// Format test URL.
		$test_options = array(
			'enable_image_cdn'      => 1,
			'image_cdn_provider'    => $provider,
			'image_cdn_endpoint'    => $endpoint,
			'image_cdn_auto_square' => $auto_square,
			'image_cdn_force_jpeg'  => $force_jpeg,
		);
		$cdn_test_url = Feed_Item::format_image_url( $sample_image_url, $test_options );

		// Perform live HTTP GET test request using Meta\'s crawler User-Agent.
		$start_time = microtime( true );
		$response   = wp_remote_get( $cdn_test_url, array(
			'timeout'    => 12,
			'user-agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
			'sslverify'  => true,
		) );
		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array(
				'message' => sprintf( __( 'Échec de connexion réseau au CDN : %s', 'woo-meta-catalog' ), $response->get_error_message() ),
				'url'     => $cdn_test_url,
			) );
		}

		$status_code  = wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( 200 === $status_code ) {
			wp_send_json_success( array(
				'message'      => sprintf( __( 'Connexion CDN réussie ! (HTTP 200 en %d ms)', 'woo-meta-catalog' ), $duration_ms ),
				'cdn_url'      => $cdn_test_url,
				'original_url' => $sample_image_url,
				'content_type' => $content_type,
				'product_name' => $sample_title,
				'duration_ms'  => $duration_ms,
			) );
		} else {
			wp_send_json_error( array(
				'message'     => sprintf( __( 'Le CDN a retourné un code HTTP %d. Vérifiez que votre Origine Web Folder dans ImageKit pointe bien vers « %s »', 'woo-meta-catalog' ), $status_code, home_url() ),
				'cdn_url'     => $cdn_test_url,
				'status_code' => $status_code,
			) );
		}
	}

	/**
	 * Handle AJAX run diagnostic sample test.
	 */
	public function ajax_run_diagnostic_test() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		$result = Feed_Diagnostic::run_sample_test( 5 );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Handle AJAX clear error logs.
	 */
	public function ajax_clear_logs() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		Feed_Logger::clear_logs();
		wp_send_json_success( array( 'message' => __( 'Journal des incidents vidé avec succès.', 'woo-meta-catalog' ) ) );
	}

	/**
	 * Handle AJAX recalculate trending products.
	 */
	public function ajax_recalculate_trending() {
		check_ajax_referer( 'woo_meta_catalog_admin_nonce', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'woo-meta-catalog' ) ) );
		}

		Feed_Item::calculate_trending_ids( true );
		$cache = get_option( 'woo_meta_catalog_trending_cache', array() );

		ob_start();
		$this->render_trending_preview_box( $cache );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'message' => __( 'Sélection Tendance recalculée avec succès.', 'woo-meta-catalog' ),
			'html'    => $html,
			'total'   => $cache['total_count'] ?? 0,
		) );
	}

	/**
	 * Render preview table and diagnostics for the trending marker selection.
	 *
	 * @param array|null $cache Cached trending data array.
	 */
	public function render_trending_preview_box( $cache = null ) {
		if ( null === $cache ) {
			$cache = get_option( 'woo_meta_catalog_trending_cache', array() );
		}

		$options     = get_option( 'woo_meta_catalog_settings', array() );
		$mode        = ! empty( $options['label_trending_mode'] ) ? $options['label_trending_mode'] : 'classic';
		$is_seasonal = ( 'seasonal' === $mode );

		$items       = ! empty( $cache['items'] ) && is_array( $cache['items'] ) ? $cache['items'] : array();
		$total_count = ! empty( $cache['total_count'] ) ? (int) $cache['total_count'] : count( $items );
		$calc_date   = ! empty( $cache['calculated_at'] ) ? $cache['calculated_at'] : '-';
		$b1_warning  = ! empty( $cache['b1_warning'] );

		$count_a  = isset( $cache['count_a'] ) ? (int) $cache['count_a'] : 0;
		$count_b1 = isset( $cache['count_b1'] ) ? (int) $cache['count_b1'] : 0;
		$count_b2 = isset( $cache['count_b2'] ) ? (int) $cache['count_b2'] : 0;
		$quota_a  = isset( $cache['quota_a'] ) ? (int) $cache['quota_a'] : 0;
		$quota_b  = isset( $cache['quota_b'] ) ? (int) $cache['quota_b'] : 0;
		$b2_cap   = isset( $cache['b2_cap'] ) ? (int) $cache['b2_cap'] : 0;
		?>
		<div id="woo-meta-trending-preview-box" class="woo-meta-trending-preview-wrap" style="margin-top: 15px; background: #ffffff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px;">
			<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f1;">
				<div>
					<h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #1e293b;">
						<span class="dashicons dashicons-chart-line" style="vertical-align: middle; margin-right: 4px; color: #0284c7;"></span>
						<?php esc_html_e( 'Aperçu & Diagnostic de la sélection Tendance', 'woo-meta-catalog' ); ?>
					</h4>
					<div style="font-size: 12px; color: #64748b;">
						<?php if ( $is_seasonal ) : ?>
							<span class="diag-badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; margin-right: 6px;"><?php esc_html_e( 'Mode Saisonnier', 'woo-meta-catalog' ); ?></span>
						<?php else : ?>
							<span class="diag-badge" style="background: #f1f5f9; color: #475569; font-weight: 700; margin-right: 6px;"><?php esc_html_e( 'Mode Classique', 'woo-meta-catalog' ); ?></span>
						<?php endif; ?>
						<?php printf( esc_html__( 'Total sélectionné : %d produit(s) • Dernier calcul : %s', 'woo-meta-catalog' ), $total_count, esc_html( $calc_date ) ); ?>
					</div>
				</div>
				<div>
					<button type="button" id="btn-recalculate-trending" class="button button-secondary">
						<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 4px;"></span>
						<span class="btn-text"><?php esc_html_e( 'Recalculer maintenant', 'woo-meta-catalog' ); ?></span>
					</button>
				</div>
			</div>

			<?php if ( $is_seasonal && ! empty( $cache ) ) : ?>
				<!-- METRICS PILLS -->
				<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px;">
					<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; padding: 6px 12px; font-size: 12px; color: #15803d;">
						<strong><?php esc_html_e( 'Source A (Ventes récentes) :', 'woo-meta-catalog' ); ?></strong> <?php echo esc_html( $count_a ); ?> / <?php echo esc_html( $quota_a ); ?>
					</div>
					<div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 6px 12px; font-size: 12px; color: #1d4ed8;">
						<strong><?php esc_html_e( 'Source B1 (Année N-1 directe) :', 'woo-meta-catalog' ); ?></strong> <?php echo esc_html( $count_b1 ); ?> / <?php echo esc_html( $quota_b ); ?>
					</div>
					<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 4px; padding: 6px 12px; font-size: 12px; color: #b45309;">
						<strong><?php esc_html_e( 'Source B2 (Repli catégorie) :', 'woo-meta-catalog' ); ?></strong> <?php echo esc_html( $count_b2 ); ?> (plafond max : <?php echo esc_html( $b2_cap ); ?>)
					</div>
				</div>

				<?php if ( $b1_warning ) : ?>
					<div class="notice notice-warning inline" style="margin: 0 0 14px 0; padding: 10px 14px; border-left: 4px solid #f59e0b; background: #fffbeb; border-radius: 4px;">
						<p style="margin: 0; font-size: 13px; color: #92400e; line-height: 1.4;">
							<strong>⚠️ <?php esc_html_e( 'Attention — Renouvellement de fiches détecté :', 'woo-meta-catalog' ); ?></strong>
							<?php printf( esc_html__( 'La source Année précédente (B1) ne couvre que %1$d / %2$d produits prévus. Les fiches produits ont très probablement été recréées cette année. Le repli par catégorie (B2) a pris le relais pour %3$d produit(s).', 'woo-meta-catalog' ), $count_b1, $quota_b, $count_b2 ); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( empty( $items ) ) : ?>
				<p style="margin: 0; color: #64748b; font-style: italic;">
					<?php esc_html_e( 'Aucun produit calculé pour le moment. Cliquez sur « Recalculer maintenant » pour générer la sélection.', 'woo-meta-catalog' ); ?>
				</p>
			<?php else : ?>
				<div style="max-height: 420px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 4px;">
					<table class="wp-list-table widefat fixed striped" style="border: none; margin: 0;">
						<thead style="position: sticky; top: 0; background: #f8fafc; z-index: 2;">
							<tr>
								<th style="width: 40px; text-align: center;">#</th>
								<th style="min-width: 220px;"><?php esc_html_e( 'Produit', 'woo-meta-catalog' ); ?></th>
								<th style="width: 140px;"><?php esc_html_e( 'Source', 'woo-meta-catalog' ); ?></th>
								<th style="width: 150px;"><?php esc_html_e( 'Score / Ventes', 'woo-meta-catalog' ); ?></th>
								<th style="width: 100px;"><?php esc_html_e( 'Prix effectif', 'woo-meta-catalog' ); ?></th>
								<th style="width: 100px;"><?php esc_html_e( 'Stock', 'woo-meta-catalog' ); ?></th>
								<th style="width: 160px;"><?php esc_html_e( 'Catégorie repli', 'woo-meta-catalog' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $idx => $item ) : ?>
								<tr>
									<td style="text-align: center; color: #94a3b8; font-weight: 600;"><?php echo (int) ( $idx + 1 ); ?></td>
									<td>
										<div style="display: flex; align-items: center; gap: 10px;">
											<?php if ( ! empty( $item['thumb'] ) ) : ?>
												<img src="<?php echo esc_url( $item['thumb'] ); ?>" style="width: 36px; height: 36px; object-fit: cover; border-radius: 4px; border: 1px solid #e2e8f0; flex-shrink: 0;" alt="" />
											<?php endif; ?>
											<div>
												<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $item['id'] . '&action=edit' ) ); ?>" target="_blank" style="font-weight: 600; text-decoration: none;">
													<?php echo esc_html( $item['name'] ); ?>
												</a>
												<div style="font-size: 11px; color: #64748b;">ID: <?php echo (int) $item['id']; ?></div>
											</div>
										</div>
									</td>
									<td>
										<?php if ( 'A' === $item['source'] ) : ?>
											<span class="diag-badge" style="background: #dcfce7; color: #15803d;"><?php esc_html_e( 'Récent [A]', 'woo-meta-catalog' ); ?></span>
										<?php elseif ( 'B1' === $item['source'] ) : ?>
											<span class="diag-badge" style="background: #dbeafe; color: #1e40af;"><?php esc_html_e( 'Année N-1 [B1]', 'woo-meta-catalog' ); ?></span>
										<?php elseif ( 'B2' === $item['source'] ) : ?>
											<span class="diag-badge" style="background: #fef3c7; color: #b45309;"><?php esc_html_e( 'Repli [B2]', 'woo-meta-catalog' ); ?></span>
										<?php else : ?>
											<span class="diag-badge" style="background: #f1f5f9; color: #475569;"><?php echo esc_html( $item['source'] ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( 'A' === $item['source'] ) : ?>
											<strong><?php echo esc_html( $item['score'] ); ?></strong>
											<span style="font-size: 11px; color: #64748b; display: block;">
												<?php
												if ( ! empty( $item['atc'] ) ) {
													printf( esc_html__( '%1$d ventes • %2$d ajouts', 'woo-meta-catalog' ), (int) $item['sales'], (int) $item['atc'] );
												} else {
													printf( esc_html__( '%d ventes', 'woo-meta-catalog' ), (int) $item['sales'] );
												}
												?>
											</span>
										<?php elseif ( 'B1' === $item['source'] ) : ?>
											<strong><?php echo (int) $item['sales']; ?></strong>
											<span style="font-size: 11px; color: #64748b; display: block;"><?php esc_html_e( 'ventes l\'an passé', 'woo-meta-catalog' ); ?></span>
										<?php elseif ( 'B2' === $item['source'] ) : ?>
											<span style="font-size: 11px; color: #64748b;"><?php esc_html_e( 'Nouveauté catégorie', 'woo-meta-catalog' ); ?></span>
										<?php else : ?>
											<strong><?php echo (int) $item['sales']; ?></strong> <?php esc_html_e( 'ventes', 'woo-meta-catalog' ); ?>
										<?php endif; ?>
									</td>
									<td>
										<strong><?php echo wc_price( $item['price'] ); ?></strong>
									</td>
									<td>
										<?php if ( null !== $item['stock'] ) : ?>
											<span style="color: #15803d;"><?php echo esc_html( $item['stock'] ); ?> <?php esc_html_e( 'en stock', 'woo-meta-catalog' ); ?></span>
										<?php else : ?>
											<span style="color: #15803d;"><?php esc_html_e( 'En stock', 'woo-meta-catalog' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $item['fallback_cat'] ) ) : ?>
											<span style="font-size: 12px; color: #b45309;"><?php echo esc_html( $item['fallback_cat'] ); ?></span>
										<?php else : ?>
											<span style="color: #94a3b8;">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save plugin settings.
	 */
	public function save_settings() {
		if ( ! isset( $_POST['woo_meta_catalog_save_settings'] ) ) {
			return;
		}

		check_admin_referer( 'woo_meta_catalog_settings_action', 'woo_meta_catalog_settings_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$existing       = get_option( 'woo_meta_catalog_settings', array() );
		$submitted_mode = isset( $_POST['label_trending_mode'] ) && 'seasonal' === $_POST['label_trending_mode'] ? 'seasonal' : 'classic';

		if ( 'seasonal' === $submitted_mode ) {
			// Mode saisonnier actif : champs saisonniers lus depuis $_POST, champs classiques préservés depuis $existing.
			$trending_count                 = isset( $existing['label_trending_count'] ) ? max( 1, intval( $existing['label_trending_count'] ) ) : 35;
			$trending_days                  = isset( $existing['label_trending_days'] ) ? max( 1, intval( $existing['label_trending_days'] ) ) : 45;
			$trending_seasonal_count        = max( 1, intval( $_POST['label_trending_seasonal_count'] ?? ( $existing['label_trending_seasonal_count'] ?? 60 ) ) );
			$trending_recent_ratio          = max( 0.0, min( 100.0, floatval( $_POST['label_trending_recent_ratio'] ?? ( $existing['label_trending_recent_ratio'] ?? 60.0 ) ) ) );
			$trending_recent_days           = max( 1, intval( $_POST['label_trending_recent_days'] ?? ( $existing['label_trending_recent_days'] ?? 15 ) ) );
			$trending_enable_atc            = isset( $_POST['label_trending_enable_atc'] ) ? 1 : 0;
			$trending_atc_weight            = max( 0.0, floatval( $_POST['label_trending_atc_weight'] ?? ( $existing['label_trending_atc_weight'] ?? 0.3 ) ) );
			$trending_min_sales             = max( 1, intval( $_POST['label_trending_min_sales'] ?? ( $existing['label_trending_min_sales'] ?? 2 ) ) );
			$trending_min_atc               = max( 1, intval( $_POST['label_trending_min_atc'] ?? ( $existing['label_trending_min_atc'] ?? 3 ) ) );
			$trending_prev_year_days_before = max( 0, intval( $_POST['label_trending_prev_year_days_before'] ?? ( $existing['label_trending_prev_year_days_before'] ?? 5 ) ) );
			$trending_prev_year_days_after  = max( 0, intval( $_POST['label_trending_prev_year_days_after'] ?? ( $existing['label_trending_prev_year_days_after'] ?? 25 ) ) );
			$trending_enable_cat_fallback   = isset( $_POST['label_trending_enable_cat_fallback'] ) ? 1 : 0;
			$trending_cat_fallback_cap      = max( 0.0, min( 100.0, floatval( $_POST['label_trending_cat_fallback_cap'] ?? ( $existing['label_trending_cat_fallback_cap'] ?? 15.0 ) ) ) );
			$trending_cat_fallback_excluded = isset( $_POST['label_trending_cat_fallback_excluded'] ) && is_array( $_POST['label_trending_cat_fallback_excluded'] ) ? array_map( 'intval', $_POST['label_trending_cat_fallback_excluded'] ) : array();
			$trending_min_price             = max( 0.0, floatval( $_POST['label_trending_min_price'] ?? ( $existing['label_trending_min_price'] ?? 8.0 ) ) );
			$trending_min_stock             = max( 1, intval( $_POST['label_trending_min_stock'] ?? ( $existing['label_trending_min_stock'] ?? 2 ) ) );
		} else {
			// Mode classique actif : champs classiques lus depuis $_POST, champs saisonniers préservés depuis $existing.
			$trending_count                 = max( 1, intval( $_POST['label_trending_count'] ?? ( $existing['label_trending_count'] ?? 35 ) ) );
			$trending_days                  = max( 1, intval( $_POST['label_trending_days'] ?? ( $existing['label_trending_days'] ?? 45 ) ) );
			$trending_seasonal_count        = isset( $existing['label_trending_seasonal_count'] ) ? max( 1, intval( $existing['label_trending_seasonal_count'] ) ) : 60;
			$trending_recent_ratio          = isset( $existing['label_trending_recent_ratio'] ) ? max( 0.0, min( 100.0, floatval( $existing['label_trending_recent_ratio'] ) ) ) : 60.0;
			$trending_recent_days           = isset( $existing['label_trending_recent_days'] ) ? max( 1, intval( $existing['label_trending_recent_days'] ) ) : 15;
			$trending_enable_atc            = ! empty( $existing['label_trending_enable_atc'] ) ? 1 : 0;
			$trending_atc_weight            = isset( $existing['label_trending_atc_weight'] ) ? max( 0.0, floatval( $existing['label_trending_atc_weight'] ) ) : 0.3;
			$trending_min_sales             = isset( $existing['label_trending_min_sales'] ) ? max( 1, intval( $existing['label_trending_min_sales'] ) ) : 2;
			$trending_min_atc               = isset( $existing['label_trending_min_atc'] ) ? max( 1, intval( $existing['label_trending_min_atc'] ) ) : 3;
			$trending_prev_year_days_before = isset( $existing['label_trending_prev_year_days_before'] ) ? max( 0, intval( $existing['label_trending_prev_year_days_before'] ) ) : 5;
			$trending_prev_year_days_after  = isset( $existing['label_trending_prev_year_days_after'] ) ? max( 0, intval( $existing['label_trending_prev_year_days_after'] ) ) : 25;
			$trending_enable_cat_fallback   = isset( $existing['label_trending_enable_cat_fallback'] ) ? ( ! empty( $existing['label_trending_enable_cat_fallback'] ) ? 1 : 0 ) : 1;
			$trending_cat_fallback_cap      = isset( $existing['label_trending_cat_fallback_cap'] ) ? max( 0.0, min( 100.0, floatval( $existing['label_trending_cat_fallback_cap'] ) ) ) : 15.0;
			$trending_cat_fallback_excluded = isset( $existing['label_trending_cat_fallback_excluded'] ) && is_array( $existing['label_trending_cat_fallback_excluded'] ) ? array_map( 'intval', $existing['label_trending_cat_fallback_excluded'] ) : array();
			$trending_min_price             = isset( $existing['label_trending_min_price'] ) ? max( 0.0, floatval( $existing['label_trending_min_price'] ) ) : 8.0;
			$trending_min_stock             = isset( $existing['label_trending_min_stock'] ) ? max( 1, intval( $existing['label_trending_min_stock'] ) ) : 2;
		}

		$settings = array(
			'id_format'               => isset( $_POST['id_format'] ) && in_array( $_POST['id_format'], array( 'id', 'sku' ), true ) ? sanitize_text_field( wp_unslash( $_POST['id_format'] ) ) : 'id',
			'exclude_hidden'          => isset( $_POST['exclude_hidden'] ) ? 1 : 0,
			'exclude_dead_stock_days' => max( 0, intval( $_POST['exclude_dead_stock_days'] ?? 0 ) ),
			'exclude_out_of_stock'    => isset( $_POST['exclude_out_of_stock'] ) ? 1 : 0,
			'exclude_no_image'        => isset( $_POST['exclude_no_image'] ) ? 1 : 0,
			'image_version'           => sanitize_text_field( wp_unslash( $_POST['image_version'] ?? '' ) ),
			'enable_image_cdn'        => isset( $_POST['enable_image_cdn'] ) ? 1 : 0,
			'image_cdn_provider'      => sanitize_text_field( wp_unslash( $_POST['image_cdn_provider'] ?? 'imagekit' ) ),
			'image_cdn_endpoint'      => esc_url_raw( trim( wp_unslash( $_POST['image_cdn_endpoint'] ?? '' ) ) ),
			'image_cdn_auto_square'   => isset( $_POST['image_cdn_auto_square'] ) ? 1 : 0,
			'image_cdn_force_jpeg'    => isset( $_POST['image_cdn_force_jpeg'] ) ? 1 : 0,
			'default_brand'           => sanitize_text_field( wp_unslash( $_POST['default_brand'] ?? '' ) ),
			'brand_attribute'         => sanitize_text_field( wp_unslash( $_POST['brand_attribute'] ?? '' ) ),
			'enable_utms'             => isset( $_POST['enable_utms'] ) ? 1 : 0,
			'utm_source'              => sanitize_text_field( wp_unslash( $_POST['utm_source'] ?? 'facebook' ) ),
			'utm_medium'              => sanitize_text_field( wp_unslash( $_POST['utm_medium'] ?? 'catalog' ) ),
			'utm_campaign'            => sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ?? 'meta_feed' ) ),
			'enable_security_key'     => isset( $_POST['enable_security_key'] ) ? 1 : 0,
			'security_key'            => sanitize_text_field( wp_unslash( $_POST['security_key'] ?? '' ) ),
			'batch_size'              => max( 10, min( 500, intval( $_POST['batch_size'] ?? 50 ) ) ),
			'daily_time'              => sanitize_text_field( wp_unslash( $_POST['daily_time'] ?? '03:30' ) ),

			// Meta internal labels settings.
			'label_include_categories' => isset( $_POST['label_include_categories'] ) ? 1 : 0,
			'label_include_tags'       => isset( $_POST['label_include_tags'] ) ? 1 : 0,
			'label_enable_promo'       => isset( $_POST['label_enable_promo'] ) ? 1 : 0,
			'label_promo_tag'          => sanitize_text_field( wp_unslash( $_POST['label_promo_tag'] ?? 'promo' ) ),
			'label_enable_new'         => isset( $_POST['label_enable_new'] ) ? 1 : 0,
			'label_new_days'           => max( 1, intval( $_POST['label_new_days'] ?? 30 ) ),
			'label_new_count'          => max( 1, intval( $_POST['label_new_count'] ?? 50 ) ),
			'label_new_tag'            => sanitize_text_field( wp_unslash( $_POST['label_new_tag'] ?? 'nouveaute' ) ),
			'label_enable_bestseller'  => isset( $_POST['label_enable_bestseller'] ) ? 1 : 0,
			'label_bestseller_count'   => max( 1, intval( $_POST['label_bestseller_count'] ?? ( $_POST['label_bestseller_value'] ?? 100 ) ) ),
			'label_bestseller_value'   => max( 1, intval( $_POST['label_bestseller_count'] ?? ( $_POST['label_bestseller_value'] ?? 100 ) ) ),
			'label_bestseller_tag'     => sanitize_text_field( wp_unslash( $_POST['label_bestseller_tag'] ?? 'bestseller' ) ),
			'label_enable_trending'                => isset( $_POST['label_enable_trending'] ) ? 1 : 0,
			'label_trending_mode'                  => $submitted_mode,
			'label_trending_count'                 => $trending_count,
			'label_trending_seasonal_count'        => $trending_seasonal_count,
			'label_trending_days'                  => $trending_days,
			'label_trending_tag'                   => sanitize_text_field( wp_unslash( $_POST['label_trending_tag'] ?? 'tendance' ) ),
			'label_trending_recent_ratio'          => $trending_recent_ratio,
			'label_trending_recent_days'           => $trending_recent_days,
			'label_trending_enable_atc'            => $trending_enable_atc,
			'label_trending_atc_weight'            => $trending_atc_weight,
			'label_trending_min_sales'             => $trending_min_sales,
			'label_trending_min_atc'               => $trending_min_atc,
			'label_trending_prev_year_days_before' => $trending_prev_year_days_before,
			'label_trending_prev_year_days_after'  => $trending_prev_year_days_after,
			'label_trending_enable_cat_fallback'   => $trending_enable_cat_fallback,
			'label_trending_cat_fallback_cap'      => $trending_cat_fallback_cap,
			'label_trending_cat_fallback_excluded' => $trending_cat_fallback_excluded,
			'label_trending_min_price'             => $trending_min_price,
			'label_trending_min_stock'             => $trending_min_stock,
		);

		update_option( 'woo_meta_catalog_settings', $settings );

		// If ATC enabled, ensure table exists.
		if ( ! empty( $settings['label_trending_enable_atc'] ) ) {
			Feed_Item::maybe_create_atc_table();
		}

		// Invalidate trending cache and in-memory cache so fresh settings apply immediately.
		delete_option( 'woo_meta_catalog_trending_cache' );
		Feed_Item::reset_sales_cache();

		// Reschedule daily event with updated time.
		Feed_Generator::schedule_daily_event();

		// Flush rewrite rules in case URL endpoint structure changed.
		flush_rewrite_rules( false );

		add_settings_error(
			'woo_meta_catalog_messages',
			'woo_meta_catalog_settings_saved',
			__( 'Réglages enregistrés avec succès.', 'woo-meta-catalog' ),
			'updated'
		);
	}

	/**
	 * Render admin dashboard page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'woo-meta-catalog' ) );
		}

		$options   = get_option( 'woo_meta_catalog_settings', array() );
		$status    = get_option( Feed_Generator::FEED_STATUS_OPTION, array() );
		$feed_url  = Feed_Server::get_public_url();
		$feed_path = Feed_Generator::get_feed_file_path();
		$exists    = file_exists( $feed_path ) && filesize( $feed_path ) > 0;

		// Attribute taxonomies for brand dropdown.
		$attribute_taxonomies = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();

		// Next scheduled run in Action Scheduler.
		$next_run = '';
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next_timestamp = as_next_scheduled_action( Feed_Generator::DAILY_HOOK );
			if ( $next_timestamp ) {
				$time_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
				$next_run    = date_i18n( 'd/m/Y H:i:s', $next_timestamp + $time_offset );
			}
		}

		?>
		<div class="wrap woo-meta-catalog-wrap">
			<header class="woo-meta-catalog-header">
				<div class="header-left">
					<h1>
						<span class="dashicons dashicons-rss"></span>
						<?php esc_html_e( 'Flux Meta Catalog & Google Shopping', 'woo-meta-catalog' ); ?>
						<span class="badge-version">v<?php echo esc_html( WOO_META_CATALOG_FEED_VERSION ); ?></span>
					</h1>
					<p class="subtitle"><?php esc_html_e( 'Générateur de flux XML optimisé pour Meta Commerce Manager, Advantage+ Catalog Ads et Google Shopping.', 'woo-meta-catalog' ); ?></p>
				</div>
			</header>

			<hr class="wp-header-end" />

			<div class="woo-meta-catalog-notices">
				<?php settings_errors( 'woo_meta_catalog_messages' ); ?>
			</div>

			<?php
			$active_tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
			$error_count = Feed_Logger::get_count();
			?>
			<nav class="nav-tab-wrapper woo-meta-nav-tabs" style="margin-bottom: 20px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-meta-catalog-feed' ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-generic" style="margin-right: 4px;"></span>
					<?php esc_html_e( 'Génération & Réglages', 'woo-meta-catalog' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-meta-catalog-feed&tab=diagnostic' ) ); ?>" class="nav-tab <?php echo 'diagnostic' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-heart" style="margin-right: 4px;"></span>
					<?php esc_html_e( 'Diagnostic & Santé', 'woo-meta-catalog' ); ?>
					<?php if ( $error_count > 0 ) : ?>
						<span class="diag-tab-badge"><?php echo esc_html( $error_count ); ?></span>
					<?php endif; ?>
				</a>
			</nav>

			<?php if ( 'diagnostic' === $active_tab ) : ?>
				<?php $this->render_diagnostic_tab(); ?>
			<?php else : ?>

			<?php if ( ! $exists ) : ?>
				<div class="notice notice-warning inline woo-meta-empty-feed-notice" style="margin: 15px 0 20px; padding: 14px 18px; border-left: 4px solid #f59e0b; background: #fffbeb; border-radius: 4px;">
					<p style="margin: 0; font-size: 14px; color: #92400e; line-height: 1.5;">
						<strong><span class="dashicons dashicons-info" style="vertical-align: middle; margin-right: 4px; color: #f59e0b;"></span><?php esc_html_e( 'Action requise :', 'woo-meta-catalog' ); ?></strong>
						<?php esc_html_e( 'Le fichier XML du flux n\'a pas encore été généré. Cliquez sur le bouton « Régénérer le flux maintenant » ci-dessous pour compiler votre catalogue (~12 secondes).', 'woo-meta-catalog' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<!-- CALLOUT GREEN BOX: FEED URL -->
			<div class="woo-meta-feed-url-card">
				<div class="card-icon">
					<span class="dashicons dashicons-admin-links"></span>
				</div>
				<div class="card-content">
					<h3><?php esc_html_e( 'URL publique de votre flux catalogue XML', 'woo-meta-catalog' ); ?></h3>
					<p><?php esc_html_e( 'Copiez cette URL et collez-la dans Meta Commerce Manager (Ajout de source de données > Flux de données programmé) ou Google Merchant Center.', 'woo-meta-catalog' ); ?></p>
					
					<div class="url-input-group">
						<input type="text" id="woo-meta-feed-url-input" class="large-text code" value="<?php echo esc_url( $feed_url ); ?>" readonly />
						<button type="button" class="button button-primary btn-copy-feed" data-target="#woo-meta-feed-url-input">
							<span class="dashicons dashicons-clipboard"></span>
							<span class="copy-text"><?php esc_html_e( 'Copier l\'URL', 'woo-meta-catalog' ); ?></span>
						</button>
						<a href="<?php echo esc_url( $feed_url ); ?>" target="_blank" class="button button-secondary btn-test-feed">
							<span class="dashicons dashicons-external"></span>
							<span class="btn-text"><?php esc_html_e( 'Tester le flux', 'woo-meta-catalog' ); ?></span>
						</a>
					</div>

					<p class="description" style="margin-top: 10px; font-size: 12px; color: #64748b;">
						<?php esc_html_e( 'URL directe du fichier statique sur le serveur :', 'woo-meta-catalog' ); ?>
						<code><?php echo esc_html( Feed_Generator::get_feed_file_url() ); ?></code>
					</p>

					<?php if ( ! empty( $options['enable_security_key'] ) ) : ?>
						<div class="security-badge">
							<span class="dashicons dashicons-lock"></span>
							<?php esc_html_e( 'Protection par jeton secret active : seuls les crawlers disposant de votre clé de sécurité peuvent télécharger le flux.', 'woo-meta-catalog' ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php $this->render_tracking_alignment_box(); ?>

			<!-- STATUS & REGENERATION BAR -->
			<div class="woo-meta-status-container">
				<div class="status-grid">
					<div class="status-box">
						<span class="label"><?php esc_html_e( 'Statut du fichier', 'woo-meta-catalog' ); ?></span>
						<span class="value" id="status-state-val">
							<?php if ( $exists ) : ?>
								<span class="badge-status-ok"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Flux généré et valide', 'woo-meta-catalog' ); ?></span>
							<?php else : ?>
								<span class="badge-status-warn"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'En attente de génération', 'woo-meta-catalog' ); ?></span>
							<?php endif; ?>
						</span>
					</div>

					<div class="status-box">
						<span class="label"><?php esc_html_e( 'Dernière génération', 'woo-meta-catalog' ); ?></span>
						<span class="value" id="status-date-val">
							<?php
							if ( ! empty( $status['last_generated'] ) ) {
								$time_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
								echo esc_html( date_i18n( 'd/m/Y à H:i:s', (int) $status['last_generated'] + $time_offset ) );
							} elseif ( $exists ) {
								$time_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
								echo esc_html( date_i18n( 'd/m/Y à H:i:s', filemtime( $feed_path ) + $time_offset ) );
							} else {
								esc_html_e( 'Jamais', 'woo-meta-catalog' );
							}
							?>
						</span>
					</div>

					<div class="status-box">
						<span class="label"><?php esc_html_e( 'Articles compilés', 'woo-meta-catalog' ); ?></span>
						<span class="value" id="status-items-val">
							<?php
							if ( ! empty( $status['total_items'] ) ) {
								echo esc_html( number_format_i18n( (int) $status['total_items'] ) . ' ' . __( 'items', 'woo-meta-catalog' ) );
							} else {
								echo '-';
							}
							?>
						</span>
					</div>

					<div class="status-box">
						<span class="label"><?php esc_html_e( 'Poids du fichier', 'woo-meta-catalog' ); ?></span>
						<span class="value" id="status-size-val">
							<?php
							if ( $exists ) {
								echo esc_html( size_format( filesize( $feed_path ), 2 ) );
							} else {
								echo '-';
							}
							?>
						</span>
					</div>

					<div class="status-box">
						<span class="label"><?php esc_html_e( 'Temps d\'exécution', 'woo-meta-catalog' ); ?></span>
						<span class="value" id="status-duration-val">
							<?php
							if ( ! empty( $status['duration'] ) ) {
								echo esc_html( $status['duration'] . ' s' );
							} else {
								echo '-';
							}
							?>
						</span>
					</div>

					<div class="status-box">
						<span class="label"><?php esc_html_e( 'Prochaine exécution auto', 'woo-meta-catalog' ); ?></span>
						<span class="value">
							<?php echo esc_html( $next_run ?: __( 'Action Scheduler', 'woo-meta-catalog' ) ); ?>
						</span>
					</div>
				</div>

				<!-- ACTION BUTTONS & PROGRESS BAR -->
				<div class="regeneration-action-bar">
					<button type="button" class="button button-primary button-large btn-trigger-generation" id="btn-trigger-generation">
						<span class="dashicons dashicons-update-alt"></span>
						<span class="btn-text"><?php esc_html_e( 'Régénérer le flux maintenant', 'woo-meta-catalog' ); ?></span>
					</button>

					<button type="button" class="button button-secondary btn-reset-lock" id="btn-reset-lock" title="<?php esc_attr_e( 'En cas de blocage d\'un ancien lot', 'woo-meta-catalog' ); ?>">
						<span class="dashicons dashicons-image-rotate"></span>
						<?php esc_html_e( 'Débloquer / Réinitialiser', 'woo-meta-catalog' ); ?>
					</button>

					<div class="progress-wrap" id="generation-progress-wrap" style="display: none;">
						<div class="progress-bar-outer">
							<div class="progress-bar-inner" id="generation-progress-bar" style="width: 0%;"></div>
						</div>
						<span class="progress-message" id="generation-progress-msg"><?php esc_html_e( 'Initialisation du traitement par lots...', 'woo-meta-catalog' ); ?></span>
					</div>
				</div>
			</div>

			<!-- SETTINGS FORM -->
			<div class="woo-meta-settings-card">
				<form method="post" action="">
					<?php wp_nonce_field( 'woo_meta_catalog_settings_action', 'woo_meta_catalog_settings_nonce' ); ?>

					<h2><?php esc_html_e( 'Configuration du flux', 'woo-meta-catalog' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Personnalisez les critères de filtrage, la marque, les balises UTM et la sécurité de votre catalogue.', 'woo-meta-catalog' ); ?></p>

					<table class="form-table" role="presentation">
						<tbody>
							<!-- ID FORMAT (CONTENT ID RESOLUTION) -->
							<tr>
								<th scope="row">
									<label for="id_format"><?php esc_html_e( 'Format des identifiants (<g:id>)', 'woo-meta-catalog' ); ?></label>
								</th>
								<td>
									<select name="id_format" id="id_format">
										<option value="id" <?php selected( $options['id_format'] ?? 'id', 'id' ); ?>><?php esc_html_e( 'ID produit WooCommerce (recommandé)', 'woo-meta-catalog' ); ?></option>
										<option value="sku" <?php selected( $options['id_format'] ?? 'id', 'sku' ); ?>><?php esc_html_e( 'SKU avec repli sur ID', 'woo-meta-catalog' ); ?></option>
									</select>
									<p class="description">
										<?php esc_html_e( 'Définit la valeur de la balise <g:id> transmise dans le flux XML et partagée avec l\'extension woo-fb-tracking-server-side. L\'option « ID produit » garantit un alignement universel à 100 % même si certains produits ont un SKU dans WooCommerce.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- EXCLUDE HIDDEN PRODUCTS -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Visibilité catalogue', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="exclude_hidden">
										<input name="exclude_hidden" type="checkbox" id="exclude_hidden" value="1" <?php checked( ! isset( $options['exclude_hidden'] ) || ! empty( $options['exclude_hidden'] ) ); ?> />
										<?php esc_html_e( 'Exclure les produits masqués (Visibilité catalogue : Caché)', 'woo-meta-catalog' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Recommandé (Activé) : Écarte automatiquement les anciens produits déréférencés du site sans avoir besoin de les supprimer de WooCommerce.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- DEAD STOCK FILTER (OUT OF STOCK FOR A LONG TIME) -->
							<tr>
								<th scope="row">
									<label for="exclude_dead_stock_days"><?php esc_html_e( 'Stock mort (Épuisé depuis longtemps)', 'woo-meta-catalog' ); ?></label>
								</th>
								<td>
									<select name="exclude_dead_stock_days" id="exclude_dead_stock_days">
										<option value="0" <?php selected( (int) ( $options['exclude_dead_stock_days'] ?? 0 ), 0 ); ?>><?php esc_html_e( 'Désactivé (conserver tous les produits épuisés avec balise out of stock)', 'woo-meta-catalog' ); ?></option>
										<option value="60" <?php selected( (int) ( $options['exclude_dead_stock_days'] ?? 0 ), 60 ); ?>><?php esc_html_e( 'Épuisé sans vente depuis plus de 60 jours (~2 mois)', 'woo-meta-catalog' ); ?></option>
										<option value="90" <?php selected( (int) ( $options['exclude_dead_stock_days'] ?? 0 ), 90 ); ?>><?php esc_html_e( 'Épuisé sans vente depuis plus de 90 jours (~3 mois)', 'woo-meta-catalog' ); ?></option>
										<option value="180" <?php selected( (int) ( $options['exclude_dead_stock_days'] ?? 0 ), 180 ); ?>><?php esc_html_e( '★ Recommandé Meta Ads : Épuisé sans vente depuis plus de 180 jours (~6 mois)', 'woo-meta-catalog' ); ?></option>
										<option value="365" <?php selected( (int) ( $options['exclude_dead_stock_days'] ?? 0 ), 365 ); ?>><?php esc_html_e( 'Épuisé sans vente depuis plus de 365 jours (1 an)', 'woo-meta-catalog' ); ?></option>
										<option value="730" <?php selected( (int) ( $options['exclude_dead_stock_days'] ?? 0 ), 730 ); ?>><?php esc_html_e( 'Épuisé sans vente depuis plus de 730 jours (2 ans)', 'woo-meta-catalog' ); ?></option>
									</select>
									<p class="description">
										<?php esc_html_e( 'Détection intelligente : Vérifie la date de dernière vente dans les commandes WooCommerce (ou la date de création si 0 vente). Permet de purger les milliers de références mortes tout en préservant l\'apprentissage Meta Ads sur les ruptures récentes.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- EXCLUDE OUT OF STOCK -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Rupture de stock totale', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="exclude_out_of_stock">
										<input name="exclude_out_of_stock" type="checkbox" id="exclude_out_of_stock" value="1" <?php checked( ! empty( $options['exclude_out_of_stock'] ) ); ?> />
										<?php esc_html_e( 'Exclure strictement TOUS les produits en rupture de stock', 'woo-meta-catalog' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Recommandation Meta Ads : Laisser désactivé si vous utilisez le filtre « Stock mort » ci-dessus. Meta gère nativement la balise out of stock pour suspendre les annonces sans détruire l\'historique d\'apprentissage.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- EXCLUDE WITHOUT IMAGE -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Images produits', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="exclude_no_image">
										<input name="exclude_no_image" type="checkbox" id="exclude_no_image" value="1" <?php checked( ! isset( $options['exclude_no_image'] ) || ! empty( $options['exclude_no_image'] ) ); ?> />
										<?php esc_html_e( 'Exclure les produits sans aucune image principale', 'woo-meta-catalog' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Conseillé (Oui) pour éviter les rejets de conformité dans Meta Commerce Manager.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- IMAGE CACHE BUSTING -->
							<tr>
								<th scope="row"><label for="image_version"><?php esc_html_e( 'Version de cache des images (?v=...)', 'woo-meta-catalog' ); ?></label></th>
								<td>
									<div style="display: flex; gap: 8px; align-items: center; max-width: 520px;">
										<input name="image_version" type="text" id="image_version" value="<?php echo esc_attr( $options['image_version'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'ex: 2 ou timestamp', 'woo-meta-catalog' ); ?>" />
										<button type="button" class="button button-secondary" id="btn-bust-image-cache" title="<?php esc_attr_e( 'Génère un timestamp pour forcer Meta à retélécharger toutes les images', 'woo-meta-catalog' ); ?>">
											<span class="dashicons dashicons-update" style="vertical-align: text-top;"></span>
											<?php esc_html_e( 'Purger le cache Meta (timestamp)', 'woo-meta-catalog' ); ?>
										</button>
									</div>
									<p class="description">
										<?php esc_html_e( 'Ajoute un paramètre ?v=... aux URLs des images (<g:image_link> et <g:additional_image_link>). Permet d\'invalider immédiatement le cache de Meta Commerce Manager pour forcer le retéléchargement complet des visuels.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- IMAGE CDN OFFLOADING & AUTO-SQUARE -->
							<tr class="woo-meta-section-header">
								<th colspan="2" style="padding: 24px 0 10px 0; border-top: 1px solid #e2e8f0;">
									<h3 style="margin: 0; font-size: 15px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
										<span class="dashicons dashicons-cloud" style="color: #2563eb; font-size: 20px; width: 20px; height: 20px;"></span>
										<?php esc_html_e( 'Déportation CDN & Normalisation des Images (ImageKit / Cloudflare)', 'woo-meta-catalog' ); ?>
										<span style="font-size: 11px; background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">NOUVEAU v1.5.0</span>
									</h3>
									<p class="description" style="margin-top: 4px; font-weight: normal; color: #64748b;">
										<?php esc_html_e( 'Résout définitivement le blocage des vignettes (carrés gris) dans Meta Commerce Manager en servant les photos depuis un réseau CDN haute performance, sans surcharger votre serveur WordPress.', 'woo-meta-catalog' ); ?>
									</p>
								</th>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Activer le CDN d\'images', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="enable_image_cdn">
										<input name="enable_image_cdn" type="checkbox" id="enable_image_cdn" value="1" <?php checked( ! empty( $options['enable_image_cdn'] ) ); ?> />
										<strong><?php esc_html_e( 'Distribuer les images du catalogue via un CDN tiers (Origin Pull)', 'woo-meta-catalog' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Le CDN récupère l\'image sur votre boutique une seule fois à la volée et absorbe la rafale de requêtes simultanées du robot Meta sans déclencher de blocage de sécurité (WAF/Rate-Limiting) sur votre hébergement.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<tr class="row-cdn-field">
								<th scope="row"><label for="image_cdn_provider"><?php esc_html_e( 'Fournisseur CDN', 'woo-meta-catalog' ); ?></label></th>
								<td>
									<select name="image_cdn_provider" id="image_cdn_provider">
										<option value="imagekit" <?php selected( $options['image_cdn_provider'] ?? 'imagekit', 'imagekit' ); ?>><?php esc_html_e( 'ImageKit.io (Recommandé - 20 Go/mois gratuits, sans CB)', 'woo-meta-catalog' ); ?></option>
										<option value="custom" <?php selected( $options['image_cdn_provider'] ?? '', 'custom' ); ?>><?php esc_html_e( 'Personnalisé / Autre CDN (Cloudflare Worker, BunnyCDN, CDN CNAME...)', 'woo-meta-catalog' ); ?></option>
									</select>
								</td>
							</tr>

							<tr class="row-cdn-field">
								<th scope="row"><label for="image_cdn_endpoint"><?php esc_html_e( 'URL Endpoint CDN', 'woo-meta-catalog' ); ?></label></th>
								<td>
									<div style="display: flex; gap: 8px; align-items: center; max-width: 600px;">
										<input name="image_cdn_endpoint" type="url" id="image_cdn_endpoint" value="<?php echo esc_attr( $options['image_cdn_endpoint'] ?? '' ); ?>" class="large-text" placeholder="https://ik.imagekit.io/votre_identifiant" />
										<button type="button" class="button button-secondary" id="btn-test-cdn">
											<span class="dashicons dashicons-admin-links" style="vertical-align: text-top;"></span>
											<?php esc_html_e( 'Tester le CDN', 'woo-meta-catalog' ); ?>
										</button>
									</div>
									<div id="cdn-test-result" style="margin-top: 8px; display: none;"></div>
									<div class="cdn-guide-box" style="margin-top: 12px; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; line-height: 1.5; color: #475569; max-width: 640px;">
										<strong style="color: #0f172a;"><?php esc_html_e( 'Configuration rapide ImageKit.io en 3 étapes (Gratuit) :', 'woo-meta-catalog' ); ?></strong>
										<ol style="margin: 6px 0 0 18px; padding: 0;">
											<li><?php printf( __( 'Créez un compte gratuit sur <a href="%s" target="_blank" rel="noopener">imagekit.io</a> avec l\'email du client (aucun moyen de paiement requis).', 'woo-meta-catalog' ), 'https://imagekit.io/registration' ); ?></li>
											<li><?php printf( __( 'Dans <em>External Storage > Origins > Add New</em>, choisissez <strong>Web Folder</strong> et entrez comme Base URL : <code>%s</code> (laissez les 2 cases optionnelles décochées).', 'woo-meta-catalog' ), esc_html( home_url() ) ); ?></li>
											<li><?php _e( 'Copiez votre <strong>URL-endpoint</strong> (ex: <code>https://ik.imagekit.io/client974</code>) et collez-la dans le champ ci-dessus.', 'woo-meta-catalog' ); ?></li>
										</ol>
									</div>
								</td>
							</tr>

							<tr class="row-cdn-field">
								<th scope="row"><?php esc_html_e( 'Optimisation Meta Ads', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="image_cdn_auto_square" style="display: block; margin-bottom: 6px;">
										<input name="image_cdn_auto_square" type="checkbox" id="image_cdn_auto_square" value="1" <?php checked( ! isset( $options['image_cdn_auto_square'] ) || ! empty( $options['image_cdn_auto_square'] ) ); ?> />
										<?php esc_html_e( 'Normalisation automatique au format Carré 1:1 (1024×1024 px) avec marges blanches (ImageKit)', 'woo-meta-catalog' ); ?>
									</label>
									<label for="image_cdn_force_jpeg" style="display: block;">
										<input name="image_cdn_force_jpeg" type="checkbox" id="image_cdn_force_jpeg" value="1" <?php checked( ! isset( $options['image_cdn_force_jpeg'] ) || ! empty( $options['image_cdn_force_jpeg'] ) ); ?> />
										<?php esc_html_e( 'Forcer la délivrabilité en JPEG standard (f-jpg) pour compatibilité 100% avec les crawlers Meta', 'woo-meta-catalog' ); ?>
									</label>
									<p class="description" style="margin-top: 6px;">
										<?php esc_html_e( 'Élimine les rejets de ratio d\'aspect ou d\'encodage en convertissant à la volée toutes les photos rectangulaires en visuels carrés avec bandes blanches élégantes.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- BRAND DEFAULTS & ATTRIBUTE -->
							<tr>
								<th scope="row"><label for="default_brand"><?php esc_html_e( 'Marque par défaut (Brand)', 'woo-meta-catalog' ); ?></label></th>
								<td>
									<input name="default_brand" type="text" id="default_brand" value="<?php echo esc_attr( $options['default_brand'] ?? get_bloginfo( 'name' ) ); ?>" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Nom de marque utilisé en repli si aucun attribut spécifique n\'est renseigné sur le produit.', 'woo-meta-catalog' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="brand_attribute"><?php esc_html_e( 'Attribut de marque WooCommerce', 'woo-meta-catalog' ); ?></label></th>
								<td>
									<select name="brand_attribute" id="brand_attribute">
										<option value=""><?php esc_html_e( '— Détection automatique (pa_marque, pa_brand, etc.) —', 'woo-meta-catalog' ); ?></option>
										<?php if ( ! empty( $attribute_taxonomies ) ) : ?>
											<?php foreach ( $attribute_taxonomies as $tax ) : ?>
												<?php $tax_slug = 'pa_' . $tax->attribute_name; ?>
												<option value="<?php echo esc_attr( $tax_slug ); ?>" <?php selected( $options['brand_attribute'] ?? '', $tax_slug ); ?>>
													<?php echo esc_html( $tax->attribute_label . ' (' . $tax_slug . ')' ); ?>
												</option>
											<?php endforeach; ?>
										<?php endif; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Sélectionnez l\'attribut produit contenant la marque du produit.', 'woo-meta-catalog' ); ?></p>
								</td>
							</tr>

							<!-- UTM TRACKING -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Balisage UTM dynamique', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="enable_utms">
										<input name="enable_utms" type="checkbox" id="enable_utms" value="1" <?php checked( ! isset( $options['enable_utms'] ) || ! empty( $options['enable_utms'] ) ); ?> />
										<?php esc_html_e( 'Ajouter automatiquement les paramètres UTM aux URLs produits', 'woo-meta-catalog' ); ?>
									</label>
									<div class="utm-fields-grid" style="margin-top: 10px;">
										<div>
											<label for="utm_source"><strong>utm_source:</strong></label><br/>
											<input name="utm_source" type="text" id="utm_source" value="<?php echo esc_attr( $options['utm_source'] ?? 'facebook' ); ?>" class="small-text" style="width: 140px;" />
										</div>
										<div>
											<label for="utm_medium"><strong>utm_medium:</strong></label><br/>
											<input name="utm_medium" type="text" id="utm_medium" value="<?php echo esc_attr( $options['utm_medium'] ?? 'catalog' ); ?>" class="small-text" style="width: 140px;" />
										</div>
										<div>
											<label for="utm_campaign"><strong>utm_campaign:</strong></label><br/>
											<input name="utm_campaign" type="text" id="utm_campaign" value="<?php echo esc_attr( $options['utm_campaign'] ?? 'meta_feed' ); ?>" class="small-text" style="width: 140px;" />
										</div>
									</div>
								</td>
							</tr>

							<!-- SECURITY KEY -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Sécurité du flux', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="enable_security_key">
										<input name="enable_security_key" type="checkbox" id="enable_security_key" value="1" <?php checked( ! empty( $options['enable_security_key'] ) ); ?> />
										<?php esc_html_e( 'Activer un jeton secret dans l\'URL du flux (?feed_key=...)', 'woo-meta-catalog' ); ?>
									</label>
									<div class="security-key-wrap" style="margin-top: 10px;">
										<input name="security_key" type="text" id="security_key" value="<?php echo esc_attr( $options['security_key'] ?? wp_generate_password( 24, false, false ) ); ?>" class="regular-text code" />
										<button type="button" class="button button-secondary" id="btn-gen-key">
											<span class="dashicons dashicons-randomize"></span>
											<?php esc_html_e( 'Générer un nouveau jeton', 'woo-meta-catalog' ); ?>
										</button>
									</div>
									<p class="description">
										<?php esc_html_e( 'Empêche les outils de scraping concurrentiel de télécharger votre catalogue complet sans autorisation.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- META INTERNAL LABELS & PRODUCT SETS -->
							<tr>
								<th colspan="2" style="padding-top: 25px; padding-bottom: 10px;">
									<h3 style="margin: 0; padding-bottom: 6px; border-bottom: 1px solid #dcdcde; color: #1d2327;">
										<span class="dashicons dashicons-tag" style="margin-right: 6px;"></span>
										<?php esc_html_e( 'Étiquettes internes Meta (<g:internal_label> / Ensembles de produits)', 'woo-meta-catalog' ); ?>
									</h3>
									<p class="description" style="margin-top: 6px;">
										<?php esc_html_e( 'Permet de segmenter vos produits dans Meta Commerce Manager pour créer des ensembles dynamiques (Product Sets) sans limite et sans déclencher de réexamen publicitaire. Dans Meta Ads / Commerce Manager, créez votre ensemble avec le filtre Attribut "Étiquette interne" et sélectionnez ou saisissez directement le nom du marqueur (ex : promo, bestseller).', 'woo-meta-catalog' ); ?>
									</p>
								</th>
							</tr>

							<!-- INCLUDE CATEGORIES -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Catégories produits', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="label_include_categories">
										<input name="label_include_categories" type="checkbox" id="label_include_categories" value="1" <?php checked( ! empty( $options['label_include_categories'] ) ); ?> />
										<?php esc_html_e( 'Inclure toutes les catégories WooCommerce (product_cat)', 'woo-meta-catalog' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Ajoute le nom de chaque catégorie du produit dans l\'étiquette interne. Idéal pour filtrer et créer des ensembles par catégorie dans Meta Commerce Manager.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- INCLUDE TAGS -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Étiquettes WooCommerce', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="label_include_tags">
										<input name="label_include_tags" type="checkbox" id="label_include_tags" value="1" <?php checked( ! empty( $options['label_include_tags'] ) ); ?> />
										<?php esc_html_e( 'Inclure les étiquettes WooCommerce (product_tag)', 'woo-meta-catalog' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Ajoute les étiquettes manuelles du produit dans l\'étiquette interne.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- PROMO / ON SALE FLAG -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Marqueur Promotion', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="label_enable_promo">
										<input name="label_enable_promo" type="checkbox" id="label_enable_promo" value="1" <?php checked( ! empty( $options['label_enable_promo'] ) ); ?> />
										<?php esc_html_e( 'Ajouter automatiquement un marqueur pour les produits en solde', 'woo-meta-catalog' ); ?>
									</label>
									<div style="margin-top: 8px;">
										<label for="label_promo_tag"><?php esc_html_e( 'Nom du marqueur :', 'woo-meta-catalog' ); ?></label>
										<input name="label_promo_tag" type="text" id="label_promo_tag" value="<?php echo esc_attr( $options['label_promo_tag'] ?? 'promo' ); ?>" class="regular-text" style="max-width: 160px;" />
									</div>
									<p class="description">
										<?php esc_html_e( 'Détecte si le produit ou sa variante est en promotion et lui attribue cette étiquette (ex: promo). Permet de créer un ensemble dynamique "Soldes / Promos" dans Meta en 1 clic.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- NEW PRODUCT FLAG -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Marqueur Nouveauté', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="label_enable_new">
										<input name="label_enable_new" type="checkbox" id="label_enable_new" value="1" <?php checked( ! empty( $options['label_enable_new'] ) ); ?> />
										<?php esc_html_e( 'Ajouter automatiquement un marqueur pour les nouveautés', 'woo-meta-catalog' ); ?>
									</label>
									<div style="margin-top: 8px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
										<div>
											<label for="label_new_days"><?php esc_html_e( 'Créé depuis moins de :', 'woo-meta-catalog' ); ?></label>
											<input name="label_new_days" type="number" min="1" max="365" id="label_new_days" value="<?php echo esc_attr( $options['label_new_days'] ?? 30 ); ?>" class="small-text" /> <?php esc_html_e( 'jours', 'woo-meta-catalog' ); ?>
										</div>
										<div>
											<label for="label_new_count"><?php esc_html_e( 'Nombre max de produits (Top N) :', 'woo-meta-catalog' ); ?></label>
											<input name="label_new_count" type="number" min="1" id="label_new_count" value="<?php echo esc_attr( $options['label_new_count'] ?? 50 ); ?>" class="small-text" />
										</div>
										<div>
											<label for="label_new_tag"><?php esc_html_e( 'Nom du marqueur :', 'woo-meta-catalog' ); ?></label>
											<input name="label_new_tag" type="text" id="label_new_tag" value="<?php echo esc_attr( $options['label_new_tag'] ?? 'nouveaute' ); ?>" class="regular-text" style="max-width: 160px;" />
										</div>
									</div>
									<p class="description">
										<?php esc_html_e( 'Attribue automatiquement le tag spécifié aux N produits les plus récents créés dans la période définie et actuellement en stock.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- BESTSELLER FLAG -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Marqueur Best-Seller', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="label_enable_bestseller">
										<input name="label_enable_bestseller" type="checkbox" id="label_enable_bestseller" value="1" <?php checked( ! empty( $options['label_enable_bestseller'] ) ); ?> />
										<?php esc_html_e( 'Ajouter automatiquement un marqueur pour les meilleures ventes', 'woo-meta-catalog' ); ?>
									</label>
									<div style="margin-top: 8px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
										<div>
											<?php
											$bestseller_count = ! empty( $options['label_bestseller_count'] )
												? (int) $options['label_bestseller_count']
												: ( ( isset( $options['label_bestseller_mode'] ) && 'percentage' === $options['label_bestseller_mode'] ) ? 100 : ( $options['label_bestseller_value'] ?? 100 ) );
											?>
											<label for="label_bestseller_count"><?php esc_html_e( 'Nombre de produits (Top N) :', 'woo-meta-catalog' ); ?></label>
											<input name="label_bestseller_count" type="number" min="1" id="label_bestseller_count" value="<?php echo esc_attr( $bestseller_count ); ?>" class="small-text" />
										</div>
										<div>
											<label for="label_bestseller_tag"><?php esc_html_e( 'Nom du marqueur :', 'woo-meta-catalog' ); ?></label>
											<input name="label_bestseller_tag" type="text" id="label_bestseller_tag" value="<?php echo esc_attr( $options['label_bestseller_tag'] ?? 'bestseller' ); ?>" class="regular-text" style="max-width: 160px;" />
										</div>
									</div>
									<p class="description">
										<?php esc_html_e( 'Identifie automatiquement les N produits en stock ayant le plus grand volume de ventes historiques (total_sales > 0) et leur applique l\'étiquette bestseller. Idéal pour cibler vos bestsellers en Advantage+ Catalog Ads.', 'woo-meta-catalog' ); ?>
									</p>
								</td>
							</tr>

							<!-- TRENDING FLAG -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Marqueur Tendance', 'woo-meta-catalog' ); ?></th>
								<td>
									<label for="label_enable_trending" style="font-weight: 600;">
										<input name="label_enable_trending" type="checkbox" id="label_enable_trending" value="1" <?php checked( ! empty( $options['label_enable_trending'] ) ); ?> />
										<?php esc_html_e( 'Ajouter automatiquement un marqueur pour les produits tendance dans le flux Meta', 'woo-meta-catalog' ); ?>
									</label>
									<p class="description" style="margin-top: 4px;">
										<?php esc_html_e( 'Attribue le marqueur aux produits les plus performants. Idéal pour créer un ensemble dynamique « Tendances » dans Meta Ads Advantage+.', 'woo-meta-catalog' ); ?>
									</p>

									<div id="woo-meta-trending-settings-wrap" style="margin-top: 15px; <?php echo empty( $options['label_enable_trending'] ) ? 'display: none;' : ''; ?>">
										<!-- MODE SELECTOR -->
										<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin-bottom: 15px;">
											<div style="font-weight: 600; margin-bottom: 8px; color: #1e293b;"><?php esc_html_e( 'Algorithme de calcul :', 'woo-meta-catalog' ); ?></div>
											<label style="margin-right: 20px; cursor: pointer;">
												<input type="radio" name="label_trending_mode" value="classic" class="trending-mode-radio" <?php checked( empty( $options['label_trending_mode'] ) || 'classic' === $options['label_trending_mode'] ); ?> />
												<strong><?php esc_html_e( 'Mode Classique', 'woo-meta-catalog' ); ?></strong>
												<span style="color: #64748b; font-size: 12px;"> — <?php esc_html_e( 'Ventes directes sur les X derniers jours', 'woo-meta-catalog' ); ?></span>
											</label>
											<label style="cursor: pointer;">
												<input type="radio" name="label_trending_mode" value="seasonal" class="trending-mode-radio" <?php checked( ! empty( $options['label_trending_mode'] ) && 'seasonal' === $options['label_trending_mode'] ); ?> />
												<strong><?php esc_html_e( 'Mode Saisonnier (Recommandé)', 'woo-meta-catalog' ); ?></strong>
												<span style="color: #64748b; font-size: 12px;"> — <?php esc_html_e( 'Ventes récentes + Période N-1 + Repli automatique par catégorie', 'woo-meta-catalog' ); ?></span>
											</label>
										</div>

										<!-- CLASSIC SETTINGS -->
										<div id="trending-fields-classic" style="<?php echo ( ! empty( $options['label_trending_mode'] ) && 'seasonal' === $options['label_trending_mode'] ) ? 'display: none;' : ''; ?> margin-bottom: 15px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
											<div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
												<div>
													<label for="label_trending_count_classic"><strong><?php esc_html_e( 'Nombre de produits (Top N) :', 'woo-meta-catalog' ); ?></strong></label><br>
													<input name="label_trending_count" type="number" min="1" id="label_trending_count_classic" value="<?php echo esc_attr( $options['label_trending_count'] ?? 35 ); ?>" class="small-text" />
												</div>
												<div>
													<label for="label_trending_days"><strong><?php esc_html_e( 'Ventes des derniers :', 'woo-meta-catalog' ); ?></strong></label><br>
													<input name="label_trending_days" type="number" min="1" max="365" id="label_trending_days" value="<?php echo esc_attr( $options['label_trending_days'] ?? 45 ); ?>" class="small-text" /> <?php esc_html_e( 'jours', 'woo-meta-catalog' ); ?>
												</div>
											</div>
										</div>

										<!-- SEASONAL SETTINGS -->
										<div id="trending-fields-seasonal" style="<?php echo ( empty( $options['label_trending_mode'] ) || 'seasonal' !== $options['label_trending_mode'] ) ? 'display: none;' : ''; ?> margin-bottom: 15px;">
											<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
												<!-- SECTION 1: VOLUME & QUOTAS -->
												<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
													<h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #1e293b;">
														<span class="dashicons dashicons-chart-pie" style="vertical-align: middle; color: #0284c7;"></span> <?php esc_html_e( '1. Volume global & Répartition', 'woo-meta-catalog' ); ?>
													</h5>
													<div style="margin-bottom: 10px;">
														<label for="label_trending_count_seasonal"><?php esc_html_e( 'Nombre total de produits (N) :', 'woo-meta-catalog' ); ?></label><br>
														<input name="label_trending_seasonal_count" type="number" min="1" id="label_trending_count_seasonal" value="<?php echo esc_attr( $options['label_trending_seasonal_count'] ?? ( $options['label_trending_count'] ?? 60 ) ); ?>" class="small-text" />
													</div>
													<div>
														<label for="label_trending_recent_ratio"><?php esc_html_e( 'Part ventes récentes (%) :', 'woo-meta-catalog' ); ?></label><br>
														<input name="label_trending_recent_ratio" type="number" min="0" max="100" id="label_trending_recent_ratio" value="<?php echo esc_attr( $options['label_trending_recent_ratio'] ?? 60 ); ?>" class="small-text" /> %
														<p class="description" style="margin-top: 4px;" id="label_trending_prev_ratio_desc">
															<?php
															$r_ratio = (float) ( $options['label_trending_recent_ratio'] ?? 60 );
															printf( esc_html__( 'Part période année N-1 : %d%%', 'woo-meta-catalog' ), (int) ( 100 - $r_ratio ) );
															?>
														</p>
													</div>
												</div>

												<!-- SECTION 2: VENTES RÉCENTES (SOURCE A) -->
												<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
													<h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #1e293b;">
														<span class="dashicons dashicons-clock" style="vertical-align: middle; color: #16a34a;"></span> <?php esc_html_e( '2. Source A — Ventes récentes', 'woo-meta-catalog' ); ?>
													</h5>
													<div style="margin-bottom: 10px;">
														<label for="label_trending_recent_days"><?php esc_html_e( 'Fenêtre récente en jours (D) :', 'woo-meta-catalog' ); ?></label><br>
														<input name="label_trending_recent_days" type="number" min="1" max="180" id="label_trending_recent_days" value="<?php echo esc_attr( $options['label_trending_recent_days'] ?? 15 ); ?>" class="small-text" /> <?php esc_html_e( 'jours', 'woo-meta-catalog' ); ?>
													</div>
													<div style="margin-bottom: 10px;">
														<label>
															<input name="label_trending_enable_atc" type="checkbox" id="label_trending_enable_atc" value="1" <?php checked( ! empty( $options['label_trending_enable_atc'] ) ); ?> />
															<strong><?php esc_html_e( 'Utiliser les ajouts au panier', 'woo-meta-catalog' ); ?></strong>
														</label>
														<div id="trending-atc-weight-wrap" style="margin-top: 6px; <?php echo empty( $options['label_trending_enable_atc'] ) ? 'display:none;' : ''; ?>">
															<label for="label_trending_atc_weight"><?php esc_html_e( 'Poids d\'un ajout au panier :', 'woo-meta-catalog' ); ?></label>
															<input name="label_trending_atc_weight" type="number" step="0.05" min="0" max="5" id="label_trending_atc_weight" value="<?php echo esc_attr( $options['label_trending_atc_weight'] ?? '0.3' ); ?>" class="small-text" />
														</div>
													</div>
													<div>
														<label><strong><?php esc_html_e( 'Seuil d\'entrée Source A :', 'woo-meta-catalog' ); ?></strong></label><br>
														<span style="font-size: 12px;">
															Au moins <input name="label_trending_min_sales" type="number" min="1" value="<?php echo esc_attr( $options['label_trending_min_sales'] ?? 2 ); ?>" class="small-text" style="width: 50px;" /> ventes,<br>
															OU 1 vente et <input name="label_trending_min_atc" type="number" min="1" value="<?php echo esc_attr( $options['label_trending_min_atc'] ?? 3 ); ?>" class="small-text" style="width: 50px;" /> ajouts au panier.
														</span>
													</div>
												</div>

												<!-- SECTION 3: ANNÉE PRÉCÉDENTE (SOURCE B1) -->
												<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
													<h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #1e293b;">
														<span class="dashicons dashicons-calendar-alt" style="vertical-align: middle; color: #2563eb;"></span> <?php esc_html_e( '3. Source B — Période Année N-1', 'woo-meta-catalog' ); ?>
													</h5>
													<p style="margin: 0 0 8px 0; font-size: 12px; color: #64748b;">
														<?php esc_html_e( 'Fenêtre calculée un an plus tôt [J - a ; J + b] avec fuseau horaire du site et gestion des années bissextiles :', 'woo-meta-catalog' ); ?>
													</p>
													<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
														<div>
															<label for="label_trending_prev_year_days_before" style="font-size: 12px;"><?php esc_html_e( 'J - (jours avant) :', 'woo-meta-catalog' ); ?></label><br>
															<input name="label_trending_prev_year_days_before" type="number" min="0" max="180" id="label_trending_prev_year_days_before" value="<?php echo esc_attr( $options['label_trending_prev_year_days_before'] ?? 5 ); ?>" class="small-text" />
														</div>
														<div>
															<label for="label_trending_prev_year_days_after" style="font-size: 12px;"><?php esc_html_e( 'J + (jours après) :', 'woo-meta-catalog' ); ?></label><br>
															<input name="label_trending_prev_year_days_after" type="number" min="0" max="180" id="label_trending_prev_year_days_after" value="<?php echo esc_attr( $options['label_trending_prev_year_days_after'] ?? 25 ); ?>" class="small-text" />
														</div>
													</div>
												</div>

												<!-- SECTION 4: REPLI CATÉGORIE (SOURCE B2) -->
												<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
													<h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #1e293b;">
														<span class="dashicons dashicons-category" style="vertical-align: middle; color: #d97706;"></span> <?php esc_html_e( '4. Source B2 — Repli par catégorie', 'woo-meta-catalog' ); ?>
													</h5>
													<div style="margin-bottom: 8px;">
														<label>
															<input name="label_trending_enable_cat_fallback" type="checkbox" id="label_trending_enable_cat_fallback" value="1" <?php checked( ! isset( $options['label_trending_enable_cat_fallback'] ) || ! empty( $options['label_trending_enable_cat_fallback'] ) ); ?> />
															<strong><?php esc_html_e( 'Activer le repli par catégorie', 'woo-meta-catalog' ); ?></strong>
														</label>
														<p class="description" style="margin-top: 2px;">
															<?php esc_html_e( 'Prend le relais avec les nouveautés de la catégorie si les articles N-1 ont été recréés.', 'woo-meta-catalog' ); ?>
														</p>
													</div>
													<div id="trending-cat-fallback-options" style="<?php echo ( isset( $options['label_trending_enable_cat_fallback'] ) && empty( $options['label_trending_enable_cat_fallback'] ) ) ? 'display:none;' : ''; ?>">
														<div style="margin-bottom: 8px;">
															<label for="label_trending_cat_fallback_cap" style="font-size: 12px;"><?php esc_html_e( 'Plafond strict du repli (% de N) :', 'woo-meta-catalog' ); ?></label><br>
															<input name="label_trending_cat_fallback_cap" type="number" min="0" max="100" id="label_trending_cat_fallback_cap" value="<?php echo esc_attr( $options['label_trending_cat_fallback_cap'] ?? 15 ); ?>" class="small-text" /> %
														</div>
														<div>
															<label style="font-size: 12px; font-weight: 600;"><?php esc_html_e( 'Catégories exclues du repli :', 'woo-meta-catalog' ); ?></label><br>
															<?php
															$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
															$def_cat = (int) get_option( 'default_product_cat' );
															$excluded = isset( $options['label_trending_cat_fallback_excluded'] ) ? array_map( 'intval', (array) $options['label_trending_cat_fallback_excluded'] ) : array( $def_cat );
															?>
															<select name="label_trending_cat_fallback_excluded[]" multiple size="4" style="width: 100%; max-width: 320px; font-size: 12px;">
																<?php if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) : ?>
																	<?php foreach ( $cats as $c ) : ?>
																		<option value="<?php echo (int) $c->term_id; ?>" <?php echo in_array( (int) $c->term_id, $excluded, true ) ? 'selected' : ''; ?>>
																			<?php echo esc_html( $c->name ); ?> (ID: <?php echo (int) $c->term_id; ?>)
																		</option>
																	<?php endforeach; ?>
																<?php endif; ?>
															</select>
															<p class="description" style="font-size: 11px;">
																<?php esc_html_e( 'Maintenez Ctrl (ou Cmd) pour sélectionner plusieurs catégories.', 'woo-meta-catalog' ); ?>
															</p>
														</div>
													</div>
												</div>

												<!-- SECTION 5: CONDITIONS D'ÉLIGIBILITÉ (TOUTES SOURCES) -->
												<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; grid-column: 1 / -1;">
													<h5 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #1e293b;">
														<span class="dashicons dashicons-filter" style="vertical-align: middle; color: #7c3aed;"></span> <?php esc_html_e( '5. Conditions d\'éligibilité requises (Toutes sources A, B1 & B2)', 'woo-meta-catalog' ); ?>
													</h5>
													<div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
														<div>
															<label for="label_trending_min_price"><strong><?php esc_html_e( 'Prix effectif minimum (€) :', 'woo-meta-catalog' ); ?></strong></label><br>
															<input name="label_trending_min_price" type="number" step="0.5" min="0" id="label_trending_min_price" value="<?php echo esc_attr( $options['label_trending_min_price'] ?? '8.0' ); ?>" class="small-text" /> €
															<p class="description" style="margin-top: 2px; font-size: 11px;"><?php esc_html_e( 'Pour un produit variable, basé sur la variation en stock la moins chère.', 'woo-meta-catalog' ); ?></p>
														</div>
														<div>
															<label for="label_trending_min_stock"><strong><?php esc_html_e( 'Stock minimum (unités) :', 'woo-meta-catalog' ); ?></strong></label><br>
															<input name="label_trending_min_stock" type="number" min="1" id="label_trending_min_stock" value="<?php echo esc_attr( $options['label_trending_min_stock'] ?? 2 ); ?>" class="small-text" /> <?php esc_html_e( 'unités', 'woo-meta-catalog' ); ?>
															<p class="description" style="margin-top: 2px; font-size: 11px;"><?php esc_html_e( 'Si le stock est géré, au moins ce nombre en stock.', 'woo-meta-catalog' ); ?></p>
														</div>
													</div>
												</div>
											</div>
										</div>

										<!-- TAG NAME (BOTH MODES) -->
										<div style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">
											<label for="label_trending_tag"><strong><?php esc_html_e( 'Nom du marqueur dans le flux :', 'woo-meta-catalog' ); ?></strong></label>
											<input name="label_trending_tag" type="text" id="label_trending_tag" value="<?php echo esc_attr( $options['label_trending_tag'] ?? 'tendance' ); ?>" class="regular-text" style="max-width: 160px;" />
										</div>

										<!-- PREVIEW & DIAGNOSTIC BOX -->
										<?php $this->render_trending_preview_box(); ?>
									</div>
								</td>
							</tr>

							<!-- SCHEDULER & BATCH PERFORMANCE -->
							<tr>
								<th scope="row"><label for="daily_time"><?php esc_html_e( 'Heure de régénération quotidienne', 'woo-meta-catalog' ); ?></label></th>
								<td>
									<input name="daily_time" type="time" id="daily_time" value="<?php echo esc_attr( $options['daily_time'] ?? '03:30' ); ?>" />
									<p class="description"><?php esc_html_e( 'Heure locale programmée pour le déclenchement nocturne automatique via WooCommerce Action Scheduler.', 'woo-meta-catalog' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="batch_size"><?php esc_html_e( 'Taille des lots (Batch Chunk Size)', 'woo-meta-catalog' ); ?></label></th>
								<td>
									<input name="batch_size" type="number" step="10" min="10" max="500" id="batch_size" value="<?php echo esc_attr( $options['batch_size'] ?? 50 ); ?>" class="small-text" />
									<p class="description"><?php esc_html_e( 'Nombre de produits traités par lot asynchrone (défaut : 50). Recommandé à 50 pour éviter la saturation de mémoire vive PHP sur les boutiques avec beaucoup de variations.', 'woo-meta-catalog' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<input type="submit" name="woo_meta_catalog_save_settings" id="submit" class="button button-primary button-hero" value="<?php esc_attr_e( 'Enregistrer les réglages', 'woo-meta-catalog' ); ?>" />
					</p>
				</form>
			</div>

			<!-- DOCUMENTATION CALLOUT -->
			<div class="woo-meta-doc-card">
				<h3><span class="dashicons dashicons-book-alt"></span> <?php esc_html_e( 'Procédure d\'ajout dans Meta Commerce Manager', 'woo-meta-catalog' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Connectez-vous à Meta Commerce Manager (business.facebook.com/commerce).', 'woo-meta-catalog' ); ?></li>
					<li><?php esc_html_e( 'Sélectionnez votre catalogue, puis allez dans Catalogue > Sources de données > Flux de données (Data feed).', 'woo-meta-catalog' ); ?></li>
					<li><?php esc_html_e( 'Choisissez « Flux programmé » (Scheduled feed), collez l\'URL ci-dessus, et réglez la fréquence sur Quotidienne (ex. 04h30 ou 05h00 du matin, soit 1h après la régénération du site).', 'woo-meta-catalog' ); ?></li>
					<li><?php esc_html_e( 'Validez l\'importation : Meta analysera et synchronisera tous vos produits simples et déclinaisons avec les identifiants stricts CAPI.', 'woo-meta-catalog' ); ?></li>
				</ol>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "Tracking Meta" alignment box.
	 *
	 * Compares, on a sample of 5 published products, the content_id sent by
	 * woo-fb-tracking-server-side (\WFBT\Product_Id::get) with the catalog <g:id>
	 * (Feed_Item::get_content_id). Same sample query as the tracking plugin box,
	 * so both screens evaluate the same products.
	 */
	private function render_tracking_alignment_box() {
		$box_style = 'margin: 0 0 20px; padding: 14px 18px; border-radius: 4px; font-size: 13px; line-height: 1.5;';

		if ( ! defined( 'WFBT_VERSION' ) || ! class_exists( '\WFBT\Product_Id' ) ) {
			?>
			<div class="woo-meta-tracking-box" style="<?php echo esc_attr( $box_style ); ?> border-left: 4px solid #f59e0b; background: #fffbeb; color: #92400e;">
				<strong><span class="dashicons dashicons-warning" style="vertical-align: middle; margin-right: 4px; color: #f59e0b;"></span><?php esc_html_e( 'Tracking Meta', 'woo-meta-catalog' ); ?></strong><br />
				<?php esc_html_e( 'Aucun tracking Meta SOYOO détecté : les événements ViewContent/AddToCart/Purchase ne seront pas reliés au catalogue.', 'woo-meta-catalog' ); ?>
			</div>
			<?php
			return;
		}

		$sample = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 5,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => array( 'simple', 'variation', 'external' ),
			)
		);

		$rows       = array();
		$mismatches = 0;
		foreach ( $sample as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$tracking_id = (string) \WFBT\Product_Id::get( $product );
			$feed_id     = Feed_Item::get_content_id( $product );
			$match       = ( $tracking_id === $feed_id );
			if ( ! $match ) {
				$mismatches++;
			}
			$rows[] = array( $product->get_name(), $tracking_id, $feed_id, $match );
		}

		$ok        = ( 0 === $mismatches );
		$effective = (string) \WFBT\Product_Id::get_effective_format();
		$color     = $ok ? '#008a00' : '#d93025';
		$bg        = $ok ? '#e7f7ed' : '#fce8e6';
		?>
		<div class="woo-meta-tracking-box" style="<?php echo esc_attr( $box_style ); ?> border-left: 4px solid <?php echo esc_attr( $color ); ?>; background: <?php echo esc_attr( $bg ); ?>;">
			<strong style="color: <?php echo esc_attr( $color ); ?>;">
				<span class="dashicons <?php echo $ok ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>" style="vertical-align: middle; margin-right: 4px;"></span>
				<?php
				if ( $ok ) {
					echo esc_html( sprintf( __( 'Tracking aligné (mode : %s)', 'woo-meta-catalog' ), $effective ) );
				} else {
					echo esc_html( sprintf( __( 'Tracking désaligné (mode : %s) : le taux de correspondance catalogue Meta va chuter à 0 %%.', 'woo-meta-catalog' ), $effective ) );
				}
				?>
			</strong>
			<span style="color: #646970;">
				<?php echo esc_html( sprintf( __( '(woo-fb-tracking-server-side v%s)', 'woo-meta-catalog' ), WFBT_VERSION ) ); ?>
			</span>
			<?php if ( ! $ok ) : ?>
				<br />
				<?php esc_html_e( 'Passez le format d\'ID du tracking sur « Automatique » pour déléguer les content_ids au flux :', 'woo-meta-catalog' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wfbt-settings' ) ); ?>"><?php esc_html_e( 'Ouvrir les réglages du tracking Meta', 'woo-meta-catalog' ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $rows ) ) : ?>
				<table class="widefat striped" style="margin-top: 10px; font-size: 12px;">
					<thead><tr>
						<th><?php esc_html_e( 'Produit', 'woo-meta-catalog' ); ?></th>
						<th><?php esc_html_e( 'content_id tracking', 'woo-meta-catalog' ); ?></th>
						<th><?php esc_html_e( 'g:id flux', 'woo-meta-catalog' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row[0] ); ?></td>
								<td><code><?php echo esc_html( $row[1] ); ?></code></td>
								<td><code><?php echo esc_html( $row[2] ); ?></code></td>
								<td style="color: <?php echo $row[3] ? '#008a00' : '#d93025'; ?>;"><?php echo $row[3] ? '✓' : '✗'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Diagnostic & Health tab.
	 */
	public function render_diagnostic_tab() {
		$diagnostic_data = Feed_Diagnostic::get_health_status();
		$summary         = $diagnostic_data['summary'];
		$checks          = $diagnostic_data['checks'];
		$logs            = Feed_Logger::get_logs( 50 );
		$log_count       = count( $logs );
		$mem_limit       = ini_get( 'memory_limit' );
		$max_time        = (int) ini_get( 'max_execution_time' );
		?>
		<div class="woo-meta-diagnostic-container">
			<!-- TOP METRICS / SUMMARY CARDS -->
			<div class="woo-meta-diag-summary-grid">
				<div class="diag-summary-card <?php echo $summary['errors'] > 0 ? 'is-error' : ( $summary['warnings'] > 0 ? 'is-warning' : 'is-ok' ); ?>">
					<div class="card-icon">
						<?php if ( $summary['errors'] > 0 ) : ?>
							<span class="dashicons dashicons-dismiss"></span>
						<?php elseif ( $summary['warnings'] > 0 ) : ?>
							<span class="dashicons dashicons-warning"></span>
						<?php else : ?>
							<span class="dashicons dashicons-yes-alt"></span>
						<?php endif; ?>
					</div>
					<div class="card-text">
						<h4><?php esc_html_e( 'Santé Globale', 'woo-meta-catalog' ); ?></h4>
						<span class="value">
							<?php
							if ( $summary['errors'] > 0 ) {
								echo esc_html( sprintf( _n( '%d Problème détecté', '%d Problèmes détectés', $summary['errors'], 'woo-meta-catalog' ), $summary['errors'] ) );
							} elseif ( $summary['warnings'] > 0 ) {
								echo esc_html( sprintf( _n( '%d Avertissement', '%d Avertissements', $summary['warnings'], 'woo-meta-catalog' ), $summary['warnings'] ) );
							} else {
								esc_html_e( 'Tous les voyants sont au vert', 'woo-meta-catalog' );
							}
							?>
						</span>
						<p class="sub"><?php echo esc_html( sprintf( __( '%1$d vérifications : %2$d valides, %3$d avertissements, %4$d erreurs', 'woo-meta-catalog' ), count( $checks ), $summary['ok'], $summary['warnings'], $summary['errors'] ) ); ?></p>
					</div>
				</div>

				<div class="diag-summary-card <?php echo $log_count > 0 ? 'is-warning' : 'is-neutral'; ?>">
					<div class="card-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="card-text">
						<h4><?php esc_html_e( 'Incidents Enregistrés', 'woo-meta-catalog' ); ?></h4>
						<span class="value" id="diag-log-count-val"><?php echo (int) $log_count; ?></span>
						<p class="sub"><?php esc_html_e( 'Erreurs & avertissements de génération', 'woo-meta-catalog' ); ?></p>
					</div>
					<?php if ( $log_count > 0 ) : ?>
						<button type="button" class="button button-small btn-clear-logs-secondary" id="btn-clear-logs-header">
							<?php esc_html_e( 'Vider', 'woo-meta-catalog' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="diag-summary-card is-neutral">
					<div class="card-icon">
						<span class="dashicons dashicons-performance"></span>
					</div>
					<div class="card-text">
						<h4><?php esc_html_e( 'Ressources PHP', 'woo-meta-catalog' ); ?></h4>
						<span class="value"><?php echo esc_html( $mem_limit ); ?></span>
						<p class="sub"><?php echo esc_html( sprintf( __( 'PHP %1$s | Délai max : %2$s', 'woo-meta-catalog' ), PHP_VERSION, ( $max_time > 0 ? $max_time . 's' : '0s' ) ) ); ?></p>
					</div>
				</div>
			</div>

			<!-- LIVE SAMPLE TEST CARD (EXPRESS DRY-RUN) -->
			<div class="woo-meta-diag-test-card">
				<div class="card-header">
					<div class="title-wrap">
						<h3><span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Test de génération express (Dry-Run 5 produits)', 'woo-meta-catalog' ); ?></h3>
						<p class="desc"><?php esc_html_e( 'Simule la génération XML en mémoire vive sur 5 produits récents pour mesurer la durée d\'exécution, le delta de consommation RAM et vérifier la conformité du balisage.', 'woo-meta-catalog' ); ?></p>
					</div>
					<div class="action-wrap">
						<button type="button" class="button button-primary button-large" id="btn-run-diag-test">
							<span class="dashicons dashicons-controls-play"></span>
							<span class="btn-text"><?php esc_html_e( 'Lancer le test express', 'woo-meta-catalog' ); ?></span>
						</button>
					</div>
				</div>

				<div id="diag-test-results-placeholder" class="diag-test-results" style="display: none;">
					<div class="diag-test-spinner" style="display: none; padding: 20px 0; text-align: center;">
						<span class="spinner is-active" style="float: none; margin: 0 8px 0 0; vertical-align: middle;"></span>
						<span style="font-weight: 600; color: #475569;"><?php esc_html_e( 'Compilation test en cours dans la mémoire PHP...', 'woo-meta-catalog' ); ?></span>
					</div>
					<div class="diag-test-output" style="display: none;">
						<!-- Injected dynamically via JS -->
					</div>
				</div>
			</div>

			<!-- AUTOMATED HEALTH CHECKS -->
			<div class="woo-meta-diag-section">
				<div class="section-title">
					<h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Vérifications automatiques du système', 'woo-meta-catalog' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Audit complet de l\'environnement d\'exécution, de la compatibilité du serveur et de l\'intégrité des composants du flux.', 'woo-meta-catalog' ); ?></p>
				</div>

				<div class="diag-checks-grid">
					<?php foreach ( $checks as $check_key => $check ) :
						$c_status    = $check['status'] ?? 'ok';
						$icon_class  = 'dashicons-yes-alt';
						$badge_class = 'diag-badge-ok';
						if ( 'warning' === $c_status ) {
							$icon_class  = 'dashicons-warning';
							$badge_class = 'diag-badge-warn';
						} elseif ( 'error' === $c_status ) {
							$icon_class  = 'dashicons-dismiss';
							$badge_class = 'diag-badge-err';
						} elseif ( 'info' === $c_status ) {
							$icon_class  = 'dashicons-info';
							$badge_class = 'diag-badge-info';
						}
						?>
						<div class="diag-check-card <?php echo esc_attr( 'status-' . $c_status ); ?>">
							<div class="check-header">
								<div class="check-title">
									<span class="dashicons <?php echo esc_attr( $icon_class ); ?>"></span>
									<strong><?php echo esc_html( $check['title'] ); ?></strong>
								</div>
								<span class="diag-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $check['badge'] ); ?></span>
							</div>

							<?php if ( ! empty( $check['notes'] ) ) : ?>
								<div class="check-notes">
									<?php foreach ( $check['notes'] as $note ) : ?>
										<div class="note-item <?php echo esc_attr( 'note-' . $c_status ); ?>">
											<span class="dashicons dashicons-arrow-right-alt2"></span>
											<?php echo esc_html( $note ); ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<div class="check-details">
								<table class="diag-details-table">
									<tbody>
										<?php foreach ( $check['details'] as $label => $val ) : ?>
											<tr>
												<td class="dt-label"><?php echo esc_html( $label ); ?></td>
												<td class="dt-val"><code><?php echo esc_html( (string) $val ); ?></code></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- INCIDENT & ERROR LOGS TABLE -->
			<div class="woo-meta-diag-section" id="diag-logs-section">
				<div class="section-title logs-header-bar">
					<div>
						<h2><span class="dashicons dashicons-format-aside"></span> <?php esc_html_e( 'Journal des incidents de génération', 'woo-meta-catalog' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Historique des 50 dernières erreurs, avertissements ou exceptions interceptées lors des générations manuelles ou automatiques.', 'woo-meta-catalog' ); ?></p>
					</div>
					<?php if ( ! empty( $logs ) ) : ?>
						<button type="button" class="button button-secondary" id="btn-clear-logs">
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Vider le journal', 'woo-meta-catalog' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="diag-logs-wrapper">
					<?php if ( empty( $logs ) ) : ?>
						<div class="diag-empty-logs">
							<span class="dashicons dashicons-yes-alt"></span>
							<h3><?php esc_html_e( 'Aucun incident enregistré', 'woo-meta-catalog' ); ?></h3>
							<p><?php esc_html_e( 'Le journal est vierge. Aucune exception PHP ni erreur de traitement n\'a été capturée récemment.', 'woo-meta-catalog' ); ?></p>
						</div>
					<?php else : ?>
						<table class="widefat striped diag-logs-table" id="diag-logs-table">
							<thead>
								<tr>
									<th style="width: 140px;"><?php esc_html_e( 'Date & Heure', 'woo-meta-catalog' ); ?></th>
									<th style="width: 90px;"><?php esc_html_e( 'Niveau', 'woo-meta-catalog' ); ?></th>
									<th><?php esc_html_e( 'Message d\'erreur', 'woo-meta-catalog' ); ?></th>
									<th style="width: 130px;"><?php esc_html_e( 'Mémoire RAM', 'woo-meta-catalog' ); ?></th>
									<th style="width: 180px;"><?php esc_html_e( 'Contexte', 'woo-meta-catalog' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $logs as $log ) :
									$level     = $log['level'] ?? 'error';
									$lvl_class = 'lvl-' . $level;
									?>
									<tr>
										<td class="log-date">
											<strong><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $log['date'] ) ) ); ?></strong><br />
											<span class="time-sub"><?php echo esc_html( date_i18n( 'H:i:s', strtotime( $log['date'] ) ) ); ?></span>
										</td>
										<td class="log-level">
											<span class="badge-log-lvl <?php echo esc_attr( $lvl_class ); ?>"><?php echo esc_html( strtoupper( $level ) ); ?></span>
										</td>
										<td class="log-msg">
											<strong><?php echo esc_html( $log['message'] ); ?></strong>
											<?php if ( ! empty( $log['context']['file'] ) ) : ?>
												<div class="log-file-loc">
													<code><?php echo esc_html( $log['context']['file'] ); ?></code>
												</div>
											<?php endif; ?>
										</td>
										<td class="log-mem">
											<span title="<?php esc_attr_e( 'Usage au moment de l\'erreur / Pic', 'woo-meta-catalog' ); ?>">
												<?php echo esc_html( $log['memory'] ); ?> / <small><?php echo esc_html( $log['memory_peak'] ); ?></small>
											</span>
										</td>
										<td class="log-ctx">
											<?php
											if ( ! empty( $log['context'] ) ) {
												$ctx_items = array();
												if ( isset( $log['context']['step'] ) ) {
													$ctx_items[] = 'Lot: ' . $log['context']['step'];
												}
												if ( ! empty( $log['context']['product_id'] ) ) {
													$ctx_items[] = 'ID: #' . $log['context']['product_id'];
												}
												if ( ! empty( $log['context']['run_id'] ) ) {
													$ctx_items[] = 'Run: ' . substr( $log['context']['run_id'], -6 );
												}
												if ( ! empty( $ctx_items ) ) {
													echo '<span class="log-ctx-pill">' . esc_html( implode( ' | ', $ctx_items ) ) . '</span>';
												} else {
													echo '<code>' . esc_html( wp_json_encode( $log['context'] ) ) . '</code>';
												}
											} else {
												echo '-';
											}
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}

