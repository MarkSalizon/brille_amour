</div><!-- /page-content -->
</div><!-- /main-content -->
</div><!-- /layout -->
<link rel="stylesheet" href="../assets/css/responsive.css?v=<?= filemtime(__DIR__ . '/../assets/css/responsive.css') ?>">
<link rel="stylesheet" href="../assets/css/design-system.css?v=<?= filemtime(__DIR__ . '/../assets/css/design-system.css') ?>">
<link rel="stylesheet" href="../assets/css/brille-mobile-polish.css?v=<?= filemtime(__DIR__ . '/../assets/css/brille-mobile-polish.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-compat.css') ?>">
<link rel="stylesheet" href="../assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="../assets/css/system-ux-v2.css?v=<?= filemtime(__DIR__ . '/../assets/css/system-ux-v2.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-comfort.css') ?>">
<link rel="stylesheet" href="../assets/css/desktop-layout-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/desktop-layout-fix.css') ?>">
<link rel="stylesheet" href="../assets/css/admin-mobile-pages-fix.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-mobile-pages-fix.css') ?>">
<link rel="stylesheet" href="../assets/css/overflow-guard.css?v=<?= filemtime(__DIR__ . '/../assets/css/overflow-guard.css') ?>">
<?php if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/staff/') !== false): ?>
<link rel="stylesheet" href="../assets/css/staff-layout-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-layout-final.css') ?>">
<?php endif; ?>
<?php if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/staff/') !== false): ?>
<link rel="stylesheet" href="../assets/css/staff-mobile-stable.css?v=<?= filemtime(__DIR__ . '/../assets/css/staff-mobile-stable.css') ?>">
<?php endif; ?>
<?php if (!empty($pageFinalCss)): ?>
<?php $pageFinalCssPath = __DIR__ . '/../assets/css/' . basename((string)$pageFinalCss); ?>
<link rel="stylesheet" href="<?= htmlspecialchars((string)$pageFinalCss) ?>?v=<?= file_exists($pageFinalCssPath) ? filemtime($pageFinalCssPath) : time() ?>">
<?php endif; ?>
<?php if (in_array('admin-inventory-page', preg_split('/\s+/', trim((string)($bodyClass ?? ''))), true)): ?>
<link rel="stylesheet" href="../assets/css/admin-inventory-no-scroll.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-inventory-no-scroll.css') ?>">
<?php endif; ?>
<script src="../assets/js/main.js"></script>
<script src="../assets/js/responsive.js"></script>
<script src="../assets/js/mobile-polish.js?v=<?= filemtime(__DIR__ . '/../assets/js/mobile-polish.js') ?>"></script>
<script src="../assets/js/system-ajax.js?v=<?= filemtime(__DIR__ . '/../assets/js/system-ajax.js') ?>"></script>
<link rel="stylesheet" href="../assets/css/search-suggestions.css?v=<?= filemtime(__DIR__ . '/../assets/css/search-suggestions.css') ?>">
<script src="../assets/js/search-suggestions.js?v=<?= filemtime(__DIR__ . '/../assets/js/search-suggestions.js') ?>" defer></script>
</body>
</html>
