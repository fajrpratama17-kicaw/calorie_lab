<?php
$admin_halaman = basename($_SERVER['PHP_SELF'] ?? '');

$meta_halaman = [
  'index.php' => [
    'judul' => 'Ringkasan &amp; Statistik',
    'deskripsi' => 'Pantau statistik pengguna, katalog makanan, serta kesehatan ekosistem Calorie Lab.',
    'ikon' => '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>'
  ],
  'makanan.php' => [
    'judul' => 'Katalog Makanan (CRUD)',
    'deskripsi' => 'Kelola basis data kalori, takaran saji, serta kriteria gizi menu makanan terkurasi.',
    'ikon' => '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>'
  ],
  'pengguna.php' => [
    'judul' => 'Manajemen Pengguna &amp; Role',
    'deskripsi' => 'Kelola hak akses, status akun, serta riwayat interaksi pengguna dan pakar gizi.',
    'ikon' => '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
  ],
  'verifikasi_pakar.php' => [
    'judul' => 'Verifikasi Pakar Gizi',
    'deskripsi' => 'Tinjau keabsahan nomor STR dan aktivasi akun pakar gizi klinis terdaftar.',
    'ikon' => '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>'
  ],
];

$info = $meta_halaman[$admin_halaman] ?? [
  'judul' => 'Kontrol Panel Administrator',
  'deskripsi' => 'Kelola basis data makanan, pengguna, dan pantau kesehatan sistem Calorie Lab.',
  'ikon' => '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'
];
?>

<div class="page-hero-header" style="margin-bottom: 24px;">
  <div class="page-hero-icon">
    <?= $info['ikon'] ?>
  </div>
  <div class="page-hero-teks">
    <h2><?= $info['judul'] ?></h2>
    <p><?= $info['deskripsi'] ?></p>
  </div>
  <div style="margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
    <span class="badge" style="background: var(--mint); color: var(--hijau); font-weight: 700; padding: 6px 12px; font-size: 0.78rem;">
      Administrator
    </span>
  </div>
</div>
