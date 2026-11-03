<?php
require_once __DIR__ . '/config/koneksi.php';

// Jika pengguna sudah login, arahkan langsung ke dashboard
if (isset($_SESSION['user_id'])) {
    $r = $_SESSION['role'] ?? 'user';
    if ($r === 'admin')      { header('Location: ' . base_url('admin/index.php')); exit; }
    elseif ($r === 'pakar')  { header('Location: ' . base_url('pakar/index.php')); exit; }
    else                     { header('Location: ' . base_url('dashboard.php'));   exit; }
}

$error = '';
$csrf  = csrf_token('csrf_token_register');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $nama  = trim($_POST['nama'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if (!csrf_valid($token, 'csrf_token_register')) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (mb_strlen($nama) < 2 || mb_strlen($nama) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Isi nama lengkap yang valid (2-100 karakter) dan gunakan format email yang benar.';
    } elseif (strlen($pass) < 6 || strlen($pass) > 72) {
        $error = 'Kata sandi minimal 6 karakter dan maksimal 72 karakter.';
    } else {
        $cek = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ?');
        $cek->execute([$email]);

        if ($cek->fetch()) {
            $error = 'Email ini sudah terdaftar. Silakan langsung masuk ke akun Anda.';
        } else {
            $simpan = $pdo->prepare("INSERT INTO users (nama, email, password_hash, role) VALUES (?, ?, ?, 'user')");
            $simpan->execute([$nama, $email, password_hash($pass, PASSWORD_DEFAULT)]);

            // ── Auto-Login Mulus (Seamless Onboarding) ──
            // Pengguna baru langsung masuk tanpa perlu repot mengetik ulang kata sandinya
            $new_user_id = (int)$pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $new_user_id;
            $_SESSION['nama']    = $nama;
            $_SESSION['role']    = 'user';

            // Berikan pesan sambutan hangat di halaman awal
            $_SESSION['pesan_bmi']      = "Selamat datang di Calorie Lab, {$nama}! Akunmu berhasil dibuat. Ayo mulai dengan menghitung BMI pertamamu.";
            $_SESSION['pesan_bmi_tipe'] = 'sukses';

            // Arahkan langsung ke halaman hitung BMI untuk memulai program kesehatannya
            header('Location: ' . base_url('bmi.php'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daftar Akun &mdash; Calorie Lab</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= base_url('assets/css/styling.css') ?>">
  <style>
    /* ── Reset & Canvas Layout ── */
    *, *::before, *::after { box-sizing: border-box; }
    html, body { min-height: 100%; margin: 0; }

    body.register-page {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background-color: #FAF8F5;
      background-image: url('<?= base_url('assets/img/bg-diet-green.jpg') ?>');
      background-repeat: no-repeat;
      background-position: center center;
      background-size: cover;
      background-attachment: fixed;
      font-family: var(--font-utama);
      padding: clamp(16px, 3vw, 36px);
      position: relative;
    }

    /* Floating Canvas Card (Kembar Identik dengan Halaman Login) */
    .register-canvas-card {
      display: flex;
      width: 100%;
      max-width: 1160px;
      min-height: 640px;
      background: #ffffff;
      border-radius: 28px;
      box-shadow: 0 24px 64px rgba(34, 56, 45, 0.09), 0 4px 16px rgba(34, 56, 45, 0.03);
      overflow: hidden;
      border: 1px solid rgba(232, 227, 218, 0.85);
    }

    /* ── Panel Kiri: Latar Belakang Gambar Gaya Hidup Sehat & Branding ── */
    .register-panel-kiri {
      flex: 1.25;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: clamp(32px, 4.5vw, 52px);
      position: relative;
      background-image: 
        linear-gradient(135deg, rgba(255, 255, 255, 0.94) 0%, rgba(255, 255, 255, 0.82) 42%, rgba(255, 255, 255, 0.28) 85%, rgba(255, 255, 255, 0.15) 100%),
        url('<?= base_url('assets/img/login-bg.jpg') ?>');
      background-size: cover;
      background-position: right center;
      background-repeat: no-repeat;
    }

    .register-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      position: relative;
      z-index: 2;
    }

    .register-brand-logo {
      width: 42px;
      height: 42px;
      background: var(--hijau);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.15rem;
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.5px;
      box-shadow: 0 4px 12px rgba(58, 105, 83, 0.25);
    }

    .register-brand-nama {
      font-size: 1.18rem;
      font-weight: 800;
      color: var(--gelap);
      letter-spacing: -0.2px;
    }

    .register-hero {
      position: relative;
      z-index: 2;
      max-width: 450px;
      margin: 28px 0;
    }

    .register-hero-label {
      display: inline-block;
      background: rgba(58, 105, 83, 0.12);
      color: var(--hijau);
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      padding: 5px 14px;
      border-radius: 999px;
      margin-bottom: 14px;
      backdrop-filter: blur(4px);
    }

    .register-hero h1 {
      font-size: clamp(1.8rem, 3.2vw, 2.5rem);
      font-weight: 800;
      color: var(--gelap);
      line-height: 1.22;
      margin: 0 0 14px;
      letter-spacing: -0.5px;
    }

    .register-hero p {
      font-size: 0.94rem;
      color: var(--teks);
      line-height: 1.65;
      margin: 0 0 22px;
      opacity: 0.88;
    }

    .register-fitur-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .register-fitur-list li {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.86rem;
      font-weight: 600;
      color: var(--gelap);
      background: rgba(255, 255, 255, 0.80);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      padding: 9px 15px;
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.95);
      box-shadow: 0 4px 14px rgba(34, 56, 45, 0.04);
      width: fit-content;
    }

    .register-fitur-list li span.icon {
      width: 26px;
      height: 26px;
      border-radius: 8px;
      background: var(--mint);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--hijau);
      flex-shrink: 0;
    }

    .register-kiri-footer {
      position: relative;
      z-index: 2;
      font-size: 0.8rem;
      color: var(--teks-redup);
    }

    .register-kiri-footer a {
      color: var(--teks-redup);
      text-decoration: none;
      font-weight: 500;
    }
    .register-kiri-footer a:hover { color: var(--hijau); }

    /* ── Panel Kanan: Form Pendaftaran ── */
    .register-panel-kanan {
      width: clamp(380px, 42%, 480px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(28px, 4vw, 44px) clamp(24px, 4vw, 44px);
      background: #ffffff;
      border-left: 1px solid rgba(232, 227, 218, 0.65);
    }

    .register-form-wrap {
      width: 100%;
      max-width: 380px;
    }

    .register-form-header {
      margin-bottom: 24px;
    }

    .register-form-header h2 {
      font-size: 1.65rem;
      font-weight: 800;
      color: var(--gelap);
      margin: 0 0 6px;
      letter-spacing: -0.3px;
    }

    .register-form-header p {
      font-size: 0.88rem;
      color: var(--teks-redup);
      margin: 0 0 20px;
    }

    /* Input Fields */
    .register-field { margin-bottom: 16px; }
    .register-field label {
      display: block;
      font-size: 0.83rem;
      font-weight: 600;
      color: var(--gelap);
      margin-bottom: 6px;
    }
    .register-field input[type="email"],
    .register-field input[type="text"],
    .register-field input[type="password"] {
      width: 100%;
      padding: 11px 14px;
      font-size: 0.9rem;
      font-family: var(--font-utama);
      border: 1.5px solid var(--garis);
      border-radius: 12px;
      background: #FAFAF9;
      color: var(--teks);
      outline: none;
      transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
    }
    .register-field input:focus {
      border-color: var(--hijau);
      box-shadow: 0 0 0 3px rgba(58, 105, 83, 0.12);
      background: #fff;
    }

    .register-pass-group {
      position: relative;
    }
    .register-pass-group input {
      padding-right: 70px !important;
    }
    .register-pass-toggle {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--teks-redup);
      cursor: pointer;
      padding: 4px 6px;
      border-radius: 6px;
      transition: color 0.15s;
    }
    .register-pass-toggle:hover { color: var(--hijau); }

    /* Alert */
    .register-alert {
      padding: 11px 14px;
      border-radius: 12px;
      font-size: 0.84rem;
      font-weight: 500;
      margin-bottom: 18px;
      line-height: 1.5;
    }
    .register-alert.error { background: #FEF0EF; color: #B45148; border: 1px solid #FACACB; }

    /* Tombol Submit */
    .register-submit {
      width: 100%;
      padding: 13px;
      font-size: 0.92rem;
      font-weight: 700;
      font-family: var(--font-utama);
      background: var(--hijau);
      color: #fff;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      letter-spacing: 0.02em;
      transition: background 0.18s, transform 0.12s, box-shadow 0.18s;
      box-shadow: 0 4px 14px rgba(58, 105, 83, 0.20);
      margin-top: 6px;
    }
    .register-submit:hover {
      background: var(--hijau-hover);
      box-shadow: 0 6px 20px rgba(58, 105, 83, 0.28);
      transform: translateY(-1px);
    }
    .register-submit:active { transform: translateY(0); }

    /* Divider & Nav Links */
    .register-divider {
      text-align: center;
      font-size: 0.82rem;
      color: var(--teks-redup);
      margin: 18px 0 0;
    }
    .register-divider a {
      color: var(--hijau);
      font-weight: 600;
      text-decoration: none;
    }
    .register-divider a:hover { text-decoration: underline; }

    .register-back {
      display: block;
      text-align: center;
      font-size: 0.78rem;
      color: var(--teks-redup);
      text-decoration: none;
      margin-top: 14px;
    }
    .register-back:hover { color: var(--hijau); }

    /* ── Responsivitas ── */
    @media (max-width: 900px) {
      .register-canvas-card {
        flex-direction: column;
        border-radius: 20px;
        min-height: auto;
      }
      .register-panel-kiri {
        min-height: 280px;
        padding: 32px 24px;
        background-position: center center;
      }
      .register-hero {
        margin: 16px 0;
      }
      .register-fitur-list {
        display: none;
      }
      .register-panel-kanan {
        width: 100%;
        border-left: none;
        border-top: 1px solid rgba(232, 227, 218, 0.65);
        padding: 36px 24px;
      }
    }

    @media (max-width: 480px) {
      body.register-page {
        padding: 10px;
      }
      .register-canvas-card {
        border-radius: 16px;
      }
      .register-panel-kiri {
        padding: 24px 18px;
        min-height: 200px;
      }
      .register-hero h1 {
        font-size: 1.45rem;
      }
      .register-hero p {
        display: none;
      }
      .register-panel-kanan {
        padding: 24px 18px;
      }
    }
  </style>
</head>
<body class="register-page">

<div class="register-canvas-card">

  <!-- ═══════════════════════════════════════════════════════════ PANEL KIRI (VISUAL & BRANDING) -->
  <div class="register-panel-kiri">
    <!-- Branding Header -->
    <div class="register-brand">
      <div class="register-brand-logo">CL</div>
      <span class="register-brand-nama">Calorie Lab</span>
    </div>

    <!-- Konten Hero & Value Proposition -->
    <div class="register-hero">
      <div class="register-hero-label">Mulai Langkah Sehatmu</div>
      <h1>Satu Akun untuk<br>Pola Hidup Lebih Baik</h1>
      <p>
        Daftar gratis untuk menghitung kebutuhan kalori harianmu, mendapatkan panduan diet terukur,
        dan memantau progres kesehatan tubuhmu setiap hari.
      </p>
      <ul class="register-fitur-list">
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/></svg>
          </span>
          Akses 240+ database menu makanan bernutrisi
        </li>
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
          </span>
          Kalkulator BMI & target kalori instan
        </li>
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
          </span>
          Panduan nutrisi ilmiah bebas metode ekstrem
        </li>
      </ul>
    </div>

    <!-- Footer Panel Kiri -->
    <div class="register-kiri-footer">
      &copy; <?= date('Y') ?> Calorie Lab &nbsp;&middot;&nbsp;
      <a href="<?= base_url('index.php') ?>">Beranda</a> &nbsp;&middot;&nbsp;
      <a href="<?= base_url('login.php') ?>">Masuk</a>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ PANEL KANAN (FORM PENDAFTARAN) -->
  <div class="register-panel-kanan">
    <div class="register-form-wrap">

      <div class="register-form-header">
        <h2>Buat Akun Baru</h2>
        <p>Lengkapi formulir singkat di bawah ini</p>
      </div>

      <?php if ($error): ?>
        <div class="register-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <!-- Form Pendaftaran Pengguna -->
      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <div class="register-field">
          <label for="nama">Nama Lengkap</label>
          <input type="text" id="nama" name="nama"
                 value="<?= h($_POST['nama'] ?? '') ?>"
                 required autofocus placeholder="Contoh: Rian Pratama"
                 autocomplete="name">
        </div>

        <div class="register-field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email"
                 value="<?= h($_POST['email'] ?? '') ?>"
                 required placeholder="nama@email.com"
                 autocomplete="email">
        </div>

        <div class="register-field">
          <label for="password">Kata Sandi</label>
          <div class="register-pass-group">
            <input type="password" id="password" name="password"
                   minlength="6" maxlength="72" required
                   placeholder="Minimal 6 karakter"
                   autocomplete="new-password">
            <button type="button" class="register-pass-toggle" id="btn-toggle-pass"
                    aria-label="Lihat atau sembunyikan kata sandi">Lihat</button>
          </div>
        </div>

        <button type="submit" class="register-submit" id="btn-submit">Buat Akun Gratis</button>
      </form>

      <div class="register-divider">
        Sudah memiliki akun? <a href="<?= base_url('login.php') ?>">Masuk ke akun</a>
      </div>
      <div class="register-divider" style="margin-top:8px;">
        Tenaga medis / ahli gizi? <a href="<?= base_url('register_pakar.php') ?>">Daftar sebagai Pakar</a>
      </div>

      <a href="<?= base_url('index.php') ?>" class="register-back">&larr; Kembali ke beranda</a>
    </div>
  </div>

</div>

<script>
  // ── Toggle show/hide password ──────────────────────────────────────────
  document.getElementById('btn-toggle-pass').addEventListener('click', function() {
    const inp = document.getElementById('password');
    const isPass = inp.type === 'password';
    inp.type = isPass ? 'text' : 'password';
    this.textContent = isPass ? 'Sembunyikan' : 'Lihat';
  });
</script>

</body>
</html>
