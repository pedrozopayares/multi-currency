<?php
/**
 * Tests for IMC_Frontend.
 */

use PHPUnit\Framework\TestCase;

class FrontendTest extends TestCase {

    protected function setUp(): void {
        imc_reset_test_state();
        delete_option( 'imc_db_version' );
        delete_option( 'imc_currencies' );
        delete_option( 'imc_language_currency_map' );
        delete_option( 'imc_plugin_settings' );

        imc_reset_singleton();
    }

    /* ── maybe_set_cookie() ─────────────────────────────── */

    public function test_maybe_set_cookie_sets_valid_currency(): void {
        $_GET['imc_currency'] = 'USD';

        // Need to ensure admin settings give a valid cookie_duration.
        update_option( 'imc_plugin_settings', [ 'cookie_duration' => 30 ] );

        $frontend = new IMC_Frontend();
        $frontend->maybe_set_cookie();

        $this->assertSame( 'USD', $_COOKIE['imc_currency'] ?? '' );
    }

    public function test_maybe_set_cookie_ignores_invalid(): void {
        $_GET['imc_currency'] = 'FAKE';

        $frontend = new IMC_Frontend();
        $frontend->maybe_set_cookie();

        $this->assertArrayNotHasKey( 'imc_currency', $_COOKIE );
    }

    public function test_maybe_set_cookie_no_op_without_param(): void {
        $frontend = new IMC_Frontend();
        $frontend->maybe_set_cookie();

        $this->assertArrayNotHasKey( 'imc_currency', $_COOKIE );
    }

    /* ── Shortcode ──────────────────────────────────────── */

    public function test_shortcode_links_output(): void {
        $frontend = new IMC_Frontend();
        $output   = $frontend->shortcode_switcher( [ 'style' => 'links' ] );

        $this->assertStringContainsString( 'imc-switcher--links', $output );
        $this->assertStringContainsString( 'COP', $output );
        $this->assertStringContainsString( 'USD', $output );
        $this->assertStringContainsString( 'EUR', $output );
    }

    public function test_shortcode_dropdown_output(): void {
        $frontend = new IMC_Frontend();
        $output   = $frontend->shortcode_switcher( [ 'style' => 'dropdown' ] );

        $this->assertStringContainsString( 'imc-switcher--dropdown', $output );
        $this->assertStringContainsString( '<select', $output );
        $this->assertStringContainsString( 'USD', $output );
    }

    public function test_shortcode_empty_when_single_currency(): void {
        // Remove all additional currencies.
        IMC()->currency_manager->save_currencies( [] );

        $frontend = new IMC_Frontend();
        $output   = $frontend->shortcode_switcher( [ 'style' => 'links' ] );

        $this->assertEmpty( $output, 'Switcher should be empty with only one currency.' );
    }

    /* ── Floating switcher ──────────────────────────────── */

    public function test_floating_switcher_renders_currencies(): void {
        $frontend = new IMC_Frontend();

        ob_start();
        $frontend->render_floating_switcher();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'imc-float', $output );
        $this->assertStringContainsString( 'COP', $output );
        $this->assertStringContainsString( 'USD', $output );
    }

    public function test_floating_switcher_respects_position(): void {
        update_option( 'imc_plugin_settings', [ 'floating_position' => 'top-right' ] );

        $frontend = new IMC_Frontend();

        ob_start();
        $frontend->render_floating_switcher();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'imc-float--top-right', $output );
    }

    public function test_floating_switcher_empty_with_single_currency(): void {
        IMC()->currency_manager->save_currencies( [] );

        $frontend = new IMC_Frontend();

        ob_start();
        $frontend->render_floating_switcher();
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    public function test_floating_switcher_marks_active(): void {
        $_COOKIE['imc_currency'] = 'EUR';

        // Recreate manager to pick up cookie.
        $mgr = new IMC_Currency_Manager();
        $core = IMC_Core::instance();
        $ref  = new \ReflectionObject( $core );
        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $mgr );

        $frontend = new IMC_Frontend();

        ob_start();
        $frontend->render_floating_switcher();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'imc-float__option--active', $output );
    }

    /* ── enqueue_assets localized data ──────────────────── */

    public function test_enqueue_assets_localizes_script(): void {
        global $wp_test_localized_scripts;

        $frontend = new IMC_Frontend();
        $frontend->enqueue_assets();

        $this->assertArrayHasKey( 'imc-frontend', $wp_test_localized_scripts );

        $data = $wp_test_localized_scripts['imc-frontend']['data'];

        $this->assertArrayHasKey( 'activeCurrency', $data );
        $this->assertArrayHasKey( 'currencies', $data );
        $this->assertArrayHasKey( 'langMap', $data );
        $this->assertArrayHasKey( 'cookieDays', $data );
        $this->assertArrayHasKey( 'gtEnabled', $data );
        $this->assertArrayHasKey( 'gtReloadDelay', $data );
        $this->assertArrayHasKey( 'gtDetectMethod', $data );
    }

    public function test_enqueue_assets_includes_gtranslate_defaults(): void {
        global $wp_test_localized_scripts;

        update_option( 'imc_plugin_settings', [
            'gtranslate_enabled'       => '1',
            'gtranslate_auto_defaults' => '1',
        ] );

        $frontend = new IMC_Frontend();
        $frontend->enqueue_assets();

        $data    = $wp_test_localized_scripts['imc-frontend']['data'];
        $langMap = (array) $data['langMap'];

        // 'en' → 'USD' should be auto-added.
        $this->assertArrayHasKey( 'en', $langMap );
        $this->assertSame( 'USD', $langMap['en'] );
    }

    public function test_enqueue_assets_skips_gtranslate_when_disabled(): void {
        global $wp_test_localized_scripts;

        update_option( 'imc_plugin_settings', [
            'gtranslate_enabled' => '0',
        ] );
        // Remove any saved language map.
        delete_option( 'imc_language_currency_map' );

        $mgr = new IMC_Currency_Manager();
        $core = IMC_Core::instance();
        $ref  = new \ReflectionObject( $core );
        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $mgr );

        $frontend = new IMC_Frontend();
        $frontend->enqueue_assets();

        $data    = $wp_test_localized_scripts['imc-frontend']['data'];
        $langMap = (array) $data['langMap'];

        // With gtranslate disabled and no saved map, auto-defaults should NOT be added.
        // But maybe_install_defaults creates a base map. Check 'fr' isn't there.
        $this->assertArrayNotHasKey( 'fr', $langMap, 'GTranslate auto-defaults should not be added when disabled.' );
    }

    public function test_enqueue_assets_cookie_days_from_settings(): void {
        global $wp_test_localized_scripts;

        update_option( 'imc_plugin_settings', [ 'cookie_duration' => 45 ] );

        $frontend = new IMC_Frontend();
        $frontend->enqueue_assets();

        $data = $wp_test_localized_scripts['imc-frontend']['data'];
        $this->assertSame( 45, $data['cookieDays'] );
    }
}
