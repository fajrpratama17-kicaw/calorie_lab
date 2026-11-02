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
if (!function_exists('selisih_hari')) { function selisih_hari($a, $b) { return (int)(new DateTime($a))->diff(new DateTime($b))->format('%r%a'); } }

$csrf = csrf_token('csrf_token_profil');

$q = $pdo->prepare('SELECT nama, email FROM users WHERE id = ?');
$q->execute([$uid]);
$user = $q->fetch();

$q = $pdo->prepare("SELECT * FROM rencana_diet WHERE user_id = ? AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$q->execute([$uid]);
$aktif = $q->fetch();

$error = '';
$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);

// Catat berat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'catat' && $aktif) {
    $token   = $_POST['csrf_token'] ?? '';
    $berat   = (float)($_POST['berat'] ?? 0);
    $tanggal = $_POST['tanggal'] ?? '';
    $cat     = mb_substr(trim($_POST['catatan'] ?? ''), 0, 255);

    $d       = DateTime::createFromFormat('!Y-m-d', $tanggal);
    $err_d   = DateTime::getLastErrors();
    $format_ok = $d && $d->format('Y-m-d') === $tanggal && ($err_d === false || ($err_d['warning_count'] === 0 && $err_d['error_count'] === 0));

    if (!csrf_valid($token, 'csrf_token_profil')) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (!$format_ok || $tanggal > $hari_ini) {
        $error = 'Tanggal tidak valid. Kamu dapat memilih tanggal hari ini atau tanggal sebelumnya, tetapi tidak boleh melebihi hari ini (' . h(tgl($hari_ini)) . ').';
    } elseif ($berat < 20 || $berat > 250) {
        $error = 'Berat harus antara 20 dan 250 kg.';
    } else {
        // Jika pengguna mencatat berat dengan tanggal sebelum awal rencana,
        // perbarui tanggal_mulai rencana agar data ini menjadi titik awal (Hari ke-0)
        if ($tanggal < $aktif['tanggal_mulai']) {
            $pdo->prepare('UPDATE rencana_diet SET tanggal_mulai = ? WHERE id = ?')->execute([$tanggal, $aktif['id']]);
            $aktif['tanggal_mulai'] = $tanggal;
        }

        // Berat sebelumnya (untuk cek laju perubahan)
        $q = $pdo->prepare('SELECT berat_kg, tanggal FROM catatan_berat WHERE user_id = ? AND rencana_id = ? AND tanggal < ? ORDER BY tanggal DESC LIMIT 1');
        $q->execute([$uid, $aktif['id'], $tanggal]);
        $prev = $q->fetch();

        // Satu catatan per tanggal: perbarui kalau sudah ada
        $q = $pdo->prepare('SELECT id FROM catatan_berat WHERE user_id = ? AND rencana_id = ? AND tanggal = ?');
        $q->execute([$uid, $aktif['id'], $tanggal]);
        if ($ada = $q->fetch()) {
            $pdo->prepare('UPDATE catatan_berat SET berat_kg = ?, catatan = ? WHERE id = ?')->execute([$berat, $cat ?: null, $ada['id']]);
        } else {
            $pdo->prepare('INSERT INTO catatan_berat (user_id, rencana_id, berat_kg, tanggal, catatan) VALUES (?, ?, ?, ?, ?)')
                ->execute([$uid, $aktif['id'], $berat, $tanggal, $cat ?: null]);
        }

        // Berat terbaru di rencana ini
        $q = $pdo->prepare('SELECT berat_kg FROM catatan_berat WHERE user_id = ? AND rencana_id = ? ORDER BY tanggal DESC, id DESC LIMIT 1');
        $q->execute([$uid, $aktif['id']]);
        $terbaru = (float)$q->fetchColumn();

        $msg = 'Berat ' . nf($berat, 1) . ' kg tersimpan.';

        // Cek laju perubahan (hanya kalau jarak catatan minimal 7 hari)
        if ($prev) {
            $hari = selisih_hari($prev['tanggal'], $tanggal);
            if ($hari >= 7) {
                $per_minggu = ($prev['berat_kg'] - $berat) / ($hari / 7);
                if ($aktif['tujuan'] === 'turun' && $per_minggu > 1.0) {
                    $msg .= ' Peringatan: turunmu sekitar ' . nf($per_minggu, 1) . ' kg per minggu, lebih cepat dari laju aman. Pertimbangkan berkonsultasi dengan ahli gizi.';
                } elseif ($aktif['tujuan'] === 'naik' && -$per_minggu > 0.5) {
                    $msg .= ' Catatan: kenaikanmu sekitar ' . nf(-$per_minggu, 1) . ' kg per minggu, lebih cepat dari laju yang disarankan.';
                }
            }
        }

        // Target tercapai?
        $selesai_rencana = (new DateTime($aktif['tanggal_mulai']))->modify('+' . (int)$aktif['durasi_hari'] . ' days')->format('Y-m-d');
        $tercapai = ($aktif['tujuan'] === 'turun' && $terbaru <= $aktif['berat_target'])
                 || ($aktif['tujuan'] === 'naik'  && $terbaru >= $aktif['berat_target'])
                 || ($aktif['tujuan'] === 'pertahankan' && $hari_ini >= $selesai_rencana && abs($terbaru - $aktif['berat_target']) <= 1.0);

        if ($tercapai) {
            $pdo->prepare("UPDATE rencana_diet SET status = 'selesai' WHERE id = ?")->execute([$aktif['id']]);
            $msg .= ' Selamat, kamu mencapai berat targetmu! Rencana ditandai selesai.';
        } else {
            // Hitung ulang target kalori dengan berat terbaru
            $selesai = (new DateTime($aktif['tanggal_mulai']))->modify('+' . (int)$aktif['durasi_hari'] . ' days')->format('Y-m-d');
            $sisa    = max(14, selisih_hari($hari_ini, $selesai));
            $bmr     = 10 * $terbaru + 6.25 * $aktif['tinggi_cm'] - 5 * $aktif['usia'] + ($aktif['jenis_kelamin'] === 'L' ? 5 : -161);
            $tdee    = $bmr * $aktif['aktivitas'];
            $per_hari = ($aktif['tujuan'] === 'pertahankan') ? 0 : ($aktif['berat_target'] - $terbaru) * 7700 / $sisa;
            $per_hari = max(-1000, min(500, $per_hari));
            $batas   = ($aktif['jenis_kelamin'] === 'L') ? 1500 : 1200;
            $target  = (int)(round(max($tdee + $per_hari, $batas) / 10) * 10);

            if ($target !== (int)$aktif['target_kalori']) {
                $pdo->prepare('UPDATE rencana_diet SET target_kalori = ? WHERE id = ?')->execute([$target, $aktif['id']]);
                $msg .= ' Target kalorimu diperbarui menjadi ' . nf($target) . ' kkal.';
            }
        }
        $_SESSION['pesan'] = $msg;
        header('Location: profil.php');
        exit;
    }
}

// Data tampilan
$catatan = [];
$rencana_lama = [];
if ($aktif) {
    $q = $pdo->prepare('SELECT * FROM catatan_berat WHERE user_id = ? AND rencana_id = ? ORDER BY tanggal, id');
    $q->execute([$uid, $aktif['id']]);
    $catatan = $q->fetchAll();
}
$q = $pdo->prepare("SELECT * FROM rencana_diet WHERE user_id = ? AND status <> 'aktif' ORDER BY id DESC LIMIT 5");
$q->execute([$uid]);
$rencana_lama = $q->fetchAll();

$durasi = 0;
$hari_ke = 0;
$berat_skrg = 0;
$status = ['Belum ada catatan timbangan', '#888'];
$capai = 0;
$titik = [];
$max_grafik = 30;

if ($aktif) {
    $durasi     = (int)$aktif['durasi_hari'];
    $lewat      = max(0, min($durasi, selisih_hari($aktif['tanggal_mulai'], $hari_ini)));
    $hari_ke    = min($durasi, $lewat + 1);
    $berat_skrg = $catatan ? (float)end($catatan)['berat_kg'] : (float)$aktif['berat_awal'];
    $ideal      = $aktif['berat_awal'] + ($aktif['berat_target'] - $aktif['berat_awal']) * $lewat / $durasi;
    $beda       = $berat_skrg - $ideal;   // positif = lebih berat dari jalur ideal
    $sel_total  = $aktif['berat_target'] - $aktif['berat_awal'];
    $capai      = $sel_total != 0 ? max(0, min(100, (int)round(($berat_skrg - $aktif['berat_awal']) / $sel_total * 100))) : 0;

    if (!$catatan) {
        $status = ['Belum ada catatan timbangan', '#888'];
    } elseif ($aktif['tujuan'] === 'pertahankan') {
        $status = abs($beda) <= 1 ? ['Sesuai jalur', 'var(--normal)'] : ['Menyimpang dari berat target', 'var(--gemuk)'];
    } else {
        $arah = ($aktif['tujuan'] === 'turun') ? -$beda : $beda;   // positif = lebih maju dari jalur
        if ($arah > 0.5)       $status = ['Lebih cepat dari rencana', 'var(--kurus)'];
        elseif ($arah >= -0.5) $status = ['Sesuai jalur', 'var(--normal)'];
        else                   $status = ['Tertinggal dari jalur', 'var(--gemuk)'];
    }

    if ($catatan) {
        foreach ($catatan as $c) {
            $titik[] = ['x' => max(0, selisih_hari($aktif['tanggal_mulai'], $c['tanggal'])), 'y' => (float)$c['berat_kg']];
        }
    } else {
        $titik[] = ['x' => 0, 'y' => (float)$aktif['berat_awal']];
    }

    $max_hari_titik = $titik ? max(array_column($titik, 'x')) : 0;
    $max_grafik = max($durasi, $max_hari_titik, $hari_ke);
    $max_grafik = (int)(ceil($max_grafik / 5) * 5);
}

$judul = 'Profil dan Perkembangan';
include BASE_DIR . 'includes/header.php';
?>

<div class="profil-page-header">
  <div class="profil-user-card">
    <div class="profil-avatar">
      <?= mb_strtoupper(mb_substr($user['nama'], 0, 1)) ?>
    </div>
    <div class="profil-user-info">
      <h2><?= h($user['nama']) ?></h2>
      <p class="teks-redup"><?= h($user['email']) ?>
        <?php if ($aktif): ?> &middot; Tinggi <?= nf($aktif['tinggi_cm']) ?> cm &middot; Usia <?= (int)$aktif['usia'] ?> th<?php endif; ?>
      </p>
    </div>
  </div>
</div>

<?php if ($pesan): ?><div class="alert sukses"><?= h($pesan) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

<?php if (!$aktif): ?>
  <div class="kosong-state">
    <div class="kosong-state-ikon">📊</div>
    <h3>Belum ada rencana aktif</h3>
    <p>Kamu belum punya rencana diet yang aktif, jadi belum ada perkembangan yang bisa dipantau.</p>
    <a class="tombol" href="rencana.php">Buat rencana diet &rarr;</a>
  </div>

<?php else: ?>
  <!-- Status rencana -->
  <div class="profil-status-bar">
    <div class="profil-status-label">
      <span class="profil-status-tujuan"><?= h($label[$aktif['tujuan']]) ?></span>
      <span class="teks-redup" style="font-size:0.85rem;">Hari ke-<?= $hari_ke ?> dari <?= $durasi ?> hari</span>
    </div>
    <span class="badge profil-status-badge" style="background:<?= h($status[1]) ?>"><?= h($status[0]) ?></span>
  </div>

  <!-- Stat cards berat -->
  <div class="profil-stat-grid">
    <div class="profil-stat-card">
      <div class="profil-stat-ikon">⚖️</div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Berat Awal</span>
        <span class="profil-stat-nilai"><?= nf($aktif['berat_awal'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="profil-stat-card profil-stat-current">
      <div class="profil-stat-ikon">📍</div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Berat Sekarang</span>
        <span class="profil-stat-nilai"><?= nf($berat_skrg, 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="profil-stat-card">
      <div class="profil-stat-ikon">🎯</div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Berat Target</span>
        <span class="profil-stat-nilai"><?= nf($aktif['berat_target'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="profil-stat-card">
      <div class="profil-stat-ikon">🔥</div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Target Kalori</span>
        <span class="profil-stat-nilai"><?= nf($aktif['target_kalori']) ?> <small>kkal</small></span>
      </div>
    </div>
  </div>

  <?php if ($aktif['tujuan'] !== 'pertahankan'): ?>
    <div class="profil-progress-section">
      <div class="profil-progress-label">
        <span>Progres menuju target</span>
        <strong><?= $capai ?>%</strong>
      </div>
      <div class="progres" style="margin-top:6px"><div class="progres-isi" style="width:<?= $capai ?>%"></div></div>
    </div>
  <?php endif; ?>

  <div class="profil-two-col">

    <!-- Kolom kiri: Grafik + Form catat -->
    <div class="profil-col-main">
      <div class="kartu profil-grafik-card">
        <h3 style="margin-bottom:14px;">📈 Grafik Berat Badan</h3>
        <canvas id="grafik" height="130"></canvas>
        <p class="catatan" style="margin-top:10px;">Garis putus-putus adalah jalur ideal dari berat awal ke target.</p>
      </div>

      <div class="kartu profil-form-card">
        <h3 style="margin-bottom:4px;">+ Catat Timbangan</h3>
        <p class="teks-redup" style="font-size:0.85rem; margin-bottom:16px;">Timbang secara rutin 1&times; seminggu pada hari & jam yang sama.</p>
        <form method="post">
          <input type="hidden" name="aksi" value="catat">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <div class="form-row-2">
            <div>
              <label for="tanggal">Tanggal</label>
              <input type="date" id="tanggal" name="tanggal" value="<?= h($hari_ini) ?>" max="<?= h($hari_ini) ?>" required>
            </div>
            <div>
              <label for="berat-profil">Berat (kg)</label>
              <div class="input-unit-wrapper">
                <input type="number" id="berat-profil" name="berat" step="0.1" min="20" max="250" placeholder="Contoh: 70.5" required>
                <span class="unit-suffix">kg</span>
              </div>
            </div>
          </div>
          <div style="margin-top:14px;">
            <label for="catatan-profil">Catatan <span style="font-weight:400; color:var(--teks-redup)">(opsional)</span></label>
            <input type="text" id="catatan-profil" name="catatan" maxlength="255" placeholder="Misal: minggu ini banyak olahraga">
          </div>
          <button type="submit" class="tombol penuh" style="margin-top:16px;">Simpan catatan berat</button>
        </form>
      </div>
    </div>

    <!-- Kolom kanan: Riwayat berat -->
    <div class="profil-col-side">
      <div class="kartu profil-riwayat-card">
        <h3 style="margin-bottom:14px;">📋 Riwayat Timbangan</h3>
        <?php
        $sebelum = null;
        $baris = [];
        foreach ($catatan as $c) { $baris[] = [$c, $sebelum]; $sebelum = (float)$c['berat_kg']; }
        if (empty($baris)): ?>
          <div class="profil-riwayat-kosong">
            <div style="font-size:2rem; margin-bottom:8px;">⚖️</div>
            <p>Belum ada catatan timbangan.</p>
            <p class="teks-redup" style="font-size:0.85rem;">Catat berat badanmu di formulir sebelah kiri.</p>
          </div>
        <?php else: ?>
          <div class="profil-riwayat-list">
            <?php foreach (array_reverse($baris) as [$c, $sb]):
              $selisih = $sb === null ? null : (float)$c['berat_kg'] - $sb;
              $selisih_teks = $selisih === null ? '' : (($selisih > 0 ? '+' : '') . nf($selisih, 1) . ' kg');
              $selisih_kelas = $selisih === null ? '' : ($selisih < 0 ? 'turun' : ($selisih > 0 ? 'naik' : 'sama'));
            ?>
              <div class="profil-riwayat-baris">
                <div class="profil-riwayat-tanggal"><?= h(tgl($c['tanggal'])) ?></div>
                <div class="profil-riwayat-berat"><?= nf($c['berat_kg'], 1) ?> kg</div>
                <?php if ($selisih_teks): ?>
                  <div class="profil-riwayat-selisih <?= $selisih_kelas ?>"><?= $selisih_teks ?></div>
                <?php else: ?>
                  <div class="profil-riwayat-selisih">—</div>
                <?php endif; ?>
                <?php if ($c['catatan']): ?>
                  <div class="profil-riwayat-catatan teks-redup" style="font-size:0.8rem;"><?= h($c['catatan']) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    new Chart(document.getElementById('grafik'), {
      type: 'line',
      data: { datasets: [
        { label: 'Berat kamu (kg)', data: <?= json_encode($titik) ?>, borderColor: '#1F8A5B', backgroundColor: 'rgba(31,138,91,0.08)', fill: true, tension: .25, pointRadius: 5, pointBackgroundColor: '#1F8A5B' },
        { label: 'Jalur ideal', data: [{x:0, y:<?= (float)$aktif['berat_awal'] ?>}, {x:<?= $durasi ?>, y:<?= (float)$aktif['berat_target'] ?>}],
          borderColor: '#F5C542', borderDash: [6,6], pointRadius: 0 }
      ]},
      options: { responsive: true, scales: {
        x: { type: 'linear', min: 0, max: <?= $max_grafik ?>, ticks: { stepSize: 5 }, title: { display: true, text: 'Hari ke-' } },
        y: { title: { display: true, text: 'kg' } }
      }, plugins: { legend: { position: 'bottom', labels: { font: { size: 12 } } } } }
    });
  </script>
<?php endif; ?>

<?php if ($rencana_lama): ?>
  <div style="margin-top:32px;">
    <h3 style="margin-bottom:12px;">Rencana Sebelumnya</h3>
    <div style="display:flex; flex-direction:column; gap:8px;">
      <?php foreach ($rencana_lama as $r): ?>
        <div class="profil-rencana-lama-baris">
          <div>
            <strong><?= h($label[$r['tujuan']]) ?></strong>
            <div class="teks-redup" style="font-size:0.83rem; margin-top:2px;"><?= h(tgl($r['tanggal_mulai'])) ?> &middot; <?= nf($r['berat_awal'], 1) ?> &rarr; <?= nf($r['berat_target'], 1) ?> kg &middot; <?= (int)$r['durasi_hari'] ?> hari</div>
          </div>
          <span class="badge" style="background:<?= $r['status'] === 'selesai' ? 'var(--normal)' : '#888' ?>; flex-shrink:0;"><?= h(ucfirst($r['status'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<p class="catatan" style="margin-top:20px;">Perhitungan bersifat perkiraan dan edukatif, bukan pengganti saran dokter atau ahli gizi.</p>

<?php include BASE_DIR . 'includes/footer.php'; ?>

