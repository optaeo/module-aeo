# Changelog

All notable changes to the OptAEO Magento module are documented here.

Versions are published as git tags on the public repository; Packagist reads them
from there (composer.json deliberately carries no `version` field, so the tag is
the single source of truth). **1.1.0 is the first PUBLICLY released tag** — 1.0.0
was a pre-publication release candidate that never reached Packagist. The history
below is kept as it happened rather than renumbered, so the 1.1.0 entry continues
to describe exactly what shipped in it.

## 1.3.0

### Added

- Module support for `GET /sitemap.xml` at the store root, generated read-only
  from the complete live enabled-and-visible catalogue truth. Output streams in
  bounded batches, uses a sitemap index above 50,000 URLs, XML-escapes and
  de-duplicates each document, skips XML-illegal URLs, and aborts visibly if a
  catalogue row read fails rather than returning a successful truncated
  document. Base-URL validation and both catalogue counts complete before XML
  headers are sent; failure at request start returns `503 text/plain` instead
  of a false-success sitemap response.

## 1.2.0

### Fixed

- **Product-save callbacks now actually reach OptAEO.** In 1.1.0 the
  `catalog_product_save_after` observer wrote its callback to a raw stream socket
  and closed it before reading any answer. Against a TLS 1.3 edge that appends a
  post-handshake record to its handshake flight, the client kernel discarded the
  still-unsent request when the socket was closed with unread inbound bytes — so
  every callback completed a TLS handshake and never sent one byte of HTTP (verified
  with tcpdump: handshake, client `Finished`, RST 1 ms later; zero deliveries in
  24 hours of product saves). Delivery is rebuilt around cURL and WAITS for the
  receiver's answer (bounded: 3 s connect / 15 s total per callback), so a callback
  is either delivered or logged as not delivered — never silently lost.
- **Zero added latency for the merchant.** Under php-fpm / LiteSpeed the observer
  only queues; the queue is flushed in a shutdown function after
  `fastcgi_finish_request()` has handed the response to the web server, with the
  PHP session released first so a concurrent admin request is never held up.
  Under the CLI the queue flushes at process exit (a long import is not slowed per
  product). Under mod_php / CGI delivery is inline with a tighter 8 s bound.
- **Honest logging, nothing swallowed.** Delivered callbacks log at debug;
  every other outcome is a warning in the module log with the reason and what
  OptAEO does next (rejected signature → re-sync to re-provision; not processed →
  the receiver's reason; unreachable → the next full sync reconciles). Bounded
  everywhere: one delivery per SKU per request, 25 SKUs per request (bulk saves
  beyond that reconcile on the next sync), a 45 s flush budget, and a circuit that
  stops the flush the moment OptAEO proves unreachable.

### Added

- `GET /V1/optaeo/webhook-config` — read-back of the observer callback config the
  store actually holds (callback URL, secret fingerprint, installed module
  version). The OptAEO connector counts a provisioning push as live only when this
  read-back agrees, and uses it to tell an outdated module from a broken one.
- `PUT /V1/optaeo/protocol-config` — the OptAEO connector pushes the merchant's
  protocol switches: whether `/llms.txt` and `/agents.txt` (+ `/agents.md`) are
  served, and the IndexNow key to host. Toggling llms.txt or agents.txt off in
  OptAEO now returns an honest 404 at the store root instead of a file OptAEO says
  is off. Both files stay ON by default. Authorized by the existing
  `Optaeo_Aeo::webhooks` ACL resource, so Integrations granted for 1.1.0 need no
  re-grant.
- **IndexNow key file at the store root.** With a key provisioned, `GET /<key>.txt`
  returns the key as `text/plain` — the IndexNow ownership check search engines
  perform — so OptAEO's IndexNow submissions from a Magento store can verify. Only
  the exact provisioned key is answered; every other `/<something>.txt` is untouched.
- Product JSON-LD `offers.shippingDetails` (`OfferShippingDetails`) composed from
  the store's own live shipping configuration — one entry per allowed destination
  country, a free `MonetaryAmount` rate only when free shipping is actually
  configured, and no `deliveryTime` (Magento core carries no transit data; never
  fabricated). Honest omission when the store has no shipping to represent.
- Unit tests (`Test/Unit`) covering the signed callback wire contract, the
  dispatch / dedupe / budget / logging rules, a real-socket regression test for the
  1.1.0 early-close defect (fails on the old sender, passes on the new one), and the
  protocol switches.

### Upgrade

```bash
composer update optaeo/module-aeo
bin/magento setup:upgrade
bin/magento cache:flush
```

In production mode also run `bin/magento setup:di:compile`. No data patch runs in
this release. The connector re-provisions the callback config and protocol
switches on its next sync — no merchant action in OptAEO is needed.

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
