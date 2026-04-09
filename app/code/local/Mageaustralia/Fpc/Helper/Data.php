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
     * Parse lazy block names from config.
     *
     * @return string[]
     */
    public function getLazyBlocks(): array
    {
        $raw = (string) Mage::getStoreConfig('system/fpc/lazy_blocks');
        return $this->parseLines($raw);
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
     *   picklewarehouse/pickleball-paddles.html           (base URL)
     *   picklewarehouse/pickleball-paddles__p-2.html      (page 2)
     *   picklewarehouse/pickleball-paddles__g1.html       (customer group 1)
     *   picklewarehouse/index.html                        (homepage)
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
        return $this->getFpcDir() . DS . $cacheKey . '.gz';
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

        // Category URLs
        $categoryIds = $product->getCategoryIds();
        foreach ($categoryIds as $categoryId) {
            $category = Mage::getModel('catalog/category')->load($categoryId);
            if ($category->getId()) {
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
