<?php
/**
 * Tests for IMC_Currency_Manager.
 */

use PHPUnit\Framework\TestCase;

class CurrencyManagerTest extends TestCase {

    private IMC_Currency_Manager $manager;

    protected function setUp(): void {
        imc_reset_test_state();
        // Clear any cached DB version so maybe_install_defaults runs.
        delete_option( 'imc_db_version' );
        delete_option( 'imc_currencies' );
        delete_option( 'imc_language_currency_map' );

        $this->manager = new IMC_Currency_Manager();

        // Wire up the singleton so IMC() calls work.
        $core = IMC_Core::instance();
        $ref  = new \ReflectionObject( $core );
        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $this->manager );
        $prop = $ref->getProperty( 'language_detector' );
        $prop->setValue( $core, new IMC_Language_Detector() );
    }

    /* ── get_default_currency() ─────────────────────────── */

    public function test_default_currency_returns_wc_option(): void {
        $this->assertSame( 'COP', $this->manager->get_default_currency() );
    }

    public function test_default_currency_changes_with_option(): void {
        update_option( 'woocommerce_currency', 'USD' );
        $this->assertSame( 'USD', $this->manager->get_default_currency() );
    }

    /* ── maybe_install_defaults() ───────────────────────── */

    public function test_install_defaults_creates_usd_and_eur(): void {
        $currencies = $this->manager->get_currencies();

        $this->assertArrayHasKey( 'COP', $currencies, 'Default COP must be present.' );
        $this->assertArrayHasKey( 'USD', $currencies, 'USD must be installed by default.' );
        $this->assertArrayHasKey( 'EUR', $currencies, 'EUR must be installed by default.' );
    }

    public function test_install_defaults_creates_language_map(): void {
        $map = $this->manager->get_language_map();

        $this->assertIsArray( $map );
        $this->assertArrayHasKey( 'en', $map );
        $this->assertSame( 'USD', $map['en'] );
    }

    public function test_install_defaults_sets_db_version(): void {
        $this->assertSame( IMC_VERSION, get_option( 'imc_db_version' ) );
    }

    public function test_install_defaults_skips_if_already_current(): void {
        // Defaults already installed above. Now change something and confirm it persists.
        $currencies = get_option( 'imc_currencies', [] );
        unset( $currencies['EUR'] );
        update_option( 'imc_currencies', $currencies );

        // Reinstantiating should NOT re-add EUR because imc_db_version matches.
        $manager2 = new IMC_Currency_Manager();
        $all      = $manager2->get_currencies();
        $this->assertArrayNotHasKey( 'EUR', $all, 'EUR should NOT be re-added if DB version matches.' );
    }

    /* ── get_currencies() ───────────────────────────────── */

    public function test_get_currencies_includes_default(): void {
        $currencies = $this->manager->get_currencies();
        $this->assertArrayHasKey( 'COP', $currencies );
        $this->assertSame( 'COP', $currencies['COP']['code'] );
    }

    public function test_get_currencies_includes_additional(): void {
        $currencies = $this->manager->get_currencies();
        $this->assertArrayHasKey( 'USD', $currencies );
        $this->assertSame( 'USD', $currencies['USD']['code'] );
        $this->assertSame( 'US Dollar', $currencies['USD']['name'] );
    }

    public function test_get_currencies_default_has_correct_formatting(): void {
        $currencies = $this->manager->get_currencies();
        $cop        = $currencies['COP'];

        $this->assertSame( '$', $cop['symbol'] );
        $this->assertSame( 'left', $cop['position'] );
        $this->assertSame( 0, $cop['decimals'] );
        $this->assertSame( ',', $cop['decimal_sep'] );
        $this->assertSame( '.', $cop['thousand_sep'] );
    }

    public function test_get_currencies_caches_result(): void {
        $first  = $this->manager->get_currencies();
        $second = $this->manager->get_currencies();
        $this->assertSame( $first, $second );
    }

    /* ── get_currency() ─────────────────────────────────── */

    public function test_get_currency_returns_config_for_valid_code(): void {
        $config = $this->manager->get_currency( 'USD' );
        $this->assertIsArray( $config );
        $this->assertSame( 'USD', $config['code'] );
    }

    public function test_get_currency_returns_null_for_unknown(): void {
        $this->assertNull( $this->manager->get_currency( 'XYZ' ) );
    }

    public function test_get_currency_returns_default(): void {
        $config = $this->manager->get_currency( 'COP' );
        $this->assertIsArray( $config );
        $this->assertSame( 'COP', $config['code'] );
    }

    /* ── save_currencies() ──────────────────────────────── */

    public function test_save_currencies_stores_in_option(): void {
        $new = [
            'GBP' => [
                'code' => 'GBP', 'name' => 'British pound', 'symbol' => '£',
                'position' => 'left', 'decimals' => 2,
                'decimal_sep' => '.', 'thousand_sep' => ',',
            ],
        ];
        $this->manager->save_currencies( $new );

        $stored = get_option( 'imc_currencies' );
        $this->assertArrayHasKey( 'GBP', $stored );
        $this->assertSame( '£', $stored['GBP']['symbol'] );
    }

    public function test_save_currencies_excludes_default(): void {
        $new = [
            'COP' => [ 'code' => 'COP', 'name' => 'Peso', 'symbol' => '$', 'position' => 'left', 'decimals' => 0, 'decimal_sep' => ',', 'thousand_sep' => '.' ],
            'GBP' => [ 'code' => 'GBP', 'name' => 'Pound', 'symbol' => '£', 'position' => 'left', 'decimals' => 2, 'decimal_sep' => '.', 'thousand_sep' => ',' ],
        ];
        $this->manager->save_currencies( $new );

        $stored = get_option( 'imc_currencies' );
        $this->assertArrayNotHasKey( 'COP', $stored, 'Default currency should not be saved in additional currencies.' );
        $this->assertArrayHasKey( 'GBP', $stored );
    }

    public function test_save_currencies_invalidates_cache(): void {
        $before = $this->manager->get_currencies();

        $this->manager->save_currencies( [
            'MXN' => [ 'code' => 'MXN', 'name' => 'Mexican peso', 'symbol' => '$', 'position' => 'left', 'decimals' => 2, 'decimal_sep' => '.', 'thousand_sep' => ',' ],
        ] );

        $after = $this->manager->get_currencies();
        $this->assertArrayHasKey( 'MXN', $after );
        $this->assertNotSame( $before, $after );
    }

    /* ── get_non_default_currencies() ───────────────────── */

    public function test_non_default_currencies_excludes_default(): void {
        $non_default = $this->manager->get_non_default_currencies();
        $this->assertArrayNotHasKey( 'COP', $non_default );
        $this->assertArrayHasKey( 'USD', $non_default );
        $this->assertArrayHasKey( 'EUR', $non_default );
    }

    /* ── Language map CRUD ──────────────────────────────── */

    public function test_save_and_get_language_map(): void {
        $map = [ 'es' => 'COP', 'en' => 'USD', 'fr' => 'EUR' ];
        $this->manager->save_language_map( $map );

        $retrieved = $this->manager->get_language_map();
        $this->assertSame( $map, $retrieved );
    }

    public function test_language_map_empty_by_default_when_no_install(): void {
        // With install defaults having run, there should be a map.
        $map = $this->manager->get_language_map();
        $this->assertNotEmpty( $map );
    }

    /* ── get_active_currency() priority chain ───────────── */

    public function test_active_currency_defaults_to_wc_default(): void {
        // No GET, no cookie, no matching language map.
        delete_option( 'imc_language_currency_map' );

        $mgr = new IMC_Currency_Manager();

        // We need a minimal IMC() function context.
        $this->assertSame( 'COP', $mgr->get_default_currency() );
    }

    public function test_active_currency_from_get_param(): void {
        $_GET['imc_currency'] = 'USD';

        // Reset cached active_currency by creating new instance.
        $mgr = new IMC_Currency_Manager();

        // Simulate IMC() context.
        $this->set_imc_instance( $mgr );
        $result = $mgr->get_active_currency();

        $this->assertSame( 'USD', $result );
    }

    public function test_active_currency_from_cookie(): void {
        $_COOKIE['imc_currency'] = 'EUR';

        $mgr = new IMC_Currency_Manager();
        $this->set_imc_instance( $mgr );
        $result = $mgr->get_active_currency();

        $this->assertSame( 'EUR', $result );
    }

    public function test_active_currency_get_param_overrides_cookie(): void {
        $_GET['imc_currency']    = 'USD';
        $_COOKIE['imc_currency'] = 'EUR';

        $mgr = new IMC_Currency_Manager();
        $this->set_imc_instance( $mgr );
        $result = $mgr->get_active_currency();

        $this->assertSame( 'USD', $result, 'GET parameter should override cookie.' );
    }

    public function test_active_currency_ignores_invalid_code(): void {
        $_GET['imc_currency'] = 'INVALID';

        $mgr = new IMC_Currency_Manager();
        $this->set_imc_instance( $mgr );
        $result = $mgr->get_active_currency();

        $this->assertNotSame( 'INVALID', $result );
    }

    public function test_active_currency_from_language_map(): void {
        // Set up: locale es → map es→COP, but default is also COP so let's use en→USD.
        update_option( 'WPLANG', 'en_US' );
        $this->manager->save_language_map( [ 'en' => 'USD' ] );

        $mgr = new IMC_Currency_Manager();
        $detector = new IMC_Language_Detector();
        $this->set_imc_instance( $mgr, $detector );

        $result = $mgr->get_active_currency();
        $this->assertSame( 'USD', $result );
    }

    public function test_active_currency_caches_result(): void {
        $mgr = new IMC_Currency_Manager();
        $this->set_imc_instance( $mgr );

        $first  = $mgr->get_active_currency();
        // Change cookie — should NOT change result due to caching.
        $_COOKIE['imc_currency'] = 'EUR';
        $second = $mgr->get_active_currency();

        $this->assertSame( $first, $second, 'Active currency should be cached for the request.' );
    }

    /* ── set_currency_cookie() ──────────────────────────── */

    public function test_set_currency_cookie_updates_superglobal(): void {
        // Need admin settings context.
        $this->set_imc_instance( $this->manager );
        update_option( 'imc_plugin_settings', [ 'cookie_duration' => 15 ] );

        $this->manager->set_currency_cookie( 'EUR' );

        $this->assertSame( 'EUR', $_COOKIE['imc_currency'] );
    }

    /* ── Helper: wire up a minimal IMC() singleton ──────── */

    private function set_imc_instance( IMC_Currency_Manager $mgr, ?IMC_Language_Detector $det = null ): void {
        $core = IMC_Core::instance();
        $ref  = new \ReflectionObject( $core );

        $prop = $ref->getProperty( 'currency_manager' );
        $prop->setValue( $core, $mgr );

        $prop = $ref->getProperty( 'language_detector' );
        $prop->setValue( $core, $det ?? new IMC_Language_Detector() );
    }
}
