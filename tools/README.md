# Webhook Fanout Tester

A small PowerShell helper to fire multiple concurrent webhook requests against your local WordPress site.

## Requirements

- Windows PowerShell 5.1+
- Access to the BigCommerce webhook auth key (`bigcommerce_webhook_key`)

## Quick Start

1) Get the webhook auth key from WordPress (via WP-CLI):

```powershell
wp option get bigcommerce_webhook_key
```

2) Fire 4 concurrent product create webhook requests:

```powershell
powershell -ExecutionPolicy Bypass -File C:\path\to\plugin\tools\bc-webhook-fanout.ps1 `
  -ProductId 123 `
  -WebhookType create `
  -BaseUrl http://your-site.local `
  -WebhookKey "YOUR_WEBHOOK_KEY" `
  -ChannelId 1234567
```

3) Fire 4 concurrent product update webhook requests:

```powershell
powershell -ExecutionPolicy Bypass -File C:\path\to\plugin\tools\bc-webhook-fanout.ps1 `
  -ProductId 123 `
  -WebhookType update `
  -BaseUrl http://your-site.local `
  -WebhookKey "YOUR_WEBHOOK_KEY" `
  -ChannelId 1234567
```

## Options

- `-Concurrency` (default: 4): number of requests to send.
- `-TimeoutSeconds` (default: 30): request timeout.
- `-PayloadPath`: path to a JSON file to send instead of the generated payload.
- `-StoreId`: include `store_id` in generated payload.
- `-Producer`: include `producer` in generated payload.
- `-ChannelId`: include `data.channel_id` in generated payload.
- `-AuthHeaderValue`: use a precomputed auth header instead of `-WebhookKey`.
- `-DryRun`: prints the request details without sending.

## Notes

- The tool computes the auth header as `md5( bigcommerce_webhook_key + webhook_name )`.
- The webhook name is `product_create` or `product_update` based on `-WebhookType`.
- The payload includes the minimum fields required by the plugin (`data.id`) plus optional metadata.
