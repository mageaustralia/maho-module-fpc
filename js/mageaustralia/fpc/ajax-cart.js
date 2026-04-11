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

    function updateCartCount(qty) {
        var badges = document.querySelectorAll('[data-cart-count]');
        for (var i = 0; i < badges.length; i++) {
            badges[i].textContent = String(qty);
            // Show/hide based on qty
            if (qty > 0) {
                badges[i].removeAttribute('hidden');
            }
        }
    }

    // ── AJAX submit ─────────────────────────────────────────────────

    function ajaxAddToCart(form) {
        var url = form.getAttribute('action');
        if (!url) {
            return false;
        }

        // Ensure we have a fresh form_key (FPC cached pages have stale keys)
        if (!window._fpcFormKeyReady) {
            // Dynamic loader hasn't finished — fetch form_key synchronously-ish
            var fkReq = new XMLHttpRequest();
            fkReq.open('GET', '/fpc/dynamic/', false); // synchronous
            try {
                fkReq.send();
                if (fkReq.status === 200) {
                    var fkData = JSON.parse(fkReq.responseText);
                    if (fkData.form_key) {
                        var fkInputs = document.querySelectorAll('input[name="form_key"]');
                        for (var fi = 0; fi < fkInputs.length; fi++) fkInputs[fi].value = fkData.form_key;
                        window._fpcFormKeyReady = true;
                    }
                }
            } catch(e) {}
        }

        // Append isAjax param
        if (url.indexOf('isAjax') === -1) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'isAjax=true';
        }

        var formData = new FormData(form);

        // Add EasyAjax params for server-side block rendering
        formData.append("easy_ajax", "1");
        formData.append("action_content[0]", "cart_toggle_sidebar");
        formData.append("action_content[1]", "cart_toggle");

        // Disable submit button to prevent double-clicks
        var submitBtn = form.querySelector('button[type="submit"], .btn-cart');
        var originalText = '';
        if (submitBtn) {
            originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding...';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function () {
            // Re-enable button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }

            if (xhr.status !== 200) {
                showToast('Failed to add item to cart', 'error');
                return;
            }

            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
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
            } else if (data.message) {
                showToast(data.message);
            } else if (data.success) {
                showToast('Item added to cart');
            }

            // Update blocks from EasyAjax response
            if (data.action_content_data) {
                if (data.action_content_data.cart_toggle) {
                    var toggle = document.querySelector("a.cart-toggle");
                    if (toggle) toggle.outerHTML = data.action_content_data.cart_toggle;
                }
                if (data.action_content_data.cart_toggle_sidebar) {
                    var sidebarBlock = document.querySelector(".header-cart-wrapper .block-cart");
                    if (sidebarBlock) sidebarBlock.outerHTML = data.action_content_data.cart_toggle_sidebar;
                // Briefly show the cart dropdown
                var wrapper = document.querySelector(".header-cart-wrapper");
                if (wrapper) {
                    wrapper.style.display = "block";
                    setTimeout(function() { wrapper.style.display = ""; }, 5000);
                }
                }
            } else if (data.content) {
                var sidebar = document.getElementById('mini-cart-sidebar');
                if (sidebar) {
                    sidebar.innerHTML = data.content;
                }
            }

            // Update form key if returned
            if (data.form_key) {
                var formKeyInputs = document.querySelectorAll('input[name="form_key"]');
                for (var k = 0; k < formKeyInputs.length; k++) {
                    formKeyInputs[k].value = data.form_key;
                }
            }
        };

        xhr.onerror = function () {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
            showToast('Network error — please try again', 'error');
        };

        xhr.send(formData);
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
