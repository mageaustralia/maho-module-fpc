<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * FPC Statistics Dashboard block.
 *
 * Prepares all data needed by the dashboard template: stat cards,
 * hourly chart data, top missed URLs, and recent flushes.
 */
class Mageaustralia_Fpc_Block_Adminhtml_Stats_Dashboard extends Mage_Adminhtml_Block_Template
{
    private ?Mageaustralia_Fpc_Helper_Data $fpcHelper = null;

    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('mageaustralia/fpc/stats/dashboard.phtml');
    }

    /**
     * Get the selected time period in hours (default 24).
     */
    public function getPeriodHours(): int
    {
        $period = $this->getRequest()->getParam('period', '24');
        $allowed = [1, 6, 24, 168]; // 1h, 6h, 24h, 7d
        $hours = (int) $period;
        return in_array($hours, $allowed, true) ? $hours : 24;
    }

    /**
     * Get a human-readable label for the current time period.
     */
    public function getPeriodLabel(): string
    {
        return match ($this->getPeriodHours()) {
            1   => 'Last 1 Hour',
            6   => 'Last 6 Hours',
            24  => 'Last 24 Hours',
            168 => 'Last 7 Days',
            default => 'Last 24 Hours',
        };
    }

    /**
     * Get all available time period options.
     *
     * @return array<int, array{hours: int, label: string, active: bool}>
     */
    public function getPeriodOptions(): array
    {
        $current = $this->getPeriodHours();
        return [
            ['hours' => 1,   'label' => '1h',  'active' => $current === 1],
            ['hours' => 6,   'label' => '6h',  'active' => $current === 6],
            ['hours' => 24,  'label' => '24h', 'active' => $current === 24],
            ['hours' => 168, 'label' => '7d',  'active' => $current === 168],
        ];
    }

    /**
     * Get the URL for a time period link.
     */
    public function getPeriodUrl(int $hours): string
    {
        return $this->getUrl('*/*/index', ['period' => $hours]);
    }

    /**
     * @return array{hits: int, misses: int, total: int, rate: float}
     */
    public function getHitRate(): array
    {
        return $this->getFpcHelper()->getHitRate($this->getPeriodHours());
    }

    public function getFlushCount(): int
    {
        return $this->getFpcHelper()->getFlushCount($this->getPeriodHours());
    }

    public function getAverageTtfb(): float
    {
        return $this->getFpcHelper()->getAverageTtfb($this->getPeriodHours());
    }

    /**
     * @return array<int, array{url_path: string, miss_count: int}>
     */
    public function getTopMissedUrls(): array
    {
        return $this->getFpcHelper()->getTopMissedUrls(10, $this->getPeriodHours());
    }

    /**
     * @return array<int, array{flush_reason: string, created_at: string, store_code: string}>
     */
    public function getRecentFlushes(): array
    {
        return $this->getFpcHelper()->getRecentFlushes(15, $this->getPeriodHours());
    }

    /**
     * @return array<int, array{hour: string, hits: int, misses: int}>
     */
    public function getHourlyStats(): array
    {
        return $this->getFpcHelper()->getHourlyStats($this->getPeriodHours());
    }

    /**
     * Whether FPC stats collection is currently enabled.
     */
    public function isStatsEnabled(): bool
    {
        return Mage::getStoreConfigFlag('system/fpc/stats_enabled');
    }

    /**
     * Whether FPC itself is enabled.
     */
    public function isFpcEnabled(): bool
    {
        return $this->getFpcHelper()->isEnabled();
    }

    /**
     * URL to FPC config section.
     */
    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'mageaustralia_fpc']);
    }

    protected function getFpcHelper(): Mageaustralia_Fpc_Helper_Data
    {
        if ($this->fpcHelper === null) {
            $this->fpcHelper = Mage::helper('mageaustralia_fpc');
        }
        return $this->fpcHelper;
    }
}
