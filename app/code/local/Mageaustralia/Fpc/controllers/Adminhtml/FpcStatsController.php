<?php

declare(strict_types=1);

class Mageaustralia_Fpc_Adminhtml_FpcStatsController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'system/config/mageaustralia_fpc';

    public function indexAction(): void
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        return true;
    }
}
