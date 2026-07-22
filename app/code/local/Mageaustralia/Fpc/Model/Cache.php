<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Cache model — handles reading, writing, and purging static HTML cache files.
 *
 * Layer 1: var/fpc/{store}/{hash}.html + .html.gz
 * Layer 2: CDN via Cache-Control headers (managed by Observer, not here)
 */
class Mageaustralia_Fpc_Model_Cache
{
    private Mageaustralia_Fpc_Helper_Data $helper;
    private Mageaustralia_Fpc_Model_Purge_AdapterInterface $purgeAdapter;

    public function __construct()
    {
        $this->helper = Mage::helper('mageaustralia_fpc');
        $this->purgeAdapter = $this->buildPurgeAdapter();
    }

    // ── Read ────────────────────────────────────────────────────────

    /**
     * Check if a cached file exists and is not expired.
     */
    public function exists(string $cacheKey): bool
    {
        $file = $this->helper->getCacheFilePath($cacheKey);
        $fileGz = $this->helper->getCacheFilePathGz($cacheKey);

        // Check plain or gzipped file
        $checkFile = is_file($file) ? $file : (is_file($fileGz) ? $fileGz : null);
        if ($checkFile === null) {
            return false;
        }

        // Check expiry
        $mtime = filemtime($checkFile);
        $lifetime = $this->helper->getCacheLifetime();

        if ($mtime !== false && (time() - $mtime) > $lifetime) {
            $this->remove($cacheKey);
            return false;
        }

        return true;
    }

    /**
     * Read cached HTML from disk.
     */
    public function load(string $cacheKey): ?string
    {
        $file = $this->helper->getCacheFilePath($cacheKey);

        // Try plain HTML first
        if (is_file($file)) {
            $content = file_get_contents($file);
            return $content !== false ? $content : null;
        }

        // Gzip-only mode: decompress .gz file
        $fileGz = $this->helper->getCacheFilePathGz($cacheKey);
        if (is_file($fileGz)) {
            $gz = file_get_contents($fileGz);
            if ($gz !== false) {
                $content = gzdecode($gz);
                return $content !== false ? $content : null;
            }
        }

        return null;
    }

    /**
     * Get the age of a cache entry in seconds.
     */
    public function getAge(string $cacheKey): int
    {
        $file = $this->helper->getCacheFilePath($cacheKey);

        if (!is_file($file)) {
            return 0;
        }

        $mtime = filemtime($file);
        return $mtime !== false ? (time() - $mtime) : 0;
    }

    // ── Write ───────────────────────────────────────────────────────

    /**
     * Write HTML to cache as both plain and gzipped files.
     */
    public function save(string $cacheKey, string $html): bool
    {
        $file = $this->helper->getCacheFilePath($cacheKey);
        $fileGz = $this->helper->getCacheFilePathGz($cacheKey);

        $dir = dirname($file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                Mage::log("FPC: failed to create directory {$dir}", 3);
                return false;
            }
        }

        $gzipOnly = $this->helper->gzipOnly();

        // Write gzipped version (always — used by nginx gzip_static and as primary in gzip-only mode)
        $gz = gzencode($html, 6);
        if ($gz !== false) {
            file_put_contents($fileGz, $gz, LOCK_EX);
        }

        // Write plain HTML (skip if gzip-only mode — saves ~80% disk space)
        if (!$gzipOnly) {
            $written = file_put_contents($file, $html, LOCK_EX);
            if ($written === false) {
                Mage::log("FPC: failed to write {$file}", 3);
                return false;
            }
        }

        return true;
    }

    // ── Purge ───────────────────────────────────────────────────────

    /**
     * Remove a specific cache entry.
     */
    public function remove(string $cacheKey): void
    {
        $file = $this->helper->getCacheFilePath($cacheKey);
        $fileGz = $this->helper->getCacheFilePathGz($cacheKey);

        if (is_file($file)) {
            @unlink($file);
        }
        if (is_file($fileGz)) {
            @unlink($fileGz);
        }
    }

    /**
     * Purge cache entries for specific URL paths.
     *
     * With path-based cache keys, purging is straightforward:
     * delete the exact file + glob for parameterized/group variants.
     *
     * @param string[] $paths Relative URL paths (e.g. "shoes.html")
     */
    /**
     * Absolute URLs to re-warm for the given paths, one per active store.
     *
     * Deliberately independent of whether a store currently has a cache directory. The
     * first version built this inside the purge loop, after the is_dir() guard, so nothing
     * was ever queued when the cache was cold - which is precisely when warming matters
     * most, and is the state immediately after a full flush.
     *
     * Uses the path as given, before the .html cache-filename normalisation: the cache file
     * is an implementation detail, the warmer needs the address a browser would request.
     *
     * @param string[] $paths
     * @return string[]
     */
    private function collectWarmUrls(array $paths): array
    {
        $urls = [];

        // Empty (the default) means every active store, matching the purge - which clears
        // all stores' caches. A selection narrows it, for views that should not be crawled.
        $selected = trim((string) Mage::getStoreConfig('system/fpc/warmup_requeue_stores'));
        $allowed = $selected === '' ? null : array_filter(explode(',', $selected), static fn(string $id): bool => $id !== '');

        foreach (Mage::app()->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }
            if ($allowed !== null && !in_array((string) $store->getId(), $allowed, true)) {
                continue;
            }
            $storeBaseUrl = rtrim($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB), '/');
            foreach ($paths as $rawPath) {
                $urls[] = $storeBaseUrl . '/' . trim((string) $rawPath, '/');
            }
        }

        return $urls;
    }

    public function purgeByPaths(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $fpcDir = $this->helper->getFpcDir();
        $requeue = Mage::getStoreConfigFlag('system/fpc/warmup_requeue')
            && Mage::getStoreConfigFlag('system/fpc/warmup_enabled');
        $toWarm = $requeue ? $this->collectWarmUrls($paths) : [];

        foreach (Mage::app()->getStores() as $store) {
            $storeCode = $store->getCode();
            $storeDir = $fpcDir . DS . $storeCode;

            if (!is_dir($storeDir)) {
                continue;
            }

            foreach ($paths as $path) {
                $rawPath = trim($path, '/');

                // Build candidate (base, pattern) pairs covering both
                // directory-style and file-style cache key formats. Which
                // form was written depends on whether the invalidating URL
                // ended in a trailing slash at save time — and we may not
                // know from just the path string, so we purge BOTH
                // candidates for any path without an extension.
                $candidates = [];

                if ($rawPath === '') {
                    // Homepage
                    $candidates[] = ['base' => 'index', 'ext' => 'html'];
                } else {
                    $ext = pathinfo($rawPath, PATHINFO_EXTENSION);
                    if ($ext !== '') {
                        // File-style URL (e.g. /foo.html) — one candidate only.
                        $base = substr($rawPath, 0, -(strlen($ext) + 1));
                        $candidates[] = ['base' => $base, 'ext' => $ext];
                    } else {
                        // Unknown style — purge both the directory-style form
                        // ({path}/index.html, current) and the legacy
                        // file-style form ({path}.html) so in-progress
                        // redeploys don't leave stale files behind.
                        $candidates[] = ['base' => $rawPath . '/index', 'ext' => 'html'];
                        $candidates[] = ['base' => $rawPath,            'ext' => 'html'];
                    }
                }

                foreach ($candidates as $c) {
                    $base = $c['base'];
                    $ext  = $c['ext'];

                    // Delete exact file (base URL, guest)
                    $this->remove($storeCode . '/' . $base . '.' . $ext);

                    // Glob for parameterized and customer group variants:
                    // base__*.ext (e.g. accessories/index__p-2.html)
                    $pattern = $storeDir . DS . $base . '__*.' . $ext;
                    foreach (glob($pattern) ?: [] as $file) {
                        @unlink($file);
                        if (is_file($file . '.gz')) {
                            @unlink($file . '.gz');
                        }
                    }
                }
            }
        }

        $this->purgeAdapter->purgeUrls($paths);

        if ($toWarm !== []) {
            Mage::getSingleton('mageaustralia_fpc/warmup')->enqueue($toWarm);
        }
    }

    /**
     * Flush the entire FPC directory and notify CDN.
     */
    public function flush(): void
    {
        $fpcDir = $this->helper->getFpcDir();

        if (is_dir($fpcDir)) {
            $this->recursiveDelete($fpcDir);
        }

        $this->purgeAdapter->purgeAll();

        Mage::log('FPC: flushed all cache entries', 6);
    }

    // ── Internal ────────────────────────────────────────────────────

    /**
     * Recursively delete all files in a directory (preserves the directory itself).
     */
    private function recursiveDelete(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    /**
     * Instantiate the configured purge adapter.
     */
    private function buildPurgeAdapter(): Mageaustralia_Fpc_Model_Purge_AdapterInterface
    {
        // Future: read adapter class from config for Cloudflare, Varnish, etc.
        return new Mageaustralia_Fpc_Model_Purge_Null();
    }
}
