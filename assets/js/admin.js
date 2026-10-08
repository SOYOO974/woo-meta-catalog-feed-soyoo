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
									alert(chunkRes.data && chunkRes.data.message ? chunkRes.data.message : 'Erreur sur le lot ' + (step + 1));
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
								alert('Erreur serveur lors du traitement du lot ' + (step + 1) + ' : ' + error);
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
									alert(finRes.data && finRes.data.message ? finRes.data.message : 'Erreur lors de la finalisation.');
								}
							},
							error: function(xhr, status, error) {
								stopLoading($btn);
								alert('Erreur serveur lors de la finalisation : ' + error);
							}
						});
					}

					// Start chunk 0
					processChunk(0);
				},
				error: function(xhr, status, error) {
					stopLoading($btn);
					alert('Erreur serveur lors de l\'initialisation : ' + error);
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

		function stopLoading($btn) {
			$btn.removeClass('loading').prop('disabled', false);
		}

	});

})(jQuery);
