<?php
// includes/layout_header.php
// Usage: include this at the top of every page after setting $pageTitle and $activeNav

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

requireLogin();

$role  = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
$flash = getFlash();

// Build correct dashboard URL based on role
$dashURL = BASE_URL . ($role === 'admin' ? '/admin/dashboard.php' : '/staff/dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($pageTitle ?? 'Brille Amour') ?> — Brille Amour</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/typography-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/typography-clean.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile-compat.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-compat.css') ?>">
<link rel="stylesheet" href="../assets/css/mobile-comfort.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-comfort.css') ?>">
  <link rel="stylesheet" href="../assets/css/mobile-system-final.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile-system-final.css') ?>">
</head>
<body>
<div class="app-wrapper">

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-name">Brille Amour</div>
    <div class="brand-role"><?= $role === 'admin' ? 'Admin Panel' : 'Staff Panel' ?></div>
  </div>

  <ul class="sidebar-nav">
    <li class="nav-group-label">Overview</li>
    <li class="nav-item <?= ($activeNav??'') === 'dashboard' ? 'active' : '' ?>">
      <a href="<?= $dashURL ?>"><span class="nav-icon">⊞</span> Dashboard</a>
    </li>

    <li class="nav-group-label">Operations</li>
    <li class="nav-item <?= ($activeNav??'') === 'orders' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/<?= $role ?>/orders.php">Orders</a>
    </li>
    <li class="nav-item <?= ($activeNav??'') === 'production' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/<?= $role ?>/production.php">Production</a>
    </li>
    <li class="nav-item <?= ($activeNav??'') === 'inventory' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/<?= $role ?>/inventory.php">Inventory</a>
    </li>
    <li class="nav-item <?= ($activeNav??'') === 'customers' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/<?= $role ?>/customers.php">Customers</a>
    </li>

    <?php if ($role === 'admin'): ?>
    <li class="nav-group-label">Catalogue</li>
    <li class="nav-item <?= ($activeNav??'') === 'products' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/admin/products.php">Products</a>
    </li>
    <li class="nav-item <?= ($activeNav??'') === 'categories' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/admin/categories.php">Categories</a>
    </li>

    <li class="nav-group-label">Reports</li>
    <li class="nav-item <?= ($activeNav??'') === 'reports' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/admin/reports.php">Reports</a>
    </li>

    <li class="nav-group-label">Admin</li>
    <li class="nav-item <?= ($activeNav??'') === 'users' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/admin/users.php">Users</a>
    </li>
    <li class="nav-item <?= ($activeNav??'') === 'archives' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/admin/archives.php">Archives</a>
    </li>
    <li class="nav-item <?= ($activeNav??'') === 'activity' ? 'active' : '' ?>">
      <a href="<?= BASE_URL ?>/admin/activity_log.php">Activity Log</a>
    </li>
    <?php endif; ?>
  </ul>

  <div class="sidebar-footer">
    <div class="user-info">
      <strong><?= e(currentUserName()) ?></strong>
      <?= e(ucfirst($role)) ?>
    </div>
    <a href="<?= BASE_URL ?>/logout.php?portal=<?= urlencode($role) ?>" class="logout-btn">⇠ Logout</a>
  </div>
</nav>

<!-- Main -->
<div class="main-content">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:.9rem">
      <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation">Menu</button>
      <span class="topbar-title"><?= e($pageTitle ?? '') ?></span>
    </div>
    <div class="topbar-right">
      <span style="font-size:.82rem;color:var(--mid-gray)"><?= date('M d, Y') ?></span>
    </div>
  </header>
  <div class="page-body">
<?php if ($flash): ?>
<div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>
