# OptAEO for Magento

**Optimise your Agentic Engine.** OptAEO makes your Magento catalogue legible to AI
shopping agents — ChatGPT, Perplexity, Claude, and Gemini — so they read, cite, and
recommend your products correctly.

This module is the on-store half of OptAEO. It owns your product schema, serves the
agent-discovery files at your store root, and exposes the catalogue data the OptAEO
connector reads. It is verified on Luma and includes a Hyvä implementation that
requires licensed-store verification before it can be claimed as tested. Using the full OptAEO
service requires an OptAEO account and an active subscription where applicable.

## What it does

- **One authoritative schema.org Product node.** Emits a single, complete Product
  JSON-LD node in the page `<head>` of every product page, and suppresses the
  theme's native product microdata (Luma) so AI engines see exactly one source of
  truth — never a half-filled or conflicting node.
- **Agent-discovery files at the store root.** Serves `/llms.txt`, the canonical
  `/agents.txt`, and the compatible `/agents.md` alias, built live from your
  catalogue so agents can find and crawl your products.
- **Provisions the attributes AI needs.** Install data-patches create the `gtin`
  product attribute and a set of descriptive attributes (material, color, style,
  fit, size, and more) — idempotent and non-clobbering, so your existing attributes
  are never touched.
- **Read APIs for the connector.** Bundled REST endpoints fill the gaps in Magento's
  core API: a per-product review summary and the store-level shipping configuration,
  both authorized by the connector's integration token.
- **Near-real-time change signal.** Magento core has no product webhooks, so every
  product save posts an HMAC-signed callback to OptAEO, which re-syncs and re-scores
  that product within seconds. Delivery never slows the save: under php-fpm the
  callback is sent after the response has been handed to the web server; on the CLI
  at process exit; the callback is bounded, waited on, and every failure is logged
  in `var/log/system.log` (successes at debug level in `var/log/debug.log`).
- **Switches that follow OptAEO.** The connector pushes the merchant's protocol
  choices to the store (`PUT /V1/optaeo/protocol-config`): switching llms.txt or
  agents.txt off in OptAEO makes the store answer 404 for that file, and the
  merchant's IndexNow key is hosted at `/<key>.txt` so IndexNow submissions verify.

## Requirements

- Magento Open Source / Adobe Commerce / Mage-OS **2.4.4 or newer**
- PHP **8.1+**

## Install

```bash
composer require optaeo/module-aeo
bin/magento setup:upgrade
```

In production mode also run `bin/magento setup:di:compile` and
`bin/magento setup:static-content:deploy`, then `bin/magento cache:flush`.

The install data-patches create the `gtin` and descriptive product attributes and
flush the EAV cache automatically on `setup:upgrade`.

Upgrading from 1.1.0: `composer update optaeo/module-aeo && bin/magento setup:upgrade
&& bin/magento cache:flush` — 1.1.0's product-save callback never reached OptAEO
(see CHANGELOG); the OptAEO connector re-provisions the callback config and protocol
switches automatically on its next sync.

### Integration permissions

The connector's Integration needs, besides Catalog › Products (read + write), the
OptAEO resources: review summaries, shipping config and webhook config — the last one
also covers the connector-pushed protocol switches, so an Integration created for
1.1.0 keeps working without a re-grant.

### Running the module tests

From a Magento root with the module installed:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/optaeo/module-aeo/Test/Unit
```

## Storefront support

Verified on **Luma**: the module suppresses native product microdata so only the
OptAEO JSON-LD node remains. The Hyvä implementation is built to the theme's
integration contract but awaits verification on a licensed Hyvä store.

## Support

Questions or issues: **support@optaeo.ai** · https://optaeo.ai

See [CHANGELOG.md](CHANGELOG.md) for release notes and [SECURITY.md](SECURITY.md)
for private vulnerability reporting.

## License

Licensed under the Open Software License version 3.0 (OSL-3.0). See `LICENSE.txt`.
