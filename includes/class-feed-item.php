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

		$is_variation = ( null !== $parent && is_a( $parent, '\WC_Product' ) );

		// 1. Stock & Availability check.
		$is_in_stock  = $product->is_in_stock();
		$availability = $is_in_stock ? 'in stock' : 'out of stock';

		if ( ! empty( $options['exclude_out_of_stock'] ) && ! $is_in_stock ) {
			return null;
		}

		// 2. Critical CAPI ID alignment:
		// Logic strictly matches woo-fb-tracking-server-side:
		// $id = (string) ( $product->get_sku() ? $product->get_sku() : $product->get_id() );
		$id = (string) ( $product->get_sku() ? $product->get_sku() : $product->get_id() );

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

		// 7. Prices & Currency.
		$currency      = get_woocommerce_currency();
		$regular_price = $product->get_regular_price();
		$sale_price    = $product->get_sale_price();

		// If regular price is missing, fallback to current price.
		if ( '' === $regular_price || null === $regular_price ) {
			$regular_price = $product->get_price();
		}

		if ( '' === $regular_price || null === $regular_price ) {
			$regular_price = 0;
		}

		$formatted_price = number_format( (float) $regular_price, 2, '.', '' ) . ' ' . $currency;

		$formatted_sale_price = null;
		if ( $product->is_on_sale() && '' !== $sale_price && null !== $sale_price ) {
			$sale_val = (float) $sale_price;
			if ( $sale_val < (float) $regular_price ) {
				$formatted_sale_price = number_format( $sale_val, 2, '.', '' ) . ' ' . $currency;
			}
		}

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
	 * Escape standard XML attributes / values.
	 *
	 * @param string $text Raw string.
	 * @return string Escaped XML.
	 */
	public static function escape_xml( $text ) {
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
