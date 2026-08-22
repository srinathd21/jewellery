<?php
require __DIR__ . '/_common.php';

function psrTableExists($conn, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

function psrLoadSettings($conn, $businessId, $defaults)
{
    if (!psrTableExists($conn, 'business_settings')) return $defaults;

    $keys = array_keys($defaults);
    if (!$keys) return $defaults;

    $quoted = array();
    foreach ($keys as $key) {
        $quoted[] = "'" . $conn->real_escape_string($key) . "'";
    }

    $sql = 'SELECT setting_key, setting_value FROM business_settings'
         . ' WHERE business_id=' . (int)$businessId
         . ' AND setting_key IN (' . implode(',', $quoted) . ')';

    $r = $conn->query($sql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $key = (string)$row['setting_key'];
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = (string)($row['setting_value'] ?? '');
            }
        }
    }

    return $defaults;
}

$hasBusinessSettings = psrTableExists($conn, 'business_settings');

$receiptDefaults = array(
    'pawn_receipt_business_name' => '',
    'pawn_receipt_tagline' => 'GOLD - SILVER - DIAMOND - PRECIOUS JEWELLERY',
    'pawn_receipt_title' => 'PAWN LOAN RECEIPT',
    'pawn_receipt_copy_label' => 'ORIGINAL FOR CUSTOMER',
    'pawn_receipt_address' => '',
    'pawn_receipt_mobile' => '',
    'pawn_receipt_email' => '',
    'pawn_receipt_website' => '',
    'pawn_receipt_gstin' => '',
    'pawn_receipt_footer_text' => 'This is a system-generated pawn receipt.',
    'pawn_receipt_terms_conditions' => '',
    'pawn_receipt_upi_id' => '',
    'pawn_receipt_watermark_text' => '',
    'pawn_receipt_logo_path' => '',
    'pawn_receipt_signature_path' => '',
    'pawn_receipt_stamp_path' => '',
    'pawn_receipt_qr_path' => '',
    'pawn_receipt_show_logo' => '1',
    'pawn_receipt_show_address' => '1',
    'pawn_receipt_show_mobile' => '1',
    'pawn_receipt_show_email' => '1',
    'pawn_receipt_show_website' => '1',
    'pawn_receipt_show_gstin' => '1',
    'pawn_receipt_show_watermark' => '1',
    'pawn_receipt_show_terms' => '1',
    'pawn_receipt_show_signature' => '1',
    'pawn_receipt_show_stamp' => '1',
    'pawn_receipt_show_upi' => '1',
    'pawn_receipt_show_qr' => '1'
);

$receipt = psrLoadSettings($conn, $businessId, $receiptDefaults);

// Preview fallbacks only. Saving blank values still means "use master data" in pawn-receipt.php.
$preview = array(
    'business_name' => (string)($businessName ?? 'Jewellery Business'),
    'address' => '',
    'mobile' => '',
    'email' => '',
    'website' => '',
    'gstin' => ''
);

if (psrTableExists($conn, 'businesses')) {
    $s = $conn->prepare('SELECT business_name,legal_name,mobile,email,website,gstin FROM businesses WHERE id=? LIMIT 1');
    if ($s) {
        $s->bind_param('i', $businessId);
        $s->execute();
        $b = $s->get_result()->fetch_assoc();
        $s->close();
        if ($b) {
            $preview['business_name'] = (string)(($b['business_name'] ?? '') ?: ($b['legal_name'] ?? '') ?: $preview['business_name']);
            $preview['mobile'] = (string)($b['mobile'] ?? '');
            $preview['email'] = (string)($b['email'] ?? '');
            $preview['website'] = (string)($b['website'] ?? '');
            $preview['gstin'] = (string)($b['gstin'] ?? '');
        }
    }
}

if (!empty($branchId) && psrTableExists($conn, 'branches')) {
    $s = $conn->prepare('SELECT address_line1,address_line2,city,state,pincode,mobile,email,gstin FROM branches WHERE id=? AND business_id=? LIMIT 1');
    if ($s) {
        $bid = (int)$branchId;
        $s->bind_param('ii', $bid, $businessId);
        $s->execute();
        $br = $s->get_result()->fetch_assoc();
        $s->close();
        if ($br) {
            $preview['address'] = trim(implode(', ', array_filter(array(
                $br['address_line1'] ?? '', $br['address_line2'] ?? '', $br['city'] ?? '', $br['state'] ?? '', $br['pincode'] ?? ''
            ))));
            if (!empty($br['mobile'])) $preview['mobile'] = (string)$br['mobile'];
            if (!empty($br['email'])) $preview['email'] = (string)$br['email'];
            if (!empty($br['gstin'])) $preview['gstin'] = (string)$br['gstin'];
        }
    }
}

function psrAssetUrl($path)
{
    $path = trim((string)$path);
    return $path === '' ? '' : $path;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Receipt Settings</title>
    <?php include('includes/links.php'); require __DIR__ . '/_style.php'; ?>
    <style>
        .receipt-page-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
        .receipt-layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:14px;align-items:start}
        .receipt-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .receipt-grid .wide{grid-column:1/-1}
        .receipt-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 14px}
        .receipt-options label{font-size:10px;display:flex;align-items:center;gap:6px}
        .receipt-preview-card{position:sticky;top:82px}
        .receipt-preview{border:1px solid var(--line);border-radius:12px;padding:16px;background:var(--card-bg)}
        .preview-top{display:grid;grid-template-columns:76px minmax(0,1fr) 135px;gap:12px;align-items:center}
        .preview-logo{height:76px;border:1px solid var(--line);border-radius:9px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:var(--primary-soft);font-weight:900;color:var(--primary-dark)}
        .preview-logo img{width:100%;height:100%;object-fit:contain;background:#fff}
        .preview-center{text-align:center;min-width:0}
        .preview-name{font-size:18px;font-weight:900;color:var(--primary-dark);line-height:1.15}
        .preview-tag{font-size:9px;font-weight:800;color:var(--primary);margin-top:5px}
        .preview-meta{font-size:8px;color:var(--muted);margin-top:4px;line-height:1.5;word-break:break-word}
        .preview-right{text-align:center}
        .preview-title{font-size:11px;font-weight:900;color:var(--primary-dark)}
        .preview-copy{border:1px solid var(--primary);background:var(--primary-soft);padding:6px 5px;margin-top:6px;font-size:7px;font-weight:800;color:var(--primary-dark)}
        .preview-line{height:3px;background:var(--primary-dark);margin-top:10px;border-radius:99px}
        .asset-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .asset-box{border:1px solid var(--line);border-radius:10px;padding:12px;background:var(--card-bg)}
        .asset-note{font-size:9px;color:var(--muted);margin-top:5px;word-break:break-all}
        .asset-thumb{height:65px;border:1px dashed var(--line);border-radius:8px;margin-top:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--muted);font-size:9px}
        .asset-thumb img{width:100%;height:100%;object-fit:contain;background:#fff}
        .section-caption{font-size:10px;color:var(--muted);margin-top:-5px;margin-bottom:12px}
        .toast-x{position:fixed;right:18px;top:78px;z-index:25000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:700;box-shadow:0 12px 30px rgba(0,0,0,.18)}
        .toast-ok{background:#168449}.toast-bad{background:#c0392b}
        .save-bar{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:16px}
        @media(max-width:1150px){.receipt-layout{grid-template-columns:1fr}.receipt-preview-card{position:static}.preview-top{grid-template-columns:70px minmax(0,1fr) 125px}}
        @media(max-width:767px){.receipt-grid,.asset-grid{grid-template-columns:1fr}.receipt-grid .wide{grid-column:auto}.receipt-options{grid-template-columns:repeat(2,minmax(0,1fr))}.preview-top{grid-template-columns:1fr;text-align:center}.preview-logo{width:76px;margin:auto}.preview-right{max-width:210px;margin:auto;width:100%}}
        @media(max-width:480px){.receipt-options{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <div class="page-card mb-3">
            <div class="page-head receipt-page-head">
                <div>
                    <div class="page-title">Pawn Receipt Settings</div>
                    <div class="small text-muted">Separate settings used only by Pawn Receipt. Invoice Settings will not affect this page.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="pawn-settings.php" class="btn-soft"><i class="fa-solid fa-sliders me-1"></i> Pawn Settings</a>
                    <a href="pawn-manage.php" class="btn-soft"><i class="fa-solid fa-arrow-left me-1"></i> Pawn Manage</a>
                </div>
            </div>
        </div>

        <?php if(!$hasBusinessSettings): ?>
            <div class="page-card mb-3">
                <div class="card-body-x text-danger">The <code>business_settings</code> table is not available. Receipt settings cannot be saved.</div>
            </div>
        <?php else: ?>
        <form id="receiptForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_receipt">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="receipt-layout">
                <div>
                    <div class="page-card mb-3">
                        <div class="page-head"><div class="section-title">Receipt Header</div></div>
                        <div class="card-body-x">
                            <div class="section-caption">All blank business/contact fields automatically use the current branch/business master when the PDF is generated.</div>
                            <div class="receipt-grid">
                                <div><label class="form-label">Display Business Name</label><input class="form-control receipt-live" name="pawn_receipt_business_name" value="<?= e($receipt['pawn_receipt_business_name']) ?>" placeholder="Blank = business master name"></div>
                                <div><label class="form-label">Tagline</label><input class="form-control receipt-live" name="pawn_receipt_tagline" value="<?= e($receipt['pawn_receipt_tagline']) ?>"></div>
                                <div><label class="form-label">Receipt Title</label><input class="form-control receipt-live" name="pawn_receipt_title" value="<?= e($receipt['pawn_receipt_title']) ?>"></div>
                                <div><label class="form-label">Copy Label</label><input class="form-control receipt-live" name="pawn_receipt_copy_label" value="<?= e($receipt['pawn_receipt_copy_label']) ?>"></div>
                                <div class="wide"><label class="form-label">Receipt Address</label><input class="form-control receipt-live" name="pawn_receipt_address" value="<?= e($receipt['pawn_receipt_address']) ?>" placeholder="Blank = current pawn branch address"></div>
                                <div><label class="form-label">Mobile</label><input class="form-control receipt-live" name="pawn_receipt_mobile" value="<?= e($receipt['pawn_receipt_mobile']) ?>" placeholder="Blank = branch/business mobile"></div>
                                <div><label class="form-label">Email</label><input type="email" class="form-control receipt-live" name="pawn_receipt_email" value="<?= e($receipt['pawn_receipt_email']) ?>" placeholder="Blank = branch/business email"></div>
                                <div><label class="form-label">Website</label><input class="form-control receipt-live" name="pawn_receipt_website" value="<?= e($receipt['pawn_receipt_website']) ?>" placeholder="Blank = business website"></div>
                                <div><label class="form-label">GSTIN</label><input class="form-control receipt-live" name="pawn_receipt_gstin" maxlength="20" value="<?= e($receipt['pawn_receipt_gstin']) ?>" placeholder="Blank = branch/business GSTIN"></div>
                            </div>
                        </div>
                    </div>

                    <div class="page-card mb-3">
                        <div class="page-head"><div class="section-title">Receipt Images</div></div>
                        <div class="card-body-x">
                            <div class="asset-grid">
                                <?php
                                $assets = array(
                                    array('Pawn Receipt Logo','pawn_receipt_logo','pawn_receipt_logo_path','remove_receipt_logo'),
                                    array('Authorised Signature','pawn_receipt_signature','pawn_receipt_signature_path','remove_receipt_signature'),
                                    array('Stamp','pawn_receipt_stamp','pawn_receipt_stamp_path','remove_receipt_stamp'),
                                    array('UPI QR Image','pawn_receipt_qr','pawn_receipt_qr_path','remove_receipt_qr')
                                );
                                foreach($assets as $a):
                                    $current = (string)$receipt[$a[2]];
                                ?>
                                <div class="asset-box">
                                    <label class="form-label"><?= e($a[0]) ?></label>
                                    <input type="file" class="form-control" name="<?= e($a[1]) ?>" accept=".png,.jpg,.jpeg,.webp">
                                    <div class="asset-note"><?= $current !== '' ? 'Current: '.e($current) : 'No image uploaded.' ?></div>
                                    <div class="asset-thumb">
                                        <?php if($current !== ''): ?><img src="<?= e(psrAssetUrl($current)) ?>" alt="<?= e($a[0]) ?>"><?php else: ?>No image<?php endif; ?>
                                    </div>
                                    <label class="mt-2 small"><input type="checkbox" name="<?= e($a[3]) ?>" value="1"> Remove current image</label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="page-card mb-3">
                        <div class="page-head"><div class="section-title">Terms, Payment & Footer</div></div>
                        <div class="card-body-x">
                            <div class="receipt-grid">
                                <div class="wide"><label class="form-label">Terms & Conditions</label><textarea class="form-control" rows="5" name="pawn_receipt_terms_conditions"><?= e($receipt['pawn_receipt_terms_conditions']) ?></textarea></div>
                                <div class="wide"><label class="form-label">Footer Text</label><textarea class="form-control" rows="3" name="pawn_receipt_footer_text"><?= e($receipt['pawn_receipt_footer_text']) ?></textarea></div>
                                <div><label class="form-label">UPI ID</label><input class="form-control" name="pawn_receipt_upi_id" value="<?= e($receipt['pawn_receipt_upi_id']) ?>"></div>
                                <div><label class="form-label">Watermark Text</label><input class="form-control" name="pawn_receipt_watermark_text" value="<?= e($receipt['pawn_receipt_watermark_text']) ?>" placeholder="Blank = display business name"></div>
                            </div>
                        </div>
                    </div>

                    <div class="page-card">
                        <div class="page-head"><div class="section-title">Show / Hide Options</div></div>
                        <div class="card-body-x">
                            <div class="receipt-options">
                                <?php
                                $receiptChecks = array(
                                    'pawn_receipt_show_logo'=>'Show Logo',
                                    'pawn_receipt_show_address'=>'Show Address',
                                    'pawn_receipt_show_mobile'=>'Show Mobile',
                                    'pawn_receipt_show_email'=>'Show Email',
                                    'pawn_receipt_show_website'=>'Show Website',
                                    'pawn_receipt_show_gstin'=>'Show GSTIN',
                                    'pawn_receipt_show_watermark'=>'Show Watermark',
                                    'pawn_receipt_show_terms'=>'Show Terms',
                                    'pawn_receipt_show_signature'=>'Show Signature',
                                    'pawn_receipt_show_stamp'=>'Show Stamp',
                                    'pawn_receipt_show_upi'=>'Show UPI ID',
                                    'pawn_receipt_show_qr'=>'Show UPI QR'
                                );
                                foreach($receiptChecks as $key=>$label): ?>
                                    <label><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $receipt[$key]==='1'?'checked':'' ?>> <?= e($label) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="save-bar">
                                <button type="button" class="btn-soft" id="resetReceiptBtn"><i class="fa-solid fa-rotate-left me-1"></i> Reset Form</button>
                                <button class="btn-theme" type="submit" id="saveReceiptBtn"><i class="fa-solid fa-floppy-disk me-1"></i> Save Pawn Receipt Settings</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="page-card receipt-preview-card">
                    <div class="page-head"><div class="section-title">Header Preview</div></div>
                    <div class="card-body-x">
                        <div class="receipt-preview">
                            <div class="preview-top">
                                <div class="preview-logo" id="rpLogo">
                                    <?php if($receipt['pawn_receipt_logo_path'] !== ''): ?>
                                        <img src="<?= e(psrAssetUrl($receipt['pawn_receipt_logo_path'])) ?>" alt="Logo">
                                    <?php else: ?>
                                        <?= e(strtoupper(substr(preg_replace('/[^A-Za-z]/','',$preview['business_name']),0,3)) ?: 'JW') ?>
                                    <?php endif; ?>
                                </div>
                                <div class="preview-center">
                                    <div class="preview-name" id="rpName"></div>
                                    <div class="preview-tag" id="rpTag"></div>
                                    <div class="preview-meta" id="rpAddress"></div>
                                    <div class="preview-meta" id="rpContact"></div>
                                    <div class="preview-meta" id="rpGstin"></div>
                                </div>
                                <div class="preview-right">
                                    <div class="preview-title" id="rpTitle"></div>
                                    <div class="preview-copy" id="rpCopy"></div>
                                </div>
                            </div>
                            <div class="preview-line"></div>
                        </div>
                        <div class="small text-muted mt-3">This preview uses master-data fallbacks for blank fields. The generated Pawn Receipt uses the same fallback logic.</div>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php include('includes/footer.php'); ?>
    </div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    window.scrollTo(0, 0);

    const form = document.getElementById('receiptForm');
    if (!form) return;

    const fallback = <?= json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function toast(ok, msg) {
        const d = document.createElement('div');
        d.className = 'toast-x ' + (ok ? 'toast-ok' : 'toast-bad');
        d.textContent = msg;
        document.body.appendChild(d);
        setTimeout(() => d.remove(), 3200);
    }

    function value(name) {
        const el = form.elements[name];
        return el ? String(el.value || '').trim() : '';
    }

    function checked(name) {
        const el = form.elements[name];
        return !!(el && el.checked);
    }

    function receiptPreview() {
        const name = value('pawn_receipt_business_name') || fallback.business_name || 'Jewellery Business';
        const address = value('pawn_receipt_address') || fallback.address || '';
        const mobile = value('pawn_receipt_mobile') || fallback.mobile || '';
        const email = value('pawn_receipt_email') || fallback.email || '';
        const website = value('pawn_receipt_website') || fallback.website || '';
        const gstin = value('pawn_receipt_gstin') || fallback.gstin || '';

        document.getElementById('rpName').textContent = name;
        document.getElementById('rpTag').textContent = value('pawn_receipt_tagline');
        document.getElementById('rpAddress').textContent = checked('pawn_receipt_show_address') ? address : '';

        const contact = [];
        if (checked('pawn_receipt_show_mobile') && mobile) contact.push(mobile);
        if (checked('pawn_receipt_show_email') && email) contact.push(email);
        if (checked('pawn_receipt_show_website') && website) contact.push(website);
        document.getElementById('rpContact').textContent = contact.join(' | ');
        document.getElementById('rpGstin').textContent = checked('pawn_receipt_show_gstin') && gstin ? 'GSTIN: ' + gstin : '';
        document.getElementById('rpTitle').textContent = value('pawn_receipt_title') || 'PAWN LOAN RECEIPT';
        document.getElementById('rpCopy').textContent = value('pawn_receipt_copy_label') || 'ORIGINAL FOR CUSTOMER';
        document.getElementById('rpLogo').style.visibility = checked('pawn_receipt_show_logo') ? 'visible' : 'hidden';
    }

    document.querySelectorAll('.receipt-live').forEach(el => el.addEventListener('input', receiptPreview));
    document.querySelectorAll('.receipt-options input[type="checkbox"]').forEach(el => el.addEventListener('change', receiptPreview));

    document.getElementById('resetReceiptBtn').addEventListener('click', function(){
        if (!confirm('Reset unsaved changes on this form?')) return;
        location.reload();
    });

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('saveReceiptBtn');
        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

        try {
            const response = await fetch('api/pawn-settings-receipt.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });

            let data = {};
            try { data = await response.json(); }
            catch (err) { throw new Error('Invalid response received from server.'); }

            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to save receipt settings.');
            toast(true, data.message);
            setTimeout(function(){ location.href = 'pawn-settings-receipt.php?saved=1'; }, 450);
        } catch (err) {
            toast(false, err.message || 'Unable to save receipt settings.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = old;
        }
    });

    receiptPreview();
})();
</script>
</body>
</html>
