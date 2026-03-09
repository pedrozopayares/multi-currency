<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds multi-currency price fields to:
 *  - Simple / external product "General" pricing tab
 *  - Each product variation pricing section
 *
 * HPOS-compatible: uses WC product object API for saving.
 */
class IMC_Product_Fields {

    public function __construct() {
        // Simple products
        add_action( 'woocommerce_product_options_pricing', [ $this, 'render_simple_fields' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_simple_fields' ] );

        // Variations
        add_action( 'woocommerce_variation_options_pricing', [ $this, 'render_variation_fields' ], 10, 3 );
        add_action( 'woocommerce_admin_process_variation_object', [ $this, 'save_variation_fields' ], 10, 2 );

        // Enqueue admin CSS on product screens
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ── Assets ─────────────────────────────────────────── */

    public function enqueue_assets( $hook ) {
        $screen = get_current_screen();
        if ( $screen && 'product' === $screen->post_type ) {
            wp_enqueue_style( 'imc-admin', IMC_PLUGIN_URL . 'assets/css/admin.css', [], IMC_VERSION );
        }
    }

    /* ================================================================
     *  SIMPLE PRODUCTS
     * ============================================================= */

    public function render_simple_fields() {
        global $post;
        $currencies = IMC()->currency_manager->get_non_default_currencies();
        if ( empty( $currencies ) ) {
            return;
        }

        echo '</div>'; // close the default pricing options_group
        echo '<div class="options_group imc-product-prices">';
        echo '<p class="form-field"><strong>' . esc_html__( 'Precios multi-moneda', 'japp-mc' ) . '</strong></p>';

        foreach ( $currencies as $code => $c ) {
            woocommerce_wp_text_input( [
                'id'          => "_imc_regular_price_{$code}",
                'label'       => sprintf( '%s (%s) — %s', $c['symbol'], $code, __( 'Precio regular', 'japp-mc' ) ),
                'desc_tip'    => true,
                'description' => sprintf( __( 'Precio regular en %s', 'japp-mc' ), $c['name'] ),
                'data_type'   => 'price',
                'value'       => get_post_meta( $post->ID, "_imc_regular_price_{$code}", true ),
            ] );

            woocommerce_wp_text_input( [
                'id'          => "_imc_sale_price_{$code}",
                'label'       => sprintf( '%s (%s) — %s', $c['symbol'], $code, __( 'Precio de oferta', 'japp-mc' ) ),
                'desc_tip'    => true,
                'description' => sprintf( __( 'Precio de oferta en %s', 'japp-mc' ), $c['name'] ),
                'data_type'   => 'price',
                'value'       => get_post_meta( $post->ID, "_imc_sale_price_{$code}", true ),
            ] );
        }
    }

    /**
     * Save simple product multi-currency prices (HPOS-compatible).
     *
     * @param WC_Product $product  Product object about to be saved.
     */
    public function save_simple_fields( $product ) {
        $currencies = IMC()->currency_manager->get_non_default_currencies();

        foreach ( $currencies as $code => $c ) {
            $reg_key  = "_imc_regular_price_{$code}";
            $sale_key = "_imc_sale_price_{$code}";

            if ( isset( $_POST[ $reg_key ] ) ) { // phpcs:ignore
                $val = sanitize_text_field( wp_unslash( $_POST[ $reg_key ] ) );
                $product->update_meta_data( $reg_key, '' !== $val ? wc_format_decimal( $val ) : '' );
            }
            if ( isset( $_POST[ $sale_key ] ) ) { // phpcs:ignore
                $val = sanitize_text_field( wp_unslash( $_POST[ $sale_key ] ) );
                $product->update_meta_data( $sale_key, '' !== $val ? wc_format_decimal( $val ) : '' );
            }
        }
    }

    /* ================================================================
     *  VARIATIONS
     * ============================================================= */

    /**
     * Render price inputs inside each variation panel.
     *
     * @param int     $loop           0-based variation index.
     * @param array   $variation_data Variation data (deprecated in newer WC but still passed).
     * @param WP_Post $variation      The variation post object.
     */
    public function render_variation_fields( $loop, $variation_data, $variation ) {
        $currencies = IMC()->currency_manager->get_non_default_currencies();
        if ( empty( $currencies ) ) {
            return;
        }

        echo '<div class="imc-variation-prices">';
        echo '<p class="form-row form-row-full"><strong>' . esc_html__( 'Precios multi-moneda', 'japp-mc' ) . '</strong></p>';

        foreach ( $currencies as $code => $c ) {
            woocommerce_wp_text_input( [
                'id'            => "imc_var_regular_price_{$code}_{$loop}",
                'name'          => "imc_var_regular_price_{$code}[{$loop}]",
                'label'         => sprintf( '%s (%s) — %s', $c['symbol'], $code, __( 'Regular', 'japp-mc' ) ),
                'data_type'     => 'price',
                'value'         => get_post_meta( $variation->ID, "_imc_regular_price_{$code}", true ),
                'wrapper_class' => 'form-row form-row-first',
            ] );

            woocommerce_wp_text_input( [
                'id'            => "imc_var_sale_price_{$code}_{$loop}",
                'name'          => "imc_var_sale_price_{$code}[{$loop}]",
                'label'         => sprintf( '%s (%s) — %s', $c['symbol'], $code, __( 'Oferta', 'japp-mc' ) ),
                'data_type'     => 'price',
                'value'         => get_post_meta( $variation->ID, "_imc_sale_price_{$code}", true ),
                'wrapper_class' => 'form-row form-row-last',
            ] );
        }

        echo '</div>';
    }

    /**
     * Save variation multi-currency prices (HPOS-compatible).
     *
     * @param WC_Product_Variation $variation Variation object about to be saved.
     * @param int                  $i         0-based loop index.
     */
    public function save_variation_fields( $variation, $i ) {
        $currencies = IMC()->currency_manager->get_non_default_currencies();

        foreach ( $currencies as $code => $c ) {
            $reg_field  = "imc_var_regular_price_{$code}";
            $sale_field = "imc_var_sale_price_{$code}";

            if ( isset( $_POST[ $reg_field ][ $i ] ) ) { // phpcs:ignore
                $val = sanitize_text_field( wp_unslash( $_POST[ $reg_field ][ $i ] ) );
                $variation->update_meta_data( "_imc_regular_price_{$code}", '' !== $val ? wc_format_decimal( $val ) : '' );
            }
            if ( isset( $_POST[ $sale_field ][ $i ] ) ) { // phpcs:ignore
                $val = sanitize_text_field( wp_unslash( $_POST[ $sale_field ][ $i ] ) );
                $variation->update_meta_data( "_imc_sale_price_{$code}", '' !== $val ? wc_format_decimal( $val ) : '' );
            }
        }
    }
}
