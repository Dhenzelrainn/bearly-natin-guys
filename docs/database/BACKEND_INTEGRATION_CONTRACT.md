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
| Seller-approved business category and compliance classification | `categories`, `seller_profiles.approved_category_id`, `products.category_id` |
| Buyer marketplace category/subcategory | `marketplace_categories`, `marketplace_subcategories`, `marketplace_category_business_categories`, `products.marketplace_subcategory_id` |
| Seller Store section/collection | `store_subcategories`, `products.subcategory_id` |
| Stock history | `inventory_movements`; variant stock is the current cached balance |
| Buyer purchase | `orders`; one marketplace checkout may contain multiple `seller_orders` |
| Seller fulfillment | `seller_orders`, `order_items` |
| Payment attempt/result | `payments` |
| Physical delivery journey / current Logistics custodian | `shipments.logistics_profile_id` |
| Shipment's first Logistics custodian | `shipments.origin_logistics_profile_id` |
| Inter-city custody changes | `shipment_transfers` plus immutable `shipment_events` |
| Scannable shipping label | `waybills` |
| Physical package/piece | `parcels` |
| Immutable tracking history | `shipment_events` |
| Product moderation | `product_compliance_checks`, matches, `product_violations`, `violation_actions` |
| Advisory product-image/category consistency | `product_image_category_checks`; separate from text compliance and enforcement history |
| Return/refund | `return_requests`, `return_items`, `return_evidence`, `refunds` |
| Case management | `disputes`, participants, evidence, events |

## Registration identity integrity

- Valid account phone numbers are normalized to E.164 and dual-written to the transitional `users.phone` and `users.contact_number` columns. Both columns have unique database indexes; application validation checks both and provides the user-facing validation message. Null values remain allowed for accounts that do not have a phone on file. Unparseable legacy strings are preserved unchanged rather than silently rewriting account data; they are constrained only by their stored value and must not be treated as verified phone numbers.
- Rider plates are stored uppercase with surrounding whitespace removed and kept in sync between `users.plate_number` and `rider_profiles.plate_number`. Database unique indexes cover each storage location, including soft-deleted rows. Plate punctuation is retained; values that differ only by punctuation are not treated as equal.
- These unique indexes apply across roles for phone numbers and among Rider users/profiles for plates. Soft deletion does not release a phone number or plate for reuse.
- Additive identity migrations preflight existing values. They stop with affected user IDs if aliases disagree or valid phone/plate identities collide after normalization; they do not delete accounts or silently choose a winning record. Unparseable legacy phone values remain unchanged.

### Product variant images

- A variation-specific image belongs to its exact `product_variants.id` through `product_images.variant_id` and uses purpose `variant_sku`.
- A Seller-managed shared color image uses purpose `variant_color`, is attached to one representative `product_variants.id`, and applies to active variants with the same normalized Color/Colour option value.
- Existing image rows with purpose `variant` are legacy color-level images; keep them as a same-color fallback until a Seller replaces or removes them.
- Buyer variation selection prefers a shared `variant_color` image, then its exact SKU image, then a legacy same-color image, then the product's main image (primary when present). This ensures the selected color-gallery image is used consistently for every matching SKU.
- `order_items` snapshots the selected product image's disk and path at checkout so later catalog edits do not change order presentation. Retain an image file while either a catalog image or an order-item snapshot references its disk/path; older rows without a snapshot may resolve through their current product/variant relationships.
- `order_items` also snapshots the purchase-time marketplace category (`category_id_snapshot`, nullable FK to `categories` with delete SET NULL, and `category_name_snapshot`). Admin sales-by-category reporting groups by this snapshot and never substitutes the Product's current category. Legacy rows without a snapshot report as "Category not recorded"; deleting a category clears the id reference while the name snapshot remains readable.

## Seller pricing and campaigns

- Seller Product Discounts and Store Vouchers are `store_promotions` with types
  `product_discount` and `store_voucher`. Store Voucher and minimum-spend amounts
  use integer centavos. Promotion schedules combine `starts_on`/`ends_on` with
  `starts_time`/`ends_time` in the `Asia/Manila` timezone. Existing date-only
  records default to midnight starts and 11:59:59 PM ends; newly selected end
  times include the selected minute. New voucher creation accepts
  `store_voucher` only; existing `product_discount` rows remain readable.
- Store Voucher names may be generated from their offer when the Seller leaves
  the optional internal label blank. Product Discounts retain a Seller-only
  internal name and may carry a separate optional `buyer_campaign_name`; show
  that label only while the corresponding Product Discount is active. Buyer
  pricing and checkout must continue to resolve from the authoritative promotion
  window, not from display metadata.
- Product Discounts are Seller-funded scheduled pricing offers. An active
  Product Discount replaces (does not stack with) the regular Seller discount.
  Reject overlapping scheduled/active Product Discounts for the same Store
  product/SKU. Store Vouchers remain checkout discounts and use the existing
  claim, redemption, cancellation, and refund restoration workflow.
- Bearly Campaign events reuse `platform_promotions`; do not create a parallel
  campaign engine. Campaign-specific event fields are additive. Seller opt-ins
  live in `platform_campaign_participations` and selected product/SKU pricing,
  stock, and per-Buyer limits live in `platform_campaign_participation_items`.
- Campaign events use `draft`, `open`, `active`, `paused`, and `ended`; Seller
  participations use `pending_review`, `approved`, `rejected`, and `withdrawn`.
  Join, review, status, and price-affecting checkout operations are transactional
  and lock the campaign/participation/item rows in a consistent order.
- New campaigns require an Admin-configured `minimum_discount_percent` from 1
  through 90. The nullable column is reserved for legacy campaigns without a
  configured minimum. Seller submissions provide a whole discount percentage
  per product; the server resolves the effective pre-campaign Seller price for
  every eligible active SKU, computes the campaign price in integer centavos,
  and snapshots the baseline, percentage, and computed price. Admin may add or
  raise a minimum on an existing campaign only when every pending/approved
  participation item already meets it; existing submitted prices are never
  rewritten. Admin activation remains required before Buyer pricing is live.
  At checkout, a configured minimum is rechecked against the current effective
  Seller price; campaign pricing is skipped if a Seller price change makes the
  discount fall below the minimum. For Bearly-funded campaigns, pricing is also
  skipped if the effective Seller price has risen above the submitted baseline,
  preventing an unapproved subsidy increase. Legacy campaigns without a
  configured minimum retain their existing behavior.
- Campaign funding is exactly `seller` or `platform`; shared funding is not
  supported. New Admin-created Bearly campaigns are `platform`-funded and
  require a positive `budget_minor`; Admin cannot create uncapped or
  Seller-funded campaign events. Existing uncapped/Seller-funded campaign rows
  remain readable, and legacy Seller-funded rows remain editable without
  changing their funding or Seller-proceeds behavior. Seller-managed Product
  Discounts and Store Vouchers remain independent Seller promotions. Campaign
  funding is immutable after creation. Seller-funded campaign prices reduce
  Seller proceeds. A
  platform-funded price difference is recorded in separate
  `campaign_platform_discount_minor` snapshots on Orders, Seller Orders, and
  Order Items and does not reduce the Seller earnings/commission basis.
- Campaign pricing does not stack: use the best eligible effective price. The
  campaign price is already reflected in the Buyer item price/subtotal; do not
  subtract the same campaign difference a second time from checkout totals.
  Buyer campaign pricing requires an approved participation and an active,
  public campaign within its configured schedule.
  Revalidate campaign dates, approval, stock limits, per-Buyer limits, and
  Bearly budget under checkout locks. Order Item prices and discounts remain
  immutable snapshots if campaign terms later change.
- Campaign banners are stored on Laravel disks; database rows store disk/path
  metadata, not file contents.

## Required status vocabulary

Use constants or PHP backed enums in application code, while columns remain strings for deployability.

| Domain | Allowed initial vocabulary |
|---|---|
| Account | `pending`, `active`, `needs_revision`, `rejected`, `suspended`, `deactivated` |
| Application | `draft`, `submitted`, `under_review`, `needs_revision`, `approved`, `rejected`, `withdrawn` |
| Product | `draft`, `pending_review`, `active`, `flagged`, `blocked`, `archived` |
| Compliance check | `queued`, `running`, `passed`, `flagged`, `blocked`, `failed` |
| Advisory product-image/category check | `pending`, `match`, `possible_mismatch`, `uncertain`, `unavailable` |
| Violation | `flagged`, `under_review`, `confirmed`, `dismissed`, `corrective_action`, `resolved` |
| Order | `pending_payment`, `paid`, `processing`, `partially_fulfilled`, `fulfilled`, `cancelled`, `closed` |
| Seller order | `pending`, `accepted`, `packing`, `ready_for_pickup`, `handed_to_logistics`, `completed`, `cancelled` |
| Payment | `pending`, `authorized`, `paid`, `failed`, `cancelled`, `partially_refunded`, `refunded` |
| Shipment | `pending`, `ready_for_pickup`, `pickup_assigned`, `picked_up`, `at_sorting_center`, `handoff_pending`, `sorted`, `dispatched`, `out_for_delivery`, `delivered`, `delivery_failed`, `return_to_sender`, `cancelled` |
| Parcel | `created`, `picked_up`, `received`, `in_transit`, `sorted`, `dispatched`, `out_for_delivery`, `delivered`, `failed`, `returned`, `lost`, `damaged` |
| Pickup | `requested`, `scheduled`, `assigned`, `in_progress`, `completed`, `cancelled`, `failed` |
| Assignment | `assigned`, `accepted`, `en_route`, `completed`, `declined`, `cancelled` |
| Dispatch | `draft`, `loading`, `dispatched`, `completed`, `cancelled` |
| Announcement | `draft`, `scheduled`, `published`, `archived` |
| Return | `awaiting_seller`, `under_review`, `awaiting_evidence`, `return_pickup_requested`, `return_in_transit`, `awaiting_inspection`, `refund_pending`, `resolved`, `rejected` |
| Refund | `pending`, `processing`, `completed`, `failed`, `cancelled` |
| COD cash custody | `collected`, `disputed`, `received_by_logistics`, `remitted_to_finance`, `reconciled` |
| Refund cash custody | `awaiting_logistics_receipt`, `awaiting_rider_assignment`, `assigned_to_rider`, `cash_with_rider`, `out_for_delivery`, `cash_return_pending`, `logistics_return_verification`, `delivered_pending_verification`, `awaiting_finance_completion`, `completed` |
| Dispute | `open`, `under_review`, `awaiting_response`, `escalated`, `resolved`, `closed` |
| Commission/transaction/earning | `pending`, `posted`, `available`, `held`, `processing`, `paid`, `reversed`, `cancelled` |
| Shipping settlement | `pending`, `remitted` |
| Seller refund recovery | `pending`, `partially_recovered`, `recovered` |
| Seller bank account | `active`, `inactive` |
| Seller withdrawal | `pending_review`, `approved`, `processing`, `completed`, `rejected`, `failed` |
| Seller withdrawal transfer | `pending_verification`, `verified`, `failed` |
| Bearly campaign | `draft`, `open`, `active`, `paused`, `ended` |
| Campaign participation | `pending_review`, `approved`, `rejected`, `withdrawn` |
| Marketplace subcategory request | `pending_review`, `approved`, `rejected` |

Do not add synonymous values (for example both `shipped` and `dispatched`) without updating this contract and every consumer.

### Announcement retention

- New announcements do not require or set an expiration date. The legacy nullable
  `announcements.expires_at` column remains for existing rows and legacy delivery
  backfill behavior; it does not hide a published announcement or trigger
  deletion.
- Keep at most 10 announcements in `published` status. When the limit is
  exceeded, archive the oldest published announcements with
  `archive_reason = retention`; preserve their rows, audience roles, audit
  history, and media. A retention-archived announcement remains readable to its
  original active audience and in paginated announcement history, but is not
  sent as a new feed/realtime announcement.
- Admin-initiated archives use `archive_reason = manual` and remain hidden from
  recipients. Existing archived rows with a null archive reason retain this
  hidden/manual behavior. Automatic retention must never hard-delete
  announcements or media.

## Marketplace taxonomy

- `categories` and `products.category_id` retain the Seller-approved business/compliance meaning. Buyer navigation is persisted separately in `marketplace_categories`; the many-to-many mapping table only identifies which Buyer branches Sellers in an approved business category may classify into.
- `categories.is_registration_option` marks the exact 12 active Buyer-label Category rows selectable for new Seller registrations. Registration submits a Category ID, validates that it is active and mapped to its matching Buyer root, and persists that ID to `account_applications.business_category_id`; `users.business_category` remains a database-derived display/legacy text mirror. Other legacy Category rows remain active and non-selectable. Preserve existing legacy mappings; do not infer aliases. Historical application/profile/product IDs are not rewritten.
- `products.marketplace_subcategory_id` is nullable and points to one canonical Buyer subcategory. It is separate from both `products.category_id` and `products.subcategory_id`; no automatic legacy text-matching/backfill is allowed.
- A product may have at most one marketplace subcategory. Active Seller choices must be mapped to the authenticated Seller Profile's active `approved_category_id`; never accept a submitted business-category ID as authority. Fashion and Apparel can map to Women’s or Men’s Apparel, but each product receives exactly one branch.
- Buyer category pages use canonical IDs/slugs, show only active subcategories that have Buyer-visible products, and may include unassigned legacy products in All items only for one-to-one business-category mappings. Ambiguous Fashion products remain out of both Buyer branches until classified.
- Seller-created `store_subcategories` remain optional Store-only sections. They must never populate global Buyer category navigation or be used to filter products across stores.
- Seller requests persist a server-derived business category, suggested name/reason, lowercase lifecycle status, reviewer user ID, decision timestamp/reason, and the approved/reused canonical IDs. Admin decisions and taxonomy edits retain audit actor/history. Used entries are deactivated rather than hard-deleted.

## Service contracts

### Product compliance

Input: persisted `Product`, scan trigger (`create`, `update`, `manual`, `report`), and an optional authenticated actor for recheck audit history.

Output: `ProductComplianceCheck` with zero or more matches and violations. Active rules scan product names/descriptions; category matching uses configured term rules, not image recognition or an inferred category model. Critical prohibited rules block/hide the product; high-severity rules hold it for review. Actionable automated violations and active in-app `seller_warnings` are deduplicated per product/rule. A later clean scan resolves automated violations/warnings and records a `violation_actions` history entry; an unchanged Admin-dismissed false positive is not reopened. Flagged/blocked products are not buyer-visible. Admin decisions require a reason and append `violation_actions` transactionally. Supported decisions are `dismiss`, `confirm`, `request_correction`, `remove_product`, and `warn_seller`; removal blocks the listing without deleting its product record, and dismissal preserves false-positive history. A final decision cannot be applied twice to the same violation. Seller account suspension/escalation is a separate workflow and is not implied by product moderation.

### Advisory product-image/category consistency

- `product_image_category_checks` is separate from text compliance checks, matches, violations, and Seller warnings. It stores image SHA-256 and listing/category snapshots plus provider/model/prompt version and short validated output; it never stores image bytes or raw API payloads.
- The asynchronous primary-image check runs only after the Seller product-save transaction commits. It is disabled by default and must remain disabled until the Project Owner accepts the applicable Gemini data-use terms. Provider failure is `unavailable`, never `match`; stale image/listing results are not current.
- `match`, `possible_mismatch`, and `uncertain` are advisory observations only. They never block/hide products, change product or compliance status, create violations, or issue Seller warnings. Admin remains solely responsible for any audited manual category-review action.

### Checkout

Input: buyer, Buyer-owned persisted cart items, selected address, COD payment method, and a request key. Output: one `Order`, one `SellerOrder` per store, immutable `OrderItem` snapshots, and a pending `Payment`. Creation must be one database transaction and idempotent using a request key. Placement must reserve stock without reducing `stock_on_hand`; eligible cancellation releases the reservation and appends an `inventory_movements` record.

### Seller Order workflow

- Seller-controlled transitions are `pending → accepted → packing → ready_for_pickup`. The packing checklist is a UI milestone within `packing`, not a persisted `packed` status.
- Every transition appends a `seller_order_status_histories` record with the previous/next status, actor, occurrence time, and reason. The authenticated Seller must own the Seller Order's Store.
- Seller cancellation is allowed only while that Seller Order is `pending`, requires a reason, and atomically releases its reservations with inventory movement history. For a multi-Store COD order, recompute marketplace totals and pending payment amount from the remaining non-cancelled Seller Orders; cancel the marketplace Order/payment only when none remain.
- Lock the marketplace `Order` and its `SellerOrder` rows during transitions; lock affected variants when releasing reservations. Waybills, pickup scheduling, and logistics handoff are separate workflows and must not be implied by `ready_for_pickup`.
- Buyer receipt confirmation moves every non-cancelled Seller Order of a fully delivered marketplace Order to `completed` with a buyer-attributed history record and marks the marketplace Order `fulfilled`. Buyer confirmation is not COD collection evidence and must not mark a COD payment paid. A COD payment becomes `paid` only when every active outbound COD shipment has a Rider-recorded collection exactly equal to its COD amount due and the collection totals equal the marketplace payment. Admin payment confirmation must not bypass this rule. Confirmation is idempotent and never completes a cancelled order. `delivered` without Buyer confirmation must not be presented as `completed`.

### COD cash custody, Seller Wallet, and legacy settlements

- Record Rider-reported cash for each outbound COD shipment in `cod_cash_collections`, including the amount due, actual amount, signed difference, assigned Rider, owning Logistics provider, and collection time. A mismatch is preserved as `disputed`; it must not capture the marketplace payment or qualify Seller proceeds for settlement.
- The cash custody sequence is Rider collection → the same Logistics provider acknowledges the exact Rider-recorded amount → Logistics physically hands off and records the exact cash amount → Admin/Finance physically counts and confirms the exact remitted amount. On acknowledgment, Bearly derives a unique `CODH-` Rider-to-Logistics reference from the collection number. On new Logistics remittances, Bearly derives a distinct `L2F-` Logistics-to-Finance reference from that same unique collection number; existing remittance-reference values are preserved. On new Finance reconciliation, Bearly generates a `BFR-<year>-<collection-id>` internal Finance cash receipt number, stores it in `finance_reference`, and offers a print/save-as-PDF view on Admin Seller Payments. Retrying the same reconciliation is idempotent and must not create a second event or receipt number. Existing Finance references are preserved. The printed Bearly record is not a BIR/tax invoice or bank deposit receipt. Store the three custody references separately (`logistics_reference`, `finance_remittance_reference`, and `finance_reference`). Every state transition appends a `cod_cash_collection_events` row with actor, amount, reference, and occurrence time; receipt views are Admin-only and audited.
- Each positive posted Seller sale transaction not already linked to a legacy `seller_payout_items` row receives one immutable `sale_earning_pending` wallet-ledger entry. For new `zone_based_v1` quotes only, the commission base and Seller sale proceeds exclude the original shipping fee; commission remains at the configured merchandise rate. `SellerOrder.total_minor`, Buyer totals, and COD due retain the full original shipping amount. Existing `flat_per_seller_order` and historical commission/proceeds snapshots are never rewritten. Wallet counters are integer centavos and must reconcile to append-only movement deltas and balance-after snapshots; lock the Seller wallet for every movement. Duplicate posting/release must not create a second credit.
- Pending sale earnings become Available only after the Seller Order is completed, the original payment has `paid_at` and status `paid`, `partially_refunded`, or `refunded`, the three-calendar-day return window has ended, and no return/refund case or refund payout remains open or processing. The release transaction locks the marketplace Order and Seller Order before current reads of case/refund state so a return request cannot race the eligibility check. For COD, every associated outbound shipment with a positive COD amount must have a Finance-reconciled collection whose `amount_reconciled_minor` exactly matches that shipment's COD amount. Finance reconciliation alone never releases earnings. `CODH-`, `L2F-`, and `BFR-` remain distinct cash-custody references and are never used as Seller bank-transfer references.
- Seller refund recoveries remain separate receivables. A closed refund case does not create an automatic wallet offset; recovery is recorded and collected through its separate recovery workflow. Existing `seller_payouts` and `seller_payout_items` remain auditable legacy records; wallet crediting excludes linked transactions. Historical paid payouts are represented in lifetime-withdrawn wallet history without crediting their sale transactions again. Legacy pending batches may only be completed after Finance verifies the external bank transfer; no new Seller payout batches are generated for wallet-ledger earnings.
- Completing a legacy bank-transfer payout requires Finance to record the Seller's `receiving_bank_name` alongside the transfer reference and proof. Store that destination bank name on the payout and in its audit values; never use the field for an account number or COD custody reference.
- Bank account numbers are encrypted at rest and masked to their last four digits in Seller lists. The owning active Seller and authorized Admin/Finance review may access the full number; access is restricted, and full account numbers are never copied into audit values. A withdrawal request stores bank/account snapshots, a Seller-scoped idempotency key, and an atomic reservation of Available funds. It creates status history, audit records, and a Seller notification.
- Finance manually approves/rejects requests, starts processing, records the exact external bank transfer amount/date/reference and optional private proof, then independently verifies success with a note before completion. A reference alone is not proof. Only one transfer record is allowed per withdrawal. Rejection or a Finance-confirmed unsuccessful transfer releases the reservation exactly once; a verified transfer moves it to lifetime-withdrawn exactly once. Bearly does not initiate bank or GCash payouts, hand cash directly, or route Seller payouts through Riders. Proof is stored on a private Laravel disk; Finance may access it during review, while the owning Seller may access it only after verified completion. Every proof access is audited.
- Seller My Earnings and Transaction History are read models of the authenticated Seller's `seller_transactions`, `platform_commissions`, `seller_payouts`, and wallet ledger; all queries are scoped through the authenticated `seller_profile_id`. A legacy payout proof is visible/downloadable by that Seller only after the payout is paid and the private receipt exists; access is audited. Order-item images use the immutable checkout image snapshot where present, with product image fallback; subcategory is display metadata and never changes ledger amounts.

### COD cash refunds

- A refund requires an actually paid COD payment (`paid` or `partially_refunded` with `paid_at`), completed Seller response/Admin responsibility decision, and Seller inspection for physical returns. Unpaid or cancelled orders have no cash refund.
- Finance records an exact cash handoff to the original outbound Logistics provider. Bearly generates a unique `CFH-` handoff reference when Finance confirms the physical handoff; the same reference is retained on the payout, event history, and Logistics workspace. That provider acknowledges receipt and assigns an active, approved Rider belonging to it. Rider receipt, delivery attempt, failure, return of the full amount, Logistics return confirmation, and Buyer receipt verification are distinct audited `refund_cash_payout_events` transitions.
- When Logistics assigns a Rider to a Buyer cash refund, Bearly generates an `ACK-<payout_no>-A<n>` acknowledgment reference, stores it on the payout, and appends it to the assignment event. Logistics prints the Bearly-branded acknowledgment form or saves it as PDF and hands the paper form to the Rider with the cash before dispatch. The Rider confirms the form's reference against the active assignment when acknowledging cash receipt; the event records that reference. The Buyer signs only after receiving the full amount; the Rider uploads the signed proof privately on a Laravel disk. Failed-attempt events retain that attempt's reference, and reassignment after the full cash is returned generates a new reference. Logistics verifies the displayed reference against the signed proof; Admin/Finance completes the refund only after Logistics verification and records a reason. A Rider must never personally fund a refund. Seller liability/recovery remains separate from payout custody and no automatic Seller-payout offset is permitted.

### Product reviews

- A Buyer may review an order item only when its Seller Order is `completed` and the marketplace Order belongs to them. Each order item carries at most one review; resubmission updates the same row (replacing the photo and deleting the superseded file) instead of duplicating it.
- Reviews store a 1–5 rating, a comment of up to 500 characters, and an optional photo (JPG/PNG/WebP, max 2 MB) on Laravel disks; rows store metadata/path, not binary content.
- The owning Store may post one public response of up to 400 characters per review; cross-store replies are forbidden.
- Public surfaces expose only `is_visible` reviews with masked buyer names. The Seller feedback list shows full buyer identity and is scoped to the authenticated Seller's Store.

### Zone-based Buyer shipping and quote snapshots

- New or edited SKUs require packaged weight and L×W×H; weight and each package dimension are stored to three decimal places. Legacy SKUs with null package data cannot be quoted.
- Quote one combined parcel per `SellerOrder` using the deterministic `greedy_minimum_bounding_box_v1` estimate. Billable weight is `ceil(max(actual_weight, parcel_volume / divisor))`, with a one-kilogram minimum; package measurements remain in the quote for delivery planning.
- New checkout quotes price one fee per combined `SellerOrder` shipment, never per product, quantity, or parcel. `zone_based_v1` defaults to a 5000-centavo base plus 500 centavos per zone level: zone 0 same municipality ₱50; zone 1 different municipality/same province ₱55; zone 2 different province/same region ₱60; zone 3 different region/same island group ₱65; zone 4 different island groups ₱70. Billable weight does not change the Buyer fee.
- Zone classification uses the exact persisted 10-digit PSGC city code, the validated PSGC province-parent code when available, the versioned `resources/data/psgc-shipping-zones.json` province/region map, and its region-to-island-group mapping. The snapshot is sourced from PSGC Cloud `/regions` and `/provinces` data; island-group assignment is a versioned Bearly shipping classification. City/province display names are never used as geographic identity. Missing, unknown, mismatched, or same-region locations without a reliable province parent fail validation; no GPS, routing, or name-only fallback is permitted.
- Admin edits the existing shipping settings: `system_settings.shipping.base_fee_minor`, `shipping.zone_surcharge_per_level_minor`, and `shipping.volumetric_divisor`. The initial base falls back to the old `shipping.seller_order_fee_minor` value if present, then to the 5000-centavo default; new Admin saves write the zone settings and leave legacy configuration/history intact. Changes include an audit actor, reason, and before/after values. Marketplace shipping is the sum of independently priced Seller Orders.
- The volumetric divisor defaults to 5000 and changes only the informational billable-weight estimate. Former `shipping_rates` rows remain legacy and do not price new quotes.
- Calculate the original zone fee before eligible vouchers. Preserve original fee, Buyer-paid shipping, platform shipping subsidy, funding source, zone level, base/surcharge, and canonical location inputs on each `SellerOrder` (`shipping_fee_minor`, `buyer_paid_shipping_fee_minor`, `platform_shipping_discount_minor`, `shipping_funding_source`, and `shipping_quote`). A platform shipping voucher is Bearly-funded and does not reduce the Logistics/Rider allocation. Preserve Store Voucher plus platform shipping-voucher stacking and existing eligibility/caps; Buyer-paid fee plus Bearly subsidy must equal the original fee and never be negative.
- The Buyer quote is integrity-protected and expires after ten minutes. At placement, revalidate the cart, address identifiers, item snapshots, package estimate, zone inputs, current fee settings, and voucher allocations against the accepted quote; changed terms require a refreshed quote. Persist the applied provider, pricing rule, quote inputs, and immutable fee snapshot on each `SellerOrder`. Admin setting changes never rewrite confirmed orders.

### Shipping settlement and Rider payout

- A delivered, completed `zone_based_v1` Seller Order creates at most one `shipping_settlements` row and one linked Rider earning after the shared Seller Wallet eligibility gate passes: payment is paid, the Seller Order is completed, the three-calendar-day return window elapsed, no return/refund remains open or processing, and every positive COD shipment is exactly Finance-reconciled. Failed, cancelled, returned, and undelivered work is not eligible.
- Split the original shipping fee in integer centavos: Logistics receives `floor(fee × 40 / 100)` and the Rider pool receives the exact remainder. The shares always sum to the original fee. The 40% recipient is the `shipments.logistics_profile_id` current custodian at delivery; the 60% pool goes only to the Rider whose completion made the whole shipment delivered, even when earlier parcels were assigned to other Riders.
- Admin/Finance records the actual exact remittance of Logistics 40% plus Rider 60% to that provider, with method, reference, date, actor, reason, and an audit log. This remittance is only the shipping allocation; it is never the full COD/product cash. COD custody remains exclusively in the existing Rider → Logistics → Finance `cod_cash_collections` workflow.
- Rider earnings use `pending` until Finance remits to Logistics, `posted` after that remittance, and `paid` only after the owning Logistics provider records the actual Rider payout. `rider_earning_payouts` is unique per earning and stores exact amount, method, reference, paid date, recording actor, and reason; Logistics can access and pay only its own Riders/records. Retry paths must not duplicate settlements, earnings, remittances, or Rider payouts.

### Shipment and waybill creation

Input: fulfillable `SellerOrder`, logistics provider, origin/destination snapshots, parcel dimensions and weight. Output: `Shipment`, unique `Waybill` number plus unguessable scan token, one or more `Parcel` rows, and initial event. A waybill must never be created from arbitrary manually entered buyer/order data.

### Parcel intake scan

Input: authenticated logistics user, scan token or waybill number, sorting center, scan method. Output: updated parcel/shipment and an appended event. The operation locks the waybill and rejects duplicate/foreign-provider scans. Manual lookup uses the same endpoint and service.

### Barangay delivery zones

- `sorting_zones.destination_rules` stores `province`, `city_municipality`, `psgc_city_code`, and a `barangays` string list. PSGC city code is authoritative for city identity when present; barangays continue to match by normalized name. Each Logistics profile has exactly one Sorting Center, and each active zone belongs to that Center; active barangay coverage must not overlap across zones in the Center.
- Logistics creates zones from the official province → municipality/city → barangay selector. The account's single Sorting Center is assigned automatically; clients cannot choose a center. Zone codes are generated from the municipality and a per-center sequence; zone names use the municipality and first covered barangay.
- Logistics may edit coverage or archive an owned zone in the Sorting Center UI. Archiving sets `status=inactive` and `deleted_at`; restore clears `deleted_at`, activates the zone, and rechecks for overlapping active coverage. Coverage changes and archiving are rejected while the zone has non-final parcels or an active dispatch batch. Rider center/zone assignments are automatically recalculated after a coverage change, archive, or restore. Archived zone codes remain reserved and are not reused.
- Permanent deletion is available only for archived zones and is blocked while non-final parcels or active dispatch batches still use the zone. It permanently removes the zone row while retaining parcel, dispatch, and shipment-event records; their nullable zone foreign keys are set to `NULL`. In particular, `dispatch_batches.sorting_zone_id` uses `ON DELETE SET NULL` so historical batches survive.
- Parcel sorting matches province, municipality/city, and barangay against the zone coverage (case-, punctuation-, and accent-insensitive). Outbound parcels use the immutable marketplace Order destination snapshot; return parcels use their reverse Shipment destination address. A parcel with no matching active zone cannot be sorted into a zone.
- Dispatch includes only ready parcels whose destination matches the zone. A parcel assigned to a different or uncovered zone remains undispatched until corrected. The selected Rider must belong to the Logistics provider and Sorting Center and be assigned to that exact zone.

### City-scoped Logistics and Rider assignment

- `logistics_profiles.psgc_city_code` is the authoritative municipality/city for a Logistics account, sourced from its registered Sorting Center/application address and uniquely owned by one profile (including inactive/soft-deleted profiles). Registration and approval must preserve the city; a profile cannot silently move to a different city. Each city has at most one Logistics profile, and each profile has exactly one Sorting Center.
- Sorting Zones are limited to the owning Logistics city and belong to its single Sorting Center. Zone creation uses that center automatically; no center selector or client-supplied `sorting_center_id` is accepted.
- Rider registration must select a Logistics sponsor serving the Rider's registered PSGC city. Approval and later refresh derive `home_sorting_center_id` and `current_zone_id` from the Rider's Home address and matching active barangay coverage. Unmatched Riders remain unassigned and cannot receive dispatch; Logistics cannot manually override the city/barangay match.
- New checkout snapshots the validated delivery `psgc_city_code` onto `orders`; outbound shipment snapshots it to `shipments.destination_city_code`. Return shipments snapshot the Seller pickup address city code. Text address fields remain immutable/display context but do not decide which Logistics profile owns last-mile delivery.

### Inter-Logistics shipment handoff

- `shipments.logistics_profile_id` is the current custodian; `origin_logistics_profile_id` preserves the first provider for audit and historical workflows. Each outbound or reverse shipment can have at most one direct city handoff in `shipment_transfers`.
- The current Logistics custodian may send a shipment only after every parcel is received at one of its active Sorting Centers and before sorting/dispatch. The target is resolved server-side from the immutable destination city code; clients cannot submit a destination provider.
- Sending creates one `shipment_transfers` row in `sent`, changes shipment state to `handoff_pending` and parcels to `in_transit`, and appends one `logistics_handoff_sent` shipment event per parcel. The origin remains current custodian until acceptance and cannot sort or dispatch in-transit parcels.
- The destination Logistics account scans the waybill; its one active Sorting Center is resolved automatically from the authenticated account. Acceptance locks and revalidates the pending transfer, destination city, custodian, parcel states, and center ownership; it atomically changes current custody, records the accepting actor/center/time, returns parcels to `received`, clears prior zone assignment, and appends `logistics_handoff_accepted` events. Repeated, foreign, wrong-city, and invalid-state operations must not duplicate events or custody changes.
- Cross-city physical returns are collected by the Logistics account serving the Buyer/order city, then use the same handoff workflow to reach the Seller pickup-address city account before local sorting and delivery. COD collection remains owned by the Rider and current Logistics custodian who completed outbound delivery; the immutable origin provider is retained separately.

## Authorization invariants

- Buyers access only their carts, orders, payments, returns, disputes, and conversations.
- Sellers access records through their `seller_profile`/`store`; never trust a submitted seller ID.
- Logistics users access shipments whose current `logistics_profile_id` belongs to them; only the recorded destination account may accept a pending handoff. Historical origin-provider data does not grant current custody access.
- Riders access only active assignments tied to their `rider_profile`.
- Admin actions record the acting user and reason in action/event/audit tables.

## Returns and refunds

- A Buyer may request a return/refund within three calendar days after the Seller Order's recorded completion time. Requests select immutable order-item quantities; the requested value is calculated from saved item and Seller discount snapshots and cannot exceed the remaining paid order value.
- A case moves `awaiting_seller → under_review` only after the Seller submits a response and supporting evidence. Admin may then reject, request Buyer/Seller evidence, or approve; only Admin decides the claim.
- A physical return is authorized with a zero-fee reverse shipment and pickup request assigned to the active Logistics provider serving the immutable Buyer/order city. Do not select an arbitrary provider or fall back to another provider if it is inactive; block approval until that provider is active. The return destination is the Seller pickup-address city; if different, the Buyer-city provider accepts the pickup and sends the received parcel through the audited inter-Logistics handoff to the Seller-city provider, which completes local sorting/delivery. Return parcel scans use the provider-scoped pickup, intake, sorting, dispatch, and Rider lifecycle; outbound Buyer tracking excludes return-purpose shipments.
- Physical-return refunds remain pending until the Seller records inspection for every returned item. Only items marked sellable and restock are added to stock, with an `inventory_movements` record. Other inspected units are quarantined or disposed, not added to stock.
- Admin approval records a required financial-responsibility party (`seller` or `platform`) and evidence-based reason; this is separate from who sends the money. Admin is always responsible for sending the Buyer refund externally.
- One approved refund is one exact, full payout. Completion requires an explicit Admin confirmation that the external payout was already made, persists the actual payout amount (equal to the approved amount), method/reference/date, and a private receipt or Buyer acknowledgment. Bearly does not initiate a transfer. Completion is transaction-safe, idempotent, and bounded by captured payment.
- For Seller-attributable refunds, completion reverses the proportional platform commission and creates a separate Seller recoverable balance for net proceeds. It is not an automatic deduction from Seller settlements. Platform-attributable refunds create no Seller recovery or commission reversal.
- A Seller recovery may be recorded only after Admin verifies an external collection or an offset already applied to a paid Seller payout. Recovery events retain the actor, amount, date, reference, method, and optional paid-payout link; they do not change a Seller payout or move funds.
- Return evidence is private on the local Laravel disk. Buyer, Store-owning Seller, and Admin downloads require authenticated, role-scoped routes; state changes retain actor, reason, and case event/audit history.
- External payout receipts are private on the local Laravel disk and available only to the owning Buyer and Admin through audited downloads. Seller views show the finalized payout details and any outstanding/recovered balance, but not the Buyer’s receipt file.

## API response baseline

Successful service-backed JSON endpoints return `{ "data": ..., "message": ... }`. Validation uses Laravel's standard `422` errors; unauthenticated is `401`, unauthorized is `403`, missing is `404`, and invalid state transition is `409`. Dates use ISO-8601 and money fields expose integer centavos plus optional formatted display values.
