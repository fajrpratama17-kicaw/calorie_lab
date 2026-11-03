<?php
require_once __DIR__ . '/config/koneksi.php';

// ── Inisialisasi akun default (admin & pakar) ──────────────────────────────
try {
    $cek_admin = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $cek_admin->execute(['admin@calorielab.com']);
    if (!$cek_admin->fetch()) {
        $pdo->prepare("INSERT INTO users (nama, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
            ->execute(['Administrator Utama', 'admin@calorielab.com', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','pakar') NOT NULL DEFAULT 'user'");
} catch (Exception $e) {}

try {
    $cek_pakar = $pdo->prepare("SELECT id, role FROM users WHERE LOWER(email) = ?");
    $cek_pakar->execute(['pakar@calorielab.com']);
    $akun_pakar = $cek_pakar->fetch();
    if (!$akun_pakar) {
        $pdo->prepare("INSERT INTO users (nama, email, password_hash, role) VALUES (?, ?, ?, 'pakar')")
            ->execute(['Pakar Gizi', 'pakar@calorielab.com', password_hash('pakar123', PASSWORD_DEFAULT)]);
    } elseif ($akun_pakar['role'] !== 'pakar') {
        $pdo->prepare("UPDATE users SET role = 'pakar' WHERE LOWER(email) = ?")
            ->execute(['pakar@calorielab.com']);
    }
} catch (Exception $e) {}

// ── Jika sudah login, arahkan ke halaman yang sesuai ─────────────────────
if (isset($_SESSION['user_id'])) {
    $r = $_SESSION['role'] ?? 'user';
    if ($r === 'admin')      { header('Location: ' . base_url('admin/index.php')); exit; }
    elseif ($r === 'pakar')  { header('Location: ' . base_url('pakar/index.php')); exit; }
    else                     { header('Location: ' . base_url('dashboard.php'));   exit; }
}

$error = '';
$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);

$csrf = csrf_token('csrf_token_login');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if (!csrf_valid($token, 'csrf_token_login')) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
    } elseif ($email === '' || $pass === '') {
        $error = 'Harap isi email dan kata sandi.';
    } else {
        $q = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ?');
        $q->execute([$email]);
        $user = $q->fetch();

        if ($user && password_verify($pass, $user['password_hash'])) {
            // Cek status akun untuk role pakar
            if ($user['role'] === 'pakar') {
                $status = $user['status_akun'] ?? 'aktif';
                if ($status === 'pending') {
                    $error = 'Akun Anda masih dalam proses verifikasi oleh Administrator. Mohon tunggu konfirmasi.';
                } elseif ($status === 'ditolak') {
                    $catatan = !empty($user['catatan_admin']) ? ' Alasan: ' . h($user['catatan_admin']) : '';
                    $error = 'Pendaftaran akun Anda telah ditolak.' . $catatan;
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['nama']    = $user['nama'];
                    $_SESSION['role']    = $user['role'];
                    header('Location: ' . base_url('pakar/index.php'));
                    exit;
                }
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['nama']    = $user['nama'];
                $_SESSION['role']    = $user['role'];
                if ($user['role'] === 'admin') {
                    header('Location: ' . base_url('admin/index.php'));
                } else {
                    header('Location: ' . base_url('dashboard.php'));
                }
                exit;
            }
        } else {
            $error = 'Email atau kata sandi tidak cocok.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Masuk &mdash; Calorie Lab</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= base_url('assets/css/styling.css') ?>">
  <style>
    /* ── Reset & Canvas Layout ── */
    *, *::before, *::after { box-sizing: border-box; }
    html, body { min-height: 100%; margin: 0; }

    body.login-page {
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

    /* Floating Canvas Card (Mengikuti Referensi Desain Modern) */
    .login-canvas-card {
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
    .login-panel-kiri {
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

    .login-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      position: relative;
      z-index: 2;
    }

    .login-brand-logo {
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

    .login-brand-nama {
      font-size: 1.18rem;
      font-weight: 800;
      color: var(--gelap);
      letter-spacing: -0.2px;
    }

    .login-hero {
      position: relative;
      z-index: 2;
      max-width: 450px;
      margin: 28px 0;
    }

    .login-hero-label {
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

    .login-hero h1 {
      font-size: clamp(1.8rem, 3.2vw, 2.5rem);
      font-weight: 800;
      color: var(--gelap);
      line-height: 1.22;
      margin: 0 0 14px;
      letter-spacing: -0.5px;
    }

    .login-hero p {
      font-size: 0.94rem;
      color: var(--teks);
      line-height: 1.65;
      margin: 0 0 22px;
      opacity: 0.88;
    }

    .login-fitur-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .login-fitur-list li {
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

    .login-fitur-list li span.icon {
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

    .login-kiri-footer {
      position: relative;
      z-index: 2;
      font-size: 0.8rem;
      color: var(--teks-redup);
    }

    .login-kiri-footer a {
      color: var(--teks-redup);
      text-decoration: none;
      font-weight: 500;
    }
    .login-kiri-footer a:hover { color: var(--hijau); }

    /* ── Panel Kanan: Form Login ── */
    .login-panel-kanan {
      width: clamp(380px, 42%, 480px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(28px, 4vw, 44px) clamp(24px, 4vw, 44px);
      background: #ffffff;
      border-left: 1px solid rgba(232, 227, 218, 0.65);
    }

    .login-form-wrap {
      width: 100%;
      max-width: 380px;
    }

    .login-form-header {
      margin-bottom: 24px;
    }

    .login-form-header h2 {
      font-size: 1.65rem;
      font-weight: 800;
      color: var(--gelap);
      margin: 0 0 6px;
      letter-spacing: -0.3px;
    }

    .login-form-header p {
      font-size: 0.88rem;
      color: var(--teks-redup);
      margin: 0;
    }

    /* Input Fields */
    .login-field { margin-bottom: 16px; }
    .login-field label {
      display: block;
      font-size: 0.83rem;
      font-weight: 600;
      color: var(--gelap);
      margin-bottom: 6px;
    }
    .login-field input[type="email"],
    .login-field input[type="text"],
    .login-field input[type="password"] {
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
    .login-field input:focus {
      border-color: var(--hijau);
      box-shadow: 0 0 0 3px rgba(58, 105, 83, 0.12);
      background: #fff;
    }

    .login-pass-group {
      position: relative;
    }
    .login-pass-group input {
      padding-right: 70px !important;
    }
    .login-pass-toggle {
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
    .login-pass-toggle:hover { color: var(--hijau); }

    /* Alert */
    .login-alert {
      padding: 11px 14px;
      border-radius: 12px;
      font-size: 0.84rem;
      font-weight: 500;
      margin-bottom: 18px;
      line-height: 1.5;
    }
    .login-alert.error  { background: #FEF0EF; color: #B45148; border: 1px solid #FACACB; }
    .login-alert.sukses { background: #E8F2EC; color: #264A38; border: 1px solid #B8D9C5; }

    /* Tombol Submit */
    .login-submit {
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
    .login-submit:hover {
      background: var(--hijau-hover);
      box-shadow: 0 6px 20px rgba(58, 105, 83, 0.28);
      transform: translateY(-1px);
    }
    .login-submit:active { transform: translateY(0); }

    /* Divider & Nav Links */
    .login-divider {
      text-align: center;
      font-size: 0.82rem;
      color: var(--teks-redup);
      margin: 18px 0 0;
    }
    .login-divider a {
      color: var(--hijau);
      font-weight: 600;
      text-decoration: none;
    }
    .login-divider a:hover { text-decoration: underline; }

    .login-back {
      display: block;
      text-align: center;
      font-size: 0.78rem;
      color: var(--teks-redup);
      text-decoration: none;
      margin-top: 14px;
    }
    .login-back:hover { color: var(--hijau); }

    /* ── Responsivitas ── */
    @media (max-width: 900px) {
      .login-canvas-card {
        flex-direction: column;
        border-radius: 20px;
        min-height: auto;
      }
      .login-panel-kiri {
        min-height: 280px;
        padding: 32px 24px;
        background-position: center center;
      }
      .login-hero {
        margin: 16px 0;
      }
      .login-fitur-list {
        display: none;
      }
      .login-panel-kanan {
        width: 100%;
        border-left: none;
        border-top: 1px solid rgba(232, 227, 218, 0.65);
        padding: 36px 24px;
      }
    }

    @media (max-width: 480px) {
      body.login-page {
        padding: 10px;
      }
      .login-canvas-card {
        border-radius: 16px;
      }
      .login-panel-kiri {
        padding: 24px 18px;
        min-height: 200px;
      }
      .login-hero h1 {
        font-size: 1.45rem;
      }
      .login-hero p {
        display: none;
      }
      .login-panel-kanan {
        padding: 24px 18px;
      }
    }
  </style>
</head>
<body class="login-page">

<div class="login-canvas-card">

  <!-- ═══════════════════════════════════════════════════════════ PANEL KIRI (VISUAL & BRANDING) -->
  <div class="login-panel-kiri">
    <!-- Branding Header -->
    <div class="login-brand">
      <div class="login-brand-logo">CL</div>
      <span class="login-brand-nama">Calorie Lab</span>
    </div>

    <!-- Konten Hero & Value Proposition -->
    <div class="login-hero">
      <div class="login-hero-label">Nutrisi &amp; Gaya Hidup Sehat</div>
      <h1>Lacak Kalori,<br>Raih Tujuan Sehatmu</h1>
      <p>
        Platform cerdas untuk memantau asupan kalori harian, merencanakan menu diet,
        dan mendapatkan panduan gizi yang dipersonalisasi dari para ahli.
      </p>
      <ul class="login-fitur-list">
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/></svg>
          </span>
          240+ menu makanan dengan rincian kalori lengkap
        </li>
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
          </span>
          Pantau BMI dan target kalori harianmu
        </li>
        <li>
          <span class="icon" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
          </span>
          Saran gizi dari pakar terpercaya
        </li>
      </ul>
    </div>

    <!-- Footer Panel Kiri -->
    <div class="login-kiri-footer">
      &copy; <?= date('Y') ?> Calorie Lab &nbsp;&middot;&nbsp;
      <a href="<?= base_url('index.php') ?>">Beranda</a> &nbsp;&middot;&nbsp;
      <a href="<?= base_url('register.php') ?>">Daftar Akun</a>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ PANEL KANAN (FORM LOGIN) -->
  <div class="login-panel-kanan">
    <div class="login-form-wrap">

      <div class="login-form-header">
        <h2>Selamat datang</h2>
        <p>Masuk ke akun Calorie Lab Anda</p>
      </div>

      <?php if ($pesan): ?>
        <div class="login-alert sukses"><?= h($pesan) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="login-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <!-- Form Login Universal -->
      <form method="post" id="login-form" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <div class="login-field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email"
                 value="<?= h($_POST['email'] ?? '') ?>"
                 required autofocus placeholder="nama@email.com"
                 autocomplete="email">
        </div>

        <div class="login-field">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <label for="password" style="margin-bottom: 0;">Kata Sandi</label>
            <a href="<?= base_url('lupa_password.php') ?>" style="font-size: 0.78rem; color: var(--hijau); font-weight: 600; text-decoration: none;">Lupa kata sandi?</a>
          </div>
          <div class="login-pass-group">
            <input type="password" id="password" name="password"
                   required placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                   autocomplete="current-password">
            <button type="button" class="login-pass-toggle" id="btn-toggle-pass"
                    aria-label="Lihat atau sembunyikan kata sandi">Lihat</button>
          </div>
        </div>

        <button type="submit" class="login-submit" id="btn-submit">Masuk ke Akun</button>
      </form>

      <div class="login-divider" id="div-daftar">
        Belum punya akun? <a href="<?= base_url('register.php') ?>">Daftar gratis</a>
      </div>
      <div class="login-divider" style="margin-top:8px;">
        Tenaga medis / ahli gizi? <a href="<?= base_url('register_pakar.php') ?>">Daftar sebagai Pakar</a>
      </div>

      <a href="<?= base_url('index.php') ?>" class="login-back">&larr; Kembali ke beranda</a>
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
