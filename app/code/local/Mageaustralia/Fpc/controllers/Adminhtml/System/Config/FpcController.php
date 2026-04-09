<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Admin controller for FPC management actions.
 *
 * Handles the "Flush FPC" button from System > Config > Full Page Cache.
 */
class Mageaustralia_Fpc_Adminhtml_System_Config_FpcController extends Mage_Adminhtml_Controller_Action
{
    #[\Override]
    public function preDispatch(): void
    {
        $this->_setForcedFormKeyActions(['flush']);
        parent::preDispatch();
    }

    /**
     * Flush the full page cache and redirect back to config.
     */
    public function flushAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('adminhtml/system_config/edit', ['section' => 'mageaustralia_fpc']);
            return;
        }

        /** @var Mageaustralia_Fpc_Model_Cache $cache */
        $cache = Mage::getModel('mageaustralia_fpc/cache');
        $cache->flush();

        Mage::getSingleton('adminhtml/session')->addSuccess(
            Mage::helper('mageaustralia_fpc')->__('Full page cache has been flushed.'),
        );

        $this->_redirect('adminhtml/system_config/edit', ['section' => 'mageaustralia_fpc']);
    }

    /**
     * ACL check — require FPC config access.
     */
    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')
            ->isAllowed('system/config/mageaustralia_fpc');
    }
}
