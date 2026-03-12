/**
 * Multi Currency – Frontend JS
 *
 * 1. Dispatches `imc_currency_loaded` on DOMContentLoaded with active currency info.
 * 2. Listens for `imc_switch_currency` events from external scripts to trigger a
 *    currency change (sets cookie + reloads).
 * 3. Floating currency switcher toggle behaviour.
 * 4. GTranslate integration: when the user changes language via GTranslate
 *    (select dropdown or floating flag links) the plugin automatically switches
 *    the active currency according to the language→currency map.
 *    IMPORTANT: we must NOT reload immediately — GTranslate needs time to set
 *    its own `googtrans` cookie.  So we set `imc_currency` cookie client-side,
 *    wait for GTranslate to complete, and then reload the page cleanly.
 */
(function () {
    'use strict';

    /* ── Helpers ─────────────────────────────────────────── */

    /**
     * Given a GTranslate language code (e.g. "en", "zh-CN", "fr-CA", "pt-PT"),
     * find the matching currency in the langMap.
     *
     * Lookup order:
     *  1. Exact code ("zh-CN")
     *  2. Base code  ("zh")
     *  3. null (no match)
     */
    function currencyForLang(langCode) {
        if (typeof imcFrontend === 'undefined' || !imcFrontend.langMap) {
            return null;
        }
        var map = imcFrontend.langMap;

        // Exact match first
        if (map[langCode]) {
            return map[langCode];
        }

        // Base language (before the first hyphen)
        var base = langCode.split('-')[0].toLowerCase();
        if (map[base]) {
            return map[base];
        }

        return null;
    }

    /**
     * Set a cookie on the current domain.
     */
    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var d = new Date();
            d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
            expires = '; expires=' + d.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) +
            expires + '; path=/' +
            (location.protocol === 'https:' ? '; Secure' : '');
    }

    /**
     * Get the configured cookie duration (from admin settings, default 30).
     */
    function cookieDays() {
        return (typeof imcFrontend !== 'undefined' && imcFrontend.cookieDays)
            ? imcFrontend.cookieDays : 30;
    }

    /**
     * Get the configured GTranslate reload delay (from admin settings, default 800).
     */
    function gtReloadDelay() {
        return (typeof imcFrontend !== 'undefined' && imcFrontend.gtReloadDelay)
            ? imcFrontend.gtReloadDelay : 800;
    }

    /**
     * Handle a GTranslate-initiated language change.
     *
     * Instead of dispatching `imc_switch_currency` (which does an immediate
     * reload via ?imc_currency=), we:
     *  1. Set the `imc_currency` cookie ourselves (client-side).
     *  2. Let GTranslate finish its own work — it needs to set the `googtrans`
     *     cookie so the translation persists across the reload.
     *  3. After a short delay, reload the page without any query param.
     *     On reload both cookies are present: `imc_currency` → correct prices,
     *     `googtrans` → Google Translate re-applies the translation.
     */
    function switchCurrencyForGTranslate(langCode) {
        var currency = currencyForLang(langCode);
        if (!currency || currency === imcFrontend.activeCurrency) {
            return; // no change needed
        }

        // Set imc_currency cookie (using admin-configured duration)
        setCookie('imc_currency', currency, cookieDays());

        // Give GTranslate time to set googtrans cookie + start translation,
        // then reload so the server renders the correct currency prices.
        setTimeout(function () {
            // Reload the current page without adding ?imc_currency= (cookie is enough)
            window.location.reload();
        }, gtReloadDelay());
    }

    /* ── Announce current currency on page load ─────────── */
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof imcFrontend === 'undefined') {
            return;
        }

        document.dispatchEvent(new CustomEvent('imc_currency_loaded', {
            detail: {
                currency:   imcFrontend.activeCurrency,
                symbol:     imcFrontend.activeSymbol,
                language:   imcFrontend.activeLanguage,
                currencies: imcFrontend.currencies
            }
        }));

        /* ── Floating switcher ──────────────────────────── */
        var floatEl = document.getElementById('imc-float');
        var toggle  = document.getElementById('imc-float-toggle');

        if (floatEl && toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = floatEl.classList.toggle('imc-float--open');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            // Close when clicking outside
            document.addEventListener('click', function (e) {
                if (!floatEl.contains(e.target)) {
                    floatEl.classList.remove('imc-float--open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });

            // Close on Escape key
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    floatEl.classList.remove('imc-float--open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        /* ── GTranslate integration ─────────────────────── */
        initGTranslateWatcher();
    });

    /* ── Listen for external switch requests ─────────────── */
    document.addEventListener('imc_switch_currency', function (e) {
        if (!e.detail || !e.detail.currency) {
            return;
        }

        var currency = e.detail.currency.toUpperCase();
        setCookie('imc_currency', currency, cookieDays());
        window.location.reload();
    });

    /* ── Click handler for currency switcher links ──────── */
    /**
     * Intercept clicks on [data-currency] links (floating widget + shortcode).
     * Instead of navigating to ?imc_currency=X (which requires a server-side
     * redirect back to the clean URL and can be served from browser cache),
     * we set the cookie client-side and reload to ensure a fresh render.
     */
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-currency]');
        if (!link || typeof imcFrontend === 'undefined') return;

        var currency = link.getAttribute('data-currency');
        if (!currency) return;

        e.preventDefault();

        currency = currency.toUpperCase();
        if (currency === imcFrontend.activeCurrency) return;

        setCookie('imc_currency', currency, cookieDays());
        window.location.reload();
    });

    /* ── GTranslate watcher ──────────────────────────────── */

    /**
     * Hooks into GTranslate's two UI mechanisms:
     *
     *  A) The Google Translate `<select class="goog-te-combo">` dropdown.
     *     When the user picks a language, the `change` event fires.
     *
     *  B) The GTranslate floating flag links `<a data-gt-lang="en">`.
     *     These fire a `click` event, and then GTranslate's own JS handles
     *     the actual translation.  We capture the `data-gt-lang` attribute.
     *
     * Because GTranslate may inject its DOM *after* DOMContentLoaded (e.g.
     * the Google Translate widget is loaded asynchronously), we use
     * event delegation on `document.body` and also poll for the select.
     *
     * NOTE: we do NOT call switchIfNeeded / dispatch imc_switch_currency here.
     * Instead we call switchCurrencyForGTranslate(), which sets the cookie
     * client-side and delays the reload so GTranslate can persist its own state.
     */
    function initGTranslateWatcher() {
        // Check if GTranslate integration is enabled in admin settings.
        if (typeof imcFrontend === 'undefined' || !imcFrontend.gtEnabled) {
            return;
        }

        var method = imcFrontend.gtDetectMethod || 'both';

        // ── A) Floating flag links (event delegation on body) ──
        if (method === 'both' || method === 'flags') {
            document.body.addEventListener('click', function (e) {
                var link = e.target.closest('[data-gt-lang]');
                if (!link) return;

                var lang = link.getAttribute('data-gt-lang');
                if (lang) {
                    switchCurrencyForGTranslate(lang);
                }
            });
        }

        // ── B) Google Translate <select> (may not exist yet) ──
        if (method === 'both' || method === 'select') {
            watchGoogTeCombo();
        }
    }

    /**
     * Poll for the `.goog-te-combo` <select> element every 500 ms for up to
     * 10 seconds. Once found, attach a `change` listener. This handles the
     * case where Google Translate is loaded asynchronously after page load.
     */
    function watchGoogTeCombo() {
        var maxAttempts = 20;
        var attempts    = 0;

        function tryAttach() {
            var sel = document.querySelector('select.goog-te-combo');
            if (sel) {
                sel.addEventListener('change', function () {
                    var lang = sel.value;
                    if (lang) {
                        switchCurrencyForGTranslate(lang);
                    }
                });
                return; // done
            }

            attempts++;
            if (attempts < maxAttempts) {
                setTimeout(tryAttach, 500);
            }
        }

        tryAttach();
    }

})();
