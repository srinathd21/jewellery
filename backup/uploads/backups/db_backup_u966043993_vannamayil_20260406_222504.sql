-- Database Backup
-- Generated on: 2026-04-06 22:25:04
-- Database: u966043993_vannamayil

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Table structure for table `audit_logs`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `module_name` varchar(100) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_audit_logs_user` (`user_id`),
  KEY `fk_audit_logs_business` (`business_id`),
  KEY `idx_audit_module` (`module_name`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_logs_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `audit_logs`

INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('1', NULL, '3', 'Register', 'Create', '3', 'Super Admin account created from onboarding register page', '160.20.106.235', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 14:55:51');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('2', NULL, '3', 'Login', 'Login', '3', 'User logged in successfully', '160.20.106.235', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 14:56:00');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('3', NULL, '3', 'Business', 'Create', '2', 'Business created: Ecommer (ECOMMER); Admin user: Srinath', '160.20.106.235', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 14:57:10');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('4', NULL, '3', 'Logout', 'Logout', '3', 'Super Admin logged out successfully', '160.20.106.232', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:18:16');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('5', NULL, '3', 'Login', 'Login', '3', 'User logged in successfully', '160.20.106.232', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:18:59');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('6', NULL, '3', 'Logout', 'Logout', '3', 'Super Admin logged out successfully', '160.20.106.232', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:20:58');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('7', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '160.20.106.232', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:21:04');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('8', '2', '4', 'Logout', 'Logout', '4', 'Business Admin user logged out successfully', '160.20.106.228', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:32:55');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('9', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '160.20.106.228', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:33:04');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('10', '2', '4', 'Company Settings', 'Update', '2', 'Company settings updated', '160.20.106.231', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:40:36');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('11', '2', '4', 'Silver Rate Settings', 'Create', '1', 'Created silver rate entry', '160.20.106.227', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:49:06');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('12', '2', '4', 'Users', 'Create', '5', 'Created user Ariharasudhan', '160.20.106.226', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 15:54:38');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('13', '2', '4', 'Categories', 'Create', '6', 'Created category Rings', '160.20.106.233', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-17 16:07:44');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('14', '2', '4', 'Products', 'Create', '1', 'Created product Gold Ring', '160.20.106.230', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-18 06:30:51');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('15', NULL, '3', 'Login', 'Login', '3', 'User logged in successfully', '2409:40f4:301a:a3e6:7088:5c67:2bcf:f886', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 20:51:10');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('16', NULL, '3', 'Logout', 'Logout', '3', 'Super Admin logged out successfully', '2409:40f4:301a:a3e6:7088:5c67:2bcf:f886', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 20:51:17');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('17', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '2409:40f4:301a:a3e6:7088:5c67:2bcf:f886', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 20:51:36');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('18', '2', '4', 'Logout', 'Logout', '4', 'Business Admin user logged out successfully', '2409:40f4:301a:a3e6:7088:5c67:2bcf:f886', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 20:51:42');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('19', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '2409:40f4:301a:a3e6:7088:5c67:2bcf:f886', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 20:56:37');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('20', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '2401:4900:ca88:7438:1c41:1ad0:7d45:8049', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 21:13:33');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('21', '2', '4', 'Logout', 'Logout', '4', 'Business Admin user logged out successfully', '2401:4900:ca88:7438:1c41:1ad0:7d45:8049', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 21:18:35');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('22', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '2401:4900:ca88:7438:1c41:1ad0:7d45:8049', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 21:18:47');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('23', '2', '4', 'Customers', 'Create', '1', 'Created customer Test Customer', '2401:4900:ca88:7438:1c41:1ad0:7d45:8049', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 21:28:47');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('24', NULL, '3', 'Login', 'Login', '3', 'User logged in successfully', '2409:40f4:301b:d405:3149:3734:7f6b:5f6', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-31 06:10:53');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('25', NULL, '3', 'Logout', 'Logout', '3', 'Super Admin logged out successfully', '2409:40f4:301b:d405:3149:3734:7f6b:5f6', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-31 06:11:02');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('26', NULL, '3', 'Login', 'Login', '3', 'User logged in successfully', '160.20.106.227', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-06 12:28:43');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('27', NULL, '3', 'Logout', 'Logout', '3', 'Super Admin logged out successfully', '160.20.106.227', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-06 12:29:05');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('28', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '160.20.106.227', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-06 12:29:08');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('29', '2', '4', 'Logout', 'Logout', '4', 'Business Admin user logged out successfully', '160.20.106.230', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-06 12:32:45');
INSERT INTO `audit_logs` (`id`, `business_id`, `user_id`, `module_name`, `action_type`, `reference_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES ('30', '2', '4', 'Login', 'Login', '4', 'User logged in successfully', '160.20.106.234', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-06 16:18:56');

-- --------------------------------------------------------
-- Table structure for table `businesses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `businesses`;
CREATE TABLE `businesses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_code` varchar(50) NOT NULL,
  `business_name` varchar(150) NOT NULL,
  `business_type` varchar(100) DEFAULT 'Silver Jewellery',
  `owner_name` varchar(150) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'India',
  `gstin` varchar(30) DEFAULT NULL,
  `pan_no` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_code` (`business_code`),
  KEY `idx_business_name` (`business_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `businesses`

INSERT INTO `businesses` (`id`, `business_code`, `business_name`, `business_type`, `owner_name`, `mobile`, `whatsapp`, `email`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `country`, `gstin`, `pan_no`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('1', 'VMJ001', 'Vanna Mayil Jewellers', 'Silver Jewellery', NULL, '9999999999', NULL, NULL, NULL, NULL, 'Dharmapuri', 'Tamil Nadu', NULL, 'India', NULL, NULL, '1', NULL, '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `businesses` (`id`, `business_code`, `business_name`, `business_type`, `owner_name`, `mobile`, `whatsapp`, `email`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `country`, `gstin`, `pan_no`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'ECOMMER', 'Ecommer', 'Silver Jewellery', 'Ariharasudhan P', '7200314099', '', '', 'Dharmapuri', '', 'Dharmapuri', 'Tamil Nadu', '636809', 'India', '', '', '1', '3', '2026-03-17 14:57:10', '2026-03-17 15:40:36');

-- --------------------------------------------------------
-- Table structure for table `company_settings`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `company_settings`;
CREATE TABLE `company_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `business_type` varchar(100) DEFAULT 'Silver Jewellery',
  `owner_name` varchar(150) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'India',
  `gstin` varchar(30) DEFAULT NULL,
  `pan_no` varchar(20) DEFAULT NULL,
  `invoice_prefix` varchar(20) DEFAULT 'VMJ',
  `estimate_prefix` varchar(20) DEFAULT 'EST',
  `return_prefix` varchar(20) DEFAULT 'SR',
  `currency_symbol` varchar(10) DEFAULT '₹',
  `timezone` varchar(100) DEFAULT 'Asia/Kolkata',
  `logo_path` varchar(255) DEFAULT NULL,
  `bill_footer` text DEFAULT NULL,
  `terms_conditions` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_business` (`business_id`),
  CONSTRAINT `fk_company_settings_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `company_settings`

INSERT INTO `company_settings` (`id`, `business_id`, `company_name`, `business_type`, `owner_name`, `mobile`, `whatsapp`, `email`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `country`, `gstin`, `pan_no`, `invoice_prefix`, `estimate_prefix`, `return_prefix`, `currency_symbol`, `timezone`, `logo_path`, `bill_footer`, `terms_conditions`, `created_at`, `updated_at`) VALUES ('1', '1', 'Vanna Mayil Jewellers', 'Silver Jewellery', NULL, '9999999999', NULL, NULL, NULL, NULL, 'Dharmapuri', 'Tamil Nadu', NULL, 'India', NULL, NULL, 'VMJ', 'EST', 'SR', '₹', 'Asia/Kolkata', NULL, NULL, NULL, '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `company_settings` (`id`, `business_id`, `company_name`, `business_type`, `owner_name`, `mobile`, `whatsapp`, `email`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `country`, `gstin`, `pan_no`, `invoice_prefix`, `estimate_prefix`, `return_prefix`, `currency_symbol`, `timezone`, `logo_path`, `bill_footer`, `terms_conditions`, `created_at`, `updated_at`) VALUES ('2', '2', 'Ecommer', 'Silver Jewellery', 'Ariharasudhan P', '7200314099', '', '', 'Dharmapuri', '', 'Dharmapuri', 'Tamil Nadu', '636809', 'India', '', '', 'INV', 'EST', 'RET', '₹', 'Asia/Kolkata', 'uploads/company/logo_20260317_211036_72ae8b8b.png', '', '', '2026-03-17 14:57:10', '2026-03-17 15:40:36');

-- --------------------------------------------------------
-- Table structure for table `customer_payments`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customer_payments`;
CREATE TABLE `customer_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `receipt_no` varchar(30) NOT NULL,
  `receipt_date` date NOT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `sale_id` int(10) unsigned DEFAULT NULL,
  `payment_method_id` tinyint(3) unsigned DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_receipt_no_business` (`business_id`,`receipt_no`),
  KEY `fk_customer_payments_customer` (`customer_id`),
  KEY `fk_customer_payments_sale` (`sale_id`),
  KEY `fk_customer_payments_method` (`payment_method_id`),
  KEY `fk_customer_payments_user` (`created_by`),
  KEY `fk_customer_payments_business` (`business_id`),
  CONSTRAINT `fk_customer_payments_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_customer_payments_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customer_payments_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customer_payments_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `customer_payments`

-- --------------------------------------------------------
-- Table structure for table `customers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `customer_code` varchar(30) NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `alternate_mobile` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `gstin` varchar(30) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `anniversary_date` date DEFAULT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_type` enum('Dr','Cr') DEFAULT 'Dr',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_code_business` (`business_id`,`customer_code`),
  KEY `idx_customers_name` (`customer_name`),
  KEY `idx_customers_mobile` (`mobile`),
  KEY `fk_customers_business` (`business_id`),
  CONSTRAINT `fk_customers_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `customers`

INSERT INTO `customers` (`id`, `business_id`, `customer_code`, `customer_name`, `mobile`, `alternate_mobile`, `email`, `gstin`, `address_line1`, `address_line2`, `city`, `state`, `pincode`, `date_of_birth`, `anniversary_date`, `opening_balance`, `balance_type`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES ('1', '2', 'CUS580626', 'Test Customer', '9999999999', '', '', '', '', '', '', '', '', NULL, NULL, '0.00', 'Dr', '', '1', '2026-03-30 21:28:47', '2026-03-30 21:28:47');

-- --------------------------------------------------------
-- Table structure for table `expenses`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `expense_date` date NOT NULL,
  `expense_category` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_method_id` tinyint(3) unsigned DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_expenses_method` (`payment_method_id`),
  KEY `fk_expenses_user` (`created_by`),
  KEY `fk_expenses_business` (`business_id`),
  CONSTRAINT `fk_expenses_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expenses_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expenses_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `expenses`

-- --------------------------------------------------------
-- Table structure for table `old_silver_entries`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `old_silver_entries`;
CREATE TABLE `old_silver_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `entry_no` varchar(30) NOT NULL,
  `entry_date` date NOT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_mobile` varchar(20) DEFAULT NULL,
  `id_proof_type` varchar(50) DEFAULT NULL,
  `id_proof_number` varchar(100) DEFAULT NULL,
  `total_gross_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `total_less_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `total_net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `rate_per_gram` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deduction_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `deduction_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `adjustment_type` enum('Cash','Exchange','Pending') NOT NULL DEFAULT 'Exchange',
  `linked_sale_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_entry_no_business` (`business_id`,`entry_no`),
  KEY `fk_old_silver_customer` (`customer_id`),
  KEY `fk_old_silver_sale` (`linked_sale_id`),
  KEY `fk_old_silver_user` (`created_by`),
  KEY `fk_old_silver_business` (`business_id`),
  CONSTRAINT `fk_old_silver_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_old_silver_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_old_silver_sale` FOREIGN KEY (`linked_sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_old_silver_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `old_silver_entries`

-- --------------------------------------------------------
-- Table structure for table `old_silver_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `old_silver_items`;
CREATE TABLE `old_silver_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `old_silver_entry_id` int(10) unsigned NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `purity` varchar(20) DEFAULT NULL,
  `gross_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `less_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_old_silver_items_entry` (`old_silver_entry_id`),
  KEY `fk_old_silver_items_business` (`business_id`),
  CONSTRAINT `fk_old_silver_items_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_old_silver_items_entry` FOREIGN KEY (`old_silver_entry_id`) REFERENCES `old_silver_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `old_silver_items`

-- --------------------------------------------------------
-- Table structure for table `payment_methods`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `payment_methods`;
CREATE TABLE `payment_methods` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `method_name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `method_name` (`method_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payment_methods`

INSERT INTO `payment_methods` (`id`, `method_name`, `is_active`) VALUES ('1', 'Cash', '1');
INSERT INTO `payment_methods` (`id`, `method_name`, `is_active`) VALUES ('2', 'UPI', '1');
INSERT INTO `payment_methods` (`id`, `method_name`, `is_active`) VALUES ('3', 'Card', '1');
INSERT INTO `payment_methods` (`id`, `method_name`, `is_active`) VALUES ('4', 'Bank Transfer', '1');
INSERT INTO `payment_methods` (`id`, `method_name`, `is_active`) VALUES ('5', 'Credit', '1');
INSERT INTO `payment_methods` (`id`, `method_name`, `is_active`) VALUES ('6', 'Mixed', '1');

-- --------------------------------------------------------
-- Table structure for table `product_categories`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE `product_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `hsn_code` varchar(20) DEFAULT NULL,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 3.00,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_category_business` (`business_id`,`category_name`),
  KEY `fk_product_categories_business` (`business_id`),
  CONSTRAINT `fk_product_categories_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `product_categories`

INSERT INTO `product_categories` (`id`, `business_id`, `category_name`, `hsn_code`, `gst_percent`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('1', '1', 'Silver Ring', '7113', '3.00', 'Silver rings', '1', '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `product_categories` (`id`, `business_id`, `category_name`, `hsn_code`, `gst_percent`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('2', '1', 'Silver Chain', '7113', '3.00', 'Silver chains', '1', '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `product_categories` (`id`, `business_id`, `category_name`, `hsn_code`, `gst_percent`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('3', '1', 'Silver Anklet', '7113', '3.00', 'Silver anklets', '1', '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `product_categories` (`id`, `business_id`, `category_name`, `hsn_code`, `gst_percent`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('4', '1', 'Silver Pooja Items', '7114', '3.00', 'Pooja and gift items', '1', '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `product_categories` (`id`, `business_id`, `category_name`, `hsn_code`, `gst_percent`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('5', '1', 'Silver Articles', '7114', '3.00', 'General silver articles', '1', '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `product_categories` (`id`, `business_id`, `category_name`, `hsn_code`, `gst_percent`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('6', '2', 'Rings', '', '3.00', '', '1', '2026-03-17 16:07:44', '2026-03-17 16:07:44');

-- --------------------------------------------------------
-- Table structure for table `product_stock`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `product_stock`;
CREATE TABLE `product_stock` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `opening_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `opening_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `in_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `in_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `out_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `out_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `closing_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `closing_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_stock` (`product_id`),
  KEY `fk_product_stock_business` (`business_id`),
  CONSTRAINT `fk_product_stock_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_stock_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `product_stock`

-- --------------------------------------------------------
-- Table structure for table `products`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  `product_code` varchar(50) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `product_name` varchar(150) NOT NULL,
  `design_name` varchar(150) DEFAULT NULL,
  `purity` varchar(20) NOT NULL DEFAULT '925',
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `gross_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `less_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `making_charge_type` enum('fixed','per_gram','percentage') NOT NULL DEFAULT 'fixed',
  `making_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wastage_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `stone_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sale_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `min_stock_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `current_stock_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `image_path` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_code_business` (`business_id`,`product_code`),
  UNIQUE KEY `uniq_barcode_business` (`business_id`,`barcode`),
  KEY `fk_products_category` (`category_id`),
  KEY `fk_products_business` (`business_id`),
  KEY `idx_products_name` (`product_name`),
  KEY `idx_products_purity` (`purity`),
  CONSTRAINT `fk_products_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `products`

INSERT INTO `products` (`id`, `business_id`, `category_id`, `product_code`, `barcode`, `product_name`, `design_name`, `purity`, `unit`, `gross_weight`, `less_weight`, `net_weight`, `making_charge_type`, `making_charge`, `wastage_percent`, `stone_charge`, `purchase_rate`, `sale_rate`, `min_stock_qty`, `current_stock_qty`, `image_path`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('1', '2', '6', 'PRD710795', '', 'Gold Ring', '', '925', 'pcs', '0.000', '0.000', '0.000', 'fixed', '0.00', '0.00', '0.00', '500.00', '4000.00', '0.000', '0.000', '', '', '1', '2026-03-18 06:30:51', '2026-03-18 06:30:51');

-- --------------------------------------------------------
-- Table structure for table `purchase_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE `purchase_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `purchase_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `purity` varchar(20) NOT NULL DEFAULT '925',
  `hsn_code` varchar(20) DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `gross_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `less_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `rate_per_gram` decimal(12,2) NOT NULL DEFAULT 0.00,
  `making_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stone_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `item_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 3.00,
  `gst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_purchase_items_purchase` (`purchase_id`),
  KEY `fk_purchase_items_product` (`product_id`),
  KEY `fk_purchase_items_business` (`business_id`),
  CONSTRAINT `fk_purchase_items_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `purchase_items`

-- --------------------------------------------------------
-- Table structure for table `purchase_return_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchase_return_items`;
CREATE TABLE `purchase_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `purchase_return_id` int(10) unsigned NOT NULL,
  `purchase_item_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `rate_per_gram` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `gst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_purchase_return_items_business` (`business_id`),
  KEY `fk_purchase_return_items_return` (`purchase_return_id`),
  KEY `fk_purchase_return_items_purchase_item` (`purchase_item_id`),
  KEY `fk_purchase_return_items_product` (`product_id`),
  CONSTRAINT `fk_purchase_return_items_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_return_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_return_items_purchase_item` FOREIGN KEY (`purchase_item_id`) REFERENCES `purchase_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_return_items_return` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `purchase_return_items`

-- --------------------------------------------------------
-- Table structure for table `purchase_returns`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchase_returns`;
CREATE TABLE `purchase_returns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `return_no` varchar(30) NOT NULL,
  `return_date` date NOT NULL,
  `purchase_id` int(10) unsigned DEFAULT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_purchase_return_no_business` (`business_id`,`return_no`),
  KEY `fk_purchase_returns_business` (`business_id`),
  KEY `fk_purchase_returns_purchase` (`purchase_id`),
  KEY `fk_purchase_returns_supplier` (`supplier_id`),
  KEY `fk_purchase_returns_user` (`created_by`),
  CONSTRAINT `fk_purchase_returns_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_returns_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_returns_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_returns_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `purchase_returns`

-- --------------------------------------------------------
-- Table structure for table `purchases`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `purchases`;
CREATE TABLE `purchases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `purchase_no` varchar(30) NOT NULL,
  `purchase_date` date NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cgst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sgst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `igst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `round_off` decimal(14,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_purchase_no_business` (`business_id`,`purchase_no`),
  KEY `fk_purchases_supplier` (`supplier_id`),
  KEY `fk_purchases_user` (`created_by`),
  KEY `idx_purchases_date` (`purchase_date`),
  KEY `fk_purchases_business` (`business_id`),
  CONSTRAINT `fk_purchases_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_purchases_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `purchases`

-- --------------------------------------------------------
-- Table structure for table `roles`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `roles`

INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES ('1', 'Super Admin', 'Can create and manage businesses');
INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES ('2', 'Admin', 'Business full access');
INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES ('3', 'Manager', 'Manager level access');
INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES ('4', 'Billing', 'Billing counter access');
INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES ('5', 'Stock', 'Inventory access');

-- --------------------------------------------------------
-- Table structure for table `sale_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `sale_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `product_code` varchar(50) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `category_name` varchar(100) DEFAULT NULL,
  `purity` varchar(20) NOT NULL DEFAULT '925',
  `hsn_code` varchar(20) DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `gross_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `less_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `rate_date` date DEFAULT NULL,
  `rate_per_gram` decimal(12,2) NOT NULL DEFAULT 0.00,
  `metal_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `making_charge_type` enum('fixed','per_gram','percentage') NOT NULL DEFAULT 'fixed',
  `making_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wastage_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `wastage_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `stone_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 3.00,
  `gst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_sale_items_product` (`product_id`),
  KEY `idx_sale_items_sale_id` (`sale_id`),
  KEY `fk_sale_items_business` (`business_id`),
  CONSTRAINT `fk_sale_items_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sale_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `sale_items`

-- --------------------------------------------------------
-- Table structure for table `sales`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `bill_no` varchar(30) NOT NULL,
  `bill_date` date NOT NULL,
  `bill_time` time NOT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_mobile` varchar(20) DEFAULT NULL,
  `bill_type` enum('Retail','GST','Estimate','Exchange') NOT NULL DEFAULT 'Retail',
  `payment_method_id` tinyint(3) unsigned DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cgst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sgst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `igst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `round_off` decimal(14,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Paid',
  `notes` text DEFAULT NULL,
  `status` enum('Active','Cancelled') NOT NULL DEFAULT 'Active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bill_no_business` (`business_id`,`bill_no`),
  KEY `fk_sales_payment_method` (`payment_method_id`),
  KEY `fk_sales_created_user` (`created_by`),
  KEY `fk_sales_cancelled_user` (`cancelled_by`),
  KEY `idx_sales_date` (`bill_date`),
  KEY `idx_sales_customer` (`customer_id`),
  KEY `fk_sales_business` (`business_id`),
  CONSTRAINT `fk_sales_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_cancelled_user` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_created_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `sales`

-- --------------------------------------------------------
-- Table structure for table `sales_return_items`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sales_return_items`;
CREATE TABLE `sales_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `sales_return_id` int(10) unsigned NOT NULL,
  `sale_item_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
  `rate_per_gram` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_percent` decimal(5,2) NOT NULL DEFAULT 3.00,
  `gst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_sales_return_items_return` (`sales_return_id`),
  KEY `fk_sales_return_items_sale_item` (`sale_item_id`),
  KEY `fk_sales_return_items_product` (`product_id`),
  KEY `fk_sales_return_items_business` (`business_id`),
  CONSTRAINT `fk_sales_return_items_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_return_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_return_items_return` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_return_items_sale_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `sales_return_items`

-- --------------------------------------------------------
-- Table structure for table `sales_returns`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `sales_returns`;
CREATE TABLE `sales_returns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `return_no` varchar(30) NOT NULL,
  `return_date` date NOT NULL,
  `sale_id` int(10) unsigned DEFAULT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `refund_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `refund_method_id` tinyint(3) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_return_no_business` (`business_id`,`return_no`),
  KEY `fk_sales_returns_sale` (`sale_id`),
  KEY `fk_sales_returns_customer` (`customer_id`),
  KEY `fk_sales_returns_method` (`refund_method_id`),
  KEY `fk_sales_returns_user` (`created_by`),
  KEY `fk_sales_returns_business` (`business_id`),
  CONSTRAINT `fk_sales_returns_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_returns_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_returns_method` FOREIGN KEY (`refund_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_returns_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_returns_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `sales_returns`

-- --------------------------------------------------------
-- Table structure for table `silver_rate_history`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `silver_rate_history`;
CREATE TABLE `silver_rate_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `rate_date` date NOT NULL,
  `purity` varchar(20) NOT NULL DEFAULT '925',
  `rate_per_gram` decimal(12,2) NOT NULL,
  `rate_per_kg` decimal(12,2) GENERATED ALWAYS AS (`rate_per_gram` * 1000) STORED,
  `remarks` varchar(255) DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rate_date_purity_business` (`business_id`,`rate_date`,`purity`),
  KEY `fk_silver_rate_user` (`updated_by`),
  KEY `idx_rate_date` (`rate_date`),
  KEY `fk_silver_rate_business` (`business_id`),
  CONSTRAINT `fk_silver_rate_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_silver_rate_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `silver_rate_history`

INSERT INTO `silver_rate_history` (`id`, `business_id`, `rate_date`, `purity`, `rate_per_gram`, `rate_per_kg`, `remarks`, `updated_by`, `created_at`) VALUES ('1', '2', '2026-03-17', '925', '14500.00', '14500000.00', '', '4', '2026-03-17 15:49:06');

-- --------------------------------------------------------
-- Table structure for table `stock_movements`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `movement_date` datetime NOT NULL DEFAULT current_timestamp(),
  `product_id` int(10) unsigned NOT NULL,
  `movement_type` enum('Opening','Purchase','Sale','Sale Return','Purchase Return','Adjustment','Old Silver Inward','Damage','Manual') NOT NULL,
  `ref_table` varchar(50) DEFAULT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `qty_in` decimal(12,3) NOT NULL DEFAULT 0.000,
  `qty_out` decimal(12,3) NOT NULL DEFAULT 0.000,
  `weight_in` decimal(12,3) NOT NULL DEFAULT 0.000,
  `weight_out` decimal(12,3) NOT NULL DEFAULT 0.000,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_stock_movements_user` (`created_by`),
  KEY `idx_stock_movement_date` (`movement_date`),
  KEY `idx_stock_movement_product` (`product_id`),
  KEY `fk_stock_movements_business` (`business_id`),
  CONSTRAINT `fk_stock_movements_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_movements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `stock_movements`

-- --------------------------------------------------------
-- Table structure for table `supplier_payments`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `supplier_payments`;
CREATE TABLE `supplier_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `payment_no` varchar(30) NOT NULL,
  `payment_date` date NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `purchase_id` int(10) unsigned DEFAULT NULL,
  `payment_method_id` tinyint(3) unsigned DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_no_business` (`business_id`,`payment_no`),
  KEY `fk_supplier_payments_supplier` (`supplier_id`),
  KEY `fk_supplier_payments_purchase` (`purchase_id`),
  KEY `fk_supplier_payments_method` (`payment_method_id`),
  KEY `fk_supplier_payments_user` (`created_by`),
  KEY `fk_supplier_payments_business` (`business_id`),
  CONSTRAINT `fk_supplier_payments_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_supplier_payments_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supplier_payments_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supplier_payments_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_supplier_payments_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `supplier_payments`

-- --------------------------------------------------------
-- Table structure for table `suppliers`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned NOT NULL,
  `supplier_code` varchar(30) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `alternate_mobile` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `gstin` varchar(30) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_type` enum('Dr','Cr') DEFAULT 'Cr',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_supplier_code_business` (`business_id`,`supplier_code`),
  KEY `idx_suppliers_name` (`supplier_name`),
  KEY `idx_suppliers_mobile` (`mobile`),
  KEY `fk_suppliers_business` (`business_id`),
  CONSTRAINT `fk_suppliers_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `suppliers`

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` int(10) unsigned DEFAULT NULL,
  `role_id` tinyint(3) unsigned NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_business` (`business_id`),
  CONSTRAINT `fk_users_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `users`

INSERT INTO `users` (`id`, `business_id`, `role_id`, `full_name`, `username`, `password_hash`, `mobile`, `email`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('1', NULL, '1', 'Super Administrator', 'superadmin', '$2y$10$replace_with_bcrypt_hash', '9999999999', 'superadmin@example.com', '1', NULL, '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `users` (`id`, `business_id`, `role_id`, `full_name`, `username`, `password_hash`, `mobile`, `email`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('2', '1', '2', 'VMJ Administrator', 'vmjadmin', '$2y$10$replace_with_bcrypt_hash', '9999999999', 'admin@vmj.com', '1', NULL, '2026-03-17 14:27:36', '2026-03-17 14:27:36');
INSERT INTO `users` (`id`, `business_id`, `role_id`, `full_name`, `username`, `password_hash`, `mobile`, `email`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('3', NULL, '1', 'Ariharasudhan', 'Ariharan', '$2y$10$QH0Cmn2PfbgkaafLnVMMqO9DleADYYtSnizu7qP11Tm60mtTsQmFq', '7200314099', 'ariadhibala@gmail.com', '1', '2026-04-06 12:28:43', '2026-03-17 14:55:51', '2026-04-06 12:28:43');
INSERT INTO `users` (`id`, `business_id`, `role_id`, `full_name`, `username`, `password_hash`, `mobile`, `email`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('4', '2', '2', 'Ariharasudhan', 'Srinath', '$2y$10$5wv8paFnag1KBI596CB/r.UokAwwZMNufzPoU3/VyKwZZJGN/mIZu', '', '', '1', '2026-04-06 16:18:56', '2026-03-17 14:57:10', '2026-04-06 16:18:56');
INSERT INTO `users` (`id`, `business_id`, `role_id`, `full_name`, `username`, `password_hash`, `mobile`, `email`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES ('5', '2', '2', 'Ariharasudhan', 'Dinesh', '$2y$10$xiMqHeAK/Yj5pFhE9G9lUO0VxW86Mg2zThhFo7xaQORHy7Kfau/2G', '7200314099', '', '1', NULL, '2026-03-17 15:54:38', '2026-03-17 15:54:38');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
