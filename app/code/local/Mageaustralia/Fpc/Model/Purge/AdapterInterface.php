<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Purge adapter interface for CDN/reverse proxy cache invalidation.
 *
 * Implementations handle external cache layers (Cloudflare, Varnish, etc.).
 * The Null adapter is the default and relies on TTL expiry only.
 */
interface Mageaustralia_Fpc_Model_Purge_AdapterInterface
{
    /**
     * Purge specific URLs from the external cache.
     *
     * @param string[] $urls Relative URL paths to purge
     */
    public function purgeUrls(array $urls): void;

    /**
     * Purge the entire external cache.
     */
    public function purgeAll(): void;
}
