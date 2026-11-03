<?php
$role = $_SESSION['role'] ?? 'user';
?>
<aside class="app-sidebar" id="sidebar-navigasi">
  <div class="sidebar-drawer-heading">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="logo-ikon" aria-hidden="true" style="font-size:0.82rem; font-weight:800; background:var(--mint); color:var(--hijau); width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;">CL</span>
      <strong style="color:var(--gelap); font-size:1.05rem;">Calorie <em>Lab</em></strong>
    </div>
    <label class="sidebar-close" for="sidebar-toggle" aria-label="Tutup navigasi">&times;</label>
  </div>
  <nav aria-label="Navigasi utama">
    <?php if ($role === 'user'): ?>
      <div style="margin: 8px 12px 8px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">
        Menu Utama
      </div>
      <a class="sidebar-link <?= ($halaman_aktif === 'dashboard.php') ? 'aktif' : '' ?>" href="<?= base_url('dashboard.php') ?>">
        <span>Dashboard</span>
      </a>
      <a class="sidebar-link <?= ($halaman_aktif === 'bmi.php') ? 'aktif' : '' ?>" href="<?= base_url('bmi.php') ?>">
        <span>Hitung BMI</span>
      </a>
      <a class="sidebar-link <?= ($halaman_aktif === 'menu.php') ? 'aktif' : '' ?>" href="<?= base_url('menu.php') ?>">
        <span>Menu Rekomendasi</span>
      </a>
      <a class="sidebar-link <?= ($halaman_aktif === 'catatan.php') ? 'aktif' : '' ?>" href="<?= base_url('catatan.php') ?>">
        <span>Catatan Kalori</span>
      </a>
      <a class="sidebar-link <?= ($halaman_aktif === 'rencana.php') ? 'aktif' : '' ?>" href="<?= base_url('rencana.php') ?>">
        <span>Rencana Diet</span>
      </a>
      <a class="sidebar-link <?= ($halaman_aktif === 'profil.php') ? 'aktif' : '' ?>" href="<?= base_url('profil.php') ?>">
        <span>Profil &amp; Perkembangan</span>
      </a>
    <?php elseif ($role === 'pakar'): ?>
      <div style="margin: 8px 12px 8px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">
        Portal Pakar Gizi
      </div>
      <a class="sidebar-link <?= ($is_pakar_area && $halaman_aktif === 'index.php') ? 'aktif' : '' ?>" href="<?= base_url('pakar/index.php') ?>">
        <span>Dashboard Pakar</span>
      </a>
      <a class="sidebar-link <?= ($halaman_aktif === 'menu.php') ? 'aktif' : '' ?>" href="<?= base_url('pakar/menu.php') ?>">
        <span>Menu Gizi Klinis</span>
      </a>
      <a class="sidebar-link <?= ($halaman_aktif === 'saran.php') ? 'aktif' : '' ?>" href="<?= base_url('pakar/saran.php') ?>">
        <span>Edukasi &amp; Saran</span>
      </a>
    <?php elseif ($role === 'admin'): ?>
      <?php if ($is_admin_area): ?>
        <div style="margin: 8px 12px 8px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">
          Panel Administrator
        </div>
        <a class="sidebar-link <?= ($halaman_aktif === 'index.php') ? 'aktif' : '' ?>" href="<?= base_url('admin/index.php') ?>">
          <span>Ringkasan Admin</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'makanan.php') ? 'aktif' : '' ?>" href="<?= base_url('admin/makanan.php') ?>">
          <span>Kelola Makanan</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'pengguna.php') ? 'aktif' : '' ?>" href="<?= base_url('admin/pengguna.php') ?>">
          <span>Pengguna &amp; Role</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'verifikasi_pakar.php') ? 'aktif' : '' ?>" href="<?= base_url('admin/verifikasi_pakar.php') ?>">
          <span>Verifikasi Pakar</span>
        </a>
      <?php elseif ($is_pakar_area): ?>
        <div style="margin: 8px 12px 8px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">
          Portal Pakar Gizi
        </div>
        <a class="sidebar-link <?= ($halaman_aktif === 'index.php') ? 'aktif' : '' ?>" href="<?= base_url('pakar/index.php') ?>">
          <span>Dashboard Pakar</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'menu.php') ? 'aktif' : '' ?>" href="<?= base_url('pakar/menu.php') ?>">
          <span>Menu Gizi Klinis</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'saran.php') ? 'aktif' : '' ?>" href="<?= base_url('pakar/saran.php') ?>">
          <span>Edukasi &amp; Saran</span>
        </a>
      <?php else: ?>
        <div style="margin: 8px 12px 8px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">
          Aplikasi Pengguna
        </div>
        <a class="sidebar-link <?= ($halaman_aktif === 'dashboard.php') ? 'aktif' : '' ?>" href="<?= base_url('dashboard.php') ?>">
          <span>Dashboard</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'bmi.php') ? 'aktif' : '' ?>" href="<?= base_url('bmi.php') ?>">
          <span>Hitung BMI</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'menu.php') ? 'aktif' : '' ?>" href="<?= base_url('menu.php') ?>">
          <span>Menu Rekomendasi</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'catatan.php') ? 'aktif' : '' ?>" href="<?= base_url('catatan.php') ?>">
          <span>Catatan Kalori</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'rencana.php') ? 'aktif' : '' ?>" href="<?= base_url('rencana.php') ?>">
          <span>Rencana Diet</span>
        </a>
        <a class="sidebar-link <?= ($halaman_aktif === 'profil.php') ? 'aktif' : '' ?>" href="<?= base_url('profil.php') ?>">
          <span>Profil &amp; Perkembangan</span>
        </a>
      <?php endif; ?>

      <!-- Switcher Mode untuk Admin di Mobile Drawer -->
      <div style="margin: 14px 12px 6px; padding-top: 10px; border-top: 1px solid var(--garis); font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--abu); font-weight: 700;">
        Beralih Ruang Kerja
      </div>
      <a class="sidebar-link <?= $is_admin_area ? 'aktif' : '' ?>" href="<?= base_url('admin/index.php') ?>" style="font-size:0.85rem;">
        <span>Workspace Admin</span>
      </a>
      <a class="sidebar-link <?= $is_pakar_area ? 'aktif' : '' ?>" href="<?= base_url('pakar/index.php') ?>" style="font-size:0.85rem;">
        <span>Portal Pakar</span>
      </a>
      <a class="sidebar-link <?= $is_user_area ? 'aktif' : '' ?>" href="<?= base_url('dashboard.php') ?>" style="font-size:0.85rem;">
        <span>Aplikasi Pengguna</span>
      </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-note">
    <strong>Calorie Lab</strong>
    <span>Nutrisi terukur berbasis sains &amp; bimbingan ahli.</span>
  </div>
</aside>
