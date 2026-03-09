<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Multi-Moneda settings page.
 *
 * Tabbed interface:
 *  1. Monedas – Additional currencies & language mapping
 *  2. Ajustes generales – Floating switcher, cookie, badge, detection
 *  3. GTranslate – Integration with the GTranslate plugin
 *  4. Uso – Shortcodes & JS API reference
 */
class IMC_Admin_Settings {

    const PAGE_SLUG    = 'imc-settings';
    const OPTION_KEY   = 'imc_plugin_settings';

    /**
     * Default values for all plugin-level settings.
     */
    private static $defaults = [
        /* General */
        'enable_floating_switcher' => '1',
        'floating_position'        => 'bottom-left',
        'cookie_duration'          => 30,
        'show_currency_badge'      => '1',
        'badge_position'           => 'after',
        'auto_detect_language'     => '1',
        /* GTranslate */
        'gtranslate_enabled'       => '1',
        'gtranslate_reload_delay'  => 800,
        'gtranslate_auto_defaults' => '1',
        'gtranslate_detect_method' => 'both',
    ];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ── Helpers ─────────────────────────────────────────── */

    /**
     * Get all plugin settings merged with defaults.
     */
    public static function get_settings() {
        $saved = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $saved, self::$defaults );
    }

    /**
     * Get a single setting value.
     */
    public static function get( $key ) {
        $s = self::get_settings();
        return $s[ $key ] ?? ( self::$defaults[ $key ] ?? null );
    }

    /* ── Menu ───────────────────────────────────────────── */

    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Multi-Moneda', 'japp-mc' ),
            __( 'Multi-Moneda', 'japp-mc' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /* ── Assets ─────────────────────────────────────────── */

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
            return;
        }
        wp_enqueue_style( 'imc-admin', IMC_PLUGIN_URL . 'assets/css/admin.css', [], IMC_VERSION );
        wp_enqueue_script( 'imc-admin', IMC_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], IMC_VERSION, true );
    }

    /* ── Save handler ───────────────────────────────────── */

    public function handle_save() {
        if ( ! isset( $_POST['imc_save_settings'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['imc_nonce'] ?? '', 'imc_save_settings' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $default_code = IMC()->currency_manager->get_default_currency();

        /* ── Currencies ─────────────────────────────────── */
        $currencies = [];
        if ( ! empty( $_POST['imc_currency'] ) && is_array( $_POST['imc_currency'] ) ) {
            foreach ( $_POST['imc_currency'] as $item ) { // phpcs:ignore
                $code = strtoupper( sanitize_text_field( $item['code'] ?? '' ) );
                if ( empty( $code ) || $code === $default_code ) {
                    continue;
                }
                $currencies[ $code ] = [
                    'code'         => $code,
                    'name'         => sanitize_text_field( $item['name'] ?? '' ),
                    'symbol'       => sanitize_text_field( $item['symbol'] ?? $code ),
                    'position'     => sanitize_text_field( $item['position'] ?? 'left' ),
                    'decimals'     => absint( $item['decimals'] ?? 2 ),
                    'decimal_sep'  => sanitize_text_field( $item['decimal_sep'] ?? '.' ),
                    'thousand_sep' => sanitize_text_field( $item['thousand_sep'] ?? ',' ),
                ];
            }
        }
        IMC()->currency_manager->save_currencies( $currencies );

        /* ── Language map ───────────────────────────────── */
        $lang_map = [];
        if ( ! empty( $_POST['imc_lang'] ) && is_array( $_POST['imc_lang'] ) ) {
            foreach ( $_POST['imc_lang'] as $item ) { // phpcs:ignore
                $lang     = strtolower( sanitize_text_field( $item['lang'] ?? '' ) );
                $currency = strtoupper( sanitize_text_field( $item['currency'] ?? '' ) );
                if ( $lang && $currency ) {
                    $lang_map[ $lang ] = $currency;
                }
            }
        }
        IMC()->currency_manager->save_language_map( $lang_map );

        /* ── Plugin settings ────────────────────────────── */
        $settings = [];
        $post_s   = $_POST['imc_settings'] ?? [];

        // Checkboxes: present → '1', absent → '0'
        foreach ( [ 'enable_floating_switcher', 'show_currency_badge', 'auto_detect_language',
                    'gtranslate_enabled', 'gtranslate_auto_defaults' ] as $cb ) {
            $settings[ $cb ] = ! empty( $post_s[ $cb ] ) ? '1' : '0';
        }

        // Selects / text
        $settings['floating_position']        = sanitize_text_field( $post_s['floating_position'] ?? 'bottom-left' );
        $settings['badge_position']           = sanitize_text_field( $post_s['badge_position'] ?? 'after' );
        $settings['gtranslate_detect_method'] = sanitize_text_field( $post_s['gtranslate_detect_method'] ?? 'both' );

        // Integers
        $settings['cookie_duration']         = max( 1, absint( $post_s['cookie_duration'] ?? 30 ) );
        $settings['gtranslate_reload_delay'] = max( 100, absint( $post_s['gtranslate_reload_delay'] ?? 800 ) );

        update_option( self::OPTION_KEY, $settings );

        add_action( 'admin_notices', function () {
            echo '<div class="updated"><p>' . esc_html__( 'Configuración guardada.', 'japp-mc' ) . '</p></div>';
        } );
    }

    /* ── Render ──────────────────────────────────────────── */

    public function render_page() {
        $currencies   = IMC()->currency_manager->get_currencies();
        $default_code = IMC()->currency_manager->get_default_currency();
        $default_cfg  = $currencies[ $default_code ] ?? [];
        $lang_map     = IMC()->currency_manager->get_language_map();
        $s            = self::get_settings();

        // Additional currencies only (exclude default).
        $additional = $currencies;
        unset( $additional[ $default_code ] );

        $positions = [
            'left'        => __( 'Izquierda ($99)', 'japp-mc' ),
            'right'       => __( 'Derecha (99$)', 'japp-mc' ),
            'left_space'  => __( 'Izq. espacio ($ 99)', 'japp-mc' ),
            'right_space' => __( 'Der. espacio (99 $)', 'japp-mc' ),
        ];

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'currencies';
        ?>
        <div class="wrap imc-settings">
            <h1><?php esc_html_e( 'Multi Currency — Multi Language Detector', 'japp-mc' ); ?></h1>

            <!-- ── Tab navigation ───────────────────────── -->
            <nav class="nav-tab-wrapper imc-tabs">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'currencies' ) ); ?>"
                   class="nav-tab <?php echo 'currencies' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '💱 Monedas', 'japp-mc' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'general' ) ); ?>"
                   class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '⚙️ Ajustes generales', 'japp-mc' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'gtranslate' ) ); ?>"
                   class="nav-tab <?php echo 'gtranslate' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '🌐 GTranslate', 'japp-mc' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'usage' ) ); ?>"
                   class="nav-tab <?php echo 'usage' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '📖 Uso', 'japp-mc' ); ?>
                </a>
            </nav>

            <form method="post">
                <?php wp_nonce_field( 'imc_save_settings', 'imc_nonce' ); ?>

                <?php
                switch ( $active_tab ) :
                    case 'currencies':
                        $this->render_tab_currencies( $default_code, $default_cfg, $additional, $positions, $lang_map );
                        break;
                    case 'general':
                        $this->render_tab_general( $s );
                        break;
                    case 'gtranslate':
                        $this->render_tab_gtranslate( $s );
                        break;
                    case 'usage':
                        $this->render_tab_usage();
                        break;
                endswitch;
                ?>

                <p class="submit">
                    <input type="submit" name="imc_save_settings" class="button-primary"
                           value="<?php esc_attr_e( 'Guardar cambios', 'japp-mc' ); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    /* ── Tab: Currencies ─────────────────────────────────── */

    private function render_tab_currencies( $default_code, $default_cfg, $additional, $positions, $lang_map ) {
        ?>
        <!-- Default currency info -->
        <div class="imc-info-box">
            <h3><?php esc_html_e( 'Moneda principal', 'japp-mc' ); ?></h3>
            <p>
                <strong><?php echo esc_html( $default_code ); ?></strong>
                — <?php echo esc_html( $default_cfg['name'] ?? '' ); ?>
                — Símbolo: <code><?php echo esc_html( $default_cfg['symbol'] ?? '' ); ?></code>
                — <?php echo esc_html( $default_cfg['decimals'] ?? 0 ); ?> decimales
            </p>
            <p class="description">
                <?php
                printf(
                    esc_html__( 'Esta moneda se configura en %s.', 'japp-mc' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings' ) ) . '">WooCommerce → Ajustes → General</a>'
                );
                ?>
            </p>
        </div>

        <!-- Additional currencies -->
        <h2><?php esc_html_e( 'Monedas adicionales', 'japp-mc' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Agregue las monedas en las que desea mostrar precios.', 'japp-mc' ); ?></p>

        <table class="widefat imc-table" id="imc-currencies-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Código', 'japp-mc' ); ?></th>
                    <th><?php esc_html_e( 'Nombre', 'japp-mc' ); ?></th>
                    <th><?php esc_html_e( 'Símbolo', 'japp-mc' ); ?></th>
                    <th><?php esc_html_e( 'Posición', 'japp-mc' ); ?></th>
                    <th><?php esc_html_e( 'Decimales', 'japp-mc' ); ?></th>
                    <th><?php esc_html_e( 'Sep. decimal', 'japp-mc' ); ?></th>
                    <th><?php esc_html_e( 'Sep. miles', 'japp-mc' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="imc-currencies-body">
                <?php
                $idx = 0;
                foreach ( $additional as $code => $c ) :
                    $this->render_currency_row( $idx, $c, $positions );
                    $idx++;
                endforeach;
                ?>
            </tbody>
        </table>

        <p>
            <button type="button" id="imc-add-currency" class="button">
                + <?php esc_html_e( 'Agregar moneda', 'japp-mc' ); ?>
            </button>
        </p>

        <!-- Language → Currency mapping -->
        <h2><?php esc_html_e( 'Mapeo Idioma → Moneda', 'japp-mc' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Asocie cada código de idioma (es, en, fr…) con la moneda que se mostrará.', 'japp-mc' ); ?></p>

        <table class="widefat imc-table" id="imc-lang-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Idioma', 'japp-mc' ); ?></th>
                    <th><?php esc_html_e( 'Moneda', 'japp-mc' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="imc-lang-map-body">
                <?php
                $midx = 0;
                foreach ( $lang_map as $lang => $cur ) :
                    $this->render_mapping_row( $midx, $lang, $cur );
                    $midx++;
                endforeach;
                ?>
            </tbody>
        </table>

        <p>
            <button type="button" id="imc-add-mapping" class="button">
                + <?php esc_html_e( 'Agregar mapeo', 'japp-mc' ); ?>
            </button>
        </p>
        <?php
    }

    /* ── Tab: General settings ───────────────────────────── */

    private function render_tab_general( $s ) {
        ?>
        <!-- Floating switcher -->
        <h2><?php esc_html_e( 'Selector de moneda flotante', 'japp-mc' ); ?></h2>
        <table class="form-table imc-form-table">
            <tr>
                <th scope="row">
                    <label for="imc-enable-float"><?php esc_html_e( 'Activar selector flotante', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <label class="imc-toggle">
                        <input type="checkbox" id="imc-enable-float"
                               name="imc_settings[enable_floating_switcher]" value="1"
                               <?php checked( $s['enable_floating_switcher'], '1' ); ?>>
                        <span class="imc-toggle__slider"></span>
                    </label>
                    <p class="description"><?php esc_html_e( 'Muestra un botón flotante en el frontend para cambiar de moneda.', 'japp-mc' ); ?></p>
                </td>
            </tr>
            <tr class="imc-dep-float">
                <th scope="row">
                    <label for="imc-float-pos"><?php esc_html_e( 'Posición del selector', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <select id="imc-float-pos" name="imc_settings[floating_position]">
                        <?php
                        $float_positions = [
                            'bottom-left'  => __( 'Abajo izquierda', 'japp-mc' ),
                            'bottom-right' => __( 'Abajo derecha', 'japp-mc' ),
                            'top-left'     => __( 'Arriba izquierda', 'japp-mc' ),
                            'top-right'    => __( 'Arriba derecha', 'japp-mc' ),
                        ];
                        foreach ( $float_positions as $val => $label ) :
                        ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['floating_position'], $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>

        <!-- Currency badge -->
        <h2><?php esc_html_e( 'Indicador de moneda en precios', 'japp-mc' ); ?></h2>
        <table class="form-table imc-form-table">
            <tr>
                <th scope="row">
                    <label for="imc-show-badge"><?php esc_html_e( 'Mostrar código de moneda', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <label class="imc-toggle">
                        <input type="checkbox" id="imc-show-badge"
                               name="imc_settings[show_currency_badge]" value="1"
                               <?php checked( $s['show_currency_badge'], '1' ); ?>>
                        <span class="imc-toggle__slider"></span>
                    </label>
                    <p class="description"><?php esc_html_e( 'Agrega una etiqueta con el código de moneda (ej. COP, USD) junto a cada precio.', 'japp-mc' ); ?></p>
                </td>
            </tr>
            <tr class="imc-dep-badge">
                <th scope="row">
                    <label for="imc-badge-pos"><?php esc_html_e( 'Posición de la etiqueta', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <select id="imc-badge-pos" name="imc_settings[badge_position]">
                        <option value="after"  <?php selected( $s['badge_position'], 'after' ); ?>><?php esc_html_e( 'Después del precio', 'japp-mc' ); ?></option>
                        <option value="before" <?php selected( $s['badge_position'], 'before' ); ?>><?php esc_html_e( 'Antes del precio', 'japp-mc' ); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e( 'Ejemplo: $ 50.000 COP (después) o COP $ 50.000 (antes).', 'japp-mc' ); ?></p>
                </td>
            </tr>
        </table>

        <!-- Cookie & detection -->
        <h2><?php esc_html_e( 'Detección y persistencia', 'japp-mc' ); ?></h2>
        <table class="form-table imc-form-table">
            <tr>
                <th scope="row">
                    <label for="imc-cookie-dur"><?php esc_html_e( 'Duración de la cookie (días)', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <input type="number" id="imc-cookie-dur" name="imc_settings[cookie_duration]"
                           value="<?php echo esc_attr( $s['cookie_duration'] ); ?>"
                           class="small-text" min="1" max="365">
                    <p class="description"><?php esc_html_e( 'Tiempo que el navegador recordará la moneda seleccionada por el visitante.', 'japp-mc' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="imc-auto-detect"><?php esc_html_e( 'Auto-detectar idioma', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <label class="imc-toggle">
                        <input type="checkbox" id="imc-auto-detect"
                               name="imc_settings[auto_detect_language]" value="1"
                               <?php checked( $s['auto_detect_language'], '1' ); ?>>
                        <span class="imc-toggle__slider"></span>
                    </label>
                    <p class="description"><?php esc_html_e( 'Usa el mapeo Idioma → Moneda para seleccionar la moneda automáticamente según el idioma detectado (Polylang, WPML, TranslatePress, URL, locale).', 'japp-mc' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /* ── Tab: GTranslate ─────────────────────────────────── */

    private function render_tab_gtranslate( $s ) {
        $gt_active = is_plugin_active( 'gtranslate/gtranslate.php' );
        ?>
        <?php if ( ! $gt_active ) : ?>
        <div class="notice notice-warning inline" style="margin:16px 0;">
            <p><?php esc_html_e( '⚠️ El plugin GTranslate no está activo. Active GTranslate para usar estas opciones.', 'japp-mc' ); ?></p>
        </div>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Integración con GTranslate', 'japp-mc' ); ?></h2>
        <div class="imc-info-box">
            <p><?php esc_html_e( 'Cuando el visitante cambia de idioma mediante GTranslate, el plugin puede cambiar automáticamente la moneda activa según el mapeo configurado en la pestaña "Monedas".', 'japp-mc' ); ?></p>
        </div>

        <table class="form-table imc-form-table">
            <tr>
                <th scope="row">
                    <label for="imc-gt-enabled"><?php esc_html_e( 'Activar integración', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <label class="imc-toggle">
                        <input type="checkbox" id="imc-gt-enabled"
                               name="imc_settings[gtranslate_enabled]" value="1"
                               <?php checked( $s['gtranslate_enabled'], '1' ); ?>>
                        <span class="imc-toggle__slider"></span>
                    </label>
                    <p class="description"><?php esc_html_e( 'Sincroniza automáticamente el cambio de idioma de GTranslate con el cambio de moneda.', 'japp-mc' ); ?></p>
                </td>
            </tr>
            <tr class="imc-dep-gt">
                <th scope="row">
                    <label for="imc-gt-delay"><?php esc_html_e( 'Retardo de recarga (ms)', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <input type="number" id="imc-gt-delay" name="imc_settings[gtranslate_reload_delay]"
                           value="<?php echo esc_attr( $s['gtranslate_reload_delay'] ); ?>"
                           class="small-text" min="100" max="5000" step="100">
                    <p class="description">
                        <?php esc_html_e( 'Milisegundos de espera antes de recargar la página. Da tiempo a GTranslate para establecer su cookie de traducción. Valor recomendado: 800.', 'japp-mc' ); ?>
                    </p>
                </td>
            </tr>
            <tr class="imc-dep-gt">
                <th scope="row">
                    <label for="imc-gt-method"><?php esc_html_e( 'Método de detección', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <select id="imc-gt-method" name="imc_settings[gtranslate_detect_method]">
                        <option value="both"   <?php selected( $s['gtranslate_detect_method'], 'both' ); ?>><?php esc_html_e( 'Ambos (selector + banderas)', 'japp-mc' ); ?></option>
                        <option value="select"  <?php selected( $s['gtranslate_detect_method'], 'select' ); ?>><?php esc_html_e( 'Solo selector desplegable', 'japp-mc' ); ?></option>
                        <option value="flags"   <?php selected( $s['gtranslate_detect_method'], 'flags' ); ?>><?php esc_html_e( 'Solo banderas/enlaces', 'japp-mc' ); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e( 'Qué elementos de GTranslate debe escuchar el plugin para detectar cambios de idioma.', 'japp-mc' ); ?></p>
                </td>
            </tr>
            <tr class="imc-dep-gt">
                <th scope="row">
                    <label for="imc-gt-defaults"><?php esc_html_e( 'Auto-completar idiomas comunes', 'japp-mc' ); ?></label>
                </th>
                <td>
                    <label class="imc-toggle">
                        <input type="checkbox" id="imc-gt-defaults"
                               name="imc_settings[gtranslate_auto_defaults]" value="1"
                               <?php checked( $s['gtranslate_auto_defaults'], '1' ); ?>>
                        <span class="imc-toggle__slider"></span>
                    </label>
                    <p class="description"><?php esc_html_e( 'Agrega automáticamente mapeos predeterminados para idiomas comunes (es→COP, en→USD, fr→EUR, de→EUR, etc.) si no están configurados manualmente. Solo aplica para monedas que estén activas en el plugin.', 'japp-mc' ); ?></p>
                </td>
            </tr>
        </table>

        <!-- GTranslate status -->
        <h2><?php esc_html_e( 'Estado de la integración', 'japp-mc' ); ?></h2>
        <table class="widefat imc-table imc-status-table">
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e( 'Plugin GTranslate', 'japp-mc' ); ?></strong></td>
                    <td>
                        <?php if ( $gt_active ) : ?>
                            <span class="imc-status imc-status--ok">✅ <?php esc_html_e( 'Activo', 'japp-mc' ); ?></span>
                        <?php else : ?>
                            <span class="imc-status imc-status--warn">❌ <?php esc_html_e( 'No activo', 'japp-mc' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Mapeos configurados', 'japp-mc' ); ?></strong></td>
                    <td>
                        <?php
                        $map = IMC()->currency_manager->get_language_map();
                        if ( ! empty( $map ) ) {
                            $pairs = [];
                            foreach ( $map as $l => $c ) {
                                $pairs[] = strtoupper( $l ) . ' → ' . $c;
                            }
                            echo '<span class="imc-status imc-status--ok">' . esc_html( implode( ', ', $pairs ) ) . '</span>';
                        } else {
                            echo '<span class="imc-status imc-status--warn">' . esc_html__( 'Ninguno — configure en la pestaña Monedas', 'japp-mc' ) . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Retardo de recarga', 'japp-mc' ); ?></strong></td>
                    <td><?php echo esc_html( $s['gtranslate_reload_delay'] . ' ms' ); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Método de detección', 'japp-mc' ); ?></strong></td>
                    <td>
                        <?php
                        $methods = [
                            'both'   => __( 'Selector + Banderas', 'japp-mc' ),
                            'select' => __( 'Solo selector', 'japp-mc' ),
                            'flags'  => __( 'Solo banderas', 'japp-mc' ),
                        ];
                        echo esc_html( $methods[ $s['gtranslate_detect_method'] ] ?? $s['gtranslate_detect_method'] );
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /* ── Tab: Usage ──────────────────────────────────────── */

    private function render_tab_usage() {
        ?>
        <h2><?php esc_html_e( 'Shortcodes', 'japp-mc' ); ?></h2>
        <div class="imc-info-box">
            <p><strong><?php esc_html_e( 'Selector de moneda:', 'japp-mc' ); ?></strong></p>
            <code>[imc_currency_switcher]</code>
            <p class="description" style="margin-top:6px;">
                <?php esc_html_e( 'Opciones: style="links" (predeterminado) o style="dropdown".', 'japp-mc' ); ?>
            </p>
        </div>

        <h2><?php esc_html_e( 'API JavaScript', 'japp-mc' ); ?></h2>
        <div class="imc-info-box">
            <p><strong><?php esc_html_e( 'Cambiar moneda programáticamente:', 'japp-mc' ); ?></strong></p>
            <code>document.dispatchEvent(new CustomEvent('imc_switch_currency', { detail: { currency: 'USD' } }));</code>
        </div>

        <h2><?php esc_html_e( 'Evento de carga', 'japp-mc' ); ?></h2>
        <div class="imc-info-box">
            <p><strong><?php esc_html_e( 'Escuchar cuándo se cargó la moneda activa:', 'japp-mc' ); ?></strong></p>
            <code>document.addEventListener('imc_currency_loaded', function(e) { console.log(e.detail); });</code>
            <p class="description" style="margin-top:6px;">
                <?php esc_html_e( 'El objeto detail contiene: currency, symbol, language, currencies.', 'japp-mc' ); ?>
            </p>
        </div>

        <h2><?php esc_html_e( 'Prioridad de detección de moneda', 'japp-mc' ); ?></h2>
        <div class="imc-info-box">
            <ol style="margin:0 0 0 18px;">
                <li><code>?imc_currency=USD</code> — <?php esc_html_e( 'Parámetro GET en la URL', 'japp-mc' ); ?></li>
                <li><code>Cookie imc_currency</code> — <?php esc_html_e( 'Cookie del navegador', 'japp-mc' ); ?></li>
                <li><?php esc_html_e( 'Mapeo Idioma → Moneda', 'japp-mc' ); ?></li>
                <li><?php esc_html_e( 'Moneda predeterminada de WooCommerce', 'japp-mc' ); ?></li>
            </ol>
        </div>

        <h2><?php esc_html_e( 'Precios por producto', 'japp-mc' ); ?></h2>
        <div class="imc-info-box">
            <p><?php esc_html_e( 'En el editor de cada producto (y variación) encontrará campos para ingresar el precio en cada moneda configurada. Si un producto no tiene precio configurado para una moneda, se mostrará el precio de la moneda predeterminada.', 'japp-mc' ); ?></p>
        </div>
        <?php
    }

    /* ── Row renderers ──────────────────────────────────── */

    private function render_currency_row( $idx, $c, $positions ) {
        ?>
        <tr>
            <td><input type="text" name="imc_currency[<?php echo $idx; ?>][code]"
                       value="<?php echo esc_attr( $c['code'] ?? '' ); ?>"
                       class="small-text" maxlength="3" placeholder="USD" required style="text-transform:uppercase"></td>
            <td><input type="text" name="imc_currency[<?php echo $idx; ?>][name]"
                       value="<?php echo esc_attr( $c['name'] ?? '' ); ?>"
                       placeholder="US Dollar"></td>
            <td><input type="text" name="imc_currency[<?php echo $idx; ?>][symbol]"
                       value="<?php echo esc_attr( $c['symbol'] ?? '' ); ?>"
                       class="small-text" maxlength="5" placeholder="$"></td>
            <td>
                <select name="imc_currency[<?php echo $idx; ?>][position]">
                    <?php foreach ( $positions as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>"
                            <?php selected( $c['position'] ?? 'left', $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="number" name="imc_currency[<?php echo $idx; ?>][decimals]"
                       value="<?php echo esc_attr( $c['decimals'] ?? 2 ); ?>"
                       class="small-text" min="0" max="6"></td>
            <td><input type="text" name="imc_currency[<?php echo $idx; ?>][decimal_sep]"
                       value="<?php echo esc_attr( $c['decimal_sep'] ?? '.' ); ?>"
                       class="small-text" maxlength="1"></td>
            <td><input type="text" name="imc_currency[<?php echo $idx; ?>][thousand_sep]"
                       value="<?php echo esc_attr( $c['thousand_sep'] ?? ',' ); ?>"
                       class="small-text" maxlength="1"></td>
            <td><button type="button" class="button imc-remove-row" title="<?php esc_attr_e( 'Eliminar', 'japp-mc' ); ?>">✕</button></td>
        </tr>
        <?php
    }

    private function render_mapping_row( $idx, $lang, $currency ) {
        ?>
        <tr>
            <td><input type="text" name="imc_lang[<?php echo $idx; ?>][lang]"
                       value="<?php echo esc_attr( $lang ); ?>"
                       class="small-text" maxlength="5" placeholder="en"></td>
            <td><input type="text" name="imc_lang[<?php echo $idx; ?>][currency]"
                       value="<?php echo esc_attr( $currency ); ?>"
                       class="small-text" maxlength="3" placeholder="USD" style="text-transform:uppercase"></td>
            <td><button type="button" class="button imc-remove-row" title="<?php esc_attr_e( 'Eliminar', 'japp-mc' ); ?>">✕</button></td>
        </tr>
        <?php
    }
}
