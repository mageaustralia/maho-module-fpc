<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Dynamic block controller — serves AJAX-loaded block content for FPC hole-punching.
 *
 * GET /fpc/dynamic/?blocks=cart_count,account_links,messages
 *
 * Renders blocks based on admin config (dynamic blocks table).
 * Each block config row defines: name, block_type, template, selector, mode.
 *
 * Block types:
 *   - "checkout/cart_sidebar"          → creates Maho block of that type
 *   - "helper:checkout/cart:getMethod" → calls a helper method, returns the result as string
 *   - ""                               → tries to find a layout block by name
 */
class Mageaustralia_Fpc_DynamicController extends Mage_Core_Controller_Front_Action
{
    /**
     * Render requested dynamic blocks and return as JSON.
     */
    #[\Maho\Config\Route('/fpc/dynamic', name: 'fpc.dynamic')]
    #[\Maho\Config\Route('/fpc/dynamic/index', name: 'fpc.dynamic.index')]
    public function indexAction(): void
    {
        // Log FPC cache hit for stats tracking
        $pagePath = trim((string) $this->getRequest()->getParam('p', ''));
        if ($pagePath !== '' && Mage::getStoreConfigFlag('system/fpc/stats_enabled')) {
            try {
                $db = Mage::getSingleton('core/resource')->getConnection('core_write');
                $table = Mage::getSingleton('core/resource')->getTableName('mageaustralia_fpc_stats');
                $db->insert($table, [
                    'event_type' => 'hit',
                    'url_path'   => ltrim(substr($pagePath, 0, 500), '/'),
                    'ttfb_ms'    => (int) $this->getRequest()->getParam('ttfb', 0),
                    'store_code' => Mage::app()->getStore()->getCode(),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                // Don't let stats tracking break the dynamic loader
            }
        }

        $blockParam = trim((string) $this->getRequest()->getParam('blocks', ''));

        $requestedNames = $blockParam !== ''
            ? array_filter(array_map('trim', explode(',', $blockParam)))
            : [];

        /** @var Mageaustralia_Fpc_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_fpc');
        $dynamicBlocks = $helper->getDynamicBlocks();

        // Only render blocks that are configured
        $validNames = array_intersect($requestedNames, array_keys($dynamicBlocks));

        // Load layout for layout-block lookups
        $this->loadLayout(['default']);

        $result = [];
        foreach ($validNames as $name) {
            $config = $dynamicBlocks[$name];
            $html = $this->renderConfiguredBlock($name, $config);

            if ($config['mode'] === 'text') {
                $result[$name] = trim(strip_tags($html));
            } else {
                $result[$name] = $html;
            }
        }

        // Collect session messages — extractAll() calls getMessages(true)
        // on each registered namespace which empties the in-memory message
        // collection (Mage's _data['messages'] is a reference into $_SESSION
        // under Symfony's session handler, so the mutation IS visible).
        /** @var Mageaustralia_Fpc_Model_Ajax_Message_Storage $messageStorage */
        $messageStorage = Mage::getModel('mageaustralia_fpc/ajax_message_storage');
        $messages = $messageStorage->extractAll();

        /** @var Mageaustralia_Fpc_Model_Ajax_Core $ajaxCore */
        $ajaxCore = Mage::getModel('mageaustralia_fpc/ajax_core');

        // Mint (or read) the form key. This writes _form_key into the core
        // session namespace ($_SESSION['core']). It MUST be persisted to the
        // session store so the form_key handed back below matches the one
        // Mage::getSingleton('core/session')->getFormKey() returns on the
        // subsequent add-to-cart / login POST.
        $formKey = Mage::getSingleton('core/session')->getFormKey();

        // Commit the session NOW, before the JSON response is flushed to the
        // client. Two reasons:
        //   1. Message flush: extractAll() above cleared the flash messages from
        //      $_SESSION; persisting here guarantees the next /fpc/dynamic poll
        //      does not re-read and re-render stale messages.
        //   2. Form key: persists the freshly-minted _form_key so cold sessions
        //      (visitor landing on an nginx-static FPC page, no PHP session yet)
        //      get a form_key that actually matches on the next POST.
        // This relies on the session still being WRITE-OPEN at this point. The
        // storefront "release session for read requests" observer explicitly
        // skips the 'fpc' module for exactly this reason — see
        // Mageaustralia_Storefront_Model_Observer::releaseSessionForReadRequest().
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->sendJson([
            'success'        => true,
            'blocks'         => $result,
            'messages'       => $messages,
            'cart_qty'       => $ajaxCore->getCartQty(),
            'compare_count'  => (int) Mage::helper('catalog/product_compare')->getItemCount(),
            'wishlist_count' => (int) Mage::helper('wishlist')->getItemCount(),
            'form_key'       => $formKey,
        ]);
    }

    /**
     * Return fresh minicart HTML for sidebar AJAX refresh.
     *
     * GET /fpc/dynamic/minicart
     */
    #[\Maho\Config\Route('/fpc/dynamic/minicart', name: 'fpc.dynamic.minicart')]
    public function minicartAction(): void
    {
        // Set the "current URL" to the referring page so that uenc in
        // remove/update links redirects back to the actual page, not this endpoint.
        // turbo-compat.js passes the page URL as ?referer= because browsers
        // forbid setting the Referer header on fetch requests.
        $referer = $this->getRequest()->getParam('referer')
            ?: $this->getRequest()->getHeader('Referer');
        if ($referer) {
            $this->getRequest()->setParam(
                Mage_Core_Controller_Varien_Action::PARAM_NAME_URL_ENCODED,
                Mage::helper('core')->urlEncode($referer),
            );
            Mage::unregister('current_url');
            Mage::register('current_url', $referer);
        }

        $this->loadLayout(['default']);

        $blockName = Mage::getStoreConfig('system/fpc/minicart_block') ?: 'minicart_content';
        $block = $this->getLayout()->getBlock($blockName);

        // Fallback: create block directly if layout doesn't have it
        if (!$block) {
            // Maho base theme uses checkout/cart/minicart/items.phtml for the
            // inner cart content (product list + subtotal + checkout button).
            // Legacy themes use checkout/cart/sidebar.phtml for the same.
            // minicart.phtml renders the WHOLE header section (icon + wrapper)
            // which is NOT what we want for the AJAX refresh.
            $template = 'checkout/cart/minicart/items.phtml';
            if (!file_exists(Mage::getDesign()->getTemplateFilename($template))) {
                $template = 'checkout/cart/sidebar.phtml';
            }
            $block = $this->getLayout()->createBlock('checkout/cart_sidebar')
                ->setTemplate($template);
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setBody($block ? $block->toHtml() : '');
    }

    /**
     * Render a block based on its config row.
     *
     * @param array{block_type?: string, template?: string, selector: string, mode: string} $config
     */
    private function renderConfiguredBlock(string $name, array $config): string
    {
        $blockType = $config['block_type'] ?? '';
        $template = $config['template'] ?? '';

        // Helper call: "helper:module/helper:methodName"
        if (str_starts_with($blockType, 'helper:')) {
            return $this->renderHelperCall($blockType);
        }

        // Maho block type: create block by alias
        if ($blockType !== '') {
            return $this->renderBlockByType($blockType, $template);
        }

        // Fallback: try layout block by name
        return $this->renderLayoutBlock($name);
    }

    /**
     * Call a helper method and return its result as string.
     *
     * Format: "helper:module/helper:methodName"
     * Example: "helper:checkout/cart:getSummaryCount"
     */
    private function renderHelperCall(string $spec): string
    {
        // Remove "helper:" prefix
        $spec = substr($spec, 7);
        $parts = explode(':', $spec, 2);

        if (count($parts) !== 2) {
            return '';
        }

        [$helperAlias, $method] = $parts;

        try {
            $helper = Mage::helper($helperAlias);
            if ($helper && method_exists($helper, $method)) {
                return (string) $helper->$method();
            }
        } catch (\Throwable) {
            // Silent fail — block just renders empty
        }

        return '';
    }

    /**
     * Create a block by Maho block type alias and render it.
     *
     * Example: "checkout/cart_sidebar" with template "checkout/cart/minicart.phtml"
     */
    private function renderBlockByType(string $blockType, string $template = ''): string
    {
        try {
            $block = $this->getLayout()->createBlock($blockType);
            if (!$block) {
                return '';
            }
            if ($template !== '') {
                $block->setTemplate($template);
            }
            return $block->toHtml();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Render a named layout block (from the loaded layout).
     */
    private function renderLayoutBlock(string $name): string
    {
        $block = $this->getLayout()->getBlock($name);
        return $block ? $block->toHtml() : '';
    }

    /**
     * Send a JSON response.
     *
     * @param array<string, mixed> $data
     */
    private function sendJson(array $data): void
    {
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true)
            ->setBody(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
