# Bearly Database Schema Dictionary

> Generated from a clean MySQL migration. Laravel migrations remain the executable source of truth.

Conventions: money integers are centavos; timestamps are UTC at storage boundaries; workflow statuses use application strings rather than MySQL enums; nullable foreign keys represent optional actors or later assignments.

## `account_applications`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `application_no` | `varchar(30)` | NO | — | UNI |
| `user_id` | `bigint unsigned` | NO | — | MUL |
| `requested_role_id` | `bigint unsigned` | NO | — | MUL |
| `sponsor_logistics_profile_id` | `bigint unsigned` | YES | — | MUL |
| `business_name` | `varchar(160)` | YES | — |  |
| `business_category_id` | `bigint unsigned` | YES | — | MUL |
| `status` | `varchar(32)` | NO | draft | MUL |
| `submitted_at` | `timestamp` | YES | — |  |
| `review_started_at` | `timestamp` | YES | — |  |
| `decided_at` | `timestamp` | YES | — |  |
| `reviewed_by` | `bigint unsigned` | YES | — | MUL |
| `decision_reason` | `text` | YES | — |  |
| `revision_notes` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `business_category_id` → `categories.id`; update NO ACTION, delete SET NULL.
- `requested_role_id` → `roles.id`; update NO ACTION, delete RESTRICT.
- `reviewed_by` → `users.id`; update NO ACTION, delete SET NULL.
- `sponsor_logistics_profile_id` → `logistics_profiles.id`; update NO ACTION, delete SET NULL.
- `user_id` → `users.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `account_applications_application_no_unique` (application_no) — unique.
- `account_applications_business_category_id_foreign` (business_category_id) — index.
- `account_applications_requested_role_id_status_submitted_at_index` (requested_role_id,status,submitted_at) — index.
- `account_applications_reviewed_by_foreign` (reviewed_by) — index.
- `account_applications_sponsor_logistics_profile_id_foreign` (sponsor_logistics_profile_id) — index.
- `account_applications_status_index` (status) — index.
- `account_applications_user_id_foreign` (user_id) — index.

## `addresses`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | YES | — | MUL |
| `label` | `varchar(50)` | NO | Home |  |
| `recipient_name` | `varchar(160)` | NO | — |  |
| `phone` | `varchar(30)` | NO | — |  |
| `house_number` | `varchar(40)` | YES | — |  |
| `street` | `varchar(180)` | NO | — |  |
| `barangay` | `varchar(120)` | NO | — |  |
| `city_municipality` | `varchar(120)` | NO | — |  |
| `province` | `varchar(120)` | NO | — |  |
| `postal_code` | `varchar(10)` | YES | — |  |
| `psgc_province_code` | `varchar(20)` | YES | — |  |
| `psgc_city_code` | `varchar(20)` | YES | — |  |
| `psgc_barangay_code` | `varchar(20)` | YES | — |  |
| `latitude` | `decimal(10,7)` | YES | — |  |
| `longitude` | `decimal(10,7)` | YES | — |  |
| `is_default_shipping` | `tinyint(1)` | NO | 0 |  |
| `is_default_pickup` | `tinyint(1)` | NO | 0 |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `user_id` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `addresses_user_id_is_default_shipping_index` (user_id,is_default_shipping) — index.

## `announcement_roles`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `announcement_id` | `bigint unsigned` | NO | — | PRI |
| `role_id` | `bigint unsigned` | NO | — | PRI |

**Foreign keys**

- `announcement_id` → `announcements.id`; update NO ACTION, delete CASCADE.
- `role_id` → `roles.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `announcement_roles_role_id_foreign` (role_id) — index.

## `announcements`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `announcement_no` | `varchar(32)` | NO | — | UNI |
| `title` | `varchar(180)` | NO | — |  |
| `body` | `text` | NO | — |  |
| `status` | `varchar(20)` | NO | draft |  |
| `created_by` | `bigint unsigned` | YES | — | MUL |
| `publish_at` | `timestamp` | YES | — |  |
| `published_at` | `timestamp` | YES | — |  |
| `expires_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `created_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `announcements_announcement_no_unique` (announcement_no) — unique.
- `announcements_created_by_foreign` (created_by) — index.

## `application_documents`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `application_id` | `bigint unsigned` | NO | — | MUL |
| `document_type` | `varchar(50)` | NO | — |  |
| `file_path` | `varchar(500)` | NO | — |  |
| `original_name` | `varchar(255)` | NO | — |  |
| `mime_type` | `varchar(100)` | NO | — |  |
| `size_bytes` | `bigint unsigned` | NO | — |  |
| `verification_status` | `varchar(30)` | NO | pending |  |
| `verified_by` | `bigint unsigned` | YES | — | MUL |
| `verified_at` | `timestamp` | YES | — |  |
| `rejection_reason` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `application_id` → `account_applications.id`; update NO ACTION, delete CASCADE.
- `verified_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `application_documents_application_id_document_type_index` (application_id,document_type) — index.
- `application_documents_verified_by_foreign` (verified_by) — index.

## `audit_logs`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `actor_user_id` | `bigint unsigned` | YES | — | MUL |
| `action` | `varchar(100)` | NO | — |  |
| `module` | `varchar(80)` | NO | — |  |
| `auditable_type` | `varchar(100)` | YES | — | MUL |
| `auditable_id` | `bigint unsigned` | YES | — |  |
| `description` | `text` | YES | — |  |
| `old_values` | `json` | YES | — |  |
| `new_values` | `json` | YES | — |  |
| `ip_address` | `varchar(45)` | YES | — |  |
| `user_agent` | `text` | YES | — |  |
| `severity` | `varchar(20)` | NO | info |  |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `actor_user_id` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `audit_logs_actor_user_id_foreign` (actor_user_id) — index.
- `audit_logs_auditable_type_auditable_id_index` (auditable_type,auditable_id) — index.

## `cart_items`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `cart_id` | `bigint unsigned` | NO | — | MUL |
| `product_variant_id` | `bigint unsigned` | NO | — | MUL |
| `quantity` | `int unsigned` | NO | 1 |  |
| `selected` | `tinyint(1)` | NO | 1 |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `cart_id` → `carts.id`; update NO ACTION, delete CASCADE.
- `product_variant_id` → `product_variants.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `cart_items_cart_id_product_variant_id_unique` (cart_id,product_variant_id) — unique.
- `cart_items_product_variant_id_foreign` (product_variant_id) — index.

## `carts`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | YES | — | MUL |
| `session_token` | `varchar(100)` | YES | — | MUL |
| `status` | `varchar(20)` | NO | active |  |
| `expires_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `user_id` → `users.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `carts_session_token_status_index` (session_token,status) — index.
- `carts_user_id_foreign` (user_id) — index.

## `categories`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `parent_id` | `bigint unsigned` | YES | — | MUL |
| `name` | `varchar(120)` | NO | — |  |
| `slug` | `varchar(140)` | NO | — | UNI |
| `description` | `text` | YES | — |  |
| `is_restricted` | `tinyint(1)` | NO | 0 |  |
| `is_active` | `tinyint(1)` | NO | 1 |  |
| `position` | `int unsigned` | NO | 0 |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `parent_id` → `categories.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `categories_parent_id_name_unique` (parent_id,name) — unique.
- `categories_slug_unique` (slug) — unique.

## `compliance_rules`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `code` | `varchar(50)` | NO | — | UNI |
| `name` | `varchar(160)` | NO | — |  |
| `rule_type` | `varchar(40)` | NO | — |  |
| `target_field` | `varchar(40)` | NO | — |  |
| `pattern` | `text` | YES | — |  |
| `category_id` | `bigint unsigned` | YES | — | MUL |
| `severity` | `varchar(20)` | NO | — |  |
| `default_action` | `varchar(30)` | NO | — |  |
| `configuration` | `json` | YES | — |  |
| `is_active` | `tinyint(1)` | NO | 1 |  |
| `version` | `int unsigned` | NO | 1 |  |
| `created_by` | `bigint unsigned` | YES | — | MUL |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `category_id` → `categories.id`; update NO ACTION, delete SET NULL.
- `created_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `compliance_rules_category_id_foreign` (category_id) — index.
- `compliance_rules_code_unique` (code) — unique.
- `compliance_rules_created_by_foreign` (created_by) — index.

## `conversation_participants`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `conversation_id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | NO | — | PRI |
| `participant_role` | `varchar(30)` | NO | — |  |
| `last_read_at` | `timestamp` | YES | — |  |
| `joined_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `conversation_id` → `conversations.id`; update NO ACTION, delete CASCADE.
- `user_id` → `users.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `conversation_participants_user_id_foreign` (user_id) — index.

## `conversations`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `subject` | `varchar(180)` | YES | — |  |
| `order_id` | `bigint unsigned` | YES | — | MUL |
| `seller_order_id` | `bigint unsigned` | YES | — | MUL |
| `product_id` | `bigint unsigned` | YES | — | MUL |
| `dispute_id` | `bigint unsigned` | YES | — | MUL |
| `type` | `varchar(30)` | NO | direct |  |
| `status` | `varchar(20)` | NO | open |  |
| `created_by` | `bigint unsigned` | YES | — | MUL |
| `last_message_at` | `timestamp` | YES | — | MUL |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `created_by` → `users.id`; update NO ACTION, delete SET NULL.
- `dispute_id` → `disputes.id`; update NO ACTION, delete SET NULL.
- `order_id` → `orders.id`; update NO ACTION, delete SET NULL.
- `product_id` → `products.id`; update NO ACTION, delete SET NULL.
- `seller_order_id` → `seller_orders.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `conversations_created_by_foreign` (created_by) — index.
- `conversations_dispute_id_foreign` (dispute_id) — index.
- `conversations_last_message_at_index` (last_message_at) — index.
- `conversations_order_id_foreign` (order_id) — index.
- `conversations_product_id_foreign` (product_id) — index.
- `conversations_seller_order_id_foreign` (seller_order_id) — index.

## `delivery_attempts`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `parcel_id` | `bigint unsigned` | NO | — | MUL |
| `dispatch_batch_id` | `bigint unsigned` | YES | — | MUL |
| `rider_profile_id` | `bigint unsigned` | NO | — | MUL |
| `attempt_no` | `smallint unsigned` | NO | — |  |
| `outcome` | `varchar(30)` | NO | — |  |
| `failure_reason` | `varchar(100)` | YES | — |  |
| `notes` | `text` | YES | — |  |
| `latitude` | `decimal(10,7)` | YES | — |  |
| `longitude` | `decimal(10,7)` | YES | — |  |
| `attempted_at` | `timestamp` | NO | — |  |
| `next_attempt_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `dispatch_batch_id` → `dispatch_batches.id`; update NO ACTION, delete SET NULL.
- `parcel_id` → `parcels.id`; update NO ACTION, delete RESTRICT.
- `rider_profile_id` → `rider_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `delivery_attempts_dispatch_batch_id_foreign` (dispatch_batch_id) — index.
- `delivery_attempts_parcel_id_attempt_no_unique` (parcel_id,attempt_no) — unique.
- `delivery_attempts_rider_profile_id_foreign` (rider_profile_id) — index.

## `delivery_proofs`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `delivery_attempt_id` | `bigint unsigned` | NO | — | MUL |
| `type` | `varchar(30)` | NO | — |  |
| `file_path` | `varchar(500)` | YES | — |  |
| `recipient_name` | `varchar(160)` | YES | — |  |
| `recipient_signature_path` | `varchar(500)` | YES | — |  |
| `otp_verified_at` | `timestamp` | YES | — |  |
| `captured_at` | `timestamp` | NO | — |  |
| `uploaded_by` | `bigint unsigned` | YES | — | MUL |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `delivery_attempt_id` → `delivery_attempts.id`; update NO ACTION, delete CASCADE.
- `uploaded_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `delivery_proofs_delivery_attempt_id_foreign` (delivery_attempt_id) — index.
- `delivery_proofs_uploaded_by_foreign` (uploaded_by) — index.

## `dispatch_batch_parcels`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `dispatch_batch_id` | `bigint unsigned` | NO | — | PRI |
| `parcel_id` | `bigint unsigned` | NO | — | PRI |
| `sequence` | `smallint unsigned` | YES | — |  |
| `loaded_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `dispatch_batch_id` → `dispatch_batches.id`; update NO ACTION, delete CASCADE.
- `parcel_id` → `parcels.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `dispatch_batch_parcels_parcel_id_foreign` (parcel_id) — index.

## `dispatch_batches`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `batch_no` | `varchar(32)` | NO | — | UNI |
| `sorting_center_id` | `bigint unsigned` | NO | — | MUL |
| `sorting_zone_id` | `bigint unsigned` | YES | — | MUL |
| `rider_profile_id` | `bigint unsigned` | NO | — | MUL |
| `status` | `varchar(30)` | NO | preparing |  |
| `prepared_by` | `bigint unsigned` | YES | — | MUL |
| `prepared_at` | `timestamp` | YES | — |  |
| `assigned_at` | `timestamp` | YES | — |  |
| `dispatched_at` | `timestamp` | YES | — |  |
| `completed_at` | `timestamp` | YES | — |  |
| `cancelled_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `prepared_by` → `users.id`; update NO ACTION, delete SET NULL.
- `rider_profile_id` → `rider_profiles.id`; update NO ACTION, delete RESTRICT.
- `sorting_center_id` → `sorting_centers.id`; update NO ACTION, delete RESTRICT.
- `sorting_zone_id` → `sorting_zones.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `dispatch_batches_batch_no_unique` (batch_no) — unique.
- `dispatch_batches_prepared_by_foreign` (prepared_by) — index.
- `dispatch_batches_rider_profile_id_foreign` (rider_profile_id) — index.
- `dispatch_batches_sorting_center_id_foreign` (sorting_center_id) — index.
- `dispatch_batches_sorting_zone_id_foreign` (sorting_zone_id) — index.

## `dispute_events`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `dispute_id` | `bigint unsigned` | NO | — | MUL |
| `event_type` | `varchar(40)` | NO | — |  |
| `actor_user_id` | `bigint unsigned` | YES | — | MUL |
| `from_status` | `varchar(30)` | YES | — |  |
| `to_status` | `varchar(30)` | YES | — |  |
| `note` | `text` | YES | — |  |
| `metadata` | `json` | YES | — |  |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `actor_user_id` → `users.id`; update NO ACTION, delete SET NULL.
- `dispute_id` → `disputes.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `dispute_events_actor_user_id_foreign` (actor_user_id) — index.
- `dispute_events_dispute_id_foreign` (dispute_id) — index.

## `dispute_evidence`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `dispute_id` | `bigint unsigned` | NO | — | MUL |
| `uploaded_by` | `bigint unsigned` | YES | — | MUL |
| `type` | `varchar(30)` | NO | — |  |
| `file_path` | `varchar(500)` | NO | — |  |
| `original_name` | `varchar(255)` | NO | — |  |
| `description` | `varchar(500)` | YES | — |  |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `dispute_id` → `disputes.id`; update NO ACTION, delete CASCADE.
- `uploaded_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `dispute_evidence_dispute_id_foreign` (dispute_id) — index.
- `dispute_evidence_uploaded_by_foreign` (uploaded_by) — index.

## `dispute_participants`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `dispute_id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | NO | — | PRI |
| `participant_role` | `varchar(30)` | NO | — |  |
| `joined_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `dispute_id` → `disputes.id`; update NO ACTION, delete CASCADE.
- `user_id` → `users.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `dispute_participants_user_id_foreign` (user_id) — index.

## `disputes`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `dispute_no` | `varchar(32)` | NO | — | UNI |
| `seller_order_id` | `bigint unsigned` | YES | — | MUL |
| `return_request_id` | `bigint unsigned` | YES | — | MUL |
| `shipment_id` | `bigint unsigned` | YES | — | MUL |
| `opened_by` | `bigint unsigned` | NO | — | MUL |
| `subject` | `varchar(180)` | NO | — |  |
| `description` | `text` | NO | — |  |
| `priority` | `varchar(20)` | NO | normal |  |
| `status` | `varchar(30)` | NO | open |  |
| `amount_minor` | `bigint unsigned` | YES | — |  |
| `assigned_to` | `bigint unsigned` | YES | — | MUL |
| `opened_at` | `timestamp` | NO | — |  |
| `response_due_at` | `timestamp` | YES | — |  |
| `resolved_at` | `timestamp` | YES | — |  |
| `closed_at` | `timestamp` | YES | — |  |
| `resolution` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `assigned_to` → `users.id`; update NO ACTION, delete SET NULL.
- `opened_by` → `users.id`; update NO ACTION, delete RESTRICT.
- `return_request_id` → `return_requests.id`; update NO ACTION, delete SET NULL.
- `seller_order_id` → `seller_orders.id`; update NO ACTION, delete SET NULL.
- `shipment_id` → `shipments.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `disputes_assigned_to_foreign` (assigned_to) — index.
- `disputes_dispute_no_unique` (dispute_no) — unique.
- `disputes_opened_by_foreign` (opened_by) — index.
- `disputes_return_request_id_foreign` (return_request_id) — index.
- `disputes_seller_order_id_foreign` (seller_order_id) — index.
- `disputes_shipment_id_foreign` (shipment_id) — index.

## `inventory_movements`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `variant_id` | `bigint unsigned` | NO | — | MUL |
| `type` | `varchar(40)` | NO | — |  |
| `quantity_delta` | `int` | NO | — |  |
| `balance_after` | `int unsigned` | NO | — |  |
| `reference_type` | `varchar(50)` | YES | — | MUL |
| `reference_id` | `bigint unsigned` | YES | — |  |
| `reason` | `varchar(255)` | YES | — |  |
| `created_by` | `bigint unsigned` | YES | — | MUL |
| `occurred_at` | `timestamp` | NO | — |  |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `created_by` → `users.id`; update NO ACTION, delete SET NULL.
- `variant_id` → `product_variants.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `inventory_movements_created_by_foreign` (created_by) — index.
- `inventory_movements_reference_type_reference_id_index` (reference_type,reference_id) — index.
- `inventory_movements_variant_id_occurred_at_index` (variant_id,occurred_at) — index.

## `logistics_profiles`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | NO | — | UNI |
| `application_id` | `bigint unsigned` | YES | — | MUL |
| `legal_name` | `varchar(160)` | NO | — |  |
| `display_name` | `varchar(160)` | NO | — |  |
| `contact_phone` | `varchar(30)` | NO | — |  |
| `status` | `varchar(30)` | NO | active |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `application_id` → `account_applications.id`; update NO ACTION, delete SET NULL.
- `user_id` → `users.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `logistics_profiles_application_id_foreign` (application_id) — index.
- `logistics_profiles_user_id_unique` (user_id) — unique.

## `message_attachments`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `message_id` | `bigint unsigned` | NO | — | MUL |
| `file_path` | `varchar(500)` | NO | — |  |
| `original_name` | `varchar(255)` | NO | — |  |
| `mime_type` | `varchar(100)` | NO | — |  |
| `size_bytes` | `bigint unsigned` | NO | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `message_id` → `messages.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `message_attachments_message_id_foreign` (message_id) — index.

## `messages`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `conversation_id` | `bigint unsigned` | NO | — | MUL |
| `sender_id` | `bigint unsigned` | YES | — | MUL |
| `body` | `text` | NO | — |  |
| `message_type` | `varchar(20)` | NO | text |  |
| `sent_at` | `timestamp` | NO | — |  |
| `edited_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `conversation_id` → `conversations.id`; update NO ACTION, delete CASCADE.
- `sender_id` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `messages_conversation_id_sent_at_index` (conversation_id,sent_at) — index.
- `messages_sender_id_foreign` (sender_id) — index.

## `notifications`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `char(36)` | NO | — | PRI |
| `type` | `varchar(255)` | NO | — |  |
| `notifiable_type` | `varchar(255)` | NO | — | MUL |
| `notifiable_id` | `bigint unsigned` | NO | — |  |
| `data` | `text` | NO | — |  |
| `read_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Indexes**

- `notifications_notifiable_type_notifiable_id_index` (notifiable_type,notifiable_id) — index.

## `order_items`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `seller_order_id` | `bigint unsigned` | NO | — | MUL |
| `product_id` | `bigint unsigned` | YES | — | MUL |
| `product_variant_id` | `bigint unsigned` | YES | — | MUL |
| `product_name` | `varchar(180)` | NO | — |  |
| `variant_name` | `varchar(140)` | NO | — |  |
| `sku` | `varchar(80)` | NO | — |  |
| `options` | `json` | YES | — |  |
| `unit_price_minor` | `bigint unsigned` | NO | — |  |
| `quantity` | `int unsigned` | NO | — |  |
| `discount_minor` | `bigint unsigned` | NO | 0 |  |
| `subtotal_minor` | `bigint unsigned` | NO | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `product_id` → `products.id`; update NO ACTION, delete SET NULL.
- `product_variant_id` → `product_variants.id`; update NO ACTION, delete SET NULL.
- `seller_order_id` → `seller_orders.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `order_items_product_id_foreign` (product_id) — index.
- `order_items_product_variant_id_foreign` (product_variant_id) — index.
- `order_items_seller_order_id_foreign` (seller_order_id) — index.

## `orders`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `order_no` | `varchar(32)` | NO | — | UNI |
| `buyer_id` | `bigint unsigned` | NO | — | MUL |
| `shipping_address_id` | `bigint unsigned` | YES | — | MUL |
| `recipient_name` | `varchar(160)` | NO | — |  |
| `recipient_phone` | `varchar(30)` | NO | — |  |
| `address_line` | `varchar(255)` | NO | — |  |
| `barangay` | `varchar(120)` | NO | — |  |
| `city_municipality` | `varchar(120)` | NO | — |  |
| `province` | `varchar(120)` | NO | — |  |
| `postal_code` | `varchar(10)` | YES | — |  |
| `currency` | `char(3)` | NO | PHP |  |
| `subtotal_minor` | `bigint unsigned` | NO | — |  |
| `shipping_fee_minor` | `bigint unsigned` | NO | 0 |  |
| `discount_minor` | `bigint unsigned` | NO | 0 |  |
| `total_minor` | `bigint unsigned` | NO | — |  |
| `status` | `varchar(30)` | NO | pending_payment | MUL |
| `payment_status` | `varchar(30)` | NO | unpaid |  |
| `placed_at` | `timestamp` | YES | — |  |
| `cancelled_at` | `timestamp` | YES | — |  |
| `completed_at` | `timestamp` | YES | — |  |
| `cancel_reason` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `buyer_id` → `users.id`; update NO ACTION, delete RESTRICT.
- `shipping_address_id` → `addresses.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `orders_buyer_id_created_at_index` (buyer_id,created_at) — index.
- `orders_order_no_unique` (order_no) — unique.
- `orders_shipping_address_id_foreign` (shipping_address_id) — index.
- `orders_status_created_at_index` (status,created_at) — index.

## `parcels`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `shipment_id` | `bigint unsigned` | NO | — | MUL |
| `waybill_id` | `bigint unsigned` | NO | — | MUL |
| `parcel_no` | `varchar(50)` | NO | — | UNI |
| `piece_sequence` | `smallint unsigned` | NO | — |  |
| `weight_kg` | `decimal(10,3)` | NO | — |  |
| `length_cm` | `decimal(10,2)` | YES | — |  |
| `width_cm` | `decimal(10,2)` | YES | — |  |
| `height_cm` | `decimal(10,2)` | YES | — |  |
| `size_class` | `varchar(20)` | YES | — |  |
| `status` | `varchar(40)` | NO | created | MUL |
| `current_sorting_center_id` | `bigint unsigned` | YES | — | MUL |
| `current_zone_id` | `bigint unsigned` | YES | — | MUL |
| `last_event_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `current_sorting_center_id` → `sorting_centers.id`; update NO ACTION, delete SET NULL.
- `current_zone_id` → `sorting_zones.id`; update NO ACTION, delete SET NULL.
- `shipment_id` → `shipments.id`; update NO ACTION, delete RESTRICT.
- `waybill_id` → `waybills.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `parcels_current_sorting_center_id_foreign` (current_sorting_center_id) — index.
- `parcels_current_zone_id_foreign` (current_zone_id) — index.
- `parcels_parcel_no_unique` (parcel_no) — unique.
- `parcels_shipment_id_foreign` (shipment_id) — index.
- `parcels_status_current_sorting_center_id_index` (status,current_sorting_center_id) — index.
- `parcels_waybill_id_piece_sequence_unique` (waybill_id,piece_sequence) — unique.

## `payments`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `payment_no` | `varchar(40)` | NO | — | UNI |
| `order_id` | `bigint unsigned` | NO | — | MUL |
| `method` | `varchar(30)` | NO | — |  |
| `provider` | `varchar(50)` | YES | — |  |
| `provider_reference` | `varchar(150)` | YES | — | MUL |
| `amount_minor` | `bigint unsigned` | NO | — |  |
| `status` | `varchar(30)` | NO | pending |  |
| `initiated_at` | `timestamp` | YES | — |  |
| `authorized_at` | `timestamp` | YES | — |  |
| `paid_at` | `timestamp` | YES | — |  |
| `failed_at` | `timestamp` | YES | — |  |
| `refunded_at` | `timestamp` | YES | — |  |
| `failure_reason` | `text` | YES | — |  |
| `metadata` | `json` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `order_id` → `orders.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `payments_order_id_status_index` (order_id,status) — index.
- `payments_payment_no_unique` (payment_no) — unique.
- `payments_provider_reference_index` (provider_reference) — index.

## `pickup_assignments`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `pickup_request_id` | `bigint unsigned` | NO | — | MUL |
| `rider_profile_id` | `bigint unsigned` | NO | — | MUL |
| `status` | `varchar(30)` | NO | assigned |  |
| `assigned_by` | `bigint unsigned` | YES | — | MUL |
| `assigned_at` | `timestamp` | NO | — |  |
| `accepted_at` | `timestamp` | YES | — |  |
| `arrived_at` | `timestamp` | YES | — |  |
| `picked_up_at` | `timestamp` | YES | — |  |
| `completed_at` | `timestamp` | YES | — |  |
| `cancelled_at` | `timestamp` | YES | — |  |
| `notes` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `assigned_by` → `users.id`; update NO ACTION, delete SET NULL.
- `pickup_request_id` → `pickup_requests.id`; update NO ACTION, delete RESTRICT.
- `rider_profile_id` → `rider_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `pickup_assignments_assigned_by_foreign` (assigned_by) — index.
- `pickup_assignments_pickup_request_id_foreign` (pickup_request_id) — index.
- `pickup_assignments_rider_profile_id_status_assigned_at_index` (rider_profile_id,status,assigned_at) — index.

## `pickup_request_parcels`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `pickup_request_id` | `bigint unsigned` | NO | — | PRI |
| `parcel_id` | `bigint unsigned` | NO | — | PRI |
| `added_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `parcel_id` → `parcels.id`; update NO ACTION, delete RESTRICT.
- `pickup_request_id` → `pickup_requests.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `pickup_request_parcels_parcel_id_foreign` (parcel_id) — index.

## `pickup_requests`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `pickup_no` | `varchar(32)` | NO | — | UNI |
| `store_id` | `bigint unsigned` | NO | — | MUL |
| `logistics_profile_id` | `bigint unsigned` | NO | — | MUL |
| `pickup_address_id` | `bigint unsigned` | NO | — | MUL |
| `status` | `varchar(30)` | NO | requested |  |
| `requested_date` | `date` | NO | — |  |
| `window_start` | `datetime` | NO | — |  |
| `window_end` | `datetime` | NO | — |  |
| `seller_instructions` | `text` | YES | — |  |
| `verified_by` | `bigint unsigned` | YES | — | MUL |
| `verified_at` | `timestamp` | YES | — |  |
| `cancelled_at` | `timestamp` | YES | — |  |
| `completed_at` | `timestamp` | YES | — |  |
| `cancellation_reason` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `logistics_profile_id` → `logistics_profiles.id`; update NO ACTION, delete RESTRICT.
- `pickup_address_id` → `addresses.id`; update NO ACTION, delete RESTRICT.
- `store_id` → `stores.id`; update NO ACTION, delete RESTRICT.
- `verified_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `pickup_requests_logistics_profile_id_status_requested_date_index` (logistics_profile_id,status,requested_date) — index.
- `pickup_requests_pickup_address_id_foreign` (pickup_address_id) — index.
- `pickup_requests_pickup_no_unique` (pickup_no) — unique.
- `pickup_requests_store_id_foreign` (store_id) — index.
- `pickup_requests_verified_by_foreign` (verified_by) — index.

## `platform_commissions`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `seller_order_id` | `bigint unsigned` | NO | — | MUL |
| `refund_id` | `bigint unsigned` | YES | — | MUL |
| `type` | `varchar(30)` | NO | — |  |
| `base_amount_minor` | `bigint unsigned` | NO | — |  |
| `rate_bps` | `smallint unsigned` | NO | — |  |
| `amount_minor` | `bigint` | NO | — |  |
| `status` | `varchar(30)` | NO | estimated |  |
| `calculated_at` | `timestamp` | NO | — |  |
| `finalized_at` | `timestamp` | YES | — |  |
| `reversed_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `refund_id` → `refunds.id`; update NO ACTION, delete RESTRICT.
- `seller_order_id` → `seller_orders.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `platform_commissions_refund_id_foreign` (refund_id) — index.
- `platform_commissions_seller_order_id_foreign` (seller_order_id) — index.

## `policies`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `code` | `varchar(50)` | NO | — | UNI |
| `title` | `varchar(180)` | NO | — |  |
| `category` | `varchar(80)` | NO | — |  |
| `status` | `varchar(20)` | NO | active |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Indexes**

- `policies_code_unique` (code) — unique.

## `policy_versions`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `policy_id` | `bigint unsigned` | NO | — | MUL |
| `version` | `varchar(20)` | NO | — |  |
| `body` | `longtext` | NO | — |  |
| `summary` | `text` | YES | — |  |
| `status` | `varchar(20)` | NO | draft |  |
| `created_by` | `bigint unsigned` | YES | — | MUL |
| `effective_at` | `timestamp` | YES | — |  |
| `published_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `created_by` → `users.id`; update NO ACTION, delete SET NULL.
- `policy_id` → `policies.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `policy_versions_created_by_foreign` (created_by) — index.
- `policy_versions_policy_id_version_unique` (policy_id,version) — unique.

## `product_compliance_check_matches`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `check_id` | `bigint unsigned` | NO | — | MUL |
| `rule_id` | `bigint unsigned` | NO | — | MUL |
| `matched_field` | `varchar(40)` | NO | — |  |
| `matched_excerpt` | `varchar(500)` | YES | — |  |
| `score` | `decimal(5,4)` | YES | — |  |
| `severity` | `varchar(20)` | NO | — |  |
| `recommended_action` | `varchar(30)` | NO | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `check_id` → `product_compliance_checks.id`; update NO ACTION, delete CASCADE.
- `rule_id` → `compliance_rules.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `product_compliance_check_matches_check_id_foreign` (check_id) — index.
- `product_compliance_check_matches_rule_id_foreign` (rule_id) — index.

## `product_compliance_checks`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `product_id` | `bigint unsigned` | NO | — | MUL |
| `trigger` | `varchar(20)` | NO | — |  |
| `status` | `varchar(30)` | NO | queued |  |
| `result` | `varchar(30)` | YES | — |  |
| `name_snapshot` | `varchar(180)` | NO | — |  |
| `description_snapshot` | `text` | YES | — |  |
| `category_id_snapshot` | `bigint unsigned` | YES | — | MUL |
| `rules_version` | `varchar(50)` | YES | — |  |
| `started_at` | `timestamp` | YES | — |  |
| `completed_at` | `timestamp` | YES | — |  |
| `error_message` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `category_id_snapshot` → `categories.id`; update NO ACTION, delete SET NULL.
- `product_id` → `products.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `product_compliance_checks_category_id_snapshot_foreign` (category_id_snapshot) — index.
- `product_compliance_checks_product_id_created_at_index` (product_id,created_at) — index.

## `product_images`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `product_id` | `bigint unsigned` | NO | — | MUL |
| `variant_id` | `bigint unsigned` | YES | — | MUL |
| `path` | `varchar(500)` | NO | — |  |
| `alt_text` | `varchar(255)` | YES | — |  |
| `position` | `smallint unsigned` | NO | 0 |  |
| `is_primary` | `tinyint(1)` | NO | 0 |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `product_id` → `products.id`; update NO ACTION, delete CASCADE.
- `variant_id` → `product_variants.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `product_images_product_id_position_index` (product_id,position) — index.
- `product_images_variant_id_foreign` (variant_id) — index.

## `product_reports`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `report_no` | `varchar(32)` | NO | — | UNI |
| `product_id` | `bigint unsigned` | NO | — | MUL |
| `reporter_user_id` | `bigint unsigned` | YES | — | MUL |
| `reason_code` | `varchar(50)` | NO | — |  |
| `details` | `text` | YES | — |  |
| `status` | `varchar(30)` | NO | submitted |  |
| `reviewed_by` | `bigint unsigned` | YES | — | MUL |
| `reviewed_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `product_id` → `products.id`; update NO ACTION, delete RESTRICT.
- `reporter_user_id` → `users.id`; update NO ACTION, delete SET NULL.
- `reviewed_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `product_reports_product_id_foreign` (product_id) — index.
- `product_reports_report_no_unique` (report_no) — unique.
- `product_reports_reporter_user_id_foreign` (reporter_user_id) — index.
- `product_reports_reviewed_by_foreign` (reviewed_by) — index.

## `product_variants`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `product_id` | `bigint unsigned` | NO | — | MUL |
| `sku` | `varchar(80)` | NO | — | UNI |
| `name` | `varchar(140)` | NO | — |  |
| `options` | `json` | YES | — |  |
| `price_minor` | `bigint unsigned` | NO | — |  |
| `compare_at_price_minor` | `bigint unsigned` | YES | — |  |
| `stock_on_hand` | `int unsigned` | NO | 0 |  |
| `stock_reserved` | `int unsigned` | NO | 0 |  |
| `low_stock_threshold` | `int unsigned` | NO | 5 |  |
| `weight_kg` | `decimal(10,3)` | YES | — |  |
| `is_active` | `tinyint(1)` | NO | 1 |  |
| `position` | `smallint unsigned` | NO | 0 |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `product_id` → `products.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `product_variants_product_id_is_active_index` (product_id,is_active) — index.
- `product_variants_sku_unique` (sku) — unique.

## `product_violations`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `violation_no` | `varchar(32)` | NO | — | UNI |
| `product_id` | `bigint unsigned` | NO | — | MUL |
| `seller_profile_id` | `bigint unsigned` | NO | — | MUL |
| `check_id` | `bigint unsigned` | YES | — | MUL |
| `rule_id` | `bigint unsigned` | YES | — | MUL |
| `product_report_id` | `bigint unsigned` | YES | — | MUL |
| `source` | `varchar(30)` | NO | — |  |
| `violation_type` | `varchar(50)` | NO | — |  |
| `severity` | `varchar(20)` | NO | — |  |
| `reason` | `text` | NO | — |  |
| `detected_excerpt` | `varchar(500)` | YES | — |  |
| `status` | `varchar(30)` | NO | flagged | MUL |
| `assigned_to` | `bigint unsigned` | YES | — | MUL |
| `reviewed_at` | `timestamp` | YES | — |  |
| `resolved_at` | `timestamp` | YES | — |  |
| `resolution` | `varchar(40)` | YES | — |  |
| `admin_note` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `assigned_to` → `users.id`; update NO ACTION, delete SET NULL.
- `check_id` → `product_compliance_checks.id`; update NO ACTION, delete SET NULL.
- `product_id` → `products.id`; update NO ACTION, delete RESTRICT.
- `product_report_id` → `product_reports.id`; update NO ACTION, delete SET NULL.
- `rule_id` → `compliance_rules.id`; update NO ACTION, delete SET NULL.
- `seller_profile_id` → `seller_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `product_violations_assigned_to_foreign` (assigned_to) — index.
- `product_violations_check_id_foreign` (check_id) — index.
- `product_violations_product_id_foreign` (product_id) — index.
- `product_violations_product_report_id_foreign` (product_report_id) — index.
- `product_violations_rule_id_foreign` (rule_id) — index.
- `product_violations_seller_profile_id_foreign` (seller_profile_id) — index.
- `product_violations_status_severity_created_at_index` (status,severity,created_at) — index.
- `product_violations_violation_no_unique` (violation_no) — unique.

## `products`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `store_id` | `bigint unsigned` | NO | — | MUL |
| `category_id` | `bigint unsigned` | NO | — | MUL |
| `name` | `varchar(180)` | NO | — |  |
| `slug` | `varchar(220)` | NO | — |  |
| `description` | `text` | YES | — |  |
| `product_status` | `varchar(30)` | NO | draft |  |
| `compliance_status` | `varchar(30)` | NO | pending_scan | MUL |
| `voucher_eligible` | `tinyint(1)` | NO | 0 |  |
| `published_at` | `timestamp` | YES | — |  |
| `archived_at` | `timestamp` | YES | — |  |
| `removed_at` | `timestamp` | YES | — |  |
| `removed_reason` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `category_id` → `categories.id`; update NO ACTION, delete RESTRICT.
- `store_id` → `stores.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `products_category_id_foreign` (category_id) — index.
- `products_compliance_status_updated_at_index` (compliance_status,updated_at) — index.
- `products_store_id_product_status_index` (store_id,product_status) — index.
- `products_store_id_slug_unique` (store_id,slug) — unique.

## `refunds`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `refund_no` | `varchar(40)` | NO | — | UNI |
| `return_request_id` | `bigint unsigned` | YES | — | MUL |
| `payment_id` | `bigint unsigned` | YES | — | MUL |
| `order_id` | `bigint unsigned` | NO | — | MUL |
| `amount_minor` | `bigint unsigned` | NO | — |  |
| `status` | `varchar(30)` | NO | pending |  |
| `method` | `varchar(30)` | NO | — |  |
| `provider_reference` | `varchar(150)` | YES | — |  |
| `approved_by` | `bigint unsigned` | YES | — | MUL |
| `approved_at` | `timestamp` | YES | — |  |
| `processed_at` | `timestamp` | YES | — |  |
| `completed_at` | `timestamp` | YES | — |  |
| `failed_at` | `timestamp` | YES | — |  |
| `failure_reason` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `approved_by` → `users.id`; update NO ACTION, delete SET NULL.
- `order_id` → `orders.id`; update NO ACTION, delete RESTRICT.
- `payment_id` → `payments.id`; update NO ACTION, delete RESTRICT.
- `return_request_id` → `return_requests.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `refunds_approved_by_foreign` (approved_by) — index.
- `refunds_order_id_foreign` (order_id) — index.
- `refunds_payment_id_foreign` (payment_id) — index.
- `refunds_refund_no_unique` (refund_no) — unique.
- `refunds_return_request_id_foreign` (return_request_id) — index.

## `return_evidence`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `return_request_id` | `bigint unsigned` | NO | — | MUL |
| `uploaded_by` | `bigint unsigned` | YES | — | MUL |
| `type` | `varchar(30)` | NO | — |  |
| `file_path` | `varchar(500)` | NO | — |  |
| `original_name` | `varchar(255)` | NO | — |  |
| `description` | `varchar(500)` | YES | — |  |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `return_request_id` → `return_requests.id`; update NO ACTION, delete CASCADE.
- `uploaded_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `return_evidence_return_request_id_foreign` (return_request_id) — index.
- `return_evidence_uploaded_by_foreign` (uploaded_by) — index.

## `return_items`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `return_request_id` | `bigint unsigned` | NO | — | MUL |
| `order_item_id` | `bigint unsigned` | NO | — | MUL |
| `quantity` | `int unsigned` | NO | — |  |
| `condition_code` | `varchar(40)` | YES | — |  |
| `resolution` | `varchar(30)` | YES | — |  |
| `refund_amount_minor` | `bigint unsigned` | NO | 0 |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `order_item_id` → `order_items.id`; update NO ACTION, delete RESTRICT.
- `return_request_id` → `return_requests.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `return_items_order_item_id_foreign` (order_item_id) — index.
- `return_items_return_request_id_order_item_id_unique` (return_request_id,order_item_id) — unique.

## `return_requests`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `return_no` | `varchar(32)` | NO | — | UNI |
| `order_id` | `bigint unsigned` | NO | — | MUL |
| `seller_order_id` | `bigint unsigned` | NO | — | MUL |
| `buyer_id` | `bigint unsigned` | NO | — | MUL |
| `store_id` | `bigint unsigned` | NO | — | MUL |
| `request_type` | `varchar(30)` | NO | — |  |
| `reason_code` | `varchar(50)` | NO | — |  |
| `buyer_note` | `text` | YES | — |  |
| `seller_response` | `text` | YES | — |  |
| `status` | `varchar(40)` | NO | submitted |  |
| `requested_amount_minor` | `bigint unsigned` | NO | — |  |
| `response_due_at` | `timestamp` | YES | — |  |
| `submitted_at` | `timestamp` | NO | — |  |
| `seller_responded_at` | `timestamp` | YES | — |  |
| `reviewed_at` | `timestamp` | YES | — |  |
| `resolved_at` | `timestamp` | YES | — |  |
| `reviewed_by` | `bigint unsigned` | YES | — | MUL |
| `resolution` | `text` | YES | — |  |
| `return_shipment_id` | `bigint unsigned` | YES | — | MUL |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `buyer_id` → `users.id`; update NO ACTION, delete RESTRICT.
- `order_id` → `orders.id`; update NO ACTION, delete RESTRICT.
- `return_shipment_id` → `shipments.id`; update NO ACTION, delete SET NULL.
- `reviewed_by` → `users.id`; update NO ACTION, delete SET NULL.
- `seller_order_id` → `seller_orders.id`; update NO ACTION, delete RESTRICT.
- `store_id` → `stores.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `return_requests_buyer_id_foreign` (buyer_id) — index.
- `return_requests_order_id_foreign` (order_id) — index.
- `return_requests_return_no_unique` (return_no) — unique.
- `return_requests_return_shipment_id_foreign` (return_shipment_id) — index.
- `return_requests_reviewed_by_foreign` (reviewed_by) — index.
- `return_requests_seller_order_id_foreign` (seller_order_id) — index.
- `return_requests_store_id_foreign` (store_id) — index.

## `rider_earnings`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `rider_profile_id` | `bigint unsigned` | NO | — | MUL |
| `pickup_assignment_id` | `bigint unsigned` | YES | — | MUL |
| `delivery_attempt_id` | `bigint unsigned` | YES | — | MUL |
| `type` | `varchar(30)` | NO | — |  |
| `amount_minor` | `bigint` | NO | — |  |
| `status` | `varchar(30)` | NO | pending |  |
| `description` | `varchar(255)` | YES | — |  |
| `earned_at` | `timestamp` | NO | — |  |
| `posted_at` | `timestamp` | YES | — |  |
| `paid_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `delivery_attempt_id` → `delivery_attempts.id`; update NO ACTION, delete SET NULL.
- `pickup_assignment_id` → `pickup_assignments.id`; update NO ACTION, delete SET NULL.
- `rider_profile_id` → `rider_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `rider_earnings_delivery_attempt_id_foreign` (delivery_attempt_id) — index.
- `rider_earnings_pickup_assignment_id_foreign` (pickup_assignment_id) — index.
- `rider_earnings_rider_profile_id_foreign` (rider_profile_id) — index.

## `rider_profiles`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | NO | — | UNI |
| `logistics_profile_id` | `bigint unsigned` | NO | — | MUL |
| `home_sorting_center_id` | `bigint unsigned` | YES | — | MUL |
| `current_zone_id` | `bigint unsigned` | YES | — | MUL |
| `vehicle_type` | `varchar(80)` | NO | — |  |
| `vehicle_model` | `varchar(100)` | YES | — |  |
| `plate_number` | `varchar(30)` | YES | — |  |
| `parcel_capacity` | `smallint unsigned` | YES | — |  |
| `emergency_contact_name` | `varchar(160)` | YES | — |  |
| `emergency_contact_phone` | `varchar(30)` | YES | — |  |
| `availability_status` | `varchar(30)` | NO | offline |  |
| `verification_status` | `varchar(30)` | NO | pending |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `current_zone_id` → `sorting_zones.id`; update NO ACTION, delete SET NULL.
- `home_sorting_center_id` → `sorting_centers.id`; update NO ACTION, delete SET NULL.
- `logistics_profile_id` → `logistics_profiles.id`; update NO ACTION, delete RESTRICT.
- `user_id` → `users.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `rider_profiles_current_zone_id_foreign` (current_zone_id) — index.
- `rider_profiles_home_sorting_center_id_foreign` (home_sorting_center_id) — index.
- `rider_profiles_logistics_profile_id_availability_status_index` (logistics_profile_id,availability_status) — index.
- `rider_profiles_user_id_unique` (user_id) — unique.

## `role_user`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `role_id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | NO | — | PRI |
| `assigned_by` | `bigint unsigned` | YES | — | MUL |
| `assigned_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `assigned_by` → `users.id`; update NO ACTION, delete SET NULL.
- `role_id` → `roles.id`; update NO ACTION, delete CASCADE.
- `user_id` → `users.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `role_user_assigned_by_foreign` (assigned_by) — index.
- `role_user_user_id_foreign` (user_id) — index.

## `roles`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `name` | `varchar(40)` | NO | — | UNI |
| `display_name` | `varchar(80)` | NO | — |  |
| `description` | `varchar(255)` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Indexes**

- `roles_name_unique` (name) — unique.

## `seller_orders`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `seller_order_no` | `varchar(32)` | NO | — | UNI |
| `order_id` | `bigint unsigned` | NO | — | MUL |
| `store_id` | `bigint unsigned` | NO | — | MUL |
| `status` | `varchar(30)` | NO | placed |  |
| `subtotal_minor` | `bigint unsigned` | NO | — |  |
| `discount_minor` | `bigint unsigned` | NO | 0 |  |
| `shipping_fee_minor` | `bigint unsigned` | NO | 0 |  |
| `total_minor` | `bigint unsigned` | NO | — |  |
| `fulfillment_deadline_at` | `timestamp` | YES | — |  |
| `confirmed_at` | `timestamp` | YES | — |  |
| `preparing_at` | `timestamp` | YES | — |  |
| `ready_at` | `timestamp` | YES | — |  |
| `handed_over_at` | `timestamp` | YES | — |  |
| `delivered_at` | `timestamp` | YES | — |  |
| `completed_at` | `timestamp` | YES | — |  |
| `cancelled_at` | `timestamp` | YES | — |  |
| `seller_note` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `order_id` → `orders.id`; update NO ACTION, delete RESTRICT.
- `store_id` → `stores.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `seller_orders_order_id_store_id_unique` (order_id,store_id) — unique.
- `seller_orders_seller_order_no_unique` (seller_order_no) — unique.
- `seller_orders_store_id_status_created_at_index` (store_id,status,created_at) — index.

## `seller_payouts`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `payout_no` | `varchar(40)` | NO | — | UNI |
| `seller_profile_id` | `bigint unsigned` | NO | — | MUL |
| `period_start` | `date` | NO | — |  |
| `period_end` | `date` | NO | — |  |
| `gross_minor` | `bigint unsigned` | NO | — |  |
| `commission_minor` | `bigint unsigned` | NO | — |  |
| `adjustment_minor` | `bigint` | NO | 0 |  |
| `net_minor` | `bigint unsigned` | NO | — |  |
| `status` | `varchar(30)` | NO | pending |  |
| `due_at` | `timestamp` | YES | — |  |
| `paid_at` | `timestamp` | YES | — |  |
| `reference` | `varchar(150)` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `seller_profile_id` → `seller_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `seller_payouts_payout_no_unique` (payout_no) — unique.
- `seller_payouts_seller_profile_id_foreign` (seller_profile_id) — index.

## `seller_profiles`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | NO | — | UNI |
| `application_id` | `bigint unsigned` | YES | — | MUL |
| `legal_business_name` | `varchar(160)` | NO | — |  |
| `approved_category_id` | `bigint unsigned` | YES | — | MUL |
| `pickup_address_id` | `bigint unsigned` | YES | — | MUL |
| `commission_rate_bps` | `smallint unsigned` | NO | 1000 |  |
| `standing_status` | `varchar(30)` | NO | good_standing |  |
| `warning_points` | `smallint unsigned` | NO | 0 |  |
| `approved_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `application_id` → `account_applications.id`; update NO ACTION, delete SET NULL.
- `approved_category_id` → `categories.id`; update NO ACTION, delete RESTRICT.
- `pickup_address_id` → `addresses.id`; update NO ACTION, delete SET NULL.
- `user_id` → `users.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `seller_profiles_application_id_foreign` (application_id) — index.
- `seller_profiles_approved_category_id_foreign` (approved_category_id) — index.
- `seller_profiles_pickup_address_id_foreign` (pickup_address_id) — index.
- `seller_profiles_user_id_unique` (user_id) — unique.

## `seller_transactions`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `transaction_no` | `varchar(40)` | NO | — | UNI |
| `seller_profile_id` | `bigint unsigned` | NO | — | MUL |
| `seller_order_id` | `bigint unsigned` | YES | — | MUL |
| `payment_id` | `bigint unsigned` | YES | — | MUL |
| `refund_id` | `bigint unsigned` | YES | — | MUL |
| `commission_id` | `bigint unsigned` | YES | — | MUL |
| `type` | `varchar(40)` | NO | — |  |
| `amount_minor` | `bigint` | NO | — |  |
| `status` | `varchar(30)` | NO | pending |  |
| `available_at` | `timestamp` | YES | — |  |
| `posted_at` | `timestamp` | YES | — |  |
| `description` | `varchar(255)` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `commission_id` → `platform_commissions.id`; update NO ACTION, delete SET NULL.
- `payment_id` → `payments.id`; update NO ACTION, delete SET NULL.
- `refund_id` → `refunds.id`; update NO ACTION, delete SET NULL.
- `seller_order_id` → `seller_orders.id`; update NO ACTION, delete SET NULL.
- `seller_profile_id` → `seller_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `seller_transactions_commission_id_foreign` (commission_id) — index.
- `seller_transactions_payment_id_foreign` (payment_id) — index.
- `seller_transactions_refund_id_foreign` (refund_id) — index.
- `seller_transactions_seller_order_id_foreign` (seller_order_id) — index.
- `seller_transactions_seller_profile_id_status_created_at_index` (seller_profile_id,status,created_at) — index.
- `seller_transactions_transaction_no_unique` (transaction_no) — unique.

## `seller_warnings`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `warning_no` | `varchar(32)` | NO | — | UNI |
| `seller_profile_id` | `bigint unsigned` | NO | — | MUL |
| `violation_id` | `bigint unsigned` | YES | — | MUL |
| `level` | `varchar(20)` | NO | — |  |
| `points` | `smallint unsigned` | NO | 0 |  |
| `reason` | `text` | NO | — |  |
| `status` | `varchar(20)` | NO | active |  |
| `issued_by` | `bigint unsigned` | YES | — | MUL |
| `issued_at` | `timestamp` | NO | — |  |
| `expires_at` | `timestamp` | YES | — |  |
| `acknowledged_at` | `timestamp` | YES | — |  |
| `revoked_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `issued_by` → `users.id`; update NO ACTION, delete SET NULL.
- `seller_profile_id` → `seller_profiles.id`; update NO ACTION, delete RESTRICT.
- `violation_id` → `product_violations.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `seller_warnings_issued_by_foreign` (issued_by) — index.
- `seller_warnings_seller_profile_id_foreign` (seller_profile_id) — index.
- `seller_warnings_violation_id_foreign` (violation_id) — index.
- `seller_warnings_warning_no_unique` (warning_no) — unique.

## `shipment_events`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `shipment_id` | `bigint unsigned` | NO | — | MUL |
| `parcel_id` | `bigint unsigned` | YES | — | MUL |
| `event_type` | `varchar(50)` | NO | — |  |
| `from_status` | `varchar(40)` | YES | — |  |
| `to_status` | `varchar(40)` | NO | — |  |
| `sorting_center_id` | `bigint unsigned` | YES | — | MUL |
| `sorting_zone_id` | `bigint unsigned` | YES | — | MUL |
| `actor_user_id` | `bigint unsigned` | YES | — | MUL |
| `source` | `varchar(20)` | NO | system |  |
| `scan_method` | `varchar(20)` | YES | — |  |
| `notes` | `text` | YES | — |  |
| `metadata` | `json` | YES | — |  |
| `occurred_at` | `timestamp` | NO | — |  |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `actor_user_id` → `users.id`; update NO ACTION, delete SET NULL.
- `parcel_id` → `parcels.id`; update NO ACTION, delete RESTRICT.
- `shipment_id` → `shipments.id`; update NO ACTION, delete RESTRICT.
- `sorting_center_id` → `sorting_centers.id`; update NO ACTION, delete SET NULL.
- `sorting_zone_id` → `sorting_zones.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `shipment_events_actor_user_id_foreign` (actor_user_id) — index.
- `shipment_events_parcel_id_occurred_at_index` (parcel_id,occurred_at) — index.
- `shipment_events_shipment_id_occurred_at_index` (shipment_id,occurred_at) — index.
- `shipment_events_sorting_center_id_foreign` (sorting_center_id) — index.
- `shipment_events_sorting_zone_id_foreign` (sorting_zone_id) — index.

## `shipments`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `shipment_no` | `varchar(32)` | NO | — | UNI |
| `seller_order_id` | `bigint unsigned` | NO | — | MUL |
| `logistics_profile_id` | `bigint unsigned` | NO | — | MUL |
| `origin_address_id` | `bigint unsigned` | YES | — | MUL |
| `destination_address_id` | `bigint unsigned` | YES | — | MUL |
| `purpose` | `varchar(20)` | NO | outbound |  |
| `status` | `varchar(40)` | NO | pending_waybill |  |
| `shipping_fee_minor` | `bigint unsigned` | NO | 0 |  |
| `cod_amount_minor` | `bigint unsigned` | NO | 0 |  |
| `cod_collected_at` | `timestamp` | YES | — |  |
| `estimated_delivery_at` | `timestamp` | YES | — |  |
| `delivered_at` | `timestamp` | YES | — |  |
| `returned_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `destination_address_id` → `addresses.id`; update NO ACTION, delete SET NULL.
- `logistics_profile_id` → `logistics_profiles.id`; update NO ACTION, delete RESTRICT.
- `origin_address_id` → `addresses.id`; update NO ACTION, delete SET NULL.
- `seller_order_id` → `seller_orders.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `shipments_destination_address_id_foreign` (destination_address_id) — index.
- `shipments_logistics_profile_id_status_index` (logistics_profile_id,status) — index.
- `shipments_origin_address_id_foreign` (origin_address_id) — index.
- `shipments_seller_order_id_status_index` (seller_order_id,status) — index.
- `shipments_shipment_no_unique` (shipment_no) — unique.

## `sorting_centers`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `logistics_profile_id` | `bigint unsigned` | NO | — | MUL |
| `address_id` | `bigint unsigned` | NO | — | MUL |
| `name` | `varchar(160)` | NO | — |  |
| `code` | `varchar(30)` | NO | — | UNI |
| `contact_phone` | `varchar(30)` | YES | — |  |
| `operating_hours` | `varchar(100)` | YES | — |  |
| `daily_capacity` | `int unsigned` | YES | — |  |
| `status` | `varchar(20)` | NO | active |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `address_id` → `addresses.id`; update NO ACTION, delete RESTRICT.
- `logistics_profile_id` → `logistics_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `sorting_centers_address_id_foreign` (address_id) — index.
- `sorting_centers_code_unique` (code) — unique.
- `sorting_centers_logistics_profile_id_foreign` (logistics_profile_id) — index.

## `sorting_zones`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `sorting_center_id` | `bigint unsigned` | NO | — | MUL |
| `code` | `varchar(30)` | NO | — |  |
| `name` | `varchar(120)` | NO | — |  |
| `destination_rules` | `json` | YES | — |  |
| `status` | `varchar(20)` | NO | active |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `sorting_center_id` → `sorting_centers.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `sorting_zones_sorting_center_id_code_unique` (sorting_center_id,code) — unique.

## `stores`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `seller_profile_id` | `bigint unsigned` | NO | — | UNI |
| `name` | `varchar(160)` | NO | — |  |
| `slug` | `varchar(180)` | NO | — | UNI |
| `description` | `text` | YES | — |  |
| `contact_email` | `varchar(255)` | YES | — |  |
| `contact_phone` | `varchar(30)` | YES | — |  |
| `logo_path` | `varchar(500)` | YES | — |  |
| `banner_path` | `varchar(500)` | YES | — |  |
| `publication_status` | `varchar(30)` | NO | draft |  |
| `published_at` | `timestamp` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `seller_profile_id` → `seller_profiles.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `stores_seller_profile_id_unique` (seller_profile_id) — unique.
- `stores_slug_unique` (slug) — unique.

## `support_tickets`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `ticket_no` | `varchar(32)` | NO | — | UNI |
| `user_id` | `bigint unsigned` | YES | — | MUL |
| `name` | `varchar(160)` | YES | — |  |
| `email` | `varchar(255)` | YES | — |  |
| `role_label` | `varchar(50)` | YES | — |  |
| `topic` | `varchar(80)` | NO | — |  |
| `subject` | `varchar(180)` | NO | — |  |
| `message` | `text` | NO | — |  |
| `reference` | `varchar(50)` | YES | — |  |
| `status` | `varchar(30)` | NO | open |  |
| `priority` | `varchar(20)` | NO | normal |  |
| `assigned_to` | `bigint unsigned` | YES | — | MUL |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `assigned_to` → `users.id`; update NO ACTION, delete SET NULL.
- `user_id` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `support_tickets_assigned_to_foreign` (assigned_to) — index.
- `support_tickets_ticket_no_unique` (ticket_no) — unique.
- `support_tickets_user_id_foreign` (user_id) — index.

## `system_settings`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `key` | `varchar(100)` | NO | — | UNI |
| `value` | `json` | NO | — |  |
| `value_type` | `varchar(20)` | NO | — |  |
| `is_public` | `tinyint(1)` | NO | 0 |  |
| `updated_by` | `bigint unsigned` | YES | — | MUL |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `updated_by` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `system_settings_key_unique` (key) — unique.
- `system_settings_updated_by_foreign` (updated_by) — index.

## `user_notification_preferences`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | NO | — | MUL |
| `event_key` | `varchar(80)` | NO | — |  |
| `in_app` | `tinyint(1)` | NO | 1 |  |
| `email` | `tinyint(1)` | NO | 0 |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `user_id` → `users.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `user_notification_preferences_user_id_event_key_unique` (user_id,event_key) — unique.

## `users`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `name` | `varchar(255)` | YES | — |  |
| `first_name` | `varchar(80)` | NO | — |  |
| `middle_initial` | `varchar(5)` | YES | — |  |
| `middle_name` | `varchar(80)` | YES | — |  |
| `last_name` | `varchar(80)` | NO | — |  |
| `sex` | `varchar(30)` | YES | — |  |
| `birthday` | `date` | YES | — |  |
| `birth_date` | `date` | YES | — |  |
| `email` | `varchar(255)` | NO | — | UNI |
| `phone` | `varchar(30)` | YES | — | MUL |
| `contact_number` | `varchar(30)` | YES | — |  |
| `role` | `varchar(32)` | YES | — | MUL |
| `province` | `varchar(120)` | YES | — |  |
| `city` | `varchar(120)` | YES | — |  |
| `barangay` | `varchar(120)` | YES | — |  |
| `street_address` | `varchar(255)` | YES | — |  |
| `business_name` | `varchar(160)` | YES | — |  |
| `business_category` | `varchar(120)` | YES | — |  |
| `vehicle_type` | `varchar(80)` | YES | — |  |
| `plate_number` | `varchar(30)` | YES | — |  |
| `valid_id_path` | `varchar(500)` | YES | — |  |
| `business_permit_path` | `varchar(500)` | YES | — |  |
| `or_cr_path` | `varchar(500)` | YES | — |  |
| `driver_license_path` | `varchar(500)` | YES | — |  |
| `logistics_id` | `bigint unsigned` | YES | — | MUL |
| `approved_by` | `bigint unsigned` | YES | — | MUL |
| `approved_at` | `timestamp` | YES | — |  |
| `rejection_reason` | `text` | YES | — |  |
| `password` | `varchar(255)` | NO | — |  |
| `status` | `varchar(32)` | NO | pending | MUL |
| `email_verified_at` | `timestamp` | YES | — |  |
| `last_login_at` | `timestamp` | YES | — |  |
| `suspended_at` | `timestamp` | YES | — |  |
| `deactivated_at` | `timestamp` | YES | — |  |
| `banned_at` | `timestamp` | YES | — |  |
| `status_reason` | `text` | YES | — |  |
| `remember_token` | `varchar(100)` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |
| `deleted_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `approved_by` → `users.id`; update NO ACTION, delete SET NULL.
- `logistics_id` → `users.id`; update NO ACTION, delete SET NULL.

**Indexes**

- `users_approved_by_foreign` (approved_by) — index.
- `users_email_unique` (email) — unique.
- `users_logistics_id_foreign` (logistics_id) — index.
- `users_phone_index` (phone) — index.
- `users_role_index` (role) — index.
- `users_status_index` (status) — index.

## `violation_actions`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `violation_id` | `bigint unsigned` | NO | — | MUL |
| `action_type` | `varchar(40)` | NO | — |  |
| `from_status` | `varchar(30)` | YES | — |  |
| `to_status` | `varchar(30)` | YES | — |  |
| `actor_user_id` | `bigint unsigned` | YES | — | MUL |
| `reason` | `text` | YES | — |  |
| `metadata` | `json` | YES | — |  |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `actor_user_id` → `users.id`; update NO ACTION, delete SET NULL.
- `violation_id` → `product_violations.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `violation_actions_actor_user_id_foreign` (actor_user_id) — index.
- `violation_actions_violation_id_foreign` (violation_id) — index.

## `waybills`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `shipment_id` | `bigint unsigned` | NO | — | UNI |
| `waybill_no` | `varchar(40)` | NO | — | UNI |
| `scan_token` | `char(64)` | NO | — | UNI |
| `barcode_value` | `varchar(120)` | NO | — | UNI |
| `piece_count` | `smallint unsigned` | NO | 1 |  |
| `total_weight_kg` | `decimal(10,3)` | NO | — |  |
| `length_cm` | `decimal(10,2)` | YES | — |  |
| `width_cm` | `decimal(10,2)` | YES | — |  |
| `height_cm` | `decimal(10,2)` | YES | — |  |
| `status` | `varchar(30)` | NO | generated |  |
| `generated_by` | `bigint unsigned` | YES | — | MUL |
| `generated_at` | `timestamp` | NO | — |  |
| `printed_at` | `timestamp` | YES | — |  |
| `print_count` | `smallint unsigned` | NO | 0 |  |
| `voided_at` | `timestamp` | YES | — |  |
| `void_reason` | `text` | YES | — |  |
| `created_at` | `timestamp` | YES | — |  |
| `updated_at` | `timestamp` | YES | — |  |

**Foreign keys**

- `generated_by` → `users.id`; update NO ACTION, delete SET NULL.
- `shipment_id` → `shipments.id`; update NO ACTION, delete RESTRICT.

**Indexes**

- `waybills_barcode_value_unique` (barcode_value) — unique.
- `waybills_generated_by_foreign` (generated_by) — index.
- `waybills_scan_token_unique` (scan_token) — unique.
- `waybills_shipment_id_unique` (shipment_id) — unique.
- `waybills_waybill_no_unique` (waybill_no) — unique.

## `wishlists`

| Column | MySQL type | Null | Default | Key |
|---|---|---:|---|---|
| `id` | `bigint unsigned` | NO | — | PRI |
| `user_id` | `bigint unsigned` | YES | — | MUL |
| `session_token` | `varchar(100)` | YES | — | MUL |
| `product_id` | `bigint unsigned` | NO | — | MUL |
| `created_at` | `timestamp` | NO | CURRENT_TIMESTAMP |  |

**Foreign keys**

- `product_id` → `products.id`; update NO ACTION, delete CASCADE.
- `user_id` → `users.id`; update NO ACTION, delete CASCADE.

**Indexes**

- `wishlists_product_id_foreign` (product_id) — index.
- `wishlists_session_token_product_id_index` (session_token,product_id) — index.
- `wishlists_user_id_product_id_unique` (user_id,product_id) — unique.

