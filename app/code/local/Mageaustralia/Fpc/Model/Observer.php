<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Main FPC observer — handles cache write, cache hit, and invalidation.
 *
 * Cache write:  controller_front_send_response_before
 * Cache check:  controller_action_predispatch
 * Invalidation: catalog/cms/stock save events + application_clean_cache
 */
class Mageaustralia_Fpc_Model_Observer
{
    private ?Mageaustralia_Fpc_Helper_Data $helper = null;
    private ?Mageaustralia_Fpc_Model_Cache $cache = null;

    // ── Cache Write ─────────────────────────────────────────────────

    /**
     * Capture the response HTML and save it to static cache files.
     *
     * Event: controller_front_send_response_before
     *
     * Flow:
     * 1. Skip if FPC disabled, POST, admin, no_cache, or non-cacheable action
     * 2. Skip if layout handles include a bypass handle
     * 3. Replace dynamic block content with placeholder divs
     * 4. Write processed HTML to var/fpc/{store}/{hash}.html + .html.gz
     * 5. Set CDN headers (Cache-Control, X-Fpc)
     */
    public function saveCache(Varien_Event_Observer $observer): void
    {
        $helper = $this->getHelper();

        if (!$helper->isEnabled()) {
            return;
        }

        $front = $observer->getEvent()->getFront();
        if (!$front) {
            return;
        }

        $request = $front->getRequest();
        $response = $front->getResponse();

        // Never cache POST, admin, no_cache, or requests with unknown query params
        if ($helper->isPostRequest() || $helper->hasNoCacheParam() || $helper->isAdminLoggedIn()) {
            return;
        }
        if ($helper->hasUnknownQueryParams($request)) {
            return;
        }

        // Only cache configured actions
        $fullActionName = $request->getRequestedRouteName() . '_'
            . $request->getRequestedControllerName() . '_'
            . $request->getRequestedActionName();

        if (!$helper->isCacheableAction($fullActionName)) {
            return;
        }

        // Check for bypass handles
        $layout = Mage::app()->getLayout();
        if ($layout) {
            $handles = $layout->getUpdate()->getHandles();
            if ($helper->hasBypassHandle($handles)) {
                return;
            }
        }

        // Only cache 200 responses
        $httpCode = $response->getHttpResponseCode();
        if ($httpCode !== 200) {
            return;
        }

        $html = $response->getBody();
        if ($html === '' || $html === false) {
            return;
        }

        // Replace dynamic blocks with placeholders
        $html = $this->replaceDynamicBlocks($html);

        // Minify: collapse whitespace between tags (saves ~15-25% HTML size)
        $html = $this->minifyHtml($html);

        // Build cache key and save
        $cacheKey = $helper->buildCacheKey($request);
        $cache = $this->getCache();
        $cache->save($cacheKey, $html);

        // Set cache headers — browser must revalidate (304), CDN can cache with TTL
        $cdnMaxAge = $helper->getCdnMaxAge();
        $response->setHeader(
            'Cache-Control',
            "public, no-cache, s-maxage={$cdnMaxAge}, stale-while-revalidate=86400",
            true,
        );
        if ($helper->showCacheAge()) {
            $response->setHeader('X-Fpc', 'MISS', true);
            $response->setHeader('X-FPC-Age', '0', true);
        }
    }

    // ── Cache Hit ───────────────────────────────────────────────────

    /**
     * Check for a cached response before controller dispatch.
     *
     * Event: controller_action_predispatch
     *
     * If a cached file exists, serve it directly and set the X-Fpc: HIT header.
     * This is the PHP fallback — nginx try_files handles most hits without PHP.
     */
    public function checkCache(Varien_Event_Observer $observer): void
    {
        $helper = $this->getHelper();

        if (!$helper->isEnabled()) {
            return;
        }

        if ($helper->isPostRequest() || $helper->hasNoCacheParam() || $helper->isAdminLoggedIn()) {
            return;
        }
        if ($helper->hasUnknownQueryParams()) {
            return;
        }

        $action = $observer->getEvent()->getControllerAction();
        if (!$action) {
            return;
        }

        $request = $action->getRequest();
        $fullActionName = $request->getRequestedRouteName() . '_'
            . $request->getRequestedControllerName() . '_'
            . $request->getRequestedActionName();

        if (!$helper->isCacheableAction($fullActionName)) {
            return;
        }

        $cacheKey = $helper->buildCacheKey($request);
        $cache = $this->getCache();

        if (!$cache->exists($cacheKey)) {
            return;
        }

        $html = $cache->load($cacheKey);
        if ($html === null) {
            return;
        }

        // Serve cached response
        $response = $action->getResponse();
        $response->setBody($html);

        $cdnMaxAge = $helper->getCdnMaxAge();
        $response->setHeader(
            'Cache-Control',
            "public, no-cache, s-maxage={$cdnMaxAge}, stale-while-revalidate=86400",
            true,
        );
        if ($helper->showCacheAge()) {
            $response->setHeader('X-Fpc', 'HIT', true);
            $age = $cache->getAge($cacheKey);
            $response->setHeader('X-FPC-Age', (string) $age, true);
        }

        $response->sendResponse();
        // @phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit;
    }

    // ── Invalidation: Product Save ──────────────────────────────────

    /**
     * Purge FPC entries for a product and its category pages.
     *
     * Event: catalog_product_save_after
     */
    public function onProductSave(Varien_Event_Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
            return;
        }

        /** @var Mage_Catalog_Model_Product $product */
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        if (!$this->getHelper()->shouldFlushOnProductSave()) {
            $this->queueAsyncFlush('products', (int) $product->getId());
            return;
        }

        $urls = $this->getHelper()->getProductUrls($product);
        $this->getCache()->purgeByPaths($urls);
    }

    // ── Invalidation: Category Save ─────────────────────────────────

    /**
     * Purge FPC entries for a category, its parent, and children.
     *
     * Event: catalog_category_save_after
     */
    public function onCategorySave(Varien_Event_Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
            return;
        }

        /** @var Mage_Catalog_Model_Category $category */
        $category = $observer->getEvent()->getCategory();
        if (!$category || !$category->getId()) {
            return;
        }

        $urls = $this->getHelper()->getCategoryUrls($category);
        $this->getCache()->purgeByPaths($urls);
    }

    // ── Invalidation: CMS Page Save ─────────────────────────────────

    /**
     * Purge FPC entries for a CMS page.
     *
     * Event: cms_page_save_after
     */
    public function onCmsPageSave(Varien_Event_Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
            return;
        }

        /** @var Mage_Cms_Model_Page $page */
        $page = $observer->getEvent()->getObject();
        if (!$page || !$page->getId()) {
            return;
        }

        $paths = [];
        $identifier = $page->getIdentifier();
        if ($identifier) {
            $paths[] = $identifier;
        }

        // If this is the home page, also purge root
        if ($identifier === Mage::getStoreConfig('web/default/cms_home_page')) {
            $paths[] = '';
        }

        $this->getCache()->purgeByPaths($paths);
    }

    // ── Invalidation: CMS Block Save ────────────────────────────────

    /**
     * Flush all FPC when a CMS block is saved.
     *
     * Event: cms_block_save_after
     *
     * CMS blocks can appear on any page, so we flush everything.
     * This is conservative but correct — blocks don't carry page context.
     */
    public function onCmsBlockSave(Varien_Event_Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
            return;
        }

        $block = $observer->getEvent()->getObject();
        if (!$block || !$block->getId()) {
            return;
        }

        // CMS blocks can appear anywhere — flush all
        $this->getCache()->flush();
    }

    // ── Invalidation: Stock Change ──────────────────────────────────

    /**
     * Purge FPC entries when product stock changes.
     *
     * Event: cataloginventory_stock_item_save_after
     */
    public function onStockSave(Varien_Event_Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
            return;
        }

        /** @var Mage_CatalogInventory_Model_Stock_Item $stockItem */
        $stockItem = $observer->getEvent()->getItem();
        if (!$stockItem || !$stockItem->getProductId()) {
            return;
        }

        // Only purge if stock status actually changed
        if (!$stockItem->getStockStatusChangedAuto() && !$stockItem->dataHasChangedFor('is_in_stock')) {
            return;
        }

        if (!$this->getHelper()->shouldFlushOnStockChange()) {
            $this->queueAsyncFlush('products', (int) $stockItem->getProductId());
            return;
        }

        $product = Mage::getModel('catalog/product')->load($stockItem->getProductId());
        if ($product->getId()) {
            $urls = $this->getHelper()->getProductUrls($product);
            $this->getCache()->purgeByPaths($urls);
        }
    }

    // ── Invalidation: Cache Tag Flush ───────────────────────────────

    /**
     * Respond to application cache flush events.
     *
     * Event: application_clean_cache
     *
     * If specific tags are flushed that relate to catalog/CMS, flush FPC.
     * If no tags (full flush), flush everything.
     */
    public function onCleanCache(Varien_Event_Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
            return;
        }

        $tags = $observer->getEvent()->getTags();

        // Full flush (no tags) or catalog/CMS related tags
        $relevantPrefixes = [
            'FPC',
            'catalog_product_',
            'catalog_category_',
            'cms_page',
            'cms_block',
            'CATALOG_',
            'CMS_',
        ];

        if (empty($tags)) {
            $this->getCache()->flush();
            return;
        }

        foreach ($tags as $tag) {
            foreach ($relevantPrefixes as $prefix) {
                if (str_starts_with($tag, $prefix)) {
                    $this->getCache()->flush();
                    return;
                }
            }
        }
    }

    // ── Dynamic Block Replacement ───────────────────────────────────

    /**
     * Replace dynamic block content with placeholder divs.
     *
     * Each dynamic block config entry defines:
     * - name: block identifier for the AJAX loader
     * - selector: CSS selector to find the element (#id, .class, [attr])
     * - mode: "text" or "html"
     *
     * The selector is converted to a regex pattern that finds the matching
     * element and replaces its innerHTML with a data-fpc-block placeholder.
     */
    private function replaceDynamicBlocks(string $html): string
    {
        $blocks = $this->getHelper()->getDynamicBlocks();

        foreach ($blocks as $name => $config) {
            $selector = $config['selector'];

            // Convert CSS selector to regex for element matching
            $pattern = $this->selectorToPattern($selector);
            if ($pattern === null) {
                continue;
            }

            // Replace the element's content with a placeholder
            $replacement = '<div data-fpc-block="' . $name . '"></div>';

            $html = preg_replace(
                $pattern,
                '$1' . $replacement . '$3',
                $html,
                1, // Only replace first match
            ) ?? $html;
        }

        return $html;
    }

    /**
     * Convert a simple CSS selector to a regex pattern.
     *
     * Supports: #id, .class, [data-attr]
     * Returns a pattern with 3 groups: (opening tag)(content)(closing tag)
     */
    private function selectorToPattern(string $selector): ?string
    {
        if (str_starts_with($selector, '#')) {
            // ID selector: #some-id
            $id = preg_quote(substr($selector, 1), '/');
            return '/(<[^>]+\bid=["\']' . $id . '["\'][^>]*>)(.*?)(<\/[a-z]+>)/si';
        }

        if (str_starts_with($selector, '.')) {
            // Class selector: .some-class
            $class = preg_quote(substr($selector, 1), '/');
            return '/(<[^>]+\bclass=["\'][^"\']*\b' . $class . '\b[^"\']*["\'][^>]*>)(.*?)(<\/[a-z]+>)/si';
        }

        if (str_starts_with($selector, '[') && str_ends_with($selector, ']')) {
            // Attribute selector: [data-cart-count]
            $attr = preg_quote(substr($selector, 1, -1), '/');
            return '/(<[^>]+\b' . $attr . '(?:=["\'][^"\']*["\'])?[^>]*>)(.*?)(<\/[a-z]+>)/si';
        }

        return null;
    }

    // ── HTML Minification ──────────────────────────────────────────

    /**
     * Minify HTML by collapsing whitespace between tags.
     *
     * Preserves whitespace inside <pre>, <script>, <style>, <textarea> blocks.
     */
    private function minifyHtml(string $html): string
    {
        // Extract and preserve content of sensitive tags
        $preserved = [];
        $html = preg_replace_callback(
            '/<(pre|script|style|textarea)\b[^>]*>.*?<\/\1>/si',
            static function (array $match) use (&$preserved): string {
                $key = '<!--FPC_PRESERVE_' . count($preserved) . '-->';
                $preserved[$key] = $match[0];
                return $key;
            },
            $html,
        ) ?? $html;

        // Collapse whitespace between tags
        $html = preg_replace('/>\s+</', '> <', $html) ?? $html;
        // Collapse runs of whitespace (but keep single space for inline elements)
        $html = preg_replace('/\s{2,}/', ' ', $html) ?? $html;
        // Remove HTML comments (except IE conditionals and preserved blocks)
        $html = preg_replace('/<!--(?!\[|FPC_PRESERVE_).*?-->/s', '', $html) ?? $html;

        // Restore preserved blocks
        return str_replace(array_keys($preserved), array_values($preserved), $html);
    }

    // ── Lazy Accessors ──────────────────────────────────────────────

    private function getHelper(): Mageaustralia_Fpc_Helper_Data
    {
        if ($this->helper === null) {
            $this->helper = Mage::helper('mageaustralia_fpc');
        }
        return $this->helper;
    }

    private function getCache(): Mageaustralia_Fpc_Model_Cache
    {
        if ($this->cache === null) {
            $this->cache = Mage::getModel('mageaustralia_fpc/cache');
        }
        return $this->cache;
    }
}
