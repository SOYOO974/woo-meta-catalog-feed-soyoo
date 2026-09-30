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

		function stopLoading($btn) {
			$btn.removeClass('loading').prop('disabled', false);
		}

	});

})(jQuery);
