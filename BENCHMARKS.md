# FPC Module Benchmarks

Tested 2026-04-16. All tests use `wrk` with 4 threads, 10s duration.

## Test Environments

| Site | Stack | Server | PHP |
|---|---|---|---|
| **tennisdev.tenniswarehouse.com.au** | Maho + nginx + PHP-FPM 8.3 + FPC (nginx `try_files` static bypass) | Dedicated, Sydney AU | 8.3 |
| **2630.mageaustralia.com.au** | Maho + Fly.io + FrankenPHP + FPC (PHP cache hit, no static bypass) | shared-cpu-1x 512MB, Sydney | 8.4 |
| **demo.mageaustralia.com.au** | Cloudflare Worker (headless storefront, KV cache) | CF Edge (global) | N/A |

## Results — from DigitalOcean droplet (Singapore, sub-1ms RTT to AU servers)

### tennisdev (nginx FPC static serve — gold standard)

| Concurrent | Req/s | Avg Latency | p50 | Transfer/s | Page Size |
|---|---|---|---|---|---|
| 10 | **1,174** | 6.8ms | — | 410 MB/s | 366KB |
| 20 | **1,510** | 13.9ms | — | 528 MB/s | 366KB |
| 50 | **1,424** | 33.7ms | — | 498 MB/s | 366KB |

nginx serves cached HTML via `try_files $fpc_prefix$fpc_uri` — PHP never runs.
Localhost benchmark: **11,910 req/s** at 4ms avg (eliminates network).

### 2630 (FrankenPHP FPC — PHP cache hit, no static bypass)

| Concurrent | Req/s | Avg Latency | Transfer/s | Page Size |
|---|---|---|---|---|
| 10 | 22 | 361ms | 0.7 MB/s | 35KB |
| 20 | 24 | 806ms | 0.8 MB/s | 35KB |
| 50 | 24 | 1,780ms | 0.8 MB/s | 35KB |

Bottleneck: shared-cpu-1x with 2 FrankenPHP worker threads.
Every request bootstraps PHP to read the cache file.
**Target**: Caddy `try_files` static bypass would eliminate PHP, aiming for 1,000+ req/s.

### demo (Cloudflare Worker — headless storefront)

| Concurrent | Req/s | Avg Latency | Transfer/s | Page Size |
|---|---|---|---|---|
| 10 | 227 | 27.7ms | 8 MB/s | 37KB |
| 20 | 676 | 30.1ms | 25 MB/s | 37KB |
| 50 | **2,097** | 27.7ms | 77 MB/s | 37KB |

Scales horizontally at the Cloudflare edge. Latency stays flat as concurrency increases.
At 50 concurrent, overtakes tennisdev on req/s (edge compute advantage).

### www.tenniswarehouse.com.au (live, OpenMage + Lesti FPC)

Single-client TTFB only (not load-tested to avoid production impact):

| Metric | Value |
|---|---|
| Median TTFB | ~207ms |
| Page Size | 428KB |
| Stack | OpenMage + nginx + PHP-FPM + Lesti FPC + Cloudflare |

## Key Takeaways

1. **nginx static FPC serve is CDN-class** — 1,500 req/s for 366KB pages, 12K req/s localhost.
2. **Cloudflare Workers scale infinitely** — linear scaling with concurrency, best for global distribution.
3. **FrankenPHP without static bypass is the bottleneck** — 24 req/s regardless of concurrency due to PHP bootstrap on every request. The Caddy `try_files` static bypass is the critical path to matching nginx performance.
4. **Client-side TLS overhead** masks server performance — benchmarking from a nearby server (sub-1ms RTT) is essential for accurate numbers. From a remote Mac over 150ms RTT, all three sites appeared similar (~25-150 req/s).

## Reproducing

```bash
# From a server with low RTT to the target:
wrk -t4 -c50 -d10s -H "Authorization: Basic $(echo -n user:pass | base64)" https://target/page.html

# Localhost (SSH to the server):
ab -n 200 -c 50 -H "Host: tennisdev.tenniswarehouse.com.au" http://127.0.0.1/racquets.html
```
