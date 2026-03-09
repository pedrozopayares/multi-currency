<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted update checker for GitHub-hosted plugins.
 *
 * Checks the GitHub Releases API for new versions and injects update info
 * into the WordPress plugin update transient so users see the update
 * notification in wp-admin and can update with one click.
 *
 * No GitHub token required — uses public API (60 requests/hour per IP).
 * Results are cached via a transient to avoid excessive API calls.
 */
class IMC_GitHub_Updater {

    /** @var string GitHub username. */
    private $github_user;

    /** @var string GitHub repository name. */
    private $github_repo;

    /** @var string Plugin basename (e.g. "impactos-multi-currency/impactos-multi-currency.php"). */
    private $plugin_basename;

    /** @var string Current installed version. */
    private $current_version;

    /** @var string Plugin slug (directory name). */
    private $slug;

    /** @var string Transient key for caching GitHub response. */
    private $transient_key;

    /** @var int Cache duration in seconds (6 hours). */
    private $cache_duration = 21600;

    /**
     * @param string $plugin_file     Full path to the main plugin file (__FILE__).
     * @param string $github_user     GitHub username or organization.
     * @param string $github_repo     GitHub repository name.
     * @param string $current_version Installed plugin version (e.g. "1.0.0").
     */
    public function __construct( $plugin_file, $github_user, $github_repo, $current_version ) {
        $this->github_user     = $github_user;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;
        $this->plugin_basename = plugin_basename( $plugin_file );
        $this->slug            = dirname( $this->plugin_basename );
        $this->transient_key   = 'imc_gh_update_' . md5( $this->plugin_basename );

        // Hook into WP update system.
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );

        // Allow clearing the cache when the user clicks "Check Again".
        add_filter( 'site_transient_update_plugins', [ $this, 'maybe_clear_cache' ] );
    }

    /* ================================================================
     *  Check for updates (injected into update_plugins transient)
     * ============================================================= */

    /**
     * Compare the installed version with the latest GitHub release.
     * If a newer version is found, insert it into the update transient.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( ! $release || ! isset( $release['tag_name'] ) ) {
            return $transient;
        }

        $latest_version = ltrim( $release['tag_name'], 'vV' );

        if ( version_compare( $this->current_version, $latest_version, '<' ) ) {
            $download_url = $this->get_download_url( $release );

            if ( $download_url ) {
                $transient->response[ $this->plugin_basename ] = (object) [
                    'slug'        => $this->slug,
                    'plugin'      => $this->plugin_basename,
                    'new_version' => $latest_version,
                    'url'         => $this->get_repo_url(),
                    'package'     => $download_url,
                    'icons'       => [],
                    'banners'     => [],
                    'tested'      => '',
                    'requires'    => '6.0',
                    'requires_php'=> '7.4',
                ];
            }
        } else {
            // No update — but WP still needs to know we checked.
            $transient->no_update[ $this->plugin_basename ] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $this->current_version,
                'url'         => $this->get_repo_url(),
                'package'     => '',
            ];
        }

        return $transient;
    }

    /* ================================================================
     *  Plugin information popup (wp-admin → Plugins → "View details")
     * ============================================================= */

    /**
     * Provide plugin information for the WordPress plugin details popup.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $result;
        }

        $latest_version = ltrim( $release['tag_name'], 'vV' );

        $info = (object) [
            'name'            => 'Multi Currency with Multi Language Detector for WooCommerce',
            'slug'            => $this->slug,
            'version'         => $latest_version,
            'author'          => '<a href="https://github.com/pedrozopayares">Javier Andrés Pedrozo Payares</a>',
            'author_profile'  => 'https://github.com/pedrozopayares',
            'homepage'        => $this->get_repo_url(),
            'download_link'   => $this->get_download_url( $release ),
            'requires'        => '6.0',
            'tested'          => '',
            'requires_php'    => '7.4',
            'last_updated'    => $release['published_at'] ?? '',
            'sections'        => [
                'description'  => $this->get_description(),
                'changelog'    => $this->format_changelog( $release ),
            ],
        ];

        return $info;
    }

    /* ================================================================
     *  Post-install: fix directory name after GitHub zip extraction
     * ============================================================= */

    /**
     * GitHub zip archives extract to "repo-name-tag/" instead of "plugin-slug/".
     * Rename the extracted directory to match the expected plugin slug.
     */
    public function post_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
            return $result;
        }

        global $wp_filesystem;

        $proper_destination = WP_PLUGIN_DIR . '/' . $this->slug;

        // Move the extracted folder to the correct location.
        $wp_filesystem->move( $result['destination'], $proper_destination );
        $result['destination']      = $proper_destination;
        $result['destination_name'] = $this->slug;

        // Re-activate the plugin if it was active.
        if ( is_plugin_active( $this->plugin_basename ) ) {
            activate_plugin( $this->plugin_basename );
        }

        return $result;
    }

    /* ================================================================
     *  GitHub API
     * ============================================================= */

    /**
     * Fetch the latest release from GitHub (cached).
     *
     * @return array|null  Decoded release data or null on failure.
     */
    private function get_latest_release() {
        $cached = get_transient( $this->transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'headers'    => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ],
        ] );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache the failure briefly (30 min) to avoid hammering the API.
            set_transient( $this->transient_key, null, 1800 );
            return null;
        }

        $body    = wp_remote_retrieve_body( $response );
        $release = json_decode( $body, true );

        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            set_transient( $this->transient_key, null, 1800 );
            return null;
        }

        set_transient( $this->transient_key, $release, $this->cache_duration );

        return $release;
    }

    /**
     * Get the zip download URL from a release.
     * Prefers the first attached .zip asset; falls back to the auto-generated zipball.
     */
    private function get_download_url( $release ) {
        // Check for uploaded .zip assets first (e.g. "impactos-multi-currency-1.0.0.zip").
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( isset( $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fallback: GitHub's auto-generated source zipball.
        return $release['zipball_url'] ?? '';
    }

    /* ================================================================
     *  Cache management
     * ============================================================= */

    /**
     * Clear the cached release data when WordPress rechecks for updates.
     */
    public function maybe_clear_cache( $transient ) {
        // When "Check Again" is clicked, WP deletes and rebuilds the transient.
        // This hook fires when the transient is freshly populated.
        // We don't forcefully clear here — the cache expires naturally (6h).
        return $transient;
    }

    /**
     * Force-clear the cached release data. Useful after plugin settings change.
     */
    public function clear_cache() {
        delete_transient( $this->transient_key );
    }

    /* ================================================================
     *  Helpers
     * ============================================================= */

    private function get_repo_url() {
        return sprintf( 'https://github.com/%s/%s', $this->github_user, $this->github_repo );
    }

    private function get_description() {
        return '<p>Plugin de WordPress/WooCommerce que cambia automáticamente la moneda según el idioma del visitante. '
             . 'Permite definir precios por moneda en cada producto y variación.</p>'
             . '<h4>Características</h4>'
             . '<ul>'
             . '<li>Multi-moneda: COP, USD, EUR y cualquier otra moneda personalizada</li>'
             . '<li>Detección de idioma: Polylang, WPML, TranslatePress, GTranslate</li>'
             . '<li>Mapeo Idioma → Moneda automático</li>'
             . '<li>Precios por producto y variación</li>'
             . '<li>Selector flotante con banderas</li>'
             . '<li>Shortcode [imc_currency_switcher]</li>'
             . '<li>Integración con GTranslate</li>'
             . '<li>Panel de administración con pestañas</li>'
             . '<li>Compatible con HPOS de WooCommerce</li>'
             . '</ul>';
    }

    /**
     * Format the release body (Markdown) into basic HTML for the changelog tab.
     */
    private function format_changelog( $release ) {
        $version = ltrim( $release['tag_name'], 'vV' );
        $date    = isset( $release['published_at'] )
            ? date_i18n( 'F j, Y', strtotime( $release['published_at'] ) )
            : '';

        $body = $release['body'] ?? '';

        // Basic Markdown → HTML conversion for changelogs.
        $body = esc_html( $body );
        $body = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $body );
        $body = preg_replace( '/^## (.+)$/m',  '<h3>$1</h3>', $body );
        $body = preg_replace( '/^- (.+)$/m',   '<li>$1</li>', $body );
        $body = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $body );
        $body = nl2br( $body );

        return sprintf( '<h3>%s — %s</h3>%s', esc_html( $version ), esc_html( $date ), $body );
    }
}
