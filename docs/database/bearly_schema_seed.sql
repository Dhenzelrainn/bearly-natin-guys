-- Bearly database backup
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `account_applications`;
CREATE TABLE `account_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `requested_role_id` bigint unsigned NOT NULL,
  `sponsor_logistics_profile_id` bigint unsigned DEFAULT NULL,
  `business_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_category_id` bigint unsigned DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `review_started_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `decision_reason` text COLLATE utf8mb4_unicode_ci,
  `revision_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_applications_application_no_unique` (`application_no`),
  KEY `account_applications_user_id_foreign` (`user_id`),
  KEY `account_applications_business_category_id_foreign` (`business_category_id`),
  KEY `account_applications_reviewed_by_foreign` (`reviewed_by`),
  KEY `account_applications_requested_role_id_status_submitted_at_index` (`requested_role_id`,`status`,`submitted_at`),
  KEY `account_applications_status_index` (`status`),
  KEY `account_applications_sponsor_logistics_profile_id_foreign` (`sponsor_logistics_profile_id`),
  CONSTRAINT `account_applications_business_category_id_foreign` FOREIGN KEY (`business_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `account_applications_requested_role_id_foreign` FOREIGN KEY (`requested_role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `account_applications_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `account_applications_sponsor_logistics_profile_id_foreign` FOREIGN KEY (`sponsor_logistics_profile_id`) REFERENCES `logistics_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `account_applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `addresses`;
CREATE TABLE `addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `label` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Home',
  `recipient_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `house_number` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barangay` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city_municipality` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `province` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `postal_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `psgc_province_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `psgc_city_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `psgc_barangay_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `is_default_shipping` tinyint(1) NOT NULL DEFAULT '0',
  `is_default_pickup` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `addresses_user_id_is_default_shipping_index` (`user_id`,`is_default_shipping`),
  CONSTRAINT `addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `announcement_roles`;
CREATE TABLE `announcement_roles` (
  `announcement_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`announcement_id`,`role_id`),
  KEY `announcement_roles_role_id_foreign` (`role_id`),
  CONSTRAINT `announcement_roles_announcement_id_foreign` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcement_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `announcement_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint unsigned DEFAULT NULL,
  `publish_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcements_announcement_no_unique` (`announcement_no`),
  KEY `announcements_created_by_foreign` (`created_by`),
  CONSTRAINT `announcements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `application_documents`;
CREATE TABLE `application_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `document_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `verification_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `application_documents_verified_by_foreign` (`verified_by`),
  KEY `application_documents_application_id_document_type_index` (`application_id`,`document_type`),
  CONSTRAINT `application_documents_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `account_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auditable_id` bigint unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `audit_logs_actor_user_id_foreign` (`actor_user_id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  CONSTRAINT `audit_logs_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `cart_items`;
CREATE TABLE `cart_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` bigint unsigned NOT NULL,
  `product_variant_id` bigint unsigned NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `selected` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cart_items_cart_id_product_variant_id_unique` (`cart_id`,`product_variant_id`),
  KEY `cart_items_product_variant_id_foreign` (`product_variant_id`),
  CONSTRAINT `cart_items_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_product_variant_id_foreign` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `carts`;
CREATE TABLE `carts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `session_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `carts_user_id_foreign` (`user_id`),
  KEY `carts_session_token_status_index` (`session_token`,`status`),
  CONSTRAINT `carts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_restricted` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_slug_unique` (`slug`),
  UNIQUE KEY `categories_parent_id_name_unique` (`parent_id`,`name`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` VALUES ('1', NULL, 'Fashion and Apparel', 'fashion-and-apparel', NULL, '0', '1', '1', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('2', NULL, 'Jewelry and Watches', 'jewelry-and-watches', NULL, '0', '1', '2', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('3', NULL, 'Electronics and Gadgets', 'electronics-and-gadgets', NULL, '0', '1', '3', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('4', NULL, 'Home and Furniture', 'home-and-furniture', NULL, '0', '1', '4', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('5', NULL, 'Beauty and Personal Care', 'beauty-and-personal-care', NULL, '0', '1', '5', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('6', NULL, 'Food and Gourmet', 'food-and-gourmet', NULL, '0', '1', '6', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('7', NULL, 'Sports and Outdoors', 'sports-and-outdoors', NULL, '0', '1', '7', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('8', NULL, 'Books and Stationery', 'books-and-stationery', NULL, '0', '1', '8', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('9', NULL, 'Automotive and Parts', 'automotive-and-parts', NULL, '0', '1', '9', '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `categories` VALUES ('10', NULL, 'Toys and Hobbies', 'toys-and-hobbies', NULL, '0', '1', '10', '2026-09-25 06:15:26', '2026-09-25 06:15:26');

DROP TABLE IF EXISTS `compliance_rules`;
CREATE TABLE `compliance_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_field` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pattern` text COLLATE utf8mb4_unicode_ci,
  `category_id` bigint unsigned DEFAULT NULL,
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `configuration` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `compliance_rules_code_unique` (`code`),
  KEY `compliance_rules_category_id_foreign` (`category_id`),
  KEY `compliance_rules_created_by_foreign` (`created_by`),
  CONSTRAINT `compliance_rules_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compliance_rules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `compliance_rules` VALUES ('1', 'PROHIBITED_FIREARMS', 'Firearms and ammunition', 'keyword', 'name_description', 'firearm|handgun|pistol|rifle|shotgun|ammunition|live rounds', NULL, 'critical', 'block', NULL, '1', '1', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `compliance_rules` VALUES ('2', 'PROHIBITED_ILLEGAL_DRUGS', 'Illegal drugs', 'keyword', 'name_description', 'cocaine|methamphetamine|shabu|heroin|ecstasy', NULL, 'critical', 'block', NULL, '1', '1', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `compliance_rules` VALUES ('3', 'SUSPICIOUS_HEALTH_CLAIMS', 'Unverified health claims', 'phrase', 'name_description', 'guaranteed cure|instant cure|fda approved|no side effects', NULL, 'high', 'hold', NULL, '1', '1', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `compliance_rules` VALUES ('4', 'CATEGORY_MISMATCH', 'Product category does not match seller approval', 'category_mismatch', 'category', NULL, NULL, 'high', 'hold', NULL, '1', '1', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');

DROP TABLE IF EXISTS `conversation_participants`;
CREATE TABLE `conversation_participants` (
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `participant_role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`conversation_id`,`user_id`),
  KEY `conversation_participants_user_id_foreign` (`user_id`),
  CONSTRAINT `conversation_participants_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversation_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `conversations`;
CREATE TABLE `conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `seller_order_id` bigint unsigned DEFAULT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `dispute_id` bigint unsigned DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_by` bigint unsigned DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversations_order_id_foreign` (`order_id`),
  KEY `conversations_seller_order_id_foreign` (`seller_order_id`),
  KEY `conversations_product_id_foreign` (`product_id`),
  KEY `conversations_dispute_id_foreign` (`dispute_id`),
  KEY `conversations_created_by_foreign` (`created_by`),
  KEY `conversations_last_message_at_index` (`last_message_at`),
  CONSTRAINT `conversations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `conversations_dispute_id_foreign` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `conversations_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `conversations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `conversations_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `seller_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `delivery_attempts`;
CREATE TABLE `delivery_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parcel_id` bigint unsigned NOT NULL,
  `dispatch_batch_id` bigint unsigned DEFAULT NULL,
  `rider_profile_id` bigint unsigned NOT NULL,
  `attempt_no` smallint unsigned NOT NULL,
  `outcome` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `failure_reason` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_attempts_parcel_id_attempt_no_unique` (`parcel_id`,`attempt_no`),
  KEY `delivery_attempts_dispatch_batch_id_foreign` (`dispatch_batch_id`),
  KEY `delivery_attempts_rider_profile_id_foreign` (`rider_profile_id`),
  CONSTRAINT `delivery_attempts_dispatch_batch_id_foreign` FOREIGN KEY (`dispatch_batch_id`) REFERENCES `dispatch_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_attempts_parcel_id_foreign` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `delivery_attempts_rider_profile_id_foreign` FOREIGN KEY (`rider_profile_id`) REFERENCES `rider_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `delivery_proofs`;
CREATE TABLE `delivery_proofs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_attempt_id` bigint unsigned NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_signature_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_verified_at` timestamp NULL DEFAULT NULL,
  `captured_at` timestamp NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_proofs_delivery_attempt_id_foreign` (`delivery_attempt_id`),
  KEY `delivery_proofs_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `delivery_proofs_delivery_attempt_id_foreign` FOREIGN KEY (`delivery_attempt_id`) REFERENCES `delivery_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_proofs_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `dispatch_batch_parcels`;
CREATE TABLE `dispatch_batch_parcels` (
  `dispatch_batch_id` bigint unsigned NOT NULL,
  `parcel_id` bigint unsigned NOT NULL,
  `sequence` smallint unsigned DEFAULT NULL,
  `loaded_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`dispatch_batch_id`,`parcel_id`),
  KEY `dispatch_batch_parcels_parcel_id_foreign` (`parcel_id`),
  CONSTRAINT `dispatch_batch_parcels_dispatch_batch_id_foreign` FOREIGN KEY (`dispatch_batch_id`) REFERENCES `dispatch_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispatch_batch_parcels_parcel_id_foreign` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `dispatch_batches`;
CREATE TABLE `dispatch_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sorting_center_id` bigint unsigned NOT NULL,
  `sorting_zone_id` bigint unsigned DEFAULT NULL,
  `rider_profile_id` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'preparing',
  `prepared_by` bigint unsigned DEFAULT NULL,
  `prepared_at` timestamp NULL DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dispatch_batches_batch_no_unique` (`batch_no`),
  KEY `dispatch_batches_sorting_center_id_foreign` (`sorting_center_id`),
  KEY `dispatch_batches_sorting_zone_id_foreign` (`sorting_zone_id`),
  KEY `dispatch_batches_rider_profile_id_foreign` (`rider_profile_id`),
  KEY `dispatch_batches_prepared_by_foreign` (`prepared_by`),
  CONSTRAINT `dispatch_batches_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dispatch_batches_rider_profile_id_foreign` FOREIGN KEY (`rider_profile_id`) REFERENCES `rider_profiles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `dispatch_batches_sorting_center_id_foreign` FOREIGN KEY (`sorting_center_id`) REFERENCES `sorting_centers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `dispatch_batches_sorting_zone_id_foreign` FOREIGN KEY (`sorting_zone_id`) REFERENCES `sorting_zones` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `dispute_events`;
CREATE TABLE `dispute_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dispute_id` bigint unsigned NOT NULL,
  `event_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `from_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dispute_events_dispute_id_foreign` (`dispute_id`),
  KEY `dispute_events_actor_user_id_foreign` (`actor_user_id`),
  CONSTRAINT `dispute_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dispute_events_dispute_id_foreign` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `dispute_evidence`;
CREATE TABLE `dispute_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dispute_id` bigint unsigned NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dispute_evidence_dispute_id_foreign` (`dispute_id`),
  KEY `dispute_evidence_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `dispute_evidence_dispute_id_foreign` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispute_evidence_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `dispute_participants`;
CREATE TABLE `dispute_participants` (
  `dispute_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `participant_role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`dispute_id`,`user_id`),
  KEY `dispute_participants_user_id_foreign` (`user_id`),
  CONSTRAINT `dispute_participants_dispute_id_foreign` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispute_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `disputes`;
CREATE TABLE `disputes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dispute_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_order_id` bigint unsigned DEFAULT NULL,
  `return_request_id` bigint unsigned DEFAULT NULL,
  `shipment_id` bigint unsigned DEFAULT NULL,
  `opened_by` bigint unsigned NOT NULL,
  `subject` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `amount_minor` bigint unsigned DEFAULT NULL,
  `assigned_to` bigint unsigned DEFAULT NULL,
  `opened_at` timestamp NOT NULL,
  `response_due_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `resolution` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `disputes_dispute_no_unique` (`dispute_no`),
  KEY `disputes_seller_order_id_foreign` (`seller_order_id`),
  KEY `disputes_return_request_id_foreign` (`return_request_id`),
  KEY `disputes_shipment_id_foreign` (`shipment_id`),
  KEY `disputes_opened_by_foreign` (`opened_by`),
  KEY `disputes_assigned_to_foreign` (`assigned_to`),
  CONSTRAINT `disputes_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `disputes_opened_by_foreign` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `disputes_return_request_id_foreign` FOREIGN KEY (`return_request_id`) REFERENCES `return_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `disputes_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `seller_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `disputes_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `inventory_movements`;
CREATE TABLE `inventory_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `variant_id` bigint unsigned NOT NULL,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity_delta` int NOT NULL,
  `balance_after` int unsigned NOT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `inventory_movements_created_by_foreign` (`created_by`),
  KEY `inventory_movements_variant_id_occurred_at_index` (`variant_id`,`occurred_at`),
  KEY `inventory_movements_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  CONSTRAINT `inventory_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_movements_variant_id_foreign` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `logistics_profiles`;
CREATE TABLE `logistics_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `application_id` bigint unsigned DEFAULT NULL,
  `legal_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `logistics_profiles_user_id_unique` (`user_id`),
  KEY `logistics_profiles_application_id_foreign` (`application_id`),
  CONSTRAINT `logistics_profiles_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `account_applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `logistics_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `message_attachments`;
CREATE TABLE `message_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint unsigned NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_attachments_message_id_foreign` (`message_id`),
  CONSTRAINT `message_attachments_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `sender_id` bigint unsigned DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `sent_at` timestamp NOT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_sender_id_foreign` (`sender_id`),
  KEY `messages_conversation_id_sent_at_index` (`conversation_id`,`sent_at`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` VALUES ('1', '0001_01_01_000000_create_users_table', '1');
INSERT INTO `migrations` VALUES ('2', '0001_01_01_000001_create_bearly_platform_schema', '1');
INSERT INTO `migrations` VALUES ('3', '2026_09_25_000002_add_deleted_at_to_users_table', '1');

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `seller_order_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_variant_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant_name` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` json DEFAULT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `quantity` int unsigned NOT NULL,
  `discount_minor` bigint unsigned NOT NULL DEFAULT '0',
  `subtotal_minor` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_seller_order_id_foreign` (`seller_order_id`),
  KEY `order_items_product_id_foreign` (`product_id`),
  KEY `order_items_product_variant_id_foreign` (`product_variant_id`),
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_product_variant_id_foreign` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `seller_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `buyer_id` bigint unsigned NOT NULL,
  `shipping_address_id` bigint unsigned DEFAULT NULL,
  `recipient_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_line` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barangay` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city_municipality` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `province` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `postal_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PHP',
  `subtotal_minor` bigint unsigned NOT NULL,
  `shipping_fee_minor` bigint unsigned NOT NULL DEFAULT '0',
  `discount_minor` bigint unsigned NOT NULL DEFAULT '0',
  `total_minor` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_payment',
  `payment_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `placed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_no_unique` (`order_no`),
  KEY `orders_shipping_address_id_foreign` (`shipping_address_id`),
  KEY `orders_buyer_id_created_at_index` (`buyer_id`,`created_at`),
  KEY `orders_status_created_at_index` (`status`,`created_at`),
  CONSTRAINT `orders_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `orders_shipping_address_id_foreign` FOREIGN KEY (`shipping_address_id`) REFERENCES `addresses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `parcels`;
CREATE TABLE `parcels` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint unsigned NOT NULL,
  `waybill_id` bigint unsigned NOT NULL,
  `parcel_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `piece_sequence` smallint unsigned NOT NULL,
  `weight_kg` decimal(10,3) NOT NULL,
  `length_cm` decimal(10,2) DEFAULT NULL,
  `width_cm` decimal(10,2) DEFAULT NULL,
  `height_cm` decimal(10,2) DEFAULT NULL,
  `size_class` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `current_sorting_center_id` bigint unsigned DEFAULT NULL,
  `current_zone_id` bigint unsigned DEFAULT NULL,
  `last_event_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parcels_waybill_id_piece_sequence_unique` (`waybill_id`,`piece_sequence`),
  UNIQUE KEY `parcels_parcel_no_unique` (`parcel_no`),
  KEY `parcels_shipment_id_foreign` (`shipment_id`),
  KEY `parcels_current_sorting_center_id_foreign` (`current_sorting_center_id`),
  KEY `parcels_current_zone_id_foreign` (`current_zone_id`),
  KEY `parcels_status_current_sorting_center_id_index` (`status`,`current_sorting_center_id`),
  CONSTRAINT `parcels_current_sorting_center_id_foreign` FOREIGN KEY (`current_sorting_center_id`) REFERENCES `sorting_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `parcels_current_zone_id_foreign` FOREIGN KEY (`current_zone_id`) REFERENCES `sorting_zones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `parcels_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `parcels_waybill_id_foreign` FOREIGN KEY (`waybill_id`) REFERENCES `waybills` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `method` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_reference` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `initiated_at` timestamp NULL DEFAULT NULL,
  `authorized_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `failure_reason` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_payment_no_unique` (`payment_no`),
  KEY `payments_order_id_status_index` (`order_id`,`status`),
  KEY `payments_provider_reference_index` (`provider_reference`),
  CONSTRAINT `payments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `pickup_assignments`;
CREATE TABLE `pickup_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pickup_request_id` bigint unsigned NOT NULL,
  `rider_profile_id` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assigned',
  `assigned_by` bigint unsigned DEFAULT NULL,
  `assigned_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `arrived_at` timestamp NULL DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pickup_assignments_pickup_request_id_foreign` (`pickup_request_id`),
  KEY `pickup_assignments_assigned_by_foreign` (`assigned_by`),
  KEY `pickup_assignments_rider_profile_id_status_assigned_at_index` (`rider_profile_id`,`status`,`assigned_at`),
  CONSTRAINT `pickup_assignments_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pickup_assignments_pickup_request_id_foreign` FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pickup_assignments_rider_profile_id_foreign` FOREIGN KEY (`rider_profile_id`) REFERENCES `rider_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `pickup_request_parcels`;
CREATE TABLE `pickup_request_parcels` (
  `pickup_request_id` bigint unsigned NOT NULL,
  `parcel_id` bigint unsigned NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`pickup_request_id`,`parcel_id`),
  KEY `pickup_request_parcels_parcel_id_foreign` (`parcel_id`),
  CONSTRAINT `pickup_request_parcels_parcel_id_foreign` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pickup_request_parcels_pickup_request_id_foreign` FOREIGN KEY (`pickup_request_id`) REFERENCES `pickup_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `pickup_requests`;
CREATE TABLE `pickup_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pickup_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `logistics_profile_id` bigint unsigned NOT NULL,
  `pickup_address_id` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `requested_date` date NOT NULL,
  `window_start` datetime NOT NULL,
  `window_end` datetime NOT NULL,
  `seller_instructions` text COLLATE utf8mb4_unicode_ci,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pickup_requests_pickup_no_unique` (`pickup_no`),
  KEY `pickup_requests_store_id_foreign` (`store_id`),
  KEY `pickup_requests_pickup_address_id_foreign` (`pickup_address_id`),
  KEY `pickup_requests_verified_by_foreign` (`verified_by`),
  KEY `pickup_requests_logistics_profile_id_status_requested_date_index` (`logistics_profile_id`,`status`,`requested_date`),
  CONSTRAINT `pickup_requests_logistics_profile_id_foreign` FOREIGN KEY (`logistics_profile_id`) REFERENCES `logistics_profiles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pickup_requests_pickup_address_id_foreign` FOREIGN KEY (`pickup_address_id`) REFERENCES `addresses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pickup_requests_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pickup_requests_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `platform_commissions`;
CREATE TABLE `platform_commissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `seller_order_id` bigint unsigned NOT NULL,
  `refund_id` bigint unsigned DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_amount_minor` bigint unsigned NOT NULL,
  `rate_bps` smallint unsigned NOT NULL,
  `amount_minor` bigint NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'estimated',
  `calculated_at` timestamp NOT NULL,
  `finalized_at` timestamp NULL DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `platform_commissions_seller_order_id_foreign` (`seller_order_id`),
  KEY `platform_commissions_refund_id_foreign` (`refund_id`),
  CONSTRAINT `platform_commissions_refund_id_foreign` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `platform_commissions_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `seller_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `policies`;
CREATE TABLE `policies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `policies_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `policy_versions`;
CREATE TABLE `policy_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` bigint unsigned NOT NULL,
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint unsigned DEFAULT NULL,
  `effective_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `policy_versions_policy_id_version_unique` (`policy_id`,`version`),
  KEY `policy_versions_created_by_foreign` (`created_by`),
  CONSTRAINT `policy_versions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `policy_versions_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `product_compliance_check_matches`;
CREATE TABLE `product_compliance_check_matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `check_id` bigint unsigned NOT NULL,
  `rule_id` bigint unsigned NOT NULL,
  `matched_field` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `matched_excerpt` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `score` decimal(5,4) DEFAULT NULL,
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recommended_action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_compliance_check_matches_check_id_foreign` (`check_id`),
  KEY `product_compliance_check_matches_rule_id_foreign` (`rule_id`),
  CONSTRAINT `product_compliance_check_matches_check_id_foreign` FOREIGN KEY (`check_id`) REFERENCES `product_compliance_checks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_compliance_check_matches_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `compliance_rules` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `product_compliance_checks`;
CREATE TABLE `product_compliance_checks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `trigger` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `result` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_snapshot` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description_snapshot` text COLLATE utf8mb4_unicode_ci,
  `category_id_snapshot` bigint unsigned DEFAULT NULL,
  `rules_version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_compliance_checks_category_id_snapshot_foreign` (`category_id_snapshot`),
  KEY `product_compliance_checks_product_id_created_at_index` (`product_id`,`created_at`),
  CONSTRAINT `product_compliance_checks_category_id_snapshot_foreign` FOREIGN KEY (`category_id_snapshot`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_compliance_checks_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `product_images`;
CREATE TABLE `product_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `variant_id` bigint unsigned DEFAULT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alt_text` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` smallint unsigned NOT NULL DEFAULT '0',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_images_variant_id_foreign` (`variant_id`),
  KEY `product_images_product_id_position_index` (`product_id`,`position`),
  CONSTRAINT `product_images_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_images_variant_id_foreign` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `product_reports`;
CREATE TABLE `product_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `report_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `reporter_user_id` bigint unsigned DEFAULT NULL,
  `reason_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_reports_report_no_unique` (`report_no`),
  KEY `product_reports_product_id_foreign` (`product_id`),
  KEY `product_reports_reporter_user_id_foreign` (`reporter_user_id`),
  KEY `product_reports_reviewed_by_foreign` (`reviewed_by`),
  CONSTRAINT `product_reports_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `product_reports_reporter_user_id_foreign` FOREIGN KEY (`reporter_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_reports_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `product_variants`;
CREATE TABLE `product_variants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `sku` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` json DEFAULT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `compare_at_price_minor` bigint unsigned DEFAULT NULL,
  `stock_on_hand` int unsigned NOT NULL DEFAULT '0',
  `stock_reserved` int unsigned NOT NULL DEFAULT '0',
  `low_stock_threshold` int unsigned NOT NULL DEFAULT '5',
  `weight_kg` decimal(10,3) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `position` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_variants_sku_unique` (`sku`),
  KEY `product_variants_product_id_is_active_index` (`product_id`,`is_active`),
  CONSTRAINT `product_variants_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `product_violations`;
CREATE TABLE `product_violations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `violation_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `seller_profile_id` bigint unsigned NOT NULL,
  `check_id` bigint unsigned DEFAULT NULL,
  `rule_id` bigint unsigned DEFAULT NULL,
  `product_report_id` bigint unsigned DEFAULT NULL,
  `source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `violation_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `detected_excerpt` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flagged',
  `assigned_to` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_violations_violation_no_unique` (`violation_no`),
  KEY `product_violations_product_id_foreign` (`product_id`),
  KEY `product_violations_seller_profile_id_foreign` (`seller_profile_id`),
  KEY `product_violations_check_id_foreign` (`check_id`),
  KEY `product_violations_rule_id_foreign` (`rule_id`),
  KEY `product_violations_product_report_id_foreign` (`product_report_id`),
  KEY `product_violations_assigned_to_foreign` (`assigned_to`),
  KEY `product_violations_status_severity_created_at_index` (`status`,`severity`,`created_at`),
  CONSTRAINT `product_violations_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_violations_check_id_foreign` FOREIGN KEY (`check_id`) REFERENCES `product_compliance_checks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_violations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `product_violations_product_report_id_foreign` FOREIGN KEY (`product_report_id`) REFERENCES `product_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_violations_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `compliance_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_violations_seller_profile_id_foreign` FOREIGN KEY (`seller_profile_id`) REFERENCES `seller_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  `name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `product_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `compliance_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_scan',
  `voucher_eligible` tinyint(1) NOT NULL DEFAULT '0',
  `published_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_store_id_slug_unique` (`store_id`,`slug`),
  KEY `products_category_id_foreign` (`category_id`),
  KEY `products_store_id_product_status_index` (`store_id`,`product_status`),
  KEY `products_compliance_status_updated_at_index` (`compliance_status`,`updated_at`),
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `products_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `refunds`;
CREATE TABLE `refunds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `refund_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `return_request_id` bigint unsigned DEFAULT NULL,
  `payment_id` bigint unsigned DEFAULT NULL,
  `order_id` bigint unsigned NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `method` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_reference` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `refunds_refund_no_unique` (`refund_no`),
  KEY `refunds_return_request_id_foreign` (`return_request_id`),
  KEY `refunds_payment_id_foreign` (`payment_id`),
  KEY `refunds_order_id_foreign` (`order_id`),
  KEY `refunds_approved_by_foreign` (`approved_by`),
  CONSTRAINT `refunds_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refunds_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `refunds_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `refunds_return_request_id_foreign` FOREIGN KEY (`return_request_id`) REFERENCES `return_requests` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `return_evidence`;
CREATE TABLE `return_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `return_request_id` bigint unsigned NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `return_evidence_return_request_id_foreign` (`return_request_id`),
  KEY `return_evidence_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `return_evidence_return_request_id_foreign` FOREIGN KEY (`return_request_id`) REFERENCES `return_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `return_evidence_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `return_items`;
CREATE TABLE `return_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `return_request_id` bigint unsigned NOT NULL,
  `order_item_id` bigint unsigned NOT NULL,
  `quantity` int unsigned NOT NULL,
  `condition_code` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolution` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refund_amount_minor` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_items_return_request_id_order_item_id_unique` (`return_request_id`,`order_item_id`),
  KEY `return_items_order_item_id_foreign` (`order_item_id`),
  CONSTRAINT `return_items_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `return_items_return_request_id_foreign` FOREIGN KEY (`return_request_id`) REFERENCES `return_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `return_requests`;
CREATE TABLE `return_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `return_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `seller_order_id` bigint unsigned NOT NULL,
  `buyer_id` bigint unsigned NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `request_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `buyer_note` text COLLATE utf8mb4_unicode_ci,
  `seller_response` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `requested_amount_minor` bigint unsigned NOT NULL,
  `response_due_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NOT NULL,
  `seller_responded_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `resolution` text COLLATE utf8mb4_unicode_ci,
  `return_shipment_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_requests_return_no_unique` (`return_no`),
  KEY `return_requests_order_id_foreign` (`order_id`),
  KEY `return_requests_seller_order_id_foreign` (`seller_order_id`),
  KEY `return_requests_buyer_id_foreign` (`buyer_id`),
  KEY `return_requests_store_id_foreign` (`store_id`),
  KEY `return_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `return_requests_return_shipment_id_foreign` (`return_shipment_id`),
  CONSTRAINT `return_requests_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `return_requests_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `return_requests_return_shipment_id_foreign` FOREIGN KEY (`return_shipment_id`) REFERENCES `shipments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `return_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `return_requests_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `seller_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `return_requests_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `rider_earnings`;
CREATE TABLE `rider_earnings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rider_profile_id` bigint unsigned NOT NULL,
  `pickup_assignment_id` bigint unsigned DEFAULT NULL,
  `delivery_attempt_id` bigint unsigned DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_minor` bigint NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `earned_at` timestamp NOT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rider_earnings_rider_profile_id_foreign` (`rider_profile_id`),
  KEY `rider_earnings_pickup_assignment_id_foreign` (`pickup_assignment_id`),
  KEY `rider_earnings_delivery_attempt_id_foreign` (`delivery_attempt_id`),
  CONSTRAINT `rider_earnings_delivery_attempt_id_foreign` FOREIGN KEY (`delivery_attempt_id`) REFERENCES `delivery_attempts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rider_earnings_pickup_assignment_id_foreign` FOREIGN KEY (`pickup_assignment_id`) REFERENCES `pickup_assignments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rider_earnings_rider_profile_id_foreign` FOREIGN KEY (`rider_profile_id`) REFERENCES `rider_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `rider_profiles`;
CREATE TABLE `rider_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `logistics_profile_id` bigint unsigned NOT NULL,
  `home_sorting_center_id` bigint unsigned DEFAULT NULL,
  `current_zone_id` bigint unsigned DEFAULT NULL,
  `vehicle_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vehicle_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plate_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parcel_capacity` smallint unsigned DEFAULT NULL,
  `emergency_contact_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `availability_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `verification_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rider_profiles_user_id_unique` (`user_id`),
  KEY `rider_profiles_home_sorting_center_id_foreign` (`home_sorting_center_id`),
  KEY `rider_profiles_current_zone_id_foreign` (`current_zone_id`),
  KEY `rider_profiles_logistics_profile_id_availability_status_index` (`logistics_profile_id`,`availability_status`),
  CONSTRAINT `rider_profiles_current_zone_id_foreign` FOREIGN KEY (`current_zone_id`) REFERENCES `sorting_zones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rider_profiles_home_sorting_center_id_foreign` FOREIGN KEY (`home_sorting_center_id`) REFERENCES `sorting_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rider_profiles_logistics_profile_id_foreign` FOREIGN KEY (`logistics_profile_id`) REFERENCES `logistics_profiles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rider_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `role_user`;
CREATE TABLE `role_user` (
  `role_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `assigned_by` bigint unsigned DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`,`user_id`),
  KEY `role_user_user_id_foreign` (`user_id`),
  KEY `role_user_assigned_by_foreign` (`assigned_by`),
  CONSTRAINT `role_user_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `role_user` VALUES ('1', '1', NULL, '2026-09-25 06:15:27');

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` VALUES ('1', 'admin', 'Administrator', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `roles` VALUES ('2', 'buyer', 'Buyer', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `roles` VALUES ('3', 'seller', 'Seller', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `roles` VALUES ('4', 'logistics', 'Logistics', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');
INSERT INTO `roles` VALUES ('5', 'rider', 'Rider', NULL, '2026-09-25 06:15:26', '2026-09-25 06:15:26');

DROP TABLE IF EXISTS `seller_orders`;
CREATE TABLE `seller_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `seller_order_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'placed',
  `subtotal_minor` bigint unsigned NOT NULL,
  `discount_minor` bigint unsigned NOT NULL DEFAULT '0',
  `shipping_fee_minor` bigint unsigned NOT NULL DEFAULT '0',
  `total_minor` bigint unsigned NOT NULL,
  `fulfillment_deadline_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `preparing_at` timestamp NULL DEFAULT NULL,
  `ready_at` timestamp NULL DEFAULT NULL,
  `handed_over_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `seller_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_orders_order_id_store_id_unique` (`order_id`,`store_id`),
  UNIQUE KEY `seller_orders_seller_order_no_unique` (`seller_order_no`),
  KEY `seller_orders_store_id_status_created_at_index` (`store_id`,`status`,`created_at`),
  CONSTRAINT `seller_orders_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `seller_orders_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `seller_payouts`;
CREATE TABLE `seller_payouts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payout_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_profile_id` bigint unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `gross_minor` bigint unsigned NOT NULL,
  `commission_minor` bigint unsigned NOT NULL,
  `adjustment_minor` bigint NOT NULL DEFAULT '0',
  `net_minor` bigint unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `due_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `reference` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_payouts_payout_no_unique` (`payout_no`),
  KEY `seller_payouts_seller_profile_id_foreign` (`seller_profile_id`),
  CONSTRAINT `seller_payouts_seller_profile_id_foreign` FOREIGN KEY (`seller_profile_id`) REFERENCES `seller_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `seller_profiles`;
CREATE TABLE `seller_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `application_id` bigint unsigned DEFAULT NULL,
  `legal_business_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_category_id` bigint unsigned DEFAULT NULL,
  `pickup_address_id` bigint unsigned DEFAULT NULL,
  `commission_rate_bps` smallint unsigned NOT NULL DEFAULT '1000',
  `standing_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'good_standing',
  `warning_points` smallint unsigned NOT NULL DEFAULT '0',
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_profiles_user_id_unique` (`user_id`),
  KEY `seller_profiles_application_id_foreign` (`application_id`),
  KEY `seller_profiles_approved_category_id_foreign` (`approved_category_id`),
  KEY `seller_profiles_pickup_address_id_foreign` (`pickup_address_id`),
  CONSTRAINT `seller_profiles_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `account_applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_profiles_approved_category_id_foreign` FOREIGN KEY (`approved_category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `seller_profiles_pickup_address_id_foreign` FOREIGN KEY (`pickup_address_id`) REFERENCES `addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `seller_transactions`;
CREATE TABLE `seller_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_profile_id` bigint unsigned NOT NULL,
  `seller_order_id` bigint unsigned DEFAULT NULL,
  `payment_id` bigint unsigned DEFAULT NULL,
  `refund_id` bigint unsigned DEFAULT NULL,
  `commission_id` bigint unsigned DEFAULT NULL,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_minor` bigint NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `available_at` timestamp NULL DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_transactions_transaction_no_unique` (`transaction_no`),
  KEY `seller_transactions_seller_order_id_foreign` (`seller_order_id`),
  KEY `seller_transactions_payment_id_foreign` (`payment_id`),
  KEY `seller_transactions_refund_id_foreign` (`refund_id`),
  KEY `seller_transactions_commission_id_foreign` (`commission_id`),
  KEY `seller_transactions_seller_profile_id_status_created_at_index` (`seller_profile_id`,`status`,`created_at`),
  CONSTRAINT `seller_transactions_commission_id_foreign` FOREIGN KEY (`commission_id`) REFERENCES `platform_commissions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_transactions_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_transactions_refund_id_foreign` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_transactions_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `seller_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_transactions_seller_profile_id_foreign` FOREIGN KEY (`seller_profile_id`) REFERENCES `seller_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `seller_warnings`;
CREATE TABLE `seller_warnings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `warning_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_profile_id` bigint unsigned NOT NULL,
  `violation_id` bigint unsigned DEFAULT NULL,
  `level` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `points` smallint unsigned NOT NULL DEFAULT '0',
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `issued_by` bigint unsigned DEFAULT NULL,
  `issued_at` timestamp NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_warnings_warning_no_unique` (`warning_no`),
  KEY `seller_warnings_seller_profile_id_foreign` (`seller_profile_id`),
  KEY `seller_warnings_violation_id_foreign` (`violation_id`),
  KEY `seller_warnings_issued_by_foreign` (`issued_by`),
  CONSTRAINT `seller_warnings_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_warnings_seller_profile_id_foreign` FOREIGN KEY (`seller_profile_id`) REFERENCES `seller_profiles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `seller_warnings_violation_id_foreign` FOREIGN KEY (`violation_id`) REFERENCES `product_violations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `shipment_events`;
CREATE TABLE `shipment_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint unsigned NOT NULL,
  `parcel_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sorting_center_id` bigint unsigned DEFAULT NULL,
  `sorting_zone_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `scan_method` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `shipment_events_sorting_center_id_foreign` (`sorting_center_id`),
  KEY `shipment_events_sorting_zone_id_foreign` (`sorting_zone_id`),
  KEY `shipment_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `shipment_events_shipment_id_occurred_at_index` (`shipment_id`,`occurred_at`),
  KEY `shipment_events_parcel_id_occurred_at_index` (`parcel_id`,`occurred_at`),
  CONSTRAINT `shipment_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shipment_events_parcel_id_foreign` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `shipment_events_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `shipment_events_sorting_center_id_foreign` FOREIGN KEY (`sorting_center_id`) REFERENCES `sorting_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shipment_events_sorting_zone_id_foreign` FOREIGN KEY (`sorting_zone_id`) REFERENCES `sorting_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `shipments`;
CREATE TABLE `shipments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shipment_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_order_id` bigint unsigned NOT NULL,
  `logistics_profile_id` bigint unsigned NOT NULL,
  `origin_address_id` bigint unsigned DEFAULT NULL,
  `destination_address_id` bigint unsigned DEFAULT NULL,
  `purpose` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'outbound',
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_waybill',
  `shipping_fee_minor` bigint unsigned NOT NULL DEFAULT '0',
  `cod_amount_minor` bigint unsigned NOT NULL DEFAULT '0',
  `cod_collected_at` timestamp NULL DEFAULT NULL,
  `estimated_delivery_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipments_shipment_no_unique` (`shipment_no`),
  KEY `shipments_origin_address_id_foreign` (`origin_address_id`),
  KEY `shipments_destination_address_id_foreign` (`destination_address_id`),
  KEY `shipments_seller_order_id_status_index` (`seller_order_id`,`status`),
  KEY `shipments_logistics_profile_id_status_index` (`logistics_profile_id`,`status`),
  CONSTRAINT `shipments_destination_address_id_foreign` FOREIGN KEY (`destination_address_id`) REFERENCES `addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shipments_logistics_profile_id_foreign` FOREIGN KEY (`logistics_profile_id`) REFERENCES `logistics_profiles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `shipments_origin_address_id_foreign` FOREIGN KEY (`origin_address_id`) REFERENCES `addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shipments_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `seller_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sorting_centers`;
CREATE TABLE `sorting_centers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `logistics_profile_id` bigint unsigned NOT NULL,
  `address_id` bigint unsigned NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operating_hours` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `daily_capacity` int unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sorting_centers_code_unique` (`code`),
  KEY `sorting_centers_logistics_profile_id_foreign` (`logistics_profile_id`),
  KEY `sorting_centers_address_id_foreign` (`address_id`),
  CONSTRAINT `sorting_centers_address_id_foreign` FOREIGN KEY (`address_id`) REFERENCES `addresses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `sorting_centers_logistics_profile_id_foreign` FOREIGN KEY (`logistics_profile_id`) REFERENCES `logistics_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sorting_zones`;
CREATE TABLE `sorting_zones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sorting_center_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_rules` json DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sorting_zones_sorting_center_id_code_unique` (`sorting_center_id`,`code`),
  CONSTRAINT `sorting_zones_sorting_center_id_foreign` FOREIGN KEY (`sorting_center_id`) REFERENCES `sorting_centers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `stores`;
CREATE TABLE `stores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `seller_profile_id` bigint unsigned NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `contact_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banner_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `publication_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stores_seller_profile_id_unique` (`seller_profile_id`),
  UNIQUE KEY `stores_slug_unique` (`slug`),
  CONSTRAINT `stores_seller_profile_id_foreign` FOREIGN KEY (`seller_profile_id`) REFERENCES `seller_profiles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_label` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `topic` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `assigned_to` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `support_tickets_ticket_no_unique` (`ticket_no`),
  KEY `support_tickets_user_id_foreign` (`user_id`),
  KEY `support_tickets_assigned_to_foreign` (`assigned_to`),
  CONSTRAINT `support_tickets_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_tickets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` json NOT NULL,
  `value_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT '0',
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_key_unique` (`key`),
  KEY `system_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `system_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `user_notification_preferences`;
CREATE TABLE `user_notification_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `in_app` tinyint(1) NOT NULL DEFAULT '1',
  `email` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_notification_preferences_user_id_event_key_unique` (`user_id`,`event_key`),
  CONSTRAINT `user_notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_initial` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `middle_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sex` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barangay` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_category` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plate_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valid_id_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_permit_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `or_cr_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_license_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logistics_id` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `suspended_at` timestamp NULL DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `banned_at` timestamp NULL DEFAULT NULL,
  `status_reason` text COLLATE utf8mb4_unicode_ci,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_logistics_id_foreign` (`logistics_id`),
  KEY `users_approved_by_foreign` (`approved_by`),
  KEY `users_phone_index` (`phone`),
  KEY `users_role_index` (`role`),
  KEY `users_status_index` (`status`),
  CONSTRAINT `users_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_logistics_id_foreign` FOREIGN KEY (`logistics_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES ('1', 'Bearly Admin', 'Bearly', NULL, NULL, 'Admin', 'prefer_not_to_say', '2000-01-01', NULL, 'admin@bearly.test', NULL, '09000000000', 'admin', 'Metro Manila', 'Manila', 'System', 'Bearly Administration', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '$2y$12$Lw1Fcnj.uAPk8vPyOmBkJuj6VF8hDwGki7rCy9J/sbcYI2kTeXAUu', 'active', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 06:15:27', '2026-09-25 06:15:27', NULL);

DROP TABLE IF EXISTS `violation_actions`;
CREATE TABLE `violation_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `violation_id` bigint unsigned NOT NULL,
  `action_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `violation_actions_violation_id_foreign` (`violation_id`),
  KEY `violation_actions_actor_user_id_foreign` (`actor_user_id`),
  CONSTRAINT `violation_actions_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `violation_actions_violation_id_foreign` FOREIGN KEY (`violation_id`) REFERENCES `product_violations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `waybills`;
CREATE TABLE `waybills` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint unsigned NOT NULL,
  `waybill_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scan_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode_value` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `piece_count` smallint unsigned NOT NULL DEFAULT '1',
  `total_weight_kg` decimal(10,3) NOT NULL,
  `length_cm` decimal(10,2) DEFAULT NULL,
  `width_cm` decimal(10,2) DEFAULT NULL,
  `height_cm` decimal(10,2) DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generated',
  `generated_by` bigint unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `printed_at` timestamp NULL DEFAULT NULL,
  `print_count` smallint unsigned NOT NULL DEFAULT '0',
  `voided_at` timestamp NULL DEFAULT NULL,
  `void_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `waybills_shipment_id_unique` (`shipment_id`),
  UNIQUE KEY `waybills_waybill_no_unique` (`waybill_no`),
  UNIQUE KEY `waybills_scan_token_unique` (`scan_token`),
  UNIQUE KEY `waybills_barcode_value_unique` (`barcode_value`),
  KEY `waybills_generated_by_foreign` (`generated_by`),
  CONSTRAINT `waybills_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `waybills_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `wishlists`;
CREATE TABLE `wishlists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `session_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_id` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wishlists_user_id_product_id_unique` (`user_id`,`product_id`),
  KEY `wishlists_product_id_foreign` (`product_id`),
  KEY `wishlists_session_token_product_id_index` (`session_token`,`product_id`),
  CONSTRAINT `wishlists_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wishlists_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS=1;
