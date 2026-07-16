<?php
if (!function_exists('documentNumberPeriodCondition')) {
    function documentNumberPeriodCondition(string $frequency, string $dateColumn, string $date): array
    {
        $timestamp = strtotime($date) ?: time();
        $year = (int)date('Y', $timestamp);
        $month = (int)date('m', $timestamp);
        $day = date('Y-m-d', $timestamp);

        if ($frequency === 'Financial Year') {
            $fyStartYear = $month >= 4 ? $year : $year - 1;
            return ["{$dateColumn} >= ? AND {$dateColumn} < ?", [sprintf('%04d-04-01', $fyStartYear), sprintf('%04d-04-01', $fyStartYear + 1)], 'ss'];
        }
        if ($frequency === 'Calendar Year') {
            return ["YEAR({$dateColumn}) = ?", [$year], 'i'];
        }
        if ($frequency === 'Monthly') {
            return ["YEAR({$dateColumn}) = ? AND MONTH({$dateColumn}) = ?", [$year, $month], 'ii'];
        }
        if ($frequency === 'Daily') {
            return ["DATE({$dateColumn}) = ?", [$day], 's'];
        }
        return ['1=1', [], ''];
    }
}

if (!function_exists('documentNumberMap')) {
    function documentNumberMap(string $documentKey): array
    {
        $map = [
            'invoice' => ['table' => 'sales', 'number' => 'invoice_no', 'date' => 'invoice_date'],
            'purchase' => ['table' => 'purchases', 'number' => 'purchase_no', 'date' => 'purchase_date'],
            'pawn' => ['table' => 'pawn_entries', 'number' => 'pawn_no', 'date' => 'pawn_date'],
            'chit' => ['table' => 'chit_groups', 'number' => 'group_no', 'date' => 'start_date'],
        ];
        if (!isset($map[$documentKey])) {
            throw new InvalidArgumentException('Unsupported document number key.');
        }
        return $map[$documentKey];
    }
}

if (!function_exists('documentNumberCenter')) {
    function documentNumberCenter(string $format, string $date): string
    {
        $ts = strtotime($date) ?: time();
        $year = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $fyStart = $month >= 4 ? $year : $year - 1;
        $replacements = [
            '{FY_SHORT}' => substr((string)$fyStart, -2) . '-' . substr((string)($fyStart + 1), -2),
            '{FY_2DIGIT}' => substr((string)$fyStart, -2) . substr((string)($fyStart + 1), -2),
            '{YYYY}' => date('Y', $ts),
            '{YY}' => date('y', $ts),
            '{MM}' => date('m', $ts),
            '{DD}' => date('d', $ts),
        ];
        return strtr($format, $replacements);
    }
}

if (!function_exists('generateDocumentNumber')) {
    function generateDocumentNumber(mysqli $conn, int $businessId, int $branchId, string $documentKey, string $documentDate): string
    {
        $stmt = $conn->prepare("SELECT * FROM document_number_settings WHERE business_id=? AND document_key=? AND is_active=1 AND (branch_id=? OR branch_id IS NULL) ORDER BY (branch_id=?) DESC,id DESC LIMIT 1 FOR UPDATE");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('isii', $businessId, $documentKey, $branchId, $branchId);
        $stmt->execute();
        $setting = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$setting) throw new RuntimeException('Numbering setting is not configured for ' . $documentKey . '.');

        $meta = documentNumberMap($documentKey);
        [$periodSql, $periodParams, $periodTypes] = documentNumberPeriodCondition($setting['reset_frequency'], $meta['date'], $documentDate);
        $sql = "SELECT COUNT(*) AS total FROM `{$meta['table']}` WHERE business_id=? AND branch_id=? AND {$periodSql}";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException($conn->error);
        $params = array_merge([$businessId, $branchId], $periodParams);
        $types = 'ii' . $periodTypes;
        $bind = [$types];
        foreach ($params as $k => $v) $bind[] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $sequence = (int)$setting['sequence_start'] + $count;
        $seq = str_pad((string)$sequence, max(1, (int)$setting['sequence_digits']), '0', STR_PAD_LEFT);
        $center = documentNumberCenter((string)$setting['center_format'], $documentDate);
        $template = (string)$setting['format_template'];
        return strtr($template, [
            '{PREFIX}' => (string)$setting['prefix'],
            '{DIVIDER}' => (string)$setting['divider'],
            '{CENTER}' => $center,
            '{SEQ}' => $seq,
            '{SUFFIX}' => (string)$setting['suffix'],
        ]);
    }
}
