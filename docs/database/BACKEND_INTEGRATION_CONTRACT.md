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
- A Seller-managed shared color image uses purpose `variant_color`, is attached to one representative `product_variants.id`, and applies to active variants with the same normalized Color/Colour option value.
- Existing image rows with purpose `variant` are legacy color-level images; keep them as a same-color fallback until a Seller replaces or removes them.
- Buyer variation selection prefers a shared `variant_color` image, then its exact SKU image, then a legacy same-color image, then the product's main image (primary when present). This ensures the selected color-gallery image is used consistently for every matching SKU.
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
| Return | `awaiting_seller`, `under_review`, `awaiting_evidence`, `return_pickup_requested`, `return_in_transit`, `awaiting_inspection`, `refund_pending`, `resolved`, `rejected` |
| Refund | `pending`, `processing`, `completed`, `failed`, `cancelled` |
| COD cash custody | `collected`, `disputed`, `received_by_logistics`, `remitted_to_finance`, `reconciled` |
| Refund cash custody | `awaiting_logistics_receipt`, `awaiting_rider_assignment`, `assigned_to_rider`, `cash_with_rider`, `out_for_delivery`, `cash_return_pending`, `logistics_return_verification`, `delivered_pending_verification`, `awaiting_finance_completion`, `completed` |
| Dispute | `open`, `under_review`, `awaiting_response`, `escalated`, `resolved`, `closed` |
| Commission/transaction/earning | `pending`, `available`, `held`, `processing`, `paid`, `reversed`, `cancelled` |
| Seller refund recovery | `pending`, `partially_recovered`, `recovered` |

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
- Buyer receipt confirmation moves every non-cancelled Seller Order of a fully delivered marketplace Order to `completed` with a buyer-attributed history record and marks the marketplace Order `fulfilled`. Buyer confirmation is not COD collection evidence and must not mark a COD payment paid. A COD payment becomes `paid` only when every active outbound COD shipment has a Rider-recorded collection exactly equal to its COD amount due and the collection totals equal the marketplace payment. Admin payment confirmation must not bypass this rule. Confirmation is idempotent and never completes a cancelled order. `delivered` without Buyer confirmation must not be presented as `completed`.

### COD cash custody and Seller settlement

- Record Rider-reported cash for each outbound COD shipment in `cod_cash_collections`, including the amount due, actual amount, signed difference, assigned Rider, owning Logistics provider, and collection time. A mismatch is preserved as `disputed`; it must not capture the marketplace payment or qualify Seller proceeds for settlement.
- The cash custody sequence is Rider collection → the same Logistics provider acknowledges the exact Rider-recorded amount → Logistics records remittance to Finance → Admin/Finance reconciles the exact remitted amount. Every transition appends a `cod_cash_collection_events` row with actor, amount, reference, and occurrence time.
- Seller settlement eligibility requires a completed Seller Order, a paid COD marketplace payment, and Finance-reconciled cash for every associated outbound COD shipment. Settlement generation links the exact posted Seller transactions through `seller_payout_items` and creates only a pending accounting record.
- Recording a Seller settlement as paid requires an exact amount, actual external-payment confirmation, method/reference/date, private receipt or Seller acknowledgment, and an audit record. Bearly does not initiate the transfer. Seller refund recovery remains a separate receivable and is never automatically deducted from pending Seller payouts.

### COD cash refunds

- A refund requires an actually paid COD payment (`paid` or `partially_refunded` with `paid_at`), completed Seller response/Admin responsibility decision, and Seller inspection for physical returns. Unpaid or cancelled orders have no cash refund.
- Finance records an exact cash handoff to the original outbound Logistics provider. Bearly generates a unique `CFH-` handoff reference when Finance confirms the physical handoff; the same reference is retained on the payout, event history, and Logistics workspace. That provider acknowledges receipt and assigns an active, approved Rider belonging to it. Rider receipt, delivery attempt, failure, return of the full amount, Logistics return confirmation, and Buyer receipt verification are distinct audited `refund_cash_payout_events` transitions.
- When Logistics assigns a Rider to a Buyer cash refund, Bearly generates an `ACK-<payout_no>-A<n>` acknowledgment reference, stores it on the payout, and appends it to the assignment event. Logistics prints the Bearly-branded acknowledgment form or saves it as PDF and hands the paper form to the Rider with the cash before dispatch. The Rider confirms the form's reference against the active assignment when acknowledging cash receipt; the event records that reference. The Buyer signs only after receiving the full amount; the Rider uploads the signed proof privately on a Laravel disk. Failed-attempt events retain that attempt's reference, and reassignment after the full cash is returned generates a new reference. Logistics verifies the displayed reference against the signed proof; Admin/Finance completes the refund only after Logistics verification and records a reason. A Rider must never personally fund a refund. Seller liability/recovery remains separate from payout custody and no automatic Seller-payout offset is permitted.

### Product reviews

- A Buyer may review an order item only when its Seller Order is `completed` and the marketplace Order belongs to them. Each order item carries at most one review; resubmission updates the same row (replacing the photo and deleting the superseded file) instead of duplicating it.
- Reviews store a 1–5 rating, a comment of up to 500 characters, and an optional photo (JPG/PNG/WebP, max 2 MB) on Laravel disks; rows store metadata/path, not binary content.
- The owning Store may post one public response of up to 400 characters per review; cross-store replies are forbidden.
- Public surfaces expose only `is_visible` reviews with masked buyer names. The Seller feedback list shows full buyer identity and is scoped to the authenticated Seller's Store.

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

## Returns and refunds

- A Buyer may request a return/refund within seven calendar days after the Seller Order's recorded completion time. Requests select immutable order-item quantities; the requested value is calculated from saved item and Seller discount snapshots and cannot exceed the remaining paid order value.
- A case moves `awaiting_seller → under_review` only after the Seller submits a response and supporting evidence. Admin may then reject, request Buyer/Seller evidence, or approve; only Admin decides the claim.
- A physical return is authorized with a zero-fee reverse shipment and pickup request assigned to the active Logistics provider recorded on the original fulfilled outbound shipment. Do not select an arbitrary provider or fall back to another provider if the original is inactive; block approval until that provider is active or an explicit reassignment workflow is completed. Return parcel scans use the existing provider-scoped pickup, intake, sorting, dispatch, and Rider delivery lifecycle; outbound Buyer tracking excludes return-purpose shipments.
- Physical-return refunds remain pending until the Seller records inspection for every returned item. Only items marked sellable and restock are added to stock, with an `inventory_movements` record. Other inspected units are quarantined or disposed, not added to stock.
- Admin approval records a required financial-responsibility party (`seller` or `platform`) and evidence-based reason; this is separate from who sends the money. Admin is always responsible for sending the Buyer refund externally.
- One approved refund is one exact, full payout. Completion requires an explicit Admin confirmation that the external payout was already made, persists the actual payout amount (equal to the approved amount), method/reference/date, and a private receipt or Buyer acknowledgment. Bearly does not initiate a transfer. Completion is transaction-safe, idempotent, and bounded by captured payment.
- For Seller-attributable refunds, completion reverses the proportional platform commission and creates a separate Seller recoverable balance for net proceeds. It is not an automatic deduction from Seller settlements. Platform-attributable refunds create no Seller recovery or commission reversal.
- A Seller recovery may be recorded only after Admin verifies an external collection or an offset already applied to a paid Seller payout. Recovery events retain the actor, amount, date, reference, method, and optional paid-payout link; they do not change a Seller payout or move funds.
- Return evidence is private on the local Laravel disk. Buyer, Store-owning Seller, and Admin downloads require authenticated, role-scoped routes; state changes retain actor, reason, and case event/audit history.
- External payout receipts are private on the local Laravel disk and available only to the owning Buyer and Admin through audited downloads. Seller views show the finalized payout details and any outstanding/recovered balance, but not the Buyer’s receipt file.

## API response baseline

Successful service-backed JSON endpoints return `{ "data": ..., "message": ... }`. Validation uses Laravel's standard `422` errors; unauthenticated is `401`, unauthorized is `403`, missing is `404`, and invalid state transition is `409`. Dates use ISO-8601 and money fields expose integer centavos plus optional formatted display values.
