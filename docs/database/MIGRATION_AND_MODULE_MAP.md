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
13. Commissions, seller transactions/payouts, Seller Wallet/withdrawals, and rider earnings
14. Conversations, messages, and notifications
15. Announcements, policies, audits, settings, and support

The additive migration `2026_10_15_000001_create_seller_wallet_and_withdrawal_tables` adds the Seller Wallet ledger, encrypted bank-account storage, withdrawal/status-history/transfer records, and lifetime-withdrawn backfill from already-paid legacy Seller payouts. Its `down()` refuses to drop the tables once any wallet/account/withdrawal data exists; rollback after activation requires an explicit data-preservation plan.

The additive migration `2026_10_15_000002_add_receiving_bank_name_to_seller_payouts` adds the optional `seller_payouts.receiving_bank_name` field used when Finance records a verified legacy bank transfer. Its rollback refuses to drop the column while any bank names have been recorded.

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
