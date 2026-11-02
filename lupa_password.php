<?php
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/email.php';

// Pastikan tabel password_resets ada (auto-migrate)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(191) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_token (token),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// Jika sudah login, redirect
if (isset($_SESSION['user_id'])) {
    $r = $_SESSION['role'] ?? 'user';
    if ($r === 'admin')      { header('Location: ' . base_url('admin/index.php')); exit; }
    elseif ($r === 'pakar')  { header('Location: ' . base_url('pakar/index.php')); exit; }
    else                     { header('Location: ' . base_url('dashboard.php'));   exit; }
}

$error = '';
$pesan = '';
$email_terkirim = false;
$email_input = '';
$fallback_link = '';
$info_tambahan = '';
$csrf = csrf_token('csrf_token_lupa_pass');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token_csrf  = $_POST['csrf_token'] ?? '';
    $email_input = strtolower(trim($_POST['email'] ?? ''));

    if (!csrf_valid($token_csrf, 'csrf_token_lupa_pass')) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
    } elseif ($email_input === '' || !filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
        $error = 'Harap masukkan alamat email yang valid.';
    } else {
        // Cari user di database
        $st = $pdo->prepare("SELECT id, nama, email, role FROM users WHERE LOWER(email) = ?");
        $st->execute([$email_input]);
        $user = $st->fetch();

        if ($user) {
            // Buat token acak yang aman (64 hex characters)
            $token_reset = bin2hex(random_bytes(32));
            $expires_at  = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            // Hapus token lama milik email ini yang belum terpakai
            $del = $pdo->prepare("DELETE FROM password_resets WHERE LOWER(email) = ?");
            $del->execute([$email_input]);

            // Simpan token baru ke database
            $ins = $pdo->prepare("INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)");
            $ins->execute([$user['id'], $user['email'], $token_reset, $expires_at]);

            $link_reset = base_url('reset_password.php?token=' . $token_reset);

            // Kirim email via SMTP PHPMailer
            $hasil_kirim = kirim_email_reset($user['email'], $user['nama'], $link_reset);

            if ($hasil_kirim['sukses']) {
                $email_terkirim = true;
                $pesan = 'Tautan reset kata sandi telah berhasil dikirimkan ke <strong>' . h($user['email']) . '</strong>. Silakan periksa Kotak Masuk (Inbox) atau folder Spam Anda.';
            } else {
                // Jika kredensial Google belum dimasukkan atau koneksi SMTP gagal
                $fallback_link = $link_reset;
                if (!empty($hasil_kirim['is_placeholder'])) {
                    $pesan = 'Permintaan reset kata sandi berhasil diproses.';
                    $info_tambahan = 'Pengiriman email SMTP Gmail belum aktif karena Sandi Aplikasi (App Password) di <code>config/email.php</code> masih default. Untuk pengujian lokal saat ini, gunakan tautan di bawah ini:';
                } else {
                    $pesan = 'Tautan reset telah dibuat.';
                    $info_tambahan = 'Gagal mengirim email via server SMTP (' . h($hasil_kirim['error']) . '). Anda tetap dapat melanjutkan pengujian dengan tautan langsung di bawah ini:';
                }
            }
        } else {
            // Email tidak ditemukan
            $error = 'Email (' . h($email_input) . ') belum terdaftar di sistem Calorie Lab.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Lupa Kata Sandi &mdash; Calorie Lab</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= base_url('assets/css/styling.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { min-height: 100%; margin: 0; }

    body.login-page {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: radial-gradient(circle at 15% 15%, #EAF3EE 0%, #FAF8F5 50%, #EDE8E1 100%);
      font-family: var(--font-utama);
      padding: clamp(16px, 3vw, 36px);
      position: relative;
    }

    .login-canvas-card {
      display: flex;
      width: 100%;
      max-width: 1080px;
      min-height: 580px;
      background: #ffffff;
      border-radius: 28px;
      box-shadow: 0 24px 64px rgba(34, 56, 45, 0.09), 0 4px 16px rgba(34, 56, 45, 0.03);
      overflow: hidden;
      border: 1px solid rgba(232, 227, 218, 0.85);
    }

    .login-panel-kiri {
      flex: 1.15;
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
      box-shadow: 0 4px 12px rgba(58, 105, 83, 0.25);
    }

    .login-brand-nama {
      font-size: 1.18rem;
      font-weight: 800;
      color: var(--gelap);
    }

    .login-hero {
      position: relative;
      z-index: 2;
      max-width: 440px;
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
    }

    .login-hero h1 {
      font-size: clamp(1.8rem, 3vw, 2.3rem);
      font-weight: 800;
      color: var(--gelap);
      line-height: 1.25;
      margin: 0 0 14px;
    }

    .login-hero p {
      font-size: 0.94rem;
      color: var(--teks);
      line-height: 1.65;
      margin: 0;
      opacity: 0.88;
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

    .login-panel-kanan {
      width: clamp(380px, 45%, 490px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(28px, 4vw, 44px);
      background: #ffffff;
      border-left: 1px solid rgba(232, 227, 218, 0.65);
    }

    .login-form-wrap {
      width: 100%;
      max-width: 390px;
    }

    .login-form-header {
      margin-bottom: 22px;
    }

    .login-form-header h2 {
      font-size: 1.6rem;
      font-weight: 800;
      color: var(--gelap);
      margin: 0 0 6px;
      letter-spacing: -0.3px;
    }

    .login-form-header p {
      font-size: 0.88rem;
      color: var(--teks-redup);
      line-height: 1.5;
      margin: 0;
    }

    .login-field { margin-bottom: 18px; }
    .login-field label {
      display: block;
      font-size: 0.83rem;
      font-weight: 600;
      color: var(--gelap);
      margin-bottom: 6px;
    }
    .login-field input[type="email"] {
      width: 100%;
      padding: 12px 14px;
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

    .login-alert {
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 0.84rem;
      font-weight: 500;
      margin-bottom: 18px;
      line-height: 1.5;
    }
    .login-alert.error  { background: #FEF0EF; color: #B45148; border: 1px solid #FACACB; }
    .login-alert.sukses { background: #E8F2EC; color: #264A38; border: 1px solid #B8D9C5; }

    .email-sent-badge {
      display: flex;
      align-items: center;
      gap: 10px;
      background: #E8F2EC;
      color: #264A38;
      border: 1px solid #B8D9C5;
      border-radius: 14px;
      padding: 14px 16px;
      margin-bottom: 20px;
      font-size: 0.88rem;
      line-height: 1.5;
    }

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
      transition: background 0.18s, transform 0.12s, box-shadow 0.18s;
      box-shadow: 0 4px 14px rgba(58, 105, 83, 0.20);
    }
    .login-submit:hover {
      background: var(--hijau-hover);
      box-shadow: 0 6px 20px rgba(58, 105, 83, 0.28);
      transform: translateY(-1px);
    }

    .demo-reset-box {
      background: linear-gradient(135deg, #F2F8F4 0%, #FFFFFF 100%);
      border: 1.5px dashed var(--hijau);
      border-radius: 16px;
      padding: 16px 18px;
      margin-top: 18px;
      box-shadow: 0 6px 18px rgba(58, 105, 83, 0.06);
    }
    .demo-reset-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 0.72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--hijau);
      background: var(--mint);
      padding: 3px 10px;
      border-radius: 999px;
      margin-bottom: 8px;
    }
    .demo-reset-box p {
      font-size: 0.82rem;
      color: var(--gelap);
      line-height: 1.5;
      margin: 0 0 12px;
    }
    .demo-reset-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 10px 14px;
      background: var(--hijau);
      color: #fff;
      font-size: 0.84rem;
      font-weight: 700;
      text-decoration: none;
      border-radius: 10px;
      transition: all 0.2s ease;
    }
    .demo-reset-btn:hover {
      background: var(--hijau-hover);
      transform: translateY(-1px);
    }

    .login-back {
      display: block;
      text-align: center;
      font-size: 0.82rem;
      color: var(--teks-redup);
      text-decoration: none;
      margin-top: 18px;
    }
    .login-back:hover { color: var(--hijau); }

    @media (max-width: 900px) {
      .login-canvas-card { flex-direction: column; min-height: auto; }
      .login-panel-kiri { min-height: 240px; padding: 28px 22px; }
      .login-panel-kanan { width: 100%; border-left: none; border-top: 1px solid rgba(232, 227, 218, 0.65); padding: 32px 22px; }
    }
  </style>
</head>
<body class="login-page">

<div class="login-canvas-card">

  <!-- ════════════ PANEL KIRI ════════════ -->
  <div class="login-panel-kiri">
    <div class="login-brand">
      <div class="login-brand-logo">CL</div>
      <span class="login-brand-nama">Calorie Lab</span>
    </div>

    <div class="login-hero">
      <div class="login-hero-label">Keamanan &amp; Akun</div>
      <h1>Pulihkan Akses<br>Akun Anda</h1>
      <p>
        Jangan khawatir jika Anda lupa kata sandi. Masukkan email yang terdaftar dan kami akan mengirimkan tautan pemulihan resmi ke kotak masuk email Anda.
      </p>
    </div>

    <div class="login-kiri-footer">
      &copy; <?= date('Y') ?> Calorie Lab &nbsp;&middot;&nbsp;
      <a href="<?= base_url('login.php') ?>">Masuk</a> &nbsp;&middot;&nbsp;
      <a href="<?= base_url('index.php') ?>">Beranda</a>
    </div>
  </div>

  <!-- ════════════ PANEL KANAN ════════════ -->
  <div class="login-panel-kanan">
    <div class="login-form-wrap">

      <div class="login-form-header">
        <h2>Lupa Kata Sandi?</h2>
        <p>Masukkan alamat email akun Calorie Lab Anda untuk menerima tautan pemulihan kata sandi.</p>
      </div>

      <?php if ($email_terkirim): ?>
        <div class="email-sent-badge">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <div><?= $pesan ?></div>
        </div>
      <?php elseif ($pesan): ?>
        <div class="login-alert sukses"><?= $pesan ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="login-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($fallback_link): ?>
        <!-- Card Pengujian Lokal saat SMTP belum aktif -->
        <div class="demo-reset-box">
          <div class="demo-reset-badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Tautan Cadangan (Pengujian Lokal)
          </div>
          <p><?= $info_tambahan ?></p>
          <a href="<?= $fallback_link ?>" class="demo-reset-btn">
            Buka Halaman Reset Kata Sandi &rarr;
          </a>
        </div>
      <?php endif; ?>

      <form method="post" id="lupa-form" style="margin-top: <?= ($fallback_link || $email_terkirim) ? '20px' : '0' ?>;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <div class="login-field">
          <label for="email">Alamat Email Terdaftar</label>
          <input type="email" id="email" name="email"
                 value="<?= h($email_input) ?>"
                 required autofocus placeholder="nama@email.com"
                 autocomplete="email">
        </div>

        <button type="submit" class="login-submit">
          <?= ($email_terkirim || $fallback_link) ? 'Kirim Ulang Tautan' : 'Kirim Tautan Reset' ?>
        </button>
      </form>

      <a href="<?= base_url('login.php') ?>" class="login-back">&larr; Kembali ke halaman masuk</a>
    </div>
  </div>

</div>

</body>
</html>
