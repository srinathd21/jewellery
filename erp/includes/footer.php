<footer class="footer-panel mt-2 d-flex flex-column flex-md-row align-items-center justify-content-between gap-2">
    <span>© <?php echo date('Y'); ?> Swarnam Jewellers. All rights reserved.</span>

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