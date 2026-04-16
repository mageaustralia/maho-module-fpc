/**
 * Mageaustralia_Fpc — Full Page Cache — Dynamic Block Loader
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 *
 * Loads dynamic content (cart count, account links, messages, etc.) via AJAX
 * on every page view — both initial load and Turbo navigations.
 *
 * Configuration (two methods, both work — admin config takes zero template changes):
 *
 * 1. Admin config (System > Config > FPC > Turbo Drive):
 *    Cart Qty Selector:        e.g. ".skip-cart .count, .cart-toggle .badge"
 *    Minicart Trigger Selector: e.g. ".skip-cart"
 *    Minicart Content Selector: e.g. ".block-cart .block-content"
 *    → Output as window.FPC_CONFIG by Block/Config.php
 *
 * 2. Data attributes (optional override, useful for headless/custom themes):
 *    [data-fpc-block="name"]          — placeholder replaced with block HTML
 *    [data-cart-count]                — badge updated with cart count (hidden when 0)
 *    [data-compare-count]             — badge for compare count
 *    [data-wishlist-count]            — badge for wishlist count
 *    [data-fpc-compare]               — element hidden/shown based on compare count
 *    [data-fpc-cart-qty]              — textContent set to cart qty
 *
 * Custom events dispatched:
 *    fpc:dynamic:loaded  — after blocks loaded (detail = full AJAX response)
 */
(function () {
    'use strict';

    // NOTE: we do NOT cache `window.FPC_CONFIG` at parse time. The fpc.config
    // inline script is emitted by Mage_Page_Block_Html_Head AFTER all addJs
    // scripts — so at the moment loader.js is parsed, FPC_CONFIG doesn't
    // exist yet. Reading it from a local variable captured here would give
    // an empty object. Instead, resolve it on each call via getCfg().
    function getCfg() {
        return window.FPC_CONFIG || {};
    }

    // ── Optimistic cart state (localStorage) ────────────────────────
    // The cached HTML renders with whatever cart qty was current at cache
    // write time (often zero — first visit rendered the page). On each new
    // page view the REAL qty only arrives after the /fpc/dynamic/ round-trip,
    // so users see a ~150ms flash of the stale cached value. Avoid that by
    // keeping last-known state in localStorage and applying it synchronously
    // BEFORE the fetch fires, then letting the fetch confirm/correct.
    var FPC_STATE_KEY = '_fpc_state_v1';
    function readCachedState() {
        try {
            var raw = window.localStorage.getItem(FPC_STATE_KEY);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            // Ignore entries older than 24h — stale data is worse than a flash.
            if (!obj || typeof obj !== 'object' || (Date.now() - (obj.t || 0)) > 86400000) {
                return null;
            }
            return obj;
        } catch (e) {
            return null;
        }
    }
    function writeCachedState(patch) {
        try {
            var cur = readCachedState() || {};
            Object.keys(patch).forEach(function (k) { cur[k] = patch[k]; });
            cur.t = Date.now();
            window.localStorage.setItem(FPC_STATE_KEY, JSON.stringify(cur));
        } catch (e) {}
    }
    // Expose so ajax-cart.js can write after add-to-cart / remove / qty change.
    window._fpcWriteCartState = writeCachedState;
    window._fpcReadCartState  = readCachedState;

    // Apply a cart qty to the configured badge. Used both for the optimistic
    // render (from localStorage, before fetch) and for the real response
    // (from /fpc/dynamic/). Handles three cases:
    //   1. Element matches the selector → update textContent + count-N class
    //   2. No match + qty > 0 → inject a new badge inside minicartTrigger
    //   3. No match + qty == 0 → no-op (nothing to hide, nothing to show)
    function applyCartQtyToDom(cartQty) {
        var cfg = getCfg();
        var qtySelector = cfg.cartQtySelector;
        if (!qtySelector) return;
        var els = document.querySelectorAll(qtySelector);
        if (els.length > 0) {
            for (var q = 0; q < els.length; q++) {
                var el = els[q];
                el.textContent = String(cartQty);
                el.className = el.className.replace(/\bcount-\d+\b/g, '').trim();
                el.classList.add('count-' + cartQty);
                el.style.display = cartQty > 0 ? '' : 'none';
            }
            return;
        }
        if (cartQty > 0 && cfg.minicartTrigger) {
            var triggerEl = document.querySelector(cfg.minicartTrigger);
            if (!triggerEl) return;
            var tokens = qtySelector.trim().split(/\s+/);
            var last = tokens[tokens.length - 1];
            var badge = document.createElement('span');
            if (last.charAt(0) === '.') {
                var classes = last.substring(1).split('.');
                classes.push(classes[0] + '-' + cartQty);
                badge.className = classes.join(' ');
            } else if (last.charAt(0) === '#') {
                badge.id = last.substring(1);
            }
            badge.textContent = String(cartQty);
            triggerEl.appendChild(badge);
        }
    }
    window._fpcApplyCartQty = applyCartQtyToDom;

    // ── Hook cart AJAX endpoints to keep localStorage in sync ──
    // Maho core's minicart.js (and third-party widgets) fire AJAX calls
    // to /checkout/cart/ajax{Add,Update,Delete} bypassing our ajax-cart.js
    // intercept. Their JSON responses include `qty` — mirror it to
    // localStorage + the DOM so subsequent navigations show the correct
    // badge instantly. Hooks both fetch() and XMLHttpRequest so we catch
    // whichever transport the caller used.
    var CART_ENDPOINT_RE = /\/checkout\/cart\/(ajax(?:Add|Update|Delete)|add|delete|updatePost|update)(?:[/?]|$)/i;

    function applyCartResponsePayload(data) {
        if (!data || typeof data !== 'object') return;
        var q = data.qty;
        if (q == null) q = data.cart_qty;
        if (typeof q !== 'number') return;
        applyCartQtyToDom(q);
        writeCachedState({ cart_qty: q });
    }

    // Proxy window.fetch
    if (typeof window.fetch === 'function' && !window._fpcCartFetchHooked) {
        window._fpcCartFetchHooked = true;
        var origFetch = window.fetch.bind(window);
        window.fetch = function(input, opts) {
            var urlStr = typeof input === 'string' ? input : (input && input.url) || '';
            var isCartCall = CART_ENDPOINT_RE.test(urlStr);
            var p = origFetch(input, opts);
            if (!isCartCall) return p;
            return p.then(function(response) {
                try {
                    var clone = response.clone();
                    clone.json().then(applyCartResponsePayload).catch(function(){});
                } catch (e) {}
                return response;
            });
        };
    }

    // Proxy XMLHttpRequest (for jQuery / Prototype / native XHR callers)
    if (typeof XMLHttpRequest !== 'undefined' && !window._fpcCartXhrHooked) {
        window._fpcCartXhrHooked = true;
        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function(method, url) {
            this._fpcUrl = url;
            return origOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function() {
            var xhr = this;
            if (xhr._fpcUrl && CART_ENDPOINT_RE.test(xhr._fpcUrl)) {
                xhr.addEventListener('load', function() {
                    if (xhr.status !== 200) return;
                    try {
                        applyCartResponsePayload(JSON.parse(xhr.responseText));
                    } catch (e) {}
                });
            }
            return origSend.apply(this, arguments);
        };
    }

    // Apply cached state synchronously on script load. Runs BEFORE the
    // /fpc/dynamic/ fetch completes so users see their known cart qty
    // immediately instead of the stale cached-HTML value. If the element
    // isn't in the DOM yet (loader.js is in <head>, body hasn't parsed),
    // re-apply on DOMContentLoaded.
    function applyOptimisticState() {
        var cached = readCachedState();
        if (!cached) return;
        if (typeof cached.cart_qty === 'number') {
            applyCartQtyToDom(cached.cart_qty);
        }
    }
    applyOptimisticState();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyOptimisticState);
    }

    // Defensive helpers so loader.js works even if it loads BEFORE the
    // base theme's app.js (which defines mahoFetch / mahoOnReady).
    // Previously this ordering was load-ordering-dependent and would silently
    // break the minicart/cart-count AJAX update if app.js hadn't been
    // inlined yet. Fall back to window.fetch and DOMContentLoaded when
    // the Maho helpers aren't available at parse time.
    function _fpcFetch(url, opts) {
        // Always construct an absolute URL. Maho's mahoFetch() wraps the URL
        // in `new URL(url)` with no base — passing a relative URL throws
        // TypeError: Invalid URL, which silently kills the caller's promise
        // chain. Building the absolute URL here sidesteps that.
        var absolute = url;
        if (url.charAt(0) === '/') {
            absolute = window.location.origin + url;
        }
        if (typeof window.mahoFetch === 'function') {
            return window.mahoFetch(absolute, opts);
        }
        return window.fetch(absolute, { credentials: 'same-origin' }).then(function (r) {
            return r.ok ? r.json() : Promise.reject(new Error('http ' + r.status));
        });
    }
    function _fpcOnReady(fn) {
        if (typeof window.mahoOnReady === 'function') {
            window.mahoOnReady(fn);
        } else if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    // Measure Turbo navigation time directly.
    // turbo:visit fires when navigation starts, turbo:load when it completes.
    // This is more reliable than querying the resource timing buffer which
    // can contain stale entries across multiple navigations.
    var _navStart = 0;
    var _navDuration = 0;

    document.addEventListener('turbo:visit', function() {
        _navStart = performance.now();
    });

    function loadDynamicBlocks() {
        var cfg = getCfg();
        var placeholders = document.querySelectorAll('[data-fpc-block]');
        var badges = {
            cart_count: document.querySelector('[data-cart-count]'),
            compare_count: document.querySelector('[data-compare-count]'),
            wishlist_count: document.querySelector('[data-wishlist-count]')
        };

        var names = [];
        var nameSet = {};
        for (var i = 0; i < placeholders.length; i++) {
            var name = placeholders[i].getAttribute('data-fpc-block');
            if (name && !nameSet[name]) {
                names.push(name);
                nameSet[name] = true;
            }
        }

        for (var badge in badges) {
            if (badges[badge] && !nameSet[badge]) {
                names.push(badge);
                nameSet[badge] = true;
            }
        }

        // Always fetch — even with no blocks, we need a fresh form_key
        var query = names.length > 0 ? '?blocks=' + encodeURIComponent(names.join(',')) : '';

        // Get page load metric.
        // Turbo navigations: use the measured duration from turbo:visit → turbo:load.
        // Initial page load: use navigation timing API.
        var ttfb = 0, loadTime = 0;

        if (_navDuration > 0) {
            // Turbo navigation — direct measurement
            ttfb = _navDuration;
            loadTime = _navDuration;
            _navDuration = 0; // consume — next navigation starts fresh
        } else {
            // Fresh page load (SEO, direct URL, back/forward without Turbo).
            // At DOMContentLoaded time, responseEnd is set (HTML body finished
            // downloading) but loadEventEnd/domComplete are not yet set.
            // Use responseEnd - startTime as the fetch duration.
            var nav = performance.getEntriesByType("navigation")[0];
            if (nav && nav.responseEnd > 0) {
                loadTime = Math.round(nav.responseEnd - nav.startTime);
                // True server TTFB for initial loads (actually meaningful here
                // since there's no Turbo layer — matches what browsers report)
                ttfb = nav.responseStart > 0
                    ? Math.round(nav.responseStart - nav.startTime)
                    : loadTime;
            }
        }
        query += (query ? "&" : "?") + "p=" + encodeURIComponent(window.location.pathname) + "&ttfb=" + ttfb + "&lt=" + loadTime;

        _fpcFetch('/fpc/dynamic/' + query, { loaderArea: false })
        .then(function(data) {
            if (!data || !data.success || !data.blocks) return;

            // Replace [data-fpc-block] placeholders with block HTML
            var currentPlaceholders = document.querySelectorAll('[data-fpc-block]');
            for (var j = 0; j < currentPlaceholders.length; j++) {
                var blockName = currentPlaceholders[j].getAttribute('data-fpc-block');
                if (blockName && data.blocks[blockName] !== undefined) {
                    currentPlaceholders[j].innerHTML = data.blocks[blockName];
                }
            }

            // Update count badges (data-attribute driven)
            updateBadge('[data-cart-count]', data.blocks.cart_count || data.cart_qty || '0');
            updateBadge('[data-compare-count]', data.blocks.compare_count || data.compare_count || '0');
            updateBadge('[data-wishlist-count]', data.blocks.wishlist_count || data.wishlist_count || '0');

            // Show/hide compare link
            var compareLink = document.querySelector('[data-fpc-compare]');
            if (compareLink) {
                var compareCount = parseInt(data.blocks.compare_count || data.compare_count || '0', 10);
                compareLink.style.cssText = compareCount > 0 ? '' : 'display:none !important';
            }

            // Update cart qty badge via shared function (same code path used
            // by the optimistic localStorage render). Also persist the new
            // value so the NEXT page view can render instantly without
            // waiting for /fpc/dynamic/.
            var cartQty = data.cart_qty || 0;
            applyCartQtyToDom(cartQty);
            writeCachedState({
                cart_qty:       cartQty,
                compare_count:  data.compare_count  || 0,
                wishlist_count: data.wishlist_count || 0,
            });

            // Bind minicart AJAX refresh — from admin config selectors OR data attributes
            var triggerSel = cfg.minicartTrigger;
            var contentSel = cfg.minicartContent;

            // Data-attribute override
            var triggerAttr = document.querySelector('[data-fpc-minicart-trigger]');
            var contentAttr = document.querySelector('[data-fpc-minicart-content]');
            if (triggerAttr) triggerSel = triggerAttr.getAttribute('data-fpc-minicart-trigger');
            if (contentAttr) contentSel = contentAttr.getAttribute('data-fpc-minicart-content');

            if (triggerSel && contentSel) {
                var cartWrapper = document.querySelector(triggerSel);
                if (cartWrapper && !cartWrapper._fpcMinicartBound) {
                    cartWrapper._fpcMinicartBound = true;
                    cartWrapper.addEventListener('click', function() {
                        var blockContent = document.querySelector(contentSel);
                        if (!blockContent) return;
                        _fpcFetch('/fpc/dynamic/minicart/', { loaderArea: false })
                        .then(function(html) {
                            if (html && html.trim()) {
                                blockContent.innerHTML = html;
                            }
                        })
                        .catch(function() {});
                    });
                }
            }

            // Update form keys everywhere
            if (data.form_key) {
                var formKeyInputs = document.querySelectorAll('input[name="form_key"]');
                for (var k = 0; k < formKeyInputs.length; k++) {
                    formKeyInputs[k].value = data.form_key;
                }
                window._fpcFormKeyReady = true;

                document.querySelectorAll('form[action*="/form_key/"]').forEach(function(form) {
                    form.action = form.action.replace(/\/form_key\/[^\/]+\//, '/form_key/' + data.form_key + '/');
                });
                document.querySelectorAll('a[href*="/form_key/"]').forEach(function(a) {
                    a.href = a.href.replace(/\/form_key\/[^\/]+\//, '/form_key/' + data.form_key + '/');
                });
            }

            // Dispatch custom event so themes can hook in for additional updates
            document.dispatchEvent(new CustomEvent('fpc:dynamic:loaded', { detail: data }));
        })
        .catch(function() {});
    }

    function updateBadge(selector, value) {
        var el = document.querySelector(selector);
        if (!el) return;

        var count = parseInt(value, 10) || 0;
        if (count > 0) {
            el.textContent = String(count);
            el.style.display = '';
        } else {
            el.innerHTML = '&nbsp;';
            el.style.display = 'none';
        }
    }

    // Expose for manual re-trigger
    window.fpcLoadDynamicBlocks = loadDynamicBlocks;

    // Guard against double-fire on initial load (turbo:load + DOMContentLoaded both fire)
    var initialLoaded = false;

    // Turbo navigation — fires on every page (initial + navigations)
    document.addEventListener('turbo:load', function() {
        initialLoaded = true;
        if (_navStart > 0) {
            _navDuration = Math.round(performance.now() - _navStart);
            _navStart = 0;
        }
        loadDynamicBlocks();
    });

    // Fallback for non-Turbo (or if Turbo hasn't loaded yet on initial page)
    _fpcOnReady(function() {
        if (!initialLoaded) loadDynamicBlocks();
    });
})();
