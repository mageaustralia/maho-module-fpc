/**
 * Mageaustralia_Fpc — Full Page Cache — Dynamic Block Loader
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 *
 * On DOMContentLoaded, finds all [data-fpc-block] placeholder elements
 * plus badge elements ([data-cart-count], [data-compare-count], [data-wishlist-count]),
 * batches them into a single AJAX call to /fpc/dynamic, and injects
 * the returned content.
 */
(function () {
    'use strict';

    // Immediately apply cached badge counts from localStorage (prevents stale-count flash)
    try {
        var cachedQty = localStorage.getItem('fpc_cart_qty');
        if (cachedQty !== null) {
            var el = document.querySelector('[data-cart-count]');
            if (el) {
                var qty = parseInt(cachedQty, 10) || 0;
                el.textContent = String(qty);
                if (qty > 0) el.classList.remove('hidden');
                else el.classList.add('hidden');
            }
        }
    } catch(e) {}

    function loadDynamicBlocks() {
        var placeholders = document.querySelectorAll('[data-fpc-block]');
        var badges = {
            cart_count: document.querySelector('[data-cart-count]'),
            compare_count: document.querySelector('[data-compare-count]'),
            wishlist_count: document.querySelector('[data-wishlist-count]')
        };

        // Collect block names from placeholders
        var names = [];
        var nameSet = {};
        for (var i = 0; i < placeholders.length; i++) {
            var name = placeholders[i].getAttribute('data-fpc-block');
            if (name && !nameSet[name]) {
                names.push(name);
                nameSet[name] = true;
            }
        }

        // Always request badge blocks if their elements exist
        for (var badge in badges) {
            if (badges[badge] && !nameSet[badge]) {
                names.push(badge);
                nameSet[badge] = true;
            }
        }

        if (names.length === 0) {
            return;
        }

        var url = '/fpc/dynamic?blocks=' + encodeURIComponent(names.join(','));

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                return;
            }

            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                return;
            }

            if (!data.success || !data.blocks) {
                return;
            }

            // Inject content into data-fpc-block placeholders
            for (var j = 0; j < placeholders.length; j++) {
                var blockName = placeholders[j].getAttribute('data-fpc-block');
                if (blockName && data.blocks[blockName] !== undefined) {
                    placeholders[j].innerHTML = data.blocks[blockName];
                }
            }

            // Update badge counts and visibility
            // cart_count may be in blocks (dynamic block config) or top-level cart_qty
            var cartCount = (data.blocks && data.blocks.cart_count) || data.cart_qty || '0';
            var compareCount = (data.blocks && data.blocks.compare_count) || data.compare_count || '0';
            var wishlistCount = (data.blocks && data.blocks.wishlist_count) || data.wishlist_count || '0';
            updateBadge('[data-cart-count]', cartCount);
            updateBadge('[data-compare-count]', compareCount);
            updateBadge('[data-wishlist-count]', wishlistCount);

            // Cache counts in localStorage for instant display on next page load
            try {
                localStorage.setItem('fpc_cart_qty', String(cartCount));
            } catch(e) {}

            // Show/hide compare link based on count
            var compareLink = document.querySelector('[data-fpc-compare]');
            if (compareLink) {
                var compareCount = parseInt(data.blocks.compare_count || '0', 10);
                if (compareCount > 0) {
                    compareLink.classList.remove('hidden');
                } else {
                    compareLink.classList.add('hidden');
                }
            }

            // Update form keys on the page
            if (data.form_key) {
                var formKeyInputs = document.querySelectorAll('input[name="form_key"]');
                for (var k = 0; k < formKeyInputs.length; k++) {
                    formKeyInputs[k].value = data.form_key;
                }
            }
        };

        xhr.send();
    }

    /**
     * Update a badge element's text and visibility.
     */
    function updateBadge(selector, value) {
        var el = document.querySelector(selector);
        if (!el) {
            return;
        }

        var count = parseInt(value, 10) || 0;
        el.textContent = String(count);

        if (count > 0) {
            el.classList.remove('hidden');
        } else {
            el.classList.add('hidden');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadDynamicBlocks);
    } else {
        loadDynamicBlocks();
    }
})();
