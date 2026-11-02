<?php
require_once __DIR__ . '/config/koneksi.php';
wajib_login();

// Jika admin atau pakar mengakses dashboard pengguna, arahkan langsung ke portalnya masing-masing
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ' . base_url('admin/index.php'));
    exit;
} elseif (($_SESSION['role'] ?? '') === 'pakar') {
    header('Location: ' . base_url('pakar/index.php'));
    exit;
}

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

$st = $pdo->prepare('SELECT mh.id, COUNT(i.id) AS jumlah_menu
                     FROM menu_harian mh
                     LEFT JOIN menu_harian_item i ON i.menu_harian_id = mh.id
                     WHERE mh.user_id = ? AND mh.tanggal = ?
                     GROUP BY mh.id');
$st->execute([$uid, $hari_ini]);
$menu_harian = $st->fetch(PDO::FETCH_ASSOC);

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
$kategori_user = $bmi['kategori'] ?? null;
$kode_kategori = null;
if ($kategori_user) {
    // Normalisasi nama kategori ke kode
    $map_kode = ['Kurus'=>'kurus','Normal'=>'normal','Gemuk'=>'gemuk','Obesitas'=>'obesitas'];
    $kode_kategori = $map_kode[$kategori_user] ?? null;
}
$saran_pakar = [];
try {
    if ($kode_kategori) {
        $st_sp = $pdo->prepare("
            SELECT sp.judul, sp.isi_saran, sp.target_kategori, u.nama AS nama_pakar, sp.created_at
            FROM saran_pakar sp
            JOIN users u ON u.id = sp.pakar_id
            WHERE sp.target_kategori IN ('semua', ?)
            ORDER BY sp.created_at DESC
            LIMIT 3
        ");
        $st_sp->execute([$kode_kategori]);
    } else {
        $st_sp = $pdo->query("
            SELECT sp.judul, sp.isi_saran, sp.target_kategori, u.nama AS nama_pakar, sp.created_at
            FROM saran_pakar sp
            JOIN users u ON u.id = sp.pakar_id
            WHERE sp.target_kategori = 'semua'
            ORDER BY sp.created_at DESC
            LIMIT 3
        ");
    }
    $saran_pakar = $st_sp->fetchAll();
} catch (Exception $e) {
    // Tabel belum ada, abaikan
}

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
    $ikon_waktu = '🌅';
} elseif ($jam >= 11 && $jam < 15) {
    $sapaan = 'Selamat Siang';
    $ikon_waktu = '☀️';
} elseif ($jam >= 15 && $jam < 18) {
    $sapaan = 'Selamat Sore';
    $ikon_waktu = '🌇';
} else {
    $sapaan = 'Selamat Malam';
    $ikon_waktu = '🌙';
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
    <p class="dashboard-eyebrow"><?= $ikon_waktu ?> <?= $sapaan ?>, <?= h($_SESSION['nama'] ?? 'Pengguna') ?></p>
    <h2>Dashboard Kesehatan</h2>
    <p class="teks-redup">Pantau kuota kalori, progres berat badan, dan bimbingan nutrisi harianmu.</p>
  </div>
</header>

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
        <span class="stat-icon-wrapper" title="Target Kalori" aria-hidden="true">🔥</span>
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
        <span class="stat-icon-wrapper" title="Indeks Massa Tubuh" aria-hidden="true">⚖️</span>
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
        <span class="stat-icon-wrapper" title="Rencana Diet" aria-hidden="true">🎯</span>
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
        <span class="stat-icon-wrapper" title="Menu Rekomendasi" aria-hidden="true">🥗</span>
      </div>
      <?php if ($menu_harian && (int)$menu_harian['jumlah_menu'] > 0): ?>
        <strong class="dashboard-stat-value"><?= (int)$menu_harian['jumlah_menu'] ?> <span style="font-size:0.95rem; font-weight:600; color:var(--teks-redup);">menu harian</span></strong>
        <span class="dashboard-stat-note">
          Telah tersimpan dalam rencana makan hari ini.
        </span>
        <a class="dashboard-stat-link" href="menu.php?hari_ini=1">Buka menu harian <span aria-hidden="true">&rarr;</span></a>
      <?php else: ?>
        <strong class="dashboard-stat-value"><?= $total_menu_sesuai ?> <span style="font-size:0.95rem; font-weight:600; color:var(--teks-redup);">pilihan</span></strong>
        <span class="dashboard-stat-note">
          <?= $bmi ? 'Resep gizi seimbang cocok untuk BMI ' . h($bmi['kategori']) . '.' : 'Pilihan makanan bergizi seimbang.' ?>
        </span>
        <a class="dashboard-stat-link" href="menu.php">Pilih rekomendasi <span aria-hidden="true">&rarr;</span></a>
      <?php endif; ?>
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

<?php if ($menu_harian && (int)$menu_harian['jumlah_menu'] > 0): ?>
  <section class="menu-harian-dashboard" aria-labelledby="menu-harian-judul">
    <div>
      <span class="dashboard-eyebrow">RENCANA MENU &middot; <?= h(date('d-m-Y', strtotime($hari_ini))) ?></span>
      <h3 id="menu-harian-judul">Menu harianmu tersimpan</h3>
      <p><?= (int)$menu_harian['jumlah_menu'] ?> pilihan menu tersimpan. Rencana ini tidak otomatis masuk ke catatan makanan.</p>
    </div>
    <a class="tombol tombol-sekunder" href="menu.php?hari_ini=1">Buka menu harian</a>
  </section>
<?php endif; ?>



<?php if (!empty($saran_pakar)): ?>
<section style="margin-top: 28px;" aria-labelledby="saran-pakar-judul">
  <div class="dashboard-section-heading" style="margin-bottom: 14px;">
    <div>
      <h3 id="saran-pakar-judul" style="margin:0 0 4px;">Saran dari Pakar Gizi</h3>
      <p class="teks-redup" style="margin:0; font-size:0.88rem;">Panduan nutrisi yang disiapkan khusus untuk Anda.</p>
    </div>
  </div>
  <div style="display: flex; flex-direction: column; gap: 12px;">
    <?php foreach ($saran_pakar as $sp): ?>
      <div class="kartu" style="padding: 18px 20px; border-left: 3px solid #818CF8;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px;">
          <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--gelap);"><?= h($sp['judul']) ?></h4>
          <span style="font-size: 0.72rem; color: var(--teks-redup); white-space: nowrap;">
            <?= date('d M Y', strtotime($sp['created_at'])) ?>
          </span>
        </div>
        <p style="margin: 0 0 8px; font-size: 0.88rem; color: var(--gelap); line-height: 1.65; white-space: pre-wrap;"><?= h($sp['isi_saran']) ?></p>
        <span style="font-size: 0.75rem; color: #4F46E5; font-weight: 600;">
          Oleh <?= h($sp['nama_pakar']) ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php include BASE_DIR . 'includes/footer.php'; ?>


