<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
if($businessId<=0||$branchId<=0) die('A valid business and branch must be selected.');
if(empty($_SESSION['pawn_customer_csrf'])) $_SESSION['pawn_customer_csrf']=bin2hex(random_bytes(32));
$csrfToken=(string)$_SESSION['pawn_customer_csrf'];
$theme=['primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5','page_background'=>'#f4f3f0','card_background'=>'#fff','text_color'=>'#171717','muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter','heading_font_family'=>'Playfair Display','border_radius_px'=>12,'sidebar_width_px'=>230,'sidebar_gradient_1'=>'#171c21','sidebar_gradient_2'=>'#20272d','sidebar_gradient_3'=>'#101419'];
$stmt=$conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if($stmt){$stmt->bind_param('i',$businessId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();foreach($theme as $k=>$v)if(isset($r[$k])&&$r[$k]!=='')$theme[$k]=$r[$k];}
$businessName=(string)($_SESSION['business_name']??'Jewellery ERP');
?>