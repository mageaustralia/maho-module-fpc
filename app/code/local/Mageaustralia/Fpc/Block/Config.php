<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * FPC Config block — outputs a small inline <script> with admin-configured selectors.
 *
 * This allows any theme to work with FPC without template changes —
 * just configure the CSS selectors in System > Config > Full Page Cache > Turbo Drive.
 *
 * Output: window.FPC_CONFIG = { cartQtySelector: '...', minicartTrigger: '...', ... }
 */
class Mageaustralia_Fpc_Block_Config extends Mage_Core_Block_Abstract
{
    #[\Override]
    protected function _toHtml(): string
    {
        /** @var Mageaustralia_Fpc_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_fpc');

        if (!$helper->isEnabled()) {
            return '';
        }

        $config = [
            'cartQtySelector'      => trim((string) Mage::getStoreConfig('system/fpc/cart_qty_selector')),
            'minicartTrigger'      => trim((string) Mage::getStoreConfig('system/fpc/minicart_trigger_selector')),
            'minicartContent'      => trim((string) Mage::getStoreConfig('system/fpc/minicart_content_selector')),
            'resetRemoveSelectors' => trim((string) Mage::getStoreConfig('system/fpc/reset_remove_selectors')),
            'resetCloseSelectors'  => trim((string) Mage::getStoreConfig('system/fpc/reset_close_selectors')),
            'resetBodyClasses'     => trim((string) Mage::getStoreConfig('system/fpc/reset_body_classes')),
            'resetClearInputs'     => trim((string) Mage::getStoreConfig('system/fpc/reset_clear_inputs')),
            'resetCloneSelectors'  => trim((string) Mage::getStoreConfig('system/fpc/reset_clone_selectors')),
            'resetDispatchEscape'  => Mage::getStoreConfigFlag('system/fpc/reset_dispatch_escape'),
        ];

        // Remove empty/false values so JS can check truthiness
        $config = array_filter($config, static fn(mixed $v): bool => $v !== '' && $v !== false);

        if ($config === []) {
            return '';
        }

        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return '<script data-turbo-eval="false">window.FPC_CONFIG=' . $json . ';</script>' . "\n";
    }
}
