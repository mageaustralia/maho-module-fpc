#!/bin/sh
set -e

echo "=== Maho Fly.io Startup (pgloader edition) ==="
START_TIME=$(date +%s)

# --- HTTPS detection behind Fly proxy ---
cat > /tmp/prepend.php << 'PHPEOF'
<?php
if (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https") {
    $_SERVER["HTTPS"] = "on";
    $_SERVER["SERVER_PORT"] = 443;
}
PHPEOF
echo "auto_prepend_file=/tmp/prepend.php" > /usr/local/etc/php/conf.d/99-prepend.ini

# --- Symfony cache (ephemeral per instance) ---
mkdir -p /tmp/symfony-cache
export APP_CACHE_DIR=/tmp/symfony-cache

# --- Ensure no stale local.xml ---
rm -f /app/app/etc/local.xml

# --- DB config from env vars ---
# Neon connection string: postgres://user:pass@host/dbname?sslmode=require
PG_HOST="${PG_HOST:-ep-misty-forest-a7srl64d.ap-southeast-2.aws.neon.tech}"
PG_USER="${PG_USER:-neondb_owner}"
PG_PASS="${PG_PASS:-npg_AyUt2VQCON9n}"
PG_DBNAME="${PG_DBNAME:-neondb}"
PG_SSLMODE="${PG_SSLMODE:-require}"

# Extract Neon endpoint ID for pgloader (old libpq without SNI support)
NEON_ENDPOINT=$(echo "$PG_HOST" | cut -d. -f1)
# pgloader can't parse & in URIs, so use PGSSLMODE env var instead
export PGSSLMODE="${PG_SSLMODE}"
PGSQL_URI="postgresql://${PG_USER}:${PG_PASS}@${PG_HOST}/${PG_DBNAME}?options=endpoint%3D${NEON_ENDPOINT}"

# --- Check if DB needs seeding ---
TABLE_COUNT=$(PGPASSWORD="$PG_PASS" psql \
    "host=$PG_HOST dbname=$PG_DBNAME user=$PG_USER sslmode=$PG_SSLMODE" \
    -t -c "SELECT count(*) FROM pg_tables WHERE schemaname = 'public';" 2>/dev/null | tr -d ' ')

if [ -z "$TABLE_COUNT" ] || [ "$TABLE_COUNT" -lt 100 ]; then
    echo "=== Neon has $TABLE_COUNT tables — seeding from SQLite via pgloader ==="
    SEED_START=$(date +%s)

    # Drop existing tables if any (clean slate)
    if [ -n "$TABLE_COUNT" ] && [ "$TABLE_COUNT" -gt 0 ]; then
        echo "Dropping $TABLE_COUNT existing tables..."
        PGPASSWORD="$PG_PASS" psql \
            "host=$PG_HOST dbname=$PG_DBNAME user=$PG_USER sslmode=$PG_SSLMODE" \
            -c "DO \$\$ DECLARE r RECORD; BEGIN FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public') LOOP EXECUTE 'DROP TABLE IF EXISTS \"' || r.tablename || '\" CASCADE'; END LOOP; END \$\$;" \
            2>&1
    fi

    echo "Running PHP migration (SQLite → Neon)..."
    php /seed-neon.php /maho-seed.db 2>&1

    SEED_END=$(date +%s)
    SEED_ELAPSED=$((SEED_END - SEED_START))

    # Verify
    TABLE_COUNT=$(PGPASSWORD="$PG_PASS" psql \
        "host=$PG_HOST dbname=$PG_DBNAME user=$PG_USER sslmode=$PG_SSLMODE" \
        -t -c "SELECT count(*) FROM pg_tables WHERE schemaname = 'public';" 2>/dev/null | tr -d ' ')
    echo "=== pgloader complete: $TABLE_COUNT tables in ${SEED_ELAPSED}s ==="

    if [ "$TABLE_COUNT" -lt 100 ]; then
        echo "ERROR: Seeding failed — only $TABLE_COUNT tables. Sleeping for debug..."
        exec sleep 3600
    fi

    NEEDS_REINDEX=1
else
    echo "=== Neon has $TABLE_COUNT tables — skipping seed ==="
    NEEDS_REINDEX=0
fi

# --- Generate local.xml for Neon ---
CRYPT_KEY=$(cat /maho-crypt-key)
cat > /app/app/etc/local.xml << XMLEOF
<?xml version="1.0"?>
<config>
  <global>
    <install><date>$(date)</date></install>
    <crypt><key>${CRYPT_KEY}</key></crypt>
    <disable_local_modules>false</disable_local_modules>
    <resources>
      <db><table_prefix></table_prefix></db>
      <default_setup>
        <connection>
          <host>${PG_HOST}</host>
          <username>${PG_USER}</username>
          <password>${PG_PASS}</password>
          <dbname>${PG_DBNAME}</dbname>
          <engine>pgsql</engine>
          <model>pgsql</model>
          <type>pdo_pgsql</type>
          <sslmode>${PG_SSLMODE}</sslmode>
          <active>1</active>
        </connection>
      </default_setup>
    </resources>
    <session_save>files</session_save>
  </global>
  <admin><routers><adminhtml><args><frontName>admin</frontName></args></adminhtml></routers></admin>
</config>
XMLEOF
echo "local.xml generated for Neon (${PG_HOST})."

# --- Fix default config.xml MySQL-isms for Postgres ---
sed -i 's|<initStatements>SET NAMES utf8</initStatements>|<initStatements></initStatements>|' /app/app/etc/config.xml

# --- Enable FPC module ---
PGPASSWORD="$PG_PASS" psql \
    "host=$PG_HOST dbname=$PG_DBNAME user=$PG_USER sslmode=$PG_SSLMODE" \
    -c "INSERT INTO core_config_data (scope, scope_id, path, value) VALUES
        ('default', 0, 'system/fpc/enabled', '1'),
        ('default', 0, 'system/fpc/cache_actions', 'cms_index_index
cms_page_view
catalog_product_view
catalog_category_view'),
        ('default', 0, 'system/fpc/dynamic_blocks', 'cart_count:[data-cart-count]:text
account_links:[data-fpc-block=\"account_links\"]:html
messages:[data-fpc-block=\"messages\"]:html'),
        ('default', 0, 'system/fpc/bypass_handles', 'checkout_cart_index
checkout_onepage_index
customer_account_login
customer_account_create'),
        ('default', 0, 'system/fpc/turbo_enabled', '1'),
        ('default', 0, 'system/fpc/turbo_excluded_paths', '/checkout/,/customer/,/catalogsearch/')
    ON CONFLICT DO NOTHING;" 2>&1 || echo "FPC config insert skipped (may already exist)."
echo "FPC enabled."

# --- Regenerate autoloader (picks up FPC module in app/code/local) ---
COMPOSER_ALLOW_SUPERUSER=1 composer -d /app dump-autoload --quiet 2>/dev/null || true
echo "Autoloader regenerated."

# --- Clear Maho cache (prevents stale config from prior boots) ---
rm -rf /app/var/cache/* /app/var/session/* /tmp/symfony-cache/* /app/var/fpc/*
echo "Maho cache cleared."

# --- Reindex after fresh seed ---
if [ "$NEEDS_REINDEX" = "1" ]; then
    echo "Running reindex..."
    REINDEX_START=$(date +%s)
    php /app/maho index:reindex:all 2>&1 || echo "Reindex had errors (non-fatal)."
    REINDEX_END=$(date +%s)
    echo "=== Reindex complete in $((REINDEX_END - REINDEX_START))s ==="
fi


END_TIME=$(date +%s)
TOTAL_ELAPSED=$((END_TIME - START_TIME))
echo "=== Startup complete in ${TOTAL_ELAPSED}s — launching FrankenPHP ==="

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
