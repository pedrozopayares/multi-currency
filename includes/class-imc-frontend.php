<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frontend responsibilities:
 *  - Handle ?imc_currency= switch (set cookie → redirect to clean URL)
 *  - Enqueue frontend JS/CSS and expose currency data
 *  - [imc_currency_switcher] shortcode (links or dropdown)
 *  - Fire & listen for JavaScript events
 */
class IMC_Frontend {

    public function __construct() {
        add_action( 'init', [ $this, 'maybe_set_cookie' ], 1 );
        add_action( 'template_redirect', [ $this, 'maybe_redirect_clean_url' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_shortcode( 'imc_currency_switcher', [ $this, 'shortcode_switcher' ] );

        // Floating currency switcher on all frontend pages (if enabled).
        if ( IMC_Admin_Settings::get( 'enable_floating_switcher' ) === '1' ) {
            add_action( 'wp_footer', [ $this, 'render_floating_switcher' ] );
        }
    }

    /* ── Cookie & redirect ──────────────────────────────── */

    /**
     * Very early on init: persist the currency choice in a cookie.
     */
    public function maybe_set_cookie() {
        if ( empty( $_GET['imc_currency'] ) ) { // phpcs:ignore
            return;
        }
        $currency = strtoupper( sanitize_text_field( wp_unslash( $_GET['imc_currency'] ) ) );
        if ( IMC()->currency_manager->get_currency( $currency ) ) {
            IMC()->currency_manager->set_currency_cookie( $currency );
        }
    }

    /**
     * After WC is loaded, redirect to a clean URL (no query param).
     */
    public function maybe_redirect_clean_url() {
        if ( isset( $_GET['imc_currency'] ) ) { // phpcs:ignore
            wp_safe_redirect( remove_query_arg( 'imc_currency' ) );
            exit;
        }
    }

    /* ── Assets ─────────────────────────────────────────── */

    public function enqueue_assets() {
        wp_enqueue_style(
            'imc-frontend',
            IMC_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            IMC_VERSION
        );

        wp_enqueue_script(
            'imc-frontend',
            IMC_PLUGIN_URL . 'assets/js/frontend.js',
            [],
            IMC_VERSION,
            true
        );

        $active = IMC()->get_active_currency();
        $config = IMC()->currency_manager->get_currency( $active );

        // Build a comprehensive language→currency map for JS.
        $lang_map   = IMC()->currency_manager->get_language_map();
        $currencies = array_keys( IMC()->currency_manager->get_currencies() );
        $settings   = IMC_Admin_Settings::get_settings();

        // Add GTranslate auto-defaults if enabled.
        if ( $settings['gtranslate_enabled'] === '1' && $settings['gtranslate_auto_defaults'] === '1' ) {
            $gt_defaults = [
                'es' => 'COP', 'en' => 'USD', 'fr' => 'EUR', 'de' => 'EUR',
                'it' => 'EUR', 'pt' => 'EUR', 'nl' => 'EUR', 'ru' => 'EUR',
                'ar' => 'USD', 'zh' => 'USD', 'ja' => 'USD', 'ko' => 'USD',
            ];
            foreach ( $gt_defaults as $gl => $gc ) {
                if ( ! isset( $lang_map[ $gl ] ) && in_array( $gc, $currencies, true ) ) {
                    $lang_map[ $gl ] = $gc;
                }
            }
        }

        wp_localize_script( 'imc-frontend', 'imcFrontend', [
            'activeCurrency'    => $active,
            'activeSymbol'      => $config ? $config['symbol'] : '',
            'activeLanguage'    => IMC()->language_detector->get_current_language(),
            'currencies'        => $currencies,
            'langMap'           => (object) $lang_map,
            'cookieDays'        => intval( $settings['cookie_duration'] ),
            'gtEnabled'         => $settings['gtranslate_enabled'] === '1',
            'gtReloadDelay'     => intval( $settings['gtranslate_reload_delay'] ),
            'gtDetectMethod'    => $settings['gtranslate_detect_method'],
        ] );
    }

    /* ── Shortcode ──────────────────────────────────────── */

    /**
     * [imc_currency_switcher style="links|dropdown"]
     */
    public function shortcode_switcher( $atts ) {
        $atts = shortcode_atts( [
            'style' => 'links',
        ], $atts, 'imc_currency_switcher' );

        $currencies = IMC()->currency_manager->get_currencies();
        $active     = IMC()->get_active_currency();

        if ( count( $currencies ) <= 1 ) {
            return '';
        }

        ob_start();

        if ( 'dropdown' === $atts['style'] ) {
            $this->render_dropdown( $currencies, $active );
        } else {
            $this->render_links( $currencies, $active );
        }

        return ob_get_clean();
    }

    /* ── Renderers ──────────────────────────────────────── */

    private function render_links( $currencies, $active ) {
        echo '<div class="imc-switcher imc-switcher--links">';
        foreach ( $currencies as $code => $c ) {
            $url   = esc_url( add_query_arg( 'imc_currency', $code ) );
            $class = ( $code === $active )
                ? 'imc-switcher__item imc-switcher__item--active'
                : 'imc-switcher__item';

            printf(
                '<a href="%s" class="%s" data-currency="%s">%s %s</a>',
                $url,
                esc_attr( $class ),
                esc_attr( $code ),
                esc_html( $c['symbol'] ),
                esc_html( $code )
            );
        }
        echo '</div>';
    }

    private function render_dropdown( $currencies, $active ) {
        echo '<div class="imc-switcher imc-switcher--dropdown">';
        echo '<select class="imc-switcher__select" onchange="if(this.value)window.location.href=this.value">';

        foreach ( $currencies as $code => $c ) {
            $url      = esc_url( add_query_arg( 'imc_currency', $code ) );
            $selected = selected( $code, $active, false );

            printf(
                '<option value="%s" %s>%s %s — %s</option>',
                $url,
                $selected,
                esc_html( $c['symbol'] ),
                esc_html( $code ),
                esc_html( $c['name'] )
            );
        }

        echo '</select>';
        echo '</div>';
    }

    /* ── Floating switcher widget ────────────────────────── */

    /**
     * Render a fixed-position floating currency switcher on every frontend page.
     * Shows the active currency flag/code and expands to show all options on hover/click.
     */
    public function render_floating_switcher() {
        $currencies = IMC()->currency_manager->get_currencies();
        if ( count( $currencies ) <= 1 ) {
            return;
        }

        $active      = IMC()->get_active_currency();
        $active_conf = IMC()->currency_manager->get_currency( $active );
        $flag_map    = $this->get_currency_flags();
        $float_pos   = IMC_Admin_Settings::get( 'floating_position' );
        ?>
        <div class="imc-float imc-float--<?php echo esc_attr( $float_pos ); ?>" id="imc-float">
            <button class="imc-float__toggle" id="imc-float-toggle" type="button"
                    aria-expanded="false" aria-label="<?php esc_attr_e( 'Cambiar moneda', 'japp-mc' ); ?>">
                <span class="imc-float__flag"><?php echo esc_html( $flag_map[ $active ] ?? '💱' ); ?></span>
                <span class="imc-float__code"><?php echo esc_html( $active ); ?></span>
            </button>
            <div class="imc-float__panel" id="imc-float-panel">
                <span class="imc-float__title"><?php esc_html_e( 'Moneda', 'japp-mc' ); ?></span>
                <?php foreach ( $currencies as $code => $c ) :
                    $is_active = ( $code === $active );
                    $url       = esc_url( add_query_arg( 'imc_currency', $code ) );
                    $flag      = $flag_map[ $code ] ?? '💱';
                ?>
                    <a href="<?php echo $url; ?>"
                       class="imc-float__option<?php echo $is_active ? ' imc-float__option--active' : ''; ?>"
                       data-currency="<?php echo esc_attr( $code ); ?>">
                        <span class="imc-float__option-flag"><?php echo esc_html( $flag ); ?></span>
                        <span class="imc-float__option-code"><?php echo esc_html( $code ); ?></span>
                        <span class="imc-float__option-name"><?php echo esc_html( $c['name'] ); ?></span>
                        <?php if ( $is_active ) : ?>
                            <span class="imc-float__check">✓</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Map of currency codes → flag emoji (country of primary use).
     */
    private function get_currency_flags() {
        return [
            'COP' => '🇨🇴',
            'USD' => '🇺🇸',
            'EUR' => '🇪🇺',
            'GBP' => '🇬🇧',
            'MXN' => '🇲🇽',
            'BRL' => '🇧🇷',
            'ARS' => '🇦🇷',
            'CLP' => '🇨🇱',
            'PEN' => '🇵🇪',
            'JPY' => '🇯🇵',
            'CAD' => '🇨🇦',
            'AUD' => '🇦🇺',
            'CHF' => '🇨🇭',
            'CNY' => '🇨🇳',
            'KRW' => '🇰🇷',
            'INR' => '🇮🇳',
        ];
    }
}
