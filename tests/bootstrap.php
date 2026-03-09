<?php
/**
 * PHPUnit bootstrap for Multi Currency plugin.
 *
 * Provides lightweight stubs for WordPress and WooCommerce functions
 * so unit tests run without a full WordPress environment.
 */

// ── Constants ──────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/var/www/html/' );
}
define( 'IMC_VERSION',     '1.0.0' );
define( 'IMC_PLUGIN_FILE', dirname( __DIR__ ) . '/impactos-multi-currency.php' );
define( 'IMC_PLUGIN_DIR',  dirname( __DIR__ ) . '/' );
define( 'IMC_PLUGIN_URL',  'http://localhost/wp-content/plugins/impactos-multi-currency/' );
define( 'DAY_IN_SECONDS',  86400 );
define( 'COOKIEPATH',      '/' );
define( 'COOKIE_DOMAIN',   '' );

// ── Global options store (simulates WP options DB) ─────────
global $wp_test_options, $wp_test_filters, $wp_test_actions;
$wp_test_options = [
    'woocommerce_currency'              => 'COP',
    'woocommerce_currency_pos'          => 'left',
    'woocommerce_price_num_decimals'    => 0,
    'woocommerce_price_decimal_sep'     => ',',
    'woocommerce_price_thousand_sep'    => '.',
];
$wp_test_filters = [];
$wp_test_actions = [];

// ── WordPress function stubs ───────────────────────────────

function get_option( $key, $default = false ) {
    global $wp_test_options;
    return array_key_exists( $key, $wp_test_options ) ? $wp_test_options[ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
    global $wp_test_options;
    $wp_test_options[ $key ] = $value;
    return true;
}

function delete_option( $key ) {
    global $wp_test_options;
    unset( $wp_test_options[ $key ] );
    return true;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
    global $wp_test_filters;
    $wp_test_filters[ $tag ][] = [
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    ];
    return true;
}

function apply_filters( $tag, $value, ...$args ) {
    global $wp_test_filters;
    if ( ! empty( $wp_test_filters[ $tag ] ) ) {
        foreach ( $wp_test_filters[ $tag ] as $filter ) {
            $callback = $filter['callback'];
            $num_args = $filter['accepted_args'];
            $call_args = array_merge( [ $value ], array_slice( $args, 0, max( 0, $num_args - 1 ) ) );
            $value = call_user_func_array( $callback, $call_args );
        }
    }
    return $value;
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
    return add_filter( $tag, $callback, $priority, $accepted_args );
}

function do_action( $tag, ...$args ) {
    global $wp_test_filters;
    if ( ! empty( $wp_test_filters[ $tag ] ) ) {
        foreach ( $wp_test_filters[ $tag ] as $filter ) {
            call_user_func_array( $filter['callback'], array_slice( $args, 0, $filter['accepted_args'] ) );
        }
    }
}

function add_shortcode( $tag, $callback ) {
    // Stub: no-op in tests.
}

function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
    $atts = (array) $atts;
    $out  = [];
    foreach ( $defaults as $name => $default ) {
        $out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
    }
    return $out;
}

function sanitize_text_field( $str ) {
    return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
}

function wp_unslash( $value ) {
    return is_string( $value ) ? stripslashes( $value ) : $value;
}

function absint( $val ) {
    return abs( (int) $val );
}

function wp_parse_args( $args, $defaults = [] ) {
    if ( is_object( $args ) ) {
        $args = get_object_vars( $args );
    } elseif ( is_string( $args ) ) {
        parse_str( $args, $args );
    }
    return array_merge( $defaults, $args );
}

function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
    return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function esc_html__( $text, $domain = 'default' ) {
    return $text;
}

function esc_html_e( $text, $domain = 'default' ) {
    echo $text;
}

function esc_attr_e( $text, $domain = 'default' ) {
    echo esc_attr( $text );
}

function __( $text, $domain = 'default' ) {
    return $text;
}

function selected( $selected, $current = true, $echo = true ) {
    $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
    if ( $echo ) {
        echo $result;
    }
    return $result;
}

function checked( $checked, $current = true, $echo = true ) {
    $result = (string) $checked === (string) $current ? ' checked="checked"' : '';
    if ( $echo ) {
        echo $result;
    }
    return $result;
}

function is_admin() {
    global $wp_test_is_admin;
    return ! empty( $wp_test_is_admin );
}

function wp_doing_ajax() {
    global $wp_test_doing_ajax;
    return ! empty( $wp_test_doing_ajax );
}

function wp_get_raw_referer() {
    global $wp_test_raw_referer;
    return $wp_test_raw_referer ?? false;
}

function admin_url( $path = '' ) {
    return 'http://localhost/wp-admin/' . ltrim( $path, '/' );
}

function is_ssl() {
    return false;
}

// Note: headers_sent() is a PHP built-in, cannot be redeclared.
// The plugin checks headers_sent() before setcookie(); in CLI/tests it returns false naturally.

function get_locale() {
    return get_option( 'WPLANG', 'es_ES' ) ?: 'es_ES';
}

function determine_locale() {
    return get_locale();
}

function add_query_arg( ...$args ) {
    if ( count( $args ) === 2 && is_string( $args[0] ) ) {
        return '?' . $args[0] . '=' . $args[1];
    }
    return '?';
}

function remove_query_arg( $key, $url = '' ) {
    return '/';
}

function wp_safe_redirect( $location, $status = 302 ) {
    // Stub: no-op in tests.
}

function wp_verify_nonce( $nonce, $action = '' ) {
    return $nonce === 'valid_nonce' ? 1 : false;
}

function wp_nonce_field( $action, $name, $referer = true, $echo = true ) {
    if ( $echo ) {
        echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="valid_nonce">';
    }
}

function current_user_can( $capability ) {
    global $wp_test_user_can;
    return $wp_test_user_can ?? true;
}

function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}
function wp_localize_script( $handle, $name, $data ) {
    global $wp_test_localized_scripts;
    $wp_test_localized_scripts[ $handle ] = [ 'name' => $name, 'data' => $data ];
}
function plugin_dir_path( $file ) {
    return dirname( $file ) . '/';
}
function plugin_dir_url( $file ) {
    return 'http://localhost/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
function add_submenu_page( ...$args ) {}
function is_plugin_active( $plugin ) {
    global $wp_test_active_plugins;
    return in_array( $plugin, $wp_test_active_plugins ?? [], true );
}

// ── Transient stubs (used by GitHub Updater) ───────────────
global $wp_test_transients;
$wp_test_transients = [];

function get_transient( $key ) {
    global $wp_test_transients;
    return $wp_test_transients[ $key ] ?? false;
}

function set_transient( $key, $value, $expiration = 0 ) {
    global $wp_test_transients;
    $wp_test_transients[ $key ] = $value;
    return true;
}

function delete_transient( $key ) {
    global $wp_test_transients;
    unset( $wp_test_transients[ $key ] );
    return true;
}

// ── HTTP stubs ─────────────────────────────────────────────
global $wp_test_remote_response;
$wp_test_remote_response = null;

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors = [];
        public function __construct( $code = '', $message = '' ) {
            if ( $code ) {
                $this->errors[ $code ] = [ $message ];
            }
        }
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

function wp_remote_get( $url, $args = [] ) {
    global $wp_test_remote_response;
    if ( null !== $wp_test_remote_response ) {
        return $wp_test_remote_response;
    }
    return new WP_Error( 'http_request_failed', 'Stubbed: no response configured' );
}

function wp_remote_retrieve_response_code( $response ) {
    if ( is_wp_error( $response ) ) {
        return 0;
    }
    return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body( $response ) {
    if ( is_wp_error( $response ) ) {
        return '';
    }
    return $response['body'] ?? '';
}

// ── Misc WP stubs needed by GitHub Updater ─────────────────
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', '/var/www/html/wp-content/plugins' );
}

function plugin_basename( $file ) {
    $plugin_dir = WP_PLUGIN_DIR . '/';
    $file = str_replace( '\\', '/', $file );
    $file = preg_replace( '#^.*wp-content/plugins/#', '', $file );
    return $file;
}

function get_bloginfo( $show = '' ) {
    if ( 'version' === $show ) {
        return '6.7';
    }
    return '';
}

function home_url( $path = '' ) {
    return 'http://localhost' . $path;
}

function activate_plugin( $plugin, $redirect = '', $network_wide = false, $silent = false ) {
    return null;
}

function date_i18n( $format, $timestamp = false, $gmt = false ) {
    return date( $format, $timestamp ?: time() );
}

function nl2br_safe( $str ) {
    return nl2br( $str );
}

// ── WooCommerce function stubs ─────────────────────────────

function get_woocommerce_currency_symbols() {
    return [
        'COP' => '$',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'MXN' => '$',
        'BRL' => 'R$',
        'JPY' => '¥',
    ];
}

function get_woocommerce_currencies() {
    return [
        'COP' => 'Colombian peso',
        'USD' => 'United States (US) dollar',
        'EUR' => 'Euro',
        'GBP' => 'British pound',
        'MXN' => 'Mexican peso',
        'BRL' => 'Brazilian real',
        'JPY' => 'Japanese yen',
    ];
}

function get_woocommerce_currency() {
    return get_option( 'woocommerce_currency', 'COP' );
}

function wc_format_decimal( $number, $dp = false, $trim_zeros = false ) {
    return (string) floatval( str_replace( ',', '.', $number ) );
}

/**
 * Minimal WC_Product mock.
 */
class WC_Product {
    private $meta = [];
    private $id;
    private $children = [];

    /** @var array<int, WC_Product> Global product registry for wc_get_product(). */
    private static $registry = [];

    public function __construct( $id = 1 ) {
        $this->id = $id;
        self::$registry[ $id ] = $this;
    }

    public function get_id() {
        return $this->id;
    }

    public function set_meta( $key, $value ) {
        $this->meta[ $key ] = $value;
    }

    public function get_meta( $key, $single = true ) {
        return $this->meta[ $key ] ?? '';
    }

    public function set_children( array $ids ) {
        $this->children = $ids;
    }

    public function get_children() {
        return $this->children;
    }

    public static function get_from_registry( $id ) {
        return self::$registry[ $id ] ?? null;
    }

    public static function clear_registry() {
        self::$registry = [];
    }
}

function wc_get_product( $id ) {
    return WC_Product::get_from_registry( $id ) ?? new WC_Product( $id );
}

// ── Helper to reset global state between tests ─────────────

function imc_reset_test_state() {
    global $wp_test_options, $wp_test_filters, $wp_test_is_admin,
           $wp_test_doing_ajax, $wp_test_raw_referer, $wp_test_user_can,
           $wp_test_active_plugins, $wp_test_localized_scripts,
           $wp_test_transients, $wp_test_remote_response;

    $wp_test_options = [
        'woocommerce_currency'              => 'COP',
        'woocommerce_currency_pos'          => 'left',
        'woocommerce_price_num_decimals'    => 0,
        'woocommerce_price_decimal_sep'     => ',',
        'woocommerce_price_thousand_sep'    => '.',
    ];
    $wp_test_filters           = [];
    $wp_test_is_admin          = false;
    $wp_test_doing_ajax        = false;
    $wp_test_raw_referer       = false;
    $wp_test_user_can          = true;
    $wp_test_active_plugins    = [];
    $wp_test_localized_scripts = [];
    $wp_test_transients        = [];
    $wp_test_remote_response   = null;

    // Clean superglobals used by the plugin.
    unset( $_GET['imc_currency'], $_GET['lang'], $_COOKIE['imc_currency'] );
    unset( $_POST['imc_save_settings'], $_POST['imc_nonce'], $_POST['imc_currency'], $_POST['imc_lang'], $_POST['imc_settings'] );

    // Clear the WC_Product registry.
    WC_Product::clear_registry();
}

// ── Load plugin classes ────────────────────────────────────

require_once IMC_PLUGIN_DIR . 'includes/class-imc-admin-settings.php';
require_once IMC_PLUGIN_DIR . 'includes/class-imc-currency-manager.php';
require_once IMC_PLUGIN_DIR . 'includes/class-imc-language-detector.php';
require_once IMC_PLUGIN_DIR . 'includes/class-imc-price-handler.php';
require_once IMC_PLUGIN_DIR . 'includes/class-imc-frontend.php';
require_once IMC_PLUGIN_DIR . 'includes/class-imc-product-fields.php';
require_once IMC_PLUGIN_DIR . 'includes/class-imc-github-updater.php';
require_once IMC_PLUGIN_DIR . 'includes/class-imc-core.php';

/**
 * Global accessor matching the main plugin file.
 */
function IMC() {
    return IMC_Core::instance();
}

/**
 * Reset the IMC_Core singleton for test isolation.
 * Creates a fresh set of components.
 */
function imc_reset_singleton() {
    $core = IMC_Core::instance();
    $ref  = new \ReflectionObject( $core );

    // Reset the cached active_currency so each test starts clean.
    $mgr = new IMC_Currency_Manager();
    $det = new IMC_Language_Detector();

    $prop = $ref->getProperty( 'currency_manager' );
    $prop->setValue( $core, $mgr );

    $prop = $ref->getProperty( 'language_detector' );
    $prop->setValue( $core, $det );

    return $core;
}
