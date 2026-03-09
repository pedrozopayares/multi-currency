<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for IMC_GitHub_Updater.
 */
class GitHubUpdaterTest extends TestCase {

    private IMC_GitHub_Updater $updater;

    protected function setUp(): void {
        imc_reset_test_state();
        $this->updater = new IMC_GitHub_Updater(
            IMC_PLUGIN_FILE,
            'pedrozopayares',
            'multi-currency',
            '1.0.0'
        );
    }

    protected function tearDown(): void {
        imc_reset_test_state();
    }

    /* ────────────────────────────────────────────────────────
     *  Constructor / wiring
     * ──────────────────────────────────────────────────────── */

    public function test_constructor_registers_update_filter(): void {
        global $wp_test_filters;
        $tags = array_keys( $wp_test_filters );
        $this->assertContains( 'pre_set_site_transient_update_plugins', $tags );
    }

    public function test_constructor_registers_plugins_api_filter(): void {
        global $wp_test_filters;
        $tags = array_keys( $wp_test_filters );
        $this->assertContains( 'plugins_api', $tags );
    }

    public function test_constructor_registers_post_install_filter(): void {
        global $wp_test_filters;
        $tags = array_keys( $wp_test_filters );
        $this->assertContains( 'upgrader_post_install', $tags );
    }

    /* ────────────────────────────────────────────────────────
     *  check_for_update()
     * ──────────────────────────────────────────────────────── */

    public function test_check_for_update_skips_when_checked_is_empty(): void {
        $transient = new stdClass();
        $result    = $this->updater->check_for_update( $transient );
        $this->assertSame( $transient, $result );
        $this->assertObjectNotHasProperty( 'response', $result );
    }

    public function test_check_for_update_adds_update_when_newer_version(): void {
        // Simulate GitHub API returning v2.0.0.
        $this->mock_github_release( '2.0.0' );

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        $result = $this->updater->check_for_update( $transient );

        $basename = 'impactos-multi-currency/impactos-multi-currency.php';
        $this->assertArrayHasKey( $basename, (array) $result->response );
        $this->assertSame( '2.0.0', $result->response[ $basename ]->new_version );
    }

    public function test_check_for_update_no_update_when_same_version(): void {
        $this->mock_github_release( '1.0.0' );

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        $result = $this->updater->check_for_update( $transient );

        $this->assertObjectNotHasProperty( 'response', $result );
        $basename = 'impactos-multi-currency/impactos-multi-currency.php';
        $this->assertArrayHasKey( $basename, (array) $result->no_update );
    }

    public function test_check_for_update_no_update_when_older_version(): void {
        $this->mock_github_release( '0.9.0' );

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        $result = $this->updater->check_for_update( $transient );

        $this->assertObjectNotHasProperty( 'response', $result );
    }

    public function test_check_for_update_handles_api_failure(): void {
        // Leave remote response as null → WP_Error will be returned.
        global $wp_test_remote_response;
        $wp_test_remote_response = null;

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        $result = $this->updater->check_for_update( $transient );

        // Should return transient unchanged (no crash).
        $this->assertSame( $transient, $result );
    }

    public function test_check_for_update_uses_zip_asset_url(): void {
        $this->mock_github_release( '2.0.0', [
            [
                'name'                 => 'impactos-multi-currency-2.0.0.zip',
                'browser_download_url' => 'https://github.com/assets/plugin.zip',
            ],
        ] );

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        $result   = $this->updater->check_for_update( $transient );
        $basename = 'impactos-multi-currency/impactos-multi-currency.php';

        $this->assertSame( 'https://github.com/assets/plugin.zip', $result->response[ $basename ]->package );
    }

    public function test_check_for_update_fallback_to_zipball_url(): void {
        $this->mock_github_release( '2.0.0', [] ); // No assets.

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        $result   = $this->updater->check_for_update( $transient );
        $basename = 'impactos-multi-currency/impactos-multi-currency.php';

        $this->assertStringContainsString( 'zipball', $result->response[ $basename ]->package );
    }

    /* ────────────────────────────────────────────────────────
     *  plugin_info()
     * ──────────────────────────────────────────────────────── */

    public function test_plugin_info_returns_result_for_wrong_action(): void {
        $result = $this->updater->plugin_info( false, 'query_plugins', (object) [ 'slug' => 'impactos-multi-currency' ] );
        $this->assertFalse( $result );
    }

    public function test_plugin_info_returns_result_for_wrong_slug(): void {
        $result = $this->updater->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'some-other-plugin' ] );
        $this->assertFalse( $result );
    }

    public function test_plugin_info_returns_details_for_matching_slug(): void {
        $this->mock_github_release( '2.0.0' );

        $info = $this->updater->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'impactos-multi-currency' ] );

        $this->assertIsObject( $info );
        $this->assertSame( 'impactos-multi-currency', $info->slug );
        $this->assertSame( '2.0.0', $info->version );
        $this->assertArrayHasKey( 'description', $info->sections );
        $this->assertArrayHasKey( 'changelog', $info->sections );
    }

    /* ────────────────────────────────────────────────────────
     *  post_install()
     * ──────────────────────────────────────────────────────── */

    public function test_post_install_ignores_other_plugins(): void {
        $result = [
            'destination'      => '/tmp/some-other-plugin',
            'destination_name' => 'some-other-plugin',
        ];
        $output = $this->updater->post_install( true, [ 'plugin' => 'other/other.php' ], $result );
        $this->assertSame( $result, $output );
    }

    public function test_post_install_fixes_directory_for_our_plugin(): void {
        // Create a mock wp_filesystem.
        global $wp_filesystem;
        $wp_filesystem = new class {
            public $moved_from = '';
            public $moved_to   = '';
            public function move( $from, $to ) {
                $this->moved_from = $from;
                $this->moved_to   = $to;
                return true;
            }
        };

        $result = [
            'destination'      => '/tmp/multi-currency-2.0.0',
            'destination_name' => 'multi-currency-2.0.0',
        ];

        $hook_extra = [ 'plugin' => 'impactos-multi-currency/impactos-multi-currency.php' ];

        $output = $this->updater->post_install( true, $hook_extra, $result );

        $this->assertSame( WP_PLUGIN_DIR . '/impactos-multi-currency', $output['destination'] );
        $this->assertSame( 'impactos-multi-currency', $output['destination_name'] );
        $this->assertSame( '/tmp/multi-currency-2.0.0', $wp_filesystem->moved_from );

        unset( $GLOBALS['wp_filesystem'] );
    }

    /* ────────────────────────────────────────────────────────
     *  Cache
     * ──────────────────────────────────────────────────────── */

    public function test_clear_cache_removes_transient(): void {
        global $wp_test_transients;
        // Simulate a cached release.
        $key = 'imc_gh_update_' . md5( 'impactos-multi-currency/impactos-multi-currency.php' );
        $wp_test_transients[ $key ] = [ 'tag_name' => 'v1.0.0' ];

        $this->updater->clear_cache();

        $this->assertArrayNotHasKey( $key, $wp_test_transients );
    }

    public function test_caches_github_response(): void {
        $this->mock_github_release( '2.0.0' );

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        // First call populates cache.
        $this->updater->check_for_update( $transient );

        global $wp_test_transients;
        $key = 'imc_gh_update_' . md5( 'impactos-multi-currency/impactos-multi-currency.php' );
        $this->assertArrayHasKey( $key, $wp_test_transients );
    }

    /* ────────────────────────────────────────────────────────
     *  Core integration
     * ──────────────────────────────────────────────────────── */

    public function test_core_initializes_updater(): void {
        imc_reset_singleton();
        $core = IMC();
        $this->assertInstanceOf( IMC_GitHub_Updater::class, $core->updater );
    }

    /* ────────────────────────────────────────────────────────
     *  Version tag normalization
     * ──────────────────────────────────────────────────────── */

    public function test_handles_v_prefixed_tag(): void {
        $this->mock_github_release( 'v2.0.0' );

        $transient          = new stdClass();
        $transient->checked = [ 'impactos-multi-currency/impactos-multi-currency.php' => '1.0.0' ];

        $result   = $this->updater->check_for_update( $transient );
        $basename = 'impactos-multi-currency/impactos-multi-currency.php';

        $this->assertSame( '2.0.0', $result->response[ $basename ]->new_version );
    }

    /* ────────────────────────────────────────────────────────
     *  Helper
     * ──────────────────────────────────────────────────────── */

    /**
     * Inject a fake GitHub release API response.
     */
    private function mock_github_release( string $version, array $assets = [] ): void {
        global $wp_test_remote_response, $wp_test_transients;

        // Clear transient cache first so the updater fetches fresh.
        $wp_test_transients = [];

        $release = [
            'tag_name'     => $version,
            'published_at' => '2025-01-15T10:00:00Z',
            'body'         => "### Changes\n- Feature A\n- Feature B",
            'zipball_url'  => "https://api.github.com/repos/pedrozopayares/multi-currency/zipball/{$version}",
            'assets'       => $assets,
        ];

        $wp_test_remote_response = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( $release ),
        ];
    }
}
