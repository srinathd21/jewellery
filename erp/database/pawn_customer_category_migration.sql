-- Run once in phpMyAdmin before opening the new pages.
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `pawn_customers` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `guardian_name` varchar(150) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `annual_income` decimal(16,2) NOT NULL DEFAULT 0.00,
  `reference_name` varchar(150) DEFAULT NULL,
  `reference_mobile` varchar(20) DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `signature_path` varchar(500) DEFAULT NULL,
  `kyc_document_path` varchar(500) DEFAULT NULL,
  `kyc_verified` tinyint(1) NOT NULL DEFAULT 0,
  `credit_limit` decimal(16,2) NOT NULL DEFAULT 0.00,
  `risk_category` enum('Low','Medium','High') NOT NULL DEFAULT 'Low',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pawn_customer_business_customer` (`business_id`,`customer_id`),
  KEY `idx_pawn_customer_business` (`business_id`),
  KEY `idx_pawn_customer_kyc` (`business_id`,`kyc_verified`),
  KEY `idx_pawn_customer_risk` (`business_id`,`risk_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `date_of_birth` date DEFAULT NULL AFTER `pan_no`,
  ADD COLUMN IF NOT EXISTS `anniversary_date` date DEFAULT NULL AFTER `date_of_birth`,
  ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL AFTER `current_balance`;

-- Extend the current minimal pawn_categories table while keeping existing data.
ALTER TABLE `pawn_categories`
  ADD COLUMN IF NOT EXISTS `category_type` enum('Ornament','Metal','Document','Other') NOT NULL DEFAULT 'Ornament' AFTER `category_name`,
  ADD COLUMN IF NOT EXISTS `metal_type` enum('Gold','Silver','Platinum','Other') DEFAULT NULL AFTER `category_type`,
  ADD COLUMN IF NOT EXISTS `purity_standard` varchar(50) DEFAULT NULL AFTER `metal_type`,
  ADD COLUMN IF NOT EXISTS `min_purity_percent` decimal(5,2) DEFAULT NULL AFTER `purity_standard`,
  ADD COLUMN IF NOT EXISTS `max_purity_percent` decimal(5,2) DEFAULT NULL AFTER `min_purity_percent`,
  ADD COLUMN IF NOT EXISTS `max_loan_percent` decimal(5,2) NOT NULL DEFAULT 70.00 AFTER `default_interest_percent`,
  ADD COLUMN IF NOT EXISTS `storage_fee_percent` decimal(5,2) NOT NULL DEFAULT 0.00 AFTER `max_loan_percent`,
  ADD COLUMN IF NOT EXISTS `valuation_method` enum('Weight','Piece','Stone','Combined') NOT NULL DEFAULT 'Weight' AFTER `storage_fee_percent`,
  ADD COLUMN IF NOT EXISTS `requires_certificate` tinyint(1) NOT NULL DEFAULT 0 AFTER `valuation_method`,
  ADD COLUMN IF NOT EXISTS `requires_valuation` tinyint(1) NOT NULL DEFAULT 1 AFTER `requires_certificate`,
  ADD COLUMN IF NOT EXISTS `description` text DEFAULT NULL AFTER `requires_valuation`,
  ADD COLUMN IF NOT EXISTS `created_by` bigint(20) UNSIGNED DEFAULT NULL AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `created_at` timestamp NULL DEFAULT current_timestamp() AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;

COMMIT;
