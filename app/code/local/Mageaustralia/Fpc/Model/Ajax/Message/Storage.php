<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * AJAX Message Storage — extracts and clears session messages for AJAX responses.
 *
 * Collects messages from all relevant session types (core, catalog, checkout, customer)
 * and clears them from the session so they're not shown again on the next page load.
 */
class Mageaustralia_Fpc_Model_Ajax_Message_Storage
{
    /** Session types to collect messages from */
    private const SESSION_TYPES = [
        'core/session',
        'catalog/session',
        'checkout/session',
        'customer/session',
    ];

    /**
     * Extract all messages from session storage and clear them.
     *
     * Returns a flat array of message strings with type prefixes for
     * client-side rendering (e.g. "success:Product added to cart").
     *
     * @return string[]
     */
    public function extractAll(): array
    {
        $messages = [];

        foreach (self::SESSION_TYPES as $sessionType) {
            try {
                /** @var Mage_Core_Model_Session_Abstract $session */
                $session = Mage::getSingleton($sessionType);
                $sessionMessages = $session->getMessages(true); // true = clear

                if (!$sessionMessages) {
                    continue;
                }

                foreach ($sessionMessages->getItems() as $message) {
                    $type = $message->getType(); // success, error, notice, warning
                    $text = $message->getText();

                    if ($text !== '' && $text !== null) {
                        $messages[] = $type . ':' . $text;
                    }
                }
            } catch (\Throwable) {
                // Session might not be initialized in some contexts
                continue;
            }
        }

        return $messages;
    }

    /**
     * Extract messages from a specific session type only.
     *
     * @return string[]
     */
    public function extractFrom(string $sessionType): array
    {
        $messages = [];

        try {
            /** @var Mage_Core_Model_Session_Abstract $session */
            $session = Mage::getSingleton($sessionType);
            $sessionMessages = $session->getMessages(true);

            if (!$sessionMessages) {
                return $messages;
            }

            foreach ($sessionMessages->getItems() as $message) {
                $type = $message->getType();
                $text = $message->getText();

                if ($text !== '' && $text !== null) {
                    $messages[] = $type . ':' . $text;
                }
            }
        } catch (\Throwable) {
            // Silent fail
        }

        return $messages;
    }
}
