<?php

/**
 * Mageaustralia_Fpc — upgrade 1.1.0 → 1.2.0
 *
 * Creates the mageaustralia_fpc_stats_hourly rollup table for aggregated statistics.
 * Raw stats rows older than 24h are rolled up into this table by cron,
 * keeping the raw table small while preserving historical data.
 */

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $installer */ /** @phpstan-ignore variable.undefined */
$installer = $this;
$installer->startSetup();

$tableName = $installer->getTable('mageaustralia_fpc/stats_hourly');

if (!$installer->getConnection()->isTableExists($tableName)) {
    $table = $installer->getConnection()
        ->newTable($tableName)
        ->addColumn('id', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ], 'Record ID')
        ->addColumn('hour', Maho\Db\Ddl\Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ], 'Hour bucket (truncated to hour)')
        ->addColumn('store_code', Maho\Db\Ddl\Table::TYPE_TEXT, 50, [
            'nullable' => false,
            'default'  => '',
        ], 'Store code')
        ->addColumn('event_type', Maho\Db\Ddl\Table::TYPE_TEXT, 10, [
            'nullable' => false,
        ], 'Event type: hit, miss, flush, purge')
        ->addColumn('url_path', Maho\Db\Ddl\Table::TYPE_TEXT, 500, [
            'nullable' => false,
            'default'  => '',
        ], 'URL path (empty for aggregated totals)')
        ->addColumn('count', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
        ], 'Number of events')
        ->addColumn('avg_ttfb', Maho\Db\Ddl\Table::TYPE_DECIMAL, '10,2', [
            'nullable' => true,
        ], 'Average TTFB in ms')
        ->addColumn('min_ttfb', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Minimum TTFB in ms')
        ->addColumn('max_ttfb', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Maximum TTFB in ms')
        ->addColumn('p95_ttfb', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'P95 TTFB in ms')
        ->addIndex(
            $installer->getIdxName('mageaustralia_fpc/stats_hourly', ['hour', 'store_code', 'event_type']),
            ['hour', 'store_code', 'event_type'],
        )
        ->addIndex(
            $installer->getIdxName('mageaustralia_fpc/stats_hourly', ['hour']),
            ['hour'],
        )
        ->addIndex(
            $installer->getIdxName('mageaustralia_fpc/stats_hourly', ['store_code']),
            ['store_code'],
        )
        ->setComment('FPC Statistics — Hourly Rollup');

    $installer->getConnection()->createTable($table);
}

$installer->endSetup();
