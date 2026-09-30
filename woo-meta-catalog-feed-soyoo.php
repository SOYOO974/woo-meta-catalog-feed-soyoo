<?php
/**
 * Plugin Name:       Woo Meta Catalog Feed Soyoo
 * Plugin URI:        https://github.com/SOYOO974/woo-meta-catalog-feed-soyoo/
 * Description:       Générateur de flux catalogue XML haute performance, ultra-léger et autonome pour Meta Ads (Commerce Manager, Advantage+ Catalog Ads, retargeting DPA) et Google Shopping.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SOYOO
 * Author URI:        https://soyoo.re
 * Text Domain:       woo-meta-catalog
 * Domain Path:       /languages
 *
 * @package           WooMetaCatalogFeedSoyoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Plugin constants.
define( 'WOO_META_CATALOG_FEED_VERSION', '1.0.1' );
define( 'WOO_META_CATALOG_FEED_FILE', __FILE__ );
define( 'WOO_META_CATALOG_FEED_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_META_CATALOG_FEED_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_META_CATALOG_FEED_BASENAME', plugin_basename( __FILE__ ) );

// Initialize the Plugin Update Checker for GitHub releases.
require_once WOO_META_CATALOG_FEED_DIR . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$woo_meta_catalog_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/SOYOO974/woo-meta-catalog-feed-soyoo/',
	__FILE__,
	'woo-meta-catalog-feed-soyoo'
);

// Set the branch that contains the stable release.
$woo_meta_catalog_update_checker->setBranch( 'main' );
$woo_meta_catalog_update_checker->getVcsApi()->enableReleaseAssets();

// 2. Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// 3. Activation & Deactivation hooks.
register_activation_hook( __FILE__, 'woo_meta_catalog_feed_activate' );
register_deactivation_hook( __FILE__, 'woo_meta_catalog_feed_deactivate' );

/**
 * Plugin activation routine.
 */
function woo_meta_catalog_feed_activate() {
	// Initialize default settings if not set.
	$default_settings = array(
		'exclude_out_of_stock' => 0,
		'exclude_no_image'     => 1,
		'default_brand'        => get_bloginfo( 'name' ),
		'brand_attribute'      => '',
		'enable_utms'          => 1,
		'utm_source'           => 'facebook',
		'utm_medium'           => 'catalog',
		'utm_campaign'         => 'meta_feed',
		'enable_security_key'  => 0,
		'security_key'         => wp_generate_password( 24, false, false ),
		'batch_size'           => 200,
		'daily_time'           => '03:30',
	);

	$existing = get_option( 'woo_meta_catalog_settings', array() );
	if ( empty( $existing ) ) {
		update_option( 'woo_meta_catalog_settings', $default_settings );
	}

	// Load core generator for scheduling.
	require_once WOO_META_CATALOG_FEED_DIR . 'includes/class-feed-generator.php';
	\SOYOO\MetaCatalog\Feed_Generator::schedule_daily_event();

	// Flush rewrite rules for pretty /feed/meta-catalog.xml URL.
	require_once WOO_META_CATALOG_FEED_DIR . 'includes/class-feed-server.php';
	$server = new \SOYOO\MetaCatalog\Feed_Server();
	$server->register_rewrite_rules();
	flush_rewrite_rules( false );
}

/**
 * Plugin deactivation routine.
 */
function woo_meta_catalog_feed_deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'woo_meta_catalog_daily_generation' );
		as_unschedule_all_actions( 'woo_meta_catalog_process_chunk' );
		as_unschedule_all_actions( 'woo_meta_catalog_finalize_feed' );
	}
	flush_rewrite_rules( false );
}

// 4. Initialize plugin at plugins_loaded.
add_action( 'plugins_loaded', 'woo_meta_catalog_feed_init', 11 );

/**
 * Boot up core plugin classes.
 */
function woo_meta_catalog_feed_init() {
	load_plugin_textdomain( 'woo-meta-catalog', false, dirname( WOO_META_CATALOG_FEED_BASENAME ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woo_meta_catalog_missing_wc_notice' );
		return;
	}

	// Load classes.
	require_once WOO_META_CATALOG_FEED_DIR . 'includes/class-feed-item.php';
	require_once WOO_META_CATALOG_FEED_DIR . 'includes/class-feed-generator.php';
	require_once WOO_META_CATALOG_FEED_DIR . 'includes/class-feed-server.php';
	require_once WOO_META_CATALOG_FEED_DIR . 'includes/class-feed-admin.php';
	require_once WOO_META_CATALOG_FEED_DIR . 'includes/class-feed-cli.php';

	// Boot generator & schedule check.
	\SOYOO\MetaCatalog\Feed_Generator::instance();

	// Boot delivery server.
	new \SOYOO\MetaCatalog\Feed_Server();

	// Boot admin interface.
	if ( is_admin() ) {
		new \SOYOO\MetaCatalog\Feed_Admin();
	}

	// Register WP-CLI commands if running in CLI.
	\SOYOO\MetaCatalog\Feed_CLI::register();
}

/**
 * Admin notice if WooCommerce is inactive.
 */
function woo_meta_catalog_missing_wc_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Woo Meta Catalog Feed Soyoo nécessite que WooCommerce soit installé et activé.', 'woo-meta-catalog' ); ?></p>
	</div>
	<?php
}

/**
 * Add settings action link to plugin row in admin list.
 */
add_filter( 'plugin_action_links_' . WOO_META_CATALOG_FEED_BASENAME, function( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=woo-meta-catalog-feed' ) ) . '">' . __( 'Réglages & Flux', 'woo-meta-catalog' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );
