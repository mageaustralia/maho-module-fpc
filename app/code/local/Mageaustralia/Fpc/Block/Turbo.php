<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Turbo Drive block — conditionally outputs Turbo library + init script.
 *
 * Only renders when Turbo is enabled in config (system/fpc/turbo_enabled).
 * Works with any Maho theme — no template changes required.
 *
 * After each Turbo navigation, re-dispatches DOMContentLoaded and window.load
 * so ALL existing theme JS re-initializes automatically.
 */
class Mageaustralia_Fpc_Block_Turbo extends Mage_Core_Block_Abstract
{
    #[\Override]
    protected function _toHtml(): string
    {
        /** @var Mageaustralia_Fpc_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_fpc');

        if (!$helper->isTurboEnabled()) {
            return '';
        }

        $excludedPaths = $helper->getTurboExcludedPaths();
        $excludedJson = json_encode($excludedPaths, JSON_THROW_ON_ERROR);

        $reloadMeta = $this->shouldForceReload() ? '<meta name="turbo-visit-control" content="reload">' : '';

        return <<<HTML
{$reloadMeta}
<script src="https://cdn.jsdelivr.net/npm/@hotwired/turbo@8/dist/turbo.es2017-umd.min.js" data-turbo-track="reload"></script>
<script data-turbo-eval="false">
(function() {
    'use strict';

    if (window._mahoTurboInit) return;
    window._mahoTurboInit = true;

    var excludedPaths = {$excludedJson};

    // Bypass Turbo for excluded URL paths (checkout, customer, etc.)
    document.addEventListener('turbo:before-visit', function(e) {
        var url = e.detail.url;
        for (var i = 0; i < excludedPaths.length; i++) {
            if (url.indexOf(excludedPaths[i]) !== -1) {
                e.preventDefault();
                window.location.href = url;
                return;
            }
        }

        // Close megamenu dropdowns immediately on navigation
        document.querySelectorAll('#nav .menu-columns.active').forEach(function(cols) {
            cols.style.display = 'none';
            cols.style.maxHeight = '';
            cols.style.overflow = '';
            cols.style.transition = '';
            cols.classList.remove('active');
        });
        document.querySelectorAll('#nav li.level0.open').forEach(function(li) {
            li.classList.remove('open');
        });
    });

    // ── Disable Turbo for form submissions to excluded paths ──
    // Turbo intercepts form POSTs by default, breaking login/checkout/etc.
    document.addEventListener('turbo:before-fetch-request', function(e) {
        var url = e.detail.url.href || e.detail.url.toString();
        for (var i = 0; i < excludedPaths.length; i++) {
            if (url.indexOf(excludedPaths[i]) !== -1) {
                e.preventDefault();
                return;
            }
        }
    });

    // Also mark forms with excluded actions as data-turbo="false" on page load
    function disableTurboOnExcludedForms() {
        document.querySelectorAll('form[action]').forEach(function(form) {
            var action = form.action || '';
            for (var i = 0; i < excludedPaths.length; i++) {
                if (action.indexOf(excludedPaths[i]) !== -1) {
                    form.setAttribute('data-turbo', 'false');
                    break;
                }
            }
        });
    }
    document.addEventListener('turbo:load', disableTurboOnExcludedForms);
    if (document.readyState !== 'loading') disableTurboOnExcludedForms();
    else document.addEventListener('DOMContentLoaded', disableTurboOnExcludedForms);

    // ── Generic re-init: re-dispatch lifecycle events after Turbo body swap ──
    // This re-triggers ALL existing DOMContentLoaded and window.load handlers
    // from app.js, minicart.js, swatches, etc. — fully theme-agnostic.
    document.addEventListener('turbo:load', function() {
        document.dispatchEvent(new Event('DOMContentLoaded'));
        window.dispatchEvent(new Event('load'));
    });

    // ── Offcanvas: capture-phase handler with fresh dialog ref on every click ──
    // Needed because app.js closures capture stale dialog references after Turbo swap.
    // This handler fires BEFORE app.js and uses a fresh getElementById each time.
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.offcanvas-trigger');
        if (!trigger) return;

        var dialog = document.getElementById('offcanvas');
        if (!dialog || !document.body.contains(dialog)) return;

        var sel = trigger.getAttribute('data-offcanvas-target');
        var title = trigger.getAttribute('data-offcanvas-title') || trigger.textContent.trim();
        var pos = trigger.getAttribute('data-offcanvas-position') || 'left';
        var desktop = trigger.getAttribute('data-offcanvas-desktop') === 'true';
        if (!desktop && window.innerWidth >= 768) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        dialog.currentTrigger = trigger;
        var titleEl = dialog.querySelector('.offcanvas-title');
        if (titleEl) titleEl.textContent = title;
        if (pos === 'right') dialog.classList.add('offcanvas-right');
        else dialog.classList.remove('offcanvas-right');

        var content = dialog.querySelector('.offcanvas-content');
        if (content && sel) {
            var target = document.querySelector(sel);
            if (target && target.parentNode !== content) {
                content.innerHTML = '';
                content.appendChild(target);
            }
        }

        dialog.style.transition = 'none';
        dialog.offsetHeight;
        dialog.style.transition = '';
        try { dialog.showModal(); } catch(ex) {}

        // Close handlers (fresh refs)
        var closeBtn = dialog.querySelector('.offcanvas-close');
        if (closeBtn) {
            closeBtn.onclick = function() { try { dialog.close(); } catch(x) {} dialog.currentTrigger = null; };
        }
        dialog.onclick = function(ev) {
            if (ev.target === dialog) { try { dialog.close(); } catch(x) {} dialog.currentTrigger = null; }
        };

        // If opening cart sidebar, refresh minicart content via AJAX
        if (sel && sel.indexOf('minicart') !== -1) {
            fetch('/fpc/dynamic/minicart/', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                if (!html || !html.trim()) return;
                var wrapper = content ? content.querySelector('.minicart-wrapper') : null;
                if (!wrapper) wrapper = content;
                if (wrapper) {
                    wrapper.innerHTML = html;
                    // Re-init Minicart JS so remove/update handlers bind to new elements
                    if (typeof Minicart !== 'undefined') {
                        var fk = wrapper.querySelector('input[name="form_key"]')
                            || document.querySelector('input[name="form_key"]');
                        var formKey = fk ? fk.value : (window.minicart ? window.minicart.formKey : '');
                        try { window.minicart = new Minicart({ formKey: formKey }); window.minicart.init(); } catch(ex) {}
                    }
                }
            }).catch(function() {});
        }
    }, true);

    if (typeof Turbo !== 'undefined') {
        Turbo.config.drive.progressBarDelay = 150;
    }

    // ── CSS Protection ──
    // Root cause of CSS loss was document.write() in Meilisearch template (now fixed).
    // No special CSS handling needed — Turbo's default head merge works fine
    // when all pages serve the same CSS set.
})();
</script>
HTML;
    }

    /**
     * Check if the current page type should force a full reload (Turbo disabled for this type).
     */
    private function shouldForceReload(): bool
    {
        $action = Mage::app()->getRequest()->getRequestedRouteName() . '_'
            . Mage::app()->getRequest()->getRequestedControllerName() . '_'
            . Mage::app()->getRequest()->getRequestedActionName();

        $pageTypeMap = [
            'catalog_category_view'    => 'system/fpc/turbo_category',
            'catalog_category_layered' => 'system/fpc/turbo_category',
            'catalog_product_view'     => 'system/fpc/turbo_product',
            'cms_index_index'          => 'system/fpc/turbo_cms',
            'cms_page_view'            => 'system/fpc/turbo_cms',
        ];

        if (isset($pageTypeMap[$action])) {
            return !Mage::getStoreConfigFlag($pageTypeMap[$action]);
        }

        return false;
    }
}
