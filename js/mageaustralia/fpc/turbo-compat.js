/**
 * Mageaustralia_Fpc — Turbo Drive Compatibility Layer
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 *
 * Replaces the base theme's offcanvas behavior with a Turbo-safe version.
 * The base theme's app.js captures `const offcanvas = document.getElementById('offcanvas')`
 * in a DOMContentLoaded closure. On Turbo navigation, the body is swapped but
 * the closure keeps a stale reference to the old (now-detached) dialog, causing
 * `showModal()` to throw. Re-dispatching DOMContentLoaded re-runs initOffcanvas
 * which adds a SECOND click handler that wipes sidebar content.
 *
 * This file uses the CAPTURE phase to intercept `.offcanvas-trigger` clicks
 * BEFORE app.js's bubble-phase handler, calls stopImmediatePropagation to
 * suppress the original, and handles the offcanvas with a FRESH dialog
 * reference queried on every click. Works across any number of Turbo
 * navigations with zero stale closures.
 *
 * Loaded conditionally by layout XML only when Turbo is enabled.
 */
(function () {
    'use strict';

    // Only activate when Turbo is actually loaded. Without Turbo, the base
    // theme's initOffcanvas works fine and this script's stopImmediatePropagation
    // would kill other click handlers (e.g. loader.js's minicart AJAX refresh).
    if (!window._mahoTurboInit && typeof Turbo === 'undefined') return;

    // Track moved elements for restoration on close
    var movedElements = new Map();

    // Detect mobile breakpoint — base theme uses 770px
    var mobileQuery = window.matchMedia('(max-width: 770px)');

    // ── Capture-phase click handler — overrides app.js's offcanvas ──
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.offcanvas-trigger');
        if (!trigger) return;

        // Fresh reference — never stale
        var offcanvas = document.getElementById('offcanvas');
        if (!offcanvas) return;

        var targetSelector = trigger.getAttribute('data-offcanvas-target');
        var allowDesktop = trigger.getAttribute('data-offcanvas-desktop') === 'true';

        if (!allowDesktop && !mobileQuery.matches) return;
        if (!targetSelector) return;

        // Kill app.js's bubble-phase handler so it can't fire with a stale ref
        e.preventDefault();
        e.stopImmediatePropagation();

        // Store trigger reference for other handlers (e.g. focus return)
        offcanvas.currentTrigger = trigger;

        // Title
        var titleEl = offcanvas.querySelector('.offcanvas-title');
        if (titleEl) {
            titleEl.textContent = trigger.getAttribute('data-offcanvas-title')
                || trigger.textContent.trim();
        }

        // Position (left / right)
        var position = trigger.getAttribute('data-offcanvas-position') || 'left';
        offcanvas.classList.toggle('offcanvas-right', position === 'right');

        // Move target content into the offcanvas
        var offcanvasContent = offcanvas.querySelector('.offcanvas-content');
        if (offcanvasContent) {
            offcanvasContent.innerHTML = '';
            try {
                var targets = document.querySelectorAll(targetSelector);
                targets.forEach(function (target) {
                    if (target && target.parentNode !== offcanvasContent) {
                        movedElements.set(target, target.parentNode);
                        offcanvasContent.appendChild(target);
                    }
                });
            } catch (ex) {}
        }

        // Open
        offcanvas.style.transition = 'none';
        offcanvas.offsetHeight; // force reflow
        offcanvas.style.transition = '';
        offcanvas.showModal();
    }, true); // ← capture phase

    // ── Close handlers ──
    function closeOffcanvas(offcanvas) {
        if (offcanvas && offcanvas.open) offcanvas.close();
    }

    function restoreElements() {
        movedElements.forEach(function (originalParent, element) {
            if (originalParent && element) {
                originalParent.appendChild(element);
            }
        });
        movedElements.clear();
    }

    // Close button (event delegation so it works on fresh dialog after Turbo nav)
    document.addEventListener('click', function (e) {
        if (e.target.closest('.offcanvas-close')) {
            var offcanvas = e.target.closest('dialog#offcanvas');
            if (offcanvas) closeOffcanvas(offcanvas);
        }
    }, true);

    // Backdrop click
    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'offcanvas' && e.target.tagName === 'DIALOG') {
            closeOffcanvas(e.target);
        }
    });

    // Restore moved elements when dialog closes (ESC key or programmatic)
    document.addEventListener('close', function (e) {
        if (e.target && e.target.id === 'offcanvas') {
            restoreElements();
        }
    }, true);

    // ── Turbo: close offcanvas + restore before navigation ──
    document.addEventListener('turbo:before-visit', function () {
        var offcanvas = document.getElementById('offcanvas');
        if (offcanvas && offcanvas.open) {
            closeOffcanvas(offcanvas);
        }
        restoreElements();
    });
})();
