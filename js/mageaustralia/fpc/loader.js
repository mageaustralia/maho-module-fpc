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

    var cfg = window.FPC_CONFIG || {};

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
        query += (query ? "&" : "?") + "p=" + encodeURIComponent(pathname) + "&ttfb=" + ttfb + "&lt=" + loadTime;

        mahoFetch('/fpc/dynamic/' + query, { loaderArea: false })
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

            // Update cart qty elements — from admin config selector OR data attribute
            var cartQty = data.cart_qty || 0;
            var qtySelector = cfg.cartQtySelector || '[data-fpc-cart-qty]';
            var cartQtyEls = document.querySelectorAll(qtySelector);
            for (var q = 0; q < cartQtyEls.length; q++) {
                cartQtyEls[q].textContent = String(cartQty);
            }

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
                        mahoFetch('/fpc/dynamic/minicart/', { loaderArea: false })
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
    mahoOnReady(function() {
        if (!initialLoaded) loadDynamicBlocks();
    });
})();
