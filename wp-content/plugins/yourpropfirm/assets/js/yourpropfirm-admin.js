(function( $ ) {
    'use strict';

    /**
     * All of the code for your public-facing JavaScript source
     * should reside in this file.
     *
     * Note: It has been assumed you will write jQuery code here, so the
     * $ function reference has been prepared for usage within the scope
     * of this function.
     *
     * This enables you to define handlers, for when the DOM is ready:
     *
     * $(function() {
     *
     * });
     *
     * When the window is loaded:
     *
     * $( window ).load(function() {
     *
     * });
     *
     * ...and/or other possibilities.
     *
     * Ideally, it is not considered best practise to attach more than a
     * single DOM-ready or window-load handler for a particular page.
     * Although scripts in the WordPress core, Plugins and Themes may be
     * practising this, we should strive to set a better example in our own work.
     */

    jQuery(document).ready(function($) {
        // Initial check on page load
        checkSelectionType();

        // Check when selection type changes
        $('#_yourpropfirm_selection_type').change(function() {
            checkSelectionType();
        });

        function checkSelectionType() {
            var selectionType = $('#_yourpropfirm_selection_type').val();

            if (selectionType === 'challenge') {
                $('._yourpropfirm_program_id_field').show();
                $('._yourpropfirm_competition_id_field').hide();
            } else if (selectionType === 'competition') {
                $('._yourpropfirm_program_id_field').hide();
                $('._yourpropfirm_competition_id_field').show();
            } else {
                $('._yourpropfirm_program_id_field').hide();
                $('._yourpropfirm_competition_id_field').hide();
            }
        }

        $('#the-list').on('click', '.editinline', function() {
            var post_id = $(this).closest('tr').attr('id');
            post_id = post_id.replace("post-", "");

            var program_id = $('#yourpropfirm_program_id-' + post_id).text();

            $(':input[name="_yourpropfirm_program_id"]').val(program_id);
        });

    });

})( jQuery );
