# Changelog

All notable changes to the Delegat Bridge OpenCart extension.

## [Unreleased]

## [1.4.0] - 2026-11-25

### Added

- **Bulk v2 per-row results** for `prices/bulk`, `stocks/bulk` and `products/bulk`:
  every response carries an `items[]` array exactly parallel to the input rows, each
  entry with the input `index`, the verbatim pre-cast `external_product_id`, the
  `resolved_product_id`, `success` and a machine-readable `error_code` /
  `error_message` on failure. Legacy aggregate counters and the flat `errors[]`
  array are retained unchanged for older clients.
- **Server-side alias resolution** for price/stock rows: identities may be given as
  distinct `product_id` / `sku` / `model` fields, or as `external_product_id` with an
  explicit `id_kind` (`product_id`, `sku`, `model`). Aliases resolve by exact match
  only; zero matches fail the row with `product_not_found`, several distinct matches
  fail it with `product_ambiguous` — never an arbitrary first pick. A numeric SKU is
  never confused with a numeric product id when `id_kind` says `sku`. A bare numeric
  `external_product_id` without `id_kind` keeps the legacy product-id semantics.
- **Warehouse-scoped stock rejection**: a stock row carrying `warehouse_id` now fails
  row-level with `warehouse_unsupported` instead of silently writing global quantity.
- **Structured capability details** in `health`: `capability_details` publishes
  `bulk.v2.per_row_results`, `bulk.alias_resolution`, `bulk.per_row_transactions`,
  `stocks.warehouse_reject` and `bulk.max_rows` (500) so batching clients can gate on
  behavior instead of parsing version strings. The legacy flat `capabilities` array
  is unchanged.
- **Input validation**: negative prices fail row-level with `invalid_price`; missing
  or negative stock quantities fail row-level with `invalid_quantity` instead of
  silently writing zero; rows beyond the `bulk.max_rows` ceiling fail with
  `too_many_rows` while earlier rows still apply.
- `special_price` in the `products` listing readback, filtered by the same customer
  group and validity window the write path uses, so readback confirmation compares
  like with like.
- `scripts/test_bulk_v2.php`: a standalone behavioral test harness (in-memory SQL
  emulation of the OpenCart db layer) covering the bulk v2 contract.

### Changed

- **Per-row atomicity**: each price row (base price plus special delete/insert) and
  each card row (the whole product graph: base row, descriptions, store/category
  links, attributes, options, images, special price) now commits or rolls back as a
  unit. Image downloads run before the transaction opens so network latency never
  holds row locks.
- **`\Throwable` containment everywhere**: every bulk row is isolated — a PHP error
  in one row can no longer abort the batch or lose the remaining rows' results — and
  the outer dispatcher catches `\Throwable` with a best-effort rollback, returning a
  structured 500 instead of an HTML fatal.
- `products/bulk` result rows now carry the explicit input `index` in addition to
  `local_product_id`, keeping positional attribution even when local ids repeat.
- `api_version` reported by `health` is now `1.4.0`.

## [1.3.0] - 2026-09-01

### Added

- Initial marketplace bridge endpoints: catalog, categories, products (single and
  bulk upsert), bulk prices/stocks, orders, order status writeback, promotions,
  image download.
