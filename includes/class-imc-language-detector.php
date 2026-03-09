<?php
defined( 'ABSPATH' ) || exit;

/**
 * Detects the visitor's current language from multiple sources.
 *
 * Compatible with: Polylang, WPML, TranslatePress, ?lang= param, and WordPress locale.
 */
class IMC_Language_Detector {

    /**
     * Return a 2-letter language code for the current request.
     *
     * @return string  e.g. "es", "en", "fr"
     */
    public function get_current_language() {

        // 1. Explicit ?lang= parameter
        if ( ! empty( $_GET['lang'] ) ) { // phpcs:ignore
            return $this->normalize( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) );
        }

        // 2. Polylang
        if ( function_exists( 'pll_current_language' ) ) {
            $lang = pll_current_language( 'slug' );
            if ( $lang ) {
                return $this->normalize( $lang );
            }
        }

        // 3. WPML
        $wpml = apply_filters( 'wpml_current_language', null );
        if ( $wpml && 'all' !== $wpml ) {
            return $this->normalize( $wpml );
        }

        // 4. TranslatePress (uses get_locale() internally)

        // 5. WordPress locale fallback
        return $this->normalize( determine_locale() );
    }

    /**
     * Normalize locale strings to a 2-letter code.
     * "es_ES" → "es", "en_US" → "en", "pt-BR" → "pt"
     */
    private function normalize( $lang ) {
        $lang = strtolower( trim( $lang ) );

        if ( false !== strpos( $lang, '_' ) ) {
            $lang = explode( '_', $lang )[0];
        }
        if ( false !== strpos( $lang, '-' ) ) {
            $lang = explode( '-', $lang )[0];
        }

        return $lang;
    }
}
