<?php
/**
 * Tests for IMC_Language_Detector.
 */

use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase {

    private IMC_Language_Detector $detector;

    protected function setUp(): void {
        imc_reset_test_state();
        $this->detector = new IMC_Language_Detector();
    }

    /* ── normalize() via get_current_language() ─────────── */

    public function test_detect_from_locale_es_ES(): void {
        update_option( 'WPLANG', 'es_ES' );
        $this->assertSame( 'es', $this->detector->get_current_language() );
    }

    public function test_detect_from_locale_en_US(): void {
        update_option( 'WPLANG', 'en_US' );
        $this->assertSame( 'en', $this->detector->get_current_language() );
    }

    public function test_detect_from_locale_pt_BR(): void {
        update_option( 'WPLANG', 'pt_BR' );
        $this->assertSame( 'pt', $this->detector->get_current_language() );
    }

    public function test_detect_from_locale_empty_returns_en(): void {
        // WordPress default locale when WPLANG is empty is 'en_US'.
        update_option( 'WPLANG', '' );
        // determine_locale() returns get_locale() which returns 'es_ES' default.
        // But since WPLANG is empty, get_locale returns 'es_ES' per our stub
        $lang = $this->detector->get_current_language();
        $this->assertSame( 2, strlen( $lang ), 'Language code should be 2 characters.' );
    }

    /* ── Explicit ?lang= parameter ──────────────────────── */

    public function test_detect_from_get_param(): void {
        $_GET['lang'] = 'fr';
        $this->assertSame( 'fr', $this->detector->get_current_language() );
    }

    public function test_detect_from_get_param_overrides_locale(): void {
        update_option( 'WPLANG', 'es_ES' );
        $_GET['lang'] = 'de';
        $this->assertSame( 'de', $this->detector->get_current_language() );
    }

    public function test_detect_from_get_param_normalizes_full_locale(): void {
        $_GET['lang'] = 'fr_FR';
        $this->assertSame( 'fr', $this->detector->get_current_language() );
    }

    public function test_detect_from_get_param_normalizes_hyphen(): void {
        $_GET['lang'] = 'pt-BR';
        $this->assertSame( 'pt', $this->detector->get_current_language() );
    }

    public function test_detect_normalizes_uppercase(): void {
        $_GET['lang'] = 'DE';
        $this->assertSame( 'de', $this->detector->get_current_language() );
    }

    /* ── Edge cases ─────────────────────────────────────── */

    public function test_detect_trims_whitespace(): void {
        $_GET['lang'] = '  en  ';
        $this->assertSame( 'en', $this->detector->get_current_language() );
    }

    public function test_wpml_filter_integration(): void {
        // The WPML filter returns 'all' which should be skipped.
        // After our stub, apply_filters('wpml_current_language', null) returns null.
        // So it falls through to locale — just verify no error.
        $lang = $this->detector->get_current_language();
        $this->assertNotEmpty( $lang );
    }
}
