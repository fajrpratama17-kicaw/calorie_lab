<?php
require_once __DIR__ . '/config/koneksi.php';
wajib_login();

$uid = $_SESSION['user_id'];
$hari_ini = date('Y-m-d');

// Rencana diet yang sedang aktif (kalau ada)
$st = $pdo->prepare("SELECT * FROM rencana_diet WHERE user_id = ? AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$st->execute([$uid]);
$rencana = $st->fetch(PDO::FETCH_ASSOC);

// Hasil BMI terakhir (kalau ada)
$st = $pdo->prepare("SELECT r.bmi, r.target_kalori, r.kategori_id, k.nama AS kategori
                     FROM riwayat_bmi r
                     JOIN kategori_bmi k ON k.id = r.kategori_id
                     WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1");
$st->execute([$uid]);
$bmi = $st->fetch(PDO::FETCH_ASSOC);

// Hitung jumlah rekomendasi menu yang cocok
$total_menu_sesuai = 0;
if (!empty($bmi['kategori_id'])) {
    $st_m = $pdo->prepare("SELECT COUNT(DISTINCT menu_id) FROM menu_kategori WHERE kategori_id = ?");
    $st_m->execute([$bmi['kategori_id']]);
    $total_menu_sesuai = (int)$st_m->fetchColumn();
}
if ($total_menu_sesuai === 0) {
    try {
        $total_menu_sesuai = (int)$pdo->query("SELECT COUNT(*) FROM menu_diet")->fetchColumn();
    } catch (Exception $e) {
        $total_menu_sesuai = 0;
    }
}

// Berat terakhir yang dicatat pada rencana aktif
$berat_terakhir = null;
if ($rencana) {
    $st = $pdo->prepare("SELECT berat_kg FROM catatan_berat WHERE user_id = ? AND rencana_id = ? ORDER BY tanggal DESC, id DESC LIMIT 1");
    $st->execute([$uid, $rencana['id']]);
    $berat_terakhir = $st->fetchColumn();
    if ($berat_terakhir === false) {
        $berat_terakhir = $rencana['berat_awal'];
    }
}

// Target kalori: utamakan rencana aktif, kalau tidak ada pakai BMI
$target_kalori = $rencana['target_kalori'] ?? ($bmi['target_kalori'] ?? null);

// Saran dari pakar Ã¢â‚¬â€ ambil yang relevan dengan kategori BMI user
// Cek notifikasi saran personal dari pakar untuk banner ringkas di dashboard
$saran_personal_baru = null;
try {
    $st_sp = $pdo->prepare("
        SELECT sp.id, sp.judul, u.nama AS nama_pakar
        FROM saran_pakar sp
        JOIN users u ON u.id = sp.pakar_id
        WHERE sp.user_id = ?
        ORDER BY sp.id DESC
        LIMIT 1
    ");
    $st_sp->execute([$uid]);
    $saran_personal_baru = $st_sp->fetch();
} catch (Exception $e) {}

$st = $pdo->prepare('SELECT COUNT(*) FROM catatan_makan WHERE user_id = ? AND tanggal = ?');
$st->execute([$uid, $hari_ini]);
$jumlah_makanan_hari_ini = (int)$st->fetchColumn();

// Hitung total kalori yang dikonsumsi hari ini dari catatan_makan
$st_kal = $pdo->prepare('
    SELECT COALESCE(SUM(md.kalori * cm.porsi), 0)
    FROM catatan_makan cm
    JOIN menu_diet md ON md.id = cm.menu_id
    WHERE cm.user_id = ? AND cm.tanggal = ?
');
$st_kal->execute([$uid, $hari_ini]);
$kalori_tercatat_hari_ini = (int)round((float)$st_kal->fetchColumn());

$sisa_kalori = $target_kalori ? max(0, (int)$target_kalori - $kalori_tercatat_hari_ini) : null;
$persen_kalori = ($target_kalori && $target_kalori > 0) ? min(100, round(($kalori_tercatat_hari_ini / $target_kalori) * 100)) : 0;

$hari_ke = 0;
$rencana_selesai = false;
if ($rencana) {
    $tgl_mulai = new DateTime($rencana['tanggal_mulai']);
    $tgl_sekarang = new DateTime($hari_ini);
    $durasi = max(1, (int)$rencana['durasi_hari']);
    $selesai_rencana = (clone $tgl_mulai)->modify('+' . $durasi . ' days')->format('Y-m-d');
    $rencana_selesai = $hari_ini >= $selesai_rencana;
    
    $diff = $tgl_mulai->diff($tgl_sekarang);
    $hari_ke = max(1, min($durasi, $diff->invert ? 1 : ($diff->days + 1)));
}

// Sapaan waktu dinamis
$jam = (int)date('H');
if ($jam >= 4 && $jam < 11) {
    $sapaan = 'Selamat Pagi';
    $ikon_waktu = 'pagi';
} elseif ($jam >= 11 && $jam < 15) {
    $sapaan = 'Selamat Siang';
    $ikon_waktu = 'siang';
} elseif ($jam >= 15 && $jam < 18) {
    $sapaan = 'Selamat Sore';
    $ikon_waktu = 'sore';
} else {
    $sapaan = 'Selamat Malam';
    $ikon_waktu = 'malam';
}

// Kategori warna BMI klinis
$bmi_badge_class = 'normal';
if ($bmi) {
    $kat_raw = strtolower($bmi['kategori'] ?? '');
    if (strpos($kat_raw, 'kurus') !== false) {
        $bmi_badge_class = 'kurus';
    } elseif (strpos($kat_raw, 'gemuk') !== false || strpos($kat_raw, 'lebih') !== false) {
        $bmi_badge_class = 'gemuk';
    } elseif (strpos($kat_raw, 'obesitas') !== false) {
        $bmi_badge_class = 'obesitas';
    }
}

if (!$bmi) {
    $langkah = [
        'judul' => 'Mulai dengan menghitung BMI',
        'deskripsi' => 'Isi data dasar untuk melihat ringkasan dan target kalori perkiraan.',
        'tautan' => 'bmi.php',
        'tombol' => 'Hitung BMI',
    ];
} elseif (!$rencana) {
    $langkah = [
        'judul' => 'Tentukan rencana dietmu',
        'deskripsi' => 'Pilih tujuan dan durasi agar perkembanganmu punya target yang jelas.',
        'tautan' => 'rencana.php',
        'tombol' => 'Buat rencana',
    ];
} elseif ($rencana_selesai) {
    $langkah = [
        'judul' => 'Masa rencana diet telah selesai',
        'deskripsi' => 'Program dietmu telah mencapai target durasi. Selesaikan rencana ini untuk menyimpan riwayatnya atau buat rencana baru.',
        'tautan' => 'rencana.php',
        'tombol' => 'Tinjau rencana',
    ];
} elseif ($jumlah_makanan_hari_ini === 0) {
    $langkah = [
        'judul' => 'Catat makanan hari ini',
        'deskripsi' => 'Mulai catat makanan agar ringkasan kalori harianmu terisi.',
        'tautan' => 'catatan.php',
        'tombol' => 'Buka catatan kalori',
    ];
} else {
    $langkah = [
        'judul' => 'Lanjutkan memantau progres',
        'deskripsi' => 'Kamu sudah mencatat makanan hari ini. Periksa perkembangan berat secara berkala.',
        'tautan' => 'profil.php',
        'tombol' => 'Lihat progres',
    ];
}

$label_tujuan = ['turun' => 'Menurunkan berat', 'naik' => 'Menaikkan berat', 'pertahankan' => 'Mempertahankan berat'];

$judul = 'Dashboard';
include BASE_DIR . 'includes/header.php';
?>

<header class="dashboard-heading">
  <div>
    <p class="dashboard-eyebrow">
      <?php
      $svg_waktu = [
        'pagi'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        'siang' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        'sore'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px"><path d="M17 18a5 5 0 0 0-10 0"/><line x1="12" y1="9" x2="12" y2="2"/><line x1="4.22" y1="10.22" x2="5.64" y2="11.64"/><line x1="1" y1="18" x2="3" y2="18"/><line x1="21" y1="18" x2="23" y2="18"/><line x1="18.36" y1="11.64" x2="19.78" y2="10.22"/><line x1="23" y1="22" x2="1" y2="22"/><polyline points="16 5 12 9 8 5"/></svg>',
        'malam' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
      ];
      echo $svg_waktu[$ikon_waktu] ?? '';
      ?>
      <?= $sapaan ?>, <?= h($_SESSION['nama'] ?? 'Pengguna') ?>
    </p>
    <h2>Dashboard Kesehatan</h2>
    <p class="teks-redup">Pantau kuota kalori, progres berat badan, dan bimbingan nutrisi harianmu.</p>
  </div>
</header>

<?php if (!empty($saran_personal_baru)): ?>
  <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 12px; padding: 12px 18px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 10px;">
      <span class="badge" style="background: var(--mint); color: #264A38; font-weight: 800; font-size: 0.72rem; padding: 3px 10px;">Pesan Pakar</span>
      <span style="font-size: 0.88rem; color: #166534; font-weight: 600;">
        Ada bimbingan nutrisi personal untuk Anda: &ldquo;<strong><?= h($saran_personal_baru['judul']) ?></strong>&rdquo;
      </span>
    </div>
    <a href="<?= base_url('profil.php?tab=saran') ?>" class="tombol kecil" style="padding: 6px 14px; font-size: 0.8rem; font-weight: 700; border-radius: var(--radius-pil);">
      Buka di Profil &rarr;
    </a>
  </div>
<?php endif; ?>

<section aria-labelledby="ringkasan-judul">
  <div class="dashboard-section-heading">
    <div>
      <h3 id="ringkasan-judul">Ringkasan Nutrisi & Target Hari Ini</h3>
      <p class="teks-redup">Data akurat dan terpantau secara konsisten berdasarkan standar nutrisi.</p>
    </div>
  </div>

  <div class="dashboard-ringkasan-4">
    <!-- 1. Target Kalori Harian -->
    <article class="dashboard-stat dashboard-stat-highlight">
      <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
        <span class="dashboard-stat-label">Target Kalori Harian</span>
        <span class="stat-icon-wrapper" title="Target Kalori" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        </span>
      </div>
      <?php if ($target_kalori): ?>
        <strong class="dashboard-stat-value"><?= number_format((float)$target_kalori, 0, ',', '.') ?> <span style="font-size:0.95rem; font-weight:600; color:var(--teks-redup);">kkal</span></strong>
        
        <div class="progress-bar-bg" title="Progres kalori: <?= $persen_kalori ?>%">
          <div class="progress-bar-fill" style="width: <?= $persen_kalori ?>%;"></div>
        </div>
        
        <span class="dashboard-stat-note" style="margin-top:4px;">
          Tercatat: <strong><?= number_format($kalori_tercatat_hari_ini, 0, ',', '.') ?></strong> &middot; Sisa: <strong><?= number_format($sisa_kalori, 0, ',', '.') ?></strong> kkal
        </span>
        <a class="dashboard-stat-link" href="catatan.php">Catat asupan <span aria-hidden="true">&rarr;</span></a>
      <?php else: ?>
        <strong class="dashboard-stat-empty">Belum tersedia</strong>
        <span class="dashboard-stat-note">Hitung BMI untuk mendapatkan kuota target kalori.</span>
        <a class="dashboard-stat-link" href="bmi.php">Hitung BMI Sekarang <span aria-hidden="true">&rarr;</span></a>
      <?php endif; ?>
    </article>

    <!-- 2. Status BMI Terkini -->
    <article class="dashboard-stat">
      <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
        <span class="dashboard-stat-label">Status BMI Terkini</span>
        <span class="stat-icon-wrapper" title="Indeks Massa Tubuh" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><line x1="12" y1="8" x2="12" y2="21"/><path d="M5 21h14"/><path d="M5 12H2l3-3 3 3"/><path d="M19 12h3l-3-3-3 3"/></svg>
        </span>
      </div>
      <?php if ($bmi): ?>
        <strong class="dashboard-stat-value"><?= h(number_format((float)$bmi['bmi'], 1, ',', '.')) ?></strong>
        <div>
          <span class="badge-bmi <?= $bmi_badge_class ?>"><?= h($bmi['kategori']) ?></span>
        </div>
        <span class="dashboard-stat-note" style="margin-top:8px;">
          Berat terakhir: <strong><?= $berat_terakhir ? number_format((float)$berat_terakhir, 1, ',', '.') . ' kg' : '-' ?></strong>
        </span>
        <a class="dashboard-stat-link" href="bmi.php">Ukur ulang BMI <span aria-hidden="true">&rarr;</span></a>
      <?php else: ?>
        <strong class="dashboard-stat-empty">Belum dihitung</strong>
        <span class="dashboard-stat-note">Ketahui rasio berat & tinggi badanmu.</span>
        <a class="dashboard-stat-link" href="bmi.php">Hitung BMI <span aria-hidden="true">&rarr;</span></a>
      <?php endif; ?>
    </article>

    <!-- 3. Rencana Diet Aktif -->
    <article class="dashboard-stat">
      <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
        <span class="dashboard-stat-label">Program Diet Aktif</span>
        <span class="stat-icon-wrapper" title="Rencana Diet" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
        </span>
      </div>
      <?php if ($rencana): ?>
        <strong class="dashboard-stat-plan" style="margin-top:8px;"><?= h($label_tujuan[$rencana['tujuan']] ?? $rencana['tujuan']) ?></strong>
        <span class="dashboard-stat-note" style="margin-top:8px;">
          <strong><?= h(number_format((float)$berat_terakhir, 1, ',', '.')) ?> kg</strong> kini <span aria-hidden="true">&rarr;</span>
          <strong><?= h(number_format((float)$rencana['berat_target'], 1, ',', '.')) ?> kg</strong> target
        </span>
        <span class="dashboard-stat-note" style="font-size:0.82rem; color:var(--hijau); font-weight:650;">
          Hari ke-<?= $hari_ke ?> dari <?= (int)$rencana['durasi_hari'] ?> hari
        </span>
        <a class="dashboard-stat-link" href="rencana.php">Detail rencana <span aria-hidden="true">&rarr;</span></a>
      <?php else: ?>
        <strong class="dashboard-stat-empty">Belum ada rencana</strong>
        <span class="dashboard-stat-note">Atur target berat badan dan timeline dietmu.</span>
        <a class="dashboard-stat-link" href="rencana.php">Buat Rencana <span aria-hidden="true">&rarr;</span></a>
      <?php endif; ?>
    </article>

    <!-- 4. Menu Rekomendasi Diet -->
    <article class="dashboard-stat">
      <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
        <span class="dashboard-stat-label">Rekomendasi Menu</span>
        <span class="stat-icon-wrapper" title="Menu Rekomendasi" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
        </span>
      </div>
      <strong class="dashboard-stat-value"><?= $total_menu_sesuai ?> <span style="font-size:0.95rem; font-weight:600; color:var(--teks-redup);">pilihan</span></strong>
      <span class="dashboard-stat-note">
        <?= $bmi ? 'Resep gizi seimbang cocok untuk BMI ' . h($bmi['kategori']) . '.' : 'Pilihan makanan bergizi seimbang.' ?>
      </span>
      <a class="dashboard-stat-link" href="menu.php">Pilih rekomendasi <span aria-hidden="true">&rarr;</span></a>
    </article>
  </div>
</section>

<section class="langkah-berikutnya" aria-labelledby="judul-langkah">
  <div>
    <small>LANGKAH BERIKUTNYA</small>
    <h3 id="judul-langkah"><?= h($langkah['judul']) ?></h3>
    <p><?= h($langkah['deskripsi']) ?></p>
  </div>
  <a class="tombol" href="<?= h($langkah['tautan']) ?>"><?= h($langkah['tombol']) ?></a>
</section>

<?php include BASE_DIR . 'includes/footer.php'; ?>


