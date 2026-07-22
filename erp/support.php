<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$userName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
];

if (isset($conn) && $conn instanceof mysqli) {
    $businessId = (int)($_SESSION['business_id'] ?? 0);
    if ($businessId > 0) {
        $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            foreach ($theme as $key => $value) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    $theme[$key] = $row[$key];
                }
            }
        }
    }
}

$supportEmail = 'info@ecommer.in';
$supportPhone = '+91 9003552650';
$supportPhoneRaw = '919003552650';
$supportHours = 'Monday to Saturday, 10:00 AM to 7:00 PM';
$supportAddress = 'A15, Sandhanagounder Complex, Sogathur X Road, Pennagaram Main Road, Dharmapuri - 636809';
$supportWebsite = 'https://www.ecommer.in/';

$defaultMessage = rawurlencode(
    "Hello Ecommer Support,\n\n" .
    "I need assistance with {$businessName}.\n" .
    "User: {$userName}\n\n" .
    "Issue details: "
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($businessName) ?> - Support</title>
    <?php include 'includes/links.php'; ?>
    <style>
        :root{
            --primary:<?= e($theme['primary_color']) ?>;
            --primary-dark:<?= e($theme['primary_dark_color']) ?>;
            --primary-soft:<?= e($theme['primary_soft_color']) ?>;
            --page-bg:<?= e($theme['page_background']) ?>;
            --card-bg:<?= e($theme['card_background']) ?>;
            --text:<?= e($theme['text_color']) ?>;
            --muted:<?= e($theme['muted_text_color']) ?>;
            --line:<?= e($theme['border_color']) ?>;
            --radius:<?= (int)$theme['border_radius_px'] ?>px;
        }

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?= json_encode((string)$theme['font_family']) ?>,sans-serif;
        }

        .support-hero,
        .support-card,
        .faq-card{
            background:var(--card-bg);
            border:1px solid var(--line);
            border-radius:var(--radius);
        }

        .support-hero{
            position:relative;
            overflow:hidden;
            padding:24px;
            margin-bottom:14px;
            background:linear-gradient(135deg,var(--card-bg),var(--primary-soft));
        }

        .support-hero:after{
            content:"";
            position:absolute;
            width:220px;
            height:220px;
            right:-70px;
            top:-90px;
            border-radius:50%;
            background:color-mix(in srgb,var(--primary) 16%,transparent);
        }

        .support-title{
            margin:0;
            font:700 25px <?= json_encode((string)$theme['heading_font_family']) ?>,serif;
        }

        .support-subtitle{
            max-width:690px;
            margin-top:6px;
            color:var(--muted);
            font-size:11px;
            line-height:1.7;
        }

        .status-line{
            display:inline-flex;
            align-items:center;
            gap:7px;
            margin-top:14px;
            padding:7px 10px;
            border-radius:999px;
            background:#eaf8f0;
            color:#168449;
            font-size:9px;
            font-weight:800;
        }

        .status-dot{
            width:7px;
            height:7px;
            border-radius:50%;
            background:#22a85a;
        }

        .support-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
            margin-bottom:14px;
        }

        .support-card{
            padding:16px;
            display:flex;
            flex-direction:column;
            min-height:185px;
        }

        .support-icon{
            width:43px;
            height:43px;
            border-radius:11px;
            display:grid;
            place-items:center;
            background:var(--primary-soft);
            color:var(--primary-dark);
            font-size:17px;
        }

        .support-card h3{
            margin:13px 0 4px;
            font-size:13px;
            font-weight:900;
        }

        .support-card p{
            margin:0;
            color:var(--muted);
            font-size:9px;
            line-height:1.6;
        }

        .support-value{
            margin-top:9px;
            font-size:11px;
            font-weight:800;
            word-break:break-word;
        }

        .support-action{
            margin-top:auto;
            padding-top:13px;
        }

        .btn-theme,
        .btn-soft{
            min-height:37px;
            padding:8px 12px;
            border-radius:9px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            font-size:10px;
            font-weight:800;
            text-decoration:none;
        }

        .btn-theme{
            border:0;
            color:#fff;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
        }

        .btn-soft{
            border:1px solid var(--line);
            background:var(--card-bg);
            color:var(--text);
        }

        .content-grid{
            display:grid;
            grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);
            gap:14px;
        }

        .faq-card{
            overflow:hidden;
        }

        .section-head{
            padding:14px 16px;
            border-bottom:1px solid var(--line);
        }

        .section-head h2{
            margin:0;
            font-size:13px;
            font-weight:900;
        }

        .section-head p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:9px;
        }

        .faq-item{
            padding:14px 16px;
            border-bottom:1px solid var(--line);
        }

        .faq-item:last-child{
            border-bottom:0;
        }

        .faq-question{
            font-size:10px;
            font-weight:900;
        }

        .faq-answer{
            margin-top:5px;
            color:var(--muted);
            font-size:9px;
            line-height:1.65;
        }

        .contact-panel{
            padding:16px;
        }

        .contact-row{
            display:flex;
            gap:10px;
            padding:11px 0;
            border-bottom:1px dashed var(--line);
        }

        .contact-row:last-child{
            border-bottom:0;
        }

        .contact-row i{
            width:26px;
            color:var(--primary-dark);
            margin-top:2px;
        }

        .contact-label{
            color:var(--muted);
            font-size:8px;
            text-transform:uppercase;
        }

        .contact-value{
            margin-top:2px;
            font-size:10px;
            font-weight:800;
            line-height:1.5;
        }

        .quick-note{
            margin-top:12px;
            padding:11px;
            border-radius:10px;
            background:var(--primary-soft);
            color:var(--primary-dark);
            font-size:9px;
            line-height:1.6;
        }

        body.dark-mode,
        body[data-theme="dark"]{
            --page-bg:#0f151b;
            --card-bg:#182129;
            --text:#f3f6f8;
            --muted:#9aa7b3;
            --line:#2c3944;
        }

        @media(max-width:1100px){
            .support-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
            .content-grid{grid-template-columns:1fr}
        }

        @media(max-width:650px){
            .support-grid{grid-template-columns:1fr}
            .support-hero{padding:18px}
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
    <?php include 'includes/nav.php'; ?>

    <div class="content-wrap">
        <section class="support-hero">
            <div style="position:relative;z-index:1">
                <h1 class="support-title">Ecommer Support</h1>
                <div class="support-subtitle">
                    Get assistance for billing, stock, reports, printer setup, data correction and general software usage. Support is available in Tamil and English during business hours.
                </div>
                <div class="status-line">
                    <span class="status-dot"></span>
                    Support available Monday to Saturday
                </div>
            </div>
        </section>

        <section class="support-grid">
            <article class="support-card">
                <div class="support-icon"><i class="fa-brands fa-whatsapp"></i></div>
                <h3>WhatsApp Support</h3>
                <p>Send screenshots, error messages and details of the issue for faster assistance.</p>
                <div class="support-value"><?= e($supportPhone) ?></div>
                <div class="support-action">
                    <a class="btn-theme" target="_blank" rel="noopener"
                       href="https://wa.me/<?= e($supportPhoneRaw) ?>?text=<?= e($defaultMessage) ?>">
                        <i class="fa-brands fa-whatsapp"></i>Message Support
                    </a>
                </div>
            </article>

            <article class="support-card">
                <div class="support-icon"><i class="fa-solid fa-phone"></i></div>
                <h3>Phone Support</h3>
                <p>Call the Ecommer support team during working hours for urgent software assistance.</p>
                <div class="support-value"><?= e($supportPhone) ?></div>
                <div class="support-action">
                    <a class="btn-soft" href="tel:+919003552650">
                        <i class="fa-solid fa-phone"></i>Call Now
                    </a>
                </div>
            </article>

            <article class="support-card">
                <div class="support-icon"><i class="fa-solid fa-envelope"></i></div>
                <h3>Email Support</h3>
                <p>Email full issue details, screenshots or supporting documents to the support team.</p>
                <div class="support-value"><?= e($supportEmail) ?></div>
                <div class="support-action">
                    <a class="btn-soft"
                       href="mailto:<?= e($supportEmail) ?>?subject=Support Request - <?= e(rawurlencode($businessName)) ?>">
                        <i class="fa-solid fa-envelope"></i>Send Email
                    </a>
                </div>
            </article>

            <article class="support-card">
                <div class="support-icon"><i class="fa-solid fa-globe"></i></div>
                <h3>Official Website</h3>
                <p>Visit the Ecommer website to view products, services, integrations and company information.</p>
                <div class="support-value">www.ecommer.in</div>
                <div class="support-action">
                    <a class="btn-soft" target="_blank" rel="noopener" href="<?= e($supportWebsite) ?>">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>Open Website
                    </a>
                </div>
            </article>
        </section>

        <section class="content-grid">
            <div class="faq-card">
                <div class="section-head">
                    <h2>Common Support Topics</h2>
                    <p>Information to include when contacting the support team.</p>
                </div>

                <div class="faq-item">
                    <div class="faq-question">Billing or invoice problem</div>
                    <div class="faq-answer">Share the invoice number, customer name, transaction date and a screenshot of the incorrect value or error.</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">Stock quantity mismatch</div>
                    <div class="faq-answer">Mention the product name or code, expected stock, currently displayed stock and the related purchase or sales invoice.</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">Printer or barcode issue</div>
                    <div class="faq-answer">Share the printer model, paper or label size, browser name and a screenshot of the print preview.</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">Login or permission issue</div>
                    <div class="faq-answer">Provide the username, assigned role, affected page name and the exact access-denied or session message.</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">Data correction request</div>
                    <div class="faq-answer">Include record IDs, invoice numbers and the correct values. Avoid sending database passwords through normal messages.</div>
                </div>
            </div>

            <aside class="faq-card">
                <div class="section-head">
                    <h2>Support Information</h2>
                    <p>Official Ecommer contact information.</p>
                </div>

                <div class="contact-panel">
                    <div class="contact-row">
                        <i class="fa-solid fa-clock"></i>
                        <div>
                            <div class="contact-label">Working Hours</div>
                            <div class="contact-value"><?= e($supportHours) ?></div>
                        </div>
                    </div>

                    <div class="contact-row">
                        <i class="fa-solid fa-language"></i>
                        <div>
                            <div class="contact-label">Languages</div>
                            <div class="contact-value">Tamil and English</div>
                        </div>
                    </div>

                    <div class="contact-row">
                        <i class="fa-solid fa-location-dot"></i>
                        <div>
                            <div class="contact-label">Office Address</div>
                            <div class="contact-value"><?= e($supportAddress) ?></div>
                        </div>
                    </div>

                    <div class="contact-row">
                        <i class="fa-solid fa-building"></i>
                        <div>
                            <div class="contact-label">Company</div>
                            <div class="contact-value">Ecommer — Complete Business Software Solutions</div>
                        </div>
                    </div>

                    <div class="quick-note">
                        <strong>Before contacting support:</strong><br>
                        Keep the affected page open and send a clear screenshot containing the complete error message.
                    </div>
                </div>
            </aside>
        </section>

        <?php include 'includes/footer.php'; ?>
    </div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
</body>
</html>