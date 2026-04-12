<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Admin config button to flush the full page cache.
 *
 * Renders a "Flush FPC" button in System > Config > Full Page Cache.
 * Posts to the admin controller which clears var/fpc/ and notifies the purge adapter.
 */
class Mageaustralia_Fpc_Block_Adminhtml_System_Config_FlushButton extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * Render the flush button instead of the default input field.
     */
    #[\Override]
    protected function _getElementHtml(Maho\Data\Form\Element\AbstractElement $element): string
    {
        $url = Mage::helper('adminhtml')->getUrl('adminhtml/system_config_fpc/flush');
        $formKey = Mage::getSingleton('core/session')->getFormKey();

        $js = "mahoFetch('" . $this->escapeUrl($url) . "', {method:'POST', loaderArea:false})"
            . ".then(function(){alert('FPC flushed successfully!');location.reload();})"
            . ".catch(function(e){alert('Error: '+e.message);});";

        return $this->getLayout()
            ->createBlock('adminhtml/widget_button')
            ->setData([
                'id'      => 'fpc_flush_button',
                'label'   => $this->__('Flush Full Page Cache'),
                'onclick' => $js,
                'class'   => 'scalable',
            ])
            ->toHtml();

    }
}
