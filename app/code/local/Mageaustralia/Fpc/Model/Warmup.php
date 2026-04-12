<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Cache Warmer — crawls sitemap URLs after an FPC flush to pre-populate the cache.
 *
 * Triggered by a flag file (var/fpc/.warmup_pending) set during flush.
 * Cron picks up the flag and crawls URLs from the configured sitemap.
 * Progress is tracked via a state file (var/fpc/.warmup_state) so work
 * can resume across cron runs.
 */
class Mageaustralia_Fpc_Model_Warmup
{
    private const FLAG_FILE = '.warmup_pending';
    private const STATE_FILE = '.warmup_state';
    private const LOG_FILE = 'fpc_warmup.log';

    /**
     * Check if a warmup is pending.
     */
    public function isPending(): bool
    {
        return is_file($this->getFlagPath());
    }

    /**
     * Schedule a warmup if no warmup is already pending.
     *
     * Used by the scheduled warmup cron — only triggers if
     * no warmup is already in progress.
     */
    public function scheduleIfStale(): void
    {
        if ($this->isPending()) {
            return;
        }

        // Check if any cache files are older than half the cache lifetime
        $helper = Mage::helper('mageaustralia_fpc');
        $fpcDir = $helper->getFpcDir();
        if (!is_dir($fpcDir)) {
            $this->schedule();
            return;
        }

        $lifetime = $helper->getCacheLifetime();
        $threshold = time() - (int) ($lifetime * 0.5);

        // Sample a few files — if any are stale, schedule warmup
        $count = 0;
        $stale = 0;
        $items = new \DirectoryIterator($fpcDir);
        foreach ($items as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }
            // Check first HTML file in each store directory
            $storeDir = new \DirectoryIterator($item->getPathname());
            foreach ($storeDir as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.html')) {
                    $count++;
                    if ($file->getMTime() < $threshold) {
                        $stale++;
                    }
                    break;
                }
            }
            if ($count >= 5) {
                break;
            }
        }

        // If more than half the sampled files are stale, schedule warmup
        if ($count === 0 || ($stale / $count) >= 0.5) {
            $this->schedule();
        }
    }

    /**
     * Request a cache warmup (called after flush).
     */
    public function schedule(): void
    {
        $dir = $this->getFpcDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($this->getFlagPath(), (string) time());

        // Clear any previous state so we start fresh
        $statePath = $this->getStatePath();
        if (is_file($statePath)) {
            @unlink($statePath);
        }

        $this->log('Warmup scheduled');
    }

    /**
     * Run a batch of warmup requests. Called by cron every minute.
     * Returns the number of URLs warmed in this batch.
     */
    public function runBatch(): int
    {
        if (!$this->isPending()) {
            return 0;
        }

        $urls = $this->getUrlsToWarm();
        if (empty($urls)) {
            $this->complete('No URLs found in sitemap');
            return 0;
        }

        $state = $this->loadState();
        $offset = $state['offset'] ?? 0;
        $maxPerRun = $this->getMaxUrlsPerRun();
        $delayMs = $this->getDelayMs();

        $batch = array_slice($urls, $offset, $maxPerRun);
        if (empty($batch)) {
            $this->complete(sprintf('Warmup complete — %d URLs processed', $offset));
            return 0;
        }

        $warmed = 0;
        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
        $basicAuth = $this->getBasicAuth();

        foreach ($batch as $url) {
            $success = $this->warmUrl($url, $basicAuth);
            if ($success) {
                $warmed++;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $newOffset = $offset + count($batch);
        $total = count($urls);

        if ($newOffset >= $total) {
            $this->complete(sprintf('Warmup complete — %d/%d URLs cached', $newOffset, $total));
        } else {
            $this->saveState(['offset' => $newOffset, 'total' => $total]);
            $this->log(sprintf('Warmup progress: %d/%d (batch: %d warmed)', $newOffset, $total, $warmed));
        }

        return $warmed;
    }

    /**
     * Parse sitemap XML and return all URLs.
     * Handles both regular sitemaps and sitemap index files.
     *
     * @return string[]
     */
    private function getUrlsToWarm(): array
    {
        $sitemapPath = $this->getSitemapPath();
        if ($sitemapPath === null || !is_file($sitemapPath)) {
            $this->log('Sitemap file not found: ' . ($sitemapPath ?? 'no sitemap configured'));
            return [];
        }

        $urls = $this->parseSitemapFile($sitemapPath);
        $this->log(sprintf('Parsed %d URLs from sitemap', count($urls)));
        return $urls;
    }

    /**
     * Parse a single sitemap file. If it's a sitemap index, recursively
     * parse each child sitemap.
     *
     * @return string[]
     */
    private function parseSitemapFile(string $path): array
    {
        $xml = @simplexml_load_file($path);
        if ($xml === false) {
            $this->log('Failed to parse sitemap XML: ' . $path);
            return [];
        }

        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? '';

        // Check if this is a sitemap index (contains <sitemap> elements)
        if ($ns) {
            $xml->registerXPathNamespace('sm', $ns);
            $sitemaps = $xml->xpath('//sm:sitemap/sm:loc');
        } else {
            $sitemaps = $xml->xpath('//sitemap/loc');
        }

        if ($sitemaps && count($sitemaps) > 0) {
            // This is a sitemap index — parse each child sitemap
            $urls = [];
            $baseDir = dirname($path);
            foreach ($sitemaps as $loc) {
                $childUrl = trim((string) $loc);
                if ($childUrl === '') {
                    continue;
                }

                // Convert URL to local file path
                $childFile = $baseDir . '/' . basename($childUrl);
                if (is_file($childFile)) {
                    $childUrls = $this->parseSitemapFile($childFile);
                    $urls = array_merge($urls, $childUrls);
                } else {
                    $this->log('Child sitemap not found: ' . $childFile);
                }
            }
            return $urls;
        }

        // Regular sitemap — extract <url><loc> entries
        if ($ns) {
            $locs = $xml->xpath('//sm:url/sm:loc');
        } else {
            $locs = $xml->xpath('//url/loc');
        }

        if ($locs === false) {
            return [];
        }

        $urls = [];
        foreach ($locs as $loc) {
            $url = trim((string) $loc);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Warm a single URL via HTTP GET request using Symfony HttpClient.
     */
    private function warmUrl(string $url, ?string $basicAuth): bool
    {
        try {
            $options = [
                'timeout' => 30,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'MahoFPC-Warmup/1.0',
                    'Accept-Encoding' => 'gzip',
                ],
            ];

            if ($basicAuth !== null) {
                [$user, $pass] = explode(':', $basicAuth, 2) + [1 => ''];
                $options['auth_basic'] = [$user, $pass];
            }

            $client = \Symfony\Component\HttpClient\HttpClient::create($options);
            $response = $client->request('GET', $url);
            $httpCode = $response->getStatusCode();

            if ($httpCode !== 200) {
                $this->log(sprintf('FAIL %d %s', $httpCode, $url));
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->log(sprintf('FAIL %s — %s', $url, $e->getMessage()));
            return false;
        }
    }

    /**
     * Get the filesystem path to the sitemap XML file.
     * Reads from the Maho sitemap model configuration.
     */
    private function getSitemapPath(): ?string
    {
        // Check if a custom path is configured in FPC settings
        $customPath = trim((string) Mage::getStoreConfig('system/fpc/warmup_sitemap_path'));
        if ($customPath !== '') {
            if (str_starts_with($customPath, '/')) {
                return $customPath;
            }
            return BP . DS . 'public' . DS . ltrim($customPath, '/');
        }

        // Fall back to first sitemap in the sitemap model for the default store
        $sitemap = Mage::getModel('sitemap/sitemap')->getCollection()
            ->addFieldToFilter('store_id', Mage::app()->getStore()->getId())
            ->getFirstItem();

        if ($sitemap && $sitemap->getId()) {
            $path = rtrim($sitemap->getSitemapPath(), '/') . '/' . $sitemap->getSitemapFilename();
            // Sitemap paths are relative to Maho root
            return BP . DS . 'public' . DS . ltrim($path, '/');
        }

        return null;
    }

    /**
     * Get basic auth credentials if configured (for staging/dev sites).
     */
    private function getBasicAuth(): ?string
    {
        $auth = trim((string) Mage::getStoreConfig('system/fpc/warmup_basic_auth'));
        return $auth !== '' ? $auth : null;
    }

    private function getMaxUrlsPerRun(): int
    {
        $max = (int) Mage::getStoreConfig('system/fpc/warmup_batch_size');
        return $max > 0 ? $max : 200;
    }

    private function getDelayMs(): int
    {
        $delay = (int) Mage::getStoreConfig('system/fpc/warmup_delay_ms');
        return max(0, $delay);
    }

    // ── State Management ────────────────────────────────────────────

    private function loadState(): array
    {
        $path = $this->getStatePath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveState(array $state): void
    {
        file_put_contents($this->getStatePath(), json_encode($state), LOCK_EX);
    }

    private function complete(string $message): void
    {
        @unlink($this->getFlagPath());
        @unlink($this->getStatePath());
        $this->log($message);
    }

    // ── Paths ───────────────────────────────────────────────────────

    private function getFpcDir(): string
    {
        return Mage::helper('mageaustralia_fpc')->getFpcDir();
    }

    private function getFlagPath(): string
    {
        return $this->getFpcDir() . DS . self::FLAG_FILE;
    }

    private function getStatePath(): string
    {
        return $this->getFpcDir() . DS . self::STATE_FILE;
    }

    private function log(string $message): void
    {
        Mage::log('FPC Warmup: ' . $message, 6, self::LOG_FILE);
    }
}
