<?php
$footerBusinessName = 'Business';

if (isset($conn) && $conn instanceof mysqli) {
    $businessId = (int)($_SESSION['business_id'] ?? 0);

    if ($businessId > 0) {
        $stmt = $conn->prepare("
            SELECT business_name
            FROM businesses
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("i", $businessId);
            $stmt->execute();

            $result = $stmt->get_result();
            $business = $result->fetch_assoc();

            if ($business && !empty($business['business_name'])) {
                $footerBusinessName = $business['business_name'];
            }

            $stmt->close();
        }
    }
}
?>
<footer class="footer-panel mt-2 d-flex flex-column flex-md-row align-items-center justify-content-between gap-2">

    <span>
        © <?php echo date('Y'); ?> 
        <?php echo htmlspecialchars($footerBusinessName); ?>.
        All rights reserved.
    </span>

    <div class="d-flex align-items-center gap-4">

        <span>
            Business Date:
            <strong><?php echo date('d M Y'); ?></strong>
            <i class="fa-regular fa-calendar ms-2"></i>
        </span>

        <span>
            Financial Year:
            <strong>
                <?php
                $year = date('Y');
                $month = date('n');

                if ($month >= 4) {
                    echo $year . '-' . substr($year + 1, -2);
                } else {
                    echo ($year - 1) . '-' . substr($year, -2);
                }
                ?>
            </strong>
            <i class="fa-solid fa-chevron-down ms-2"></i>
        </span>

    </div>

</footer>