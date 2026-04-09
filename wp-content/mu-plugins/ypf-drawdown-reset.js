/**
 * YPF Drawdown Reset Button Script
 * Handles the reset button functionality in subscription details table
 */
(function($) {
    'use strict';

    var DRAWDOWN_REGEX = /Drawdown[:\s]+([-\d.]+)%/i;

    function parseDrawdownValue(str) {
        if (!str || typeof str !== 'string') return NaN;
        var val = parseFloat(String(str).replace(/%/g, '').replace(/,/g, ''));
        return isNaN(val) ? NaN : val;
    }

    function extractDrawdownFromText(text) {
        var m = text && text.match(DRAWDOWN_REGEX);
        return m && m[1] ? parseDrawdownValue(m[1]) : NaN;
    }

    $(document).ready(function() {
        var ypf = typeof ypfDrawdownReset !== 'undefined' ? ypfDrawdownReset : {};
        var resetData = {
            userId: ypf.userId || '',
            accountId: ypf.accountId || '',
            productId: ypf.productId || '',
            checkoutUrl: ypf.checkoutUrl || '',
            drawdownThreshold: (typeof ypf.drawdownThreshold !== 'undefined' && !isNaN(parseFloat(ypf.drawdownThreshold)))
                ? parseFloat(ypf.drawdownThreshold) : 4.5
        };

        function getDrawdownFromHTML() {
            var $el = $('.ypf-drawdown-critical');
            if ($el.length) {
                var val = parseDrawdownValue($el.text().trim());
                if (!isNaN(val)) return val;
            }
            $el = $('p:contains("Drawdown:")');
            if ($el.length) {
                var val = extractDrawdownFromText($el.text());
                if (!isNaN(val)) return val;
            }
            $el = $('.ypf-account-basic-info');
            if ($el.length) {
                var val = extractDrawdownFromText($el.text());
                if (!isNaN(val)) return val;
            }
            return null;
        }

        /**
         * Function to add reset button to subscription details table (only 1 button at the end)
         */
        function addResetButtonToTable() {
            if ($('.ypf-drawdown-reset-btn').length) return;

            var $lastTd = $('table.shop_table.subscription_details').find('tr:last td:last');
            if (!$lastTd.length) return;

            var drawdownValue = getDrawdownFromHTML();
            var threshold = 4.5;
            var $thresholdEl = $('.ypf-account-details-item[data-ypf-drawdown-threshold]');
            if ($thresholdEl.length) {
                var t = parseFloat($thresholdEl.attr('data-ypf-drawdown-threshold'));
                if (!isNaN(t) && t > 0) threshold = t;
            } else if (!isNaN(resetData.drawdownThreshold) && resetData.drawdownThreshold > 0) {
                threshold = resetData.drawdownThreshold;
            }

            var checkoutUrl = $('.ypf-account-details-item[data-ypf-checkout-url]').attr('data-ypf-checkout-url') || '';
            if (!checkoutUrl) checkoutUrl = resetData.checkoutUrl;
            if (!checkoutUrl && resetData.productId) {
                var origin = (typeof window.location !== 'undefined' && window.location.origin) ? window.location.origin : '';
                checkoutUrl = origin + '/checkout/?add-to-cart=' + resetData.productId;
            }

            if (drawdownValue !== null) {
                if (drawdownValue >= -threshold) {
                    console.log('YPF Drawdown Reset: Drawdown is ' + drawdownValue + '%, not showing button (must be < -' + threshold + '%)');
                    return;
                }
                console.log('YPF Drawdown Reset: Drawdown is ' + drawdownValue + '%, showing button (threshold: ' + threshold + '%)');
            } else {
                console.log('YPF Drawdown Reset: Cannot find drawdown in HTML, not showing button for safety');
                return;
            }

            // Check if checkout URL is available (needed for the link)
            if (!checkoutUrl) {
                return;
            }

            // Create reset button as link
            var $resetBtn = $('<a>', {
                href: checkoutUrl,
                target: '_blank',
                class: 'woocommerce-button ypf-drawdown-reset-btn',
                text: 'Reset Drawdown',
                css: {
                    'background': '#017FDD',
                    'color': '#fff',
                    'display': 'inline-block',
                    'padding': '12px 30px',
                    'text-decoration': 'none',
                    'border-radius': '4px',
                    'font-weight': '500',
                    'line-height': '14px',
                    'transition': 'all 0.3s ease',
                    'cursor': 'pointer',
                    'text-align': 'center'
                }
            });

            // Add hover effect (primary blue)
            $resetBtn.on('mouseenter', function() {
                $(this).css({
                    'background': '#0169c4',
                    'box-shadow': '0 4px 8px rgba(1, 127, 221, 0.3)'
                });
            }).on('mouseleave', function() {
                $(this).css({
                    'background': '#017FDD',
                    'box-shadow': 'none'
                });
            });

            // Confirmation popup on click (same as ypf-subscriptions-accounts-table)
            var confirmResetMsg = '<b>Are you sure you want to reset the drawdown for this account?</b><br><br>Your drawdown will be reset to 0.';
            function confirmReset() {
                if (typeof window.ypfCustomConfirm === 'function') {
                    return window.ypfCustomConfirm(confirmResetMsg);
                }
                return Promise.resolve(window.confirm('Are you sure you want to reset the drawdown for this account? Your drawdown will be reset to 0.'));
            }
            $resetBtn.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var url = $(this).attr('href');
                if (!url || url === '#') return;
                confirmReset().then(function(ok) {
                    if (ok && url) window.open(url, '_blank');
                });
            });

            $resetBtn.attr({
                'data-user-id': resetData.userId,
                'data-account-id': resetData.accountId,
                'data-product-id': resetData.productId
            });

            // Add button to last td
            $lastTd.append($resetBtn);
        }

        var tryAdd = addResetButtonToTable;
        tryAdd();
        setTimeout(tryAdd, 500);
        setTimeout(tryAdd, 1500);
    });
})(jQuery);
