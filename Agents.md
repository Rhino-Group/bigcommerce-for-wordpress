# Agents.md — BigCommerce for WordPress (Suma)

## Overview

Fork of the BigCommerce for WordPress plugin, customized for Suma storefronts. Provides product catalog display, pricing, cart, checkout, and order management via the BigCommerce API.

## Key Architecture

### REST API Endpoints

| Endpoint | Methods | Controller |
|---|---|---|
| `/bigcommerce/v1/pricing` | GET, POST | `Pricing_Controller` |
| `/bigcommerce/v1/products` | GET | `Products_Controller` |

### Pricing API (`/bigcommerce/v1/pricing`)

- **GET**: Items passed as JSON-encoded `items` query param (URL-encoded). Preferred for CDN cacheability.
- **POST**: Items passed as JSON body `{ "items": [...] }`. Kept for backward compatibility.
- **Cache Key**: `X-BC-Pricing-Key` header sent on request (JS) and echoed on response (PHP). Value is a djb2 hash of the normalized items payload in base-36.
- **Validation Flow**: Custom `validate_items_param()` decodes JSON strings before schema validation (WordPress runs validate before sanitize). Custom `sanitize_items_param()` provides safety-net decoding.
- **Nonce**: Configurable via Customizer (`ENABLE_PRICE_NONCE`). Should be disabled (`no`) for full-page/CDN caching.

### JavaScript

- **Bundle**: `assets/js/dist/scripts.js` (webpack-compiled, eval strings). No build system in repo — source files not shipped.
- **Minified**: `assets/js/dist/scripts.min.js`
- **HTTP Library**: superagent
- **Pricing Function**: `wpAPIProductPricing()` in ajax module (webpack module 26) — uses `superagent.get()` with `.query({ items: ... })`.
- **Hash Function**: `bcPricingHash()` (unminified) / `bc_h()` (minified) — djb2 algorithm matching the PHP implementation.

### Build & Packaging

- **Script**: `package-plugin.ps1` (PowerShell)
- **Output**: `dist/bigcommerce-suma-{version}.zip`
- **Includes**: `bigcommerce.php`, `build-timestamp.php`, `uninstall.php`, `readme.txt`, `LICENSE`, `assets/`, `src/`, `templates/`, `vendor/`
- **Important**: `vendor/` is committed in full — do NOT strip dev dependencies. The autoloader depends on all packages.

## Recent Major Changes

### 6.1.3 (2026-04-17)
- Added `package-plugin.ps1` build script.

### 6.1.2 (2026-04-17)
- Converted pricing API frontend requests from POST to GET for CDN cacheability.
- Added custom validate/sanitize callbacks for JSON query param handling.

### 6.1.1 (2026-04-17)
- Added `X-BC-Pricing-Key` cache differentiation header for Imperva CDN.
- Implemented djb2 hash in PHP (`generate_pricing_cache_key()`) and JS (`bcPricingHash()`).

### 6.1.0 (2026-02-16)
- Fixed webhook and product cleanup cron scheduling for PHP 8+ compatibility.
