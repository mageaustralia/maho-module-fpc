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

        // Collect session messages
        /** @var Mageaustralia_Fpc_Model_Ajax_Message_Storage $messageStorage */
        $messageStorage = Mage::getModel('mageaustralia_fpc/ajax_message_storage');
        $messages = $messageStorage->extractAll();

        /** @var Mageaustralia_Fpc_Model_Ajax_Core $ajaxCore */
        $ajaxCore = Mage::getModel('mageaustralia_fpc/ajax_core');

        // Get form key and force-persist to session file
        $formKey = Mage::getSingleton('core/session')->getFormKey();

        // Maho uses Symfony session handler which doesn't always persist
        // $_SESSION mutations made through Maho's namespace references.
        // Write directly to session file after close as a workaround.
        $sessionId = session_id();
        register_shutdown_function(function () use ($sessionId, $formKey) {
            $path = Mage::getBaseDir('session') . '/sess_' . $sessionId;
            if (!is_file($path)) {
                return;
            }
            $data = file_get_contents($path);
            if ($data === false || str_contains($data, '_form_key')) {
                return;
            }
            // Inject _form_key into the core namespace
            $data = str_replace(
                'core|a:1:{s:8:"messages"',
                'core|a:2:{s:9:"_form_key";s:16:"' . $formKey . '";s:8:"messages"',
                $data,
            );
            file_put_contents($path, $data, LOCK_EX);
        });

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
    public function minicartAction(): void
    {
        // Set the "current URL" to the referring page so that uenc in
        // remove/update links redirects back to the actual page, not this endpoint.
        $referer = $this->getRequest()->getHeader('Referer');
        if ($referer) {
            $this->getRequest()->setParam(
                Mage_Core_Controller_Varien_Action::PARAM_NAME_URL_ENCODED,
                Mage::helper('core')->urlEncode($referer),
            );
            Mage::unregister('current_url');
            Mage::register('current_url', $referer);
        }

        $this->loadLayout(['default']);

        $blockName = Mage::getStoreConfig('system/fpc/minicart_block') ?: 'cart_sidebar';
        $block = $this->getLayout()->getBlock($blockName);

        // Fallback: create block directly if layout doesn't have it
        if (!$block) {
            // Maho renamed sidebar.phtml → minicart.phtml in the base theme.
            // Try minicart.phtml first, fall back to sidebar.phtml for legacy themes.
            $template = 'checkout/cart/minicart.phtml';
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
