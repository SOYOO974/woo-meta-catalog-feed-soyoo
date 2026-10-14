<?php
/**
 * Strategic Product Sets Image Risk Analyzer, Auto-Remediator & Daily Email Alert Engine.
 *
 * Audits products carrying Meta internal labels (bestseller, promo, nouveaute, tendance)
 * against Meta Commerce Manager image specifications:
 * - Format: JPEG or PNG (or WebP/AVIF normalized via CDN f-jpg)
 * - File size: < 8 MB and > 0 bytes (existing on disk)
 * - Minimum dimensions: >= 500 x 500 pixels (or auto-squared 1024x1024 via CDN)
 * - Valid HTTP/HTTPS accessible URL with proper RFC-3986 path encoding
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_Image_Risk
 */
class Feed_Image_Risk {

	const REPORT_OPTION     = 'woo_meta_catalog_image_risk_report';
	const LAST_EMAIL_OPTION = 'woo_meta_catalog_image_risk_last_email';
	const MAX_FILE_SIZE     = 8388608; // 8 MB in bytes.
	const MIN_DIMENSION     = 500;     // 500 px minimum width & height required by Meta.
	const MAX_REPORT_ITEMS  = 150;     // Max distinct products stored in option/email.

	/**
	 * Static cache for attachment inspections during a request/chunk.
	 *
	 * @var array<string, array>
	 */
	protected static $attachment_cache = array();

	/**
	 * Static cache for attachment filemtime during a request/chunk.
	 *
	 * @var array<int, int>
	 */
	protected static $mtime_cache = array();

	/**
	 * Runtime buffer collected during a chunk execution.
	 *
	 * @var array
	 */
	protected static $runtime_buffer = array(
		'checked_count'         => 0,
		'auto_healed_count'     => 0,
		'labels_stripped_count' => 0,
		'risks_total'           => 0,
		'risks'                 => array(),
	);

	/**
	 * Reset the runtime buffer before processing a chunk or standalone scan.
	 *
	 * @return void
	 */
	public static function reset_runtime_buffer() {
		self::$runtime_buffer = array(
			'checked_count'         => 0,
			'auto_healed_count'     => 0,
			'labels_stripped_count' => 0,
			'risks_total'           => 0,
			'risks'                 => array(),
		);
	}

	/**
	 * Get current runtime buffer.
	 *
	 * @return array
	 */
	public static function get_runtime_buffer(): array {
		return self::$runtime_buffer;
	}

	/**
	 * Return the map of strategic internal labels configured in plugin settings.
	 *
	 * @param array $options          Plugin settings.
	 * @param bool  $include_disabled Whether to include tags even if their checkbox is currently unchecked.
	 * @return array<string, string> Map of [ key => cleaned_tag_name ].
	 */
	public static function get_strategic_label_map( array $options = array(), bool $include_disabled = false ): array {
		if ( empty( $options ) ) {
			$options = get_option( 'woo_meta_catalog_settings', array() );
		}

		$map = array();

		if ( $include_disabled || ! empty( $options['label_enable_promo'] ) ) {
			$raw = ! empty( $options['label_promo_tag'] ) ? $options['label_promo_tag'] : 'promo';
			$cln = Feed_Item::clean_internal_label( $raw );
			if ( '' !== $cln ) {
				$map['promo'] = $cln;
			}
		}

		if ( $include_disabled || ! empty( $options['label_enable_new'] ) ) {
			$raw = ! empty( $options['label_new_tag'] ) ? $options['label_new_tag'] : 'nouveaute';
			$cln = Feed_Item::clean_internal_label( $raw );
			if ( '' !== $cln ) {
				$map['nouveaute'] = $cln;
			}
		}

		if ( $include_disabled || ! empty( $options['label_enable_bestseller'] ) ) {
			$raw = ! empty( $options['label_bestseller_tag'] ) ? $options['label_bestseller_tag'] : 'bestseller';
			$cln = Feed_Item::clean_internal_label( $raw );
			if ( '' !== $cln ) {
				$map['bestseller'] = $cln;
			}
		}

		if ( $include_disabled || ! empty( $options['label_enable_trending'] ) ) {
			$raw = ! empty( $options['label_trending_tag'] ) ? $options['label_trending_tag'] : 'tendance';
			$cln = Feed_Item::clean_internal_label( $raw );
			if ( '' !== $cln ) {
				$map['tendance'] = $cln;
			}
		}

		return $map;
	}

	/**
	 * Extract strategic labels (promo, nouveaute, bestseller, tendance) present in a product's internal_labels list.
	 *
	 * @param array $internal_labels Cleaned internal labels assigned to the product.
	 * @param array $options         Plugin settings.
	 * @return array<string> List of matched strategic label strings.
	 */
	public static function extract_strategic_labels( array $internal_labels, array $options = array() ): array {
		if ( empty( $internal_labels ) ) {
			return array();
		}

		$strategic_map = self::get_strategic_label_map( $options, false );
		if ( empty( $strategic_map ) ) {
			return array();
		}

		$strategic_values = array_values( $strategic_map );
		return array_values( array_intersect( $internal_labels, $strategic_values ) );
	}

	/**
	 * Get cached modification timestamp for an attachment ID (used for automatic per-image cache busting).
	 *
	 * @param int $image_id Attachment ID.
	 * @return int Timestamp or 0.
	 */
	public static function get_attachment_version_token( int $image_id ): int {
		if ( $image_id <= 0 ) {
			return 0;
		}

		if ( isset( self::$mtime_cache[ $image_id ] ) ) {
			return self::$mtime_cache[ $image_id ];
		}

		$mtime = 0;
		$file  = get_attached_file( $image_id );
		if ( $file && file_exists( $file ) ) {
			$mtime = (int) @filemtime( $file );
		}

		if ( $mtime <= 0 ) {
			$post = get_post( $image_id );
			if ( $post && ! empty( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
				$mtime = (int) strtotime( $post->post_modified_gmt );
			}
		}

		self::$mtime_cache[ $image_id ] = $mtime;
		return $mtime;
	}

	/**
	 * Inspect an attachment image locally against Meta Commerce Manager rules.
	 *
	 * Rules verified:
	 * 1. Valid image ID & HTTP/HTTPS URL
	 * 2. Physical file exists on disk and > 0 bytes
	 * 3. File size < 8 MB (8,388,608 bytes)
	 * 4. Dimensions >= 500 x 500 px (unless mitigated by active ImageKit 1024x1024 auto-square)
	 * 5. Format is JPEG or PNG (or WebP/AVIF mitigated by active ImageKit f-jpg; flags unmitigated WebP/SVG/AVIF/GIF/BMP)
	 *
	 * @param int    $image_id Attachment ID.
	 * @param string $raw_url  Raw attachment URL from WordPress.
	 * @param array  $options  Plugin settings.
	 * @return array Structured inspection result.
	 */
	public static function inspect_attachment( int $image_id, string $raw_url, array $options = array() ): array {
		$is_cdn_enabled = ! empty( $options['enable_image_cdn'] ) && ! empty( $options['image_cdn_endpoint'] );
		$cdn_provider   = ! empty( $options['image_cdn_provider'] ) ? $options['image_cdn_provider'] : 'imagekit';
		$cdn_square     = $is_cdn_enabled && 'imagekit' === $cdn_provider && ! empty( $options['image_cdn_auto_square'] );
		$cdn_jpeg       = $is_cdn_enabled && 'imagekit' === $cdn_provider && ! empty( $options['image_cdn_force_jpeg'] );

		$cache_key = $image_id . '|' . ( $cdn_square ? '1' : '0' ) . '|' . ( $cdn_jpeg ? '1' : '0' );
		if ( $image_id > 0 && isset( self::$attachment_cache[ $cache_key ] ) ) {
			return self::$attachment_cache[ $cache_key ];
		}

		$result = array(
			'image_id'       => $image_id,
			'raw_url'        => $raw_url,
			'status'         => 'ok', // 'ok', 'warning', 'critical'
			'issues'         => array(),
			'issue_codes'    => array(),
			'width'          => 0,
			'height'         => 0,
			'filesize'       => 0,
			'filesize_human' => '-',
			'format'         => '-',
			'cdn_mitigated'  => false,
		);

		// 1. Check missing image or empty URL.
		if ( $image_id <= 0 || empty( $raw_url ) ) {
			$result['status']        = 'critical';
			$result['issue_codes'][] = 'no_image';
			$result['issues'][]      = __( 'Aucune image principale définie sur le produit', 'woo-meta-catalog' );
			return $result;
		}

		// 2. Check URL scheme (must start with http:// or https://).
		if ( 0 !== strpos( $raw_url, 'http://' ) && 0 !== strpos( $raw_url, 'https://' ) ) {
			$result['status']        = 'critical';
			$result['issue_codes'][] = 'invalid_url';
			$result['issues'][]      = __( 'Le lien de l\'image ne commence pas par http:// ou https://', 'woo-meta-catalog' );
		}

		// 3. Check physical file on server disk.
		$file_path = get_attached_file( $image_id );
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			$result['status']        = 'critical';
			$result['issue_codes'][] = 'missing_file';
			$result['issues'][]      = __( 'Fichier image introuvable sur le disque du serveur (lien brisé 404)', 'woo-meta-catalog' );
			self::$attachment_cache[ $cache_key ] = $result;
			return $result;
		}

		$filesize = (int) @filesize( $file_path );
		$result['filesize']       = $filesize;
		$result['filesize_human'] = size_format( $filesize, 2 );

		if ( $filesize <= 0 ) {
			$result['status']        = 'critical';
			$result['issue_codes'][] = 'empty_file';
			$result['issues'][]      = __( 'Fichier image corrompu ou vide (0 octet)', 'woo-meta-catalog' );
			self::$attachment_cache[ $cache_key ] = $result;
			return $result;
		}

		// 4. Check file size > 8 MB limit.
		if ( $filesize > self::MAX_FILE_SIZE ) {
			$result['status']        = 'critical';
			$result['issue_codes'][] = 'oversized_file';
			$result['issues'][]      = sprintf(
				/* translators: %s: human file size */
				__( 'Poids du fichier (%s) supérieur à la limite maximale Meta de 8 Mo', 'woo-meta-catalog' ),
				$result['filesize_human']
			);
		}

		// 5. Check image format / extension.
		$mime = (string) get_post_mime_type( $image_id );
		$ext  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( empty( $ext ) && ! empty( $mime ) ) {
			$mime_parts = explode( '/', $mime );
			$ext        = strtolower( end( $mime_parts ) );
		}

		$format_label = strtoupper( $ext ?: 'INCONNU' );
		if ( 'JPG' === $format_label ) {
			$format_label = 'JPEG';
		}
		$result['format'] = $format_label;

		$strict_meta_formats = array( 'jpg', 'jpeg', 'png' );
		if ( ! in_array( $ext, $strict_meta_formats, true ) ) {
			if ( in_array( $ext, array( 'svg', 'bmp', 'tiff', 'tif', 'ico' ), true ) ) {
				// Even with CDN, SVG or BMP often fail or produce invalid catalog thumbnails.
				if ( 'svg' === $ext || ! $cdn_jpeg ) {
					$result['status']        = 'critical';
					$result['issue_codes'][] = 'unsupported_format';
					$result['issues'][]      = sprintf(
						/* translators: %s: image format */
						__( 'Format %s non supporté par Meta (JPEG ou PNG requis)', 'woo-meta-catalog' ),
						$format_label
					);
				} else {
					$result['cdn_mitigated'] = true;
				}
			} elseif ( in_array( $ext, array( 'avif', 'gif' ), true ) ) {
				if ( $cdn_jpeg ) {
					$result['cdn_mitigated'] = true;
				} else {
					$result['status']        = 'critical';
					$result['issue_codes'][] = 'unsupported_format';
					$result['issues'][]      = sprintf(
						/* translators: %s: image format */
						__( 'Format %s rejeté sans conversion JPEG CDN (JPEG ou PNG requis)', 'woo-meta-catalog' ),
						$format_label
					);
				}
			} elseif ( 'webp' === $ext ) {
				if ( $cdn_jpeg ) {
					$result['cdn_mitigated'] = true;
				} elseif ( ! empty( $options['image_risk_flag_webp'] ) ) {
					if ( 'critical' !== $result['status'] ) {
						$result['status'] = 'warning';
					}
					$result['issue_codes'][] = 'webp_unconverted';
					$result['issues'][]      = __( 'Format WebP servi sans conversion JPEG CDN (exigence officielle Meta : JPEG ou PNG)', 'woo-meta-catalog' );
				}
			}
		}

		// 6. Check dimensions (must be >= 500 x 500 px).
		$meta   = wp_get_attachment_metadata( $image_id );
		$width  = ! empty( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = ! empty( $meta['height'] ) ? (int) $meta['height'] : 0;

		if ( ( $width <= 0 || $height <= 0 ) && function_exists( 'getimagesize' ) ) {
			$dims = @getimagesize( $file_path );
			if ( is_array( $dims ) && ! empty( $dims[0] ) && ! empty( $dims[1] ) ) {
				$width  = (int) $dims[0];
				$height = (int) $dims[1];
			}
		}

		$result['width']  = $width;
		$result['height'] = $height;

		if ( $width > 0 && $height > 0 && ( $width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION ) ) {
			if ( $cdn_square ) {
				// ImageKit w-1024,h-1024,cm-pad_resize pads/upscales to 1024x1024 automatically.
				$result['cdn_mitigated'] = true;
				// Still warn if the source image is tiny (< 250px) and looks blurry in ads.
				if ( $width < 250 || $height < 250 ) {
					if ( 'critical' !== $result['status'] ) {
						$result['status'] = 'warning';
					}
					$result['issue_codes'][] = 'tiny_source_dimensions';
					$result['issues'][]      = sprintf(
						/* translators: 1: width, 2: height */
						__( 'Résolution source très faible (%1$d×%2$d px < 500×500 px, agrandie en 1024×1024 par le CDN mais risque de flou)', 'woo-meta-catalog' ),
						$width,
						$height
					);
				}
			} else {
				$result['status']        = 'critical';
				$result['issue_codes'][] = 'small_dimensions';
				$result['issues'][]      = sprintf(
					/* translators: 1: width, 2: height */
					__( 'Dimensions %1$d×%2$d px inférieures au minimum Meta de 500×500 pixels', 'woo-meta-catalog' ),
					$width,
					$height
				);
			}
		}

		self::$attachment_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Search product gallery for the first 100% compliant image that can replace a faulty primary image.
	 *
	 * @param array $gallery_ids      List of gallery attachment IDs.
	 * @param int   $exclude_image_id Primary attachment ID to skip.
	 * @param array $options          Plugin settings.
	 * @return array|null Array with ['image_id', 'raw_url', 'inspection'] or null if none found.
	 */
	public static function find_compliant_gallery_image( array $gallery_ids, int $exclude_image_id, array $options = array() ): ?array {
		if ( empty( $gallery_ids ) ) {
			return null;
		}

		foreach ( $gallery_ids as $gal_id ) {
			$gal_id = (int) $gal_id;
			if ( $gal_id <= 0 || $gal_id === $exclude_image_id ) {
				continue;
			}

			$gal_url = (string) wp_get_attachment_image_url( $gal_id, 'full' );
			if ( empty( $gal_url ) ) {
				continue;
			}

			$insp = self::inspect_attachment( $gal_id, $gal_url, $options );
			if ( 'ok' === $insp['status'] ) {
				return array(
					'image_id'   => $gal_id,
					'raw_url'    => $gal_url,
					'inspection' => $insp,
				);
			}
		}

		return null;
	}

	/**
	 * Evaluate image risk for a product carrying strategic internal labels during XML build,
	 * apply optional auto-remediation (gallery fallback or label protection), and record findings.
	 *
	 * @param \WC_Product      $product          Current product or variation.
	 * @param \WC_Product|null $parent           Parent product if variation.
	 * @param int              $image_id         Resolved primary attachment ID (passed by reference).
	 * @param string           $raw_image_url    Resolved raw primary image URL (passed by reference).
	 * @param array            $gallery_ids      Product gallery attachment IDs.
	 * @param array            $strategic_labels Strategic labels assigned to this item.
	 * @param array            $internal_labels  All internal labels assigned to this item (passed by reference).
	 * @param array            $options          Plugin settings.
	 * @return void
	 */
	public static function evaluate_and_remediate(
		\WC_Product $product,
		$parent,
		int &$image_id,
		string &$raw_image_url,
		array $gallery_ids,
		array $strategic_labels,
		array &$internal_labels,
		array $options
	) {
		self::$runtime_buffer['checked_count']++;

		$original_image_id = $image_id;
		$inspection        = self::inspect_attachment( $image_id, $raw_image_url, $options );

		if ( 'ok' === $inspection['status'] ) {
			return;
		}

		$auto_healed      = false;
		$healed_image_id  = 0;
		$healed_dims      = '';
		$labels_stripped  = false;

		// 1. Auto-remediation: try to replace faulty primary image with a compliant gallery image.
		$enable_gallery_fallback = ! isset( $options['image_risk_auto_fallback_gallery'] ) || ! empty( $options['image_risk_auto_fallback_gallery'] );
		if ( $enable_gallery_fallback && ! empty( $gallery_ids ) ) {
			$fallback = self::find_compliant_gallery_image( $gallery_ids, $original_image_id, $options );
			if ( null !== $fallback ) {
				$image_id        = (int) $fallback['image_id'];
				$raw_image_url   = (string) $fallback['raw_url'];
				$auto_healed     = true;
				$healed_image_id = $image_id;
				$healed_dims     = $fallback['inspection']['width'] . '×' . $fallback['inspection']['height'] . ' px';
				self::$runtime_buffer['auto_healed_count']++;
			}
		}

		// 2. Strategic Set Protection: if still critical and not auto-healed, optionally strip strategic labels.
		if ( ! $auto_healed && 'critical' === $inspection['status'] && ! empty( $options['image_risk_protect_sets'] ) ) {
			$internal_labels = array_values( array_diff( $internal_labels, $strategic_labels ) );
			$labels_stripped = true;
			self::$runtime_buffer['labels_stripped_count']++;
		}

		// 3. Record in runtime buffer (deduplicated by parent/product + original image ID).
		$is_variation   = ( null !== $parent && is_a( $parent, '\WC_Product' ) );
		$edit_post_id   = $is_variation ? (int) $parent->get_id() : (int) $product->get_id();
		$display_name   = $is_variation ? $parent->get_name() : $product->get_name();
		$dedup_key      = $edit_post_id . '_' . $original_image_id;

		if ( isset( self::$runtime_buffer['risks'][ $dedup_key ] ) ) {
			self::$runtime_buffer['risks'][ $dedup_key ]['variations_count']++;
			self::$runtime_buffer['risks'][ $dedup_key ]['labels'] = array_values(
				array_unique( array_merge( self::$runtime_buffer['risks'][ $dedup_key ]['labels'], $strategic_labels ) )
			);
			return;
		}

		self::$runtime_buffer['risks_total']++;

		if ( count( self::$runtime_buffer['risks'] ) >= self::MAX_REPORT_ITEMS ) {
			return;
		}

		self::$runtime_buffer['risks'][ $dedup_key ] = array(
			'product_id'       => $edit_post_id,
			'item_id'          => (int) $product->get_id(),
			'content_id'       => Feed_Item::get_content_id( $product, $options['id_format'] ?? null ),
			'name'             => $display_name,
			'edit_url'         => admin_url( 'post.php?post=' . $edit_post_id . '&action=edit' ),
			'permalink'        => get_permalink( $edit_post_id ),
			'image_id'         => $original_image_id,
			'image_url'        => $raw_image_url,
			'thumb_url'        => $original_image_id ? ( wp_get_attachment_image_url( $original_image_id, 'thumbnail' ) ?: $raw_image_url ) : '',
			'status'           => $inspection['status'],
			'issues'           => $inspection['issues'],
			'issue_codes'      => $inspection['issue_codes'],
			'width'            => $inspection['width'],
			'height'           => $inspection['height'],
			'filesize_human'   => $inspection['filesize_human'],
			'format'           => $inspection['format'],
			'labels'           => $strategic_labels,
			'variations_count' => $is_variation ? 1 : 0,
			'auto_healed'      => $auto_healed,
			'healed_image_id'  => $healed_image_id,
			'healed_dims'      => $healed_dims,
			'labels_stripped'  => $labels_stripped,
		);
	}

	/**
	 * Merge current chunk's runtime buffer into the persistent job_state array.
	 *
	 * @param array $job_state Feed generator job state (passed by reference).
	 * @return void
	 */
	public static function merge_chunk_buffer_into_job_state( array &$job_state ) {
		$buf = self::$runtime_buffer;

		$job_state['strategic_items_checked'] = (int) ( $job_state['strategic_items_checked'] ?? 0 ) + (int) $buf['checked_count'];
		$job_state['auto_healed_count']       = (int) ( $job_state['auto_healed_count'] ?? 0 ) + (int) $buf['auto_healed_count'];
		$job_state['labels_stripped_count']   = (int) ( $job_state['labels_stripped_count'] ?? 0 ) + (int) $buf['labels_stripped_count'];

		if ( ! isset( $job_state['image_risks'] ) || ! is_array( $job_state['image_risks'] ) ) {
			$job_state['image_risks'] = array();
		}

		foreach ( $buf['risks'] as $dedup_key => $risk_item ) {
			if ( isset( $job_state['image_risks'][ $dedup_key ] ) ) {
				$job_state['image_risks'][ $dedup_key ]['variations_count'] += (int) $risk_item['variations_count'];
				$job_state['image_risks'][ $dedup_key ]['labels'] = array_values(
					array_unique( array_merge( $job_state['image_risks'][ $dedup_key ]['labels'], $risk_item['labels'] ) )
				);
			} else {
				$job_state['image_risks_total'] = (int) ( $job_state['image_risks_total'] ?? 0 ) + 1;
				if ( count( $job_state['image_risks'] ) < self::MAX_REPORT_ITEMS ) {
					$job_state['image_risks'][ $dedup_key ] = $risk_item;
				}
			}
		}

		self::reset_runtime_buffer();
	}

	/**
	 * Finalize the risk report at the end of XML feed generation, save it, and send daily email alert if needed.
	 *
	 * @param array $job_state Final job state from Feed_Generator.
	 * @param array $options   Plugin settings.
	 * @return array Compiled report.
	 */
	public static function finalize_report_and_maybe_alert( array $job_state, array $options = array() ): array {
		if ( empty( $options ) ) {
			$options = get_option( 'woo_meta_catalog_settings', array() );
		}

		$items           = ! empty( $job_state['image_risks'] ) && is_array( $job_state['image_risks'] ) ? array_values( $job_state['image_risks'] ) : array();
		$total_at_risk   = isset( $job_state['image_risks_total'] ) ? (int) $job_state['image_risks_total'] : count( $items );
		$critical_count  = 0;
		$warning_count   = 0;

		foreach ( $items as $item ) {
			if ( 'critical' === ( $item['status'] ?? '' ) ) {
				$critical_count++;
			} else {
				$warning_count++;
			}
		}

		$is_cdn_enabled = ! empty( $options['enable_image_cdn'] ) && ! empty( $options['image_cdn_endpoint'] );

		$report = array(
			'run_id'                  => $job_state['run_id'] ?? 'scan_' . time(),
			'timestamp'               => time(),
			'date'                    => wp_date( 'd/m/Y à H:i:s' ),
			'strategic_items_checked' => (int) ( $job_state['strategic_items_checked'] ?? 0 ),
			'total_at_risk'           => $total_at_risk,
			'critical_count'          => $critical_count,
			'warning_count'           => $warning_count,
			'auto_healed_count'       => (int) ( $job_state['auto_healed_count'] ?? 0 ),
			'labels_stripped_count'   => (int) ( $job_state['labels_stripped_count'] ?? 0 ),
			'cdn_active'              => $is_cdn_enabled,
			'items'                   => $items,
			'email_sent'              => false,
			'email_status_msg'        => '',
		);

		// Check if automatic email alert is enabled and risks were detected.
		$alert_enabled = ! isset( $options['enable_image_risk_alert'] ) || ! empty( $options['enable_image_risk_alert'] );
		if ( $alert_enabled && $total_at_risk > 0 ) {
			$email_res                  = self::send_alert_email( $report, $options, false );
			$report['email_sent']       = ! empty( $email_res['sent'] );
			$report['email_status_msg'] = $email_res['message'] ?? '';
		}

		update_option( self::REPORT_OPTION, $report, false );

		if ( $total_at_risk > 0 ) {
			Feed_Logger::log(
				sprintf(
					'Audit Images Ensembles Meta : %1$d produit(s) à risque détecté(s) sur %2$d articles étiquetés (%3$d critiques, %4$d auto-corrigés via galerie). %5$s',
					$total_at_risk,
					$report['strategic_items_checked'],
					$critical_count,
					$report['auto_healed_count'],
					$report['email_status_msg']
				),
				$critical_count > 0 ? 'warning' : 'info',
				array(
					'run_id'        => $report['run_id'],
					'total_at_risk' => $total_at_risk,
					'email_sent'    => $report['email_sent'],
				)
			);
		}

		return $report;
	}

	/**
	 * Run an instant standalone scan of all products carrying strategic internal labels (bestseller, promo, nouveaute, tendance).
	 * Used by the Admin UI "Analyser maintenant" button without needing a full XML regeneration.
	 *
	 * @param array $options Plugin settings.
	 * @return array Compiled report.
	 */
	public static function run_standalone_scan( array $options = array() ): array {
		if ( empty( $options ) ) {
			$options = get_option( 'woo_meta_catalog_settings', array() );
		}

		self::reset_runtime_buffer();
		Feed_Item::reset_sales_cache();

		// Force all 4 strategic label detectors on if we want to inspect active sets, or respect current settings.
		// If none of the 4 checkboxes are enabled yet, inspect all 4 so the user gets immediate value.
		$any_enabled = ! empty( $options['label_enable_promo'] )
			|| ! empty( $options['label_enable_new'] )
			|| ! empty( $options['label_enable_bestseller'] )
			|| ! empty( $options['label_enable_trending'] );

		$scan_options = $options;
		if ( ! $any_enabled ) {
			$scan_options['label_enable_promo']      = 1;
			$scan_options['label_enable_new']        = 1;
			$scan_options['label_enable_bestseller'] = 1;
			$scan_options['label_enable_trending']   = 1;
		}

		// Gather candidate product IDs across the 4 strategic sets.
		$candidate_ids = array();

		if ( ! empty( $scan_options['label_enable_bestseller'] ) ) {
			$bs_map        = Feed_Item::get_bestseller_ids( $scan_options );
			$candidate_ids = array_merge( $candidate_ids, array_keys( $bs_map ) );
		}

		if ( ! empty( $scan_options['label_enable_trending'] ) ) {
			$tr_map        = Feed_Item::get_trending_ids( $scan_options );
			$candidate_ids = array_merge( $candidate_ids, array_keys( $tr_map ) );
		}

		if ( ! empty( $scan_options['label_enable_new'] ) ) {
			$new_map       = Feed_Item::get_new_product_ids( $scan_options );
			$candidate_ids = array_merge( $candidate_ids, array_keys( $new_map ) );
		}

		if ( ! empty( $scan_options['label_enable_promo'] ) && function_exists( 'wc_get_product_ids_on_sale' ) ) {
			$sale_ids      = wc_get_product_ids_on_sale();
			$candidate_ids = array_merge( $candidate_ids, array_map( 'intval', (array) $sale_ids ) );
		}

		$candidate_ids = array_values( array_unique( array_filter( array_map( 'intval', $candidate_ids ) ) ) );

		$exclude_hidden       = isset( $scan_options['exclude_hidden'] ) ? ! empty( $scan_options['exclude_hidden'] ) : true;
		$exclude_out_of_stock = ! empty( $scan_options['exclude_out_of_stock'] );
		$dead_stock_days      = ! empty( $scan_options['exclude_dead_stock_days'] ) ? (int) $scan_options['exclude_dead_stock_days'] : 0;

		foreach ( $candidate_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! is_a( $product, '\WC_Product' ) ) {
				continue;
			}

			// If wc_get_product_ids_on_sale returned a variation ID directly, resolve its parent.
			$parent = null;
			if ( $product->is_type( 'variation' ) ) {
				$parent_id = $product->get_parent_id();
				$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
				if ( ! $parent || 'publish' !== $parent->get_status() ) {
					continue;
				}
			} elseif ( 'publish' !== $product->get_status() ) {
				continue;
			}

			$target_vis = $parent ? $parent : $product;
			if ( $exclude_hidden && 'hidden' === $target_vis->get_catalog_visibility() ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				$children_ids = $product->get_children();
				foreach ( $children_ids as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( ! $variation || ! is_a( $variation, '\WC_Product_Variation' ) ) {
						continue;
					}
					if ( $exclude_out_of_stock && ! $variation->is_in_stock() ) {
						continue;
					}
					if ( $dead_stock_days > 0 && Feed_Item::is_dead_stock( $variation, $product, $dead_stock_days ) ) {
						continue;
					}
					self::scan_single_product_or_variation( $variation, $product, $scan_options );
				}
			} else {
				if ( $exclude_out_of_stock && ! $product->is_in_stock() ) {
					continue;
				}
				if ( $dead_stock_days > 0 && Feed_Item::is_dead_stock( $product, $parent, $dead_stock_days ) ) {
					continue;
				}
				self::scan_single_product_or_variation( $product, $parent, $scan_options );
			}
		}

		$fake_job_state = array(
			'run_id' => 'manual_scan_' . time(),
		);
		self::merge_chunk_buffer_into_job_state( $fake_job_state );

		$items          = ! empty( $fake_job_state['image_risks'] ) ? array_values( $fake_job_state['image_risks'] ) : array();
		$total_at_risk  = (int) ( $fake_job_state['image_risks_total'] ?? count( $items ) );
		$critical_count = 0;
		$warning_count  = 0;

		foreach ( $items as $item ) {
			if ( 'critical' === ( $item['status'] ?? '' ) ) {
				$critical_count++;
			} else {
				$warning_count++;
			}
		}

		$existing_report = get_option( self::REPORT_OPTION, array() );

		$report = array(
			'run_id'                  => $fake_job_state['run_id'],
			'timestamp'               => time(),
			'date'                    => wp_date( 'd/m/Y à H:i:s' ),
			'strategic_items_checked' => (int) ( $fake_job_state['strategic_items_checked'] ?? 0 ),
			'total_at_risk'           => $total_at_risk,
			'critical_count'          => $critical_count,
			'warning_count'           => $warning_count,
			'auto_healed_count'       => (int) ( $fake_job_state['auto_healed_count'] ?? 0 ),
			'labels_stripped_count'   => (int) ( $fake_job_state['labels_stripped_count'] ?? 0 ),
			'cdn_active'              => ! empty( $options['enable_image_cdn'] ) && ! empty( $options['image_cdn_endpoint'] ),
			'items'                   => $items,
			'email_sent'              => $existing_report['email_sent'] ?? false,
			'email_status_msg'        => $existing_report['email_status_msg'] ?? '',
		);

		update_option( self::REPORT_OPTION, $report, false );
		return $report;
	}

	/**
	 * Helper for standalone scan on a single product or variation.
	 *
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent  Parent if variation.
	 * @param array            $options Settings.
	 * @return void
	 */
	protected static function scan_single_product_or_variation( \WC_Product $product, $parent, array $options ) {
		$is_variation    = ( null !== $parent && is_a( $parent, '\WC_Product' ) );
		$internal_labels = Feed_Item::get_internal_labels( $product, $parent, $options );
		$strategic       = self::extract_strategic_labels( $internal_labels, $options );

		if ( empty( $strategic ) ) {
			return;
		}

		$image_id = (int) $product->get_image_id();
		if ( ! $image_id && $is_variation ) {
			$image_id = (int) $parent->get_image_id();
		}

		$raw_url          = $image_id ? (string) wp_get_attachment_image_url( $image_id, 'full' ) : '';
		$target_for_gal   = $is_variation ? $parent : $product;
		$gallery_ids      = (array) $target_for_gal->get_gallery_image_ids();

		self::evaluate_and_remediate(
			$product,
			$parent,
			$image_id,
			$raw_url,
			$gallery_ids,
			$strategic,
			$internal_labels,
			$options
		);
	}

	/**
	 * Send the HTML email alert for at-risk strategic products, enforcing a strict max 1 email per 24h limit.
	 *
	 * @param array $report       Compiled image risk report.
	 * @param array $options      Plugin settings.
	 * @param bool  $force_manual True when triggered manually by admin click (bypasses 24h throttle).
	 * @return array Status array ['sent' => bool, 'message' => string].
	 */
	public static function send_alert_email( array $report, array $options = array(), bool $force_manual = false ): array {
		if ( empty( $options ) ) {
			$options = get_option( 'woo_meta_catalog_settings', array() );
		}

		$total_at_risk = (int) ( $report['total_at_risk'] ?? 0 );
		if ( $total_at_risk <= 0 && ! $force_manual ) {
			return array(
				'sent'    => false,
				'message' => __( 'Aucun produit à risque à signaler.', 'woo-meta-catalog' ),
			);
		}

		// 1. Strict 24-hour rate limit check ("une fois par jour max").
		$last_email = get_option( self::LAST_EMAIL_OPTION, array() );
		$last_ts    = ! empty( $last_email['timestamp'] ) ? (int) $last_email['timestamp'] : 0;
		$now        = time();

		if ( ! $force_manual && $last_ts > 0 && ( $now - $last_ts ) < DAY_IN_SECONDS ) {
			$next_allowed = wp_date( 'd/m/Y à H:i', $last_ts + DAY_IN_SECONDS );
			return array(
				'sent'    => false,
				'message' => sprintf(
					/* translators: %s: next allowed date/time */
					__( 'E-mail d\'alerte déjà envoyé au cours des dernières 24h (prochain envoi autorisé à partir du %s).', 'woo-meta-catalog' ),
					$next_allowed
				),
			);
		}

		// 2. Resolve recipient email(s).
		$raw_recipients = ! empty( $options['image_risk_alert_email'] ) ? $options['image_risk_alert_email'] : get_option( 'admin_email' );
		$emails         = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $raw_recipients ) ) ) );

		if ( empty( $emails ) ) {
			$fallback_admin = sanitize_email( get_option( 'admin_email' ) );
			if ( ! empty( $fallback_admin ) ) {
				$emails = array( $fallback_admin );
			}
		}

		if ( empty( $emails ) ) {
			return array(
				'sent'    => false,
				'message' => __( 'Aucune adresse e-mail de destinataire valide configurée.', 'woo-meta-catalog' ),
			);
		}

		$store_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( $total_at_risk > 0 ) {
			$subject = sprintf(
				'[%1$s] ⚠️ Alerte Catalogue Meta : %2$d produit(s) stratégique(s) avec image non conforme',
				$store_name,
				$total_at_risk
			);
		} else {
			$subject = sprintf(
				'[%1$s] ✅ Audit Catalogue Meta : 100%% des images stratégiques sont conformes',
				$store_name
			);
		}

		$body    = self::build_email_html( $report, $options, $store_name );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $emails, $subject, $body, $headers );

		if ( $sent ) {
			$last_data = array(
				'timestamp'     => $now,
				'date'          => wp_date( 'd/m/Y à H:i:s', $now ),
				'recipients'    => implode( ', ', $emails ),
				'total_at_risk' => $total_at_risk,
			);
			update_option( self::LAST_EMAIL_OPTION, $last_data, false );

			return array(
				'sent'    => true,
				'message' => sprintf(
					/* translators: %s: recipient emails */
					__( 'Alerte e-mail envoyée avec succès à %s.', 'woo-meta-catalog' ),
					implode( ', ', $emails )
				),
			);
		}

		return array(
			'sent'    => false,
			'message' => __( 'Échec d\'envoi via wp_mail(). Vérifiez la configuration SMTP de WordPress.', 'woo-meta-catalog' ),
		);
	}

	/**
	 * Build the HTML body for the daily image risk alert email.
	 *
	 * @param array  $report     Compiled risk report.
	 * @param array  $options    Plugin settings.
	 * @param string $store_name Store name.
	 * @return string HTML content.
	 */
	protected static function build_email_html( array $report, array $options, string $store_name ): string {
		$items           = ! empty( $report['items'] ) && is_array( $report['items'] ) ? $report['items'] : array();
		$total_at_risk   = (int) ( $report['total_at_risk'] ?? count( $items ) );
		$checked         = (int) ( $report['strategic_items_checked'] ?? 0 );
		$critical        = (int) ( $report['critical_count'] ?? 0 );
		$auto_healed     = (int) ( $report['auto_healed_count'] ?? 0 );
		$labels_stripped = (int) ( $report['labels_stripped_count'] ?? 0 );
		$cdn_active      = ! empty( $report['cdn_active'] );
		$settings_url    = admin_url( 'admin.php?page=woo-meta-catalog-feed#woo-meta-image-risk-section' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="fr">
		<head>
			<meta charset="UTF-8">
			<title>Audit Images Catalogue Meta</title>
		</head>
		<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1e293b;">
			<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9; padding:24px 12px;">
				<tr>
					<td align="center">
						<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:720px; background-color:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #cbd5e1;">
							<!-- HEADER -->
							<tr>
								<td style="background-color:#0f172a; padding:20px 24px; color:#ffffff;">
									<div style="font-size:12px; text-transform:uppercase; letter-spacing:0.06em; color:#94a3b8; font-weight:600;">
										Woo Meta Catalog Feed • <?php echo esc_html( $store_name ); ?>
									</div>
									<h1 style="margin:6px 0 0 0; font-size:18px; font-weight:700; color:#ffffff;">
										<?php if ( $total_at_risk > 0 ) : ?>
											⚠️ <?php printf( esc_html__( '%d produit(s) stratégique(s) à risque de rejet d\'image Meta', 'woo-meta-catalog' ), $total_at_risk ); ?>
										<?php else : ?>
											✅ <?php esc_html_e( 'Aucune anomalie d\'image sur vos ensembles stratégiques Meta', 'woo-meta-catalog' ); ?>
										<?php endif; ?>
									</h1>
								</td>
							</tr>

							<!-- INTRO & METRICS -->
							<tr>
								<td style="padding:20px 24px;">
									<p style="margin:0 0 14px 0; font-size:14px; line-height:1.5; color:#334155;">
										Lors de la compilation du flux catalogue XML (<strong><?php echo esc_html( $report['date'] ?? wp_date( 'd/m/Y H:i' ) ); ?></strong>), le plugin a contrôlé les images des produits porteurs de vos étiquettes internes stratégiques (<code>bestseller</code>, <code>promo</code>, <code>nouveaute</code>, <code>tendance</code>) selon les exigences strictes de Meta Commerce Manager (<strong>format JPEG/PNG, poids &lt; 8 Mo, dimensions &ge; 500×500 px, fichier existant</strong>).
									</p>

									<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:18px; background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
										<tr>
											<td style="padding:12px 14px; border-right:1px solid #e2e8f0; text-align:center;" width="25%">
												<div style="font-size:11px; color:#64748b; text-transform:uppercase; font-weight:600;">Articles contrôlés</div>
												<div style="font-size:18px; font-weight:700; color:#0f172a; margin-top:4px;"><?php echo (int) $checked; ?></div>
											</td>
											<td style="padding:12px 14px; border-right:1px solid #e2e8f0; text-align:center;" width="25%">
												<div style="font-size:11px; color:#64748b; text-transform:uppercase; font-weight:600;">Produits à risque</div>
												<div style="font-size:18px; font-weight:700; color:<?php echo $total_at_risk > 0 ? '#dc2626' : '#16a34a'; ?>; margin-top:4px;"><?php echo (int) $total_at_risk; ?></div>
											</td>
											<td style="padding:12px 14px; border-right:1px solid #e2e8f0; text-align:center;" width="25%">
												<div style="font-size:11px; color:#64748b; text-transform:uppercase; font-weight:600;">Sauvés via Galerie</div>
												<div style="font-size:18px; font-weight:700; color:#0284c7; margin-top:4px;"><?php echo (int) $auto_healed; ?></div>
											</td>
											<td style="padding:12px 14px; text-align:center;" width="25%">
												<div style="font-size:11px; color:#64748b; text-transform:uppercase; font-weight:600;">Bouclier CDN</div>
												<div style="font-size:14px; font-weight:700; color:<?php echo $cdn_active ? '#16a34a' : '#d97706'; ?>; margin-top:6px;">
													<?php echo $cdn_active ? 'Actif (ImageKit)' : 'Inactif'; ?>
												</div>
											</td>
										</tr>
									</table>

									<?php if ( ! $cdn_active ) : ?>
										<div style="margin-bottom:18px; padding:12px 16px; background-color:#fffbeb; border-left:4px solid #f59e0b; border-radius:4px; font-size:13px; line-height:1.5; color:#92400e;">
											<strong>💡 Conseil d'expert (Bouclier CDN ImageKit inactif) :</strong> En plus des images &lt; 500×500 px ci-dessous, Meta affiche parfois cette même erreur lorsque le pare-feu du serveur limite le débit (Rate-Limiting) lors du crawl simultané de centaines d'images. Activer l'option gratuite <strong>ImageKit.io (Carré 1:1 1024×1024 + JPEG auto)</strong> dans les réglages du plugin élimine automatiquement les rejets de dimensions (&lt; 500×500 px), de format et de rate-limiting.
										</div>
									<?php endif; ?>

									<?php if ( ! empty( $items ) ) : ?>
										<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; font-size:13px; border:1px solid #e2e8f0;">
											<thead>
												<tr style="background-color:#f1f5f9; text-align:left; color:#475569;">
													<th style="padding:10px 12px; border-bottom:1px solid #e2e8f0;">Produit</th>
													<th style="padding:10px 12px; border-bottom:1px solid #e2e8f0;">Étiquette(s)</th>
													<th style="padding:10px 12px; border-bottom:1px solid #e2e8f0;">Anomalie détectée</th>
													<th style="padding:10px 12px; border-bottom:1px solid #e2e8f0;">Statut Flux</th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( array_slice( $items, 0, 50 ) as $row ) : ?>
													<tr style="border-bottom:1px solid #e2e8f0;">
														<td style="padding:10px 12px; vertical-align:top;">
															<a href="<?php echo esc_url( $row['edit_url'] ); ?>" style="font-weight:600; color:#2563eb; text-decoration:none;">
																<?php echo esc_html( $row['name'] ); ?>
															</a>
															<div style="font-size:11px; color:#64748b; margin-top:2px;">
																ID #<?php echo (int) $row['product_id']; ?>
																<?php if ( ! empty( $row['variations_count'] ) && $row['variations_count'] > 1 ) : ?>
																	• <?php printf( esc_html__( '%d déclinaisons', 'woo-meta-catalog' ), (int) $row['variations_count'] ); ?>
																<?php endif; ?>
																• <a href="<?php echo esc_url( $row['edit_url'] ); ?>" style="color:#475569; text-decoration:underline;">Modifier dans WP</a>
															</div>
														</td>
														<td style="padding:10px 12px; vertical-align:top;">
															<?php foreach ( (array) $row['labels'] as $lbl ) : ?>
																<span style="display:inline-block; background-color:#e0f2fe; color:#0369a1; font-size:11px; font-weight:600; padding:2px 7px; border-radius:4px; margin:0 3px 3px 0;">
																	<?php echo esc_html( $lbl ); ?>
																</span>
															<?php endforeach; ?>
														</td>
														<td style="padding:10px 12px; vertical-align:top; color:#991b1b;">
															<?php foreach ( (array) $row['issues'] as $iss ) : ?>
																<div style="margin-bottom:3px;">• <?php echo esc_html( $iss ); ?></div>
															<?php endforeach; ?>
															<div style="font-size:11px; color:#64748b; margin-top:2px;">
																Format : <?php echo esc_html( $row['format'] ); ?> |
																Dim : <?php echo ( $row['width'] > 0 ? esc_html( $row['width'] . '×' . $row['height'] . ' px' ) : 'N/A' ); ?> |
																Poids : <?php echo esc_html( $row['filesize_human'] ); ?>
															</div>
														</td>
														<td style="padding:10px 12px; vertical-align:top;">
															<?php if ( ! empty( $row['auto_healed'] ) ) : ?>
																<span style="display:inline-block; background-color:#dcfce7; color:#15803d; font-size:11px; font-weight:600; padding:3px 8px; border-radius:4px;">
																	✓ Sauvé par Galerie (#<?php echo (int) $row['healed_image_id']; ?>)
																</span>
															<?php elseif ( ! empty( $row['labels_stripped'] ) ) : ?>
																<span style="display:inline-block; background-color:#fef3c7; color:#b45309; font-size:11px; font-weight:600; padding:3px 8px; border-radius:4px;">
																	🛡️ Étiquette suspendue
																</span>
															<?php else : ?>
																<span style="display:inline-block; background-color:#fee2e2; color:#b91c1c; font-size:11px; font-weight:600; padding:3px 8px; border-radius:4px;">
																	À remplacer (&ge; 500×500 px)
																</span>
															<?php endif; ?>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>

										<?php if ( count( $items ) > 50 ) : ?>
											<p style="margin:10px 0 0 0; font-size:12px; color:#64748b; font-style:italic;">
												<?php printf( esc_html__( '... et %d autre(s) produit(s) consultables dans le tableau de bord WordPress.', 'woo-meta-catalog' ), count( $items ) - 50 ); ?>
											</p>
										<?php endif; ?>
									<?php endif; ?>

									<div style="margin-top:22px; text-align:center;">
										<a href="<?php echo esc_url( $settings_url ); ?>" style="display:inline-block; background-color:#2563eb; color:#ffffff; font-weight:600; font-size:13px; padding:10px 20px; border-radius:6px; text-decoration:none;">
											Ouvrir le rapport complet dans WordPress
										</a>
									</div>
								</td>
							</tr>

							<!-- FOOTER -->
							<tr>
								<td style="background-color:#f8fafc; padding:14px 24px; border-top:1px solid #e2e8f0; font-size:11px; color:#64748b; text-align:center;">
									Cet e-mail automatique est limité à <strong>1 envoi maximum par 24 heures</strong> par l'extension <em>Woo Meta Catalog Feed Soyoo</em>.
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php
		return (string) ob_get_clean();
	}
}
