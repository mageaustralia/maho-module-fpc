<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

class Mageaustralia_Fpc_Adminhtml_FpcStatsController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'mageaustralia/fpc_stats';

    public function indexAction(): void
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')
            ->isAllowed(self::ADMIN_RESOURCE);
    }
}
