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
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= base_url('assets/css/styling.css') ?>">
  <style>
    /* ── Latar Belakang Healthy Diet Green Theme ── */
    body.landing-page {
      margin: 0;
      font-family: var(--font-utama);
      color: var(--teks);
      background-color: #FAF8F5;
      background-image: url('<?= base_url('assets/img/bg-diet-green.jpg') ?>');
      background-repeat: no-repeat;
      background-position: center top;
      background-size: cover;
      background-attachment: fixed;
      min-height: 100vh;
    }

    /* ── Navbar Deep Forest Green ── */
    .landing-navbar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: rgba(30, 64, 50, 0.96);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.12);
      box-shadow: 0 4px 20px rgba(16, 38, 28, 0.2);
      transition: all 0.2s ease;
    }

    /* ── Hero Container ── */
    .landing-hero {
      padding: clamp(64px, 8vw, 96px) 0 clamp(48px, 6vw, 72px);
      position: relative;
    }

    .hero-layout {
      display: grid;
      grid-template-columns: 1.18fr 0.82fr;
      align-items: center;
      gap: clamp(32px, 5vw, 64px);
      margin-bottom: clamp(36px, 5vw, 56px);
    }

    .hero-konten {
      position: relative;
      z-index: 2;
    }

    .hero-badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 18px;
      background: rgba(58, 105, 83, 0.10);
      border: 1px solid rgba(58, 105, 83, 0.18);
      border-radius: 999px;
      color: var(--hijau);
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      margin-bottom: 22px;
      backdrop-filter: blur(6px);
    }
    .hero-badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--hijau);
      box-shadow: 0 0 0 3px rgba(58, 105, 83, 0.20);
    }

    .landing-hero h1 {
      font-size: clamp(2.3rem, 4.2vw, 3.5rem);
      font-weight: 800;
      line-height: 1.15;
      color: var(--gelap);
      letter-spacing: -0.03em;
      margin: 0 0 20px;
    }
    .landing-hero h1 .teks-aksen {
      color: var(--hijau);
      display: inline-block;
      position: relative;
    }

    .landing-hero p.hero-deskripsi {
      font-size: clamp(1.02rem, 1.5vw, 1.15rem);
      color: var(--teks);
      opacity: 0.88;
      line-height: 1.68;
      max-width: 620px;
      margin: 0 0 32px;
    }

    /* ── Hero Visual Border Lingkaran ── */
    .hero-visual {
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
    }

    .hero-circle-frame {
      position: relative;
      width: clamp(280px, 32vw, 450px);
      height: clamp(280px, 32vw, 450px);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* Lingkaran border dekoratif luar (soft mint organic glow) */
    .hero-circle-aura {
      position: absolute;
      inset: -16px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(206, 235, 219, 0.75) 0%, rgba(220, 242, 230, 0.35) 65%, transparent 100%);
      border: 14px solid rgba(212, 238, 224, 0.7);
      pointer-events: none;
      z-index: 1;
    }

    /* Gambar utama dengan border lingkaran & bayangan halus */
    .hero-circle-image {
      position: relative;
      z-index: 2;
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: cover;
      object-position: 85% center;
      border: 6px solid #ffffff;
      box-shadow: 
        0 24px 50px rgba(34, 56, 45, 0.16),
        0 0 0 12px rgba(212, 238, 224, 0.65);
      display: block;
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .hero-circle-image:hover {
      transform: scale(1.02);
    }

    @media (max-width: 960px) {
      .hero-layout {
        grid-template-columns: 1fr;
        gap: 36px;
        text-align: center;
      }
      .hero-badge-pill {
        margin-left: auto;
        margin-right: auto;
      }
      .landing-hero p.hero-deskripsi {
        margin-left: auto;
        margin-right: auto;
      }
      .hero-btn-group {
        justify-content: center;
      }
      .hero-trust-bar {
        justify-content: center;
      }
      .hero-circle-frame {
        width: clamp(260px, 55vw, 360px);
        height: clamp(260px, 55vw, 360px);
      }
    }

    /* Tombol Hero */
    .hero-btn-group {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 40px;
    }
    .btn-hero-utama {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 14px 30px;
      background: var(--hijau);
      color: #ffffff !important;
      font-size: 0.96rem;
      font-weight: 700;
      text-decoration: none;
      border-radius: 14px;
      box-shadow: 0 8px 24px rgba(58, 105, 83, 0.25);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .btn-hero-utama:hover {
      background: var(--hijau-hover);
      transform: translateY(-2px);
      box-shadow: 0 12px 30px rgba(58, 105, 83, 0.32);
    }
    .btn-hero-sekunder {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 14px 26px;
      background: rgba(255, 255, 255, 0.85);
      color: var(--gelap) !important;
      border: 1.5px solid var(--garis);
      font-size: 0.94rem;
      font-weight: 650;
      text-decoration: none;
      border-radius: 14px;
      backdrop-filter: blur(6px);
      transition: all 0.2s ease;
    }
    .btn-hero-sekunder:hover {
      background: #ffffff;
      border-color: var(--hijau);
      color: var(--hijau) !important;
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(34, 56, 45, 0.06);
    }

    /* Trust Points Bar */
    .hero-trust-bar {
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      align-items: center;
      padding-top: 24px;
      border-top: 1px solid rgba(232, 227, 218, 0.85);
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--teks-redup);
    }
    .trust-item {
      display: inline-flex;
      align-items: center;
      gap: 7px;
    }
    .trust-item svg {
      color: var(--hijau);
      flex-shrink: 0;
    }

    /* ── Section Fitur Utama ── */
    .section-fitur {
      padding: 72px 0 88px;
    }
    .fitur-header {
      text-align: center;
      max-width: 660px;
      margin: 0 auto 52px;
    }
    .fitur-tag {
      color: var(--hijau);
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      display: inline-block;
      margin-bottom: 8px;
    }
    .fitur-header h2 {
      font-size: clamp(1.8rem, 3.2vw, 2.4rem);
      font-weight: 800;
      color: var(--gelap);
      letter-spacing: -0.02em;
      margin: 0 0 12px;
    }
    .fitur-header p {
      color: var(--teks-redup);
      font-size: 1rem;
      line-height: 1.6;
      margin: 0;
    }

    /* Grid Kartu Fitur Modern */
    .grid-fitur-modern {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 22px;
    }

    .kartu-fitur-modern {
      background: #ffffff;
      border: 1px solid rgba(232, 227, 218, 0.95);
      border-radius: 24px;
      padding: 32px 26px;
      text-decoration: none;
      color: inherit;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 8px 24px rgba(34, 56, 45, 0.04);
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
    }
    .kartu-fitur-modern:hover {
      background: #ffffff;
      transform: translateY(-5px);
      border-color: rgba(58, 105, 83, 0.35);
      box-shadow: 0 18px 40px rgba(34, 56, 45, 0.08);
    }

    .fitur-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 16px;
      background: var(--mint);
      color: var(--hijau);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      transition: transform 0.2s ease, background 0.2s ease;
    }
    .kartu-fitur-modern:hover .fitur-icon-box {
      background: var(--hijau);
      color: #ffffff;
      transform: scale(1.06);
    }

    .kartu-fitur-modern h3 {
      font-size: 1.16rem;
      font-weight: 800;
      color: var(--gelap);
      margin: 0 0 10px;
      letter-spacing: -0.01em;
    }
    .kartu-fitur-modern p {
      font-size: 0.9rem;
      color: var(--teks-redup);
      line-height: 1.6;
      margin: 0 0 20px;
      flex-grow: 1;
    }
    .fitur-tautan-link {
      font-size: 0.84rem;
      font-weight: 700;
      color: var(--hijau);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: gap 0.2s ease;
    }
    .kartu-fitur-modern:hover .fitur-tautan-link {
      gap: 10px;
    }

    /* ── Section Cara Kerja ── */
    .section-alur {
      padding: clamp(72px, 8vw, 100px) 0 clamp(80px, 9vw, 110px);
      background-color: #FAF8F5;
      background-image: url('<?= base_url('assets/img/bg-diet-food.jpg') ?>');
      background-repeat: no-repeat;
      background-position: center center;
      background-size: cover;
      border-top: 1px solid rgba(232, 227, 218, 0.85);
      border-bottom: 1px solid rgba(232, 227, 218, 0.85);
      position: relative;
    }
    .alur-grid-modern {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 24px;
    }
    .kartu-alur {
      background: #ffffff;
      border: 1px solid rgba(232, 227, 218, 0.95);
      border-radius: 22px;
      padding: 34px 28px;
      position: relative;
      box-shadow: 0 10px 28px rgba(34, 56, 45, 0.06), 0 2px 8px rgba(34, 56, 45, 0.02);
      transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .kartu-alur:hover {
      transform: translateY(-4px);
      box-shadow: 0 18px 38px rgba(34, 56, 45, 0.10);
    }
    .alur-nomor-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: #ffffff;
      border: 1.5px solid var(--garis);
      color: var(--hijau);
      font-size: 1.15rem;
      font-weight: 800;
      margin-bottom: 16px;
      box-shadow: 0 4px 12px rgba(34, 56, 45, 0.04);
    }
    .kartu-alur h4 {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--gelap);
      margin: 0 0 10px;
    }
    .kartu-alur p {
      color: var(--teks-redup);
      font-size: 0.92rem;
      line-height: 1.65;
      margin: 0;
    }

    /* ── Footer ── */
    .landing-footer {
      padding: 36px 20px;
      text-align: center;
      font-size: 0.85rem;
      color: var(--teks-redup);
      background: #ffffff;
      border-top: 1px solid rgba(232, 227, 218, 0.75);
    }

    @media (max-width: 768px) {
      .landing-hero { padding: 48px 0 40px; }
      .hero-trust-bar { gap: 14px; }
      .grid-fitur-modern, .alur-grid-modern { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body class="landing-page">

<!-- ═════════════════════════════════════════════════════════════ NAVBAR -->
<nav class="navbar landing-navbar">
  <div class="container nav-isi">
    <div class="nav-brand">
      <a class="logo" href="<?= base_url('index.php') ?>">
        <span class="logo-ikon" aria-hidden="true" style="font-size:0.85rem; font-weight:800; background:rgba(255,255,255,0.18); color:#fff; border:1px solid rgba(255,255,255,0.25); width:32px; height:32px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 3px 8px rgba(0,0,0,0.15);">CL</span>
        <span class="logo-teks" style="color:#fff;">Calorie <em style="color:#9AE6B4;">Lab</em></span>
      </a>
    </div>
    <div class="nav-menu">
      <?php if ($sudah_login): ?>
        <a class="nav-link-subtle" style="font-weight:700; color:#fff;" href="<?= htmlspecialchars($url_dashboard) ?>">
          <?= $role === 'admin' ? 'Panel Admin' : ($role === 'pakar' ? 'Portal Pakar' : 'Buka Dashboard') ?>
        </a>
        <a class="tombol kecil tombol-keluar" href="<?= base_url('logout.php') ?>" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Keluar
        </a>
      <?php else: ?>
        <a class="tombol kecil" href="<?= base_url('register.php') ?>" style="font-weight:700; background:#fff; color:#1E4032; border:none; box-shadow:0 4px 14px rgba(0,0,0,0.15);">Daftar Akun</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ═════════════════════════════════════════════════════════════ HERO SECTION -->
<header class="landing-hero">
  <div class="container">
    
    <div class="hero-layout">
      <!-- Kolom Kiri: Teks & Aksi -->
      <div class="hero-konten">
        <!-- Badge Status -->
        <div class="hero-badge-pill">
          <span class="hero-badge-dot"></span>
          Platform Nutrisi &amp; Gaya Hidup Sehat
        </div>

        <!-- Headline Utama -->
        <h1>
          Nutrisi Terukur,<br>
          <span class="teks-aksen">Sesuai Kebutuhan Tubuhmu.</span>
        </h1>

        <!-- Paragraf Penjelas -->
        <p class="hero-deskripsi">
          Hitung indeks massa tubuh (BMI), rancang program diet seimbang, dan pantau kuota kalori harianmu secara presisi. Bebas dari metode diet ekstrem — hanya konsistensi terukur berbasis sains dan bimbingan ahli.
        </p>

        <!-- Tombol Aksi Hero -->
        <div class="hero-btn-group">
          <?php if ($sudah_login): ?>
            <?php if ($role === 'pakar'): ?>
              <a class="btn-hero-utama" href="<?= base_url('pakar/index.php') ?>">
                Buka Portal Pakar &rarr;
              </a>
            <?php elseif ($role === 'admin'): ?>
              <a class="btn-hero-utama" href="<?= base_url('admin/index.php') ?>">
                Buka Panel Admin &rarr;
              </a>
            <?php else: ?>
              <a class="btn-hero-utama" href="<?= base_url('dashboard.php') ?>">
                Buka Dashboard Saya &rarr;
              </a>
              <a class="btn-hero-sekunder" href="<?= base_url('menu.php') ?>">
                Lihat Menu Rekomendasi
              </a>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn-hero-utama" href="<?= base_url('register.php') ?>">
              Mulai Hitung BMI Gratis &rarr;
            </a>
            <a class="btn-hero-sekunder" href="<?= base_url('login.php') ?>">
              Sudah punya akun? Masuk
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Kolom Kanan: Visual Border Lingkaran Gambar -->
      <div class="hero-visual">
        <div class="hero-circle-frame">
          <div class="hero-circle-aura"></div>
          <img src="<?= base_url('assets/img/hero-healthy.jpg') ?>" alt="Gaya Hidup Sehat & Nutrisi Terukur" class="hero-circle-image">
        </div>
      </div>
    </div>

    <!-- Trust Points Bar -->
    <div class="hero-trust-bar">
      <div class="trust-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <span>Formula Klinis Mifflin-St Jeor</span>
      </div>
      <div class="trust-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <span>4 Kategori Standar BMI</span>
      </div>
      <div class="trust-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <span>240+ Basis Data Makanan</span>
      </div>
      <div class="trust-item">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <span>Diverifikasi Pakar Gizi &amp; STR</span>
      </div>
    </div>

  </div>
</header>

<!-- ═════════════════════════════════════════════════════════════ FITUR UTAMA -->
<section class="section-fitur">
  <div class="container">
    
    <div class="fitur-header">
      <span class="fitur-tag">FITUR UNGGULAN</span>
      <h2>Langkah Terarah untuk Pola Makan Sehat</h2>
      <p>Dirancang khusus dengan prinsip nutrisi klinis untuk membantumu mencapai target berat badan secara bertahap, aman, dan berkelanjutan.</p>
    </div>

    <div class="grid-fitur-modern">
      
      <!-- Kartu 1: Kalkulator BMI -->
      <a class="kartu-fitur-modern" href="<?= $sudah_login ? 'bmi.php' : 'register.php' ?>">
        <div>
          <div class="fitur-icon-box">
            <!-- Ikon Scale / Timbangan -->
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m16 12-4-4-4 4"/><path d="M12 16V8"/></svg>
          </div>
          <h3>Kalkulator BMI &amp; BMR</h3>
          <p>Hitung indeks massa tubuh, estimasi basal metabolic rate (BMR), dan total kebutuhan kalori harianmu secara akurat.</p>
        </div>
        <span class="fitur-tautan-link">Buka Kalkulator &rarr;</span>
      </a>

      <!-- Kartu 2: Rencana Diet -->
      <a class="kartu-fitur-modern" href="<?= $sudah_login ? 'rencana.php' : 'register.php' ?>">
        <div>
          <div class="fitur-icon-box">
            <!-- Ikon Target / Goals -->
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
          </div>
          <h3>Rencana Diet Personal</h3>
          <p>Tentukan target realistis (turun, naik, atau pertahankan berat) beserta durasi program yang terstruktur dan aman.</p>
        </div>
        <span class="fitur-tautan-link">Atur Rencana &rarr;</span>
      </a>

      <!-- Kartu 3: Rekomendasi Menu -->
      <a class="kartu-fitur-modern" href="<?= $sudah_login ? 'menu.php' : 'register.php' ?>">
        <div>
          <div class="fitur-icon-box">
            <!-- Ikon Makanan / Nutrisi -->
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
          </div>
          <h3>Rekomendasi Menu Sehat</h3>
          <p>Pilih menu sarapan, makan siang, malam, dan camilan yang pas dengan budget, kalori, dan disusun langsung oleh dokter spesialis gizi.</p>
        </div>
        <span class="fitur-tautan-link">Eksplorasi Menu &rarr;</span>
      </a>

      <!-- Kartu 4: Catatan Kalori -->
      <a class="kartu-fitur-modern" href="<?= $sudah_login ? 'catatan.php' : 'register.php' ?>">
        <div>
          <div class="fitur-icon-box">
            <!-- Ikon Jurnal / Buku Catatan -->
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="13" y2="11"/></svg>
          </div>
          <h3>Catatan Kalori Harian</h3>
          <p>Catat asupan makanan per porsi dengan mudah, dan pantau sisa kuota kalori harianmu secara realtime setiap saat.</p>
        </div>
        <span class="fitur-tautan-link">Mulai Catat &rarr;</span>
      </a>

      <!-- Kartu 5: Grafik Perkembangan -->
      <a class="kartu-fitur-modern" href="<?= $sudah_login ? 'profil.php' : 'register.php' ?>">
        <div>
          <div class="fitur-icon-box">
            <!-- Ikon Grafik Tren Naik -->
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
          </div>
          <h3>Grafik Progres Timbangan</h3>
          <p>Pantau timbangan berkala dan lihat visualisasi garis perkembangan berat badanmu dibandingkan dengan garis ideal rencana.</p>
        </div>
        <span class="fitur-tautan-link">Lihat Perkembangan &rarr;</span>
      </a>

    </div>

  </div>
</section>

<!-- ═════════════════════════════════════════════════════════════ CARA KERJA ── -->
<section class="section-alur">
  <div class="container">
    
    <div class="fitur-header" style="margin-bottom: 42px;">
      <span class="fitur-tag">CARA KERJA</span>
      <h2>3 Langkah Menuju Pola Hidup Sehat</h2>
      <p>Proses sederhana dan terstruktur untuk memulai perjalanan nutrisimu.</p>
    </div>

    <div class="alur-grid-modern">
      
      <div class="kartu-alur">
        <div class="alur-nomor-badge">01</div>
        <h4>Ukur Kondisi Tubuh</h4>
        <p>Masukkan data fisikmu untuk mengetahui skor BMI, BMR, dan kebutuhan kalori harian dasar (TDEE).</p>
      </div>

      <div class="kartu-alur">
        <div class="alur-nomor-badge">02</div>
        <h4>Rancang Program &amp; Menu</h4>
        <p>Pilih tujuan diet yang realistis dan tentukan paket makanan harian yang cocok dengan selera serta budget Anda.</p>
      </div>

      <div class="kartu-alur">
        <div class="alur-nomor-badge">03</div>
        <h4>Catat &amp; Pantau Rutin</h4>
        <p>Catat asupan makanan per porsi dan perbarui timbangan berkala untuk melihat kurva progres nyata.</p>
      </div>

    </div>

  </div>
</section>

<!-- ═════════════════════════════════════════════════════════════ FOOTER ── -->
<footer class="landing-footer">
  <div class="container">
    <p style="margin: 0;">
      &copy; <?= date('Y') ?> Calorie Lab &middot; Platform Nutrisi &amp; Gaya Hidup Sehat &middot; Informasi bersifat edukatif, bukan pengganti diagnosis medis resmi.
    </p>
  </div>
</footer>

</body>
</html>
