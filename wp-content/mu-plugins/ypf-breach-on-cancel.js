jQuery(document).ready(function($){

    /* =====================================
       1. Inject Custom Modal (CFM = Confirm)
    ===================================== */

    if ($("#cfm-modal").length === 0) {
        $("body").append(`
            <div id="cfm-modal" class="cfm-overlay">
                <div class="cfm-box">
                    <div class="cfm-message"></div>
                    <div class="cfm-actions">
                        <button type="button" class="cfm-btn cfm-cancel">Cancel</button>
                        <button type="button" class="cfm-btn cfm-confirm">Yes, Continue</button>
                    </div>
                </div>
            </div>
        `);
    }

    $("<style>").text(`
        .cfm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 999999;
        }

        .cfm-box {
            background: #ffffff;
            width: 90%;
            max-width: 420px;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            animation: cfmFade 0.2s ease;
        }

        .cfm-message {
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        .cfm-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
        }

        .cfm-btn {
            padding: 9px 18px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            min-width: 110px;
            transition: 0.3s ease;
        }

        .cfm-cancel {
            background-color: #e9ecef;
	        color: #017FDD;
        }

        .cfm-cancel:hover {
            background: #cfcfcf;
            color: #017FDD;
        }

        .cfm-confirm {
            background: #017FDD;
            color: #fff;
        }

        .cfm-confirm:hover {
            background: #0166B0;
        }

        @keyframes cfmFade {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
    `).appendTo("head");


    /* =====================================
       2. Custom Confirm Function
    ===================================== */

    function customConfirm(message){
        return new Promise(function(resolve){

            var $modal = $("#cfm-modal");

            $modal.find(".cfm-message").html(message);
            $modal.fadeIn(150).css("display","flex");

            // Helper: bersihkan overlay/blockUI pada tabel subscription_details saja
            function clearBlockUI(){
                var $scope = $(".shop_table.subscription_details").first();
                if ($scope.length && typeof $scope.unblock === "function") {
                    $scope.unblock();
                }
                // Hapus overlay yang mungkin masih tersisa di dalam tabel
                $scope.find(".blockUI.blockOverlay, .blockUI.blockMsg").remove();
            }

            $modal.find(".cfm-confirm").one("click", function(){
                $modal.fadeOut(150);
                clearBlockUI();
                resolve(true);
            });

            $modal.find(".cfm-cancel").one("click", function(){
                $modal.fadeOut(150);
                clearBlockUI();
                resolve(false);
            });

            $modal.one("click", function(e){
                if($(e.target).is("#cfm-modal")){
                    $modal.fadeOut(150);
                    clearBlockUI();
                    resolve(false);
                }
            });

        });
    }


    /* =====================================
       3. Intercept WooCommerce Cancel
    ===================================== */

    var cancelSelectors = "a.button.cancel, a.woocommerce-button.button.cancel, a.woocommerce-button.button.cancel.wcs_block_ui_on_click";

    $(document).on("click", cancelSelectors, function(e){

        var $button = $(this);
        var originalHref = $button.attr("href");

        e.preventDefault();
        e.stopImmediatePropagation();

        var message = "<b>Are you sure you want to cancel your subscription?</b><br><br>Your trading account will be permanently closed. You won’t be able to reactivate it, and all progress will be lost.";

        customConfirm(message).then(function(confirmed){

            if(!confirmed) return;

            var userEmail = typeof WCSViewSubscription !== "undefined" ? WCSViewSubscription.user_email : "";
            var subscriptionId = typeof WCSViewSubscription !== "undefined" ? WCSViewSubscription.subscription_id : "";

            // Tampilkan blockUI hanya di tabel shop_table.subscription_details
            var $scope = $(".shop_table.subscription_details").first();
            if ($scope.length && typeof $scope.block === "function") {
                $scope.block({
                    message: null,
                    overlayCSS: {
                        background: "#fff",
                        opacity: 0.6
                    }
                });
            }

            var originalText = $button.text();
            $button.prop("disabled", true).text("Processing...");

            var breachUrl = "/unsub";
            var params = [];

            if(subscriptionId){
                params.push("subscription_id=" + encodeURIComponent(subscriptionId));
            } else if(userEmail){
                params.push("email=" + encodeURIComponent(userEmail));
            }

            if(params.length){
                breachUrl += "?" + params.join("&");
            }

            $.ajax({
                url: breachUrl,
                method: "GET",
                dataType: "json",
                timeout: 30000,

                success: function(response){
                    window.location.href = originalHref;
                },

                error: function(){
                    customConfirm("Breach request failed.<br><br>Continue cancellation anyway?")
                        .then(function(force){
                            if(force){
                                window.location.href = originalHref;
                            } else {
                                $button.prop("disabled", false).text(originalText);
                            }
                        });
                }
            });

        });

    });

    // Expose for reset drawdown (and other) confirmations
    if (typeof window.ypfCustomConfirm === "undefined") {
        window.ypfCustomConfirm = customConfirm;
    }

});
