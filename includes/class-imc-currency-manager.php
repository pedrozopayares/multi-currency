<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages currency definitions, language→currency mapping and active currency resolution.
 */
class IMC_Currency_Manager {

    const OPTION_CURRENCIES = 'imc_currencies';
    const OPTION_LANG_MAP   = 'imc_language_currency_map';
    const COOKIE_NAME       = 'imc_currency';

    /** @var string|null Cached active currency for the current request. */
    private $active_currency = null;

    /** @var bool Guard against recursive calls in get_currencies(). */
    private $building_currencies = false;

    /** @var array|null Cached result of get_currencies(). */
    private $currencies_cache = null;

    public function __construct() {
        $this->maybe_install_defaults();
    }

    /* ── Installation ───────────────────────────────────── */

    /**
     * Install or repair default currencies and language map.
     *
     * Uses a DB version number so defaults are repaired when the plugin is updated
     * (e.g. if a previous buggy activation left USD out).
     */
    private function maybe_install_defaults() {
        $db_version = get_option( 'imc_db_version', '0' );

        if ( version_compare( $db_version, IMC_VERSION, '>=' ) ) {
            return; // already up to date
        }

        $wc_currency = $this->get_default_currency();
        $lang        = substr( get_locale(), 0, 2 );

        // ── Language → Currency map (create if missing) ──
        $map = get_option( self::OPTION_LANG_MAP, false );
        if ( false === $map ) {
            $map = [
                $lang => $wc_currency,
                'en'  => 'USD',
            ];
            update_option( self::OPTION_LANG_MAP, $map );
        }

        // ── Ensure USD and EUR exist in additional currencies ──
        $additional = get_option( self::OPTION_CURRENCIES, [] );
        if ( ! is_array( $additional ) ) {
            $additional = [];
        }

        if ( $wc_currency !== 'USD' && ! isset( $additional['USD'] ) ) {
            $additional['USD'] = [
                'code'         => 'USD',
                'name'         => 'US Dollar',
                'symbol'       => '$',
                'position'     => 'left',
                'decimals'     => 2,
                'decimal_sep'  => '.',
                'thousand_sep' => ',',
            ];
        }
        if ( $wc_currency !== 'EUR' && ! isset( $additional['EUR'] ) ) {
            $additional['EUR'] = [
                'code'         => 'EUR',
                'name'         => 'Euro',
                'symbol'       => '€',
                'position'     => 'right_space',
                'decimals'     => 2,
                'decimal_sep'  => ',',
                'thousand_sep' => '.',
            ];
        }
        update_option( self::OPTION_CURRENCIES, $additional );

        update_option( 'imc_db_version', IMC_VERSION );
    }

    /* ── Currency CRUD ──────────────────────────────────── */

    /**
     * All enabled currencies. The WC default is always present (synced from WC settings).
     *
     * Uses a recursion guard because WC functions like get_woocommerce_currency_symbol()
     * trigger the 'woocommerce_currency' filter, which calls get_active_currency() →
     * get_currency() → get_currencies() — creating an infinite loop.
     *
     * @return array<string, array>  Keyed by currency code.
     */
    public function get_currencies() {
        if ( null !== $this->currencies_cache ) {
            return $this->currencies_cache;
        }

        // Recursion guard: return a minimal fallback while building.
        if ( $this->building_currencies ) {
            $default_code = get_option( 'woocommerce_currency', 'COP' );
            return [
                $default_code => [
                    'code'         => $default_code,
                    'name'         => $default_code,
                    'symbol'       => $default_code,
                    'position'     => get_option( 'woocommerce_currency_pos', 'left' ),
                    'decimals'     => absint( get_option( 'woocommerce_price_num_decimals', 2 ) ),
                    'decimal_sep'  => get_option( 'woocommerce_price_decimal_sep', '.' ),
                    'thousand_sep' => get_option( 'woocommerce_price_thousand_sep', ',' ),
                ],
            ];
        }

        $this->building_currencies = true;

        $stored       = get_option( self::OPTION_CURRENCIES, [] );
        $default_code = $this->get_default_currency();

        // Read the symbol directly from WC's symbol map to avoid triggering filters.
        $symbols = get_woocommerce_currency_symbols();
        $symbol  = $symbols[ $default_code ] ?? $default_code;

        $all = [];
        $all[ $default_code ] = [
            'code'         => $default_code,
            'name'         => $this->get_currency_name( $default_code ),
            'symbol'       => $symbol,
            'position'     => get_option( 'woocommerce_currency_pos', 'left' ),
            'decimals'     => absint( get_option( 'woocommerce_price_num_decimals', 2 ) ),
            'decimal_sep'  => get_option( 'woocommerce_price_decimal_sep', '.' ),
            'thousand_sep' => get_option( 'woocommerce_price_thousand_sep', ',' ),
        ];

        foreach ( $stored as $code => $config ) {
            if ( $code !== $default_code ) {
                $all[ $code ] = $config;
            }
        }

        $this->building_currencies = false;
        $this->currencies_cache    = $all;

        return $all;
    }

    /**
     * Single currency config or null.
     */
    public function get_currency( $code ) {
        $all = $this->get_currencies();
        return $all[ $code ] ?? null;
    }

    /**
     * Save only the additional (non-default) currencies.
     */
    public function save_currencies( $currencies ) {
        $default = $this->get_default_currency();
        unset( $currencies[ $default ] );
        update_option( self::OPTION_CURRENCIES, $currencies );
        $this->currencies_cache = null; // invalidate cache
    }

    /**
     * Currencies that are NOT the WC default.
     */
    public function get_non_default_currencies() {
        $all     = $this->get_currencies();
        $default = $this->get_default_currency();
        unset( $all[ $default ] );
        return $all;
    }

    /**
     * WooCommerce default currency code.
     */
    public function get_default_currency() {
        return get_option( 'woocommerce_currency', 'COP' );
    }

    /* ── Language ↔ Currency map ─────────────────────────── */

    public function get_language_map() {
        return get_option( self::OPTION_LANG_MAP, [] );
    }

    public function save_language_map( $map ) {
        update_option( self::OPTION_LANG_MAP, $map );
    }

    /* ── Active currency resolution ─────────────────────── */

    /**
     * Determine which currency is active for the current request.
     *
     * Priority:
     *  1. ?imc_currency=XXX  (URL override, also sets cookie)
     *  2. Cookie imc_currency
     *  3. Language → Currency map
     *  4. WooCommerce default
     */
    public function get_active_currency() {
        if ( null !== $this->active_currency ) {
            return $this->active_currency;
        }

        // 1. URL parameter (manual switch — cookie set by Frontend handler)
        if ( ! empty( $_GET['imc_currency'] ) ) { // phpcs:ignore
            $requested = strtoupper( sanitize_text_field( wp_unslash( $_GET['imc_currency'] ) ) );
            if ( $this->get_currency( $requested ) ) {
                $this->active_currency = $requested;
                return $requested;
            }
        }

        // 2. Cookie
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) { // phpcs:ignore
            $from_cookie = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) );
            if ( $this->get_currency( $from_cookie ) ) {
                $this->active_currency = $from_cookie;
                return $from_cookie;
            }
        }

        // 3. Language detection → map
        $lang = IMC()->language_detector->get_current_language();
        $map  = $this->get_language_map();
        if ( $lang && isset( $map[ $lang ] ) && $this->get_currency( strtoupper( $map[ $lang ] ) ) ) {
            $this->active_currency = strtoupper( $map[ $lang ] );
            return $this->active_currency;
        }

        // 4. Default
        $this->active_currency = $this->get_default_currency();
        return $this->active_currency;
    }

    /**
     * Persist currency choice in a cookie (duration from settings).
     */
    public function set_currency_cookie( $currency ) {
        $days = intval( IMC_Admin_Settings::get( 'cookie_duration' ) );
        if ( $days < 1 ) {
            $days = 30;
        }
        if ( ! headers_sent() ) {
            setcookie(
                self::COOKIE_NAME,
                $currency,
                time() + ( $days * DAY_IN_SECONDS ),
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                false
            );
        }
        $_COOKIE[ self::COOKIE_NAME ] = $currency;
    }

    /* ── Helpers ────────────────────────────────────────── */

    private function get_currency_name( $code ) {
        // get_woocommerce_currencies() is safe — it returns a static list
        // and does NOT trigger 'woocommerce_currency' filter.
        if ( function_exists( 'get_woocommerce_currencies' ) ) {
            $names = get_woocommerce_currencies();
            return $names[ $code ] ?? $code;
        }
        return $code;
    }
}
