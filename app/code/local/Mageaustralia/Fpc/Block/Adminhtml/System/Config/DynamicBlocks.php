<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * Admin config field: Dynamic Blocks table.
 *
 * Renders a dynamic table with add/remove rows for configuring FPC hole-punch blocks.
 * Each row defines a block that gets replaced with a placeholder in cached HTML
 * and loaded via AJAX on every page view.
 *
 * Columns:
 *   - Name: unique identifier for the block (e.g. "cart_count")
 *   - Block Type: Maho block alias or helper call (e.g. "checkout/cart_sidebar" or "helper:checkout/cart:getSummaryCount")
 *   - Template: optional phtml template override (e.g. "checkout/cart/minicart.phtml")
 *   - Selector: CSS selector to find in cached HTML (e.g. ".skip-cart .count", "#minicart")
 *   - Mode: "html" (replace innerHTML) or "text" (replace textContent)
 */
class Mageaustralia_Fpc_Block_Adminhtml_System_Config_DynamicBlocks
    extends Mage_Adminhtml_Block_System_Config_Form_Field_Array_Abstract
{
    private ?Mage_Adminhtml_Block_Html_Select $modeRenderer = null;

    #[\Override]
    protected function _prepareToRender(): void
    {
        // Column widths are tight on purpose: the admin System Config form
        // wraps each field in a fixed-width column, so if the sum of these
        // plus the Delete button exceeds the available space the table
        // overflows horizontally. Keep the total under ~650px of content.
        $this->addColumn('name', [
            'label' => Mage::helper('mageaustralia_fpc')->__('Name'),
            'style' => 'width:110px',
        ]);
        $this->addColumn('block_type', [
            'label' => Mage::helper('mageaustralia_fpc')->__('Block Type'),
            'style' => 'width:160px',
            'comment' => 'e.g. checkout/cart_sidebar or helper:checkout/cart:getSummaryCount',
        ]);
        $this->addColumn('template', [
            'label' => Mage::helper('mageaustralia_fpc')->__('Template'),
            'style' => 'width:110px',
        ]);
        $this->addColumn('selector', [
            'label' => Mage::helper('mageaustralia_fpc')->__('CSS Selector'),
            'style' => 'width:150px',
        ]);
        $this->addColumn('mode', [
            'label' => Mage::helper('mageaustralia_fpc')->__('Mode'),
            'style' => 'width:80px',
            'renderer' => $this->getModeRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = Mage::helper('mageaustralia_fpc')->__('Add Block');
    }

    /**
     * Get the mode dropdown renderer.
     */
    private function getModeRenderer(): Mage_Adminhtml_Block_Html_Select
    {
        if ($this->modeRenderer === null) {
            $this->modeRenderer = $this->getLayout()
                ->createBlock('adminhtml/html_select')
                ->setIsRenderToJsTemplate(true)
                ->addOption('html', 'HTML')
                ->addOption('text', 'Text');
        }
        return $this->modeRenderer;
    }

    #[\Override]
    protected function _prepareArrayRow(Maho\DataObject $row): void
    {
        $options = [];
        $mode = $row->getData('mode');
        if ($mode) {
            $key = 'option_' . $this->getModeRenderer()->calcOptionHash($mode);
            $options[$key] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }

    #[\Override]
    protected function _toHtml(): string
    {
        $html = parent::_toHtml();
        $id = $this->getHtmlId() ?: '_' . uniqid();
        // Column order: 1=Name, 2=Block Type, 3=Template, 4=CSS Selector, 5=Mode, 6+=Delete/Add
        return <<<CSS
<style>
#grid{$id} table.border { table-layout: fixed; width: 100%; }
#grid{$id} table.border th:nth-child(1),
#grid{$id} table.border td:nth-child(1) { width: 14%; }
#grid{$id} table.border th:nth-child(2),
#grid{$id} table.border td:nth-child(2) { width: 24%; }
#grid{$id} table.border th:nth-child(3),
#grid{$id} table.border td:nth-child(3) { width: 14%; }
#grid{$id} table.border th:nth-child(4),
#grid{$id} table.border td:nth-child(4) { width: 22%; }
#grid{$id} table.border th:nth-child(5),
#grid{$id} table.border td:nth-child(5) { width: 10%; }
#grid{$id} table.border td input,
#grid{$id} table.border td select { width: 100%; box-sizing: border-box; }
</style>
CSS
            . $html;
    }
}
