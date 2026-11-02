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

<div class="rencana-page-header">
  <h2>Rencana Diet</h2>
  <p class="teks-redup">Program penurunan, kenaikan, atau pemeliharaan berat badan yang dipersonalisasi.</p>
</div>

<?php if ($pesan): ?><div class="alert sukses"><?= h($pesan) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

<?php if (!$bmi): ?>
  <div class="kosong-state">
    <div class="kosong-state-ikon">📏</div>
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
      <strong>🎉 Masa program telah selesai!</strong> Target durasi rencana dietmu telah terpenuhi. Tandai sebagai selesai untuk menyimpan rekam jejaknya, atau buat program baru.
    </div>
  <?php endif; ?>

  <!-- Header rencana aktif -->
  <div class="rencana-aktif-header">
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

  <!-- Stat cards -->
  <div class="rencana-stat-grid">
    <div class="rencana-stat-card">
      <span class="rencana-stat-ikon">⚖️</span>
      <div class="rencana-stat-isi">
        <span class="rencana-stat-label">Berat Awal</span>
        <span class="rencana-stat-nilai"><?= nf($aktif['berat_awal'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="rencana-stat-card">
      <span class="rencana-stat-ikon">🎯</span>
      <div class="rencana-stat-isi">
        <span class="rencana-stat-label">Berat Target</span>
        <span class="rencana-stat-nilai"><?= nf($aktif['berat_target'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="rencana-stat-card">
      <span class="rencana-stat-ikon">🔥</span>
      <div class="rencana-stat-isi">
        <span class="rencana-stat-label">Target Kalori</span>
        <span class="rencana-stat-nilai"><?= nf($aktif['target_kalori']) ?> <small>kkal/hari</small></span>
      </div>
    </div>
  </div>

  <!-- Progress bar durasi -->
  <div class="rencana-progress-section">
    <div class="rencana-progress-label">
      <span>Progres durasi</span>
      <strong><?= min(100, $persen) ?>%</strong>
    </div>
    <div class="progres" style="margin-top:6px"><div class="progres-isi" style="width:<?= min(100, $persen) ?>%"></div></div>
  </div>

  <!-- Aksi navigasi -->
  <div class="kartu rencana-aksi-card">
    <p style="margin-bottom:14px; color:var(--teks-redup); font-size:0.9rem;">Pantau perkembangan beratmu secara berkala dan ikuti rekomendasi menu setiap hari.</p>
    <div class="rencana-aksi-nav">
      <a class="tombol" href="menu.php">🍽️ Menu Rekomendasi</a>
      <a class="tombol tombol-sekunder" href="catatan.php">📓 Catatan Kalori</a>
      <a class="tombol tombol-sekunder" href="profil.php">📊 Perkembangan Berat</a>
    </div>
    <div class="rencana-aksi-selesai">
      <form method="post" style="display:inline">
        <input type="hidden" name="aksi" value="selesai">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <button type="submit" class="tombol" onclick="return confirm('Tandai rencana diet ini sebagai selesai?')">✅ Tandai selesai</button>
      </form>
      <form method="post" style="display:inline">
        <input type="hidden" name="aksi" value="batal">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <button type="submit" class="tombol hapus" onclick="return confirm('Batalkan rencana ini? Catatan yang sudah tersimpan akan tetap ada di riwayat.')">Batalkan rencana</button>
      </form>
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
        <h3 style="margin-bottom:14px;">💡 Panduan Singkat</h3>
        <div class="rencana-panduan-item">
          <div class="rencana-panduan-ikon">📉</div>
          <div>
            <strong>Menurunkan berat</strong>
            <p class="teks-redup" style="font-size:0.85rem; margin-top:3px;">Cocok jika BMI-mu di atas 18,5. Target berat harus lebih rendah dari berat sekarang dengan laju aman maks 1 kg/minggu.</p>
          </div>
        </div>
        <div class="rencana-panduan-item">
          <div class="rencana-panduan-ikon">📈</div>
          <div>
            <strong>Menaikkan berat</strong>
            <p class="teks-redup" style="font-size:0.85rem; margin-top:3px;">Cocok jika BMI-mu di bawah 25. Target berat harus lebih tinggi dari berat sekarang dengan laju aman maks 0,5 kg/minggu.</p>
          </div>
        </div>
        <div class="rencana-panduan-item">
          <div class="rencana-panduan-ikon">⚖️</div>
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

