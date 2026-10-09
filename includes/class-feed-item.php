<?php
/**
 * Feed Item formatter for Meta Catalog and Google Shopping XML.
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_Item
 */
class Feed_Item {

	/**
	 * Convert a standard or variation WooCommerce product to an XML <item> string.
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if current product is a variation.
	 * @param array            $options Feed generation options.
	 * @return string|null XML string or null if excluded.
	 */
	public static function build( $product, $parent = null, $options = array() ) {
		if ( ! $product || ! is_a( $product, '\WC_Product' ) ) {
			return null;
		}

		$is_variation          = ( null !== $parent && is_a( $parent, '\WC_Product' ) );
		$target_for_visibility = $is_variation ? $parent : $product;

		// 1. Visibility check (exclude hidden products).
		$exclude_hidden = isset( $options['exclude_hidden'] ) ? ! empty( $options['exclude_hidden'] ) : true;
		if ( $exclude_hidden && 'hidden' === $target_for_visibility->get_catalog_visibility() ) {
			return null;
		}

		// 2. Stock & Availability check.
		$is_in_stock  = $product->is_in_stock();
		$availability = $is_in_stock ? 'in stock' : 'out of stock';

		if ( ! empty( $options['exclude_out_of_stock'] ) && ! $is_in_stock ) {
			return null;
		}

		// 3. Dead stock check (exclude products out of stock without recent sales or old creation date).
		$dead_stock_days = ! empty( $options['exclude_dead_stock_days'] ) ? (int) $options['exclude_dead_stock_days'] : 0;
		if ( $dead_stock_days > 0 && self::is_dead_stock( $product, $parent, $dead_stock_days ) ) {
			return null;
		}

		// 2. Critical CAPI ID alignment: single source of truth shared with
		// woo-fb-tracking-server-side through the `soyoo_meta_catalog_content_id` filter.
		$id_format = isset( $options['id_format'] ) ? $options['id_format'] : null;
		$id        = self::get_content_id( $product, $id_format );

		// 3. Images.
		$image_id = $product->get_image_id();
		if ( ! $image_id && $is_variation ) {
			$image_id = $parent->get_image_id();
		}

		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		if ( empty( $image_url ) && ! empty( $options['exclude_no_image'] ) ) {
			return null;
		}

		if ( ! empty( $image_url ) ) {
			$image_url = self::format_image_url( $image_url, $options );
		}

		/**
		 * Filter the main catalog image URL.
		 *
		 * @param string           $image_url Primary image URL.
		 * @param int              $image_id  Attachment ID.
		 * @param \WC_Product      $product   Current product or variation.
		 * @param \WC_Product|null $parent    Parent product if variation.
		 * @param array            $options   Feed generation options.
		 */
		$image_url = apply_filters( 'woo_meta_catalog_image_url', $image_url, $image_id, $product, $parent, $options );

		// 4. Title formatting.
		if ( $is_variation ) {
			$raw_title = $product->get_name();
			// Fallback if variation name is identical to parent or empty.
			if ( empty( $raw_title ) || $raw_title === $parent->get_name() ) {
				$attributes_desc = wc_get_formatted_variation( $product, true, false, false );
				$raw_title       = $parent->get_name() . ( $attributes_desc ? ' - ' . $attributes_desc : '' );
			}
		} else {
			$raw_title = $product->get_name();
		}
		$title = self::clean_text( $raw_title, 150 );

		// 5. Description formatting.
		$raw_desc = $product->get_short_description();
		if ( empty( $raw_desc ) ) {
			$raw_desc = $product->get_description();
		}
		if ( empty( $raw_desc ) && $is_variation ) {
			$raw_desc = $parent->get_short_description() ?: $parent->get_description();
		}
		if ( empty( $raw_desc ) ) {
			$raw_desc = $raw_title;
		}
		$description = self::clean_text( $raw_desc, 5000 );

		// 6. Link & UTMs.
		$raw_link = $is_variation ? $product->get_permalink() : $product->get_permalink();
		if ( empty( $raw_link ) && $is_variation ) {
			$raw_link = $parent->get_permalink();
		}

		$link = $raw_link;
		if ( ! empty( $options['enable_utms'] ) ) {
			$utm_args = array();
			if ( ! empty( $options['utm_source'] ) ) {
				$utm_args['utm_source'] = sanitize_text_field( $options['utm_source'] );
			}
			if ( ! empty( $options['utm_medium'] ) ) {
				$utm_args['utm_medium'] = sanitize_text_field( $options['utm_medium'] );
			}
			if ( ! empty( $options['utm_campaign'] ) ) {
				$utm_args['utm_campaign'] = sanitize_text_field( $options['utm_campaign'] );
			}
			if ( ! empty( $utm_args ) ) {
				$link = add_query_arg( $utm_args, $link );
			}
		}

		// 7. Prices & Currency (TTC-compliant, dynamic sales fallback & schedule dates).
		$prices                    = self::get_prices( $product, $parent );
		$formatted_price           = $prices['formatted_regular_price'];
		$formatted_sale_price      = $prices['formatted_sale_price'];
		$sale_price_effective_date = $prices['sale_price_effective_date'];

		// 8. Brand determination.
		$brand = '';
		$target_product_for_brand = $is_variation ? $parent : $product;
		
		if ( ! empty( $options['brand_attribute'] ) ) {
			$brand = $target_product_for_brand->get_attribute( $options['brand_attribute'] );
		}

		if ( empty( $brand ) ) {
			// Auto-detection of popular taxonomy attributes.
			$popular_taxonomies = array( 'pa_marque', 'pa_brand', 'brand', 'marque', 'yith_product_brand', 'product_brand' );
			foreach ( $popular_taxonomies as $tax ) {
				$val = $target_product_for_brand->get_attribute( $tax );
				if ( ! empty( $val ) ) {
					$brand = $val;
					break;
				}
			}
		}

		if ( empty( $brand ) ) {
			$brand = ! empty( $options['default_brand'] ) ? $options['default_brand'] : get_bloginfo( 'name' );
		}
		$brand = self::clean_text( $brand, 100 );

		// 9. Product Type (hierarchical category breadcrumbs).
		$target_for_cats = $is_variation ? $parent : $product;
		$product_type    = self::get_category_breadcrumb( $target_for_cats->get_id() );

		// 10. Additional images (gallery up to 5).
		$gallery_ids = $target_product_for_brand->get_gallery_image_ids();
		$additional_images = array();
		if ( is_array( $gallery_ids ) ) {
			$count = 0;
			foreach ( $gallery_ids as $gal_id ) {
				if ( $gal_id === $image_id ) {
					continue;
				}
				$gal_url = wp_get_attachment_image_url( $gal_id, 'full' );
				if ( $gal_url ) {
					$additional_images[] = self::format_image_url( $gal_url, $options );
					$count++;
					if ( $count >= 5 ) {
						break;
					}
				}
			}
		}

		// 11. Build XML Item string.
		$xml  = "\t\t<item>\n";
		$xml .= "\t\t\t<g:id>" . self::escape_xml( $id ) . "</g:id>\n";
		$xml .= "\t\t\t<g:title><![CDATA[" . self::sanitize_cdata( $title ) . "]]></g:title>\n";
		$xml .= "\t\t\t<g:description><![CDATA[" . self::sanitize_cdata( $description ) . "]]></g:description>\n";
		$xml .= "\t\t\t<g:link>" . self::escape_xml( $link ) . "</g:link>\n";

		if ( ! empty( $image_url ) ) {
			$xml .= "\t\t\t<g:image_link>" . self::escape_xml( $image_url ) . "</g:image_link>\n";
		}

		foreach ( $additional_images as $add_img ) {
			$xml .= "\t\t\t<g:additional_image_link>" . self::escape_xml( $add_img ) . "</g:additional_image_link>\n";
		}

		$xml .= "\t\t\t<g:availability>" . self::escape_xml( $availability ) . "</g:availability>\n";
		$xml .= "\t\t\t<g:price>" . self::escape_xml( $formatted_price ) . "</g:price>\n";

		if ( ! empty( $formatted_sale_price ) ) {
			$xml .= "\t\t\t<g:sale_price>" . self::escape_xml( $formatted_sale_price ) . "</g:sale_price>\n";

			if ( ! empty( $sale_price_effective_date ) ) {
				$xml .= "\t\t\t<g:sale_price_effective_date>" . self::escape_xml( $sale_price_effective_date ) . "</g:sale_price_effective_date>\n";
			}
		}

		$xml .= "\t\t\t<g:condition>new</g:condition>\n";

		if ( ! empty( $brand ) ) {
			$xml .= "\t\t\t<g:brand><![CDATA[" . self::sanitize_cdata( $brand ) . "]]></g:brand>\n";
		}

		// Item Group ID for variations (Parent ID).
		if ( $is_variation ) {
			$parent_id = (string) $parent->get_id();
			$xml .= "\t\t\t<g:item_group_id>" . self::escape_xml( $parent_id ) . "</g:item_group_id>\n";
		}

		if ( ! empty( $product_type ) ) {
			$xml .= "\t\t\t<g:product_type><![CDATA[" . self::sanitize_cdata( $product_type ) . "]]></g:product_type>\n";
		}

		// Internal labels for Meta Commerce Manager Product Sets segmentation.
		$internal_labels = self::get_internal_labels( $product, $parent, $options );
		if ( ! empty( $internal_labels ) && is_array( $internal_labels ) ) {
			foreach ( $internal_labels as $label ) {
				$xml .= "\t\t\t<g:internal_label><![CDATA[" . self::sanitize_cdata( $label ) . "]]></g:internal_label>\n";
			}
		}

		// Stock quantity if managed.
		if ( $product->managing_stock() ) {
			$stock_qty = $product->get_stock_quantity();
			if ( null !== $stock_qty ) {
				$xml .= "\t\t\t<g:inventory>" . (int) $stock_qty . "</g:inventory>\n";
			}
		}

		$xml .= "\t\t</item>\n";

		return $xml;
	}

	/**
	 * Resolve the catalog <g:id> of a product or variation.
	 *
	 * SINGLE SOURCE OF TRUTH for Meta IDs: woo-fb-tracking-server-side (v2.1.0+, "auto" mode)
	 * reads this value through the `soyoo_meta_catalog_content_id` filter for its
	 * content_ids (Pixel + CAPI). Any change here is automatically followed by the tracking.
	 *
	 * - 'id' (default, recommended): strictly uses WooCommerce Product ID ((string) $product->get_id()).
	 * - 'sku': SKU if defined, otherwise Post ID (for variations: own SKU only, no inheritance).
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param string|null $format  Optional format ('id' or 'sku'). Defaults to 'id_format' option or 'id'.
	 * @return string
	 */
	public static function get_content_id( \WC_Product $product, $format = null ): string {
		if ( null === $format ) {
			$settings = get_option( 'woo_meta_catalog_settings', array() );
			$format   = ! empty( $settings['id_format'] ) ? $settings['id_format'] : 'id';
		}

		if ( 'sku' === $format ) {
			$sku = $product->is_type( 'variation' ) ? $product->get_sku( 'edit' ) : $product->get_sku();
			$id  = ( '' !== (string) $sku ) ? (string) $sku : (string) $product->get_id();
		} else {
			$id = (string) $product->get_id();
		}

		/**
		 * Filters the catalog item ID (custom per-site formats).
		 * Also applied to the tracking content_ids via the inter-plugin contract.
		 *
		 * @param string      $id      Resolved <g:id>.
		 * @param \WC_Product $product Product or variation.
		 */
		return (string) apply_filters( 'woo_meta_catalog_item_id', $id, $product );
	}

	/**
	 * Calculate regular and promotional prices for a product or variation.
	 *
	 * Ensures strict compliance with store tax display settings (TTC / HT)
	 * via `wc_get_price_to_display()`, matching `woo-fb-tracking-server-side`
	 * CAPI value calculation, and provides dynamic sale price fallback and
	 * ISO 8601 promotion schedule dates (<g:sale_price_effective_date>).
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @return array Price data array:
	 *               - 'currency': ISO currency code.
	 *               - 'regular_price': (float) regular price displayed.
	 *               - 'sale_price': (float|null) sale price displayed if on sale.
	 *               - 'formatted_regular_price': string formatted with currency.
	 *               - 'formatted_sale_price': string|null formatted with currency.
	 *               - 'sale_price_effective_date': string|null ISO 8601 interval.
	 */
	public static function get_prices( \WC_Product $product, $parent = null ): array {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		$raw_reg  = $product->get_regular_price();

		if ( '' === $raw_reg || null === $raw_reg ) {
			$raw_reg = $product->get_price();
		}
		if ( '' === $raw_reg || null === $raw_reg ) {
			$raw_reg = 0;
		}

		$regular_price = function_exists( 'wc_get_price_to_display' )
			? (float) wc_get_price_to_display( $product, array( 'price' => $raw_reg ) )
			: (float) $raw_reg;

		$sale_price                = null;
		$formatted_sale_price      = null;
		$sale_price_effective_date = null;

		if ( $product->is_on_sale() ) {
			$raw_sale = $product->get_sale_price();
			// Dynamic pricing fallback: if get_sale_price() is empty, fallback to current effective price.
			if ( '' === $raw_sale || null === $raw_sale ) {
				$raw_sale = $product->get_price();
			}

			if ( '' !== $raw_sale && null !== $raw_sale ) {
				$calc_sale = function_exists( 'wc_get_price_to_display' )
					? (float) wc_get_price_to_display( $product, array( 'price' => $raw_sale ) )
					: (float) $raw_sale;

				// Sale price must be strictly lower than regular price to be valid in Meta / Google.
				if ( $calc_sale > 0 && $calc_sale < $regular_price ) {
					$sale_price           = $calc_sale;
					$formatted_sale_price = number_format( $sale_price, 2, '.', '' ) . ' ' . $currency;

					// Check for scheduled promotion dates (WC_DateTime).
					$date_from = $product->get_date_on_sale_from();
					$date_to   = $product->get_date_on_sale_to();

					// Fallback to parent dates if variation has no specific schedule dates.
					if ( ! $date_from && $parent && is_a( $parent, '\WC_Product' ) ) {
						$date_from = $parent->get_date_on_sale_from();
					}
					if ( ! $date_to && $parent && is_a( $parent, '\WC_Product' ) ) {
						$date_to = $parent->get_date_on_sale_to();
					}

					if ( $date_from && $date_to && method_exists( $date_from, 'format' ) && method_exists( $date_to, 'format' ) ) {
						$sale_price_effective_date = $date_from->format( 'c' ) . '/' . $date_to->format( 'c' );
					}
				}
			}
		}

		$formatted_regular_price = number_format( $regular_price, 2, '.', '' ) . ' ' . $currency;

		$prices = array(
			'currency'                  => $currency,
			'regular_price'             => $regular_price,
			'sale_price'                => $sale_price,
			'formatted_regular_price'   => $formatted_regular_price,
			'formatted_sale_price'      => $formatted_sale_price,
			'sale_price_effective_date' => $sale_price_effective_date,
		);

		/**
		 * Filters the calculated prices for a product in the catalog feed.
		 *
		 * @param array            $prices  Calculated price data.
		 * @param \WC_Product      $product Current product or variation.
		 * @param \WC_Product|null $parent  Parent product if variation.
		 */
		return (array) apply_filters( 'woo_meta_catalog_product_prices', $prices, $product, $parent );
	}

	/**
	 * Get category breadcrumb for a product (e.g. Vêtements > Fille > Robes).
	 *
	 * @param int $product_id Product ID.
	 * @return string Breadcrumb path.
	 */
	public static function get_category_breadcrumb( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}

		// Find the deepest category.
		$deepest_term = null;
		$max_ancestors = -1;

		foreach ( $terms as $term ) {
			$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
			$count     = count( $ancestors );
			if ( $count > $max_ancestors ) {
				$max_ancestors = $count;
				$deepest_term  = $term;
			}
		}

		if ( ! $deepest_term ) {
			return '';
		}

		$path_terms = array();
		$ancestors  = array_reverse( get_ancestors( $deepest_term->term_id, 'product_cat', 'taxonomy' ) );

		foreach ( $ancestors as $ancestor_id ) {
			$anc_term = get_term( $ancestor_id, 'product_cat' );
			if ( $anc_term && ! is_wp_error( $anc_term ) ) {
				$path_terms[] = $anc_term->name;
			}
		}
		$path_terms[] = $deepest_term->name;

		return implode( ' > ', $path_terms );
	}

	/**
	 * Clean text for XML output (strip shortcodes, html tags, decode entities).
	 *
	 * @param string $text Raw text.
	 * @param int    $max_len Maximum length.
	 * @return string Cleaned text.
	 */
	public static function clean_text( $text, $max_len = 0 ) {
		if ( empty( $text ) ) {
			return '';
		}

		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Remove non-printable control characters except tab, CR, LF.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		// Collapse multiple spaces.
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		if ( $max_len > 0 && mb_strlen( $text, 'UTF-8' ) > $max_len ) {
			$text = mb_substr( $text, 0, $max_len, 'UTF-8' );
		}

		return $text;
	}

	/**
	 * Sanitize text inside a CDATA block (prevent premature closing ]]>).
	 *
	 * @param string $text Text to place inside CDATA.
	 * @return string Safe text.
	 */
	public static function sanitize_cdata( $text ) {
		return str_replace( ']]>', ']]&gt;', $text );
	}

	/**
	 * Cache for product/variation last sale timestamps.
	 *
	 * @var array<string, int|null>
	 */
	protected static $sales_timestamp_cache = array();

	/**
	 * Cache for bestselling product IDs lookup map.
	 *
	 * @var array<int, bool>|null
	 */
	protected static $bestseller_ids_cache = null;

	/**
	 * Cache for trending product IDs lookup map.
	 *
	 * @var array<int, bool>|null
	 */
	protected static $trending_ids_cache = null;

	/**
	 * Cache for new product IDs lookup map.
	 *
	 * @var array<int, bool>|null
	 */
	protected static $new_product_ids_cache = null;

	/**
	 * Check if WooCommerce Analytics order lookup table exists.
	 *
	 * @return bool
	 */
	public static function has_order_lookup_table() {
		static $has_table = null;
		if ( null === $has_table ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'wc_order_product_lookup';
			$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
			$has_table  = ( $found === $table_name );
		}
		return $has_table;
	}

	/**
	 * Get the last sale timestamp for a product or variation from wc_order_product_lookup.
	 *
	 * @param int $product_id   Product ID (or parent ID if variation).
	 * @param int $variation_id Variation ID (0 if simple product).
	 * @return int|null Timestamp of last sale, or null if no sales recorded.
	 */
	public static function get_last_sale_timestamp( $product_id, $variation_id = 0 ) {
		$cache_key = $variation_id > 0 ? 'v_' . $variation_id : 'p_' . $product_id;
		if ( array_key_exists( $cache_key, self::$sales_timestamp_cache ) ) {
			return self::$sales_timestamp_cache[ $cache_key ];
		}

		if ( ! self::has_order_lookup_table() ) {
			self::$sales_timestamp_cache[ $cache_key ] = null;
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';

		if ( $variation_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT MAX(date_created) FROM {$table} WHERE product_id = %d AND variation_id = %d",
				$product_id,
				$variation_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT MAX(date_created) FROM {$table} WHERE product_id = %d",
				$product_id
			);
		}

		$date_str  = $wpdb->get_var( $sql );
		$timestamp = ( $date_str && '0000-00-00 00:00:00' !== $date_str ) ? strtotime( $date_str ) : null;

		self::$sales_timestamp_cache[ $cache_key ] = $timestamp;
		return $timestamp;
	}

	/**
	 * Check whether a product or variation is considered "dead stock".
	 *
	 * A product is dead stock if:
	 * 1. It is currently out of stock.
	 * 2. AND either its last recorded sale is older than $threshold_days.
	 * 3. OR it has never been sold and was created more than $threshold_days ago.
	 *
	 * @param \WC_Product      $product        Product or variation instance.
	 * @param \WC_Product|null $parent         Parent product if current product is a variation.
	 * @param int              $threshold_days Number of days threshold.
	 * @return bool True if dead stock, false otherwise.
	 */
	public static function is_dead_stock( $product, $parent = null, $threshold_days = 0 ) {
		if ( ! $product || ! is_a( $product, '\WC_Product' ) ) {
			return false;
		}

		// In-stock products are NEVER dead stock.
		if ( $product->is_in_stock() ) {
			return false;
		}

		$threshold_days = (int) $threshold_days;
		if ( $threshold_days <= 0 ) {
			return false;
		}

		$cutoff_timestamp = current_time( 'timestamp' ) - ( $threshold_days * DAY_IN_SECONDS );

		$is_variation = ( null !== $parent && is_a( $parent, '\WC_Product' ) );
		$product_id   = $is_variation ? $parent->get_id() : $product->get_id();
		$variation_id = $is_variation ? $product->get_id() : 0;

		$last_sale = self::get_last_sale_timestamp( $product_id, $variation_id );

		if ( null !== $last_sale && $last_sale > 0 ) {
			// Product has sales history. Check if last sale was before cutoff.
			return ( $last_sale < $cutoff_timestamp );
		}

		// No sales history found: check product creation date.
		$date_created = $product->get_date_created();
		if ( ! $date_created && $is_variation && $parent ) {
			$date_created = $parent->get_date_created();
		}

		if ( $date_created ) {
			return ( $date_created->getTimestamp() < $cutoff_timestamp );
		}

		// If no WC date object, default to post_date via get_post if available.
		$post = get_post( $product->get_id() );
		if ( $post && ! empty( $post->post_date_gmt ) && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
			return ( strtotime( $post->post_date_gmt ) < $cutoff_timestamp );
		}

		return false;
	}

	/**
	 * Reset sales timestamp, bestseller, trending and new products cache.
	 */
	public static function reset_sales_cache() {
		self::$sales_timestamp_cache = array();
		self::$bestseller_ids_cache   = null;
		self::$trending_ids_cache     = null;
		self::$new_product_ids_cache  = null;
	}

	/**
	 * Sanitize an individual label for Meta internal_label array.
	 * Strips quotes, apostrophes, commas, brackets, slashes and control characters.
	 *
	 * @param string $text Raw label.
	 * @return string Sanitized label, max 110 characters.
	 */
	public static function clean_internal_label( $text ) {
		if ( empty( $text ) ) {
			return '';
		}

		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Replace ampersand with word 'et' for cleaner semantic naming in French stores.
		$text = str_replace( '&', 'et', $text );

		// Remove quotes, apostrophes, commas, brackets, braces, and backslashes for clean XML tag value.
		$text = str_replace( array( "'", '"', '`', '’', '‘', '[', ']', '{', '}', ',', '\\' ), ' ', $text );

		// Remove non-printable control characters.
		$text = preg_replace( '/[\x00-\x1F\x7F]/u', '', $text );

		// Collapse multiple spaces.
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		// Meta limit: 110 characters maximum per label.
		if ( mb_strlen( $text, 'UTF-8' ) > 110 ) {
			$text = mb_substr( $text, 0, 110, 'UTF-8' );
			$text = trim( $text );
		}

		return $text;
	}

	/**
	 * Retrieve top newest in-stock product IDs mapped as [ ID => true ].
	 *
	 * @param array $options Plugin settings.
	 * @return array<int, bool> Map of new product IDs.
	 */
	public static function get_new_product_ids( $options = array() ) {
		if ( null !== self::$new_product_ids_cache ) {
			return self::$new_product_ids_cache;
		}

		global $wpdb;
		$limit = ! empty( $options['label_new_count'] ) ? max( 1, (int) $options['label_new_count'] ) : 50;
		$days  = ! empty( $options['label_new_days'] ) ? max( 1, (int) $options['label_new_days'] ) : 30;

		$cutoff_date = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$has_lookup   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) === $lookup_table );

		if ( $has_lookup ) {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$lookup_table} meta ON p.ID = meta.product_id
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND p.post_date_gmt >= %s
				   AND meta.stock_status = 'instock'
				 ORDER BY p.post_date_gmt DESC
				 LIMIT %d",
				$cutoff_date,
				$limit
			);
			$ids = $wpdb->get_col( $sql );
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND p.post_date_gmt >= %s
				   AND pm_stock.meta_value = 'instock'
				 ORDER BY p.post_date_gmt DESC
				 LIMIT %d",
				$cutoff_date,
				$limit
			);
			$ids = $wpdb->get_col( $sql );
		}

		if ( empty( $ids ) ) {
			self::$new_product_ids_cache = array();
		} else {
			self::$new_product_ids_cache = array_fill_keys( array_map( 'intval', $ids ), true );
		}

		return self::$new_product_ids_cache;
	}

	/**
	 * Check if a product or its parent is identified as a new product in stock.
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @param array|int        $options Plugin settings array, or legacy int $days.
	 * @return bool True if identified as new product in stock.
	 */
	public static function is_new_product( $product, $parent = null, $options = array() ) {
		if ( ! $product || ! is_a( $product, '\WC_Product' ) || ! $product->is_in_stock() ) {
			return false;
		}

		// Backward compatibility if integer $days was passed.
		if ( is_int( $options ) || is_numeric( $options ) ) {
			$options = array( 'label_new_days' => (int) $options );
		}

		$new_map = self::get_new_product_ids( $options );
		if ( empty( $new_map ) ) {
			return false;
		}

		$pid = (int) $product->get_id();
		if ( isset( $new_map[ $pid ] ) ) {
			return true;
		}

		if ( $parent ) {
			$parent_id = (int) $parent->get_id();
			if ( isset( $new_map[ $parent_id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieve top bestselling in-stock product IDs mapped as [ ID => true ].
	 *
	 * @param array $options Plugin settings.
	 * @return array<int, bool> Map of bestseller IDs.
	 */
	public static function get_bestseller_ids( $options = array() ) {
		if ( null !== self::$bestseller_ids_cache ) {
			return self::$bestseller_ids_cache;
		}

		global $wpdb;
		if ( ! empty( $options['label_bestseller_count'] ) ) {
			$limit = max( 1, (int) $options['label_bestseller_count'] );
		} elseif ( ! empty( $options['label_bestseller_mode'] ) && 'percentage' === $options['label_bestseller_mode'] ) {
			$limit = 100;
		} elseif ( ! empty( $options['label_bestseller_value'] ) ) {
			$limit = max( 1, (int) $options['label_bestseller_value'] );
		} else {
			$limit = 100;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$has_lookup   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) === $lookup_table );

		if ( $has_lookup ) {
			$sql = $wpdb->prepare(
				"SELECT product_id FROM {$lookup_table} 
				 WHERE stock_status = 'instock' AND total_sales > 0 
				 ORDER BY total_sales DESC 
				 LIMIT %d",
				$limit
			);
			$ids = $wpdb->get_col( $sql );
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_sales ON p.ID = pm_sales.post_id AND pm_sales.meta_key = 'total_sales'
				 INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' 
				   AND pm_stock.meta_value = 'instock' 
				   AND CAST(pm_sales.meta_value AS UNSIGNED) > 0
				 ORDER BY CAST(pm_sales.meta_value AS UNSIGNED) DESC 
				 LIMIT %d",
				$limit
			);
			$ids = $wpdb->get_col( $sql );
		}

		if ( empty( $ids ) ) {
			self::$bestseller_ids_cache = array();
		} else {
			self::$bestseller_ids_cache = array_fill_keys( array_map( 'intval', $ids ), true );
		}

		return self::$bestseller_ids_cache;
	}

	/**
	 * Check if a product or its parent is identified as a bestseller.
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @param array            $options Plugin settings.
	 * @return bool
	 */
	public static function is_bestseller( $product, $parent = null, $options = array() ) {
		if ( ! $product || ! is_a( $product, '\WC_Product' ) || ! $product->is_in_stock() ) {
			return false;
		}

		$bestseller_map = self::get_bestseller_ids( $options );
		if ( empty( $bestseller_map ) ) {
			return false;
		}

		$pid = (int) $product->get_id();
		if ( isset( $bestseller_map[ $pid ] ) ) {
			return true;
		}

		if ( $parent ) {
			$parent_id = (int) $parent->get_id();
			if ( isset( $bestseller_map[ $parent_id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get product IDs identified as trending (most sold over the last X days, in stock).
	 *
	 * @param array $options Plugin settings.
	 * @return array<int, bool> Map of trending IDs.
	 */
	public static function get_trending_ids( $options = array() ) {
		if ( null !== self::$trending_ids_cache ) {
			return self::$trending_ids_cache;
		}

		global $wpdb;
		$limit = ! empty( $options['label_trending_count'] ) ? max( 1, (int) $options['label_trending_count'] ) : 35;
		$days  = ! empty( $options['label_trending_days'] ) ? max( 1, (int) $options['label_trending_days'] ) : 45;

		$cutoff_date = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$has_lookup   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) === $lookup_table );

		$ids = array();

		if ( self::has_order_lookup_table() ) {
			$order_table = $wpdb->prefix . 'wc_order_product_lookup';
			$stats_table = $wpdb->prefix . 'wc_order_stats';
			$has_stats   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats_table ) ) === $stats_table );

			if ( $has_lookup ) {
				if ( $has_stats ) {
					$sql = $wpdb->prepare(
						"SELECT o.product_id
						 FROM {$order_table} o
						 INNER JOIN {$stats_table} s ON o.order_id = s.order_id
						 INNER JOIN {$lookup_table} p ON o.product_id = p.product_id
						 WHERE o.date_created >= %s
						   AND s.status IN ('wc-completed', 'wc-processing')
						   AND p.stock_status = 'instock'
						 GROUP BY o.product_id
						 HAVING SUM(o.product_qty) > 0
						 ORDER BY SUM(o.product_qty) DESC
						 LIMIT %d",
						$cutoff_date,
						$limit
					);
				} else {
					$sql = $wpdb->prepare(
						"SELECT o.product_id
						 FROM {$order_table} o
						 INNER JOIN {$lookup_table} p ON o.product_id = p.product_id
						 WHERE o.date_created >= %s
						   AND p.stock_status = 'instock'
						 GROUP BY o.product_id
						 HAVING SUM(o.product_qty) > 0
						 ORDER BY SUM(o.product_qty) DESC
						 LIMIT %d",
						$cutoff_date,
						$limit
					);
				}
				$ids = $wpdb->get_col( $sql );
			} else {
				$sql = $wpdb->prepare(
					"SELECT o.product_id
					 FROM {$order_table} o
					 INNER JOIN {$wpdb->postmeta} pm_stock ON o.product_id = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
					 WHERE o.date_created >= %s
					   AND pm_stock.meta_value = 'instock'
					 GROUP BY o.product_id
					 HAVING SUM(o.product_qty) > 0
					 ORDER BY SUM(o.product_qty) DESC
					 LIMIT %d",
					$cutoff_date,
					$limit
				);
				$ids = $wpdb->get_col( $sql );
			}
		} else {
			// Fallback: standard WooCommerce order items tables.
			$order_items     = $wpdb->prefix . 'woocommerce_order_items';
			$order_item_meta = $wpdb->prefix . 'woocommerce_order_itemmeta';

			if ( $has_lookup ) {
				$sql = $wpdb->prepare(
					"SELECT CAST(p_meta.meta_value AS UNSIGNED) AS product_id
					 FROM {$order_items} oi
					 INNER JOIN {$order_item_meta} p_meta ON oi.order_item_id = p_meta.order_item_id AND p_meta.meta_key = '_product_id'
					 INNER JOIN {$order_item_meta} q_meta ON oi.order_item_id = q_meta.order_item_id AND q_meta.meta_key = '_qty'
					 INNER JOIN {$lookup_table} p ON CAST(p_meta.meta_value AS UNSIGNED) = p.product_id
					 INNER JOIN {$wpdb->posts} orders ON oi.order_id = orders.ID
					 WHERE orders.post_type = 'shop_order'
					   AND orders.post_status IN ('wc-completed', 'wc-processing')
					   AND orders.post_date_gmt >= %s
					   AND p.stock_status = 'instock'
					 GROUP BY product_id
					 HAVING SUM(CAST(q_meta.meta_value AS SIGNED)) > 0
					 ORDER BY SUM(CAST(q_meta.meta_value AS SIGNED)) DESC
					 LIMIT %d",
					$cutoff_date,
					$limit
				);
				$ids = $wpdb->get_col( $sql );
			}
		}

		if ( empty( $ids ) ) {
			self::$trending_ids_cache = array();
		} else {
			self::$trending_ids_cache = array_fill_keys( array_map( 'intval', $ids ), true );
		}

		return self::$trending_ids_cache;
	}

	/**
	 * Check if a product or its parent is identified as trending.
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @param array            $options Plugin settings.
	 * @return bool
	 */
	public static function is_trending( $product, $parent = null, $options = array() ) {
		if ( ! $product || ! is_a( $product, '\WC_Product' ) || ! $product->is_in_stock() ) {
			return false;
		}

		$trending_map = self::get_trending_ids( $options );
		if ( empty( $trending_map ) ) {
			return false;
		}

		$pid = (int) $product->get_id();
		if ( isset( $trending_map[ $pid ] ) ) {
			return true;
		}

		if ( $parent ) {
			$parent_id = (int) $parent->get_id();
			if ( isset( $trending_map[ $parent_id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build Meta-compliant internal_label list for <g:internal_label>.
	 * Outputs individual <g:internal_label> tags per value without bracket/quote array syntax,
	 * conforming to Meta's XML feed parser for Commerce Manager Product Sets.
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @param array            $options Plugin settings.
	 * @return array<string> List of cleaned label strings.
	 */
	public static function get_internal_labels( $product, $parent = null, $options = array() ): array {
		$labels    = array();
		$target    = ( null !== $parent && is_a( $parent, '\WC_Product' ) ) ? $parent : $product;
		$target_id = $target->get_id();

		// 1. Categories (product_cat).
		if ( ! empty( $options['label_include_categories'] ) ) {
			$cats = get_the_terms( $target_id, 'product_cat' );
			if ( $cats && ! is_wp_error( $cats ) ) {
				foreach ( $cats as $cat ) {
					$labels[] = $cat->name;
				}
			}
		}

		// 2. Product Tags (product_tag).
		if ( ! empty( $options['label_include_tags'] ) ) {
			$tags = get_the_terms( $target_id, 'product_tag' );
			if ( $tags && ! is_wp_error( $tags ) ) {
				foreach ( $tags as $tag ) {
					$labels[] = $tag->name;
				}
			}
		}

		// 3. On-sale / Promo flag.
		if ( ! empty( $options['label_enable_promo'] ) ) {
			$is_on_sale = $product->is_on_sale() || ( $parent && $parent->is_on_sale() );
			if ( $is_on_sale ) {
				$promo_tag = ! empty( $options['label_promo_tag'] ) ? $options['label_promo_tag'] : 'promo';
				$labels[]  = $promo_tag;
			}
		}

		// 4. New product flag.
		if ( ! empty( $options['label_enable_new'] ) ) {
			if ( self::is_new_product( $product, $parent, $options ) ) {
				$new_tag  = ! empty( $options['label_new_tag'] ) ? $options['label_new_tag'] : 'nouveaute';
				$labels[] = $new_tag;
			}
		}

		// 5. Bestseller flag.
		if ( ! empty( $options['label_enable_bestseller'] ) ) {
			if ( self::is_bestseller( $product, $parent, $options ) ) {
				$bestseller_tag = ! empty( $options['label_bestseller_tag'] ) ? $options['label_bestseller_tag'] : 'bestseller';
				$labels[]       = $bestseller_tag;
			}
		}

		// 6. Trending flag (most sold in last X days, in stock).
		if ( ! empty( $options['label_enable_trending'] ) ) {
			if ( self::is_trending( $product, $parent, $options ) ) {
				$trending_tag = ! empty( $options['label_trending_tag'] ) ? $options['label_trending_tag'] : 'tendance';
				$labels[]     = $trending_tag;
			}
		}

		/**
		 * Filters the raw internal labels list before sanitization.
		 *
		 * @param array            $labels  Raw list of labels.
		 * @param \WC_Product      $product Current product or variation.
		 * @param \WC_Product|null $parent  Parent product if variation.
		 * @param array            $options Plugin settings.
		 */
		$labels = apply_filters( 'woo_meta_catalog_internal_labels', $labels, $product, $parent, $options );

		if ( empty( $labels ) ) {
			return array();
		}

		if ( is_string( $labels ) ) {
			$labels = array( $labels );
		}

		if ( ! is_array( $labels ) ) {
			return array();
		}

		$clean_labels = array();
		foreach ( $labels as $raw_label ) {
			$clean = self::clean_internal_label( (string) $raw_label );
			if ( '' !== $clean ) {
				$clean_labels[] = $clean;
			}
		}

		return array_values( array_unique( $clean_labels ) );
	}

	/**
	 * Backward compatibility wrapper for get_internal_labels().
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @param array            $options Plugin settings.
	 * @return array<string> List of cleaned label strings.
	 */
	public static function build_internal_labels( $product, $parent = null, $options = array() ): array {
		return self::get_internal_labels( $product, $parent, $options );
	}

	/**
	 * Format and optionally offload an image URL to a CDN (e.g. ImageKit.io or Custom Pull CDN).
	 * Supports auto-squaring 1:1 padding, JPEG normalization, and cache busting.
	 *
	 * @param string $image_url Raw image URL from WordPress.
	 * @param array  $options   Plugin settings.
	 * @return string Formatted/CDN-offloaded image URL.
	 */
	public static function format_image_url( $image_url, $options = array() ) {
		if ( empty( $image_url ) ) {
			return '';
		}

		$image_url = set_url_scheme( $image_url, 'https' );

		// 1. Check if CDN Offloading is enabled.
		$is_cdn_enabled = ! empty( $options['enable_image_cdn'] ) && ! empty( $options['image_cdn_endpoint'] );
		$provider       = ! empty( $options['image_cdn_provider'] ) ? $options['image_cdn_provider'] : 'imagekit';
		$cdn_endpoint   = $is_cdn_enabled ? untrailingslashit( trim( $options['image_cdn_endpoint'] ) ) : '';

		if ( $is_cdn_enabled && ! empty( $cdn_endpoint ) ) {
			if ( 0 !== strpos( $cdn_endpoint, 'http://' ) && 0 !== strpos( $cdn_endpoint, 'https://' ) ) {
				$cdn_endpoint = 'https://' . $cdn_endpoint;
			}
			$cdn_endpoint = set_url_scheme( $cdn_endpoint, 'https' );

			// Extract site base URL.
			$site_url = untrailingslashit( set_url_scheme( home_url(), 'https' ) );

			// Replace WordPress origin domain with CDN endpoint.
			if ( 0 === strpos( $image_url, $site_url ) ) {
				$image_url = $cdn_endpoint . substr( $image_url, strlen( $site_url ) );
			}

			// Provider-specific transformations (ImageKit.io).
			if ( 'imagekit' === $provider ) {
				$tr_parts = array();

				if ( ! empty( $options['image_cdn_auto_square'] ) ) {
					$tr_parts[] = 'w-1024';
					$tr_parts[] = 'h-1024';
					$tr_parts[] = 'cm-pad_resize';
					$tr_parts[] = 'bg-FFFFFF';
				}

				if ( ! empty( $options['image_cdn_force_jpeg'] ) ) {
					$tr_parts[] = 'f-jpg';
				}

				if ( ! empty( $tr_parts ) ) {
					$image_url = add_query_arg( 'tr', implode( ',', $tr_parts ), $image_url );
				}
			}
		}

		// 2. Cache busting version parameter (?v=...).
		if ( ! empty( $options['image_version'] ) ) {
			$image_url = add_query_arg( 'v', rawurlencode( (string) $options['image_version'] ), $image_url );
		}

		return $image_url;
	}

	/**
	 * Escape standard XML attributes / values.
	 *
	 * @param string $text Raw string.
	 * @return string Escaped XML.
	 */
	public static function escape_xml( $text ) {
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
