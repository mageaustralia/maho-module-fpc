<?php

/**
 * Mageaustralia_Fpc — upgrade 1.0.0 → 1.1.0
 *
 * Creates the mageaustralia_fpc_stats table for hit/miss/flush/purge tracking.
 */

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $installer */ /** @phpstan-ignore variable.undefined */
$installer = $this;
$installer->startSetup();

$tableName = $installer->getTable('mageaustralia_fpc/stats');

if (!$installer->getConnection()->isTableExists($tableName)) {
    $table = $installer->getConnection()
        ->newTable($tableName)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ], 'Record ID')
        ->addColumn('event_type', Varien_Db_Ddl_Table::TYPE_TEXT, 10, [
            'nullable' => false,
        ], 'Event type: hit, miss, flush, warmup, purge')
        ->addColumn('url_path', Varien_Db_Ddl_Table::TYPE_TEXT, 500, [
            'nullable' => true,
        ], 'Page URL path')
        ->addColumn('ttfb_ms', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Time-to-first-byte in ms (misses only)')
        ->addColumn('flush_reason', Varien_Db_Ddl_Table::TYPE_TEXT, 255, [
            'nullable' => true,
        ], 'What triggered the flush/purge')
        ->addColumn('store_code', Varien_Db_Ddl_Table::TYPE_TEXT, 50, [
            'nullable' => false,
            'default'  => '',
        ], 'Store code')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ], 'Event timestamp')
        ->addIndex(
            $installer->getIdxName('mageaustralia_fpc/stats', ['event_type', 'created_at']),
            ['event_type', 'created_at'],
        )
        ->addIndex(
            $installer->getIdxName('mageaustralia_fpc/stats', ['created_at']),
            ['created_at'],
        )
        ->addIndex(
            $installer->getIdxName('mageaustralia_fpc/stats', ['store_code']),
            ['store_code'],
        )
        ->setComment('FPC Statistics');

    $installer->getConnection()->createTable($table);
}

$installer->endSetup();
