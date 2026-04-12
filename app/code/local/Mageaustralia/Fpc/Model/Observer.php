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

    /** @var array<int, array{event_type: string, url_path: string, ttfb_ms: ?int, flush_reason: ?string, store_code: string}> */
    private array $statsBatch = [];

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
    public function saveCache(Maho\Event\Observer $observer): void
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

        // Log miss — this page was not cached, we just wrote it.
        // Skip stat logging for warmer requests so they don't skew hit rate.
        $urlPath = trim($request->getOriginalPathInfo() ?: $request->getPathInfo(), '/');
        if (!$this->isWarmerRequest($request)) {
            $this->logStat('miss', $urlPath);
            $this->flushStatsBatch();
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
    public function checkCache(Maho\Event\Observer $observer): void
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

        $urlPath = trim($request->getOriginalPathInfo() ?: $request->getPathInfo(), '/');

        if (!$cache->exists($cacheKey)) {
            return;
        }

        $html = $cache->load($cacheKey);
        if ($html === null) {
            return;
        }

        // Log hit and flush stats before exit
        $this->logStat('hit', $urlPath);
        $this->flushStatsBatch();

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
    public function onProductSave(Maho\Event\Observer $observer): void
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

        $this->logStat('purge', implode(', ', $urls), null, 'product_save:' . $product->getSku());
    }

    // ── Invalidation: Category Save ─────────────────────────────────

    /**
     * Purge FPC entries for a category, its parent, and children.
     *
     * Event: catalog_category_save_after
     */
    public function onCategorySave(Maho\Event\Observer $observer): void
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

        $this->logStat('purge', implode(', ', $urls), null, 'category_save:' . $category->getName());
    }

    // ── Invalidation: CMS Page Save ─────────────────────────────────

    /**
     * Purge FPC entries for a CMS page.
     *
     * Event: cms_page_save_after
     */
    public function onCmsPageSave(Maho\Event\Observer $observer): void
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

        $this->logStat('purge', implode(', ', $paths), null, 'cms_page_save:' . $identifier);
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
    public function onCmsBlockSave(Maho\Event\Observer $observer): void
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

        $this->logStat('flush', '*', null, 'cms_block_save:' . $block->getIdentifier());
    }

    // ── Invalidation: Blog Post Save ────────────────────────────────

    /**
     * Flush blog-related FPC entries when a blog post is saved.
     *
     * Event: maho_blog_post_save_after
     */
    public function onBlogPostSave(Maho\Event\Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
            return;
        }

        $post = $observer->getEvent()->getObject();
        if (!$post || !$post->getId()) {
            return;
        }

        $paths = [];

        // Purge the individual post URL
        $urlKey = $post->getUrlKey();
        if ($urlKey) {
            $paths[] = 'blog/' . $urlKey;
        }

        // Purge the blog index page
        $paths[] = 'blog';

        // Purge blog category pages this post belongs to
        $categoryIds = $post->getCategoryIds();
        if (is_array($categoryIds)) {
            foreach ($categoryIds as $catId) {
                try {
                    $category = Mage::getModel('maho_blog/category'); // @phpstan-ignore mage.invalidType
                    if (!$category) {
                        continue;
                    }
                    $category->load($catId);
                    if ($category->getId() && $category->getUrlKey()) {
                        $paths[] = 'blog/category/' . $category->getUrlKey();
                    }
                } catch (\Exception $e) {
                    // Skip
                }
            }
        }

        if (count($paths) > 0) {
            $this->getCache()->purgeByPaths($paths);
            $this->logStat('purge', implode(', ', $paths), null, 'blog_post_save:' . $urlKey);
        }
    }

    // ── Invalidation: Stock Change ──────────────────────────────────

    /**
     * Purge FPC entries when product stock changes.
     *
     * Event: cataloginventory_stock_item_save_after
     */
    public function onStockSave(Maho\Event\Observer $observer): void
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
            $this->logStat('purge', implode(', ', $urls), null, 'stock_change:' . $product->getSku());
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
    public function onCleanCache(Maho\Event\Observer $observer): void
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
            $this->logStat('flush', '*', null, 'cache_clean:all_tags');
            return;
        }

        foreach ($tags as $tag) {
            foreach ($relevantPrefixes as $prefix) {
                if (str_starts_with($tag, $prefix)) {
                    $this->getCache()->flush();
                    $this->logStat('flush', '*', null, 'cache_clean:' . implode(',', $tags));
                    return;
                }
            }
        }
    }

    // ── Refresh Actions ─────────────────────────────────────────────

    /**
     * Flush FPC after configured refresh actions complete.
     *
     * Event: controller_action_postdispatch
     */
    public function onRefreshAction(Maho\Event\Observer $observer): void
    {
        if (!$this->getHelper()->isEnabled()) {
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

        $refreshActions = $this->getHelper()->getRefreshActions();
        if (in_array($fullActionName, $refreshActions, true)) {
            $this->getCache()->flush();
            $this->logStat('flush', '*', null, 'refresh_action:' . $fullActionName);
        }

        // Flush pending stats at end of request
        $this->flushStatsBatch();
    }

    // ── Config Changed ──────────────────────────────────────────────

    /**
     * Handle FPC config changes in admin.
     *
     * Event: admin_system_config_changed_section_mageaustralia_fpc
     */
    public function onFpcConfigChanged(Maho\Event\Observer $observer): void
    {
        // Flush FPC when config changes — cached pages may reflect old settings
        $this->getCache()->flush();
        $this->logStat('flush', '*', null, 'config_changed');
        $this->flushStatsBatch();
        Mage::log('FPC: config changed, flushed all cache entries', 6);
    }

    // ── Async Flush ─────────────────────────────────────────────────

    /**
     * Queue an entity for async flush (processed by cron every minute).
     */
    private function queueAsyncFlush(string $type, int $entityId): void
    {
        $flagFile = Mage::getBaseDir('var') . '/fpc_async_queue.json';
        $queue = [];

        if (is_file($flagFile)) {
            $raw = file_get_contents($flagFile);
            if ($raw !== false) {
                $queue = json_decode($raw, true) ?: [];
            }
        }

        $queue[$type][$entityId] = time();
        file_put_contents($flagFile, json_encode($queue), LOCK_EX);
    }

    /**
     * Cron: process queued async flushes (runs every minute).
     */
    public function processAsyncFlush(): void
    {
        $flagFile = Mage::getBaseDir('var') . '/fpc_async_queue.json';

        if (!is_file($flagFile)) {
            return;
        }

        $raw = file_get_contents($flagFile);
        if ($raw === false) {
            return;
        }

        $queue = json_decode($raw, true);
        if (empty($queue)) {
            @unlink($flagFile);
            return;
        }

        // Remove first to avoid race conditions with concurrent saves
        @unlink($flagFile);

        $helper = $this->getHelper();
        $cache = $this->getCache();

        if (!empty($queue['products'])) {
            foreach (array_keys($queue['products']) as $productId) {
                try {
                    $product = Mage::getModel('catalog/product')->load($productId);
                    if ($product->getId()) {
                        $urls = $helper->getProductUrls($product);
                        $cache->purgeByPaths($urls);
                        $this->logStat('purge', implode(', ', $urls), null, 'async_flush:product_' . $productId);
                    }
                } catch (\Throwable $e) {
                    Mage::log('FPC async flush error for product ' . $productId . ': ' . $e->getMessage(), 3);
                }
            }
        }

        $this->flushStatsBatch();
    }

    /**
     * Cron: clean expired FPC files (runs every 6 hours).
     */
    public function cleanExpiredCache(): void
    {
        $helper = $this->getHelper();
        $fpcDir = $helper->getFpcDir();

        if (!is_dir($fpcDir)) {
            return;
        }

        $lifetime = $helper->getCacheLifetime();
        $now = time();
        $count = 0;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fpcDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isFile()) {
                $mtime = $item->getMTime();
                if (($now - $mtime) > $lifetime) {
                    @unlink($item->getPathname());
                    $count++;
                }
            } elseif ($item->isDir()) {
                // Remove empty directories
                @rmdir($item->getPathname());
            }
        }

        if ($count > 0) {
            Mage::log("FPC: cleaned {$count} expired cache files", 6);
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

    // ── Cache Warmup ────────────────────────────────────────────────

    /**
     * Cron: process cache warmup batch (runs every minute).
     */
    public function processWarmup(): void
    {
        if (!Mage::getStoreConfigFlag('system/fpc/warmup_enabled')) {
            return;
        }

        /** @var Mageaustralia_Fpc_Model_Warmup $warmup */
        $warmup = Mage::getModel('mageaustralia_fpc/warmup');
        $warmup->runBatch();
    }


    // ── Stats Logging ───────────────────────────────────────────────

    /**
     * Log an FPC stat event. Batches inserts for performance.
     *
     * Events are queued in memory and written as a single multi-row INSERT
     * at the end of the request (via flushStatsBatch) or when the batch
     * reaches 50 records.
     */
    private function logStat(
        string $eventType,
        string $urlPath = '',
        ?int $ttfbMs = null,
        ?string $flushReason = null,
    ): void {
        if (!Mage::getStoreConfigFlag('system/fpc/stats_enabled')) {
            return;
        }

        try {
            $storeCode = Mage::app()->getStore()->getCode();
        } catch (\Throwable) {
            $storeCode = 'default';
        }

        $this->statsBatch[] = [
            'event_type'   => $eventType,
            'url_path'     => substr(ltrim($urlPath, '/'), 0, 500),
            'ttfb_ms'      => $ttfbMs,
            'flush_reason' => $flushReason !== null ? substr($flushReason, 0, 255) : null,
            'store_code'   => $storeCode,
            'created_at'   => date('Y-m-d H:i:s'),
        ];

        // Auto-flush batch at 50 records to avoid unbounded memory use
        if (count($this->statsBatch) >= 50) {
            $this->flushStatsBatch();
        }
    }

    /**
     * Write batched stats to the database in a single multi-row INSERT.
     */
    private function flushStatsBatch(): void
    {
        if (empty($this->statsBatch)) {
            return;
        }

        try {
            $resource = Mage::getSingleton('core/resource');
            $write = $resource->getConnection('core_write');
            $table = $resource->getTableName('mageaustralia_fpc/stats');

            $write->insertMultiple($table, $this->statsBatch);
        } catch (\Throwable $e) {
            Mage::log('FPC stats write error: ' . $e->getMessage(), 3);
        }

        $this->statsBatch = [];
    }

    /**
     * Cron: aggregate raw stats into hourly rollup table (runs every hour at :05).
     */
    public function rollupHourlyStats(): void
    {
        if (!Mage::getStoreConfigFlag('system/fpc/stats_enabled')) {
            return;
        }

        try {
            $helper = $this->getHelper();
            $count = $helper->rollupHourlyStats();
            if ($count > 0) {
                Mage::log("FPC: hourly rollup processed {$count} buckets", 6);
            }
        } catch (\Throwable $e) {
            Mage::log('FPC rollup error: ' . $e->getMessage(), 3);
        }
    }

    /**
     * Cron: run scheduled warmup (independent of flush).
     *
     * Uses cron expression from system/fpc/warmup_schedule config.
     */
    public function processScheduledWarmup(): void
    {
        if (!Mage::getStoreConfigFlag('system/fpc/warmup_enabled')) {
            return;
        }

        /** @var Mageaustralia_Fpc_Model_Warmup $warmup */
        $warmup = Mage::getModel('mageaustralia_fpc/warmup');
        $warmup->scheduleIfStale();
        $warmup->runBatch();
    }

    /**
     * Cron: delete stats older than configured retention period.
     *
     * Runs daily at 3am (configured in config.xml).
     */
    public function cleanOldStats(): void
    {
        $days = (int) (Mage::getStoreConfig('system/fpc/stats_retention_days') ?: 30);

        try {
            $resource = Mage::getSingleton('core/resource');
            $write = $resource->getConnection('core_write');
            $table = $resource->getTableName('mageaustralia_fpc/stats');

            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $deleted = $write->delete($table, ['created_at < ?' => $cutoff]);

            if ($deleted > 0) {
                Mage::log("FPC: cleaned {$deleted} stats records older than {$days} days", 6);
            }
        } catch (\Throwable $e) {
            Mage::log('FPC stats cleanup error: ' . $e->getMessage(), 3);
        }

        // Also clean old rollup data
        $this->getHelper()->cleanOldRollupStats();
    }

    /**
     * Detect whether the current request is from the FPC cache warmer.
     *
     * The warmer identifies itself via User-Agent. We skip stat logging for
     * warmer requests so that warming a sitemap doesn't count as thousands
     * of cache misses.
     */
    private function isWarmerRequest(Mage_Core_Controller_Request_Http $request): bool
    {
        $ua = (string) $request->getServer('HTTP_USER_AGENT', '');
        return str_starts_with($ua, 'MahoFPC-Warmup/');
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
