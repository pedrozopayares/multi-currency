<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hooks into every WooCommerce price-related filter so that amounts,
 * symbols and formatting match the active currency.
 */
class IMC_Price_Handler {

    /** @var bool Guard against recursive filter calls. */
    private $filtering = false;

    /**
     * Tracks product IDs that fell back to the default currency because
     * no custom price was set for the active currency.
     * Populated by filter_price() and read by append_currency_badge().
     *
     * @var array<int, bool>
     */
    private $fallback_products = [];

    /**
     * Set by filter_price / filter_regular_price when a product falls back
     * to the default currency.  While set, the formatting filters (currency,
     * decimals, separators, position) return WooCommerce defaults so that
     * the price HTML is rendered with the correct symbol and formatting.
     * Cleared by append_currency_badge() after the price HTML is assembled.
     *
     * @var int|null  Product ID or null.
     */
    private $current_fallback_product_id = null;

    public function __construct() {

        /* ── Currency code & symbol ─────────────────────── */
        add_filter( 'woocommerce_currency', [ $this, 'filter_currency' ], 999 );

        /* ── Price formatting ───────────────────────────── */
        add_filter( 'wc_get_price_decimals',           [ $this, 'filter_decimals' ],     999 );
        add_filter( 'wc_get_price_decimal_separator',   [ $this, 'filter_decimal_sep' ],  999 );
        add_filter( 'wc_get_price_thousand_separator',  [ $this, 'filter_thousand_sep' ], 999 );
        add_filter( 'pre_option_woocommerce_currency_pos', [ $this, 'filter_currency_pos' ], 999 );

        /* ── Simple / external / grouped product prices ── */
        add_filter( 'woocommerce_product_get_price',         [ $this, 'filter_price' ],         999, 2 );
        add_filter( 'woocommerce_product_get_regular_price', [ $this, 'filter_regular_price' ], 999, 2 );
        add_filter( 'woocommerce_product_get_sale_price',    [ $this, 'filter_sale_price' ],    999, 2 );

        /* ── Variation prices ───────────────────────────── */
        add_filter( 'woocommerce_product_variation_get_price',         [ $this, 'filter_price' ],         999, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', [ $this, 'filter_regular_price' ], 999, 2 );
        add_filter( 'woocommerce_product_variation_get_sale_price',    [ $this, 'filter_sale_price' ],    999, 2 );

        /* ── Variable product price range ───────────────── */
        add_filter( 'woocommerce_variation_prices',          [ $this, 'filter_variation_prices' ],      999, 3 );
        add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'filter_variation_prices_hash' ], 999, 3 );

        /* ── Cart item price hash (fragment caching) ───── */
        add_filter( 'woocommerce_cart_hash', [ $this, 'append_currency_to_hash' ], 999 );

        /* ── Append currency code badge to formatted price HTML ── */
        add_filter( 'woocommerce_get_price_html', [ $this, 'append_currency_badge' ], 999, 2 );
    }

    /* ================================================================
     *  Should we filter?
     * ============================================================= */

    private function should_filter() {
        // Never alter prices / formatting inside the admin.
        // This covers regular admin pages AND admin-ajax.php requests
        // (e.g. woocommerce_save_variations, woocommerce_load_variations).
        //
        // Frontend WC AJAX through /?wc-ajax=* is NOT considered admin
        // (is_admin() returns false), so it is correctly filtered.
        //
        // Legacy frontend AJAX through admin-ajax.php is detected via the
        // HTTP referer: if it comes from a frontend page, we still filter.
        if ( is_admin() ) {
            if ( ! wp_doing_ajax() ) {
                return false; // Regular admin page.
            }

            // AJAX: check whether the request originates from the frontend.
            $referer = wp_get_raw_referer();
            if ( $referer && false !== strpos( $referer, admin_url() ) ) {
                return false; // Admin-originated AJAX → don't filter.
            }

            // If no referer or referer is a frontend URL, allow filtering
            // so legacy cart / checkout AJAX keeps working.
            if ( ! $referer ) {
                return false; // No referer → safer to skip filtering.
            }
        }

        return true;
    }

    private function is_non_default() {
        if ( $this->filtering ) {
            return false;
        }
        $this->filtering = true;
        $result = IMC()->get_active_currency() !== IMC()->get_default_currency();
        $this->filtering = false;
        return $result;
    }

    private function currency_config() {
        if ( $this->filtering ) {
            return null;
        }
        $this->filtering = true;
        $config = IMC()->currency_manager->get_currency( IMC()->get_active_currency() );
        $this->filtering = false;
        return $config;
    }

    /* ================================================================
     *  Currency code
     * ============================================================= */

    public function filter_currency( $currency ) {
        if ( ! $this->should_filter() || $this->filtering ) {
            return $currency;
        }
        // Fallback product → use the store's default currency (pass through).
        if ( $this->current_fallback_product_id !== null ) {
            return $currency;
        }
        $this->filtering = true;
        $active = IMC()->get_active_currency();
        $this->filtering = false;
        return $active;
    }

    /* ================================================================
     *  Formatting
     * ============================================================= */

    public function filter_decimals( $decimals ) {
        if ( ! $this->should_filter() || $this->current_fallback_product_id !== null ) {
            return $decimals;
        }
        $c = $this->currency_config();
        return $c ? absint( $c['decimals'] ) : $decimals;
    }

    public function filter_decimal_sep( $sep ) {
        if ( ! $this->should_filter() || $this->current_fallback_product_id !== null ) {
            return $sep;
        }
        $c = $this->currency_config();
        return $c ? $c['decimal_sep'] : $sep;
    }

    public function filter_thousand_sep( $sep ) {
        if ( ! $this->should_filter() || $this->current_fallback_product_id !== null ) {
            return $sep;
        }
        $c = $this->currency_config();
        return $c ? $c['thousand_sep'] : $sep;
    }

    /**
     * pre_option filter: returning a non-false value short-circuits get_option().
     */
    public function filter_currency_pos( $pre ) {
        if ( ! $this->should_filter() || $this->current_fallback_product_id !== null ) {
            return $pre;
        }
        $c = $this->currency_config();
        return $c ? $c['position'] : $pre;
    }

    /* ================================================================
     *  Product prices
     * ============================================================= */

    /**
     * Active price (= sale price if on sale, otherwise regular).
     */
    public function filter_price( $price, $product ) {
        if ( ! $this->should_filter() || ! $this->is_non_default() ) {
            return $price;
        }

        $cur  = IMC()->get_active_currency();
        $sale = $product->get_meta( "_imc_sale_price_{$cur}" );

        if ( '' !== $sale && false !== $sale ) {
            $this->current_fallback_product_id = null;
            return $sale;
        }

        $regular = $product->get_meta( "_imc_regular_price_{$cur}" );

        if ( '' !== $regular && false !== $regular ) {
            $this->current_fallback_product_id = null;
            return $regular;
        }

        // Variable products: check if any variation has the currency price.
        // If so, the parent is NOT a fallback — filter_variation_prices() will
        // supply the correct per-variation amounts.
        if ( $this->any_variation_has_currency( $product, $cur ) ) {
            $this->current_fallback_product_id = null;
            return $price;
        }

        // No custom price for this currency → mark as fallback.
        $this->fallback_products[ $product->get_id() ] = true;
        $this->current_fallback_product_id = $product->get_id();
        return $price;
    }

    public function filter_regular_price( $price, $product ) {
        if ( ! $this->should_filter() || ! $this->is_non_default() ) {
            return $price;
        }
        $cur     = IMC()->get_active_currency();
        $regular = $product->get_meta( "_imc_regular_price_{$cur}" );

        if ( '' !== $regular && false !== $regular ) {
            $this->current_fallback_product_id = null;
            return $regular;
        }

        if ( $this->any_variation_has_currency( $product, $cur ) ) {
            $this->current_fallback_product_id = null;
            return $price;
        }

        $this->fallback_products[ $product->get_id() ] = true;
        $this->current_fallback_product_id = $product->get_id();
        return $price;
    }

    public function filter_sale_price( $price, $product ) {
        if ( ! $this->should_filter() || ! $this->is_non_default() ) {
            return $price;
        }
        $cur  = IMC()->get_active_currency();
        $sale = $product->get_meta( "_imc_sale_price_{$cur}" );

        if ( '' !== $sale && false !== $sale ) {
            return $sale;
        }

        // Only mark fallback if there's also no regular price.
        $regular = $product->get_meta( "_imc_regular_price_{$cur}" );
        if ( '' === $regular || false === $regular ) {
            if ( ! $this->any_variation_has_currency( $product, $cur ) ) {
                $this->fallback_products[ $product->get_id() ] = true;
                $this->current_fallback_product_id = $product->get_id();
            }
        }
        return $price;
    }

    /* ================================================================
     *  Variable product: all-variation prices (used for "From $X")
     * ============================================================= */

    public function filter_variation_prices( $prices, $product, $for_display ) {
        if ( ! $this->should_filter() || ! $this->is_non_default() ) {
            return $prices;
        }

        $cur = IMC()->get_active_currency();

        foreach ( $product->get_children() as $var_id ) {
            $variation = wc_get_product( $var_id );
            if ( ! $variation ) {
                continue;
            }

            $regular = $variation->get_meta( "_imc_regular_price_{$cur}" );
            $sale    = $variation->get_meta( "_imc_sale_price_{$cur}" );

            if ( '' === $regular || false === $regular ) {
                continue; // no custom price → keep default
            }

            $prices['regular_price'][ $var_id ] = wc_format_decimal( $regular );

            if ( '' !== $sale && false !== $sale && floatval( $sale ) < floatval( $regular ) ) {
                $prices['sale_price'][ $var_id ] = wc_format_decimal( $sale );
                $prices['price'][ $var_id ]      = wc_format_decimal( $sale );
            } else {
                $prices['price'][ $var_id ] = wc_format_decimal( $regular );
                unset( $prices['sale_price'][ $var_id ] );
            }
        }

        // Re-sort so "From $X" shows the lowest.
        asort( $prices['price'] );
        asort( $prices['regular_price'] );
        if ( ! empty( $prices['sale_price'] ) ) {
            asort( $prices['sale_price'] );
        }

        return $prices;
    }

    /**
     * Include currency in the hash so WC caches variation prices per currency.
     */
    public function filter_variation_prices_hash( $hash, $product, $for_display ) {
        $hash[] = IMC()->get_active_currency();
        return $hash;
    }

    /* ================================================================
     *  Cart fragment hash
     * ============================================================= */

    public function append_currency_to_hash( $hash ) {
        return $hash . '_' . IMC()->get_active_currency();
    }

    /* ================================================================
     *  Currency code badge on price HTML
     * ============================================================= */

    /**
     * Append a small <span class="imc-currency-code"> badge after every
     * formatted price so the user always knows which currency is displayed.
     *
     * When the product has no price in the active currency (fallback to
     * default), the badge shows the default currency code and a small
     * "not available" notice is appended.
     */
    public function append_currency_badge( $price_html, $product ) {
        if ( ! $this->should_filter() || empty( $price_html ) ) {
            $this->current_fallback_product_id = null;
            return $price_html;
        }

        if ( $this->filtering ) {
            return $price_html;
        }
        $this->filtering = true;
        $active_currency  = IMC()->get_active_currency();
        $default_currency = IMC()->get_default_currency();
        $this->filtering  = false;

        // Determine if this product fell back to the default currency.
        $is_fallback = false;
        if ( $active_currency !== $default_currency ) {
            $pid = $product->get_id();
            if ( ! empty( $this->fallback_products[ $pid ] ) ) {
                $is_fallback = true;
                // Clean up to avoid stale data on subsequent calls.
                unset( $this->fallback_products[ $pid ] );
            }
        }

        // The displayed currency is the default when it's a fallback.
        $displayed_currency = $is_fallback ? $default_currency : $active_currency;

        // Respect the "show currency badge" setting.
        $show_badge = IMC_Admin_Settings::get( 'show_currency_badge' ) === '1';

        $badge = '';
        if ( $show_badge ) {
            $badge = '<span class="imc-currency-code notranslate" translate="no">' . esc_html( $displayed_currency ) . '</span>';
        }

        // Build the "not available" notice for fallback products.
        // The notice text is translatable by GTranslate, but the currency
        // code inside it is wrapped in a notranslate span.
        $notice = '';
        if ( $is_fallback ) {
            $currency_span = '<span class="notranslate" translate="no">' . esc_html( $active_currency ) . '</span>';
            // Use &nbsp; between translated text and the currency code so
            // GTranslate cannot strip the space when it replaces the text node.
            $notice = '<span class="imc-currency-notice">'
                    . esc_html__( 'Precio no disponible en', 'japp-mc' )
                    . '&nbsp;'
                    . $currency_span
                    . '</span>';
        }

        // Badge position: before or after the price HTML.
        $this->current_fallback_product_id = null;

        if ( $show_badge && IMC_Admin_Settings::get( 'badge_position' ) === 'before' ) {
            return $badge . ' ' . $price_html . $notice;
        }

        return $price_html . ( $show_badge ? ' ' . $badge : '' ) . $notice;
    }

    /* ================================================================
     *  Utility: check if a product fell back to default currency.
     *  Public so it can be used by other components if needed.
     * ============================================================= */

    /**
     * Whether the given product has a custom price for the specified currency.
     */
    public function product_has_currency_price( $product, $currency ) {
        $regular = $product->get_meta( "_imc_regular_price_{$currency}" );
        return ( '' !== $regular && false !== $regular );
    }

    /* ================================================================
     *  Helper: does any child variation have a price in $currency?
     *  Returns false immediately for non-variable products (no children).
     * ============================================================= */

    private function any_variation_has_currency( $product, $currency ) {
        $children = $product->get_children();
        if ( empty( $children ) ) {
            return false;
        }
        foreach ( $children as $var_id ) {
            $variation = wc_get_product( $var_id );
            if ( ! $variation ) {
                continue;
            }
            $regular = $variation->get_meta( "_imc_regular_price_{$currency}" );
            if ( '' !== $regular && false !== $regular ) {
                return true;
            }
        }
        return false;
    }
}
