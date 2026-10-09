# Bearly Backend Integration Contract

This file is the shared contract for backend, UI, and database work. The executable source of truth is `database/migrations`; `BEARLY_DATABASE_SCHEMA.md` contains the generated column-level reference.

## Non-negotiable conventions

- Model names are singular StudlyCase; tables are plural snake_case.
- Foreign keys use `<model>_id`. Do not introduce aliases such as `shop_id`; use `store_id`.
- Store money as integer centavos. Never use float/double for totals.
- Persist machine-readable lowercase snake_case statuses; convert them to display labels in presenters/views.
- Every state change with operational significance must be transaction-safe. Shipment movement also creates a `shipment_events` row.
- Controllers validate HTTP input and authorize access; services own multi-table business workflows.
- Reports and dashboard totals are queries over transactional tables. Do not create snapshot/report tables unless a measured performance need appears.
- Files are stored through Laravel disks; database rows contain disk/path, MIME type, size, and document purpose—not binary file contents.

## Ownership boundaries

| Concern | Authoritative record |
|---|---|
| Buyer identity and login | `users`, `roles`, `role_user` |
| Delivery/contact address | `addresses`; order addresses are immutable JSON snapshots on `orders` |
| Registration review | `account_applications`, `application_documents` |
| Seller organization | `seller_profiles`, `stores` |
| Seller campaign rules | `store_promotions`, `store_promotion_products` |
| Product offer | `products`, `product_variants`, `product_images` |
| Stock history | `inventory_movements`; variant stock is the current cached balance |
| Buyer purchase | `orders`; one marketplace checkout may contain multiple `seller_orders` |
| Seller fulfillment | `seller_orders`, `order_items` |
| Payment attempt/result | `payments` |
| Physical delivery journey | `shipments` |
| Scannable shipping label | `waybills` |
| Physical package/piece | `parcels` |
| Immutable tracking history | `shipment_events` |
| Product moderation | `product_compliance_checks`, matches, `product_violations`, `violation_actions` |
| Return/refund | `return_requests`, `return_items`, `return_evidence`, `refunds` |
| Case management | `disputes`, participants, evidence, events |

### Product variant images

- A variation-specific image belongs to its exact `product_variants.id` through `product_images.variant_id` and uses purpose `variant_sku`.
- Existing image rows with purpose `variant` are legacy color-level images; keep them as a same-color fallback until a Seller replaces or removes them.
- Buyer variation selection prefers its exact SKU image, then a legacy same-color image, then the product's main image (primary when present).
- `order_items` snapshots the selected product image's disk and path at checkout so later catalog edits do not change order presentation. Retain an image file while either a catalog image or an order-item snapshot references its disk/path; older rows without a snapshot may resolve through their current product/variant relationships.

## Seller pricing and campaigns

- Persist Seller campaigns in `store_promotions` and scope every campaign and
  selected product to the authenticated Seller's Store.
- Persist promotion types as `product_discount` or `store_voucher`. Voucher and
  minimum-spend amounts use integer centavos; promotion dates are calendar dates.
- New Seller campaign creation accepts `store_voucher` only. Product discounts
  are managed through Product Pricing; existing `product_discount` campaign rows
  remain readable for compatibility but cannot be created through the campaign form.
- Campaign lifecycle (`scheduled`, `active`, `ended`) is derived from its date
  range until a pause/cancel workflow is introduced.
- When Buyer pricing integration is implemented, an active Product Discount
  replaces the regular Seller discount; the two must not stack.
- Seller-side campaign persistence alone does not change Buyer prices or redeem
  vouchers. Do not imply either behavior until Buyer pricing and transactional
  checkout validation are implemented.

## Required status vocabulary

Use constants or PHP backed enums in application code, while columns remain strings for deployability.

| Domain | Allowed initial vocabulary |
|---|---|
| Account | `pending`, `active`, `needs_revision`, `rejected`, `suspended`, `deactivated` |
| Application | `draft`, `submitted`, `under_review`, `needs_revision`, `approved`, `rejected`, `withdrawn` |
| Product | `draft`, `pending_review`, `active`, `flagged`, `blocked`, `archived` |
| Compliance check | `queued`, `running`, `passed`, `flagged`, `blocked`, `failed` |
| Violation | `flagged`, `under_review`, `confirmed`, `dismissed`, `corrective_action`, `resolved` |
| Order | `pending_payment`, `paid`, `processing`, `partially_fulfilled`, `fulfilled`, `cancelled`, `closed` |
| Seller order | `pending`, `accepted`, `packing`, `ready_for_pickup`, `handed_to_logistics`, `completed`, `cancelled` |
| Payment | `pending`, `authorized`, `paid`, `failed`, `cancelled`, `partially_refunded`, `refunded` |
| Shipment | `pending`, `ready_for_pickup`, `pickup_assigned`, `picked_up`, `at_sorting_center`, `sorted`, `dispatched`, `out_for_delivery`, `delivered`, `delivery_failed`, `return_to_sender`, `cancelled` |
| Parcel | `created`, `picked_up`, `received`, `sorted`, `dispatched`, `out_for_delivery`, `delivered`, `failed`, `returned`, `lost`, `damaged` |
| Pickup | `requested`, `scheduled`, `assigned`, `in_progress`, `completed`, `cancelled`, `failed` |
| Assignment | `assigned`, `accepted`, `en_route`, `completed`, `declined`, `cancelled` |
| Dispatch | `draft`, `loading`, `dispatched`, `completed`, `cancelled` |
| Return | `requested`, `under_review`, `approved`, `rejected`, `in_transit`, `received`, `inspected`, `resolved`, `cancelled` |
| Refund | `pending`, `processing`, `completed`, `failed`, `cancelled` |
| Dispute | `open`, `under_review`, `awaiting_response`, `escalated`, `resolved`, `closed` |
| Commission/transaction/earning | `pending`, `available`, `held`, `processing`, `paid`, `reversed`, `cancelled` |

Do not add synonymous values (for example both `shipped` and `dispatched`) without updating this contract and every consumer.

## Service contracts

### Product compliance

Input: persisted `Product`, actor `User`, scan trigger (`create`, `update`, `manual`, `report`).

Output: `ProductComplianceCheck` with zero or more matches and violations. Critical prohibited rules block/hide the product; high-severity rules hold it for review. Admin decisions append `violation_actions`; dismissal records false positives instead of deleting history.

### Checkout

Input: buyer, Buyer-owned persisted cart items, selected address, COD payment method, and a request key. Output: one `Order`, one `SellerOrder` per store, immutable `OrderItem` snapshots, and a pending `Payment`. Creation must be one database transaction and idempotent using a request key. Placement must reserve stock without reducing `stock_on_hand`; eligible cancellation releases the reservation and appends an `inventory_movements` record.

### Seller Order workflow

- Seller-controlled transitions are `pending → accepted → packing → ready_for_pickup`. The packing checklist is a UI milestone within `packing`, not a persisted `packed` status.
- Every transition appends a `seller_order_status_histories` record with the previous/next status, actor, occurrence time, and reason. The authenticated Seller must own the Seller Order's Store.
- Seller cancellation is allowed only while that Seller Order is `pending`, requires a reason, and atomically releases its reservations with inventory movement history. For a multi-Store COD order, recompute marketplace totals and pending payment amount from the remaining non-cancelled Seller Orders; cancel the marketplace Order/payment only when none remain.
- Lock the marketplace `Order` and its `SellerOrder` rows during transitions; lock affected variants when releasing reservations. Waybills, pickup scheduling, and logistics handoff are separate workflows and must not be implied by `ready_for_pickup`.

### Bearly shipping estimate

- New or edited SKUs require packaged weight and L×W×H; legacy SKUs with null package data cannot be quoted.
- Quote one combined parcel per `SellerOrder` using the deterministic `greedy_minimum_bounding_box_v1` estimate. Billable weight is `ceil(max(actual_weight, parcel_volume / divisor))`, with a one-kilogram minimum.
- `shipping_rates` stores internal `bearly_logistics` rates in integer centavos. Initial first-kilogram fees are ₱34/₱40/₱60/₱100 for nearby delivery, same province, nearby provinces, and inter-island. Additional-kilogram surcharges remain unset until configured; do not invent a fee.
- The divisor defaults to 5000 and can be changed through `system_settings.shipping.volumetric_divisor`. Admin rate changes require an audit actor and reason.
- The Buyer quote is integrity-protected and expires after ten minutes. At placement, revalidate the cart, address, item snapshots, parcel estimate, and rates against that accepted quote; changed terms require a refreshed quote. Persist the applied provider, quote inputs, and fee on each `SellerOrder`.
- The applied fee is fixed after checkout. No remeasurement surcharge, waybill, dispatch, or extra Buyer charge is part of this phase.

### Shipment and waybill creation

Input: fulfillable `SellerOrder`, logistics provider, origin/destination snapshots, parcel dimensions and weight. Output: `Shipment`, unique `Waybill` number plus unguessable scan token, one or more `Parcel` rows, and initial event. A waybill must never be created from arbitrary manually entered buyer/order data.

### Parcel intake scan

Input: authenticated logistics user, scan token or waybill number, sorting center, scan method. Output: updated parcel/shipment and an appended event. The operation locks the waybill and rejects duplicate/foreign-provider scans. Manual lookup uses the same endpoint and service.

## Authorization invariants

- Buyers access only their carts, orders, payments, returns, disputes, and conversations.
- Sellers access records through their `seller_profile`/`store`; never trust a submitted seller ID.
- Logistics users access shipments whose `logistics_profile_id` belongs to them.
- Riders access only active assignments tied to their `rider_profile`.
- Admin actions record the acting user and reason in action/event/audit tables.

## API response baseline

Successful service-backed JSON endpoints return `{ "data": ..., "message": ... }`. Validation uses Laravel's standard `422` errors; unauthenticated is `401`, unauthorized is `403`, missing is `404`, and invalid state transition is `409`. Dates use ISO-8601 and money fields expose integer centavos plus optional formatted display values.
