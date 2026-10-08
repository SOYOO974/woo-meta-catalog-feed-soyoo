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

		$settings = array(
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
			'batch_size'              => max( 50, min( 1000, intval( $_POST['batch_size'] ?? 200 ) ) ),
			'daily_time'              => sanitize_text_field( wp_unslash( $_POST['daily_time'] ?? '03:30' ) ),

			// Meta internal labels settings.
			'label_include_categories' => isset( $_POST['label_include_categories'] ) ? 1 : 0,
			'label_include_tags'       => isset( $_POST['label_include_tags'] ) ? 1 : 0,
			'label_enable_promo'       => isset( $_POST['label_enable_promo'] ) ? 1 : 0,
			'label_promo_tag'          => sanitize_text_field( wp_unslash( $_POST['label_promo_tag'] ?? 'promo' ) ),
			'label_enable_new'         => isset( $_POST['label_enable_new'] ) ? 1 : 0,
			'label_new_days'           => max( 1, intval( $_POST['label_new_days'] ?? 30 ) ),
			'label_new_tag'            => sanitize_text_field( wp_unslash( $_POST['label_new_tag'] ?? 'nouveaute' ) ),
			'label_enable_bestseller'  => isset( $_POST['label_enable_bestseller'] ) ? 1 : 0,
			'label_bestseller_mode'    => ( isset( $_POST['label_bestseller_mode'] ) && 'percentage' === $_POST['label_bestseller_mode'] ) ? 'percentage' : 'count',
			'label_bestseller_value'   => max( 1, intval( $_POST['label_bestseller_value'] ?? 50 ) ),
			'label_bestseller_tag'     => sanitize_text_field( wp_unslash( $_POST['label_bestseller_tag'] ?? 'bestseller' ) ),
		);

		update_option( 'woo_meta_catalog_settings', $settings );

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
										<?php esc_html_e( 'Permet de segmenter vos produits dans Meta Commerce Manager pour créer des ensembles dynamiques (Product Sets) sans limite et sans déclencher de réexamen publicitaire.', 'woo-meta-catalog' ); ?>
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
											<label for="label_new_tag"><?php esc_html_e( 'Nom du marqueur :', 'woo-meta-catalog' ); ?></label>
											<input name="label_new_tag" type="text" id="label_new_tag" value="<?php echo esc_attr( $options['label_new_tag'] ?? 'nouveaute' ); ?>" class="regular-text" style="max-width: 160px;" />
										</div>
									</div>
									<p class="description">
										<?php esc_html_e( 'Attribue automatiquement le tag spécifié aux produits créés récemment pour créer un ensemble "Nouveautés" dynamique dans Meta.', 'woo-meta-catalog' ); ?>
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
											<label for="label_bestseller_mode"><?php esc_html_e( 'Mode de calcul :', 'woo-meta-catalog' ); ?></label>
											<select name="label_bestseller_mode" id="label_bestseller_mode">
												<option value="count" <?php selected( ( $options['label_bestseller_mode'] ?? 'count' ), 'count' ); ?>><?php esc_html_e( 'Nombre fixe de produits', 'woo-meta-catalog' ); ?></option>
												<option value="percentage" <?php selected( ( $options['label_bestseller_mode'] ?? 'count' ), 'percentage' ); ?>><?php esc_html_e( 'Pourcentage des produits en stock (%)', 'woo-meta-catalog' ); ?></option>
											</select>
										</div>
										<div>
											<label for="label_bestseller_value"><?php esc_html_e( 'Valeur (Top N ou %) :', 'woo-meta-catalog' ); ?></label>
											<input name="label_bestseller_value" type="number" min="1" id="label_bestseller_value" value="<?php echo esc_attr( $options['label_bestseller_value'] ?? 50 ); ?>" class="small-text" />
										</div>
										<div>
											<label for="label_bestseller_tag"><?php esc_html_e( 'Nom du marqueur :', 'woo-meta-catalog' ); ?></label>
											<input name="label_bestseller_tag" type="text" id="label_bestseller_tag" value="<?php echo esc_attr( $options['label_bestseller_tag'] ?? 'bestseller' ); ?>" class="regular-text" style="max-width: 160px;" />
										</div>
									</div>
									<p class="description">
										<?php esc_html_e( 'Identifie automatiquement les produits en stock ayant le plus grand volume de ventes historiques (total_sales > 0) et leur applique l\'étiquette bestseller. Idéal pour cibler vos bestsellers en Advantage+ Catalog Ads.', 'woo-meta-catalog' ); ?>
									</p>
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
									<input name="batch_size" type="number" step="50" min="50" max="1000" id="batch_size" value="<?php echo esc_attr( $options['batch_size'] ?? 200 ); ?>" class="small-text" />
									<p class="description"><?php esc_html_e( 'Nombre de produits traités par lot asynchrone (défaut : 200). Permet de contourner les limites de mémoire et de temps d\'exécution des hébergeurs de haute performance comme Rocket.net.', 'woo-meta-catalog' ); ?></p>
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
}
