# Mageaustralia_Fpc — Full Page Cache + Turbo Drive for Maho

High-performance full page cache module for [Maho](https://mahocommerce.com) (PHP 8.3+). Serves cached pages as static HTML files — bypassing PHP entirely when served by nginx. Includes Hotwire Turbo Drive for instant client-side navigation.

## Performance

| Layer | TTFB | Req/s | vs No Cache |
|-------|------|-------|-------------|
| No cache (PHP render) | 490ms | ~2 | — |
| PHP FPC cache hit | 45ms | 172 | **11x** |
| **nginx static serving** | **6ms** | **3,648** | **1,824x** |

> Tested on a dedicated staging server (dedicated CPU, nginx + PHP-FPM 8.4).
> 5,000 requests, 200 concurrent users, zero failures.

### FrankenPHP / Fly.io

| Page | Without FPC | With FPC | Speedup |
|------|-------------|----------|---------|
| Homepage | 110ms | 75ms | 1.5x |
| Category + Products | 303ms | 79ms | **3.8x** |
| Product Page | 300ms | 78ms | **3.8x** |

> Tested on Fly.io shared-cpu-1x (512MB) with Neon Postgres, Sydney region.

## Features

### Full Page Cache
- **Two-layer cache**: static HTML files at `var/fpc/{store}/{url}.html` + CDN via Cache-Control headers
- **Path-based keys** (Zoom FPC style): nginx `try_files` serves cached pages in ~0.3ms with no PHP
- **HTML minifier**: collapses whitespace, strips comments — up to 44% smaller HTML
- **Gzip**: pre-compressed `.gz` files for nginx `gzip_static`

### Dynamic Blocks (AJAX Hole-Punching)
- **Admin table UI**: configure blocks with Name, Block Type, Template, CSS Selector, Mode (HTML/Text)
- **Block types**: Maho block aliases (`checkout/cart_sidebar`), helper calls (`helper:checkout/cart:getSummaryCount`), or layout block names
- **Form key refresh** on every page load
- **Minicart AJAX refresh** when sidebar opens — re-initializes Minicart JS for remove/update

### Turbo Drive (Instant Navigation)
- **Per-page-type config**: enable/disable for Category, Product, CMS pages independently
- **Generic re-init**: re-dispatches `DOMContentLoaded` + `window.load` after body swap — works with ANY theme JS
- **Excluded paths**: checkout, customer account, etc. bypass Turbo automatically
- **Offcanvas handler**: fresh dialog references after Turbo navigation (base Maho theme)

### Cache Invalidation
- Product, category, CMS page/block save observers
- Stock change detection
- Glob-based purge for parameterized URL variants (pagination, filters)
- Unknown query params bypass FPC (filters render fresh)
- Cache type in Cache Storage Management + admin flush button

## Installation

Copy the module files to your Maho installation:

```bash
cp -R app/ /path/to/maho/app/
cp -R js/ /path/to/maho/public/js/

# Regenerate autoloader
cd /path/to/maho && composer dump-autoload

# Clear caches
rm -rf var/cache/* var/fpc/
```

## Configuration

**System > Configuration > Mage Australia > Full Page Cache**

### General Settings
| Setting | Default | Description |
|---------|---------|-------------|
| Enable FPC | No | Master toggle |
| Cache Lifetime | 86400 | Seconds before cached pages expire |
| CDN Max-Age | 60 | `s-maxage` for CDN layer |
| Show Cache Headers | No | Adds `X-Fpc` and `X-FPC-Age` response headers |
| Vary by Customer Group | No | Separate cache per customer group |

### Page Caching Rules
| Setting | Description |
|---------|-------------|
| Cacheable Actions | Whitelist of action names to cache (one per line) |
| Bypass Handles | Layout handles that skip caching |
| URI Parameters | Query params included in cache key |
| Strip Parameters | Query params always stripped (UTM, gclid, etc.) |

### Dynamic Blocks (AJAX Hole-Punching)
Admin table with columns:

| Column | Description |
|--------|-------------|
| Name | Unique block identifier |
| Block Type | `checkout/cart_sidebar` (Maho block) or `helper:checkout/cart:getSummaryCount` (helper method) |
| Template | Optional phtml template override |
| CSS Selector | Element to find in cached HTML (`#id`, `.class`, `[data-attr]`) |
| Mode | HTML (replace innerHTML) or Text (replace textContent) |

### Turbo Drive
| Setting | Default | Description |
|---------|---------|-------------|
| Enable Turbo | Yes | Master toggle |
| Category Pages | Yes | Use Turbo for category browsing |
| Product Pages | No | Disable if swatches/options break |
| CMS Pages | Yes | Use Turbo for static pages |
| Excluded Paths | /checkout/, /customer/, etc. | Always bypass Turbo |

## Web Server Configuration

### nginx (recommended)

Add to `http {}` context:
```nginx
map $uri $fpc_uri {
    "/"     /index.html;
    default $uri;
}

map $args $fpc_prefix {
    ""      /var/fpc/default;  # Change to your store code
    default "";
}
```

Update `location /`:
```nginx
location / {
    gzip_static on;
    default_type text/html;
    try_files $fpc_prefix$fpc_uri $uri $uri/ @handler;
}
```

Create symlink:
```bash
ln -sfn /path/to/maho/var/fpc /path/to/maho/public/var/fpc
```

### FrankenPHP / Caddy

PHP fallback handles caching automatically — no Caddyfile changes needed.

### Apache (.htaccess)

```apache
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{DOCUMENT_ROOT}/var/fpc/default%{REQUEST_URI} -f
RewriteRule .* var/fpc/default%{REQUEST_URI} [L]
```

## Architecture

```
Browser request
  │
  ├─ [nginx try_files] → static HTML file exists? → serve (~0.3ms)
  │
  └─ [PHP] → FPC predispatch observer → cached file exists?
       ├─ YES → serve from disk (~6ms, no layout/DB)
       └─ NO  → full render (~300ms) → write cache → serve
  │
  Browser receives HTML with placeholders
  │
  └─ loader.js → GET /fpc/dynamic/?blocks=cart_count,account_links,...
       └─ PHP renders only requested blocks (~50ms)
       └─ JS injects into placeholders
```

## Requirements

- Maho 26.3+ (or OpenMage with PHP 8.3+)
- `declare(strict_types=1)` compatible environment

## License

Open Software License v3.0 (OSL-3.0)

Copyright (c) 2026 [Mage Australia](https://mageaustralia.com.au)
