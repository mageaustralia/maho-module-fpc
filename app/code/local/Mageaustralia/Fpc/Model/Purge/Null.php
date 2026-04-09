<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Null purge adapter — does nothing.
 *
 * Used as the default adapter when no external cache layer is configured.
 * CDN cache relies on TTL expiry (Cache-Control headers) instead of active purging.
 */
class Mageaustralia_Fpc_Model_Purge_Null implements Mageaustralia_Fpc_Model_Purge_AdapterInterface
{
    #[\Override]
    public function purgeUrls(array $urls): void
    {
        // No-op: rely on TTL expiry
    }

    #[\Override]
    public function purgeAll(): void
    {
        // No-op: rely on TTL expiry
    }
}
