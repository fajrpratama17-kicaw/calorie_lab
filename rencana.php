<?php
require_once __DIR__ . '/config/koneksi.php';
wajib_login();

$uid      = $_SESSION['user_id'];
$hari_ini = date('Y-m-d');
$bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
          'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$label = ['turun' => 'Menurunkan berat badan', 'naik' => 'Menaikkan berat badan',
          'pertahankan' => 'Mempertahankan berat badan'];

if (!function_exists('nf')) { function nf($n, $d = 0) { return number_format($n, $d, ',', '.'); } }
if (!function_exists('tgl')) { function tgl($s) { global $bulan; $t = strtotime($s); return date('j', $t) . ' ' . $bulan[(int)date('n', $t)] . ' ' . date('Y', $t); } }

$csrf = csrf_token('csrf_token_rencana');

// Data dari hitung BMI terakhir
$q = $pdo->prepare('SELECT r.*, k.kode AS kat_kode, k.nama AS kat_nama, k.warna
                    FROM riwayat_bmi r JOIN kategori_bmi k ON k.id = r.kategori_id
                    WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1');
$q->execute([$uid]);
$bmi = $q->fetch();

// Rencana yang sedang aktif
$q = $pdo->prepare("SELECT * FROM rencana_diet WHERE user_id = ? AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$q->execute([$uid]);
$aktif = $q->fetch();

$error = '';
$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $aksi  = $_POST['aksi'] ?? '';

    if (!csrf_valid($token, 'csrf_token_rencana')) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ($aksi === 'batal' && $aktif) {
        $pdo->prepare("UPDATE rencana_diet SET status = 'batal' WHERE id = ? AND user_id = ?")
            ->execute([$aktif['id'], $uid]);
        $_SESSION['pesan'] = 'Rencana dibatalkan. Kamu bisa membuat rencana baru kapan saja.';
        header('Location: rencana.php');
        exit;
    } elseif ($aksi === 'selesai' && $aktif) {
        $pdo->prepare("UPDATE rencana_diet SET status = 'selesai' WHERE id = ? AND user_id = ?")
            ->execute([$aktif['id'], $uid]);
        $_SESSION['pesan'] = 'Selamat! Rencana diet telah ditandai selesai. Kamu bisa melihat ringkasannya di menu Profil & Perkembangan atau memulai rencana baru.';
        header('Location: rencana.php');
        exit;
    } elseif ($aksi === 'buat' && $bmi && !$aktif) {
        $tujuan = $_POST['tujuan'] ?? '';
        $durasi = (int)($_POST['durasi'] ?? 0);
        $aktv   = (float)($_POST['aktivitas'] ?? 1.2);

        $berat  = (float)$bmi['berat_kg'];
        $tinggi = (float)$bmi['tinggi_cm'];
        $usia   = (int)$bmi['usia'];
        $jk     = $bmi['jenis_kelamin'];
        $target_berat = ($tujuan === 'pertahankan') ? $berat : (float)($_POST['berat_target'] ?? 0);

        $tm = $tinggi / 100;
        $min_berat  = 18.5 * $tm * $tm;   // batas BMI normal bawah
        $maks_berat = 25.0 * $tm * $tm;   // batas BMI normal atas

        if (!in_array($tujuan, ['turun', 'naik', 'pertahankan'], true)
            || !in_array($durasi, [30, 60, 90, 180], true)
            || !in_array($aktv, [1.2, 1.375, 1.55], true)) {
            $error = 'Isian tidak valid. Silakan periksa kembali formulir.';
        } elseif ($usia < 18) {
            $error = 'Rencana otomatis hanya untuk usia 18 tahun ke atas. Untuk usia di bawah itu, sebaiknya konsultasi ke dokter atau ahli gizi.';
        } elseif ($tujuan !== 'pertahankan' && ($target_berat < 20 || $target_berat > 250)) {
            $error = 'Berat target harus diisi dengan angka valid antara 20 dan 250 kg.';
        } elseif ($tujuan === 'turun') {
            if ($bmi['kat_kode'] === 'kurus')            $error = 'BMI-mu masuk kategori Kurus, jadi menurunkan berat badan tidak disarankan.';
            elseif ($target_berat >= $berat)             $error = 'Berat target harus lebih rendah dari berat sekarang.';
            elseif ($target_berat < $min_berat)          $error = 'Berat target terlalu rendah. Untuk tinggimu, batas amannya sekitar ' . nf($min_berat, 1) . ' kg (BMI 18,5).';
            elseif ($berat - $target_berat < 1)          $error = 'Selisih minimal 1 kg. Untuk selisih lebih kecil, pilih mempertahankan berat.';
        } elseif ($tujuan === 'naik') {
            if (in_array($bmi['kat_kode'], ['gemuk', 'obesitas'], true)) $error = 'BMI-mu di atas normal, jadi menaikkan berat badan tidak disarankan.';
            elseif ($target_berat <= $berat)             $error = 'Berat target harus lebih tinggi dari berat sekarang.';
            elseif ($target_berat > $maks_berat)         $error = 'Berat target terlalu tinggi. Untuk tinggimu, batas atas normalnya sekitar ' . nf($maks_berat, 1) . ' kg (BMI 25).';
            elseif ($target_berat - $berat < 1)          $error = 'Selisih minimal 1 kg. Untuk selisih lebih kecil, pilih mempertahankan berat.';
        }

        // Cek laju perubahan yang aman: turun maks 1 kg/minggu, naik maks 0,5 kg/minggu
        if (!$error && $tujuan !== 'pertahankan') {
            $selisih   = abs($target_berat - $berat);
            $laju_maks = ($tujuan === 'turun') ? 1.0 : 0.5;
            if ($selisih / ($durasi / 7) > $laju_maks) {
                $min_hari = (int)ceil($selisih / $laju_maks * 7);
                $error = 'Terlalu cepat. Dengan laju aman maksimal ' . nf($laju_maks, 1) . ' kg per minggu, perubahan '
                       . nf($selisih, 1) . ' kg butuh sekitar ' . ceil($min_hari / 30) . ' bulan'
                       . ($min_hari > 180 ? ' (melebihi 6 bulan, coba perkecil targetmu)' : ', pilih durasi yang lebih panjang') . '.';
            }
        }

        if (!$error) {
            // Kebutuhan kalori (Mifflin-St Jeor), lalu sesuaikan dengan target
            $bmr  = 10 * $berat + 6.25 * $tinggi - 5 * $usia + ($jk === 'L' ? 5 : -161);
            $tdee = $bmr * $aktv;
            $per_hari = ($target_berat - $berat) * 7700 / $durasi;   // perkiraan 7.700 kkal per kg
            $per_hari = max(-1000, min(500, $per_hari));
            $batas = ($jk === 'L') ? 1500 : 1200;
            $target_kal = $tdee + $per_hari;
            $dibatasi = false;
            if ($target_kal < $batas) { $target_kal = $batas; $dibatasi = true; }
            $target_kal = (int)(round($target_kal / 10) * 10);

            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO rencana_diet
                (user_id, tujuan, berat_awal, berat_target, tinggi_cm, usia, jenis_kelamin, aktivitas, tanggal_mulai, durasi_hari, target_kalori)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$uid, $tujuan, $berat, $target_berat, $tinggi, $usia, $jk, $aktv, $hari_ini, $durasi, $target_kal]);
            $rid = $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO catatan_berat (user_id, rencana_id, berat_kg, tanggal, catatan) VALUES (?, ?, ?, ?, ?)')
                ->execute([$uid, $rid, $berat, $hari_ini, 'Berat awal rencana']);
            $pdo->commit();

            $_SESSION['pesan'] = 'Rencana dibuat. Target kalori harianmu ' . nf($target_kal) . ' kkal.'
                . ($dibatasi ? ' Target dinaikkan ke batas aman minimal, jadi perubahan berat bisa lebih lambat dari rencana.' : '');
            header('Location: rencana.php');
            exit;
        }
    }
}

$judul = 'Rencana Diet';
include BASE_DIR . 'includes/header.php';
?>

<div class="page-hero-header">
  <div class="page-hero-icon">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
  </div>
  <div class="page-hero-teks">
    <h2>Rencana Diet</h2>
    <p>Program penurunan, kenaikan, atau pemeliharaan berat badan yang dipersonalisasi.</p>
  </div>
  <div style="margin-left: auto; flex-shrink: 0;">
    <a href="<?= base_url('profil.php') ?>" class="tombol sekunder kecil" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius-pil); font-weight: 700; text-decoration: none;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Kembali ke Profil
    </a>
  </div>
</div>

<?php if ($pesan): ?><div class="alert sukses"><?= h($pesan) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

<?php if (!$bmi): ?>
  <div class="kosong-state">
    <div class="kosong-state-ikon">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
    </div>
    <h3>Data BMI belum tersedia</h3>
    <p>Rencana diet dibuat dari data BMI-mu. Hitung BMI terlebih dahulu.</p>
    <a class="tombol" href="bmi.php">Hitung BMI sekarang &rarr;</a>
  </div>

<?php elseif ($aktif):
    $mulai   = new DateTime($aktif['tanggal_mulai']);
    $selesai = (clone $mulai)->modify('+' . (int)$aktif['durasi_hari'] . ' days');
    $selesai_str = $selesai->format('Y-m-d');
    $diff_hari = (int)(new DateTime($hari_ini))->diff($mulai)->days;
    $hari_ke = $hari_ini >= $aktif['tanggal_mulai'] ? min((int)$aktif['durasi_hari'], $diff_hari + 1) : 1;
    $persen  = (int)round($hari_ke / $aktif['durasi_hari'] * 100);
    $durasi_selesai = $hari_ini >= $selesai_str;
?>

  <?php if ($durasi_selesai): ?>
    <div class="alert sukses" style="margin-bottom:18px">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-3px;margin-right:6px"><polyline points="20 6 9 17 4 12"/></svg>
      <strong>Masa program telah selesai!</strong> Target durasi rencana dietmu telah terpenuhi. Tandai sebagai selesai untuk menyimpan rekam jejaknya, atau buat program baru.
    </div>
  <?php endif; ?>

  <!-- Header rencana aktif (Menyatu dengan Progress Bar Durasi) -->
  <div class="rencana-aktif-header" style="flex-direction:column; align-items:stretch; gap:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <div class="rencana-aktif-tujuan"><?= h($label[$aktif['tujuan']]) ?></div>
        <div class="teks-redup" style="font-size:0.85rem; margin-top:4px;">
          <?= h(tgl($aktif['tanggal_mulai'])) ?> &ndash; <?= h(tgl($selesai_str)) ?>
          <?php if ($durasi_selesai): ?> &middot; <span class="badge" style="background:var(--normal);">Durasi tercapai</span><?php endif; ?>
        </div>
      </div>
      <div class="rencana-hari-counter">
        <span class="rencana-hari-angka"><?= $hari_ke ?></span>
        <span class="rencana-hari-label">dari <?= (int)$aktif['durasi_hari'] ?> hari</span>
      </div>
    </div>

    <!-- Progress bar timeline program yang menyatu rapi -->
    <div style="border-top:1px solid var(--garis); padding-top:14px;">
      <div class="rencana-progress-label" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <span style="font-size:0.82rem; font-weight:600; color:var(--teks-redup);">Progres timeline program</span>
        <strong style="color:var(--hijau); font-size:0.88rem;"><?= min(100, $persen) ?>%</strong>
      </div>
      <div class="progres" style="height:8px; margin:0;">
        <div class="progres-isi" style="width:<?= min(100, $persen) ?>%"></div>
      </div>
    </div>
  </div>

  <!-- Stat cards 3 kolom -->
  <div class="rencana-stat-grid">
    <div class="rencana-stat-card">
      <span class="rencana-stat-ikon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><line x1="12" y1="8" x2="12" y2="21"/><path d="M5 21h14"/><path d="M5 12H2l3-3 3 3"/><path d="M19 12h3l-3-3-3 3"/></svg>
      </span>
      <div class="rencana-stat-isi">
        <span class="rencana-stat-label">Berat Awal</span>
        <span class="rencana-stat-nilai"><?= nf($aktif['berat_awal'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="rencana-stat-card">
      <span class="rencana-stat-ikon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
      </span>
      <div class="rencana-stat-isi">
        <span class="rencana-stat-label">Berat Target</span>
        <span class="rencana-stat-nilai"><?= nf($aktif['berat_target'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="rencana-stat-card">
      <span class="rencana-stat-ikon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
      </span>
      <div class="rencana-stat-isi">
        <span class="rencana-stat-label">Target Kalori</span>
        <span class="rencana-stat-nilai"><?= nf($aktif['target_kalori']) ?> <small>kkal/hari</small></span>
      </div>
    </div>
  </div>

  <!-- Kartu Aktivitas Harian & Manajemen Program (Opsi B SaaS Style) -->
  <div class="kartu" style="padding: 24px; margin-top: 20px;">
    <div>
      <h3 style="font-size: 1.05rem; margin-bottom: 4px; color: var(--gelap);">Aktivitas Harian Program</h3>
      <p class="teks-redup" style="font-size: 0.85rem; margin: 0;">Ikuti bimbingan nutrisi dan pantau perkembangan targetmu setiap hari:</p>
    </div>

    <!-- Grid 3 Mini Card Interaktif -->
    <div class="rencana-mini-grid">
      <!-- 1. Menu Rekomendasi -->
      <a href="menu.php" class="rencana-mini-card">
        <div>
          <div class="rencana-mini-card-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
          </div>
          <strong class="rencana-mini-card-title">Menu Rekomendasi</strong>
          <span class="rencana-mini-card-desc">Paket resep gizi seimbang sesuai kuota target kalori harianmu.</span>
        </div>
        <span class="rencana-mini-card-link">Pilih Menu &rarr;</span>
      </a>

      <!-- 2. Catatan Kalori -->
      <a href="catatan.php" class="rencana-mini-card">
        <div>
          <div class="rencana-mini-card-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="13" y2="11"/></svg>
          </div>
          <strong class="rencana-mini-card-title">Catatan Kalori</strong>
          <span class="rencana-mini-card-desc">Catat makanan harian dan pantau sisa kuota kalorimu.</span>
        </div>
        <span class="rencana-mini-card-link">Catat Asupan &rarr;</span>
      </a>

      <!-- 3. Perkembangan Berat -->
      <a href="profil.php" class="rencana-mini-card">
        <div>
          <div class="rencana-mini-card-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
          </div>
          <strong class="rencana-mini-card-title">Perkembangan Berat</strong>
          <span class="rencana-mini-card-desc">Perbarui data timbangan dan pantau kurva grafik menuju target.</span>
        </div>
        <span class="rencana-mini-card-link">Pantau Progres &rarr;</span>
      </a>
    </div>

    <!-- Footer Bar: Status Durasi & Tombol Siklus Program -->
    <div class="rencana-opsi-b-footer">
      <div class="rencana-opsi-b-status-info">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau);"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span>Program berakhir pada <strong><?= h(tgl($selesai_str)) ?></strong> &middot; Hari ke-<?= $hari_ke ?> dari <?= (int)$aktif['durasi_hari'] ?> hari</span>
      </div>
      <div class="rencana-opsi-b-actions">
        <form method="post" style="display:inline; margin:0;">
          <input type="hidden" name="aksi" value="selesai">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <button type="submit" class="tombol kecil" onclick="return confirm('Tandai rencana diet ini sebagai selesai?')" style="padding:8px 16px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
            Tandai Selesai
          </button>
        </form>
        <form method="post" style="display:inline; margin:0;">
          <input type="hidden" name="aksi" value="batal">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <button type="submit" class="tombol hapus kecil" onclick="return confirm('Batalkan rencana ini? Catatan yang sudah tersimpan akan tetap ada di riwayat.')" style="padding:8px 14px; font-size:0.82rem;">
            Batalkan Rencana
          </button>
        </form>
      </div>
    </div>
  </div>

<?php else:
    $tm = $bmi['tinggi_cm'] / 100; ?>

  <div class="rencana-two-col">

    <!-- Kolom kiri: Data BMI & form -->
    <div class="rencana-col-form">
      <div class="kartu rencana-bmi-info">
        <div class="rencana-bmi-header">
          <h3 style="font-size:1rem;">Data awal dari hitung BMI</h3>
          <a href="bmi.php" class="tombol kecil tombol-sekunder" style="font-size:0.78rem;">Ukur ulang</a>
        </div>
        <div class="rencana-bmi-stats">
          <div class="rencana-bmi-stat-item">
            <span class="teks-redup">Berat</span>
            <strong><?= nf($bmi['berat_kg'], 1) ?> kg</strong>
          </div>
          <div class="rencana-bmi-stat-item">
            <span class="teks-redup">Tinggi</span>
            <strong><?= nf($bmi['tinggi_cm'], 0) ?> cm</strong>
          </div>
          <div class="rencana-bmi-stat-item">
            <span class="teks-redup">Usia</span>
            <strong><?= (int)$bmi['usia'] ?> th</strong>
          </div>
          <div class="rencana-bmi-stat-item">
            <span class="teks-redup">BMI</span>
            <strong><?= nf($bmi['bmi'], 1) ?> <span class="badge kategori-<?= h($bmi['kat_kode']) ?>" style="font-size:0.7rem;"><?= h($bmi['kat_nama']) ?></span></strong>
          </div>
        </div>
        <p class="catatan" style="margin-top:10px;">Rentang berat normal untuk tinggimu: <strong><?= nf(18.5 * $tm * $tm, 1) ?> – <?= nf(25 * $tm * $tm, 1) ?> kg</strong></p>
      </div>

      <div class="kartu rencana-form-card">
        <h3 style="margin-bottom:4px;">Buat Rencana Diet Baru</h3>
        <p class="teks-redup" style="font-size:0.85rem; margin-bottom:18px;">Laju aman: turun maks 1 kg/minggu &middot; naik maks 0,5 kg/minggu.</p>
        <form method="post">
          <input type="hidden" name="aksi" value="buat">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

          <label for="tujuan">Tujuan program</label>
          <select id="tujuan" name="tujuan">
            <option value="turun" <?= ($_POST['tujuan'] ?? '') === 'turun' ? 'selected' : '' ?>>Menurunkan berat badan</option>
            <option value="naik" <?= ($_POST['tujuan'] ?? '') === 'naik' ? 'selected' : '' ?>>Menaikkan berat badan</option>
            <option value="pertahankan" <?= ($_POST['tujuan'] ?? '') === 'pertahankan' ? 'selected' : '' ?>>Mempertahankan berat badan</option>
          </select>

          <div id="kotak-target" style="margin-top:14px;">
            <label for="berat_target">Berat target (kg)</label>
            <div class="input-unit-wrapper">
              <input type="number" id="berat_target" name="berat_target" step="0.1" min="20" max="250" placeholder="Misal: 65" value="<?= h($_POST['berat_target'] ?? '') ?>">
              <span class="unit-suffix">kg</span>
            </div>
          </div>

          <div style="margin-top:14px;">
            <label for="durasi">Durasi program</label>
            <select id="durasi" name="durasi">
              <?php foreach ([30 => '1 bulan', 60 => '2 bulan', 90 => '3 bulan', 180 => '6 bulan'] as $hr => $tx): ?>
                <option value="<?= $hr ?>" <?= (int)($_POST['durasi'] ?? 90) === $hr ? 'selected' : '' ?>><?= $tx ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="margin-top:14px;">
            <label for="aktivitas">Aktivitas harian</label>
            <select id="aktivitas" name="aktivitas">
              <option value="1.2">Jarang olahraga (banyak duduk)</option>
              <option value="1.375" <?= ($_POST['aktivitas'] ?? '') === '1.375' ? 'selected' : '' ?>>Olahraga ringan 1-3x seminggu</option>
              <option value="1.55" <?= ($_POST['aktivitas'] ?? '') === '1.55' ? 'selected' : '' ?>>Olahraga sedang 3-5x seminggu</option>
            </select>
          </div>

          <button type="submit" class="tombol penuh" style="margin-top:20px;">Buat rencana &rarr;</button>
        </form>
      </div>
    </div>

    <!-- Kolom kanan: Panduan -->
    <div class="rencana-col-guide">
      <div class="kartu rencana-panduan-card">
        <h3 style="margin-bottom:14px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-3px;margin-right:6px;color:var(--hijau)"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
          Panduan Singkat
        </h3>
        <div class="rencana-panduan-item">
          <div class="rencana-panduan-ikon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          </div>
          <div>
            <strong>Menurunkan berat</strong>
            <p class="teks-redup" style="font-size:0.85rem; margin-top:3px;">Cocok jika BMI-mu di atas 18,5. Target berat harus lebih rendah dari berat sekarang dengan laju aman maks 1 kg/minggu.</p>
          </div>
        </div>
        <div class="rencana-panduan-item">
          <div class="rencana-panduan-ikon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
          </div>
          <div>
            <strong>Menaikkan berat</strong>
            <p class="teks-redup" style="font-size:0.85rem; margin-top:3px;">Cocok jika BMI-mu di bawah 25. Target berat harus lebih tinggi dari berat sekarang dengan laju aman maks 0,5 kg/minggu.</p>
          </div>
        </div>
        <div class="rencana-panduan-item">
          <div class="rencana-panduan-ikon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><line x1="12" y1="8" x2="12" y2="21"/><path d="M5 21h14"/><path d="M5 12H2l3-3 3 3"/><path d="M19 12h3l-3-3-3 3"/></svg>
          </div>
          <div>
            <strong>Mempertahankan berat</strong>
            <p class="teks-redup" style="font-size:0.85rem; margin-top:3px;">Cocok untuk semua kategori BMI. Sistem akan menghitung kebutuhan kalori tanpa penyesuaian penurunan/kenaikan.</p>
          </div>
        </div>
        <hr style="margin:16px 0; border-color:var(--garis);">
        <p class="catatan">Perhitungan menggunakan rumus Mifflin-St Jeor. Bersifat perkiraan dan bukan pengganti saran ahli gizi atau dokter.</p>
      </div>
    </div>

  </div>

  <script>
    const t = document.getElementById('tujuan'), b = document.getElementById('kotak-target');
    function ubah() { b.style.display = (t.value === 'pertahankan') ? 'none' : 'block'; }
    t.addEventListener('change', ubah); ubah();
  </script>
<?php endif; ?>

<?php include BASE_DIR . 'includes/footer.php'; ?>

