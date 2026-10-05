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
		$id = self::get_content_id( $product );

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
			$image_url = set_url_scheme( $image_url, 'https' );
		}

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
					$additional_images[] = set_url_scheme( $gal_url, 'https' );
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
		$internal_labels = self::build_internal_labels( $product, $parent, $options );
		if ( ! empty( $internal_labels ) ) {
			$xml .= "\t\t\t<g:internal_label><![CDATA[" . self::sanitize_cdata( $internal_labels ) . "]]></g:internal_label>\n";
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
	 * - Simple / external products: SKU if defined, otherwise Post ID.
	 * - Variations: OWN SKU only (`get_sku( 'edit' )`, no inheritance from the parent SKU),
	 *   otherwise the variation ID. Prevents several variations sharing the parent SKU
	 *   as <g:id> (duplicates rejected by Meta).
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return string
	 */
	public static function get_content_id( \WC_Product $product ): string {
		$sku = $product->is_type( 'variation' ) ? $product->get_sku( 'edit' ) : $product->get_sku();
		$id  = ( '' !== (string) $sku ) ? (string) $sku : (string) $product->get_id();

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
	 * Reset sales timestamp and bestseller cache.
	 */
	public static function reset_sales_cache() {
		self::$sales_timestamp_cache = array();
		self::$bestseller_ids_cache   = null;
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

		// Remove quotes, apostrophes, commas, brackets, braces, and backslashes to avoid breaking Meta array syntax ['val1','val2'].
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
	 * Check if a product or variation was created recently.
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @param int              $days    Threshold in days.
	 * @return bool True if created within the threshold.
	 */
	public static function is_new_product( $product, $parent = null, $days = 30 ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return false;
		}

		$cutoff = current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS );
		$target = ( null !== $parent && is_a( $parent, '\WC_Product' ) ) ? $parent : $product;

		$date_created = $target->get_date_created();
		if ( $date_created ) {
			return ( $date_created->getTimestamp() >= $cutoff );
		}

		$post = get_post( $target->get_id() );
		if ( $post && ! empty( $post->post_date_gmt ) && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
			return ( strtotime( $post->post_date_gmt ) >= $cutoff );
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
		$mode  = ! empty( $options['label_bestseller_mode'] ) ? $options['label_bestseller_mode'] : 'count';
		$value = isset( $options['label_bestseller_value'] ) ? (float) $options['label_bestseller_value'] : 50;

		if ( $value <= 0 ) {
			self::$bestseller_ids_cache = array();
			return self::$bestseller_ids_cache;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$has_lookup   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) === $lookup_table );

		$limit = 50;

		if ( 'percentage' === $mode ) {
			$percent = min( 100, max( 1, $value ) );
			if ( $has_lookup ) {
				$total_instock = (int) $wpdb->get_var(
					"SELECT COUNT(DISTINCT product_id) FROM {$lookup_table} WHERE stock_status = 'instock'"
				);
			} else {
				$total_instock = (int) $wpdb->get_var(
					"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status'
					 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_value = 'instock'"
				);
			}

			if ( $total_instock <= 0 ) {
				self::$bestseller_ids_cache = array();
				return self::$bestseller_ids_cache;
			}

			$limit = max( 1, (int) round( ( $percent / 100 ) * $total_instock ) );
		} else {
			$limit = max( 1, (int) $value );
		}

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
	 * Build Meta-compliant internal_label string for <g:internal_label>.
	 * Syntax: ['label1','label2','label3']
	 *
	 * @param \WC_Product      $product Current product or variation.
	 * @param \WC_Product|null $parent  Parent product if variation.
	 * @param array            $options Plugin settings.
	 * @return string|null Formatted string or null if empty.
	 */
	public static function build_internal_labels( $product, $parent = null, $options = array() ) {
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
			$days = ! empty( $options['label_new_days'] ) ? (int) $options['label_new_days'] : 30;
			if ( $days > 0 && self::is_new_product( $product, $parent, $days ) ) {
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

		/**
		 * Filters the raw internal labels list before sanitization.
		 *
		 * @param array            $labels  Raw list of labels.
		 * @param \WC_Product      $product Current product or variation.
		 * @param \WC_Product|null $parent  Parent product if variation.
		 * @param array            $options Plugin settings.
		 */
		$labels = apply_filters( 'woo_meta_catalog_internal_labels', $labels, $product, $parent, $options );

		if ( empty( $labels ) || ! is_array( $labels ) ) {
			return null;
		}

		$clean_labels = array();
		foreach ( $labels as $raw_label ) {
			$clean = self::clean_internal_label( (string) $raw_label );
			if ( '' !== $clean ) {
				$clean_labels[] = $clean;
			}
		}

		$clean_labels = array_values( array_unique( $clean_labels ) );

		if ( empty( $clean_labels ) ) {
			return null;
		}

		return "['" . implode( "','", $clean_labels ) . "']";
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
