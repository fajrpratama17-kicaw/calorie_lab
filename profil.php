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

// ── Tab Aktif di Profil: 'progres' (default) atau 'saran' ────────────────
$tab_profil = $_GET['tab'] ?? 'progres';
if (!in_array($tab_profil, ['progres', 'saran'], true)) {
    $tab_profil = 'progres';
}

// Ambil info BMI pengguna untuk rekomendasi kategori
$st_bmi = $pdo->prepare("
    SELECT rb.bmi, kb.nama AS kat_nama, kb.kode AS kat_kode 
    FROM riwayat_bmi rb 
    LEFT JOIN kategori_bmi kb ON kb.id = rb.kategori_id 
    WHERE rb.user_id = ? 
    ORDER BY rb.id DESC LIMIT 1
");
$st_bmi->execute([$uid]);
$bmi_info = $st_bmi->fetch();
$kode_bmi_user = $bmi_info['kat_kode'] ?? '';

// Ambil seluruh saran untuk pengguna ini (personal dan kategori yang cocok)
$daftar_saran_user = [];
try {
    $st_saran = $pdo->prepare("
        SELECT sp.*, u.nama AS nama_pakar, u.gelar AS gelar_pakar
        FROM saran_pakar sp
        JOIN users u ON u.id = sp.pakar_id
        WHERE sp.user_id = ? OR (sp.user_id IS NULL AND sp.target_kategori IN ('semua', ?))
        ORDER BY (sp.user_id = ?) DESC, sp.created_at DESC
    ");
    $st_saran->execute([$uid, $kode_bmi_user, $uid]);
    $daftar_saran_user = $st_saran->fetchAll();
} catch (Exception $e) {}
$total_saran_user = count($daftar_saran_user);

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
$q = $pdo->prepare("SELECT * FROM rencana_diet WHERE user_id = ? AND status <> 'aktif' ORDER BY id DESC LIMIT 20");
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

<div class="page-hero-header">
  <div class="page-hero-icon">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
  </div>
  <div class="page-hero-teks">
    <h2>Profil &amp; Progres</h2>
    <p><?= h($user['nama']) ?> &middot; <?= h($user['email']) ?><?php if ($aktif): ?> &middot; <?= nf($aktif['tinggi_cm']) ?> cm &middot; <?= (int)$aktif['usia'] ?> tahun<?php endif; ?></p>
  </div>
  <div style="margin-left:auto; flex-shrink:0;">
    <div style="width:48px;height:48px;border-radius:50%;background:var(--hijau);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.35rem;font-weight:800;letter-spacing:-0.03em;">
      <?= mb_strtoupper(mb_substr($user['nama'], 0, 1)) ?>
    </div>
  </div>
</div>

<!-- DUAL-TAB PROFIL: PROGRES & SARAN PAKAR -->
<div style="display: flex; gap: 10px; margin-bottom: 22px; border-bottom: 2px solid var(--garis); padding-bottom: 14px; flex-wrap: wrap; align-items: center;">
  <a href="<?= base_url('profil.php?tab=progres') ?>" 
     class="tombol <?= $tab_profil === 'progres' ? '' : 'sekunder' ?>" 
     style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; border-radius: var(--radius-pil); padding: 8px 18px; font-size: 0.9rem;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Progres &amp; Timbangan
  </a>
  <a href="<?= base_url('profil.php?tab=saran') ?>" 
     class="tombol <?= $tab_profil === 'saran' ? '' : 'sekunder' ?>" 
     style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; border-radius: var(--radius-pil); padding: 8px 18px; font-size: 0.9rem;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    Saran &amp; Catatan Pakar Gizi (<?= $total_saran_user ?>)
  </a>
</div>

<?php if ($tab_profil === 'progres'): ?>
<?php if ($pesan): ?><div class="alert sukses"><?= h($pesan) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

<?php if (!$aktif): ?>
  <div class="kosong-state">
    <div class="kosong-state-ikon">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
    </div>
    <h3>Belum ada rencana aktif</h3>
    <p>Kamu belum punya rencana diet yang aktif, jadi belum ada perkembangan yang bisa dipantau.</p>
    <a class="tombol" href="rencana.php">Buat rencana diet &rarr;</a>
  </div>

<?php else: ?>
  <!-- Status rencana terintegrasi dengan Progress Bar (Solid White Card) -->
  <div class="kartu profil-status-bar" style="flex-direction:column; align-items:stretch; gap:14px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <div class="profil-status-label">
        <span class="profil-status-tujuan"><?= h($label[$aktif['tujuan']]) ?></span>
        <span class="teks-redup" style="font-size:0.85rem;">Hari ke-<?= $hari_ke ?> dari <?= $durasi ?> hari</span>
      </div>
      <span class="badge profil-status-badge" style="background:<?= h($status[1]) ?>"><?= h($status[0]) ?></span>
    </div>

    <?php if ($aktif['tujuan'] !== 'pertahankan'): ?>
      <div style="border-top:1px solid var(--garis); padding-top:12px;">
        <div class="profil-progress-label" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
          <span style="font-size:0.82rem; font-weight:600; color:var(--teks-redup);">Progres menuju berat target</span>
          <strong style="color:var(--hijau); font-size:0.88rem;"><?= $capai ?>%</strong>
        </div>
        <div class="progres" style="height:8px; margin:0;"><div class="progres-isi" style="width:<?= $capai ?>%"></div></div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Stat cards berat -->
  <div class="profil-stat-grid">
    <div class="profil-stat-card">
      <div class="profil-stat-ikon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
      </div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Berat Awal</span>
        <span class="profil-stat-nilai"><?= nf($aktif['berat_awal'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="profil-stat-card profil-stat-current">
      <div class="profil-stat-ikon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      </div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Berat Sekarang</span>
        <span class="profil-stat-nilai"><?= nf($berat_skrg, 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="profil-stat-card">
      <div class="profil-stat-ikon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
      </div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Berat Target</span>
        <span class="profil-stat-nilai"><?= nf($aktif['berat_target'], 1) ?> <small>kg</small></span>
      </div>
    </div>
    <div class="profil-stat-card">
      <div class="profil-stat-ikon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
      </div>
      <div class="profil-stat-isi">
        <span class="profil-stat-label">Target Kalori</span>
        <span class="profil-stat-nilai"><?= nf($aktif['target_kalori']) ?> <small>kkal</small></span>
      </div>
    </div>
  </div>

  <div class="profil-two-col">

    <!-- Kolom kiri: Grafik + Form catat -->
    <div class="profil-col-main">
      <div class="kartu profil-grafik-card">
        <h3 style="margin-bottom:14px;">Grafik Berat Badan</h3>
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

    <!-- Kolom kanan: Riwayat Timbangan + Rencana Sebelumnya -->
    <div class="profil-col-side" style="display:flex; flex-direction:column; gap:20px;">
      
      <!-- Card 1: Riwayat Timbangan -->
      <div class="kartu profil-riwayat-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
          <h3 style="margin:0; font-size:1.05rem; display:flex; align-items:center; gap:6px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            Riwayat Timbangan
          </h3>
        </div>

        <?php
        $sebelum = null;
        $baris = [];
        foreach ($catatan as $c) { $baris[] = [$c, $sebelum]; $sebelum = (float)$c['berat_kg']; }
        if (empty($baris)): ?>
          <div class="profil-riwayat-kosong">
            <div style="display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:14px;background:var(--mint);color:var(--hijau);margin:0 auto 8px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><line x1="12" y1="8" x2="12" y2="21"/><path d="M5 21h14"/><path d="M5 12H2l3-3 3 3"/><path d="M19 12h3l-3-3-3 3"/></svg>
            </div>
            <p>Belum ada catatan timbangan.</p>
            <p class="teks-redup" style="font-size:0.85rem;">Catat berat badanmu di formulir sebelah kiri.</p>
          </div>
        <?php else: ?>
          <div class="profil-riwayat-list" id="riwayat-timbang-list">
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
          <div id="riwayat-timbang-pagin"></div>
        <?php endif; ?>
      </div>

      <!-- Card 2: Rencana Sebelumnya (Dipindahkan ke sini agar simetris) -->
      <div class="kartu profil-rencana-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
          <h3 style="margin:0; font-size:1.05rem; display:flex; align-items:center; gap:6px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau);"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
            Rencana Sebelumnya
          </h3>
        </div>

        <?php if (empty($rencana_lama)): ?>
          <div style="text-align:center; padding:20px 10px; color:var(--teks-redup); font-size:0.85rem;">
            Belum ada riwayat rencana diet sebelumnya.
          </div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:8px;" id="rencana-lama-list">
            <?php foreach ($rencana_lama as $r): ?>
              <div class="profil-rencana-lama-baris" style="padding:10px 12px; background:var(--latar); border:1px solid var(--garis); border-radius:var(--radius-kecil);">
                <div>
                  <strong style="font-size:0.88rem; color:var(--gelap); display:block;"><?= h($label[$r['tujuan']]) ?></strong>
                  <div class="teks-redup" style="font-size:0.78rem; margin-top:2px;">
                    <?= h(tgl($r['tanggal_mulai'])) ?> &middot; <?= nf($r['berat_awal'], 1) ?> &rarr; <?= nf($r['berat_target'], 1) ?> kg
                  </div>
                </div>
                <span class="badge" style="background:<?= $r['status'] === 'selesai' ? 'var(--normal)' : '#888' ?>; font-size:0.72rem; padding:3px 8px; flex-shrink:0;">
                  <?= h(ucfirst($r['status'])) ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
          <div id="rencana-lama-pagin"></div>
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

    // Paginasi Instan (Client-Side) tanpa reload
    function pasangPaginasi(listId, paginId, perHalaman) {
      const listEl = document.getElementById(listId);
      const paginEl = document.getElementById(paginId);
      if (!listEl || !paginEl) return;

      const baris = Array.from(listEl.children);
      const total = baris.length;
      if (total <= perHalaman) return;

      let hal = 1;
      const totalHal = Math.ceil(total / perHalaman);

      function tampilkan(h) {
        hal = Math.max(1, Math.min(h, totalHal));
        baris.forEach((el, idx) => {
          el.style.display = (idx >= (hal - 1) * perHalaman && idx < hal * perHalaman) ? '' : 'none';
        });

        paginEl.innerHTML = `
          <div class="profil-pagin-bar">
            <button type="button" class="profil-pagin-btn" ${hal === 1 ? 'disabled' : ''} onclick="window.__p_${listId}(${hal - 1})" title="Sebelumnya">&lsaquo;</button>
            <span class="profil-pagin-info">Hal <strong>${hal}</strong> dari <strong>${totalHal}</strong></span>
            <button type="button" class="profil-pagin-btn" ${hal === totalHal ? 'disabled' : ''} onclick="window.__p_${listId}(${hal + 1})" title="Berikutnya">&rsaquo;</button>
          </div>
        `;
      }

      window['__p_' + listId] = tampilkan;
      tampilkan(1);
    }

    document.addEventListener('DOMContentLoaded', function () {
      pasangPaginasi('riwayat-timbang-list', 'riwayat-timbang-pagin', 5);
      pasangPaginasi('rencana-lama-list', 'rencana-lama-pagin', 3);
    });
  </script>
<?php endif; ?>
<?php else: ?>
  <!-- ══════════════════════════════════════════════════════════
       TAB 2: SARAN & CATATAN PAKAR GIZI
       ══════════════════════════════════════════════════════════ -->
  <div style="margin-bottom: 22px;">
    <h3 style="margin: 0 0 4px; font-size: 1.25rem; font-weight: 800; color: var(--gelap);">Panduan &amp; Saran Edukasi Gizi</h3>
    <p class="teks-redup" style="margin: 0; font-size: 0.88rem;">Rekomendasi nutrisi personal dan panduan klinis dari pakar gizi untuk mendukung progres kesehatan Anda.</p>
  </div>

  <?php if (empty($daftar_saran_user)): ?>
    <div class="kosong-state" style="background: #FFFFFF !important; border: 1px solid var(--garis); padding: 48px 24px; border-radius: var(--radius-kartu);">
      <div class="kosong-state-ikon" style="background: var(--latar); color: var(--teks-redup);">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <h3 style="margin: 14px 0 6px;">Belum Ada Saran Masuk</h3>
      <p style="margin: 0; color: var(--teks-redup); font-size: 0.9rem;">Pakar gizi belum memberikan catatan saran khusus untuk akun Anda saat ini.</p>
    </div>
  <?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; align-items: start;">
      <?php foreach ($daftar_saran_user as $s): 
        $is_personal = !empty($s['user_id']) && (int)$s['user_id'] === (int)$uid;
      ?>
        <div class="kartu" style="padding: 22px; display: flex; flex-direction: column; height: 100%; background: #FFFFFF !important; border: 1px solid var(--garis); border-left: 4px solid <?= $is_personal ? 'var(--hijau)' : '#818CF8' ?>;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 8px;">
            <h4 style="margin: 0; font-size: 1.02rem; font-weight: 800; color: var(--gelap); line-height: 1.4;"><?= h($s['judul']) ?></h4>
            <span style="font-size: 0.74rem; color: var(--teks-redup); white-space: nowrap; flex-shrink: 0; margin-top: 2px;">
              <?= date('d M Y', strtotime($s['created_at'])) ?>
            </span>
          </div>

          <div style="margin-bottom: 12px;">
            <?php if ($is_personal): ?>
              <span class="badge" style="background: var(--mint); color: #264A38; font-weight: 800; font-size: 0.72rem; padding: 3px 10px; border: 1px solid #A7F3D0; display: inline-flex; align-items: center; gap: 4px;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Personal Khusus Anda
              </span>
            <?php elseif ($s['target_kategori'] === 'semua'): ?>
              <span class="badge" style="background: #E0E7FF; color: #3730A3; font-weight: 700; font-size: 0.72rem; padding: 3px 10px;">
                Panduan Gizi Umum
              </span>
            <?php else: ?>
              <span class="badge kategori-<?= h($s['target_kategori']) ?>" style="font-size: 0.72rem; font-weight: 700; padding: 3px 10px;">
                Rekomendasi BMI <?= ucfirst(h($s['target_kategori'])) ?>
              </span>
            <?php endif; ?>
          </div>

          <p style="margin: 0; font-size: 0.9rem; color: var(--gelap); line-height: 1.65; white-space: pre-wrap; flex: 1;"><?= h($s['isi_saran']) ?></p>

          <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--garis); padding-top: 12px; margin-top: 16px;">
            <span style="font-size: 0.78rem; font-weight: 700; color: <?= $is_personal ? 'var(--hijau)' : '#4F46E5' ?>;">
              Oleh: <?= h(($s['gelar_pakar'] ? $s['gelar_pakar'] . ' ' : '') . $s['nama_pakar']) ?>
            </span>
            <span class="badge" style="background: var(--latar); font-size: 0.7rem; color: var(--teks-redup);">
              Pakar Gizi
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<p class="catatan" style="margin-top:20px;">Perhitungan bersifat perkiraan dan edukatif, bukan pengganti saran dokter atau ahli gizi.</p>

<?php include BASE_DIR . 'includes/footer.php'; ?>

