<?php
/**
 * Tests for IMC_Price_Handler.
 */

use PHPUnit\Framework\TestCase;

class PriceHandlerTest extends TestCase {

    protected function setUp(): void {
        imc_reset_test_state();
        delete_option( 'imc_db_version' );
        delete_option( 'imc_currencies' );
        delete_option( 'imc_language_currency_map' );
        delete_option( 'imc_plugin_settings' );

        imc_reset_singleton();
    }

    /* ── filter_currency() ──────────────────────────────── */

    public function test_filter_currency_returns_active(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_currency( 'COP' );

        $this->assertSame( 'USD', $result );
    }

    public function test_filter_currency_skips_in_admin(): void {
        global $wp_test_is_admin;
        $wp_test_is_admin = true;

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_currency( 'COP' );

        $this->assertSame( 'COP', $result, 'Should not filter in admin.' );
    }

    public function test_filter_currency_skips_admin_ajax_from_admin(): void {
        global $wp_test_is_admin, $wp_test_doing_ajax, $wp_test_raw_referer;
        $wp_test_is_admin   = true;
        $wp_test_doing_ajax = true;
        $wp_test_raw_referer = 'http://localhost/wp-admin/post.php?post=123';

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_currency( 'COP' );

        $this->assertSame( 'COP', $result, 'Should not filter admin AJAX from admin pages.' );
    }

    /* ── filter_decimals() ──────────────────────────────── */

    public function test_filter_decimals_for_usd(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_decimals( 0 );

        $this->assertSame( 2, $result, 'USD should have 2 decimals.' );
    }

    public function test_filter_decimals_skips_in_admin(): void {
        global $wp_test_is_admin;
        $wp_test_is_admin = true;

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_decimals( 0 );

        $this->assertSame( 0, $result );
    }

    /* ── filter_decimal_sep() / filter_thousand_sep() ──── */

    public function test_filter_decimal_sep_for_usd(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_decimal_sep( ',' );

        $this->assertSame( '.', $result );
    }

    public function test_filter_thousand_sep_for_usd(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_thousand_sep( '.' );

        $this->assertSame( ',', $result );
    }

    /* ── filter_currency_pos() ──────────────────────────── */

    public function test_filter_currency_pos_for_eur(): void {
        $_COOKIE['imc_currency'] = 'EUR';

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_currency_pos( false );

        $this->assertSame( 'right_space', $result );
    }

    /* ── Product price filtering ────────────────────────── */

    public function test_filter_price_returns_meta_for_non_default(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $product = new WC_Product( 42 );
        $product->set_meta( '_imc_regular_price_USD', '29.99' );

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_price( '100000', $product );

        $this->assertSame( '29.99', $result );
    }

    public function test_filter_price_returns_sale_if_set(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $product = new WC_Product( 42 );
        $product->set_meta( '_imc_regular_price_USD', '29.99' );
        $product->set_meta( '_imc_sale_price_USD', '19.99' );

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_price( '100000', $product );

        $this->assertSame( '19.99', $result );
    }

    public function test_filter_price_fallback_to_original(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $product = new WC_Product( 42 );
        // No meta set for USD.

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_price( '100000', $product );

        $this->assertSame( '100000', $result );
    }

    public function test_filter_price_for_default_currency_no_change(): void {
        // COP is default — should NOT filter.
        $product = new WC_Product( 42 );
        $product->set_meta( '_imc_regular_price_COP', '50000' );

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_price( '100000', $product );

        $this->assertSame( '100000', $result, 'Should not alter price for default currency.' );
    }

    public function test_filter_regular_price(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $product = new WC_Product( 42 );
        $product->set_meta( '_imc_regular_price_USD', '39.99' );

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_regular_price( '120000', $product );

        $this->assertSame( '39.99', $result );
    }

    public function test_filter_sale_price(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $product = new WC_Product( 42 );
        $product->set_meta( '_imc_sale_price_USD', '24.99' );

        $handler = new IMC_Price_Handler();
        $result  = $handler->filter_sale_price( '80000', $product );

        $this->assertSame( '24.99', $result );
    }

    /* ── append_currency_badge() ────────────────────────── */

    public function test_append_currency_badge_default_after(): void {
        $_COOKIE['imc_currency'] = 'USD';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '1', 'badge_position' => 'after' ] );

        $handler    = new IMC_Price_Handler();
        $price_html = '<span class="price">$29.99</span>';
        $product    = new WC_Product( 42 );
        $product->set_meta( '_imc_regular_price_USD', '29.99' );

        // Simulate that filter_price ran first (no fallback since meta exists).
        $handler->filter_price( '100000', $product );

        $result = $handler->append_currency_badge( $price_html, $product );

        $this->assertStringContainsString( 'imc-currency-code', $result );
        $this->assertStringContainsString( 'USD', $result );
        $this->assertStringNotContainsString( 'imc-currency-notice', $result );
    }

    public function test_append_currency_badge_position_before(): void {
        $_COOKIE['imc_currency'] = 'EUR';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '1', 'badge_position' => 'before' ] );

        $handler    = new IMC_Price_Handler();
        $price_html = '<span class="price">€29.99</span>';
        $product    = new WC_Product( 42 );
        $product->set_meta( '_imc_regular_price_EUR', '29.99' );

        $handler->filter_price( '120000', $product );

        $result = $handler->append_currency_badge( $price_html, $product );

        $this->assertStringContainsString( 'imc-currency-code', $result );
        $this->assertStringContainsString( 'EUR', $result );
        // Badge should come BEFORE the original price HTML.
        $badge_pos = strpos( $result, 'EUR' );
        $price_pos = strpos( $result, '€29.99' );
        $this->assertLessThan( $price_pos, $badge_pos, 'Badge should appear before the price.' );
        $this->assertStringNotContainsString( 'imc-currency-notice', $result );
    }

    public function test_append_currency_badge_disabled(): void {
        $_COOKIE['imc_currency'] = 'USD';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '0' ] );

        $handler    = new IMC_Price_Handler();
        $price_html = '<span class="price">$29.99</span>';
        $product    = new WC_Product( 42 );
        $product->set_meta( '_imc_regular_price_USD', '29.99' );

        // Simulate filter_price ran first (no fallback since meta exists).
        $handler->filter_price( '100000', $product );

        $result = $handler->append_currency_badge( $price_html, $product );

        // Badge disabled and no fallback → original HTML returned as-is.
        $this->assertStringNotContainsString( 'imc-currency-code', $result );
        $this->assertStringNotContainsString( 'imc-currency-notice', $result );
        $this->assertSame( $price_html, $result );
    }

    public function test_append_currency_badge_empty_html(): void {
        $handler = new IMC_Price_Handler();
        $result  = $handler->append_currency_badge( '', new WC_Product() );
        $this->assertSame( '', $result );
    }

    /* ── Fallback: product without active currency price ── */

    public function test_badge_shows_default_currency_on_fallback(): void {
        $_COOKIE['imc_currency'] = 'USD';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '1', 'badge_position' => 'after' ] );

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 50 );
        // No USD meta → filter_price falls back to original.
        $handler->filter_price( '100000', $product );

        $result = $handler->append_currency_badge( '<span class="price">$100.000</span>', $product );

        // Badge should show default currency (COP), not active (USD).
        $this->assertStringContainsString( '>COP<', $result );
        $this->assertStringNotContainsString( '>USD<', $result );
    }

    public function test_fallback_shows_unavailable_notice(): void {
        $_COOKIE['imc_currency'] = 'USD';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '1', 'badge_position' => 'after' ] );

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 51 );
        $handler->filter_price( '100000', $product );

        $result = $handler->append_currency_badge( '<span class="price">$100.000</span>', $product );

        $this->assertStringContainsString( 'imc-currency-notice', $result );
        $this->assertStringContainsString( 'USD', $result ); // USD in the notice text.
    }

    public function test_fallback_notice_shown_even_when_badge_disabled(): void {
        $_COOKIE['imc_currency'] = 'USD';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '0' ] );

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 52 );
        $handler->filter_price( '100000', $product );

        $result = $handler->append_currency_badge( '<span class="price">$100.000</span>', $product );

        // No badge, but notice should still appear.
        $this->assertStringNotContainsString( 'imc-currency-code', $result );
        $this->assertStringContainsString( 'imc-currency-notice', $result );
    }

    public function test_no_fallback_when_product_has_active_currency_price(): void {
        $_COOKIE['imc_currency'] = 'EUR';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '1', 'badge_position' => 'after' ] );

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 53 );
        $product->set_meta( '_imc_regular_price_EUR', '25.00' );

        $handler->filter_price( '100000', $product );

        $result = $handler->append_currency_badge( '<span class="price">€25.00</span>', $product );

        $this->assertStringContainsString( '>EUR<', $result );
        $this->assertStringNotContainsString( 'imc-currency-notice', $result );
    }

    public function test_product_has_currency_price_true(): void {
        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 54 );
        $product->set_meta( '_imc_regular_price_USD', '29.99' );

        $this->assertTrue( $handler->product_has_currency_price( $product, 'USD' ) );
    }

    public function test_product_has_currency_price_false(): void {
        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 55 );

        $this->assertFalse( $handler->product_has_currency_price( $product, 'USD' ) );
    }

    /* ── Formatting filters use default currency on fallback ── */

    public function test_filter_currency_returns_default_on_fallback(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 60 );
        // No USD meta → fallback.
        $handler->filter_price( '100000', $product );

        // filter_currency should return the WC default (passed-in value).
        $this->assertSame( 'COP', $handler->filter_currency( 'COP' ) );
    }

    public function test_filter_currency_returns_active_when_no_fallback(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 61 );
        $product->set_meta( '_imc_regular_price_USD', '29.99' );

        $handler->filter_price( '100000', $product );

        // filter_currency should return the active currency (USD).
        $this->assertSame( 'USD', $handler->filter_currency( 'COP' ) );
    }

    public function test_formatting_filters_passthrough_on_fallback(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 62 );
        // No USD meta → fallback.
        $handler->filter_price( '100000', $product );

        // All formatting filters should return the WC default values
        // (the values passed in), not the active currency's config.
        $this->assertSame( 0, $handler->filter_decimals( 0 ) );
        $this->assertSame( ',', $handler->filter_decimal_sep( ',' ) );
        $this->assertSame( '.', $handler->filter_thousand_sep( '.' ) );
        $this->assertSame( 'left', $handler->filter_currency_pos( 'left' ) );
    }

    public function test_formatting_filters_override_when_no_fallback(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $this->set_imc_currency_manager();

        $product = new WC_Product( 63 );
        $product->set_meta( '_imc_regular_price_USD', '29.99' );

        $handler->filter_price( '100000', $product );

        // Formatting should use the active currency config (USD: 2 decimals, etc.)
        $this->assertSame( 2, $handler->filter_decimals( 0 ) );
    }

    public function test_fallback_context_cleared_by_badge(): void {
        $_COOKIE['imc_currency'] = 'USD';
        update_option( 'imc_plugin_settings', [ 'show_currency_badge' => '1', 'badge_position' => 'after' ] );

        $handler = new IMC_Price_Handler();
        $product = new WC_Product( 64 );
        // No USD meta → fallback.
        $handler->filter_price( '100000', $product );

        // Verify fallback context is active.
        $this->assertSame( 'COP', $handler->filter_currency( 'COP' ) );

        // append_currency_badge clears the context.
        $handler->append_currency_badge( '<span>$100.000</span>', $product );

        // After badge, filter_currency should return active currency again.
        $this->assertSame( 'USD', $handler->filter_currency( 'COP' ) );
    }

    /* ── Cart hash ──────────────────────────────────────── */

    public function test_append_currency_to_hash(): void {
        $_COOKIE['imc_currency'] = 'USD';

        $handler = new IMC_Price_Handler();
        $this->set_imc_currency_manager();

        $result = $handler->append_currency_to_hash( 'abc123' );
        $this->assertStringContainsString( '_', $result );
    }

    /* ── Variation prices hash ──────────────────────────── */

    public function test_variation_prices_hash_includes_currency(): void {
        $handler = new IMC_Price_Handler();
        $hash = $handler->filter_variation_prices_hash( [ 'original' ], new WC_Product(), true );

        $this->assertCount( 2, $hash );
    }

    /* ── Helper ─────────────────────────────────────────── */

    private function set_imc_currency_manager(): void {
        $mgr  = new IMC_Currency_Manager();
        $core = IMC_Core::instance();
        $ref  = new \ReflectionObject( $core );
        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $mgr );
    }
}
