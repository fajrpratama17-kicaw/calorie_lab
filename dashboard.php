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
$st = $pdo->prepare("SELECT r.bmi, r.target_kalori, k.nama AS kategori
                     FROM riwayat_bmi r
                     JOIN kategori_bmi k ON k.id = r.kategori_id
                     WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1");
$st->execute([$uid]);
$bmi = $st->fetch(PDO::FETCH_ASSOC);

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

$rencana_selesai = false;
if ($rencana) {
    $selesai_rencana = (new DateTime($rencana['tanggal_mulai']))->modify('+' . (int)$rencana['durasi_hari'] . ' days')->format('Y-m-d');
    $rencana_selesai = $hari_ini >= $selesai_rencana;
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
    <p class="dashboard-eyebrow">DASHBOARD</p>
    <h2>Halo, <?= h($_SESSION['nama'] ?? 'Pengguna') ?></h2>
    <p class="teks-redup">Ini ringkasan dan langkah kecil yang bisa kamu lakukan hari ini.</p>
  </div>
  <a class="tombol tombol-sekunder" href="profil.php">Lihat perkembangan</a>
</header>

<section aria-labelledby="ringkasan-judul">
  <div class="dashboard-section-heading">
    <div>
      <h3 id="ringkasan-judul">Ringkasan dietmu</h3>
      <p class="teks-redup">Angka bersifat perkiraan edukatif, bukan saran medis.</p>
    </div>
  </div>

  <div class="dashboard-ringkasan">
    <article class="dashboard-stat">
      <span class="dashboard-stat-label">BMI terakhir</span>
      <?php if ($bmi): ?>
        <strong class="dashboard-stat-value"><?= h(number_format((float)$bmi['bmi'], 1, ',', '.')) ?></strong>
        <span class="dashboard-stat-note"><?= h($bmi['kategori']) ?></span>
      <?php else: ?>
        <strong class="dashboard-stat-empty">Belum dihitung</strong>
        <a class="dashboard-stat-link" href="bmi.php">Hitung BMI <span aria-hidden="true">&rarr;</span></a>
      <?php endif; ?>
    </article>

    <article class="dashboard-stat dashboard-stat-highlight">
      <span class="dashboard-stat-label">Target kalori harian</span>
      <?php if ($target_kalori): ?>
        <strong class="dashboard-stat-value"><?= number_format((float)$target_kalori, 0, ',', '.') ?></strong>
        <span class="dashboard-stat-note">kkal &middot; perkiraan</span>
      <?php else: ?>
        <strong class="dashboard-stat-empty">Belum tersedia</strong>
        <span class="dashboard-stat-note">Hitung BMI untuk melihat perkiraan target.</span>
      <?php endif; ?>
    </article>

    <article class="dashboard-stat">
      <span class="dashboard-stat-label">Rencana diet</span>
      <?php if ($rencana): ?>
        <strong class="dashboard-stat-plan"><?= h($label_tujuan[$rencana['tujuan']] ?? $rencana['tujuan']) ?></strong>
        <span class="dashboard-stat-note">
          <?= h(number_format((float)$berat_terakhir, 1, ',', '.')) ?> kg sekarang <span aria-hidden="true">&rarr;</span>
          <?= h(number_format((float)$rencana['berat_target'], 1, ',', '.')) ?> kg target
        </span>
        <a class="dashboard-stat-link" href="rencana.php">Lihat rencana <span aria-hidden="true">&rarr;</span></a>
      <?php else: ?>
        <strong class="dashboard-stat-empty">Belum ada rencana</strong>
        <a class="dashboard-stat-link" href="rencana.php">Buat rencana <span aria-hidden="true">&rarr;</span></a>
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

<section class="dashboard-fitur" aria-labelledby="fitur-judul">
  <div class="dashboard-section-heading">
    <div>
      <h3 id="fitur-judul">Fitur Calorie Lab</h3>
      <p class="teks-redup">Pilih bagian yang ingin kamu buka.</p>
    </div>
  </div>

<div class="dashboard-fitur-grid">
  <a class="kartu kartu-link" href="bmi.php">
    <span class="dashboard-card-label">01 &middot; UKUR</span>
    <h3>Hitung BMI</h3>
    <p>Cek kategori tubuh dan target kalori harian.</p>
  </a>
  <a class="kartu kartu-link" href="menu.php">
    <span class="dashboard-card-label">02 &middot; PILIH</span>
    <h3>Menu rekomendasi</h3>
    <p>Menu yang cocok untuk kategori BMI-mu.</p>
  </a>
  <a class="kartu kartu-link" href="catatan.php">
    <span class="dashboard-card-label">03 &middot; CATAT</span>
    <h3>Catatan kalori</h3>
    <p>Catat makanan dan lihat sisa kalori hari ini.</p>
  </a>
  <a class="kartu kartu-link" href="profil.php">
    <span class="dashboard-card-label">04 &middot; PANTAU</span>
    <h3>Profil dan perkembangan</h3>
    <p>Catat berat badan dan pantau progres dietmu.</p>
  </a>
  <a class="kartu kartu-link" href="rencana.php">
    <span class="dashboard-card-label">05 &middot; RENCANAKAN</span>
    <h3>Rencana diet</h3>
    <p>Tentukan target berat badan dan durasi dietmu.</p>
  </a>
</div>
</section>

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


