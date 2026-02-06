/**
 * WP Jamstack Sync - Admin JavaScript
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Test connection button
		$('#wpjamstack-test-connection').on('click', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var $result = $('#wpjamstack-test-result');
			
			// Disable button
			$button.prop('disabled', true);
			$result.html('<span class="wpjamstack-test-result testing">' + wpjamstackAdmin.strings.testing + '</span>');
			
			// Make AJAX request
			$.ajax({
				url: wpjamstackAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wpjamstack_test_connection',
					nonce: wpjamstackAdmin.testConnectionNonce
				},
				success: function(response) {
					if (response.success) {
						$result.html('<span class="wpjamstack-test-result success">✓ ' + response.data.message + '</span>');
					} else {
						$result.html('<span class="wpjamstack-test-result error">✗ ' + wpjamstackAdmin.strings.error + ' ' + response.data.message + '</span>');
					}
				},
				error: function() {
					$result.html('<span class="wpjamstack-test-result error">✗ ' + wpjamstackAdmin.strings.error + ' Network error</span>');
				},
				complete: function() {
					$button.prop('disabled', false);
				}
			});
		});
	});

})(jQuery);
