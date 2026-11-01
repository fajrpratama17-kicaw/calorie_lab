<?php
require_once __DIR__ . '/config/koneksi.php';

// Jika sudah login, redirect sesuai role
if (isset($_SESSION['user_id'])) {
    $r = $_SESSION['role'] ?? 'user';
    if ($r === 'admin')     { header('Location: ' . base_url('admin/index.php')); exit; }
    elseif ($r === 'pakar') { header('Location: ' . base_url('pakar/index.php')); exit; }
    else                    { header('Location: ' . base_url('dashboard.php'));   exit; }
}

// ── Pastikan kolom pakar tersedia (auto-migrate ringan) ──────────────────
$kolom_pakar = ['gelar','nomor_str','spesialisasi','institusi','status_akun','catatan_admin'];
foreach ($kolom_pakar as $kol) {
    try {
        $res = $pdo->query("SHOW COLUMNS FROM users LIKE '$kol'");
        if (!$res->fetch()) {
            switch ($kol) {
                case 'gelar':        $pdo->exec("ALTER TABLE users ADD COLUMN gelar VARCHAR(50) NULL AFTER nama"); break;
                case 'nomor_str':    $pdo->exec("ALTER TABLE users ADD COLUMN nomor_str VARCHAR(20) NULL AFTER role"); break;
                case 'spesialisasi': $pdo->exec("ALTER TABLE users ADD COLUMN spesialisasi VARCHAR(100) NULL AFTER nomor_str"); break;
                case 'institusi':    $pdo->exec("ALTER TABLE users ADD COLUMN institusi VARCHAR(150) NULL AFTER spesialisasi"); break;
                case 'status_akun':  $pdo->exec("ALTER TABLE users ADD COLUMN status_akun ENUM('aktif','pending','ditolak') NOT NULL DEFAULT 'aktif' AFTER institusi"); break;
                case 'catatan_admin':$pdo->exec("ALTER TABLE users ADD COLUMN catatan_admin TEXT NULL AFTER status_akun"); break;
            }
        }
    } catch (Exception $e) {}
}

// ── Validasi STR ─────────────────────────────────────────────────────────
function validasi_str($str) {
    // Harus tepat 16 digit angka
    if (!preg_match('/^\d{16}$/', $str)) return false;
    // Tidak boleh semua digit sama (0000..., 1111..., dll)
    if (preg_match('/^(\d)\1{15}$/', $str)) return false;
    // Tidak boleh sequential naik: 0123456789012345 / 1234567890123456
    $seq_naik = '01234567890123456789';
    if (strpos($seq_naik, $str) !== false) return false;
    // Tidak boleh sequential turun: 9876543210987654
    $seq_turun = '98765432109876543210';
    if (strpos($seq_turun, $str) !== false) return false;
    return true;
}

$error  = '';
$sukses = '';
$csrf   = csrf_token('csrf_token_reg_pakar');

// Data form untuk repopulate
$f = ['nama' => '', 'gelar' => '', 'email' => '', 'spesialisasi' => '', 'institusi' => '', 'nomor_str' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!csrf_valid($token, 'csrf_token_reg_pakar')) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $f['nama']        = trim($_POST['nama'] ?? '');
        $f['gelar']       = trim($_POST['gelar'] ?? '');
        $f['email']       = strtolower(trim($_POST['email'] ?? ''));
        $f['spesialisasi']= trim($_POST['spesialisasi'] ?? '');
        $f['institusi']   = trim($_POST['institusi'] ?? '');
        $f['nomor_str']   = preg_replace('/\D/', '', trim($_POST['nomor_str'] ?? ''));
        $pass             = $_POST['password'] ?? '';
        $pass2            = $_POST['password2'] ?? '';

        // Validasi
        if (mb_strlen($f['nama']) < 2 || mb_strlen($f['nama']) > 100) {
            $error = 'Nama lengkap harus antara 2 sampai 100 karakter.';
        } elseif (mb_strlen($f['gelar']) < 2 || mb_strlen($f['gelar']) > 50) {
            $error = 'Gelar/jabatan profesional wajib diisi (maks. 50 karakter).';
        } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email tidak valid.';
        } elseif ($f['spesialisasi'] === '') {
            $error = 'Silakan pilih bidang spesialisasi.';
        } elseif (mb_strlen($f['institusi']) < 3) {
            $error = 'Nama institusi/tempat praktik wajib diisi (minimal 3 karakter).';
        } elseif (!validasi_str($f['nomor_str'])) {
            $error = 'Nomor STR harus tepat 16 digit angka, tidak boleh semua digit sama atau berurutan.';
        } elseif (strlen($pass) < 8 || strlen($pass) > 72) {
            $error = 'Kata sandi minimal 8 karakter.';
        } elseif ($pass !== $pass2) {
            $error = 'Konfirmasi kata sandi tidak cocok.';
        } else {
            // Cek duplikasi email
            $cek = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
            $cek->execute([$f['email']]);
            if ($cek->fetch()) {
                $error = 'Email <strong>' . h($f['email']) . '</strong> sudah terdaftar di sistem.';
            } else {
                // Cek duplikasi STR
                $cek_str = $pdo->prepare("SELECT id FROM users WHERE nomor_str = ?");
                $cek_str->execute([$f['nomor_str']]);
                if ($cek_str->fetch()) {
                    $error = 'Nomor STR tersebut sudah terdaftar di sistem.';
                } else {
                    // Insert — status pending
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $ins  = $pdo->prepare("INSERT INTO users (nama, gelar, email, password_hash, role, nomor_str, spesialisasi, institusi, status_akun) VALUES (?, ?, ?, ?, 'pakar', ?, ?, ?, 'pending')");
                    $ins->execute([$f['nama'], $f['gelar'], $f['email'], $hash, $f['nomor_str'], $f['spesialisasi'], $f['institusi']]);
                    $sukses = 'berhasil';
                    // Reset form
                    $f = ['nama' => '', 'gelar' => '', 'email' => '', 'spesialisasi' => '', 'institusi' => '', 'nomor_str' => ''];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daftar sebagai Pakar &mdash; Calorie Lab</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= base_url('assets/css/styling.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { min-height: 100%; margin: 0; }

    body.login-page {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh;
      background: radial-gradient(circle at 15% 15%, #EAF3EE 0%, #FAF8F5 50%, #EDE8E1 100%);
      font-family: var(--font-utama);
      padding: clamp(16px, 3vw, 36px);
    }

    .login-canvas-card {
      display: flex; width: 100%; max-width: 1160px; min-height: 640px;
      background: #ffffff; border-radius: 28px;
      box-shadow: 0 24px 64px rgba(34,56,45,0.09), 0 4px 16px rgba(34,56,45,0.03);
      overflow: hidden; border: 1px solid rgba(232,227,218,0.85);
    }

    .login-panel-kiri {
      flex: 1.25; display: flex; flex-direction: column;
      justify-content: space-between; padding: clamp(32px,4.5vw,52px);
      position: relative;
      background-image:
        linear-gradient(135deg, rgba(255,255,255,0.94) 0%, rgba(255,255,255,0.82) 42%, rgba(255,255,255,0.28) 85%, rgba(255,255,255,0.15) 100%),
        url('<?= base_url('assets/img/login-bg.jpg') ?>');
      background-size: cover; background-position: right center; background-repeat: no-repeat;
    }

    .login-brand { display: flex; align-items: center; gap: 12px; position: relative; z-index: 2; }
    .login-brand-logo {
      width: 42px; height: 42px; background: var(--hijau); border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.5px;
      box-shadow: 0 4px 12px rgba(58,105,83,0.25);
    }
    .login-brand-nama { font-size: 1.18rem; font-weight: 800; color: var(--gelap); letter-spacing: -0.2px; }

    .login-hero { position: relative; z-index: 2; max-width: 450px; margin: 28px 0; }
    .login-hero-label {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(58,105,83,0.12); color: var(--hijau);
      font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
      padding: 5px 14px; border-radius: 999px; margin-bottom: 14px; backdrop-filter: blur(4px);
    }
    .login-hero h1 {
      font-size: clamp(1.7rem,3vw,2.4rem); font-weight: 800; color: var(--gelap);
      line-height: 1.22; margin: 0 0 14px; letter-spacing: -0.5px;
    }
    .login-hero p { font-size: 0.94rem; color: var(--teks); line-height: 1.65; margin: 0 0 22px; opacity: 0.88; }

    .pakar-fitur-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
    .pakar-fitur-list li {
      display: flex; align-items: center; gap: 10px; font-size: 0.86rem; font-weight: 600; color: var(--gelap);
      background: rgba(255,255,255,0.80); backdrop-filter: blur(10px);
      padding: 9px 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.95);
      box-shadow: 0 4px 14px rgba(34,56,45,0.04); width: fit-content;
    }
    .pakar-fitur-list li span.icon {
      width: 26px; height: 26px; border-radius: 8px; background: var(--mint);
      display: inline-flex; align-items: center; justify-content: center; color: var(--hijau); flex-shrink: 0;
    }

    .login-kiri-footer { position: relative; z-index: 2; font-size: 0.8rem; color: var(--teks-redup); }
    .login-kiri-footer a { color: var(--teks-redup); text-decoration: none; font-weight: 500; }
    .login-kiri-footer a:hover { color: var(--hijau); }

    /* Panel Kanan */
    .login-panel-kanan {
      width: clamp(400px, 46%, 520px); display: flex; align-items: flex-start; justify-content: center;
      padding: clamp(28px,4vw,44px) clamp(24px,4vw,44px); background: #fff;
      border-left: 1px solid rgba(232,227,218,0.65); overflow-y: auto;
    }
    .login-form-wrap { width: 100%; max-width: 420px; padding: 8px 0; }
    .login-form-header { margin-bottom: 20px; }
    .login-form-header h2 { font-size: 1.55rem; font-weight: 800; color: var(--gelap); margin: 0 0 5px; letter-spacing: -0.3px; }
    .login-form-header p { font-size: 0.87rem; color: var(--teks-redup); margin: 0; }

    /* Form fields */
    .login-field { margin-bottom: 14px; }
    .login-field label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--gelap); margin-bottom: 5px; }
    .login-field input[type="email"],
    .login-field input[type="text"],
    .login-field input[type="password"],
    .login-field select {
      width: 100%; padding: 10px 14px; font-size: 0.88rem; font-family: var(--font-utama);
      border: 1.5px solid var(--garis); border-radius: 12px; background: #FAFAF9;
      color: var(--teks); outline: none;
      transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
      appearance: none; -webkit-appearance: none;
    }
    .login-field select {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px;
    }
    .login-field input:focus, .login-field select:focus {
      border-color: var(--hijau); box-shadow: 0 0 0 3px rgba(58,105,83,0.12); background: #fff;
    }

    /* Row dua kolom */
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    /* Password group */
    .login-pass-group { position: relative; }
    .login-pass-group input { padding-right: 80px !important; }
    .login-pass-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; font-size: 0.74rem; font-weight: 700;
      color: var(--teks-redup); cursor: pointer; padding: 4px 6px; border-radius: 6px;
      transition: color 0.15s;
    }
    .login-pass-toggle:hover { color: var(--hijau); }

    /* STR helper */
    .str-hint { font-size: 0.75rem; color: var(--teks-redup); margin-top: 4px; display: block; }

    /* Alert */
    .login-alert {
      padding: 11px 14px; border-radius: 12px; font-size: 0.84rem; font-weight: 500;
      margin-bottom: 16px; line-height: 1.5;
    }
    .login-alert.error  { background: #FEF0EF; color: #B45148; border: 1px solid #FACACB; }
    .login-alert.sukses { background: #E8F2EC; color: #264A38; border: 1px solid #B8D9C5; }

    /* Sukses state besar */
    .sukses-panel {
      text-align: center; padding: 28px 8px;
    }
    .sukses-panel .sukses-icon {
      width: 64px; height: 64px; border-radius: 50%;
      background: var(--mint); display: flex; align-items: center; justify-content: center;
      margin: 0 auto 20px; color: var(--hijau);
    }
    .sukses-panel h3 { font-size: 1.25rem; font-weight: 800; color: var(--gelap); margin: 0 0 10px; }
    .sukses-panel p { font-size: 0.88rem; color: var(--teks-redup); line-height: 1.65; margin: 0 0 22px; }
    .sukses-panel .step-list { text-align: left; background: #F7F9F7; border-radius: 14px; padding: 18px 20px; margin-bottom: 22px; }
    .sukses-panel .step-list p { margin: 0 0 8px; font-size: 0.84rem; font-weight: 600; color: var(--gelap); }
    .sukses-panel .step-list ol { margin: 0; padding-left: 20px; color: var(--teks-redup); font-size: 0.83rem; line-height: 1.7; }

    /* Tombol */
    .login-submit {
      width: 100%; padding: 13px; font-size: 0.91rem; font-weight: 700; font-family: var(--font-utama);
      background: var(--hijau); color: #fff; border: none; border-radius: 12px; cursor: pointer;
      letter-spacing: 0.02em; transition: background 0.18s, transform 0.12s, box-shadow 0.18s;
      box-shadow: 0 4px 14px rgba(58,105,83,0.20); margin-top: 4px;
    }
    .login-submit:hover { background: var(--hijau-hover); box-shadow: 0 6px 20px rgba(58,105,83,0.28); transform: translateY(-1px); }
    .login-submit:active { transform: translateY(0); }

    /* Demo fill button */
    .demo-btn {
      display: inline-flex; align-items: center; gap: 6px;
      background: none; border: 1.5px solid var(--garis); border-radius: 10px;
      font-size: 0.76rem; font-weight: 600; color: var(--teks-redup); font-family: var(--font-utama);
      padding: 6px 14px; cursor: pointer; transition: all 0.18s; margin-bottom: 16px;
    }
    .demo-btn:hover { border-color: var(--hijau); color: var(--hijau); background: var(--mint); }

    .login-divider { text-align: center; font-size: 0.81rem; color: var(--teks-redup); margin: 16px 0 0; }
    .login-divider a { color: var(--hijau); font-weight: 600; text-decoration: none; }
    .login-divider a:hover { text-decoration: underline; }
    .login-back {
      display: block; text-align: center; font-size: 0.78rem; color: var(--teks-redup);
      text-decoration: none; margin-top: 12px;
    }
    .login-back:hover { color: var(--hijau); }

    /* Seksi pemisah */
    .seksi-label {
      font-size: 0.7rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase;
      color: var(--teks-redup); margin: 16px 0 10px;
      display: flex; align-items: center; gap: 8px;
    }
    .seksi-label::before, .seksi-label::after {
      content: ''; flex: 1; height: 1px; background: var(--garis);
    }

    @media (max-width: 960px) {
      .login-canvas-card { flex-direction: column; border-radius: 20px; min-height: auto; }
      .login-panel-kiri { min-height: 240px; padding: 28px 24px; background-position: center; }
      .pakar-fitur-list { display: none; }
      .login-panel-kanan { width: 100%; border-left: none; border-top: 1px solid rgba(232,227,218,0.65); padding: 32px 24px; }
      .field-row { grid-template-columns: 1fr; gap: 0; }
    }
    @media (max-width: 480px) {
      body.login-page { padding: 10px; }
      .login-canvas-card { border-radius: 16px; }
      .login-panel-kiri { padding: 22px 18px; min-height: 180px; }
      .login-hero h1 { font-size: 1.4rem; }
      .login-hero p { display: none; }
      .login-panel-kanan { padding: 24px 18px; }
    }
  </style>
</head>
<body class="login-page">

<div class="login-canvas-card">

  <!-- ═══════════════ PANEL KIRI ═══════════════ -->
  <div class="login-panel-kiri">
    <div class="login-brand">
      <div class="login-brand-logo">CL</div>
      <span class="login-brand-nama">Calorie Lab</span>
    </div>

    <div class="login-hero">
      <div class="login-hero-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
        Untuk Tenaga Medis &amp; Ahli Gizi
      </div>
      <h1>Bergabung sebagai<br>Pakar Gizi Terpercaya</h1>
      <p>
        Daftarkan diri Anda sebagai pakar gizi atau tenaga medis terverifikasi.
        Bantu jutaan pengguna mencapai tujuan kesehatan mereka dengan panduan ahli.
      </p>
      <ul class="pakar-fitur-list">
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
          </span>
          Verifikasi akun melalui nomor STR resmi
        </li>
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </span>
          Diakui dan disetujui langsung oleh Administrator
        </li>
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
          </span>
          Akses portal pakar eksklusif dengan fitur lengkap
        </li>
      </ul>
    </div>

    <div class="login-kiri-footer">
      &copy; <?= date('Y') ?> Calorie Lab &nbsp;&middot;&nbsp;
      <a href="<?= base_url('index.php') ?>">Beranda</a> &nbsp;&middot;&nbsp;
      <a href="<?= base_url('login.php') ?>">Masuk</a>
    </div>
  </div>

  <!-- ═══════════════ PANEL KANAN ═══════════════ -->
  <div class="login-panel-kanan">
    <div class="login-form-wrap">

      <?php if ($sukses === 'berhasil'): ?>
      <!-- ── Halaman Sukses ── -->
      <div class="sukses-panel">
        <div class="sukses-icon">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <h3>Pendaftaran Berhasil Dikirim!</h3>
        <p>Terima kasih telah mendaftar. Akun Anda sedang dalam proses peninjauan oleh Administrator Calorie Lab.</p>

        <div class="step-list">
          <p>Apa yang terjadi selanjutnya?</p>
          <ol>
            <li>Tim admin akan memverifikasi data profil dan nomor STR Anda.</li>
            <li>Anda akan dapat login setelah akun disetujui.</li>
            <li>Jika ada pertanyaan, hubungi admin melalui email resmi.</li>
          </ol>
        </div>

        <a href="<?= base_url('login.php') ?>" class="login-submit" style="display:block;text-align:center;text-decoration:none;line-height:1.4;">
          Kembali ke Halaman Masuk
        </a>
        <a href="<?= base_url('index.php') ?>" class="login-back">&larr; Kembali ke beranda</a>
      </div>

      <?php else: ?>
      <!-- ── Form Pendaftaran ── -->
      <div class="login-form-header">
        <h2>Daftar sebagai Pakar</h2>
        <p>Isi data profesi Anda untuk mendaftar. Pendaftaran memerlukan persetujuan Admin.</p>
      </div>

      <!-- Demo Fill Button -->
      <button type="button" class="demo-btn" onclick="isiDemo()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
        Isi Data Demo
      </button>

      <?php if ($error): ?>
        <div class="login-alert error"><?= $error ?></div>
      <?php endif; ?>

      <form method="post" id="form-pakar" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <!-- Seksi: Data Pribadi -->
        <div class="seksi-label">Data Diri &amp; Profesi</div>

        <div class="field-row">
          <div class="login-field">
            <label for="nama">Nama Lengkap <span style="color:#B45148">*</span></label>
            <input type="text" id="nama" name="nama" value="<?= h($f['nama']) ?>"
                   placeholder="Nama tanpa gelar" required maxlength="100" autocomplete="name">
          </div>
          <div class="login-field">
            <label for="gelar">Gelar / Jabatan <span style="color:#B45148">*</span></label>
            <input type="text" id="gelar" name="gelar" value="<?= h($f['gelar']) ?>"
                   placeholder="dr. / Ns. / Ahli Gizi" required maxlength="50">
          </div>
        </div>

        <div class="login-field">
          <label for="spesialisasi">Bidang Spesialisasi <span style="color:#B45148">*</span></label>
          <select id="spesialisasi" name="spesialisasi" required>
            <option value="" disabled <?= $f['spesialisasi'] === '' ? 'selected' : '' ?>>-- Pilih Spesialisasi --</option>
            <?php
            $opsi_sp = ['Ahli Gizi Klinis','Dietisien','Dokter Gizi','Dokter Umum','Dokter Spesialis Penyakit Dalam','Perawat Gizi','Lainnya'];
            foreach ($opsi_sp as $sp):
            ?>
            <option value="<?= h($sp) ?>" <?= $f['spesialisasi'] === $sp ? 'selected' : '' ?>><?= h($sp) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="login-field">
          <label for="institusi">Institusi / Tempat Praktik <span style="color:#B45148">*</span></label>
          <input type="text" id="institusi" name="institusi" value="<?= h($f['institusi']) ?>"
                 placeholder="Nama rumah sakit, klinik, atau universitas" required maxlength="150">
        </div>

        <!-- Seksi: Verifikasi STR -->
        <div class="seksi-label">Verifikasi Nomor STR</div>

        <div class="login-field">
          <label for="nomor_str">Nomor STR <span style="color:#B45148">*</span></label>
          <input type="text" id="nomor_str" name="nomor_str" value="<?= h($f['nomor_str']) ?>"
                 placeholder="16 digit angka" maxlength="16" inputmode="numeric"
                 pattern="\d{16}" autocomplete="off">
          <span class="str-hint">Surat Tanda Registrasi tenaga kesehatan (16 digit).</span>
        </div>

        <!-- Seksi: Akun -->
        <div class="seksi-label">Data Akun</div>

        <div class="login-field">
          <label for="email">Email <span style="color:#B45148">*</span></label>
          <input type="email" id="email" name="email" value="<?= h($f['email']) ?>"
                 placeholder="nama@email.com" required autocomplete="email">
        </div>

        <div class="login-field">
          <label for="password">Kata Sandi <span style="color:#B45148">*</span></label>
          <div class="login-pass-group">
            <input type="password" id="password" name="password"
                   placeholder="Minimal 8 karakter" autocomplete="new-password">
            <button type="button" class="login-pass-toggle" onclick="togglePass('password', this)">Lihat</button>
          </div>
        </div>

        <div class="login-field">
          <label for="password2">Konfirmasi Kata Sandi <span style="color:#B45148">*</span></label>
          <div class="login-pass-group">
            <input type="password" id="password2" name="password2"
                   placeholder="Ulangi kata sandi" autocomplete="new-password">
            <button type="button" class="login-pass-toggle" onclick="togglePass('password2', this)">Lihat</button>
          </div>
        </div>

        <button type="submit" class="login-submit">Kirim Pendaftaran &rarr;</button>
      </form>

      <div class="login-divider">
        Sudah punya akun? <a href="<?= base_url('login.php') ?>">Masuk di sini</a>
      </div>
      <div class="login-divider" style="margin-top:8px;">
        Pengguna biasa? <a href="<?= base_url('register.php') ?>">Daftar pengguna</a>
      </div>
      <a href="<?= base_url('index.php') ?>" class="login-back">&larr; Kembali ke beranda</a>

      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function togglePass(id, btn) {
  const inp = document.getElementById(id);
  const isPass = inp.type === 'password';
  inp.type = isPass ? 'text' : 'password';
  btn.textContent = isPass ? 'Sembunyikan' : 'Lihat';
}

function isiDemo() {
  const demos = [
    { nama: 'Siti Rahayu', gelar: 'dr.', email: 'dr.siti.rahayu@kliniksehat.id', spesialisasi: 'Dokter Gizi', institusi: 'Klinik Sehat Mandiri Jakarta', nomor_str: '3301020304050607', pass: 'Demo@12345' },
    { nama: 'Budi Santoso', gelar: 'Ns.', email: 'budi.santoso@rs-husada.co.id', spesialisasi: 'Ahli Gizi Klinis', institusi: 'RS Husada Utama Surabaya', nomor_str: '7809061234560011', pass: 'Demo@12345' },
    { nama: 'Dewi Lestari', gelar: 'RD', email: 'dewi.lestari@univ-nutrisi.ac.id', spesialisasi: 'Dietisien', institusi: 'Universitas Indonesia – FK', nomor_str: '1122334455667788', pass: 'Demo@12345' },
  ];
  const d = demos[Math.floor(Math.random() * demos.length)];
  document.getElementById('nama').value = d.nama;
  document.getElementById('gelar').value = d.gelar;
  document.getElementById('email').value = 'demo.' + Date.now().toString().slice(-4) + '@test.id';
  document.getElementById('spesialisasi').value = d.spesialisasi;
  document.getElementById('institusi').value = d.institusi;
  document.getElementById('nomor_str').value = d.nomor_str;
  document.getElementById('password').value = d.pass;
  document.getElementById('password2').value = d.pass;
}

// Hanya izinkan input angka di field STR
document.getElementById('nomor_str').addEventListener('input', function() {
  this.value = this.value.replace(/\D/g, '').slice(0, 16);
});
</script>

</body>
</html>
