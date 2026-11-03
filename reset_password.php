<?php
require_once __DIR__ . '/config/koneksi.php';

// Jika sudah login, redirect
if (isset($_SESSION['user_id'])) {
    $r = $_SESSION['role'] ?? 'user';
    if ($r === 'admin')      { header('Location: ' . base_url('admin/index.php')); exit; }
    elseif ($r === 'pakar')  { header('Location: ' . base_url('pakar/index.php')); exit; }
    else                     { header('Location: ' . base_url('dashboard.php'));   exit; }
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$sukses = '';
$token_valid = false;
$user_target = null;
$reset_row   = null;

if ($token === '') {
    $error = 'Token reset kata sandi tidak ditemukan atau tidak valid.';
} else {
    // Cari token di database
    $st = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? LIMIT 1");
    $st->execute([$token]);
    $reset_row = $st->fetch();

    if (!$reset_row) {
        $error = 'Tautan reset kata sandi tidak valid atau telah dihapus.';
    } elseif ((int)$reset_row['used'] === 1) {
        $error = 'Tautan ini sudah pernah digunakan sebelumnya. Silakan ajukan permintaan baru jika Anda ingin mereset kata sandi lagi.';
    } elseif (strtotime($reset_row['expires_at']) < time()) {
        $error = 'Tautan reset kata sandi ini telah kedaluwarsa (masa berlaku 30 menit). Silakan ajukan permintaan baru.';
    } else {
        // Ambil data user
        $st_u = $pdo->prepare("SELECT id, nama, email, role FROM users WHERE id = ?");
        $st_u->execute([$reset_row['user_id']]);
        $user_target = $st_u->fetch();

        if (!$user_target) {
            $error = 'Akun pengguna yang terkait dengan tautan ini tidak ditemukan.';
        } else {
            $token_valid = true;
        }
    }
}

$csrf = csrf_token('csrf_token_reset_pass');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $token_csrf = $_POST['csrf_token'] ?? '';
    $pass1      = $_POST['password'] ?? '';
    $pass2      = $_POST['password_konfirmasi'] ?? '';

    if (!csrf_valid($token_csrf, 'csrf_token_reset_pass')) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (strlen($pass1) < 6 || strlen($pass1) > 72) {
        $error = 'Kata sandi baru harus memiliki panjang antara 6 hingga 72 karakter.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Konfirmasi kata sandi tidak cocok. Harap periksa kembali.';
    } else {
        // Hash kata sandi baru
        $hash_baru = password_hash($pass1, PASSWORD_DEFAULT);

        // Update password pengguna
        $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $up->execute([$hash_baru, $user_target['id']]);

        // Tandai token telah digunakan
        $up_token = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        $up_token->execute([$reset_row['id']]);

        // Beri pesan sukses dan arahkan ke login
        $_SESSION['pesan'] = 'Kata sandi akun ' . $user_target['nama'] . ' berhasil diperbarui! Silakan masuk dengan kata sandi baru Anda.';
        header('Location: ' . base_url('login.php'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Atur Ulang Kata Sandi &mdash; Calorie Lab</title>
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

    .user-info-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--mint);
      color: var(--hijau);
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 700;
      margin-bottom: 18px;
    }

    .login-field { margin-bottom: 16px; }
    .login-field label {
      display: block;
      font-size: 0.83rem;
      font-weight: 600;
      color: var(--gelap);
      margin-bottom: 6px;
    }
    .login-field input[type="password"],
    .login-field input[type="text"] {
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
      margin-top: 6px;
    }
    .login-submit:hover {
      background: var(--hijau-hover);
      box-shadow: 0 6px 20px rgba(58, 105, 83, 0.28);
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
      <div class="login-hero-label">Keamanan Akun</div>
      <h1>Kata Sandi Baru,<br>Akses Aman Kembali</h1>
      <p>
        Buat kata sandi baru yang kuat dan mudah Anda ingat agar akun Anda selalu terlindungi dengan optimal.
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
        <h2>Atur Kata Sandi Baru</h2>
        <p>Silakan masukkan kata sandi baru untuk akun Anda.</p>
      </div>

      <?php if ($error): ?>
        <div class="login-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($token_valid && $user_target): ?>
        <div class="user-info-pill">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Akun: <?= h($user_target['nama']) ?> (<?= h($user_target['email']) ?>)
        </div>

        <form method="post" id="reset-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="token" value="<?= h($token) ?>">

          <div class="login-field">
            <label for="password">Kata Sandi Baru</label>
            <div class="login-pass-group">
              <input type="password" id="password" name="password"
                     required placeholder="Minimal 6 karakter"
                     autocomplete="new-password">
              <button type="button" class="login-pass-toggle" onclick="togglePass('password', this)">Lihat</button>
            </div>
          </div>

          <div class="login-field">
            <label for="password_konfirmasi">Konfirmasi Kata Sandi Baru</label>
            <div class="login-pass-group">
              <input type="password" id="password_konfirmasi" name="password_konfirmasi"
                     required placeholder="Ketik ulang kata sandi baru"
                     autocomplete="new-password">
              <button type="button" class="login-pass-toggle" onclick="togglePass('password_konfirmasi', this)">Lihat</button>
            </div>
          </div>

          <button type="submit" class="login-submit">Simpan Kata Sandi Baru</button>
        </form>
      <?php else: ?>
        <div style="text-align:center; margin-top:20px;">
          <a href="<?= base_url('lupa_password.php') ?>" class="login-submit" style="display:inline-block; text-decoration:none;">
            Minta Tautan Reset Baru &rarr;
          </a>
        </div>
      <?php endif; ?>

      <a href="<?= base_url('login.php') ?>" class="login-back">&larr; Kembali ke halaman masuk</a>
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
</script>

</body>
</html>
