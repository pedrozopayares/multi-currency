# Multi Currency with Multi Language Detector for WooCommerce

Plugin de WordPress/WooCommerce que cambia automáticamente la moneda según el idioma del visitante. Permite definir precios por moneda en cada producto y variación.

## Características

- **Multi-moneda**: Configura COP, USD, EUR y cualquier otra moneda con su formato (símbolo, posición, decimales, separadores).
- **Detección de idioma**: Compatible con Polylang, WPML, TranslatePress, GTranslate, parámetro `?lang=` y locale de WordPress.
- **Mapeo Idioma → Moneda**: Asocia automáticamente cada idioma con su moneda correspondiente.
- **Precios por producto**: Campos individuales de precio regular y rebaja por moneda en cada producto y variación.
- **Selector flotante**: Widget de cambio de moneda con banderas en 4 posiciones configurables.
- **Shortcode y API JS**: `[imc_currency_switcher]` y eventos JavaScript para integración personalizada.
- **Integración GTranslate**: Sincroniza cambio de idioma con cambio de moneda automáticamente.
- **Panel de administración**: Interfaz con pestañas (Monedas, Ajustes, GTranslate, Uso).
- **HPOS compatible**: Soporte completo para High-Performance Order Storage de WooCommerce.

## Requisitos

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+

## Instalación

1. Clona o descarga este repositorio en `wp-content/plugins/`:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/pedrozopayares/multi-currency.git impactos-multi-currency
   ```
2. Activa el plugin desde **Plugins** en el admin de WordPress.
3. Configura en **WooCommerce → Multi-Moneda**.

## Prioridad de detección de moneda

1. `?imc_currency=USD` — Parámetro GET en la URL
2. Cookie `imc_currency` — Cookie del navegador
3. Mapeo Idioma → Moneda configurado
4. Moneda predeterminada de WooCommerce

## Desarrollo

### Tests

```bash
# Instalar dependencias
composer install

# Ejecutar tests
vendor/bin/phpunit

# Con nombres descriptivos
vendor/bin/phpunit --testdox
```

### Estructura

```
impactos-multi-currency/
├── impactos-multi-currency.php   # Entry point
├── includes/
│   ├── class-imc-core.php            # Singleton orchestrator
│   ├── class-imc-currency-manager.php # Currency CRUD & resolution
│   ├── class-imc-language-detector.php # Language detection
│   ├── class-imc-price-handler.php    # WooCommerce price filters
│   ├── class-imc-admin-settings.php   # Admin settings page
│   ├── class-imc-product-fields.php   # Per-product price fields
│   └── class-imc-frontend.php        # Frontend: switcher, shortcode, JS
├── assets/
│   ├── css/admin.css
│   ├── css/frontend.css
│   ├── js/admin.js
│   └── js/frontend.js
├── tests/
│   ├── bootstrap.php
│   ├── AdminSettingsTest.php
│   ├── CoreTest.php
│   ├── CurrencyManagerTest.php
│   ├── FrontendTest.php
│   ├── LanguageDetectorTest.php
│   └── PriceHandlerTest.php
├── composer.json
├── phpunit.xml
└── README.md
```

## Licencia

GPL-2.0+

## Autor

**Javier Andrés Pedrozo Payares**
- GitHub: [@pedrozopayares](https://github.com/pedrozopayares)
