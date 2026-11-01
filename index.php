<?php
require_once __DIR__ . '/config/koneksi.php';

$sudah_login = isset($_SESSION['user_id']);
$nama = $_SESSION['nama'] ?? '';
$role = $_SESSION['role'] ?? 'user';

// Tentukan URL dashboard sesuai role
$url_dashboard = match($role) {
    'admin' => base_url('admin/index.php'),
    'pakar' => base_url('pakar/index.php'),
    default => base_url('dashboard.php'),
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calorie Lab — Nutrisi Berbasis Data untuk Tubuhmu</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= base_url('assets/css/styling.css') ?>">
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <div class="container nav-isi">
    <div class="nav-brand">
      <a class="logo" href="<?= base_url('index.php') ?>">
        <span class="logo-ikon" aria-hidden="true" style="font-size:0.85rem; font-weight:800; background:var(--mint); color:var(--hijau); width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;">CL</span>
        <span class="logo-teks">Calorie <em>Lab</em></span>
      </a>
    </div>
    <div class="nav-menu">
      <?php if ($sudah_login): ?>
        <a href="<?= htmlspecialchars($url_dashboard) ?>">Dashboard</a>
        <a class="tombol kecil" href="<?= base_url('logout.php') ?>">Keluar</a>
      <?php else: ?>
        <a class="tombol kecil" href="<?= base_url('register.php') ?>">Daftar Akun</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- Hero -->
<header class="hero">
  <div class="container">
    <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 16px;background:var(--mint);border-radius:var(--radius-pil);color:var(--hijau);font-size:0.84rem;font-weight:700;margin-bottom:18px;">
      Nutrisi Cerdas Berbasis Sains
    </div>
    <h1>Nutrisi terukur,<br><em style="font-style:normal;color:var(--hijau)">sesuai kebutuhan tubuhmu.</em></h1>
    <p>Hitung BMI, rancang program diet seimbang, dan catat kalori harianmu secara presisi. Bebas dari metode diet ekstrem — hanya konsistensi terukur yang berkelanjutan.</p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:28px;">
      <?php if ($sudah_login): ?>
        <?php if ($role === 'pakar'): ?>
          <a class="tombol tombol-besar" href="<?= base_url('pakar/index.php') ?>">Buka Portal Pakar</a>
        <?php elseif ($role === 'admin'): ?>
          <a class="tombol tombol-besar" href="<?= base_url('admin/index.php') ?>">Buka Panel Admin</a>
        <?php else: ?>
          <a class="tombol tombol-besar" href="<?= base_url('dashboard.php') ?>">Buka Dashboard Saya &rarr;</a>
          <a class="tombol tombol-sekunder tombol-besar" href="<?= base_url('menu.php') ?>">Lihat Menu Rekomendasi</a>
        <?php endif; ?>
      <?php else: ?>
        <a class="tombol tombol-besar" href="<?= base_url('register.php') ?>">Mulai Hitung BMI Gratis →</a>
        <a class="tombol tombol-sekunder tombol-besar" href="<?= base_url('login.php') ?>">Sudah punya akun? Masuk</a>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;padding-top:20px;border-top:1px solid var(--garis);font-size:0.84rem;color:var(--teks-redup);">
      <span>Formula Mifflin-St Jeor</span>
      <span style="opacity:0.4;">•</span>
      <span>4 Kategori Klinis BMI</span>
      <span style="opacity:0.4;">•</span>
      <span>240+ Database Menu Terstandar</span>
    </div>
  </div>
</header>

<!-- Fitur -->
<section style="padding:60px 0 76px;">
  <div class="container">
    <div style="text-align:center;max-width:640px;margin:0 auto 44px;">
      <span style="color:var(--hijau);font-size:0.78rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;">FITUR UTAMA</span>
      <h2 style="margin-top:6px;margin-bottom:10px;">Langkah terarah untuk pola makan sehat</h2>
      <p style="color:var(--teks-redup);margin:0;">Dirancang khusus dengan prinsip nutrisi klinis untuk membantumu mencapai target kebugaran secara aman dan terukur.</p>
    </div>
    <div class="fitur">
      <a class="kartu kartu-link" href="<?= $sudah_login ? 'bmi.php' : 'register.php' ?>">
        <div style="width:48px;height:48px;border-radius:var(--radius-pil);background:var(--mint);display:grid;place-items:center;font-size:0.92rem;font-weight:800;color:var(--hijau);margin-bottom:16px;">01</div>
        <h3>Kalkulator BMI & Kalori</h3>
        <p>Hitung indeks massa tubuh dan estimasi kebutuhan kalori harian menggunakan formula Mifflin-St Jeor.</p>
        <span class="kartu-tautan">Buka Kalkulator →</span>
      </a>
      <a class="kartu kartu-link" href="<?= $sudah_login ? 'rencana.php' : 'register.php' ?>">
        <div style="width:48px;height:48px;border-radius:var(--radius-pil);background:var(--mint);display:grid;place-items:center;font-size:0.92rem;font-weight:800;color:var(--hijau);margin-bottom:16px;">02</div>
        <h3>Rencana Diet Personal</h3>
        <p>Tentukan tujuan (turun, naik, atau pertahankan berat) dan durasi program dietmu secara terstruktur.</p>
        <span class="kartu-tautan">Atur Rencana →</span>
      </a>
      <a class="kartu kartu-link" href="<?= $sudah_login ? 'menu.php' : 'register.php' ?>">
        <div style="width:48px;height:48px;border-radius:var(--radius-pil);background:var(--mint);display:grid;place-items:center;font-size:0.92rem;font-weight:800;color:var(--hijau);margin-bottom:16px;">03</div>
        <h3>Rekomendasi Menu Sehat</h3>
        <p>Pilih menu sarapan, makan siang, makan malam, dan camilan yang pas dengan budget serta target kalorimu.</p>
        <span class="kartu-tautan">Lihat Menu →</span>
      </a>
      <a class="kartu kartu-link" href="<?= $sudah_login ? 'catatan.php' : 'register.php' ?>">
        <div style="width:48px;height:48px;border-radius:var(--radius-pil);background:var(--mint);display:grid;place-items:center;font-size:0.92rem;font-weight:800;color:var(--hijau);margin-bottom:16px;">04</div>
        <h3>Catatan Kalori Harian</h3>
        <p>Catat asupan makanan per porsi dan pantau sisa kuota kalori harianmu secara langsung.</p>
        <span class="kartu-tautan">Mulai Catat →</span>
      </a>
      <a class="kartu kartu-link" href="<?= $sudah_login ? 'profil.php' : 'register.php' ?>">
        <div style="width:48px;height:48px;border-radius:var(--radius-pil);background:var(--mint);display:grid;place-items:center;font-size:0.92rem;font-weight:800;color:var(--hijau);margin-bottom:16px;">05</div>
        <h3>Grafik Progres Berat</h3>
        <p>Pantau timbangan berkala dan lihat perbandingan perkembangan berat badanmu menuju target.</p>
        <span class="kartu-tautan">Lihat Grafik →</span>
      </a>

    </div>
  </div>
</section>

<!-- Cara Kerja -->
<section style="padding:56px 0 72px;background:var(--permukaan);border-top:1px solid var(--garis);border-bottom:1px solid var(--garis);">
  <div class="container">
    <div style="text-align:center;max-width:600px;margin:0 auto 40px;">
      <span style="color:var(--hijau);font-size:0.78rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;">CARA KERJA</span>
      <h2 style="margin:6px 0 10px;">3 Langkah Menuju Pola Hidup Sehat</h2>
      <p style="color:var(--teks-redup);margin:0;">Proses sederhana dan terstruktur untuk memulai perjalanan nutrisimu.</p>
    </div>
    <div class="alur-langkah-grid">
      <div style="background:var(--latar);border:1px solid var(--garis);border-radius:var(--radius-kartu);padding:28px 24px;">
        <span style="display:inline-block;font-size:1.7rem;font-weight:800;color:var(--hijau);margin-bottom:10px;">01</span>
        <h4 style="margin:0 0 8px;">Ukur Kondisi Tubuh</h4>
        <p style="color:var(--teks-redup);font-size:0.92rem;margin:0;line-height:1.6;">Masukkan data fisikmu untuk mengetahui skor BMI, BMR, dan kebutuhan kalori harian (TDEE).</p>
      </div>
      <div style="background:var(--latar);border:1px solid var(--garis);border-radius:var(--radius-kartu);padding:28px 24px;">
        <span style="display:inline-block;font-size:1.7rem;font-weight:800;color:var(--hijau);margin-bottom:10px;">02</span>
        <h4 style="margin:0 0 8px;">Rancang Program & Menu</h4>
        <p style="color:var(--teks-redup);font-size:0.92rem;margin:0;line-height:1.6;">Pilih tujuan diet yang realistis dan tentukan paket makanan harian yang cocok dengan seleramu.</p>
      </div>
      <div style="background:var(--latar);border:1px solid var(--garis);border-radius:var(--radius-kartu);padding:28px 24px;">
        <span style="display:inline-block;font-size:1.7rem;font-weight:800;color:var(--hijau);margin-bottom:10px;">03</span>
        <h4 style="margin:0 0 8px;">Catat & Pantau Rutin</h4>
        <p style="color:var(--teks-redup);font-size:0.92rem;margin:0;line-height:1.6;">Catat makanan yang dikonsumsi dan perbarui timbangan berkala untuk melihat kurva progres nyata.</p>
      </div>
    </div>
  </div>
</section>

<footer>
  <p>© <?= date('Y') ?> Calorie Lab · Informasi bersifat edukatif, bukan pengganti saran medis.</p>
</footer>

</body>
</html>
