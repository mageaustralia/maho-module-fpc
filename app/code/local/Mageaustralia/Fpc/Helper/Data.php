<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

class Mageaustralia_Fpc_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const FPC_DIR = 'fpc';

    /** @var array<string, array{selector: string, mode: string}>|null */
    private ?array $dynamicBlocksCache = null;

    /** @var string[]|null */
    private ?array $cacheableActionsCache = null;

    /** @var string[]|null */
    private ?array $bypassHandlesCache = null;

    /** @var string[]|null */
    private ?array $uriParamsCache = null;

    /** @var string[]|null */
    private ?array $missParamsCache = null;

    // ── Enabled / Settings ──────────────────────────────────────────

    public function isEnabled(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/enabled');
    }

    public function getCacheLifetime(): int
    {
        return (int) (Mage::getStoreConfig('system/fpc/lifetime') ?: 86400);
    }

    public function getCdnMaxAge(): int
    {
        return (int) (Mage::getStoreConfig('system/fpc/cdn_max_age') ?: 60);
    }

    public function showCacheAge(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/show_age');
    }

    public function varyByCustomerGroup(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/customer_groups');
    }

    public function gzipOnly(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/gzip_only');
    }

    // ── Product/Stock Flush Toggles ────────────────────────────────

    public function shouldFlushOnProductSave(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/flush_on_product_save');
    }

    public function shouldFlushOnStockChange(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/flush_on_stock_change');
    }

    // ── Refresh Actions ─────────────────────────────────────────────

    /**
     * @return string[]
     */
    public function getRefreshActions(): array
    {
        return $this->parseLines((string) Mage::getStoreConfig('system/fpc/refresh_actions'));
    }

    // ── Turbo Drive ────────────────────────────────────────────────

    public function isTurboEnabled(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/turbo_enabled');
    }

    /**
     * @return string[]
     */
    public function getTurboExcludedPaths(): array
    {
        $raw = (string) Mage::getStoreConfig('system/fpc/turbo_excluded_paths');
        if ($raw === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    // ── Textarea Config Parsers ─────────────────────────────────────

    /**
     * Parse cacheable actions from config (one per line).
     *
     * @return string[]
     */
    public function getCacheableActions(): array
    {
        if ($this->cacheableActionsCache !== null) {
            return $this->cacheableActionsCache;
        }

        $raw = (string) Mage::getStoreConfig('system/fpc/cache_actions');
        $this->cacheableActionsCache = $this->parseLines($raw);
        return $this->cacheableActionsCache;
    }

    /**
     * Parse dynamic block definitions from config.
     *
     * Supports two formats:
     * 1. Serialized array (new admin UI — array of rows with name/block_type/template/selector/mode)
     * 2. Legacy text format: name:selector:mode (one per line, for backward compat)
     *
     * @return array<string, array{selector: string, mode: string, block_type?: string, template?: string}>
     */
    public function getDynamicBlocks(): array
    {
        if ($this->dynamicBlocksCache !== null) {
            return $this->dynamicBlocksCache;
        }

        $raw = Mage::getStoreConfig('system/fpc/dynamic_blocks');
        $blocks = [];

        // JSON string from admin config — decode first
        if (is_string($raw) && str_starts_with(trim($raw), '{')) {
            $raw = json_decode($raw, true) ?: [];
        }

        // New format: serialized array from admin table UI
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $name = trim($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $blocks[$name] = [
                    'selector'   => trim($row['selector'] ?? ''),
                    'mode'       => trim($row['mode'] ?? 'html'),
                    'block_type' => trim($row['block_type'] ?? ''),
                    'template'   => trim($row['template'] ?? ''),
                ];
            }
            $this->dynamicBlocksCache = $blocks;
            return $this->dynamicBlocksCache;
        }

        // Legacy format: name:selector:mode (one per line)
        $lines = $this->parseLines((string) ($raw ?? ''));

        foreach ($lines as $line) {
            $parts = explode(':', $line, 3);
            if (count($parts) === 3) {
                $blocks[$parts[0]] = [
                    'selector' => $parts[1],
                    'mode'     => $parts[2],
                ];
            }
        }

        $this->dynamicBlocksCache = $blocks;
        return $this->dynamicBlocksCache;
    }


    /**
     * Parse bypass handles from config.
     *
     * @return string[]
     */
    public function getBypassHandles(): array
    {
        if ($this->bypassHandlesCache !== null) {
            return $this->bypassHandlesCache;
        }

        $raw = (string) Mage::getStoreConfig('system/fpc/bypass_handles');
        $this->bypassHandlesCache = $this->parseLines($raw);
        return $this->bypassHandlesCache;
    }

    /**
     * Parse URI params to include in cache key.
     *
     * @return string[]
     */
    public function getUriParams(): array
    {
        if ($this->uriParamsCache !== null) {
            return $this->uriParamsCache;
        }

        $raw = (string) Mage::getStoreConfig('system/fpc/uri_params');
        $this->uriParamsCache = $this->parseLines($raw);
        return $this->uriParamsCache;
    }

    /**
     * Parse params to strip before cache key (UTM etc.).
     *
     * @return string[]
     */
    public function getMissParams(): array
    {
        if ($this->missParamsCache !== null) {
            return $this->missParamsCache;
        }

        $raw = (string) Mage::getStoreConfig('system/fpc/miss_uri_params');
        $this->missParamsCache = $this->parseLines($raw);
        return $this->missParamsCache;
    }

    // ── Cache Key ───────────────────────────────────────────────────

    /**
     * Build the cache key for the current request.
     *
     * Uses URL-path-based keys (Zoom FPC style) so nginx can serve static files
     * directly via try_files without computing hashes.
     *
     * Format: {store_code}/{url_path}[__param-val][__gN].html
     *
     * Examples:
     *   default/shoes.html                (base URL)
     *   default/shoes__p-2.html           (page 2)
     *   default/shoes__g1.html            (customer group 1)
     *   default/index.html                (homepage)
     */
    public function buildCacheKey(?Mage_Core_Controller_Request_Http $request = null): string
    {
        if ($request === null) {
            $request = Mage::app()->getRequest();
        }

        $storeCode = Mage::app()->getStore()->getCode();

        // Use the original request URI (rewritten URL), not the internal route
        $path = trim($request->getOriginalPathInfo() ?: $request->getPathInfo(), '/');

        // Homepage
        if ($path === '') {
            $path = 'index.html';
        }

        // Separate base and extension
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext === '') {
            $path .= '.html';
            $ext = 'html';
        }
        $base = substr($path, 0, -(strlen($ext) + 1));

        // Build suffix from allowed URI params
        $suffix = '';
        $allowedParams = $this->getUriParams();
        $missParams = $this->getMissParams();
        $paramParts = [];

        // Use only actual URL query params, not Maho's internal route params
        foreach ($request->getQuery() as $key => $value) {
            if (in_array($key, $missParams, true)) {
                continue;
            }
            if (in_array($key, $allowedParams, true) && $value !== '') {
                $paramParts[$key] = (string) $value;
            }
        }
        ksort($paramParts);

        foreach ($paramParts as $k => $v) {
            $suffix .= '__' . $this->sanitizePathSegment($k) . '-' . $this->sanitizePathSegment($v);
        }

        // Customer group variation
        if ($this->varyByCustomerGroup()) {
            $session = Mage::getSingleton('customer/session');
            $groupId = $session->isLoggedIn()
                ? (int) $session->getCustomerGroupId()
                : Mage_Customer_Model_Group::NOT_LOGGED_IN_ID;
            if ($groupId !== Mage_Customer_Model_Group::NOT_LOGGED_IN_ID) {
                $suffix .= '__g' . $groupId;
            }
        }

        return $storeCode . '/' . $base . $suffix . '.' . $ext;
    }

    /**
     * Sanitize a string for use in a cache file path segment.
     * Removes characters unsafe for filenames.
     */
    public function sanitizePathSegment(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $value);
    }

    /**
     * Check if a cache key is a "base" key (no params, no group suffix).
     * Base keys match nginx $uri directly.
     */
    public function isBaseCacheKey(string $cacheKey): bool
    {
        // Base keys don't contain __ (param/group separator)
        $path = substr($cacheKey, strpos($cacheKey, '/') + 1);
        return !str_contains($path, '__');
    }

    // ── File Paths ──────────────────────────────────────────────────

    /**
     * Get the base FPC directory: var/fpc/
     */
    public function getFpcDir(): string
    {
        return Mage::getBaseDir('var') . DS . self::FPC_DIR;
    }

    /**
     * Get the full file path for a cache key.
     * Sanitizes against directory traversal.
     */
    public function getCacheFilePath(string $cacheKey): string
    {
        $cacheKey = str_replace(['..', "\0"], '', $cacheKey);
        $path = $this->getFpcDir() . DS . $cacheKey;

        // Verify resolved path stays within FPC directory
        $dir = dirname($path);
        if (is_dir($dir)) {
            $realDir = realpath($dir);
            $realFpc = realpath($this->getFpcDir());
            if ($realDir !== false && $realFpc !== false && !str_starts_with($realDir, $realFpc)) {
                return $this->getFpcDir() . DS . '_invalid';
            }
        }

        return $path;
    }

    /**
     * Get the gzipped file path for a cache key.
     */
    public function getCacheFilePathGz(string $cacheKey): string
    {
        return $this->getCacheFilePath($cacheKey) . '.gz';
    }

    // ── Request Checks ──────────────────────────────────────────────

    /**
     * Check if the request has query params not in uri_params or miss_uri_params.
     *
     * Unknown params (filters, etc.) should bypass FPC since the cached page
     * doesn't reflect them. Only known params are handled (cached separately
     * or stripped).
     */
    public function hasUnknownQueryParams(?Mage_Core_Controller_Request_Http $request = null): bool
    {
        if ($request === null) {
            $request = Mage::app()->getRequest();
        }

        $query = $request->getQuery();
        if (empty($query)) {
            return false;
        }

        $allowedParams = $this->getUriParams();
        $missParams = $this->getMissParams();
        $knownParams = array_merge($allowedParams, $missParams);

        foreach ($query as $key => $value) {
            if (!in_array($key, $knownParams, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current request is POST.
     */
    public function isPostRequest(): bool
    {
        return Mage::app()->getRequest()->isPost();
    }

    /**
     * Check if the current request has no_cache=1 param.
     */
    public function hasNoCacheParam(): bool
    {
        return (bool) Mage::app()->getRequest()->getParam('no_cache');
    }

    /**
     * Check if the current user is a logged-in admin.
     */
    public function isAdminLoggedIn(): bool
    {
        try {
            $session = Mage::getSingleton('admin/session');
            return $session->isLoggedIn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if the current action is cacheable.
     */
    public function isCacheableAction(string $fullActionName): bool
    {
        return in_array($fullActionName, $this->getCacheableActions(), true);
    }

    /**
     * Check if the current layout handles contain any bypass handle.
     *
     * @param string[] $handles
     */
    public function hasBypassHandle(array $handles): bool
    {
        $bypass = $this->getBypassHandles();
        return array_intersect($handles, $bypass) !== [];
    }

    // ── URL Resolution ──────────────────────────────────────────────

    /**
     * Get all frontend URLs for a product (product URL + category URLs).
     *
     * @return string[]
     */
    public function getProductUrls(Mage_Catalog_Model_Product $product): array
    {
        $urls = [];

        // Product URL
        $productUrl = $product->getProductUrl();
        if ($productUrl) {
            $urls[] = $this->urlToPath($productUrl);
        }

        // Category URLs — batch load to avoid N+1
        $categoryIds = $product->getCategoryIds();
        if (!empty($categoryIds)) {
            $categories = Mage::getModel('catalog/category')->getCollection()
                ->addAttributeToSelect('url_key')
                ->addFieldToFilter('entity_id', ['in' => $categoryIds]);

            foreach ($categories as $category) {
                $catUrl = $category->getUrl();
                if ($catUrl) {
                    $urls[] = $this->urlToPath($catUrl);
                }
            }
        }

        return array_unique(array_filter($urls));
    }

    /**
     * Get all frontend URLs for a category (self + parent + children).
     *
     * @return string[]
     */
    public function getCategoryUrls(Mage_Catalog_Model_Category $category): array
    {
        $urls = [];

        // Self
        $selfUrl = $category->getUrl();
        if ($selfUrl) {
            $urls[] = $this->urlToPath($selfUrl);
        }

        // Parent
        $parentId = (int) $category->getParentId();
        if ($parentId > 1) {
            $parent = Mage::getModel('catalog/category')->load($parentId);
            if ($parent->getId()) {
                $parentUrl = $parent->getUrl();
                if ($parentUrl) {
                    $urls[] = $this->urlToPath($parentUrl);
                }
            }
        }

        // Children (one level)
        $children = $category->getChildrenCategories();
        foreach ($children as $child) {
            $childUrl = $child->getUrl();
            if ($childUrl) {
                $urls[] = $this->urlToPath($childUrl);
            }
        }

        return array_unique(array_filter($urls));
    }

    /**
     * Convert a full URL to a relative path for cache key matching.
     */
    public function urlToPath(string $url): string
    {
        $parsed = parse_url($url);
        return trim($parsed['path'] ?? '/', '/');
    }

    // ── Stats Query Methods ─────────────────────────────────────────

    /**
     * Get the FPC hit rate as a percentage for the given time window.
     *
     * @return array{hits: int, misses: int, total: int, rate: float}
     */
    public function getHitRate(int $hours = 24, string $storeCode = ''): array
    {
        $read = $this->getStatsReadConnection();
        $since = $this->getStatsSince($hours);

        // For periods > 24h, combine rollup (old) + raw (recent)
        if ($hours > 24 && $this->hasRollupTable()) {
            return $this->getHitRateCombined($hours, $storeCode);
        }

        $table = $this->getStatsTable();
        $select = $read->select()
            ->from($table, [
                'hits'   => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'hit' THEN 1 ELSE 0 END)"),
                'misses' => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'miss' THEN 1 ELSE 0 END)"),
            ])
            ->where('event_type IN (?)', ['hit', 'miss'])
            ->where('created_at >= ?', $since);

        $this->applyStoreFilter($select, $storeCode);

        $row = $read->fetchRow($select);

        $hits = (int) ($row['hits'] ?? 0);
        $misses = (int) ($row['misses'] ?? 0);
        $total = $hits + $misses;

        return [
            'hits'   => $hits,
            'misses' => $misses,
            'total'  => $total,
            'rate'   => $total > 0 ? round(($hits / $total) * 100, 2) : 0.0,
        ];
    }

    /**
     * Get hit rate combining rollup + raw data for longer periods.
     *
     * @return array{hits: int, misses: int, total: int, rate: float}
     */
    private function getHitRateCombined(int $hours, string $storeCode): array
    {
        $read = $this->getStatsReadConnection();
        $since = $this->getStatsSince($hours);
        $rawCutoff = $this->getStatsSince(24);

        // Rollup data (older than 24h)
        $rollupTable = $this->getStatsHourlyTable();
        $rollupSelect = $read->select()
            ->from($rollupTable, [
                'hits'   => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'hit' THEN `count` ELSE 0 END)"),
                'misses' => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'miss' THEN `count` ELSE 0 END)"),
            ])
            ->where('hour >= ?', $since)
            ->where('hour < ?', $rawCutoff);

        $this->applyStoreFilter($rollupSelect, $storeCode);

        $rollupRow = $read->fetchRow($rollupSelect);

        // Raw data (last 24h)
        $rawTable = $this->getStatsTable();
        $rawSelect = $read->select()
            ->from($rawTable, [
                'hits'   => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'hit' THEN 1 ELSE 0 END)"),
                'misses' => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'miss' THEN 1 ELSE 0 END)"),
            ])
            ->where('event_type IN (?)', ['hit', 'miss'])
            ->where('created_at >= ?', $rawCutoff);

        $this->applyStoreFilter($rawSelect, $storeCode);

        $rawRow = $read->fetchRow($rawSelect);

        $hits = (int) ($rollupRow['hits'] ?? 0) + (int) ($rawRow['hits'] ?? 0);
        $misses = (int) ($rollupRow['misses'] ?? 0) + (int) ($rawRow['misses'] ?? 0);
        $total = $hits + $misses;

        return [
            'hits'   => $hits,
            'misses' => $misses,
            'total'  => $total,
            'rate'   => $total > 0 ? round(($hits / $total) * 100, 2) : 0.0,
        ];
    }

    /**
     * Get the number of FPC flushes in the given time window.
     */
    public function getFlushCount(int $hours = 24, string $storeCode = ''): int
    {
        $read = $this->getStatsReadConnection();
        $table = $this->getStatsTable();
        $since = $this->getStatsSince($hours);

        $select = $read->select()
            ->from($table, ['cnt' => new Maho\Db\Expr('COUNT(*)')])
            ->where('event_type = ?', 'flush')
            ->where('created_at >= ?', $since);

        $this->applyStoreFilter($select, $storeCode);

        $count = (int) $read->fetchOne($select);

        // Add rollup flushes for periods > 24h
        if ($hours > 24 && $this->hasRollupTable()) {
            $rollupTable = $this->getStatsHourlyTable();
            $rawCutoff = $this->getStatsSince(24);
            $rollupSelect = $read->select()
                ->from($rollupTable, ['cnt' => new Maho\Db\Expr('SUM(`count`)')])
                ->where('event_type = ?', 'flush')
                ->where('hour >= ?', $since)
                ->where('hour < ?', $rawCutoff);

            $this->applyStoreFilter($rollupSelect, $storeCode);

            $count += (int) $read->fetchOne($rollupSelect);
        }

        return $count;
    }

    /**
     * Get the average TTFB in milliseconds.
     */
    public function getAverageTtfb(int $hours = 24, string $storeCode = ''): float
    {
        $read = $this->getStatsReadConnection();
        $table = $this->getStatsTable();
        $since = $this->getStatsSince($hours);

        $select = $read->select()
            ->from($table, ['avg_ttfb' => new Maho\Db\Expr('AVG(ttfb_ms)')])
            ->where('event_type IN (?)', ['hit', 'miss'])
            ->where('ttfb_ms IS NOT NULL')
            ->where('created_at >= ?', $since);

        $this->applyStoreFilter($select, $storeCode);

        $result = $read->fetchOne($select);

        return $result !== false && $result !== null ? round((float) $result, 1) : 0.0;
    }

    /**
     * Get the most frequently missed URLs.
     *
     * @return array<int, array{url_path: string, miss_count: int}>
     */
    public function getTopMissedUrls(int $limit = 10, int $hours = 24, string $storeCode = ''): array
    {
        $read = $this->getStatsReadConnection();
        $table = $this->getStatsTable();
        $since = $this->getStatsSince($hours);

        $select = $read->select()
            ->from($table, [
                'url_path'   => new Maho\Db\Expr("REPLACE(url_path, '//', '/')"),
                'miss_count' => new Maho\Db\Expr('COUNT(*)'),
            ])
            ->where('event_type = ?', 'miss')
            ->where('created_at >= ?', $since)
            ->where('url_path != ?', '')
            ->group(new Maho\Db\Expr("REPLACE(url_path, '//', '/')"))
            ->order('miss_count DESC')
            ->limit($limit);

        $this->applyStoreFilter($select, $storeCode);

        $rows = $read->fetchAll($select);

        return array_map(static function (array $row): array {
            return [
                'url_path'   => ltrim($row['url_path'], '/'),
                'miss_count' => (int) $row['miss_count'],
            ];
        }, $rows);
    }

    /**
     * Get recent flush events with their reasons.
     *
     * @return array<int, array{flush_reason: string, created_at: string, store_code: string}>
     */
    public function getRecentFlushes(int $limit = 20, int $hours = 24, string $storeCode = ''): array
    {
        $read = $this->getStatsReadConnection();
        $table = $this->getStatsTable();
        $since = $this->getStatsSince($hours);

        $select = $read->select()
            ->from($table, ['flush_reason', 'created_at', 'store_code'])
            ->where('event_type IN (?)', ['flush', 'purge'])
            ->where('created_at >= ?', $since)
            ->order('created_at DESC')
            ->limit($limit);

        $this->applyStoreFilter($select, $storeCode);

        return $read->fetchAll($select);
    }

    /**
     * Get hourly hit/miss counts for the given time window.
     *
     * For periods > 24h, reads from the rollup table for older data
     * and raw table for the last 24h.
     *
     * @return array<int, array{hour: string, hits: int, misses: int}>
     */
    public function getHourlyStats(int $hours = 24, string $storeCode = ''): array
    {
        $read = $this->getStatsReadConnection();
        $since = $this->getStatsSince($hours);

        $dataByHour = [];

        // For periods > 24h, get rollup data for the older portion
        if ($hours > 24 && $this->hasRollupTable()) {
            $rollupTable = $this->getStatsHourlyTable();
            $rawCutoff = $this->getStatsSince(24);

            $rollupSelect = $read->select()
                ->from($rollupTable, [
                    'hour'   => new Maho\Db\Expr("DATE_FORMAT(hour, '%Y-%m-%d %H:00')"),
                    'hits'   => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'hit' THEN `count` ELSE 0 END)"),
                    'misses' => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'miss' THEN `count` ELSE 0 END)"),
                ])
                ->where('hour >= ?', $since)
                ->where('hour < ?', $rawCutoff)
                ->group(new Maho\Db\Expr("DATE_FORMAT(hour, '%Y-%m-%d %H:00')"))
                ->order('hour ASC');

            $this->applyStoreFilter($rollupSelect, $storeCode);

            foreach ($read->fetchAll($rollupSelect) as $row) {
                $dataByHour[$row['hour']] = [
                    'hour'   => $row['hour'],
                    'hits'   => (int) $row['hits'],
                    'misses' => (int) $row['misses'],
                ];
            }
        }

        // Raw data
        $table = $this->getStatsTable();
        $rawSince = $hours > 24 ? $this->getStatsSince(24) : $since;

        $select = $read->select()
            ->from($table, [
                'hour'   => new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"),
                'hits'   => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'hit' THEN 1 ELSE 0 END)"),
                'misses' => new Maho\Db\Expr("SUM(CASE WHEN event_type = 'miss' THEN 1 ELSE 0 END)"),
            ])
            ->where('event_type IN (?)', ['hit', 'miss'])
            ->where('created_at >= ?', $rawSince)
            ->group(new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"))
            ->order('hour ASC');

        $this->applyStoreFilter($select, $storeCode);

        foreach ($read->fetchAll($select) as $row) {
            $h = $row['hour'];
            if (isset($dataByHour[$h])) {
                $dataByHour[$h]['hits'] += (int) $row['hits'];
                $dataByHour[$h]['misses'] += (int) $row['misses'];
            } else {
                $dataByHour[$h] = [
                    'hour'   => $h,
                    'hits'   => (int) $row['hits'],
                    'misses' => (int) $row['misses'],
                ];
            }
        }

        // Fill in all hour buckets (so the chart has no gaps)
        $result = [];
        $now = time();
        for ($i = $hours; $i >= 0; $i--) {
            $hourKey = date('Y-m-d H:00', $now - ($i * 3600));
            if (isset($dataByHour[$hourKey])) {
                $result[] = $dataByHour[$hourKey];
            } else {
                $result[] = [
                    'hour'   => $hourKey,
                    'hits'   => 0,
                    'misses' => 0,
                ];
            }
        }

        return $result;
    }

    /**
     * Get hourly TTFB data for timeline chart.
     *
     * Returns avg and p95 TTFB per hour bucket.
     *
     * @return array<int, array{hour: string, avg_ttfb: float, p95_ttfb: int}>
     */
    public function getHourlyTtfb(int $hours = 24, string $storeCode = ''): array
    {
        $read = $this->getStatsReadConnection();
        $since = $this->getStatsSince($hours);

        $dataByHour = [];

        // Rollup data for periods > 24h
        if ($hours > 24 && $this->hasRollupTable()) {
            $rollupTable = $this->getStatsHourlyTable();
            $rawCutoff = $this->getStatsSince(24);

            $rollupSelect = $read->select()
                ->from($rollupTable, [
                    'hour'      => new Maho\Db\Expr("DATE_FORMAT(hour, '%Y-%m-%d %H:00')"),
                    'avg_ttfb'  => new Maho\Db\Expr('AVG(avg_ttfb)'),
                    'p95_ttfb'  => new Maho\Db\Expr('MAX(p95_ttfb)'),
                ])
                ->where('hour >= ?', $since)
                ->where('hour < ?', $rawCutoff)
                ->where('avg_ttfb IS NOT NULL')
                ->group(new Maho\Db\Expr("DATE_FORMAT(hour, '%Y-%m-%d %H:00')"))
                ->order('hour ASC');

            $this->applyStoreFilter($rollupSelect, $storeCode);

            foreach ($read->fetchAll($rollupSelect) as $row) {
                $dataByHour[$row['hour']] = [
                    'hour'     => $row['hour'],
                    'avg_ttfb' => round((float) $row['avg_ttfb'], 1),
                    'p95_ttfb' => (int) ($row['p95_ttfb'] ?? 0),
                ];
            }
        }

        // Raw data
        $table = $this->getStatsTable();
        $rawSince = $hours > 24 ? $this->getStatsSince(24) : $since;

        $select = $read->select()
            ->from($table, [
                'hour'     => new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"),
                'avg_ttfb' => new Maho\Db\Expr('AVG(ttfb_ms)'),
                'p95_ttfb' => new Maho\Db\Expr('CAST(PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY ttfb_ms) AS UNSIGNED)'),
            ])
            ->where('event_type IN (?)', ['hit', 'miss'])
            ->where('ttfb_ms IS NOT NULL')
            ->where('ttfb_ms > 0')
            ->where('created_at >= ?', $rawSince)
            ->group(new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"))
            ->order('hour ASC');

        $this->applyStoreFilter($select, $storeCode);

        // MySQL < 8.0 doesn't have PERCENTILE_CONT, fall back to simpler approach
        try {
            $rows = $read->fetchAll($select);
        } catch (\Throwable) {
            // Fallback: use MAX as rough p95 proxy
            $select = $read->select()
                ->from($table, [
                    'hour'     => new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"),
                    'avg_ttfb' => new Maho\Db\Expr('AVG(ttfb_ms)'),
                    'p95_ttfb' => new Maho\Db\Expr('MAX(ttfb_ms)'),
                ])
                ->where('event_type IN (?)', ['hit', 'miss'])
                ->where('ttfb_ms IS NOT NULL')
                ->where('ttfb_ms > 0')
                ->where('created_at >= ?', $rawSince)
                ->group(new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"))
                ->order('hour ASC');

            $this->applyStoreFilter($select, $storeCode);

            $rows = $read->fetchAll($select);
        }

        foreach ($rows as $row) {
            $h = $row['hour'];
            // Raw data overwrites rollup for overlapping hours
            $dataByHour[$h] = [
                'hour'     => $h,
                'avg_ttfb' => round((float) $row['avg_ttfb'], 1),
                'p95_ttfb' => (int) ($row['p95_ttfb'] ?? 0),
            ];
        }

        // Fill hour buckets
        $result = [];
        $now = time();
        for ($i = $hours; $i >= 0; $i--) {
            $hourKey = date('Y-m-d H:00', $now - ($i * 3600));
            if (isset($dataByHour[$hourKey])) {
                $result[] = $dataByHour[$hourKey];
            } else {
                $result[] = [
                    'hour'     => $hourKey,
                    'avg_ttfb' => 0.0,
                    'p95_ttfb' => 0,
                ];
            }
        }

        return $result;
    }

    /**
     * Get all store codes that have stats data.
     *
     * @return string[]
     */
    public function getAvailableStoreCodes(): array
    {
        $read = $this->getStatsReadConnection();
        $table = $this->getStatsTable();

        $select = $read->select()
            ->from($table, ['store_code'])
            ->where('store_code != ?', '')
            ->group('store_code')
            ->order('store_code ASC');

        $codes = $read->fetchCol($select);

        // Also check rollup table
        if ($this->hasRollupTable()) {
            $rollupTable = $this->getStatsHourlyTable();
            $rollupSelect = $read->select()
                ->from($rollupTable, ['store_code'])
                ->where('store_code != ?', '')
                ->group('store_code')
                ->order('store_code ASC');

            $rollupCodes = $read->fetchCol($rollupSelect);
            $codes = array_unique(array_merge($codes, $rollupCodes));
            sort($codes);
        }

        return $codes;
    }

    /**
     * Aggregate raw stats older than 24h into hourly rollup table.
     *
     * Called by cron every hour. Deletes raw rows after aggregation.
     */
    public function rollupHourlyStats(): int
    {
        if (!$this->hasRollupTable()) {
            return 0;
        }

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $write = $resource->getConnection('core_write');
        $rawTable = $this->getStatsTable();
        $rollupTable = $this->getStatsHourlyTable();

        // Only roll up rows older than 24h — keeps the last 24h of raw data
        // intact so the default dashboard view (which reads raw only) isn't
        // wiped every hour.
        $cutoff = date('Y-m-d H:00:00', time() - 86400);

        // Aggregate: group by hour + store_code + event_type
        $select = $read->select()
            ->from($rawTable, [
                'hour'       => new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')"),
                'store_code' => 'store_code',
                'event_type' => 'event_type',
                'url_path'   => new Maho\Db\Expr("''"),
                'count'      => new Maho\Db\Expr('COUNT(*)'),
                'avg_ttfb'   => new Maho\Db\Expr('AVG(ttfb_ms)'),
                'min_ttfb'   => new Maho\Db\Expr('MIN(ttfb_ms)'),
                'max_ttfb'   => new Maho\Db\Expr('MAX(ttfb_ms)'),
                'p95_ttfb'   => new Maho\Db\Expr('MAX(ttfb_ms)'), // Approximation
            ])
            ->where('created_at < ?', $cutoff)
            ->group(['event_type', 'store_code', new Maho\Db\Expr("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')")]);

        $rows = $read->fetchAll($select);

        if (empty($rows)) {
            return 0;
        }

        // Upsert rollup rows — safe to re-run, merges counts if same bucket exists
        $write->insertOnDuplicate($rollupTable, $rows, [
            'count', 'avg_ttfb', 'min_ttfb', 'max_ttfb', 'p95_ttfb',
        ]);

        // Delete the raw rows we just aggregated
        $deleted = $write->delete($rawTable, ['created_at < ?' => $cutoff]);

        Mage::log(sprintf('FPC: rolled up %d hourly buckets, deleted %d raw rows', count($rows), $deleted), 6);

        return count($rows);
    }

    /**
     * Clean old rollup data beyond retention period.
     */
    public function cleanOldRollupStats(): void
    {
        if (!$this->hasRollupTable()) {
            return;
        }

        $days = (int) (Mage::getStoreConfig('system/fpc/stats_rollup_retention_days') ?: 90);

        try {
            $write = Mage::getSingleton('core/resource')->getConnection('core_write');
            $table = $this->getStatsHourlyTable();

            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $deleted = $write->delete($table, ['hour < ?' => $cutoff]);

            if ($deleted > 0) {
                Mage::log("FPC: cleaned {$deleted} rollup records older than {$days} days", 6);
            }
        } catch (\Throwable $e) {
            Mage::log('FPC rollup cleanup error: ' . $e->getMessage(), 3);
        }
    }

    // ── Stats Internal Helpers ──────────────────────────────────────

    private function getStatsReadConnection(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    private function getStatsTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('mageaustralia_fpc/stats');
    }

    private function getStatsHourlyTable(): ?string
    {
        try {
            return Mage::getSingleton('core/resource')->getTableName('mageaustralia_fpc/stats_hourly');
        } catch (\Throwable) {
            // Table not yet created (upgrade pending)
            return null;
        }
    }

    /**
     * Check if the hourly rollup table exists and is usable.
     */
    private function hasRollupTable(): bool
    {
        $table = $this->getStatsHourlyTable();
        if ($table === null) {
            return false;
        }
        try {
            return $this->getStatsReadConnection()->isTableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function getStatsSince(int $hours): string
    {
        return date('Y-m-d H:i:s', time() - ($hours * 3600));
    }

    /**
     * Apply store code filter to a select query if a store is specified.
     */
    private function applyStoreFilter(\Maho\Db\Select $select, string $storeCode, string $column = 'store_code'): void
    {
        if ($storeCode !== '') {
            $select->where("{$column} = ?", $storeCode);
        }
    }

    // ── Internal ────────────────────────────────────────────────────

    /**
     * Parse a textarea config value into trimmed, non-empty lines.
     *
     * Handles actual newlines, literal \n, and comma separators
     * (Lesti used comma+newline format in some config values).
     *
     * @return string[]
     */
    private function parseLines(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        // Normalise: replace literal \n and commas with actual newlines
        $raw = str_replace(['\\n', ','], "\n", $raw);
        $lines = preg_split('/\r?\n/', $raw);
        $result = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }
}
