/**
 * Admin JavaScript for Woo Meta Catalog Feed Soyoo
 * Author: SOYOO
 */

(function($) {
	'use strict';

	var pollInterval = null;

	$(document).ready(function() {

		// 0. Sanitize Admin Notices - Strip any third-party notices that slipped into header or wrap
		$('.woo-meta-catalog-header').find('.notice, .updated, .error, .update-nag').remove();
		$('#wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .update-nag')
			.not('.woo-meta-catalog-notices .notice')
			.not('.woo-meta-allowed-notice')
			.remove();
		$('.woo-meta-catalog-wrap > .notice, .woo-meta-catalog-wrap > .updated, .woo-meta-catalog-wrap > .error')
			.not('.woo-meta-catalog-notices *')
			.not('.woo-meta-allowed-notice')
			.remove();

		// 1. Copy Feed URL to Clipboard
		$('.btn-copy-feed').on('click', function(e) {
			e.preventDefault();
			var $btn    = $(this);
			var target  = $btn.data('target');
			var $input  = $(target);
			var feedUrl = $input.val();

			if (!feedUrl) {
				return;
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(feedUrl).then(function() {
					showCopyFeedback($btn);
				}).catch(function() {
					fallbackCopy($input, $btn);
				});
			} else {
				fallbackCopy($input, $btn);
			}
		});

		function showCopyFeedback($btn) {
			var $txtSpan    = $btn.find('.copy-text');
			var originalTxt = $txtSpan.text();

			$btn.addClass('copied');
			$txtSpan.text(wooMetaCatalogVars.copiedTxt || 'Copié !');

			setTimeout(function() {
				$btn.removeClass('copied');
				$txtSpan.text(originalTxt);
			}, 2500);
		}

		function fallbackCopy($input, $btn) {
			$input.select();
			document.execCommand('copy');
			showCopyFeedback($btn);
		}

		// 2. Generate Random Security Token
		$('#btn-gen-key').on('click', function(e) {
			e.preventDefault();
			var chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
			var length = 24;
			var token  = '';
			for (var i = 0; i < length; i++) {
				token += chars.charAt(Math.floor(Math.random() * chars.length));
			}
			$('#security_key').val(token);
		});

		// 3. Trigger Manual Generation via Browser-Driven Batch AJAX Runner
		$('#btn-trigger-generation').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);

			if ($btn.hasClass('loading')) {
				return;
			}

			$btn.addClass('loading').prop('disabled', true);
			$('#generation-progress-wrap').fadeIn(200);
			$('#generation-progress-bar').css('width', '3%');
			$('#generation-progress-msg').text('Initialisation du flux catalogue...');

			// Step 1: Initialize generation run
			$.ajax({
				url: wooMetaCatalogVars.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'woo_meta_catalog_init_generation',
					security: wooMetaCatalogVars.nonce
				},
				success: function(initRes) {
					if (!initRes.success || !initRes.data || !initRes.data.run_id) {
						stopLoading($btn);
						alert(initRes.data && initRes.data.message ? initRes.data.message : 'Erreur lors de l\'initialisation.');
						return;
					}

					var runId       = initRes.data.run_id;
					var totalChunks = parseInt(initRes.data.total_chunks, 10) || 1;
					var totalProds  = parseInt(initRes.data.total_products, 10) || 0;

					$('#generation-progress-msg').text('Compilation de ' + totalProds + ' produits en ' + totalChunks + ' lots...');

					function formatAjaxError(xhr, status, error, defaultMsg) {
						var msg = '';
						if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							msg = xhr.responseJSON.data.message;
						} else if (xhr.responseText) {
							if (xhr.responseText.indexOf('Allowed memory size') !== -1 || xhr.responseText.indexOf('Fatal error') !== -1) {
								msg = 'Saturation de mémoire PHP (Fatal Error). Réduisez la taille des lots et consultez l\'onglet Diagnostic.';
							} else if (xhr.responseText.indexOf('<title>504') !== -1 || xhr.status === 504) {
								msg = 'Délai d\'attente serveur dépassé (Gateway Timeout 504). Réduisez la taille des lots.';
							} else if (xhr.status === 500) {
								msg = 'Erreur interne 500 du serveur PHP. Consultez l\'onglet Diagnostic pour voir l\'incident.';
							} else {
								msg = error || status || defaultMsg;
							}
						} else if (xhr.status === 500) {
							msg = 'Erreur interne 500 du serveur PHP. Consultez l\'onglet Diagnostic pour voir l\'incident.';
						} else {
							msg = error || status || defaultMsg;
						}
						return msg;
					}

					// Step 2: Recursive chunk processor
					function processChunk(step) {
						var percent = Math.min(95, Math.max(5, Math.round((step / totalChunks) * 100)));
						$('#generation-progress-bar').css('width', percent + '%');
						$('#generation-progress-msg').text('Compilation du lot ' + (step + 1) + ' / ' + totalChunks + ' (' + percent + '%)...');

						$.ajax({
							url: wooMetaCatalogVars.ajaxUrl,
							type: 'POST',
							dataType: 'json',
							data: {
								action: 'woo_meta_catalog_process_chunk',
								security: wooMetaCatalogVars.nonce,
								run_id: runId,
								step: step
							},
							success: function(chunkRes) {
								if (!chunkRes.success) {
									stopLoading($btn);
									var errMsg = (chunkRes.data && chunkRes.data.message) ? chunkRes.data.message : ('Erreur sur le lot ' + (step + 1));
									$('#generation-progress-msg').html('<span style="color:#dc2626; font-weight:600;">' + errMsg + '</span> — <a href="admin.php?page=woo-meta-catalog-feed&tab=diagnostic" style="color:#2563eb; text-decoration:underline;">Voir le Diagnostic</a>');
									alert('Erreur sur le lot ' + (step + 1) + ' : ' + errMsg);
									return;
								}

								var nextStep = step + 1;
								if (nextStep < totalChunks) {
									processChunk(nextStep);
								} else {
									finalizeFeed(runId);
								}
							},
							error: function(xhr, status, error) {
								stopLoading($btn);
								var errMsg = formatAjaxError(xhr, status, error, 'Erreur serveur inconnue');
								$('#generation-progress-msg').html('<span style="color:#dc2626; font-weight:600;">Erreur lot ' + (step + 1) + ' : ' + errMsg + '</span> — <a href="admin.php?page=woo-meta-catalog-feed&tab=diagnostic" style="color:#2563eb; text-decoration:underline;">Consulter le Diagnostic</a>');
								alert('Erreur lors du traitement du lot ' + (step + 1) + ' :\n' + errMsg + '\n\nConsultez l\'onglet « Diagnostic & Santé » pour voir le détail des incidents.');
							}
						});
					}

					// Step 3: Finalize XML feed
					function finalizeFeed(runId) {
						$('#generation-progress-bar').css('width', '98%');
						$('#generation-progress-msg').text('Finalisation et validation du fichier XML...');

						$.ajax({
							url: wooMetaCatalogVars.ajaxUrl,
							type: 'POST',
							dataType: 'json',
							data: {
								action: 'woo_meta_catalog_finalize_feed',
								security: wooMetaCatalogVars.nonce,
								run_id: runId
							},
							success: function(finRes) {
								stopLoading($btn);
								if (finRes.success && finRes.data) {
									var d = finRes.data;
									$('#generation-progress-bar').css('width', '100%');
									$('#generation-progress-msg').html('<span style="color:#059669; font-weight:600;">' + (d.message || 'Flux généré avec succès !') + '</span>');

									// Update metrics in status boxes
									if (d.total_items) {
										$('#status-items-val').text(d.total_items + ' items');
									}
									if (d.file_size_human) {
										$('#status-size-val').text(d.file_size_human);
									}
									if (d.duration) {
										$('#status-duration-val').text(d.duration + ' s');
									}
									$('#status-state-val').html('<span class="badge-status-ok"><span class="dashicons dashicons-yes"></span> Flux généré et valide</span>');
									$('#status-date-val').text('À l\'instant');
									$('.woo-meta-empty-feed-notice').slideUp(300);

									setTimeout(function() {
										$('#generation-progress-wrap').fadeOut(500);
									}, 4000);
								} else {
									var errMsg = (finRes.data && finRes.data.message) ? finRes.data.message : 'Erreur lors de la finalisation.';
									$('#generation-progress-msg').html('<span style="color:#dc2626; font-weight:600;">' + errMsg + '</span> — <a href="admin.php?page=woo-meta-catalog-feed&tab=diagnostic" style="color:#2563eb; text-decoration:underline;">Voir le Diagnostic</a>');
									alert(errMsg);
								}
							},
							error: function(xhr, status, error) {
								stopLoading($btn);
								var errMsg = formatAjaxError(xhr, status, error, 'Erreur lors de la finalisation');
								$('#generation-progress-msg').html('<span style="color:#dc2626; font-weight:600;">' + errMsg + '</span> — <a href="admin.php?page=woo-meta-catalog-feed&tab=diagnostic" style="color:#2563eb; text-decoration:underline;">Consulter le Diagnostic</a>');
								alert('Erreur serveur lors de la finalisation : ' + errMsg);
							}
						});
					}

					// Start chunk 0
					processChunk(0);
				},
				error: function(xhr, status, error) {
					stopLoading($btn);
					var errMsg = (xhr.status === 500) ? 'Erreur 500 (mémoire ou timeout)' : (error || status || 'Serveur indisponible');
					$('#generation-progress-msg').html('<span style="color:#dc2626; font-weight:600;">Initialisation échouée : ' + errMsg + '</span>');
					alert('Erreur serveur lors de l\'initialisation : ' + errMsg);
				}
			});
		});

		// 4. Reset Stuck Lock
		$('#btn-reset-lock').on('click', function(e) {
			e.preventDefault();
			if (!confirm('Voulez-vous vraiment réinitialiser le verrou du flux ? Cela annulera tout lot en cours.')) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true);

			$.ajax({
				url: wooMetaCatalogVars.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'woo_meta_catalog_reset_lock',
					security: wooMetaCatalogVars.nonce
				},
				success: function(response) {
					$btn.prop('disabled', false);
					if (response.success) {
						clearInterval(pollInterval);
						location.reload();
					}
				},
				error: function() {
					$btn.prop('disabled', false);
				}
			});
		});

		// 5. Bust Image Cache Version
		$('#btn-bust-image-cache').on('click', function(e) {
			e.preventDefault();
			var timestamp = Math.floor(Date.now() / 1000);
			$('#image_version').val(timestamp);
			alert('Nouvelle version d\'image générée (' + timestamp + '). Enregistrez les réglages puis régénérez le flux pour forcer Meta à retélécharger toutes les photos.');
		});

		// 6. Image CDN Options Toggle
		function toggleCdnFields() {
			if ($('#enable_image_cdn').is(':checked')) {
				$('.row-cdn-field').show();
			} else {
				$('.row-cdn-field').hide();
			}
		}
		$('#enable_image_cdn').on('change', toggleCdnFields);
		toggleCdnFields();

		// 7. Test CDN Connection
		$('#btn-test-cdn').on('click', function(e) {
			e.preventDefault();
			var $btn        = $(this);
			var endpoint    = $('#image_cdn_endpoint').val().trim();
			var provider    = $('#image_cdn_provider').val();
			var autoSquare  = $('#image_cdn_auto_square').is(':checked') ? 1 : 0;
			var forceJpeg   = $('#image_cdn_force_jpeg').is(':checked') ? 1 : 0;
			var $res        = $('#cdn-test-result');

			if (!endpoint) {
				alert('Veuillez saisir une URL Endpoint CDN avant de tester.');
				$('#image_cdn_endpoint').focus();
				return;
			}

			$btn.prop('disabled', true).addClass('loading');
			$res.show().html('<div class="woo-meta-cdn-alert woo-meta-cdn-alert-info" style="display:flex; align-items:center; gap:8px;"><span class="spinner is-active" style="float:none; margin: 0;"></span> <span>Test de téléchargement CDN via le User-Agent de Meta...</span></div>');

			$.ajax({
				url: wooMetaCatalogVars.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'woo_meta_catalog_test_cdn',
					security: wooMetaCatalogVars.nonce,
					endpoint: endpoint,
					provider: provider,
					auto_square: autoSquare,
					force_jpeg: forceJpeg
				},
				success: function(response) {
					$btn.prop('disabled', false).removeClass('loading');
					if (response.success && response.data) {
						var d = response.data;
						var html = '<div class="woo-meta-cdn-alert woo-meta-cdn-alert-success">';
						html += '<p style="margin: 0; color: #065f46; font-size: 13px; font-weight: 600;"><span class="dashicons dashicons-yes" style="vertical-align: middle; color: #10b981; font-size: 18px; width: 18px; height: 18px; margin-right: 4px;"></span> ' + d.message + '</p>';
						if (d.cdn_url) {
							html += '<div style="margin-top: 10px; display: flex; align-items: center; gap: 14px; background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #d1fae5;">';
							html += '<img src="' + d.cdn_url + '" alt="Aperçu CDN" style="width: 70px; height: 70px; object-fit: contain; border: 1px solid #e5e7eb; background: #f9fafb; border-radius: 4px; flex-shrink: 0;" />';
							html += '<div style="font-size: 12px; color: #047857; word-break: break-all; line-height: 1.5;">';
							html += '<div><strong>Produit testé :</strong> ' + (d.product_name || 'Échantillon') + '</div>';
							html += '<div style="margin-top: 4px;"><strong>URL CDN délivrée :</strong> <a href="' + d.cdn_url + '" target="_blank" rel="noopener" style="color: #059669; text-decoration: underline; font-family: monospace;">' + d.cdn_url + '</a></div>';
							html += '</div></div>';
						}
						html += '</div>';
						$res.html(html).show();
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : 'Erreur inconnue lors du test.';
						var html = '<div class="woo-meta-cdn-alert woo-meta-cdn-alert-error">';
						html += '<p style="margin: 0; color: #991b1b; font-size: 13px; font-weight: 600;"><span class="dashicons dashicons-warning" style="vertical-align: middle; color: #ef4444; font-size: 18px; width: 18px; height: 18px; margin-right: 4px;"></span> ' + msg + '</p>';
						html += '</div>';
						$res.html(html).show();
					}
				},
				error: function(xhr, status, error) {
					$btn.prop('disabled', false).removeClass('loading');
					$res.html('<div class="woo-meta-cdn-alert woo-meta-cdn-alert-error"><p style="margin: 0; color: #991b1b; font-size: 13px; font-weight: 600;">Erreur de communication AJAX avec le serveur (' + (error || status) + ').</p></div>').show();
				}
			});
		});

		// 8. Run Diagnostic Express Live Sample Test (Dry Run)
		$('#btn-run-diag-test').on('click', function(e) {
			e.preventDefault();
			var $btn     = $(this);
			var $wrap    = $('#diag-test-results-placeholder');
			var $spinner = $wrap.find('.diag-test-spinner');
			var $output  = $wrap.find('.diag-test-output');

			$btn.prop('disabled', true).addClass('loading');
			$wrap.show();
			$spinner.show();
			$output.hide().empty();

			$.ajax({
				url: wooMetaCatalogVars.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'woo_meta_catalog_run_diagnostic_test',
					security: wooMetaCatalogVars.nonce,
					count: 5
				},
				success: function(response) {
					$btn.prop('disabled', false).removeClass('loading');
					$spinner.hide();

					if (response.success && response.data) {
						var d          = response.data;
						var isSuccess  = d.success;
						var alertClass = isSuccess ? 'diag-alert-success' : 'diag-alert-warning';
						var iconClass  = isSuccess ? 'dashicons-yes-alt' : 'dashicons-warning';

						var html = '<div class="diag-test-alert ' + alertClass + '">';
						html += '<div class="alert-top">';
						html += '<span class="dashicons ' + iconClass + '" style="font-size:22px; width:22px; height:22px; vertical-align:middle; margin-right:6px;"></span>';
						html += '<strong>' + (d.message || 'Test terminé.') + '</strong>';
						html += '</div>';

						// Metrics grid
						html += '<div class="diag-test-metrics-grid">';
						html += '<div class="metric-pill"><span>Durée :</span> <strong>' + d.duration_ms + ' ms</strong></div>';
						html += '<div class="metric-pill"><span>Produits traités :</span> <strong>' + d.products_tested + ' parents (' + d.variations_tested + ' var.)</strong></div>';
						html += '<div class="metric-pill"><span>Delta RAM consommé :</span> <strong>+' + d.memory_delta + '</strong></div>';
						html += '<div class="metric-pill"><span>Pic mémoire RAM :</span> <strong>' + d.memory_peak + '</strong></div>';
						html += '</div>';

						if (d.errors && d.errors.length > 0) {
							html += '<div class="diag-test-errors-list">';
							html += '<h5>Erreurs rencontrées sur le lot échantillon :</h5><ul>';
							$.each(d.errors, function(i, err) {
								html += '<li>' + err + '</li>';
							});
							html += '</ul></div>';
						}

						if (d.sample_xml) {
							html += '<div class="diag-test-xml-preview">';
							html += '<button type="button" class="button button-secondary button-small btn-toggle-xml" style="margin-top: 10px;">';
							html += '<span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 2px;"></span> <span class="toggle-text">Inspecter le balisage XML échantillon</span>';
							html += '</button>';
							html += '<pre class="diag-xml-snippet" style="display: none; margin-top: 10px; max-height: 250px; overflow-y: auto; background: #0f172a; color: #f8fafc; padding: 12px; border-radius: 6px; font-size: 11px; font-family: monospace;"><code>' + escapeHtml(d.sample_xml) + '</code></pre>';
							html += '</div>';
						}

						html += '</div>';
						$output.html(html).slideDown(200);
					} else {
						var errMsg = (response.data && response.data.message) ? response.data.message : 'Erreur inconnue lors du test express.';
						$output.html('<div class="diag-test-alert diag-alert-error"><span class="dashicons dashicons-dismiss" style="vertical-align:middle; margin-right:4px;"></span> ' + errMsg + '</div>').slideDown(200);
					}
				},
				error: function(xhr, status, error) {
					$btn.prop('disabled', false).removeClass('loading');
					$spinner.hide();
					var errMsg = (xhr.status === 500) ? 'Erreur 500 : saturation mémoire ou timeout serveur.' : (error || status || 'Serveur indisponible');
					$output.html('<div class="diag-test-alert diag-alert-error"><span class="dashicons dashicons-dismiss" style="vertical-align:middle; margin-right:4px;"></span> Erreur serveur lors du test : ' + errMsg + '</div>').slideDown(200);
				}
			});
		});

		// Toggle XML snippet
		$(document).on('click', '.btn-toggle-xml', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var $pre = $btn.siblings('.diag-xml-snippet');
			$pre.slideToggle(200, function() {
				if ($pre.is(':visible')) {
					$btn.find('.toggle-text').text('Masquer l\'extrait XML');
				} else {
					$btn.find('.toggle-text').text('Inspecter le balisage XML échantillon');
				}
			});
		});

		// 9. Clear Diagnostic Error Logs
		$(document).on('click', '#btn-clear-logs, .btn-clear-logs-secondary', function(e) {
			e.preventDefault();
			if (!confirm('Voulez-vous vraiment vider l\'historique des incidents de génération ?')) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true);

			$.ajax({
				url: wooMetaCatalogVars.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'woo_meta_catalog_clear_logs',
					security: wooMetaCatalogVars.nonce
				},
				success: function(response) {
					$btn.prop('disabled', false);
					if (response.success) {
						$('#diag-log-count-val').text('0');
						$('.diag-tab-badge').remove();
						$('#btn-clear-logs').fadeOut(200);
						$('#btn-clear-logs-header').fadeOut(200);
						$('.diag-logs-wrapper').html('<div class="diag-empty-logs"><span class="dashicons dashicons-yes-alt"></span><h3>Journal des incidents vidé</h3><p>Le journal est désormais vierge. Aucune erreur n\'est enregistrée.</p></div>');
					}
				},
				error: function() {
					$btn.prop('disabled', false);
					alert('Erreur lors de la suppression des logs.');
				}
			});
		});

		// 10. Trending Settings & Realtime Recalculation
		$('#label_enable_trending').on('change', function() {
			if ($(this).is(':checked')) {
				$('#woo-meta-trending-settings-row').show();
				$('#woo-meta-trending-settings-wrap').slideDown(200);
			} else {
				$('#woo-meta-trending-settings-wrap').slideUp(200, function() {
					$('#woo-meta-trending-settings-row').hide();
				});
			}
		});

		function setTrendingMode(mode, animate) {
			if (mode === 'seasonal') {
				if (animate) {
					$('#trending-fields-classic').slideUp(150);
					$('#trending-fields-seasonal').slideDown(200);
				} else {
					$('#trending-fields-classic').hide();
					$('#trending-fields-seasonal').show();
				}
				$('#trending-fields-classic :input').prop('disabled', true);
				$('#trending-fields-seasonal :input').prop('disabled', false);
			} else {
				if (animate) {
					$('#trending-fields-seasonal').slideUp(150);
					$('#trending-fields-classic').slideDown(200);
				} else {
					$('#trending-fields-seasonal').hide();
					$('#trending-fields-classic').show();
				}
				$('#trending-fields-seasonal :input').prop('disabled', true);
				$('#trending-fields-classic :input').prop('disabled', false);
			}
		}

		$('.trending-mode-radio').on('change', function() {
			var mode = $('input[name="label_trending_mode"]:checked').val() || 'classic';
			setTrendingMode(mode, true);
		});

		// Initialize disabled state on page load
		var initialTrendingMode = $('input[name="label_trending_mode"]:checked').val() || 'classic';
		setTrendingMode(initialTrendingMode, false);

		$('#label_trending_classic_enable_atc').on('change', function() {
			if ($(this).is(':checked')) {
				$('#trending-classic-atc-wrap').slideDown(150);
			} else {
				$('#trending-classic-atc-wrap').slideUp(150);
			}
		});

		function updateClassicAtcDesc() {
			var ratio = parseFloat($('#label_trending_classic_atc_ratio').val()) || 0;
			var total = parseInt($('#label_trending_count_classic').val(), 10) || 35;
			var maxCount = Math.round(total * (ratio / 100.0));
			$('#label_trending_classic_atc_ratio_desc').text('Soit jusqu\'à ' + maxCount + ' produits sur ' + total);
		}

		$('#label_trending_classic_atc_ratio, #label_trending_count_classic').on('input', updateClassicAtcDesc);

		$('#label_trending_enable_atc').on('change', function() {
			if ($(this).is(':checked')) {
				$('#trending-atc-weight-wrap').slideDown(150);
			} else {
				$('#trending-atc-weight-wrap').slideUp(150);
			}
		});

		$('#label_trending_enable_cat_fallback').on('change', function() {
			if ($(this).is(':checked')) {
				$('#trending-cat-fallback-options').slideDown(150);
			} else {
				$('#trending-cat-fallback-options').slideUp(150);
			}
		});

		$('#label_trending_recent_ratio').on('input', function() {
			var val = parseFloat($(this).val()) || 0;
			var prevVal = Math.max(0, 100 - val);
			$('#label_trending_prev_ratio_desc').text('Part période année N-1 : ' + Math.round(prevVal) + '%');
		});

		$(document).on('click', '#btn-recalculate-trending', function(e) {
			e.preventDefault();
			var $btn = $(this);
			if ($btn.hasClass('loading')) {
				return;
			}

			var $text = $btn.find('.btn-text');
			var originalText = $text.text();

			$btn.addClass('loading').prop('disabled', true);
			$text.text('Recalcul en cours...');

			$.ajax({
				url: wooMetaCatalogVars.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'woo_meta_catalog_recalculate_trending',
					security: wooMetaCatalogVars.nonce
				},
				success: function(response) {
					$btn.removeClass('loading').prop('disabled', false);
					$text.text(originalText);

					if (response.success && response.data && response.data.html) {
						$('#woo-meta-trending-preview-box').replaceWith(response.data.html);
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : 'Erreur lors du recalcul des tendances.';
						alert(msg);
					}
				},
				error: function(xhr, status, error) {
					$btn.removeClass('loading').prop('disabled', false);
					$text.text(originalText);
					alert('Erreur serveur lors du recalcul des tendances : ' + (error || status || 'Serveur indisponible'));
				}
			});
		});

		function escapeHtml(text) {
			return $('<div>').text(text).html();
		}

		function stopLoading($btn) {
			$btn.removeClass('loading').prop('disabled', false);
		}

	});

})(jQuery);
