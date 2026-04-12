<?php

/**
 * Mageaustralia_Fpc — upgrade 1.2.0 → 1.2.1
 *
 * Adds a unique key on (hour, store_code, event_type) in the rollup table
 * so that INSERT ... ON DUPLICATE KEY UPDATE works — preventing duplicate
 * rows if the rollup cron runs twice for the same bucket.
 *
 * Also de-dupes any existing duplicates before adding the constraint.
 */

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $installer */ /** @phpstan-ignore variable.undefined */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$tableName = $installer->getTable('mageaustralia_fpc/stats_hourly');

// Remove any duplicate rows: keep the row with the highest count for each bucket
$connection->query("
    DELETE h1 FROM {$tableName} h1
    INNER JOIN {$tableName} h2
        ON h1.hour = h2.hour
        AND h1.store_code = h2.store_code
        AND h1.event_type = h2.event_type
        AND h1.id < h2.id
");

// Drop the old non-unique index if it exists
$oldIdx = $installer->getIdxName('mageaustralia_fpc/stats_hourly', ['hour', 'store_code', 'event_type']);
try {
    $connection->dropIndex($tableName, $oldIdx);
} catch (\Throwable) {
    // Index may not exist
}

// Add a unique index
$connection->addIndex(
    $tableName,
    $installer->getIdxName(
        'mageaustralia_fpc/stats_hourly',
        ['hour', 'store_code', 'event_type'],
        Maho\Db\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE,
    ),
    ['hour', 'store_code', 'event_type'],
    Maho\Db\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE,
);

$installer->endSetup();
