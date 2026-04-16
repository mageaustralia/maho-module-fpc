/**
 * Mageaustralia_Fpc — Full Page Cache — AJAX Add-to-Cart
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 *
 * Intercepts add-to-cart form submissions and handles them via AJAX.
 * Updates cart count badge, shows toast notifications, and optionally
 * opens the mini-cart sidebar.
 *
 * Intercepts:
 * - productAddToCartForm.submit() — product detail page
 * - .btn-cart clicks on category grid — category page quick-add
 *
 * Uses Maho's native ajaxDelete/ajaxUpdate for remove/update qty.
 */
(function () {
    'use strict';

    // ── Fetch helper ────────────────────────────────────────────────
    // mahoFetch() constructs `new URL(url)` with no base, which throws
    // "TypeError: Invalid URL" on relative paths. Always pass an absolute
    // URL so the promise chain doesn't silently die.
    function _fpcFetch(url, opts) {
        var absolute = url;
        if (typeof url === 'string' && url.charAt(0) === '/') {
            absolute = window.location.origin + url;
        }
        if (typeof window.mahoFetch === 'function') {
            return window.mahoFetch(absolute, opts);
        }
        return window.fetch(absolute, { credentials: 'same-origin' }).then(function (r) {
            return r.ok ? r.json() : Promise.reject(new Error('http ' + r.status));
        });
    }

    // ── Toast notification ──────────────────────────────────────────

    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'fpc-toast fpc-toast--' + (type || 'success');
        toast.textContent = message;

        // Basic inline styles — themes should override via CSS
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;'
            + 'padding:12px 20px;border-radius:6px;color:#fff;font-size:14px;'
            + 'opacity:0;transition:opacity 0.3s ease;max-width:400px;'
            + (type === 'error' ? 'background:#dc3545;' : 'background:#28a745;');

        document.body.appendChild(toast);

        // Fade in
        requestAnimationFrame(function () {
            toast.style.opacity = '1';
        });

        // Fade out and remove
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 4000);
    }

    // ── Cart badge update ───────────────────────────────────────────
    // Delegate to loader.js's shared applyCartQtyToDom() (config-driven,
    // uses cfg.cartQtySelector / cfg.minicartTrigger, rotates count-N
    // classes, injects missing badges). Also persist the new qty to
    // localStorage so the NEXT page view can render the correct badge
    // instantly without waiting for /fpc/dynamic/.
    //
    // Falls back to the legacy [data-cart-count] selector for themes
    // that still use that pattern AND for the edge case where loader.js
    // hasn't loaded (shouldn't happen — same layout block adds both).
    function updateCartCount(qty) {
        if (typeof window._fpcApplyCartQty === 'function') {
            window._fpcApplyCartQty(qty);
        } else {
            var badges = document.querySelectorAll('[data-cart-count]');
            for (var i = 0; i < badges.length; i++) {
                badges[i].textContent = String(qty);
                if (qty > 0) badges[i].removeAttribute('hidden');
            }
        }
        if (typeof window._fpcWriteCartState === 'function') {
            window._fpcWriteCartState({ cart_qty: qty });
        }
    }

    // ── Ensure form key is available ────────────────────────────────

    function ensureFormKey() {
        if (window._fpcFormKeyReady) {
            return Promise.resolve();
        }
        // Wait for the dynamic loader to finish (it sets _fpcFormKeyReady)
        return _fpcFetch('/fpc/dynamic/', { loaderArea: false }).then(function (data) {
            if (data && data.form_key) {
                var fkInputs = document.querySelectorAll('input[name="form_key"]');
                for (var fi = 0; fi < fkInputs.length; fi++) fkInputs[fi].value = data.form_key;
                window._fpcFormKeyReady = true;
            }
        }).catch(function () {});
    }

    // ── Safe text helper (prevent XSS) ──────────────────────────────

    function escText(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // ── AJAX submit ─────────────────────────────────────────────────

    function ajaxAddToCart(form) {
        var url = form.getAttribute('action');
        if (!url) {
            return false;
        }

        // Disable submit button to prevent double-clicks
        var submitBtn = form.querySelector('button[type="submit"], .btn-cart');
        var originalText = '';
        if (submitBtn) {
            originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding...';
        }

        function restoreButton() {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }

        // Ensure form key is fresh, then submit
        ensureFormKey().then(function () {
            var formData = new FormData(form);

            // Add EasyAjax params for server-side block rendering
            formData.append("easy_ajax", "1");
            formData.append("action_content[0]", "cart_toggle_sidebar");
            formData.append("action_content[1]", "cart_toggle");

            return _fpcFetch(url, {
                method: 'POST',
                body: formData,
                loaderArea: false,
            });
        }).then(function (data) {
            restoreButton();

            if (!data) {
                showToast('Failed to add item to cart', 'error');
                return;
            }

            // Handle redirect (e.g. product requires options) — same-origin only
            if (data.redirect) {
                try {
                    var redirectUrl = new URL(data.redirect, window.location.origin);
                    if (redirectUrl.origin === window.location.origin) {
                        window.location.href = data.redirect;
                    }
                } catch(e) {}
                return;
            }

            // Update cart count
            if (typeof data.qty !== "undefined") {
                updateCartCount(data.qty);
            } else if (typeof data.cart_qty !== "undefined") {
                updateCartCount(data.cart_qty);
            }

            // Show messages
            if (data.messages && data.messages.length > 0) {
                for (var i = 0; i < data.messages.length; i++) {
                    var msg = data.messages[i];
                    var colonIdx = msg.indexOf(':');
                    var msgType = colonIdx > -1 ? msg.substring(0, colonIdx) : 'success';
                    var msgText = colonIdx > -1 ? msg.substring(colonIdx + 1) : msg;
                    showToast(msgText, msgType);
                }
            } else if (data.message) {
                showToast(data.message);
            } else if (data.success) {
                showToast('Item added to cart');
            }

            // Update blocks from EasyAjax response (data-attribute driven)
            // Themes add [data-fpc-ajax-block="block_name"] to elements they want updated
            if (data.action_content_data) {
                for (var blockName in data.action_content_data) {
                    var target = document.querySelector('[data-fpc-ajax-block="' + blockName + '"]');
                    if (target) {
                        target.outerHTML = data.action_content_data[blockName];
                    }
                }
            }

            // Plain Maho core add-to-cart response format: `{success, message,
            // qty, content}` where `content` is the rendered minicart HTML
            // (typically wrapped in `<div class="minicart-wrapper">...`).
            // Inject it into whatever the admin configured as the minicart
            // content container — no template changes required. Falls through
            // if the server returned EasyAjax action_content_data instead.
            if (data.content && typeof data.content === 'string') {
                // Single source of truth: admin config exposes the selector
                // via window.FPC_CONFIG. Any theme works by configuring it —
                // no data-attribute hooks in templates, no JS edits.
                var cfg = window.FPC_CONFIG || {};
                var contentSel = cfg.minicartContent || '';
                var contentEl = contentSel ? document.querySelector(contentSel) : null;
                if (contentEl) {
                    // Response content is usually the full `.minicart-wrapper`
                    // element. If our target already IS `.minicart-wrapper`,
                    // replace it outright via outerHTML and re-resolve the
                    // element afterwards. Otherwise inject as innerHTML of
                    // the container.
                    var isWrapper = contentEl.classList && contentEl.classList.contains('minicart-wrapper');
                    if (isWrapper) {
                        var parent = contentEl.parentNode;
                        contentEl.outerHTML = data.content;
                        // Re-resolve the new wrapper for script extraction
                        contentEl = parent.querySelector('.minicart-wrapper');
                    } else {
                        contentEl.innerHTML = data.content;
                    }

                    // Inline <script> tags injected via innerHTML/outerHTML
                    // are parsed but NOT executed. Find them, unwrap any
                    // DOMContentLoaded listener (that event already fired so
                    // any new listener would never run), and eval so the
                    // base theme's `new Minicart({formKey:...}).init()` code
                    // binds to the freshly injected DOM.
                    if (contentEl) {
                        var scripts = contentEl.querySelectorAll('script');
                        for (var s = 0; s < scripts.length; s++) {
                            var code = scripts[s].textContent || '';
                            if (!code.trim()) continue;
                            // Unwrap document.addEventListener('DOMContentLoaded', function() { ... });
                            code = code.replace(
                                /document\.addEventListener\(\s*['"]DOMContentLoaded['"]\s*,\s*function\s*\(\s*\)\s*\{([\s\S]*?)\}\s*\)\s*;?/g,
                                '$1',
                            );
                            try {
                                // Indirect eval — runs in global scope, same
                                // as a real <script> tag at load time.
                                (0, eval)(code);
                            } catch (e) {
                                if (window.console && console.warn) {
                                    console.warn('[fpc] inline script eval failed:', e);
                                }
                            }
                        }
                    }
                }
            }

            // Dispatch event so themes can react (e.g. open cart drawer, refresh minicart)
            document.dispatchEvent(new CustomEvent('fpc:cart:updated', { detail: data }));

            // Update form key if returned
            if (data.form_key) {
                var formKeyInputs = document.querySelectorAll('input[name="form_key"]');
                for (var k = 0; k < formKeyInputs.length; k++) {
                    formKeyInputs[k].value = data.form_key;
                }
            }
        }).catch(function () {
            restoreButton();
            showToast('Failed to add item to cart', 'error');
        });

        return true;
    }

    // ── Event delegation ────────────────────────────────────────────

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }

        var action = form.getAttribute('action') || '';

        // Intercept add-to-cart forms
        if (action.indexOf('/checkout/cart/add/') !== -1) {
            e.preventDefault();
            ajaxAddToCart(form);
        }
    });

    // Intercept category grid quick-add buttons (customFormSubmit pattern)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-cart[data-url]');
        if (!btn) {
            return;
        }

        e.preventDefault();
        var url = btn.getAttribute('data-url');
        if (!url) {
            return;
        }

        // Build a minimal form and submit via AJAX
        var form = document.createElement('form');
        form.setAttribute('action', url);
        form.setAttribute('method', 'post');

        // Add form key
        var formKeyInput = document.querySelector('input[name="form_key"]');
        if (formKeyInput) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'form_key';
            input.value = formKeyInput.value;
            form.appendChild(input);
        }

        // Temporarily append form (needed for FormData)
        form.style.display = 'none';
        document.body.appendChild(form);
        ajaxAddToCart(form);

        // Clean up
        setTimeout(function () {
            if (form.parentNode) {
                form.parentNode.removeChild(form);
            }
        }, 100);
    });
})();
