/**
 * YPF Subscriptions Accounts Table Script
 * Handles reset button functionality for subscription accounts table on My Account page
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Get AJAX data from localized script
        var ajaxData = {
            ajaxUrl: (typeof ypfSubscriptionsAccounts !== 'undefined') ? ypfSubscriptionsAccounts.ajaxUrl : '',
            nonce: (typeof ypfSubscriptionsAccounts !== 'undefined') ? ypfSubscriptionsAccounts.nonce : ''
        };

        /**
         * Move subscription accounts table to woocommerce-MyAccount-content
         * when body has class woocommerce-dashboard
         */
        function moveTableToMyAccountContent() {
            // Check if body has woocommerce-dashboard class
            if (!$('body').hasClass('woocommerce-dashboard')) {
                return;
            }

            // Find the hidden table container
            var $tableContainer = $('.ypf-subscriptions-accounts-table-container');
            if ($tableContainer.length === 0) {
                return;
            }

            // Find woocommerce-MyAccount-content
            var $myAccountContent = $('.woocommerce-MyAccount-content');
            if ($myAccountContent.length === 0) {
                return;
            }

            // Check if table already moved
            if ($myAccountContent.find('.ypf-subscriptions-accounts-table-wrapper').length > 0) {
                return;
            }

            // Get the table wrapper from container
            var $tableWrapper = $tableContainer.find('.ypf-subscriptions-accounts-table-wrapper');
            if ($tableWrapper.length === 0) {
                return;
            }

            // Show and move table to MyAccount content (prepend to show at top)
            $tableWrapper.show();
            $myAccountContent.prepend($tableWrapper);
            
            // Remove the now-empty container
            $tableContainer.remove();
        }

        // Try to move table immediately
        moveTableToMyAccountContent();

        // Also try after a short delay in case elements are loaded dynamically
        setTimeout(function() {
            moveTableToMyAccountContent();
        }, 500);

        // Also try after a longer delay for slower page loads
        setTimeout(function() {
            moveTableToMyAccountContent();
        }, 1500);

        var confirmResetMsg = '<b>Are you sure you want to reset the drawdown for this account?</b><br><br>Your drawdown will be reset to 0.';

        function confirmReset() {
            if (typeof window.ypfCustomConfirm === 'function') {
                return window.ypfCustomConfirm(confirmResetMsg);
            }
            return Promise.resolve(window.confirm('Apakah Anda yakin ingin melakukan Reset Drawdown akun ini?'));
        }

        /**
         * Handle reset link button (dashboard table - class ypf-drawdown-reset-btn)
         * Runs even when AJAX data is not set.
         */
        $(document).on('click', '.ypf-drawdown-reset-btn', function(e) {
            var href = $(this).attr('href');
            if (!href || href === '#') return;
            e.preventDefault();
            e.stopPropagation();
            confirmReset().then(function(ok) {
                if (ok) window.open(href, '_blank');
            });
        });

        if (!ajaxData.ajaxUrl || !ajaxData.nonce) {
            return;
        }

        /**
         * Handle reset button click
         */
        $(document).on('click', '.ypf-reset-button', function(e) {
            e.preventDefault();
            var $button = $(this);

            confirmReset().then(function(ok) {
                if (!ok) return;
            var $row = $button.closest('tr');
            
            // Get data attributes
            var userId = $row.data('user-id');
            var accountId = $row.data('account-id');
            var subscriptionId = $row.data('subscription-id');

            if (!userId || !accountId) {
                alert('Missing required data. Please refresh the page and try again.');
                return;
            }

            // Disable button during request
            $button.prop('disabled', true);
            var originalText = $button.text();
            $button.text('Processing...');

            // Remove any existing messages
            $row.find('.ypf-reset-message').remove();

            // Make AJAX request
            $.ajax({
                url: ajaxData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ypf_reset_drawdown',
                    user_id: userId,
                    account_id: accountId,
                    nonce: ajaxData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        var $message = $('<span>', {
                            class: 'ypf-reset-message success',
                            text: response.data.message || 'Drawdown reset successfully!'
                        });
                        $button.after($message);

                        // Optionally reload page after 2 seconds to show updated data
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to reset drawdown.';
                        var $message = $('<span>', {
                            class: 'ypf-reset-message error',
                            text: errorMsg
                        });
                        $button.after($message);

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    // Show error message
                    var $message = $('<span>', {
                        class: 'ypf-reset-message error',
                        text: 'An error occurred. Please try again later.'
                    });
                    $button.after($message);

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);

                    console.error('YPF Reset Drawdown Error:', error);
                }
            });
            });
        });
    });
})(jQuery);
