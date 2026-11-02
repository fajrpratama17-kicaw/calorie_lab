<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
$sudah_login = isset($_SESSION['user_id']);
$halaman_aktif = basename($_SERVER['PHP_SELF'] ?? '');
$is_admin_area = (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false || strpos($halaman_aktif, 'admin_') === 0);
$is_pakar_area = (strpos($_SERVER['PHP_SELF'] ?? '', '/pakar/') !== false || strpos($halaman_aktif, 'pakar_') === 0);
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
</head>
<body>

<?php if ($sudah_login): ?>
  <input class="sidebar-toggle" type="checkbox" id="sidebar-toggle" aria-label="Buka atau tutup navigasi" aria-controls="sidebar-navigasi">
<?php endif; ?>

<nav class="navbar">
  <div class="container nav-isi <?= $sudah_login ? 'nav-isi-aplikasi' : '' ?>">
    <div class="nav-brand">
      <?php if ($sudah_login): ?>
        <label class="sidebar-toggle-label sidebar-toggle-nav" for="sidebar-toggle" aria-label="Buka navigasi"><span aria-hidden="true">☰</span></label>
      <?php endif; ?>
      <a class="logo" href="<?= base_url('index.php') ?>">
        <span class="logo-ikon" aria-hidden="true" style="font-size:0.82rem; font-weight:800; background:var(--mint); color:var(--hijau); width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;">CL</span>
        <span class="logo-teks">Calorie <em>Lab</em></span>
      </a>
    </div>

    <?php if ($sudah_login): ?>
      <div class="nav-desktop-links" role="navigation" aria-label="Menu Utama">
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <a class="nav-link-item <?= ($is_admin_area && $halaman_aktif === 'index.php') ? 'aktif' : '' ?>" href="<?= base_url('admin/index.php') ?>">Ringkasan</a>
          <a class="nav-link-item <?= $halaman_aktif === 'makanan.php' ? 'aktif' : '' ?>" href="<?= base_url('admin/makanan.php') ?>">Kelola Makanan</a>
          <a class="nav-link-item <?= $halaman_aktif === 'users.php' ? 'aktif' : '' ?>" href="<?= base_url('admin/users.php') ?>">Pengguna</a>
          <a class="nav-link-item <?= $halaman_aktif === 'saran.php' ? 'aktif' : '' ?>" href="<?= base_url('admin/saran.php') ?>">Saran Pakar</a>
        <?php elseif (($_SESSION['role'] ?? '') === 'pakar'): ?>
          <a class="nav-link-item <?= ($is_pakar_area && $halaman_aktif === 'index.php') ? 'aktif' : '' ?>" href="<?= base_url('pakar/index.php') ?>">Portal Pakar</a>
          <a class="nav-link-item <?= $halaman_aktif === 'menu.php' ? 'aktif' : '' ?>" href="<?= base_url('pakar/menu.php') ?>">Menu Gizi Klinis</a>
          <a class="nav-link-item <?= $halaman_aktif === 'saran.php' ? 'aktif' : '' ?>" href="<?= base_url('pakar/saran.php') ?>">Edukasi & Saran</a>
        <?php else: ?>
          <a class="nav-link-item <?= ($halaman_aktif === 'dashboard.php' && !$is_admin_area && !$is_pakar_area) ? 'aktif' : '' ?>" href="<?= base_url('dashboard.php') ?>">Dashboard</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'bmi.php') ? 'aktif' : '' ?>" href="<?= base_url('bmi.php') ?>">Hitung BMI</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'menu.php' && !$is_pakar_area) ? 'aktif' : '' ?>" href="<?= base_url('menu.php') ?>">Menu Rekomendasi</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'catatan.php') ? 'aktif' : '' ?>" href="<?= base_url('catatan.php') ?>">Catatan Kalori</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'rencana.php') ? 'aktif' : '' ?>" href="<?= base_url('rencana.php') ?>">Rencana Diet</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'profil.php') ? 'aktif' : '' ?>" href="<?= base_url('profil.php') ?>">Profil & Progres</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="nav-menu">
      <?php if ($sudah_login): ?>
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
    <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="halaman app-content">
<?php else: ?>
  <main class="container halaman">
<?php endif; ?>
