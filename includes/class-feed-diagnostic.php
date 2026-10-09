<?php
/**
 * Automated diagnostic health check and dry-run tester.
 *
 * @package WooMetaCatalogFeedSoyoo
 */

namespace SOYOO\MetaCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feed_Diagnostic
 */
class Feed_Diagnostic {

	/**
	 * Run full diagnostic checks and return structured health results.
	 *
	 * @return array
	 */
	public static function get_health_status() {
		global $wpdb;

		$results = array(
			'summary' => array(
				'ok'       => 0,
				'warnings' => 0,
				'errors'   => 0,
			),
			'checks'  => array(),
		);

		// 1. PHP Resources & Environment.
		$mem_limit_raw = ini_get( 'memory_limit' );
		$mem_bytes     = wp_convert_hr_to_bytes( $mem_limit_raw );
		$mem_limit_mb  = round( $mem_bytes / ( 1024 * 1024 ) );
		$cur_mem       = size_format( memory_get_usage( true ), 2 );
		$peak_mem      = size_format( memory_get_peak_usage( true ), 2 );
		$max_time      = (int) ini_get( 'max_execution_time' );

		$php_status = 'ok';
		$php_notes  = array();

		if ( $mem_limit_mb < 256 ) {
			$php_status = 'error';
			$php_notes[] = sprintf( __( 'Limite mémoire très basse (%s). Recommandé : 512M minimum pour WooCommerce.', 'woo-meta-catalog' ), $mem_limit_raw );
		} elseif ( $mem_limit_mb < 512 ) {
			$php_status = 'warning';
			$php_notes[] = sprintf( __( 'Limite mémoire (%s) correcte mais 512M recommandé pour les catalogues avec variations.', 'woo-meta-catalog' ), $mem_limit_raw );
		}

		$results['checks']['php_resources'] = array(
			'title'       => __( 'Ressources PHP & Mémoire', 'woo-meta-catalog' ),
			'status'      => $php_status,
			'badge'       => 'PHP ' . PHP_VERSION . ' / ' . $mem_limit_raw,
			'details'     => array(
				__( 'Version PHP', 'woo-meta-catalog' )            => PHP_VERSION,
				__( 'Limite mémoire PHP', 'woo-meta-catalog' )     => $mem_limit_raw,
				__( 'Mémoire RAM allouée (actuelle)', 'woo-meta-catalog' ) => $cur_mem,
				__( 'Pic mémoire RAM atteint', 'woo-meta-catalog' ) => $peak_mem,
				__( 'Délai d\'exécution max (CLI/Web)', 'woo-meta-catalog' ) => ( $max_time > 0 ? $max_time . ' s' : __( 'Illimité (0)', 'woo-meta-catalog' ) ),
			),
			'notes'       => $php_notes,
		);

		// 2. Filesystem & Feed Directory Permissions.
		$feed_dir     = Feed_Generator::get_feed_dir();
		$dir_exists   = file_exists( $feed_dir ) && is_dir( $feed_dir );
		$dir_writable = $dir_exists && is_writable( $feed_dir );

		// Test write capabilities.
		$test_file       = trailingslashit( $feed_dir ) . '.write_test_' . time();
		$test_write_ok   = false;
		$temp_write_file = @file_put_contents( $test_file, 'test' );
		if ( false !== $temp_write_file ) {
			$test_write_ok = true;
			@unlink( $test_file );
		}

		$fs_status = ( $dir_exists && $dir_writable && $test_write_ok ) ? 'ok' : 'error';
		$fs_notes  = array();
		if ( ! $dir_writable || ! $test_write_ok ) {
			$fs_notes[] = sprintf( __( 'Le dossier %s n\'est pas accessible en écriture par PHP.', 'woo-meta-catalog' ), $feed_dir );
		}

		$results['checks']['filesystem'] = array(
			'title'       => __( 'Système de fichiers & Permissions', 'woo-meta-catalog' ),
			'status'      => $fs_status,
			'badge'       => $fs_status === 'ok' ? __( 'Inscriptible', 'woo-meta-catalog' ) : __( 'Erreur écriture', 'woo-meta-catalog' ),
			'details'     => array(
				__( 'Dossier du flux', 'woo-meta-catalog' )         => $feed_dir,
				__( 'Dossier existant', 'woo-meta-catalog' )        => $dir_exists ? __( 'Oui', 'woo-meta-catalog' ) : __( 'Non', 'woo-meta-catalog' ),
				__( 'Permissions d\'écriture', 'woo-meta-catalog' ) => $dir_writable ? __( 'Oui (Accessible)', 'woo-meta-catalog' ) : __( 'Non (Bloqué)', 'woo-meta-catalog' ),
				__( 'Test d\'écriture temporaire', 'woo-meta-catalog' ) => $test_write_ok ? __( 'Réussi', 'woo-meta-catalog' ) : __( 'Échec', 'woo-meta-catalog' ),
				__( 'Protection .htaccess', 'woo-meta-catalog' )    => file_exists( $feed_dir . '/.htaccess' ) ? __( 'Active', 'woo-meta-catalog' ) : __( 'Absente', 'woo-meta-catalog' ),
			),
			'notes'       => $fs_notes,
		);

		// 3. Current XML Feed File.
		$feed_file    = Feed_Generator::get_feed_file_path();
		$file_exists  = file_exists( $feed_file );
		$file_size    = $file_exists ? filesize( $feed_file ) : 0;
		$feed_status  = get_option( Feed_Generator::FEED_STATUS_OPTION, array() );

		$xml_status = 'ok';
		$xml_notes  = array();
		$item_count = 0;

		if ( ! $file_exists || $file_size === 0 ) {
			$xml_status = 'warning';
			$xml_notes[] = __( 'Le fichier XML n\'existe pas encore ou est vide. Lancez une première génération.', 'woo-meta-catalog' );
		} else {
			// Memory-safe item counting via chunked stream scanning.
			$handle = @fopen( $feed_file, 'r' );
			if ( $handle ) {
				while ( ! feof( $handle ) ) {
					$buffer = fread( $handle, 65536 );
					$item_count += substr_count( $buffer, '<item>' );
				}
				fclose( $handle );
			}

			// Validate XML root structure without loading entire file in DOM.
			$header_chunk = '';
			$h_handle     = @fopen( $feed_file, 'r' );
			if ( $h_handle ) {
				$header_chunk = fread( $h_handle, 1024 );
				fclose( $h_handle );
			}

			$footer_chunk = '';
			$f_handle     = @fopen( $feed_file, 'r' );
			if ( $f_handle ) {
				if ( $file_size > 1024 ) {
					fseek( $f_handle, -1024, SEEK_END );
				}
				$footer_chunk = fread( $f_handle, 1024 );
				fclose( $f_handle );
			}

			$has_valid_header = ( false !== strpos( $header_chunk, '<?xml' ) && false !== strpos( $header_chunk, '<rss' ) );
			$has_valid_footer = ( false !== strpos( $footer_chunk, '</channel>' ) && false !== strpos( $footer_chunk, '</rss>' ) );

			if ( ! $has_valid_header || ! $has_valid_footer ) {
				$xml_status = 'error';
				$xml_notes[] = __( 'La structure du fichier XML est incomplète ou corrompue (balises fermantes manquantes).', 'woo-meta-catalog' );
			}
		}

		$time_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$mod_date    = $file_exists ? date_i18n( 'd/m/Y à H:i:s', filemtime( $feed_file ) + $time_offset ) : '-';

		$results['checks']['xml_feed'] = array(
			'title'       => __( 'Fichier XML du flux (meta-catalog.xml)', 'woo-meta-catalog' ),
			'status'      => $xml_status,
			'badge'       => $file_exists ? size_format( $file_size, 2 ) : __( 'Inexistant', 'woo-meta-catalog' ),
			'details'     => array(
				__( 'Chemin du fichier', 'woo-meta-catalog' )    => $feed_file,
				__( 'Taille du fichier', 'woo-meta-catalog' )    => $file_exists ? size_format( $file_size, 2 ) : '-',
				__( 'Articles (<item>) détectés', 'woo-meta-catalog' ) => $item_count > 0 ? number_format_i18n( $item_count ) : ( $file_exists ? '0' : '-' ),
				__( 'Dernière modification', 'woo-meta-catalog' ) => $mod_date,
				__( 'URL publique', 'woo-meta-catalog' )         => Feed_Server::get_public_url(),
			),
			'notes'       => $xml_notes,
		);

		// 4. Object Cache & Persistence (Redis / Object Cache Pro / WP 6.0 Runtime Flush).
		$has_ext_cache      = wp_using_ext_object_cache();
		$has_redis          = class_exists( 'Redis' ) || class_exists( 'PhpRedis' );
		$has_ocp            = defined( 'OBJECT_CACHE_PRO_VERSION' ) || class_exists( '\RedisCachePro\Plugin' );
		$has_runtime_flush  = function_exists( 'wp_cache_flush_runtime' );
		$has_prod_caching   = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' )
			&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'product_instance_caching' );

		$cache_status = 'ok';
		$cache_notes  = array();

		if ( $has_prod_caching ) {
			$cache_notes[] = __( 'La fonctionnalité WooCommerce « Product Instance Caching » est active : le flux applique une purge mémoire runtime automatique après chaque lot.', 'woo-meta-catalog' );
		}

		$results['checks']['caching_performance'] = array(
			'title'       => __( 'Cache Objet & Mémoire Vive (Redis / OCP)', 'woo-meta-catalog' ),
			'status'      => $cache_status,
			'badge'       => $has_ocp ? 'Object Cache Pro' : ( $has_ext_cache ? 'Redis / Ext' : 'Standard' ),
			'details'     => array(
				__( 'Cache objet externe actif', 'woo-meta-catalog' ) => $has_ext_cache ? __( 'Oui', 'woo-meta-catalog' ) : __( 'Non (Base SQL directe)', 'woo-meta-catalog' ),
				__( 'Object Cache Pro détecté', 'woo-meta-catalog' )  => $has_ocp ? ( defined( 'OBJECT_CACHE_PRO_VERSION' ) ? 'v' . OBJECT_CACHE_PRO_VERSION : __( 'Oui', 'woo-meta-catalog' ) ) : __( 'Non', 'woo-meta-catalog' ),
				__( 'Purge runtime (wp_cache_flush_runtime)', 'woo-meta-catalog' ) => $has_runtime_flush ? __( 'Disponible (Optimisé WP 6.0+)', 'woo-meta-catalog' ) : __( 'Non supporté', 'woo-meta-catalog' ),
				__( 'Product Instance Caching WC', 'woo-meta-catalog' ) => $has_prod_caching ? __( 'Actif (Purge locale appliquée)', 'woo-meta-catalog' ) : __( 'Désactivé', 'woo-meta-catalog' ),
			),
			'notes'       => $cache_notes,
		);

		// 5. Catalog Breakdown & Batch Size Recommendation.
		$total_published  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		$total_variations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish'" );

		$options        = get_option( 'woo_meta_catalog_settings', array() );
		$curr_batch     = ! empty( $options['batch_size'] ) ? (int) $options['batch_size'] : 50;
		$rec_batch      = 50;
		$catalog_status = 'ok';
		$catalog_notes  = array();

		if ( $total_variations > 200 && $curr_batch > 50 ) {
			$catalog_status = 'warning';
			$catalog_notes[] = sprintf(
				__( 'Votre boutique possède %1$d déclinaisons pour une taille de lot de %2$d. Nous vous recommandons de réduire la taille des lots à 50 pour éviter les saturations de mémoire PHP.', 'woo-meta-catalog' ),
				$total_variations,
				$curr_batch
			);
		}

		$results['checks']['catalog'] = array(
			'title'       => __( 'Composition du catalogue WooCommerce', 'woo-meta-catalog' ),
			'status'      => $catalog_status,
			'badge'       => number_format_i18n( $total_published ) . ' ' . __( 'produits', 'woo-meta-catalog' ),
			'details'     => array(
				__( 'Produits parents publiés', 'woo-meta-catalog' ) => number_format_i18n( $total_published ),
				__( 'Déclinaisons (variations)', 'woo-meta-catalog' ) => number_format_i18n( $total_variations ),
				__( 'Taille de lot actuelle', 'woo-meta-catalog' )   => $curr_batch . ' ' . __( 'produits / lot', 'woo-meta-catalog' ),
				__( 'Taille de lot recommandée', 'woo-meta-catalog' ) => $rec_batch . ' ' . __( 'produits / lot', 'woo-meta-catalog' ),
			),
			'notes'       => $catalog_notes,
		);

		// 6. Action Scheduler & Daily Cron.
		$as_available  = function_exists( 'as_has_scheduled_action' );
		$has_scheduled = $as_available && as_has_scheduled_action( Feed_Generator::DAILY_HOOK );
		$next_ts       = $as_available ? as_next_scheduled_action( Feed_Generator::DAILY_HOOK ) : null;
		$next_str      = $next_ts ? date_i18n( 'd/m/Y à H:i:s', $next_ts + $time_offset ) : __( 'Non planifié', 'woo-meta-catalog' );

		$sched_status = ( $as_available && $has_scheduled ) ? 'ok' : 'warning';
		$sched_notes  = array();
		if ( ! $has_scheduled ) {
			$sched_notes[] = __( 'La tâche nocturne Action Scheduler n\'est pas encore planifiée. Enregistrez les réglages pour l\'activer.', 'woo-meta-catalog' );
		}

		$results['checks']['scheduler'] = array(
			'title'       => __( 'Planificateur nocturne (Action Scheduler)', 'woo-meta-catalog' ),
			'status'      => $sched_status,
			'badge'       => $has_scheduled ? __( 'Planifié', 'woo-meta-catalog' ) : __( 'Inactif', 'woo-meta-catalog' ),
			'details'     => array(
				__( 'Action Scheduler disponible', 'woo-meta-catalog' ) => $as_available ? __( 'Oui', 'woo-meta-catalog' ) : __( 'Non', 'woo-meta-catalog' ),
				__( 'Tâche quotidienne récurrente', 'woo-meta-catalog' ) => $has_scheduled ? __( 'Active', 'woo-meta-catalog' ) : __( 'Inerte', 'woo-meta-catalog' ),
				__( 'Prochaine exécution automatique', 'woo-meta-catalog' ) => $next_str,
				__( 'Heure cible configurée', 'woo-meta-catalog' )    => ! empty( $options['daily_time'] ) ? $options['daily_time'] : '03:30',
			),
			'notes'       => $sched_notes,
		);

		// 7. Tracking CAPI Alignment.
		$has_tracking = defined( 'WFBT_VERSION' ) && class_exists( '\WFBT\Product_Id' );
		$track_mode   = $has_tracking ? (string) \WFBT\Product_Id::get_effective_format() : '-';

		$track_status = 'ok';
		$track_notes  = array();

		if ( ! $has_tracking ) {
			$track_status = 'warning';
			$track_notes[] = __( 'woo-fb-tracking-server-side non détecté. Les événements CAPI ne pourront pas s\'aligner avec les IDs du catalogue.', 'woo-meta-catalog' );
		}

		$results['checks']['tracking'] = array(
			'title'       => __( 'Alignement Tracking Meta CAPI (SOYOO)', 'woo-meta-catalog' ),
			'status'      => $track_status,
			'badge'       => $has_tracking ? 'WFBT v' . WFBT_VERSION : __( 'Non détecté', 'woo-meta-catalog' ),
			'details'     => array(
				__( 'Extension tracking active', 'woo-meta-catalog' ) => $has_tracking ? __( 'Oui', 'woo-meta-catalog' ) : __( 'Non', 'woo-meta-catalog' ),
				__( 'Version tracking', 'woo-meta-catalog' )         => $has_tracking ? 'v' . WFBT_VERSION : '-',
				__( 'Mode d\'ID tracking', 'woo-meta-catalog' )       => $track_mode,
				__( 'Contrat inter-extensions', 'woo-meta-catalog' ) => __( 'Actif (Filtre soyoo_meta_catalog_content_id)', 'woo-meta-catalog' ),
			),
			'notes'       => $track_notes,
		);

		// 8. Image CDN Status.
		$cdn_enabled  = ! empty( $options['enable_image_cdn'] );
		$cdn_endpoint = ! empty( $options['image_cdn_endpoint'] ) ? $options['image_cdn_endpoint'] : '';
		$cdn_status   = ( $cdn_enabled && ! empty( $cdn_endpoint ) ) ? 'ok' : 'info';

		$results['checks']['image_cdn'] = array(
			'title'       => __( 'Déportation CDN & Normalisation Images', 'woo-meta-catalog' ),
			'status'      => $cdn_status,
			'badge'       => $cdn_enabled ? ( ! empty( $options['image_cdn_provider'] ) ? ucfirst( $options['image_cdn_provider'] ) : 'CDN' ) : __( 'Désactivé', 'woo-meta-catalog' ),
			'details'     => array(
				__( 'Déportation CDN', 'woo-meta-catalog' )          => $cdn_enabled ? __( 'Activée', 'woo-meta-catalog' ) : __( 'Désactivée (Origine WordPress directe)', 'woo-meta-catalog' ),
				__( 'Endpoint CDN', 'woo-meta-catalog' )             => $cdn_endpoint ?: __( 'Aucun', 'woo-meta-catalog' ),
				__( 'Format Carré 1:1 auto', 'woo-meta-catalog' )    => ! empty( $options['image_cdn_auto_square'] ) ? __( 'Oui (1024×1024 avec fond blanc)', 'woo-meta-catalog' ) : __( 'Non', 'woo-meta-catalog' ),
				__( 'Forcer délivrabilité JPEG', 'woo-meta-catalog' ) => ! empty( $options['image_cdn_force_jpeg'] ) ? __( 'Oui (f-jpg)', 'woo-meta-catalog' ) : __( 'Non', 'woo-meta-catalog' ),
			),
			'notes'       => array(),
		);

		// 9. Trending Products Engine.
		$trending_enabled = ! empty( $options['label_enable_trending'] );
		$trending_mode    = ! empty( $options['label_trending_mode'] ) ? $options['label_trending_mode'] : 'classic';
		$trending_cache   = get_option( 'woo_meta_catalog_trending_cache', array() );
		$trending_count   = ! empty( $trending_cache['total_count'] ) ? (int) $trending_cache['total_count'] : 0;
		$trending_status  = 'info';
		$trending_notes   = array();

		if ( $trending_enabled ) {
			if ( ! empty( $trending_cache['b1_warning'] ) ) {
				$trending_status  = 'warning';
				$trending_notes[] = sprintf(
					__( 'Source Année N-1 directe (B1) incomplète (%1$d / %2$d produits). Vos fiches ont probablement été recréées cette année. Le repli catégorie (B2) a pris le relais pour %3$d produit(s).', 'woo-meta-catalog' ),
					$trending_cache['count_b1'] ?? 0,
					$trending_cache['quota_b'] ?? 0,
					$trending_cache['count_b2'] ?? 0
				);
			} else {
				$trending_status = 'ok';
			}
		}

		$results['checks']['trending'] = array(
			'title'   => __( 'Sélection Marqueur Tendance', 'woo-meta-catalog' ),
			'status'  => $trending_status,
			'badge'   => $trending_enabled ? ( 'seasonal' === $trending_mode ? __( 'Saisonnier', 'woo-meta-catalog' ) : __( 'Classique', 'woo-meta-catalog' ) ) : __( 'Désactivé', 'woo-meta-catalog' ),
			'details' => array(
				__( 'Marqueur Tendance actif', 'woo-meta-catalog' ) => $trending_enabled ? __( 'Oui', 'woo-meta-catalog' ) : __( 'Non', 'woo-meta-catalog' ),
				__( 'Mode algorithmique', 'woo-meta-catalog' )       => 'seasonal' === $trending_mode ? __( 'Saisonnier (Ventes récentes + N-1 + Repli)', 'woo-meta-catalog' ) : __( 'Classique (Ventes récentes)', 'woo-meta-catalog' ),
				__( 'Produits sélectionnés', 'woo-meta-catalog' )    => $trending_enabled ? sprintf( __( '%d produits', 'woo-meta-catalog' ), $trending_count ) : '-',
				__( 'Dernier calcul', 'woo-meta-catalog' )           => ! empty( $trending_cache['calculated_at'] ) ? $trending_cache['calculated_at'] : '-',
				__( 'Ajouts au panier (ATC)', 'woo-meta-catalog' )   => ! empty( $options['label_trending_enable_atc'] ) ? __( 'Activés (Pondération)', 'woo-meta-catalog' ) : __( 'Désactivés', 'woo-meta-catalog' ),
			),
			'notes'   => $trending_notes,
		);

		// Calculate global summary counters.
		foreach ( $results['checks'] as $c ) {
			if ( 'ok' === $c['status'] ) {
				$results['summary']['ok']++;
			} elseif ( 'warning' === $c['status'] ) {
				$results['summary']['warnings']++;
			} elseif ( 'error' === $c['status'] ) {
				$results['summary']['errors']++;
			}
		}

		return $results;
	}

	/**
	 * Run a safe in-memory dry run on a sample of published products.
	 *
	 * @param int $count Number of parent products to test.
	 * @return array
	 */
	public static function run_sample_test( $count = 5 ) {
		$count = max( 1, min( 20, (int) $count ) );

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 60 );
		}

		$options        = get_option( 'woo_meta_catalog_settings', array() );
		$start_time     = microtime( true );
		$mem_start      = memory_get_usage( true );
		$peak_start     = memory_get_peak_usage( true );

		// Query $count products (preferring variable ones to test edge cases).
		$product_ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		if ( empty( $product_ids ) ) {
			return array(
				'success' => false,
				'message' => __( 'Aucun produit publié trouvé dans la boutique pour tester.', 'woo-meta-catalog' ),
			);
		}

		$products_tested   = 0;
		$variations_tested = 0;
		$items_xml         = array();
		$errors            = array();

		foreach ( $product_ids as $pid ) {
			try {
				$product = wc_get_product( $pid );
				if ( ! $product || ! is_a( $product, '\WC_Product' ) ) {
					continue;
				}

				$products_tested++;

				if ( $product->is_type( 'variable' ) ) {
					$children_ids = $product->get_children();
					if ( ! empty( $children_ids ) ) {
						foreach ( $children_ids as $child_id ) {
							try {
								$variation = wc_get_product( $child_id );
								if ( $variation && is_a( $variation, '\WC_Product_Variation' ) ) {
									$variations_tested++;
									$item = Feed_Item::build( $variation, $product, $options );
									if ( ! empty( $item ) && count( $items_xml ) < 3 ) {
										$items_xml[] = $item;
									}
									unset( $variation );
								}
							} catch ( \Throwable $ve ) {
								$errors[] = sprintf( 'Erreur sur déclinaison #%d (Parent #%d) : %s', $child_id, $pid, $ve->getMessage() );
							}
						}
						unset( $children_ids );
					}
				} else {
					$item = Feed_Item::build( $product, null, $options );
					if ( ! empty( $item ) && count( $items_xml ) < 3 ) {
						$items_xml[] = $item;
					}
				}

				unset( $product );
			} catch ( \Throwable $pe ) {
				$errors[] = sprintf( 'Erreur sur produit #%d : %s', $pid, $pe->getMessage() );
			}

			// Clean runtime memory cache.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\Caches\ProductCache' ) ) {
				try {
					$pc = wc_get_container()->get( \Automattic\WooCommerce\Internal\Caches\ProductCache::class );
					if ( $pc && method_exists( $pc, 'flush' ) ) {
						$pc->flush();
					}
				} catch ( \Throwable $t ) {
					// Ignore.
				}
			}
		}

		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		$mem_end     = memory_get_usage( true );
		$mem_peak    = memory_get_peak_usage( true );

		return array(
			'success'           => empty( $errors ),
			'products_tested'   => $products_tested,
			'variations_tested' => $variations_tested,
			'total_items'       => $products_tested + $variations_tested,
			'duration_ms'       => $duration_ms,
			'memory_start'      => size_format( $mem_start, 2 ),
			'memory_end'        => size_format( $mem_end, 2 ),
			'memory_peak'       => size_format( $mem_peak, 2 ),
			'memory_delta'      => size_format( max( 0, $mem_end - $mem_start ), 2 ),
			'sample_xml'        => ! empty( $items_xml ) ? trim( $items_xml[0] ) : '',
			'errors'            => $errors,
			'message'           => sprintf(
				__( 'Test réussi en %1$s ms ! %2$d produits parents et %3$d variations compilés avec succès. Pic mémoire RAM : %4$s.', 'woo-meta-catalog' ),
				$duration_ms,
				$products_tested,
				$variations_tested,
				size_format( $mem_peak, 2 )
			),
		);
	}
}
