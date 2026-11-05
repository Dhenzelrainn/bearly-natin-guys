# Migration and Module Map

## Safe setup

For a new disposable development database:

```powershell
php artisan migrate --seed
```

Use `migrate:fresh --seed` only when the developer explicitly intends to erase that local database. Never use it as a normal production deployment command. Existing environments use `php artisan migrate --force` after a backup and migration review.

The schema has been validated against a clean MySQL database for the migration set covered by that validation. The current local `bearly_ecommerce` database experienced an interrupted first run and must not be used as evidence that the full migration batch is complete until reconciled. The new Seller Wallet migration has only been exercised by SQLite feature tests; it has not been run against the application database or verified on MySQL.

## Dependency order

1. Users/authentication
2. Roles and user-role assignments
3. Addresses and categories
4. Applications, documents, and role profiles
5. Stores, sorting centers, zones, and riders
6. Products, variants, images, and inventory movements
7. Carts and wishlists
8. Orders, seller orders, items, and payments
9. Shipments, waybills, and parcels
10. Pickup, sorting, dispatch, delivery, and shipment events
11. Compliance checks, reports, violations, actions, and warnings
12. Returns, refunds, and disputes
13. Commissions, seller transactions/payouts, Seller Wallet/withdrawals, Logistics payout destinations/remittances, and Rider earnings
14. Conversations, messages, and notifications
15. Announcements, policies, audits, settings, and support

The additive migration `2026_10_27_000001_add_archive_reason_to_announcements_table`
adds `announcements.archive_reason` to distinguish manual archives from
retention archives. Existing rows are not rewritten; old archived rows with a
null reason remain hidden from recipients. Rollback restores retention-archived
rows to `published` before dropping the marker so recipient links remain readable.

The additive migration `2026_10_15_000001_create_seller_wallet_and_withdrawal_tables` adds the Seller Wallet ledger, encrypted bank-account storage, withdrawal/status-history/transfer records, and lifetime-withdrawn backfill from already-paid legacy Seller payouts. Its `down()` refuses to drop the tables once any wallet/account/withdrawal data exists; rollback after activation requires an explicit data-preservation plan.

The additive migration `2026_10_15_000002_add_receiving_bank_name_to_seller_payouts` adds the optional `seller_payouts.receiving_bank_name` field used when Finance records a verified legacy bank transfer. Its rollback refuses to drop the column while any bank names have been recorded.

The additive migration `2026_10_18_000002_add_category_snapshot_to_order_items` adds nullable `order_items.category_id_snapshot` (FK to `categories`, delete SET NULL) and `order_items.category_name_snapshot` for purchase-time category reporting. Legacy rows stay null and report as "Category not recorded"; its `down()` drops the FK and both columns.

The additive migration `2026_10_29_000001_add_buyer_return_dropoff_and_return_shipping_settlement` adds Buyer-selected return delivery method/drop-off deadline/assigned center, a return route quote snapshot, an explicit return type on the existing shipping settlement ledger, and separate Seller refund-proceeds versus return-shipping recovery components. Existing outbound settlements remain `outbound`; historical return requests remain Rider-pickup compatible. Its rollback refuses while return delivery, quote, settlement, or fee-recovery records exist. It has only been exercised on guarded SQLite `:memory:` feature tests; do not run the project schema generator or a migration against the interrupted local MySQL database until it is reconciled.

The additive migration `2026_10_30_000001_add_logistics_payout_destinations` adds encrypted, Finance-verified Logistics bank/GCash payout destinations and nullable destination links/snapshots on `shipping_settlements`. Finance still performs bank/GCash transfers externally and records the actual reference; this is not a Logistics wallet or automatic payout integration. Its rollback refuses while destination records or settlement links exist. MySQL migration/rollback behavior must be verified before release; do not run it against the interrupted local MySQL database until it is reconciled.

The additive migration `2026_11_01_000001_add_courier_linehaul_workflows` adds optional Rider work-type and Courier vehicle-verification fields, applicant work-type/model snapshots, provider-scoped line-haul runs/manifests/events, and Logistics-reviewed Rider vehicle-change requests. A null work type preserves existing Rider profiles as legacy-local; no existing account is rewritten. A unique shipment assignment prevents duplicate manifests. Only Courier departure moves parcels in transit; destination Logistics acceptance remains the custody-transfer point. Its `down()` refuses rollback once Courier requests, Rider capability, or line-haul history exists. The migration has only been exercised by guarded SQLite feature tests; do not apply it to the interrupted local MySQL database, and verify MySQL migration/rollback behavior before release.

The additive migration `2026_11_02_000001_create_form_draft_tables` adds browser-scoped encrypted registration drafts, Store-scoped encrypted Seller product drafts, and private temporary Seller product image metadata. Draft rows expire after seven inactive days; the daily `drafts:prune-expired` command removes expired rows and image files when deletion succeeds. Failed image deletions retain encrypted retry metadata and are reported. It has only been exercised by guarded SQLite feature tests and a MySQL dry run; do not apply it to the interrupted local MySQL database without migration review.

The additive migration `2026_11_02_000002_create_rider_locations_table` adds one latest-only `rider_locations` row per Rider profile for foreground local-Rider GPS sharing. It stores coordinates, browser-reported accuracy, and the server `last_seen_at`; Off duty and account-status cleanup deletes the row. Three-minute stale locations are excluded from GPS ranking, and the feature falls back to assigned Sorting Zone/workload/manual selection. It has been exercised by guarded SQLite feature tests; verify MySQL migration and rollback behavior before release.

The additive migration `2026_11_02_000003_add_region_to_registration_addresses` adds nullable canonical Region name/code fields to `users` and nullable Region/name-code fields to `addresses` for the four-level registration cascade. Existing rows are not rewritten; new Buyer, Seller, Rider, and Logistics registrations canonicalize Region from PSGC city identity before writing the transitional user fields and normalized address fields. It has been exercised by guarded SQLite registration and approval tests; apply it to persistent environments only after the interrupted local MySQL database is reconciled.

The additive migration `2026_11_03_000001_add_flexible_rider_assignment_and_payouts` adds a `both`-defaulted local Rider specialization, planned delivery work windows, reporting-only `rider_shifts`, per-parcel pickup earning/size/rate/shift snapshots, and provider-owned reviewed payout batches with unique earning items. It does not backfill legacy earning amounts or change delivery settlement/Finance gates. Unknown saved parcel size classes are held for review; default regular/bulky rates are 1500/3000 centavos and use platform `system_settings`. Its rollback refuses to drop recorded attendance, non-default specialization, planned windows, pickup parcel earnings, or payout batches/items. The migration and rollback have only been exercised through guarded SQLite feature tests; do not run it against the interrupted local MySQL database and verify MySQL migration/rollback behavior before release.

The additive migration `2026_11_04_000001_add_courier_daily_earnings` adds nullable Courier work-date and line-haul-run snapshots to the existing Rider earnings ledger, plus indexes and a unique Rider/type/work-date key so one Courier earns ₱650 (65,000 centavos) at most once per Asia/Manila work date. Reconciliation requires a closed attendance shift and at least one run assigned during that shift that is completed after destination Logistics accepts the full manifest; late acceptance is credited to the shift date. Local Rider earnings remain unchanged. Rollback refuses once Courier daily work-date/run history exists. It passed guarded SQLite feature tests and was applied by user authorization to the confirmed dev MySQL database in batch 44; read-only inspection verified the new columns and indexes. Do not roll back this migration on the persistent database; validate rollback only on a disposable MySQL copy.

The additive migration `2026_11_05_000001_add_inter_hub_shipping_settlements` adds nullable allocation-mode/origin-provider snapshots and the origin share to `shipping_settlements`, plus a separate provider-owned origin remittance table for new outbound shipments with one accepted inter-hub transfer. It does not backfill or rewrite historical settlement snapshots. Inter-hub rows use the 10/30/60 allocation and separate 10% L1 / 90% L2 Finance remittances; single-provider outbound and all returns retain 40/60. Rollback refuses once an inter-hub settlement or origin-remittance record exists. After an initial MySQL attempt exposed an overlong generated foreign-key name, the migration was made resumable with explicit short constraint names and applied to the user-confirmed, backed-up development MySQL database in batch 45. Read-only schema inspection verified the added columns, four origin-remittance foreign keys, unique settlement index, and provider/status index; the origin-remittance table was empty at verification. Rollback has not been tested on MySQL. Do not apply this migration to production without a separate backup and release review.

The additive migration `2026_11_05_000002_add_delivery_targets_and_nearby_zone_dispatch` adds nullable `orders.delivery_latitude` / `delivery_longitude` snapshots plus nullable dispatch mode, assigned-Rider-zone snapshot, and Logistics reason fields. It does not infer or backfill historical delivery pins. The Rider-zone FK uses SET NULL to preserve existing zone-removal behavior; shipment event metadata retains immutable zone ID/code snapshots. Rollback refuses while any coordinate snapshot or dispatch assignment metadata exists. It passed guarded SQLite feature tests and was applied to the user-confirmed, backed-up development MySQL database in batch 46; read-only schema checks verified the new order and dispatch columns. Rollback has not been tested on MySQL. Do not apply it to production without a separate backup and release review.

## Delivery priorities

| Priority | Scope | Deadline classification |
|---|---|---|
| P0 | Users, roles, addresses, applications, profiles, stores, categories | Required now |
| P1 | Products, variants, inventory, carts, orders, seller orders, items, payments | Required now |
| P2 | Shipments, waybills, parcels, pickup, sorting, dispatch, delivery events/proof | Required now for logistics demo |
| P3 | Compliance review, returns/refunds, disputes, commissions, transactions, earnings, Seller Wallet/withdrawals, audit logs | Required where visible in current UI; deeper automation may follow |
| P4 | Advanced messaging attachments, notification preferences, policy version publishing, support workflow, AI/image screening | Later enhancement |

## Team ownership

| Owner | Primary responsibility |
|---|---|
| Database/Admin/Logistics/Rider owner | Migrations, ERD, shared model relationships, seed reference data, admin moderation, logistics scan/sort/dispatch, rider assignment/delivery UI wiring |
| Backend teammate | Form Requests, policies, application services, transactions, checkout/order/payment services, API/resource contracts, automated feature tests |
| Dhenzel | Seller and landing integration: store/product/variant CRUD, inventory UI, seller orders, waybill request screens |
| Shaeena | Buyer integration: catalog, cart, wishlist, checkout, order history, returns initiation |

Shared files such as migrations, status enums, models, routes, and service contracts require a short-lived integration branch or one designated owner. Feature branches should avoid mixing unrelated UI formatting with schema changes.

## Recommended checkpoints

1. **Identity:** register each role, approve it, log in, verify ownership middleware.
2. **Marketplace:** create seller/store/product/variant; inventory movement updates balance; compliance scan runs.
3. **Commerce:** checkout creates consistent totals and seller partitions; payment transitions are idempotent.
4. **Fulfillment:** shipment generates waybill/token; pickup and sorting scans append ordered events.
5. **After-sales/finance:** returns cannot exceed purchased quantities; refunds and ledger entries reconcile.
6. **System:** audit actor/reason are retained and dashboards derive totals from transactions.

## Handoff files

- `BEARLY_DATABASE_SCHEMA.md`: exact generated columns, keys, indexes, and FK behavior.
- `BEARLY_ERD.dbml`: import into dbdiagram.io.
- `BEARLY_ERD.mmd`: Mermaid source for repository previews.
- `BACKEND_INTEGRATION_CONTRACT.md`: naming, statuses, ownership, and service boundaries.
- `scripts/generate_database_docs.php`: regenerates schema/ERD outputs from the configured MySQL database.
