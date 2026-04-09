<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * AJAX Core — renders layout blocks by name and returns structured responses.
 *
 * Absorbed from VF_EasyAjax. Any controller action can be intercepted when
 * the isEasyAjax parameter is present, returning JSON instead of HTML.
 */
class Mageaustralia_Fpc_Model_Ajax_Core
{
    /**
     * Load and render specific layout blocks by name.
     *
     * Creates a fresh layout instance, applies the current request's handles,
     * generates blocks, and returns rendered HTML keyed by block name.
     *
     * @param string[] $blockNames
     * @return array<string, string> Block name => rendered HTML
     */
    public function loadContent(array $blockNames): array
    {
        $result = [];

        if ($blockNames === []) {
            return $result;
        }

        $layout = Mage::app()->getLayout();

        foreach ($blockNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $block = $layout->getBlock($name);
            if ($block) {
                $result[$name] = $block->toHtml();
            }
        }

        return $result;
    }

    /**
     * Build the standard AJAX response array.
     *
     * @param array<string, string> $blocks   Rendered block HTML keyed by name
     * @param string[]              $messages  Flash messages to display
     * @param array<string, mixed>  $extra     Additional data (cart qty, redirect URL, etc.)
     * @return array<string, mixed>
     */
    public function buildResponse(array $blocks = [], array $messages = [], array $extra = []): array
    {
        $response = [
            'success'  => true,
            'messages' => $messages,
            'blocks'   => $blocks,
        ];

        return array_merge($response, $extra);
    }

    /**
     * Get the current cart item count.
     */
    public function getCartQty(): int
    {
        /** @var Mage_Checkout_Model_Cart $cart */
        $cart = Mage::getSingleton('checkout/cart');
        return (int) $cart->getSummaryQty();
    }

    /**
     * Get the current form key for CSRF protection.
     */
    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
