# Changelog

All notable changes to the OptAEO Magento module are documented here.

Versions are published as git tags on the public repository; Packagist reads them
from there (composer.json deliberately carries no `version` field, so the tag is
the single source of truth). **1.1.0 is the first PUBLICLY released tag** — 1.0.0
was a pre-publication release candidate that never reached Packagist. The history
below is kept as it happened rather than renumbered, so the 1.1.0 entry continues
to describe exactly what shipped in it.

## 1.1.0

- Product JSON-LD now emits descriptive product attributes as schema.org
  `PropertyValue` entries in `additionalProperty`, so attributes are legible to AI
  crawlers even when the merchant has not set `is_visible_on_front` on them.
  Composed from the live product, with select/multiselect option IDs resolved to their
  labels. The OptAEO provenance marker is preserved and always emitted last.

## 1.0.0 - release candidate

- Release candidate for Magento Open Source, Adobe Commerce, and Mage-OS 2.4.4+.
- Authoritative Product and FAQPage JSON-LD delivery with Luma microdata suppression.
- Catalogue-aware `llms.txt`, `agents.txt`, and `agents.md` endpoints.
- Connector read APIs for product reviews and shipping configuration.
- Product-save callback support for near-real-time OptAEO synchronisation.
- Idempotent GTIN, FAQ, and descriptive product-attribute provisioning.
