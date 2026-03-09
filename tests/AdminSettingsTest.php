<?php
/**
 * Tests for IMC_Admin_Settings.
 */

use PHPUnit\Framework\TestCase;

class AdminSettingsTest extends TestCase {

    protected function setUp(): void {
        imc_reset_test_state();
        delete_option( 'imc_plugin_settings' );
    }

    /* ── Defaults ───────────────────────────────────────── */

    public function test_get_settings_returns_defaults_when_no_saved(): void {
        $settings = IMC_Admin_Settings::get_settings();

        $this->assertSame( '1', $settings['enable_floating_switcher'] );
        $this->assertSame( 'bottom-left', $settings['floating_position'] );
        $this->assertSame( 30, $settings['cookie_duration'] );
        $this->assertSame( '1', $settings['show_currency_badge'] );
        $this->assertSame( 'after', $settings['badge_position'] );
        $this->assertSame( '1', $settings['auto_detect_language'] );
        $this->assertSame( '1', $settings['gtranslate_enabled'] );
        $this->assertSame( 800, $settings['gtranslate_reload_delay'] );
        $this->assertSame( '1', $settings['gtranslate_auto_defaults'] );
        $this->assertSame( 'both', $settings['gtranslate_detect_method'] );
    }

    public function test_get_settings_merges_saved_with_defaults(): void {
        update_option( 'imc_plugin_settings', [
            'enable_floating_switcher' => '0',
            'cookie_duration'          => 60,
        ] );

        $settings = IMC_Admin_Settings::get_settings();

        $this->assertSame( '0', $settings['enable_floating_switcher'], 'Saved value should override default.' );
        $this->assertSame( 60, $settings['cookie_duration'], 'Saved value should override default.' );
        $this->assertSame( 'bottom-left', $settings['floating_position'], 'Default should be used when not saved.' );
        $this->assertSame( '1', $settings['show_currency_badge'], 'Default should be used when not saved.' );
    }

    /* ── get() single value ─────────────────────────────── */

    public function test_get_returns_default_for_unsaved(): void {
        $this->assertSame( '1', IMC_Admin_Settings::get( 'show_currency_badge' ) );
    }

    public function test_get_returns_saved_value(): void {
        update_option( 'imc_plugin_settings', [
            'show_currency_badge' => '0',
        ] );

        $this->assertSame( '0', IMC_Admin_Settings::get( 'show_currency_badge' ) );
    }

    public function test_get_returns_null_for_unknown_key(): void {
        $this->assertNull( IMC_Admin_Settings::get( 'nonexistent_key' ) );
    }

    /* ── Complete defaults list ──────────────────────────── */

    public function test_all_default_keys_present(): void {
        $settings = IMC_Admin_Settings::get_settings();

        $expected_keys = [
            'enable_floating_switcher',
            'floating_position',
            'cookie_duration',
            'show_currency_badge',
            'badge_position',
            'auto_detect_language',
            'gtranslate_enabled',
            'gtranslate_reload_delay',
            'gtranslate_auto_defaults',
            'gtranslate_detect_method',
        ];

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $settings, "Missing default key: {$key}" );
        }
    }

    /* ── Position defaults ──────────────────────────────── */

    public function test_floating_position_default_bottom_left(): void {
        $this->assertSame( 'bottom-left', IMC_Admin_Settings::get( 'floating_position' ) );
    }

    public function test_badge_position_default_after(): void {
        $this->assertSame( 'after', IMC_Admin_Settings::get( 'badge_position' ) );
    }

    public function test_gtranslate_detect_method_default_both(): void {
        $this->assertSame( 'both', IMC_Admin_Settings::get( 'gtranslate_detect_method' ) );
    }

    /* ── Cookie duration range ──────────────────────────── */

    public function test_cookie_duration_is_positive_integer(): void {
        $duration = IMC_Admin_Settings::get( 'cookie_duration' );
        $this->assertIsInt( $duration );
        $this->assertGreaterThan( 0, $duration );
    }

    public function test_gtranslate_reload_delay_is_positive(): void {
        $delay = IMC_Admin_Settings::get( 'gtranslate_reload_delay' );
        $this->assertIsInt( $delay );
        $this->assertGreaterThanOrEqual( 100, $delay );
    }

    /* ── Saved settings persistence ─────────────────────── */

    public function test_saved_settings_completely_override_defaults(): void {
        $custom = [
            'enable_floating_switcher' => '0',
            'floating_position'        => 'top-right',
            'cookie_duration'          => 7,
            'show_currency_badge'      => '0',
            'badge_position'           => 'before',
            'auto_detect_language'     => '0',
            'gtranslate_enabled'       => '0',
            'gtranslate_reload_delay'  => 1200,
            'gtranslate_auto_defaults' => '0',
            'gtranslate_detect_method' => 'flags',
        ];
        update_option( 'imc_plugin_settings', $custom );

        $settings = IMC_Admin_Settings::get_settings();

        foreach ( $custom as $key => $value ) {
            $this->assertSame( $value, $settings[ $key ], "Mismatch for key: {$key}" );
        }
    }
}
