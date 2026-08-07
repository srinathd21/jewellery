<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/* =========================================================
   COMMON HELPERS
========================================================= */
if (!function_exists('pawn_h')) {
    function pawn_h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pawn_table_exists')) {
    function pawn_table_exists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $sql = "SHOW TABLES LIKE '{$safe}'";
        $res = $conn->query($sql);
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('pawn_business_id')) {
    function pawn_business_id(): int
    {
        if (function_exists('currentBusinessId')) {
            return (int)(currentBusinessId() ?? 0);
        }
        return (int)($_SESSION['business_id'] ?? 0);
    }
}

if (!function_exists('pawn_user_id')) {
    function pawn_user_id(): int
    {
        if (function_exists('currentUserId')) {
            return (int)currentUserId();
        }
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('pawn_role_name')) {
    function pawn_role_name(): string
    {
        if (function_exists('currentRoleName')) {
            return (string)currentRoleName();
        }
        return (string)($_SESSION['role_name'] ?? '');
    }
}

if (!function_exists('pawn_require_access')) {
    function pawn_require_access(string $redirect = 'login.php'): void
    {
        if (function_exists('requireLogin')) {
            requireLogin($redirect);
        } else {
            if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
                header('Location: ' . $redirect);
                exit;
            }
        }

        $allowed = ['Super Admin', 'Admin', 'Manager', 'Billing'];
        if (!in_array(pawn_role_name(), $allowed, true)) {
            die('Access denied.');
        }
    }
}

if (!function_exists('pawn_add_audit')) {
    function pawn_add_audit(mysqli $conn, string $moduleName, string $actionType, ?int $referenceId = null, string $description = ''): void
    {
        if (function_exists('addAuditLog')) {
            addAuditLog(
                $conn,
                pawn_business_id(),
                pawn_user_id(),
                $moduleName,
                $actionType,
                $referenceId,
                $description
            );
        }
    }
}

/* =========================================================
   TABLE CHECK / OPTIONAL AUTO CREATE
========================================================= */
if (!function_exists('pawn_ensure_tables')) {
    function pawn_ensure_tables(mysqli $conn): void
    {
        $queries = [];

        $queries[] = "
        CREATE TABLE IF NOT EXISTS `pawn_entries` (
          `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `business_id` int(10) UNSIGNED NOT NULL,
          `pawn_no` varchar(30) NOT NULL,
          `entry_date` date NOT NULL,
          `customer_id` int(10) UNSIGNED DEFAULT NULL,
          `customer_name` varchar(150) NOT NULL,
          `customer_mobile` varchar(20) DEFAULT NULL,
          `address` text DEFAULT NULL,
          `id_proof_type` varchar(50) DEFAULT NULL,
          `id_proof_number` varchar(100) DEFAULT NULL,
          `metal_type` enum('Gold','Silver','Other') NOT NULL DEFAULT 'Gold',
          `loan_type` varchar(50) DEFAULT 'General',
          `item_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
          `total_gross_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
          `total_less_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
          `total_net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
          `loan_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `principal_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
          `interest_rate` decimal(8,2) NOT NULL DEFAULT 0.00,
          `interest_type` enum('Monthly','Weekly','Daily') NOT NULL DEFAULT 'Monthly',
          `interest_method` enum('Simple','Flat') NOT NULL DEFAULT 'Simple',
          `tenure_months` int(10) UNSIGNED NOT NULL DEFAULT 0,
          `maturity_date` date DEFAULT NULL,
          `ticket_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
          `other_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
          `payment_method_id` tinyint(3) UNSIGNED DEFAULT NULL,
          `payment_reference` varchar(100) DEFAULT NULL,
          `status` enum('Active','Released','Auctioned','Closed','Partially Paid') NOT NULL DEFAULT 'Active',
          `remarks` text DEFAULT NULL,
          `released_at` datetime DEFAULT NULL,
          `auctioned_at` datetime DEFAULT NULL,
          `closed_at` datetime DEFAULT NULL,
          `created_by` int(10) UNSIGNED DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_pawn_no` (`pawn_no`),
          KEY `idx_pawn_entries_business` (`business_id`),
          KEY `idx_pawn_entries_customer` (`customer_id`),
          KEY `idx_pawn_entries_status` (`status`),
          KEY `idx_pawn_entries_date` (`entry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $queries[] = "
        CREATE TABLE IF NOT EXISTS `pawn_items` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `business_id` int(10) UNSIGNED NOT NULL,
          `pawn_id` int(10) UNSIGNED NOT NULL,
          `item_name` varchar(150) NOT NULL,
          `item_category` varchar(100) DEFAULT NULL,
          `purity` varchar(20) DEFAULT NULL,
          `gross_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
          `less_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
          `net_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
          `stone_weight` decimal(12,3) NOT NULL DEFAULT 0.000,
          `stone_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `estimated_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `remarks` varchar(255) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_pawn_items_business` (`business_id`),
          KEY `idx_pawn_items_pawn` (`pawn_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $queries[] = "
        CREATE TABLE IF NOT EXISTS `pawn_interest_collections` (
          `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `business_id` int(10) UNSIGNED NOT NULL,
          `pawn_id` int(10) UNSIGNED NOT NULL,
          `receipt_no` varchar(30) NOT NULL,
          `collection_date` date NOT NULL,
          `interest_from` date DEFAULT NULL,
          `interest_to` date DEFAULT NULL,
          `days_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
          `interest_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `penalty_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `net_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `payment_method_id` tinyint(3) UNSIGNED DEFAULT NULL,
          `reference_no` varchar(100) DEFAULT NULL,
          `remarks` text DEFAULT NULL,
          `created_by` int(10) UNSIGNED DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_pawn_interest_receipt_no` (`receipt_no`),
          KEY `idx_pawn_interest_business` (`business_id`),
          KEY `idx_pawn_interest_pawn` (`pawn_id`),
          KEY `idx_pawn_interest_date` (`collection_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $queries[] = "
        CREATE TABLE IF NOT EXISTS `pawn_payments` (
          `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `business_id` int(10) UNSIGNED NOT NULL,
          `pawn_id` int(10) UNSIGNED NOT NULL,
          `receipt_no` varchar(30) NOT NULL,
          `payment_date` date NOT NULL,
          `payment_type` enum('Part Payment','Settlement','Release','Auction Adjustment') NOT NULL DEFAULT 'Part Payment',
          `principal_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `interest_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `penalty_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `charges_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `payment_method_id` tinyint(3) UNSIGNED DEFAULT NULL,
          `reference_no` varchar(100) DEFAULT NULL,
          `remarks` text DEFAULT NULL,
          `created_by` int(10) UNSIGNED DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_pawn_payment_receipt_no` (`receipt_no`),
          KEY `idx_pawn_payments_business` (`business_id`),
          KEY `idx_pawn_payments_pawn` (`pawn_id`),
          KEY `idx_pawn_payments_date` (`payment_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $queries[] = "
        CREATE TABLE IF NOT EXISTS `pawn_auctions` (
          `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          `business_id` int(10) UNSIGNED NOT NULL,
          `pawn_id` int(10) UNSIGNED NOT NULL,
          `auction_no` varchar(30) NOT NULL,
          `auction_date` date NOT NULL,
          `auction_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `expenses_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `net_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
          `remarks` text DEFAULT NULL,
          `created_by` int(10) UNSIGNED DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_pawn_auction_no` (`auction_no`),
          KEY `idx_pawn_auctions_business` (`business_id`),
          KEY `idx_pawn_auctions_pawn` (`pawn_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        foreach ($queries as $sql) {
            $conn->query($sql);
        }
    }
}

/* =========================================================
   NUMBER GENERATORS
========================================================= */
if (!function_exists('pawn_generate_running_number')) {
    function pawn_generate_running_number(mysqli $conn, string $tableName, string $columnName, string $prefix): string
    {
        $todayPrefix = $prefix . date('ymd');
        $stmt = $conn->prepare("SELECT {$columnName} FROM {$tableName} WHERE {$columnName} LIKE ? ORDER BY id DESC LIMIT 1");
        $like = $todayPrefix . '%';
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $nextNo = 1;
        if ($row && !empty($row[$columnName])) {
            $lastCode = (string)$row[$columnName];
            $lastNum = (int)substr($lastCode, -4);
            $nextNo = $lastNum + 1;
        }

        return $todayPrefix . str_pad((string)$nextNo, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pawn_next_pawn_no')) {
    function pawn_next_pawn_no(mysqli $conn): string
    {
        return pawn_generate_running_number($conn, 'pawn_entries', 'pawn_no', 'PWN');
    }
}

if (!function_exists('pawn_next_interest_receipt_no')) {
    function pawn_next_interest_receipt_no(mysqli $conn): string
    {
        return pawn_generate_running_number($conn, 'pawn_interest_collections', 'receipt_no', 'PIR');
    }
}

if (!function_exists('pawn_next_payment_receipt_no')) {
    function pawn_next_payment_receipt_no(mysqli $conn): string
    {
        return pawn_generate_running_number($conn, 'pawn_payments', 'receipt_no', 'PPR');
    }
}

if (!function_exists('pawn_next_auction_no')) {
    function pawn_next_auction_no(mysqli $conn): string
    {
        return pawn_generate_running_number($conn, 'pawn_auctions', 'auction_no', 'PAU');
    }
}

/* =========================================================
   MASTER FETCH
========================================================= */
if (!function_exists('pawn_get_customers')) {
    function pawn_get_customers(mysqli $conn, int $businessId): array
    {
        if (!pawn_table_exists($conn, 'customers')) {
            return [];
        }

        $rows = [];
        $stmt = $conn->prepare("
            SELECT id, customer_name, mobile, address_line1, address_line2, city, state, pincode
            FROM customers
            WHERE business_id = ?
            ORDER BY customer_name ASC
        ");
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }
}

if (!function_exists('pawn_get_payment_methods')) {
    function pawn_get_payment_methods(mysqli $conn): array
    {
        if (!pawn_table_exists($conn, 'payment_methods')) {
            return [];
        }

        $rows = [];
        $res = $conn->query("SELECT id, method_name FROM payment_methods WHERE is_active = 1 ORDER BY id ASC");
        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}

/* =========================================================
   SINGLE RECORD FETCH
========================================================= */
if (!function_exists('pawn_get_entry')) {
    function pawn_get_entry(mysqli $conn, int $businessId, int $pawnId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM pawn_entries WHERE business_id = ? AND id = ? LIMIT 1");
        $stmt->bind_param('ii', $businessId, $pawnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('pawn_get_items')) {
    function pawn_get_items(mysqli $conn, int $businessId, int $pawnId): array
    {
        $rows = [];
        $stmt = $conn->prepare("SELECT * FROM pawn_items WHERE business_id = ? AND pawn_id = ? ORDER BY id ASC");
        $stmt->bind_param('ii', $businessId, $pawnId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && $row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }
}

/* =========================================================
   INTEREST CALCULATION
========================================================= */
if (!function_exists('pawn_last_interest_to_date')) {
    function pawn_last_interest_to_date(mysqli $conn, int $pawnId, string $defaultDate): string
    {
        $stmt = $conn->prepare("
            SELECT interest_to, collection_date
            FROM pawn_interest_collections
            WHERE pawn_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $pawnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            return (string)($row['interest_to'] ?: $row['collection_date'] ?: $defaultDate);
        }

        return $defaultDate;
    }
}

if (!function_exists('pawn_due_interest')) {
    function pawn_due_interest(mysqli $conn, array $pawn, string $asOfDate = ''): array
    {
        $asOfDate = $asOfDate !== '' ? $asOfDate : date('Y-m-d');
        $entryDate = (string)($pawn['entry_date'] ?? $asOfDate);
        $lastDate = pawn_last_interest_to_date($conn, (int)$pawn['id'], $entryDate);

        try {
            $start = new DateTime($lastDate);
            $end   = new DateTime($asOfDate);
        } catch (Throwable $e) {
            return [
                'days'   => 0,
                'amount' => 0.00,
                'from'   => $entryDate,
                'to'     => $asOfDate
            ];
        }

        if ($end <= $start) {
            return [
                'days'   => 0,
                'amount' => 0.00,
                'from'   => $lastDate,
                'to'     => $asOfDate
            ];
        }

        $days = (int)$start->diff($end)->days;
        $principal = (float)($pawn['principal_balance'] ?? 0);
        $rate = (float)($pawn['interest_rate'] ?? 0);
        $interestType = (string)($pawn['interest_type'] ?? 'Monthly');

        if ($principal <= 0 || $rate <= 0 || $days <= 0) {
            return [
                'days'   => 0,
                'amount' => 0.00,
                'from'   => $lastDate,
                'to'     => $asOfDate
            ];
        }

        if ($interestType === 'Daily') {
            $amount = ($principal * $rate / 100) * $days;
        } elseif ($interestType === 'Weekly') {
            $amount = ($principal * $rate / 100) * ($days / 7);
        } else {
            $amount = ($principal * $rate / 100) * ($days / 30);
        }

        return [
            'days'   => $days,
            'amount' => round($amount, 2),
            'from'   => $lastDate,
            'to'     => $asOfDate
        ];
    }
}

/* =========================================================
   TOTALS / SUMMARY
========================================================= */
if (!function_exists('pawn_total_interest_collected')) {
    function pawn_total_interest_collected(mysqli $conn, int $pawnId): float
    {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(net_amount), 0) AS total_amount
            FROM pawn_interest_collections
            WHERE pawn_id = ?
        ");
        $stmt->bind_param('i', $pawnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return (float)($row['total_amount'] ?? 0);
    }
}

if (!function_exists('pawn_total_payments')) {
    function pawn_total_payments(mysqli $conn, int $pawnId): float
    {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) AS total_amount
            FROM pawn_payments
            WHERE pawn_id = ?
        ");
        $stmt->bind_param('i', $pawnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return (float)($row['total_amount'] ?? 0);
    }
}

/* =========================================================
   STATUS HELPERS
========================================================= */
if (!function_exists('pawn_status_badge')) {
    function pawn_status_badge(string $status): string
    {
        switch ($status) {
            case 'Released':
                return 'success';
            case 'Auctioned':
                return 'danger';
            case 'Closed':
                return 'secondary';
            case 'Partially Paid':
                return 'warning';
            default:
                return 'primary';
        }
    }
}

if (!function_exists('pawn_is_active')) {
    function pawn_is_active(array $pawn): bool
    {
        return (string)($pawn['status'] ?? '') === 'Active';
    }
}
?>