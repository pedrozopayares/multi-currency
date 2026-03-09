<?php
/**
 * Plugin Name: Multi Currency with Multi Language Detector for WooCommerce
 * Plugin URI:  https://github.com/pedrozopayares/multi-currency
 * Description: Cambia automáticamente la moneda de WooCommerce según el idioma del sitio. Permite definir precios por moneda en cada producto y variación.
 * Version:     1.0.4
 * Author:      Javier Andrés Pedrozo Payares
 * Author URI:  https://github.com/pedrozopayares
 * License:     GPL-2.0+
 * Text Domain: japp-mc
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 */

defined( 'ABSPATH' ) || exit;

define( 'IMC_VERSION',    '1.0.4' );
define( 'IMC_PLUGIN_FILE', __FILE__ );
define( 'IMC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'IMC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/* ── HPOS compatibility ─────────────────────────────────── */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
} );

/* ── Bootstrap ──────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="error"><p><strong>Multi Currency</strong> %s</p></div>',
                esc_html__( 'requiere WooCommerce activo.', 'japp-mc' )
            );
        } );
        return;
    }

    require_once IMC_PLUGIN_DIR . 'includes/class-imc-core.php';
    IMC(); // initialize singleton
} );

/**
 * Global accessor for the plugin core instance.
 *
 * @return IMC_Core
 */
function IMC() {
    return IMC_Core::instance();
}
