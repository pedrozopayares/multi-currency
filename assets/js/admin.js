/**
 * Multi Currency – Admin JS
 *
 * Handles:
 *  - Dynamic rows for currencies & language mappings
 *  - Toggle-dependent field visibility
 */
(function ($) {
    'use strict';

    /* ── Currency rows ──────────────────────────────────── */

    var currencyIdx = $('#imc-currencies-body tr').length;

    $('#imc-add-currency').on('click', function () {
        var i = currencyIdx;
        var row =
            '<tr>' +
                '<td><input type="text" name="imc_currency[' + i + '][code]" value="" class="small-text" maxlength="3" placeholder="USD" required style="text-transform:uppercase"></td>' +
                '<td><input type="text" name="imc_currency[' + i + '][name]" value="" placeholder="US Dollar"></td>' +
                '<td><input type="text" name="imc_currency[' + i + '][symbol]" value="" class="small-text" maxlength="5" placeholder="$"></td>' +
                '<td>' +
                    '<select name="imc_currency[' + i + '][position]">' +
                        '<option value="left">Izquierda ($99)</option>' +
                        '<option value="right">Derecha (99$)</option>' +
                        '<option value="left_space">Izq. espacio ($ 99)</option>' +
                        '<option value="right_space">Der. espacio (99 $)</option>' +
                    '</select>' +
                '</td>' +
                '<td><input type="number" name="imc_currency[' + i + '][decimals]" value="2" class="small-text" min="0" max="6"></td>' +
                '<td><input type="text" name="imc_currency[' + i + '][decimal_sep]" value="." class="small-text" maxlength="1"></td>' +
                '<td><input type="text" name="imc_currency[' + i + '][thousand_sep]" value="," class="small-text" maxlength="1"></td>' +
                '<td><button type="button" class="button imc-remove-row" title="Eliminar">✕</button></td>' +
            '</tr>';

        $('#imc-currencies-body').append(row);
        currencyIdx++;
    });

    /* ── Language-mapping rows ──────────────────────────── */

    var mappingIdx = $('#imc-lang-map-body tr').length;

    $('#imc-add-mapping').on('click', function () {
        var i = mappingIdx;
        var row =
            '<tr>' +
                '<td><input type="text" name="imc_lang[' + i + '][lang]" value="" class="small-text" maxlength="5" placeholder="en"></td>' +
                '<td><input type="text" name="imc_lang[' + i + '][currency]" value="" class="small-text" maxlength="3" placeholder="USD" style="text-transform:uppercase"></td>' +
                '<td><button type="button" class="button imc-remove-row" title="Eliminar">✕</button></td>' +
            '</tr>';

        $('#imc-lang-map-body').append(row);
        mappingIdx++;
    });

    /* ── Remove any row ─────────────────────────────────── */

    $(document).on('click', '.imc-remove-row', function () {
        $(this).closest('tr').fadeOut(200, function () {
            $(this).remove();
        });
    });

    /* ── Toggle-dependent rows ──────────────────────────── */

    /**
     * When a toggle checkbox changes, show/hide rows that depend on it.
     * Convention: the toggle input has id="imc-enable-float"
     *   → dependent rows have class="imc-dep-float"
     * Mapping:
     *   #imc-enable-float   → .imc-dep-float
     *   #imc-show-badge     → .imc-dep-badge
     *   #imc-gt-enabled     → .imc-dep-gt
     */
    var depMap = {
        'imc-enable-float': 'imc-dep-float',
        'imc-show-badge':   'imc-dep-badge',
        'imc-gt-enabled':   'imc-dep-gt'
    };

    function syncDeps() {
        $.each(depMap, function (toggleId, depClass) {
            var $toggle = $('#' + toggleId);
            if (!$toggle.length) return;
            var isOn = $toggle.is(':checked');
            $('tr.' + depClass).toggleClass('imc-dep-hidden', !isOn);
        });
    }

    // Run on page load and on change
    syncDeps();
    $.each(depMap, function (toggleId) {
        $('#' + toggleId).on('change', syncDeps);
    });

})(jQuery);
