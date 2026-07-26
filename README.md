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

## Requirements

- Magento Open Source / Adobe Commerce / Mage-OS **2.4.4 or newer**
- PHP **8.1+**

_Verified on Mage-OS 3.0.0 (Magento 2.4.9), PHP 8.4. Built to the 2.4.4+ module API; other versions in that range are supported but not yet individually tested._

## Install

```bash
composer require optaeo/module-aeo
bin/magento setup:upgrade
```

In production mode also run `bin/magento setup:di:compile` and
`bin/magento setup:static-content:deploy`, then `bin/magento cache:flush`.

The install data-patches create the `gtin` and descriptive product attributes and
flush the EAV cache automatically on `setup:upgrade`.

## Storefront support

Verified on **Luma (Mage-OS 2.4.9)**: the module suppresses native product microdata
so only the OptAEO JSON-LD node remains. The Hyvä implementation is built to the theme's
integration contract but awaits verification on a licensed Hyvä store.

## Support

Questions or issues: **support@optaeo.ai** · https://optaeo.ai

See [CHANGELOG.md](CHANGELOG.md) for release notes and [SECURITY.md](SECURITY.md)
for private vulnerability reporting.

## License

Licensed under the Open Software License version 3.0 (OSL-3.0). See `LICENSE.txt`.
