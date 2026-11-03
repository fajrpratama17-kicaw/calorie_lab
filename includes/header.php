<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
$sudah_login = isset($_SESSION['user_id']);
$halaman_aktif = basename($_SERVER['PHP_SELF'] ?? '');
$is_admin_area = (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false || strpos($halaman_aktif, 'admin_') === 0);
$is_pakar_area = (strpos($_SERVER['PHP_SELF'] ?? '', '/pakar/') !== false || strpos($halaman_aktif, 'pakar_') === 0);
$is_user_area  = (!$is_admin_area && !$is_pakar_area && $sudah_login);

// Identifikasi workspace aktif untuk Role Switcher Admin
$mode_sekarang = 'pengguna';
$mode_label    = 'App Pengguna';
$mode_ikon     = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

if ($is_admin_area) {
    $mode_sekarang = 'admin';
    $mode_label    = 'Workspace Admin';
    $mode_ikon     = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>';
} elseif ($is_pakar_area) {
    $mode_sekarang = 'pakar';
    $mode_label    = 'Portal Pakar';
    $mode_ikon     = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';
}
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
<body class="<?= $sudah_login ? 'halaman-dashboard ' : '' ?><?= $is_admin_area ? 'halaman-admin' : ($is_pakar_area ? 'halaman-pakar' : 'halaman-pengguna') ?>">

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
        <span class="logo-ikon" aria-hidden="true">CL</span>
        <span class="logo-teks">Calorie <em>Lab</em></span>
      </a>
    </div>

    <?php if ($sudah_login): ?>
      <div class="nav-desktop-links" role="navigation" aria-label="Menu Utama">
        <?php if ($is_admin_area): ?>
          <a class="nav-link-item <?= $halaman_aktif === 'index.php' ? 'aktif' : '' ?>" href="<?= base_url('admin/index.php') ?>">Ringkasan</a>
          <a class="nav-link-item <?= $halaman_aktif === 'makanan.php' ? 'aktif' : '' ?>" href="<?= base_url('admin/makanan.php') ?>">Kelola Makanan</a>
          <a class="nav-link-item <?= $halaman_aktif === 'pengguna.php' ? 'aktif' : '' ?>" href="<?= base_url('admin/pengguna.php') ?>">Pengguna &amp; Role</a>
          <?php
          $pending_pakar_count = 0;
          try {
            global $pdo;
            $pending_pakar_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='pakar' AND status_akun='pending'")->fetchColumn();
          } catch (Exception $e) {}
          ?>
          <a class="nav-link-item <?= $halaman_aktif === 'verifikasi_pakar.php' ? 'aktif' : '' ?>" href="<?= base_url('admin/verifikasi_pakar.php') ?>" style="display:inline-flex;align-items:center;gap:6px;">
            Verifikasi Pakar
            <?php if ($pending_pakar_count > 0): ?>
              <span style="background:#B45148;color:#fff;border-radius:999px;font-size:0.68rem;font-weight:700;padding:1px 7px;line-height:1.6;"><?= $pending_pakar_count ?></span>
            <?php endif; ?>
          </a>
        <?php elseif ($is_pakar_area): ?>
          <a class="nav-link-item <?= $halaman_aktif === 'index.php' ? 'aktif' : '' ?>" href="<?= base_url('pakar/index.php') ?>">Portal Pakar</a>
          <a class="nav-link-item <?= $halaman_aktif === 'menu.php' ? 'aktif' : '' ?>" href="<?= base_url('pakar/menu.php') ?>">Menu Gizi Klinis</a>
          <a class="nav-link-item <?= $halaman_aktif === 'saran.php' ? 'aktif' : '' ?>" href="<?= base_url('pakar/saran.php') ?>">Edukasi &amp; Saran</a>
        <?php else: ?>
          <a class="nav-link-item <?= ($halaman_aktif === 'dashboard.php') ? 'aktif' : '' ?>" href="<?= base_url('dashboard.php') ?>">Dashboard</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'bmi.php') ? 'aktif' : '' ?>" href="<?= base_url('bmi.php') ?>">Hitung BMI</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'menu.php') ? 'aktif' : '' ?>" href="<?= base_url('menu.php') ?>">Menu Rekomendasi</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'catatan.php') ? 'aktif' : '' ?>" href="<?= base_url('catatan.php') ?>">Catatan Kalori</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'rencana.php') ? 'aktif' : '' ?>" href="<?= base_url('rencana.php') ?>">Rencana Diet</a>
          <a class="nav-link-item <?= ($halaman_aktif === 'profil.php') ? 'aktif' : '' ?>" href="<?= base_url('profil.php') ?>">Profil &amp; Progres</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="nav-menu">
      <?php if ($sudah_login): ?>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <!-- Workspace Role Switcher (Admin Only) -->
          <div class="workspace-switcher-wrapper" id="workspaceSwitcher">
            <button type="button" class="workspace-switcher-btn" id="workspaceBtn" aria-expanded="false" aria-haspopup="true">
              <span class="workspace-switcher-ikon"><?= $mode_ikon ?></span>
              <span class="workspace-switcher-label"><?= $mode_label ?></span>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="workspace-chevron"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="workspace-switcher-dropdown" id="workspaceDropdown">
              <div class="workspace-dropdown-header">
                Beralih Ruang Kerja
              </div>
              <a href="<?= base_url('admin/index.php') ?>" class="workspace-dropdown-item <?= $mode_sekarang === 'admin' ? 'aktif' : '' ?>">
                <div class="workspace-item-icon">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <div class="workspace-item-text">
                  <strong>Workspace Admin</strong>
                  <span>Kontrol sistem, pengguna &amp; database</span>
                </div>
                <?php if ($mode_sekarang === 'admin'): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="workspace-check"><polyline points="20 6 9 17 4 12"/></svg>
                <?php endif; ?>
              </a>
              <a href="<?= base_url('pakar/index.php') ?>" class="workspace-dropdown-item <?= $mode_sekarang === 'pakar' ? 'aktif' : '' ?>">
                <div class="workspace-item-icon">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </div>
                <div class="workspace-item-text">
                  <strong>Portal Pakar Gizi</strong>
                  <span>Menu gizi klinis &amp; edukasi saran</span>
                </div>
                <?php if ($mode_sekarang === 'pakar'): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="workspace-check"><polyline points="20 6 9 17 4 12"/></svg>
                <?php endif; ?>
              </a>
              <a href="<?= base_url('dashboard.php') ?>" class="workspace-dropdown-item <?= $mode_sekarang === 'pengguna' ? 'aktif' : '' ?>">
                <div class="workspace-item-icon">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div class="workspace-item-text">
                  <strong>Aplikasi Pengguna</strong>
                  <span>Simulasi diet, BMI &amp; catatan harian</span>
                </div>
                <?php if ($mode_sekarang === 'pengguna'): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="workspace-check"><polyline points="20 6 9 17 4 12"/></svg>
                <?php endif; ?>
              </a>
            </div>
          </div>
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

<?php if ($sudah_login && (($_SESSION['role'] ?? '') === 'admin')): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var wsBtn = document.getElementById('workspaceBtn');
  var wsWrap = document.getElementById('workspaceSwitcher');
  if (wsBtn && wsWrap) {
    wsBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      var buka = wsWrap.classList.toggle('buka');
      wsBtn.setAttribute('aria-expanded', buka ? 'true' : 'false');
    });
    document.addEventListener('click', function(e) {
      if (!wsWrap.contains(e.target)) {
        wsWrap.classList.remove('buka');
        wsBtn.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && wsWrap.classList.contains('buka')) {
        wsWrap.classList.remove('buka');
        wsBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }
});
</script>
<?php endif; ?>

<?php if ($sudah_login): ?>
  <label class="sidebar-backdrop" for="sidebar-toggle" aria-label="Tutup navigasi"></label>
  <div class="app-layout container">
    <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="halaman app-content">
<?php else: ?>
  <main class="container halaman">
<?php endif; ?>
