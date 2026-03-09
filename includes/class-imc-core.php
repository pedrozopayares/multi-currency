<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin orchestrator – singleton that wires every component.
 */
class IMC_Core {

    private static $instance = null;

    /** @var IMC_Currency_Manager */
    public $currency_manager;

    /** @var IMC_Language_Detector */
    public $language_detector;

    /** @var IMC_Price_Handler */
    public $price_handler;

    /** @var IMC_Admin_Settings|null */
    public $admin_settings;

    /** @var IMC_Product_Fields|null */
    public $product_fields;

    /** @var IMC_Frontend|null */
    public $frontend;

    /* ── Singleton ──────────────────────────────────────── */

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
    }

    /* ── Helpers ────────────────────────────────────────── */

    private function load_dependencies() {
        $dir = IMC_PLUGIN_DIR . 'includes/';
        require_once $dir . 'class-imc-currency-manager.php';
        require_once $dir . 'class-imc-language-detector.php';
        require_once $dir . 'class-imc-price-handler.php';
        require_once $dir . 'class-imc-admin-settings.php';
        require_once $dir . 'class-imc-product-fields.php';
        require_once $dir . 'class-imc-frontend.php';
    }

    private function init_components() {
        $this->currency_manager  = new IMC_Currency_Manager();
        $this->language_detector = new IMC_Language_Detector();
        $this->price_handler     = new IMC_Price_Handler();

        if ( is_admin() ) {
            $this->admin_settings = new IMC_Admin_Settings();
            $this->product_fields = new IMC_Product_Fields();
        }

        // Frontend + AJAX (WC AJAX goes through admin-ajax.php so is_admin() is true)
        $this->frontend = new IMC_Frontend();
    }

    /* ── Public API ─────────────────────────────────────── */

    /**
     * Currency code currently active (e.g. "USD").
     */
    public function get_active_currency() {
        return $this->currency_manager->get_active_currency();
    }

    /**
     * WooCommerce default currency code.
     */
    public function get_default_currency() {
        return $this->currency_manager->get_default_currency();
    }
}
