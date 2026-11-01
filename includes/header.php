<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
$sudah_login = isset($_SESSION['user_id']);
$halaman_aktif = basename($_SERVER['PHP_SELF'] ?? '');
$is_admin_area = (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false || strpos($halaman_aktif, 'admin_') === 0);
$is_pakar_area = (strpos($_SERVER['PHP_SELF'] ?? '', '/pakar/') !== false || strpos($halaman_aktif, 'pakar_') === 0);

$tautan_navigasi = [
    'dashboard.php' => ['Dashboard', '01'],
    'bmi.php'       => ['Hitung BMI', '02'],
    'menu.php'      => ['Menu rekomendasi', '03'],
    'catatan.php'   => ['Catatan kalori', '04'],
    'rencana.php'   => ['Rencana diet', '05'],
    'profil.php'    => ['Profil & perkembangan', '06'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($judul ?? 'Calorie Lab') ?> - Calorie Lab</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= base_url('assets/css/styling.css') ?>">
  <style>
    /* ── Sidebar collapsible section ── */
    .sidebar-section { margin-bottom: 4px; }

    .sidebar-section-toggle {
      display: flex; align-items: center; justify-content: space-between;
      width: 100%; padding: 6px 14px 8px;
      background: none; border: none; cursor: pointer;
      font-size: 0.72rem; font-weight: 800; letter-spacing: 0.09em; text-transform: uppercase;
      color: var(--teks-redup);
      font-family: var(--font-utama);
      border-radius: 8px;
      transition: color 0.18s, background 0.18s;
      user-select: none;
    }
    .sidebar-section-toggle:hover { color: var(--hijau); background: var(--mint); }

    .toggle-chevron {
      transition: transform 0.25s cubic-bezier(0.16,1,0.3,1);
      flex-shrink: 0;
    }
    .sidebar-section-toggle[aria-expanded="false"] .toggle-chevron {
      transform: rotate(-180deg);
    }

    .sidebar-section-list {
      overflow: hidden;
      display: grid;
      grid-template-rows: 1fr;
      transition: grid-template-rows 0.28s cubic-bezier(0.16,1,0.3,1);
      gap: 2px;
    }
    .sidebar-section-list.ditutup {
      grid-template-rows: 0fr;
    }
    .sidebar-section-list > * { min-height: 0; overflow: hidden; }
  </style>
</head>
<body>

<?php if ($sudah_login): ?>
  <input class="sidebar-toggle" type="checkbox" id="sidebar-toggle" aria-label="Buka atau tutup navigasi" aria-controls="sidebar-navigasi">
<?php endif; ?>

<nav class="navbar">
  <div class="container nav-isi <?= $sudah_login ? 'nav-isi-aplikasi' : '' ?>">
    <div class="nav-brand">
      <?php if ($sudah_login): ?>
        <label class="sidebar-toggle-label sidebar-toggle-desktop" for="sidebar-toggle" aria-label="Buka navigasi"><span aria-hidden="true">☰</span></label>
      <?php endif; ?>
      <a class="logo" href="<?= base_url('index.php') ?>">
        <span class="logo-ikon" aria-hidden="true" style="font-size:0.82rem; font-weight:800; background:var(--mint); color:var(--hijau); width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;">CL</span>
        <span class="logo-teks">Calorie <em>Lab</em></span>
      </a>
    </div>
    <div class="nav-menu">
      <?php if ($sudah_login): ?>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <a class="nav-link-subtle" style="font-weight:700; color:var(--hijau);" href="<?= base_url('admin/index.php') ?>">Panel Admin</a>
        <?php elseif (($_SESSION['role'] ?? '') === 'pakar'): ?>
          <a class="nav-link-subtle" style="font-weight:700; color:var(--hijau);" href="<?= base_url('pakar/index.php') ?>">Portal Pakar</a>
        <?php else: ?>
          <a class="nav-link-subtle" href="<?= base_url('dashboard.php') ?>">Dashboard</a>
        <?php endif; ?>
        <a class="tombol kecil tombol-keluar" href="<?= base_url('logout.php') ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Keluar
        </a>
      <?php else: ?>
        <a class="nav-link-subtle" href="<?= base_url('login.php') ?>">Masuk</a>
        <a class="tombol kecil" href="<?= base_url('register.php') ?>">Daftar Akun</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<?php if ($sudah_login): ?>
  <label class="sidebar-backdrop" for="sidebar-toggle" aria-label="Tutup navigasi"></label>
  <div class="app-layout container">
    <label class="sidebar-toggle-label sidebar-toggle-mobile" for="sidebar-toggle"><span aria-hidden="true">☰</span> Navigasi</label>
    <aside class="app-sidebar" id="sidebar-navigasi">
      <div class="sidebar-drawer-heading">
        <label class="sidebar-close" for="sidebar-toggle" aria-label="Tutup navigasi">&times;</label>
      </div>
      <nav aria-label="Navigasi utama">
        <!-- ── MAIN MENU collapsible ── -->
        <div class="sidebar-section">
          <button class="sidebar-section-toggle" id="btn-main-menu"
                  aria-expanded="true" aria-controls="main-menu-list"
                  onclick="toggleSidebarSection(this, 'main-menu-list')">
            <span>Main Menu</span>
            <svg class="toggle-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>
          </button>
          <div class="sidebar-section-list" id="main-menu-list">
            <?php foreach ($tautan_navigasi as $file => [$label_navigasi, $nomor]): ?>
              <a class="sidebar-link <?= ($halaman_aktif === $file && !$is_admin_area && !$is_pakar_area) ? 'aktif' : '' ?>"
                 href="<?= base_url($file) ?>"
                 <?= ($halaman_aktif === $file && !$is_admin_area && !$is_pakar_area) ? 'aria-current="page"' : '' ?>>
                <span><?= h($label_navigasi) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <div style="margin: 16px 12px 8px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">Admin Panel</div>
          <a class="sidebar-link <?= $is_admin_area ? 'aktif' : '' ?>" href="<?= base_url('admin/index.php') ?>">
            <span>Kelola Calorie Lab</span>
          </a>
        <?php elseif (($_SESSION['role'] ?? '') === 'pakar'): ?>
          <div style="margin: 16px 12px 8px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">Portal Pakar</div>
          <a class="sidebar-link <?= $is_pakar_area ? 'aktif' : '' ?>" href="<?= base_url('pakar/index.php') ?>">
            <span>Portal Pakar Gizi</span>
          </a>
        <?php endif; ?>
      </nav>
      <div class="sidebar-note">
        <strong>Calorie Lab</strong>
        <span>Catat, rencanakan, dan pantau progresmu.</span>
      </div>
    </aside>
  <main class="halaman app-content">
<?php else: ?>
  <main class="container halaman">
<?php endif; ?>
