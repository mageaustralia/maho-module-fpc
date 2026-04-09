/**
 * Mageaustralia_Fpc — Full Page Cache — Dynamic Block Loader
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 *
 * Loads dynamic content (cart count, account links, messages, etc.) via AJAX
 * on every page view — both initial load and Turbo navigations.
 */
(function () {
    'use strict';

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

        fetch('/fpc/dynamic/' + query, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.blocks) return;

            var currentPlaceholders = document.querySelectorAll('[data-fpc-block]');
            for (var j = 0; j < currentPlaceholders.length; j++) {
                var blockName = currentPlaceholders[j].getAttribute('data-fpc-block');
                if (blockName && data.blocks[blockName] !== undefined) {
                    currentPlaceholders[j].innerHTML = data.blocks[blockName];
                }
            }

            // Update DaisyUI theme badges
            updateBadge('[data-cart-count]', data.blocks.cart_count || '0');
            updateBadge('[data-compare-count]', data.blocks.compare_count || '0');
            updateBadge('[data-wishlist-count]', data.blocks.wishlist_count || '0');

            var compareLink = document.querySelector('[data-fpc-compare]');
            if (compareLink) {
                var compareCount = parseInt(data.blocks.compare_count || '0', 10);
                compareLink.style.cssText = compareCount > 0 ? '' : 'display:none !important';
            }

            // Update base Maho theme cart count (.skip-cart .count)
            var baseCount = document.querySelector('.skip-cart .count');
            if (baseCount) {
                var qty = data.cart_qty || 0;
                baseCount.textContent = String(qty);
                baseCount.className = 'count' + (qty === 0 ? ' count-0' : '');
            }

            // Update form keys everywhere
            if (data.form_key) {
                var formKeyInputs = document.querySelectorAll('input[name="form_key"]');
                for (var k = 0; k < formKeyInputs.length; k++) {
                    formKeyInputs[k].value = data.form_key;
                }
                // Also update inline form_key references in onclick handlers
                if (typeof Mage !== 'undefined' && Mage.Cookies) {
                    // Maho stores form_key in cookie too
                }
            }

            // Re-init minicart if it exists (base theme)
            if (typeof window.minicart !== 'undefined' && data.form_key) {
                window.minicart.formKey = data.form_key;
            }
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
        loadDynamicBlocks();
    });

    // Fallback for non-Turbo (or if Turbo hasn't loaded yet on initial page)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (!initialLoaded) loadDynamicBlocks();
        });
    } else if (!initialLoaded) {
        loadDynamicBlocks();
    }
})();
