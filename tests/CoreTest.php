<?php
/**
 * Tests for IMC_Core singleton and bootstrap.
 */

use PHPUnit\Framework\TestCase;

class CoreTest extends TestCase {

    protected function setUp(): void {
        imc_reset_test_state();
        delete_option( 'imc_db_version' );
        delete_option( 'imc_currencies' );
        delete_option( 'imc_language_currency_map' );

        imc_reset_singleton();
    }

    /* ── Singleton ──────────────────────────────────────── */

    public function test_singleton_returns_same_instance(): void {
        $a = IMC_Core::instance();
        $b = IMC_Core::instance();
        $this->assertSame( $a, $b );
    }

    public function test_imc_function_returns_core(): void {
        $core = IMC();
        $this->assertInstanceOf( IMC_Core::class, $core );
    }

    /* ── Component wiring ───────────────────────────────── */

    public function test_core_has_currency_manager(): void {
        $core = IMC_Core::instance();
        // Set up fresh components.
        $ref = new \ReflectionObject( $core );
        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, new IMC_Currency_Manager() );

        $this->assertInstanceOf( IMC_Currency_Manager::class, $core->currency_manager );
    }

    public function test_core_has_language_detector(): void {
        $core = IMC_Core::instance();
        $ref  = new \ReflectionObject( $core );
        $prop = $ref->getProperty( 'language_detector' );
        $prop->setValue( $core, new IMC_Language_Detector() );

        $this->assertInstanceOf( IMC_Language_Detector::class, $core->language_detector );
    }

    /* ── Public API ─────────────────────────────────────── */

    public function test_get_active_currency_delegates_to_manager(): void {
        $core = IMC_Core::instance();
        $mgr  = new IMC_Currency_Manager();
        $det  = new IMC_Language_Detector();

        $ref = new \ReflectionObject( $core );

        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $mgr );

        $prop = $ref->getProperty( 'language_detector' );
        $prop->setValue( $core, $det );

        $result = $core->get_active_currency();
        $this->assertSame( $mgr->get_active_currency(), $result );
    }

    public function test_get_default_currency_delegates_to_manager(): void {
        $core = IMC_Core::instance();
        $mgr  = new IMC_Currency_Manager();

        $ref  = new \ReflectionObject( $core );
        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $mgr );

        $this->assertSame( 'COP', $core->get_default_currency() );
    }

    /* ── Integration: active currency with GET param ────── */

    public function test_active_currency_via_get_param(): void {
        $_GET['imc_currency'] = 'EUR';

        $core = IMC_Core::instance();
        $mgr  = new IMC_Currency_Manager();
        $det  = new IMC_Language_Detector();

        $ref = new \ReflectionObject( $core );

        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $mgr );

        $prop = $ref->getProperty( 'language_detector' );
        $prop->setValue( $core, $det );

        $this->assertSame( 'EUR', $core->get_active_currency() );
    }
}
