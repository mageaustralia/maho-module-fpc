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

    // ── Reset transient UI state on Turbo navigation ──
    // All selectors driven by admin config (window.FPC_CONFIG) — themes need zero changes.
    // Configurable in System > Config > FPC > Turbo Drive (Reset: ... fields).
    function fpcResetTransientState() {
        var c = window.FPC_CONFIG || {};

        // Remove dynamically-injected overlay/modal elements
        if (c.resetRemoveSelectors) {
            try {
                document.querySelectorAll(c.resetRemoveSelectors).forEach(function(el) { el.remove(); });
            } catch(e) {}
        }

        // Remove "open" class from permanent page elements
        if (c.resetCloseSelectors) {
            try {
                document.querySelectorAll(c.resetCloseSelectors).forEach(function(el) {
                    el.classList.remove('open');
                    el.style.display = '';
                });
            } catch(e) {}
        }

        // Remove body classes that indicate popup-open state
        if (c.resetBodyClasses) {
            c.resetBodyClasses.split(',').forEach(function(cls) {
                document.body.classList.remove(cls.trim());
            });
            document.body.style.overflow = '';
        }

        // Clear input values (e.g. search inputs)
        if (c.resetClearInputs) {
            try {
                document.querySelectorAll(c.resetClearInputs).forEach(function(input) { input.value = ''; });
            } catch(e) {}
        }

        // Clone-and-replace elements to strip all JS event listeners.
        // Essential for third-party libraries (Meilisearch, Algolia) that
        // re-attach listeners on every init() — cloning gives them a fresh
        // element with no previous listeners, preventing request buildup.
        if (c.resetCloneSelectors) {
            try {
                document.querySelectorAll(c.resetCloneSelectors).forEach(function(el) {
                    var clone = el.cloneNode(true);
                    clone.value = '';
                    el.parentNode.replaceChild(clone, el);
                });
            } catch(e) {}
        }

        // Null out global autocomplete references (prevents instance buildup)
        if (window.algoliaAutocomplete) { try { window.algoliaAutocomplete.destroy(); } catch(e) {} window.algoliaAutocomplete = null; }
        if (window.meilisearchAutocomplete) { try { window.meilisearchAutocomplete.destroy(); } catch(e) {} window.meilisearchAutocomplete = null; }
    }

    // Close popups before navigation by dispatching ESC key
    document.addEventListener('turbo:before-visit', function() {
        if ((window.FPC_CONFIG || {}).resetDispatchEscape) {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, which: 27, bubbles: true }));
        }
    });

    // Clean transient state before Turbo caches the page
    document.addEventListener('turbo:before-cache', fpcResetTransientState);

    // ── Generic re-init: re-dispatch lifecycle events after Turbo body swap ──
    // This re-triggers existing DOMContentLoaded and window.load handlers
    // from app.js, minicart.js, swatches, etc. — fully theme-agnostic.
    // Sets a flag so scripts can detect Turbo re-init vs genuine page load.
    document.addEventListener('turbo:load', function() {
        // Reset BEFORE re-dispatching DOMContentLoaded.
        // This clones inputs and removes stale dropdowns so that when
        // third-party libraries (Meilisearch, Algolia) re-initialize via
        // their own DOMContentLoaded listener, they attach to a fresh
        // element with no prior listeners. Prevents request buildup.
        fpcResetTransientState();

        window._turboReinit = true;
        document.dispatchEvent(new Event('DOMContentLoaded'));
        window.dispatchEvent(new Event('load'));
        window._turboReinit = false;

        // Close any popups that the re-init may have opened
        if ((window.FPC_CONFIG || {}).resetDispatchEscape) {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, which: 27, bubbles: true }));
        }
    });

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
