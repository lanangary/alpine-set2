/**
 * YPF Account ID Table Script
 * Adds Account ID row (with login value) to subscription details table
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Get account login from localized script
        var accountLogin = (typeof ypfAccountId !== 'undefined') ? ypfAccountId.accountLogin : '';

        if (!accountLogin) {
            return;
        }

        /**
         * Function to add Account ID row to subscription details table (at first position)
         */
        function addAccountIdRow() {
            // Check if row already exists
            if ($('table.shop_table.subscription_details tr.ypf-account-id-row').length > 0) {
                return;
            }

            // Find the shop_table subscription_details
            var $table = $('table.shop_table.subscription_details');
            
            if ($table.length === 0) {
                $table = $('table.subscription_details');
            }
            
            if ($table.length === 0) {
                return;
            }

            // Find the first row (tr) in the table body or table itself
            var $firstRow = $table.find('tbody tr').first();
            if ($firstRow.length === 0) {
                $firstRow = $table.find('tr').first();
            }

            if ($firstRow.length === 0) {
                return;
            }

            // Create new row for Account ID
            var $newRow = $('<tr>', {
                class: 'ypf-account-id-row'
            });

            // Create first td with label
            var $labelTd = $('<td>').html('<strong>Account ID</strong>');
            
            // Create second td with account login value
            var $valueTd = $('<td>').text(accountLogin);

            // Append tds to row
            $newRow.append($labelTd).append($valueTd);

            // Insert before the first row (at the beginning)
            $firstRow.before($newRow);
        }

        // Try to add row immediately
        addAccountIdRow();

        // Also try after a short delay in case table is loaded dynamically
        setTimeout(function() {
            addAccountIdRow();
        }, 500);

        // Also try after a longer delay for slower page loads
        setTimeout(function() {
            addAccountIdRow();
        }, 1500);
        
        // Try one more time after 3 seconds
        setTimeout(function() {
            addAccountIdRow();
        }, 3000);
    });
})(jQuery);
