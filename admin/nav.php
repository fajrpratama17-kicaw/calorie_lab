<?php
$admin_halaman = basename($_SERVER['PHP_SELF'] ?? '');
?>
<div class="admin-header-card kartu" style="margin-bottom: 24px; padding: 22px 24px; background: linear-gradient(135deg, #FFFFFF 0%, #F5F3EF 100%); border: 1px solid var(--garis);">
  <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;">
    <div>
      <span class="badge" style="background: var(--mint); color: #264A38; font-weight: 700; margin-bottom: 6px; display: inline-block;">
        Administrator Workspace
      </span>
      <h2 style="margin: 0; font-size: 1.6rem; color: var(--gelap);">Kontrol Panel Pemilik Website</h2>
      <p class="teks-redup" style="margin: 4px 0 0; font-size: 0.92rem;">
        Kelola basis data makanan, pengguna, serta pantau statistik dan kesehatan sistem Calorie Lab.
      </p>
    </div>
    <div>
      <a href="<?= base_url('dashboard.php') ?>" class="tombol sekunder kecil">Ke Aplikasi Pengguna</a>
    </div>
  </div>

  <div style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; border-top: 1px solid var(--garis); padding-top: 16px;">
    <a href="<?= base_url('admin/index.php') ?>" class="kategori-waktu-tab <?= in_array($admin_halaman, ['index.php', 'admin_dashboard.php'], true) ? 'aktif' : '' ?>" style="text-decoration:none;">
      Ringkasan & Statistik
    </a>
    <a href="<?= base_url('admin/makanan.php') ?>" class="kategori-waktu-tab <?= in_array($admin_halaman, ['makanan.php', 'admin_makanan.php'], true) ? 'aktif' : '' ?>" style="text-decoration:none;">
      Basis Data Makanan (CRUD)
    </a>
    <a href="<?= base_url('admin/pengguna.php') ?>" class="kategori-waktu-tab <?= in_array($admin_halaman, ['pengguna.php', 'admin_pengguna.php'], true) ? 'aktif' : '' ?>" style="text-decoration:none;">
      Manajemen Pengguna
    </a>
    <a href="<?= base_url('admin/verifikasi_pakar.php') ?>" class="kategori-waktu-tab <?= $admin_halaman === 'verifikasi_pakar.php' ? 'aktif' : '' ?>" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
      Verifikasi Pakar
      <?php
      // Badge hitung pending
      try {
        global $pdo;
        $n = $pdo->query("SELECT COUNT(*) FROM users WHERE role='pakar' AND status_akun='pending'")->fetchColumn();
        if ($n > 0): ?>
          <span style="background:#B45148;color:#fff;border-radius:999px;font-size:0.68rem;font-weight:700;padding:1px 7px;line-height:1.6;"><?= (int)$n ?></span>
        <?php endif;
      } catch (Exception $e) {}
      ?>
    </a>
  </div>
</div>
