/**
 * Admin JavaScript for Woo Meta Catalog Feed Soyoo
 * Author: SOYOO
 */

(function($) {
	'use strict';

	var pollInterval = null;

	$(document).ready(function() {

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

		// 3. Trigger Manual Generation via AJAX
		$('#btn-trigger-generation').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);

			if ($btn.hasClass('loading')) {
				return;
			}

			$btn.addClass('loading').prop('disabled', true);
			$('#generation-progress-wrap').fadeIn(200);
			$('#generation-progress-bar').css('width', '5%');
			$('#generation-progress-msg').text('Démarrage du traitement par lots Action Scheduler...');

			$.ajax({
				url: wooMetaCatalogVars.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'woo_meta_catalog_trigger_generation',
					security: wooMetaCatalogVars.nonce
				},
				success: function(response) {
					if (response.success) {
						startStatusPolling();
					} else {
						stopLoading($btn);
						alert(response.data && response.data.message ? response.data.message : 'Erreur lors du déclenchement.');
					}
				},
				error: function(xhr, status, error) {
					stopLoading($btn);
					alert('Erreur serveur lors de la requête de régénération: ' + error);
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

		// 5. Polling Loop
		function startStatusPolling() {
			clearInterval(pollInterval);
			pollInterval = setInterval(function() {
				$.ajax({
					url: wooMetaCatalogVars.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'woo_meta_catalog_check_status',
						security: wooMetaCatalogVars.nonce
					},
					success: function(response) {
						if (response.success && response.data) {
							updateProgressUI(response.data);
						}
					}
				});
			}, 2000);
		}

		function updateProgressUI(data) {
			var status   = data.status || 'idle';
			var progress = parseInt(data.progress, 10) || 0;
			var message  = data.message || '';

			$('#generation-progress-bar').css('width', progress + '%');
			$('#generation-progress-msg').text(message);

			if (status === 'completed') {
				clearInterval(pollInterval);
				$('#generation-progress-bar').css('width', '100%');
				stopLoading($('#btn-trigger-generation'));

				// Update metrics in status boxes
				if (data.total_items) {
					$('#status-items-val').text(data.total_items + ' items');
				}
				if (data.file_size_human) {
					$('#status-size-val').text(data.file_size_human);
				}
				if (data.duration) {
					$('#status-duration-val').text(data.duration + ' s');
				}
				$('#status-state-val').html('<span class="badge-status-ok"><span class="dashicons dashicons-yes"></span> Flux généré et valide</span>');
				$('#status-date-val').text('À l\'instant');

				setTimeout(function() {
					$('#generation-progress-wrap').fadeOut(500);
				}, 4000);

			} else if (status === 'failed') {
				clearInterval(pollInterval);
				stopLoading($('#btn-trigger-generation'));
				$('#generation-progress-msg').html('<span style="color:#dc2626;">' + (data.error_message || 'Échec de la génération') + '</span>');
			}
		}

		function stopLoading($btn) {
			$btn.removeClass('loading').prop('disabled', false);
		}

	});

})(jQuery);
