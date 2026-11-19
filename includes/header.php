<?php
/** @var string $pageTitle */
$u = current_user();
$unread = $u ? (int)q('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$u['id']])->fetchColumn() : 0;
$current = basename($_SERVER['SCRIPT_NAME']);

$menu = [
    ['dashboard.php', 'speedometer2', 'Dashboard', ['admin', 'noc', 'vendor', 'inventory', 'foc']],
    ['procurement_dashboard.php', 'clipboard-data', 'Dashboard Procurement', ['procurement']],
    ['tickets.php', 'ticket-detailed', 'Tiket', ['admin', 'noc', 'vendor', 'inventory', 'foc', 'procurement']],
    ['ticket_create.php', 'plus-circle', 'Buat Tiket (NOC)', ['admin', 'noc']],
    ['vendors.php', 'truck', 'Vendor', ['admin', 'noc', 'procurement']],
    ['materials.php', 'box-seam', 'Material', ['admin', 'inventory', 'procurement']],
    ['regions.php', 'map', 'Region', ['admin']],
    ['cities.php', 'geo-alt', 'Kota', ['admin']],
    ['users.php', 'people', 'User', ['admin']],
    ['basto.php', 'file-earmark-check', 'BASTO', ['admin', 'noc', 'procurement', 'foc', 'nam']],
    ['rekap.php', 'bar-chart-line', 'Rekap Bulanan', ['admin', 'noc', 'procurement', 'foc', 'nam']],
    ['master_options.php', 'list-check', 'Master Option', ['admin', 'procurement']],
    ['noc_outbox.php', 'broadcast', 'Notifikasi ke NOC', ['admin', 'noc']],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? '') . ' - ' . APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if ($u): ?>
<nav class="navbar navbar-dark bg-dark sticky-top px-3">
    <button class="btn btn-outline-light btn-sm d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar">
        <i class="bi bi-list"></i>
    </button>
    <a class="navbar-brand fw-semibold" href="<?= url($u['role'] === 'procurement' ? 'procurement_dashboard.php' : 'dashboard.php') ?>"><i class="bi bi-tools"></i> <?= e(APP_NAME) ?></a>
    <div class="ms-auto d-flex align-items-center gap-3">
        <a href="<?= url('notifications.php') ?>" class="text-light position-relative">
            <i class="bi bi-bell fs-5"></i>
            <?php if ($unread): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread ?></span>
            <?php endif; ?>
        </a>
        <div class="dropdown">
            <a class="text-light dropdown-toggle text-decoration-none" href="#" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i> <span class="d-none d-sm-inline"><?= e($u['full_name']) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text small text-muted">
                    <?= e(ROLES[$u['role']]) ?><?= $u['vendor_name'] ? ' · ' . e($u['vendor_name']) : '' ?></span></li>
                <li><a class="dropdown-item" href="<?= url('profile.php') ?>"><i class="bi bi-key"></i> Ganti Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<div class="d-flex">
    <div class="offcanvas-lg offcanvas-start bg-white border-end sidebar" tabindex="-1" id="sidebar">
        <div class="offcanvas-header d-lg-none">
            <h5 class="offcanvas-title">Menu</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
        </div>
        <div class="offcanvas-body p-0">
            <ul class="nav flex-column w-100 py-2">
                <?php foreach ($menu as [$href, $icon, $label, $roles]): if (!in_array($u['role'], $roles, true)) continue; ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $current === $href ? 'active' : '' ?>" href="<?= url($href) ?>">
                            <i class="bi bi-<?= $icon ?>"></i> <?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <main class="flex-grow-1 p-3 p-md-4 main-content">
        <?php foreach (take_flashes() as [$type, $msg]): ?>
            <div class="alert alert-<?= e($type) ?> alert-dismissible fade show"><?= e($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endforeach; ?>
<?php else: ?>
<main class="container py-5">
<?php endif; ?>
