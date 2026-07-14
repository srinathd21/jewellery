<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$pageTitle = 'Daybook';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daybook</title>
<?php include('includes/links.php'); ?>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
<div class="card-panel p-4"><h1 class="h5 mb-2">Daybook</h1><p class="text-muted mb-0">Page content will be added here.</p></div>
<?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
