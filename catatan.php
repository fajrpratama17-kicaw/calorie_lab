<?php
require_once __DIR__ . '/config/koneksi.php';
wajib_login();

$uid      = $_SESSION['user_id'];
$hari_ini = date('Y-m-d');
$porsi_ok = [0.5, 1.0, 1.5, 2.0];
$bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
          'Agustus', 'September', 'Oktober', 'November', 'Desember'];

if (!function_exists('nf')) {
    function nf($n, $d = 0) { return number_format($n, $d, ',', '.'); }
}
if (!function_exists('status_svg')) {
    function status_svg($key) {
        $icons = [
            'check'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:3px"><polyline points="20 6 9 17 4 12"/></svg>',
            'clipboard' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:3px"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>',
            'warning'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:3px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'alert'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:3px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        ];
        return $icons[$key] ?? '';
    }
}
if (!function_exists('tanggal_valid')) {
    function tanggal_valid($tanggal) {
        if (!is_string($tanggal)) return false;
        $hasil = DateTime::createFromFormat('!Y-m-d', $tanggal);
        $kesalahan = DateTime::getLastErrors();
        return $hasil && $hasil->format('Y-m-d') === $tanggal
            && ($kesalahan === false || ($kesalahan['warning_count'] === 0 && $kesalahan['error_count'] === 0));
    }
}

// Batas maksimal tanggal yang bisa dilihat: hari ini atau tanggal terakhir yang tercatat
$st_max = $pdo->prepare('SELECT MAX(tanggal) FROM catatan_makan WHERE user_id = ?');
$st_max->execute([$uid]);
$max_recorded = $st_max->fetchColumn();
$tanggal_maks_lihat = ($max_recorded && $max_recorded > $hari_ini) ? $max_recorded : $hari_ini;

$csrf_token = csrf_token('csrf_token_catatan');
$tanggal_pilihan = $_GET['tanggal'] ?? $hari_ini;
if (!tanggal_valid($tanggal_pilihan) || $tanggal_pilihan > $tanggal_maks_lihat) {
    $tanggal_pilihan = $hari_ini;
}

$pesan = $_SESSION['pesan_catatan'] ?? '';
$pesan_tipe = $_SESSION['pesan_catatan_tipe'] ?? 'sukses';
unset($_SESSION['pesan_catatan']);
unset($_SESSION['pesan_catatan_tipe']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token        = $_POST['csrf_token'] ?? '';
    $aksi         = $_POST['aksi'] ?? '';
    $tanggal_post = $_POST['tanggal'] ?? $hari_ini;

    if (!csrf_valid($token, 'csrf_token_catatan')) {
        $_SESSION['pesan_catatan'] = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $_SESSION['pesan_catatan_tipe'] = 'error';
    } elseif (!tanggal_valid($tanggal_post) || $tanggal_post > $tanggal_maks_lihat) {
        $tanggal_post = $hari_ini;
        $_SESSION['pesan_catatan'] = 'Tanggal catatan tidak valid. Silakan pilih tanggal yang tersedia.';
        $_SESSION['pesan_catatan_tipe'] = 'error';
    } elseif ($aksi === 'catat_saran' || $aksi === 'tambah') {
        $menu_id = (int)($_POST['menu_id'] ?? 0);
        $porsi   = (float)($_POST['porsi'] ?? 1);
        $cek = $pdo->prepare('SELECT id FROM menu_diet WHERE id = ?');
        $cek->execute([$menu_id]);
        if ($cek->fetch() && in_array($porsi, $porsi_ok, true)) {
            $ins = $pdo->prepare('INSERT INTO catatan_makan (user_id, menu_id, porsi, tanggal) VALUES (?, ?, ?, ?)');
            $ins->execute([$uid, $menu_id, $porsi, $tanggal_post]);
            $_SESSION['pesan_catatan'] = 'Makanan berhasil dicatat.';
        } else {
            $_SESSION['pesan_catatan'] = 'Menu atau jumlah porsi tidak valid. Silakan coba lagi.';
            $_SESSION['pesan_catatan_tipe'] = 'error';
        }
    } elseif ($aksi === 'hapus') {
        $id  = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare('UPDATE menu_harian_item SET catatan_makan_id = NULL WHERE catatan_makan_id = ?')->execute([$id]);
        } catch (Exception $e) {}
        $del = $pdo->prepare('DELETE FROM catatan_makan WHERE id = ? AND user_id = ?');
        $del->execute([$id, $uid]);
        $dihapus = $del->rowCount() > 0;
        $_SESSION['pesan_catatan'] = $dihapus
            ? 'Catatan makanan dihapus.'
            : 'Catatan tidak ditemukan atau sudah dihapus.';
        $_SESSION['pesan_catatan_tipe'] = $dihapus ? 'sukses' : 'error';
    } else {
        $_SESSION['pesan_catatan'] = 'Aksi tidak dikenal. Muat ulang halaman lalu coba lagi.';
        $_SESSION['pesan_catatan_tipe'] = 'error';
    }

    header('Location: catatan.php?' . http_build_query(['tanggal' => $tanggal_post]));
    exit;
}

// BMI dan target terakhir
$q = $pdo->prepare('SELECT r.target_kalori, r.kategori_id, k.nama AS kat_nama, k.kode AS kat_kode
                    FROM riwayat_bmi r JOIN kategori_bmi k ON k.id = r.kategori_id
                    WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1');
$q->execute([$uid]);
$terakhir = $q->fetch();

$q = $pdo->prepare("SELECT target_kalori FROM rencana_diet WHERE user_id = ? AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$q->execute([$uid]);
$rencana = $q->fetch();
if ($rencana && $terakhir) $terakhir['target_kalori'] = $rencana['target_kalori'];

$catatan = [];
$semua_menu = [];
$saran = [];
$total = 0;
$target = null;

if ($terakhir) {
    $target = (int)$terakhir['target_kalori'];

    $q = $pdo->prepare("SELECT c.id, c.porsi, m.nama_menu, m.waktu_makan, m.kalori
                        FROM catatan_makan c JOIN menu_diet m ON m.id = c.menu_id
                        WHERE c.user_id = ? AND c.tanggal = ?
                        ORDER BY FIELD(m.waktu_makan,'pagi','siang','malam','camilan'), c.id");
    $q->execute([$uid, $tanggal_pilihan]);
    $catatan = $q->fetchAll();
    foreach ($catatan as $c) $total += $c['kalori'] * $c['porsi'];
    $total = (int)round($total);

    $sisa   = $tanggal_pilihan === $hari_ini ? $target - $total : null;
    $persen = $tanggal_pilihan === $hari_ini && $target > 0 ? (int)round($total / $target * 100) : 0;
    $kelas  = $persen > 100 ? 'merah' : ($persen >= 90 ? 'kuning' : '');

    // Evaluasi status gizi asupan harian (Opsi A: Edukasi batas minimal & rentang aman)
    $status_gizi  = 'ideal';
    $status_ikon  = 'check';
    $status_judul = 'Rentang Ideal';
    $status_ket   = 'Asupanmu telah mencukupi kebutuhan dasar tubuh dan berada dalam batas aman untuk mendukung targetmu.';

    if ($total == 0) {
        $status_gizi  = 'belum';
        $status_ikon  = 'clipboard';
        $status_judul = 'Belum Ada Asupan';
        $status_ket   = 'Mulai catat makanan yang kamu konsumsi hari ini untuk memantau asupan energi harian.';
    } elseif ($total < 1200) {
        $status_gizi  = 'rendah';
        $status_ikon  = 'warning';
        $status_judul = 'Di Bawah Batas Minimal (< 1.200 kkal)';
        $status_ket   = 'Target kalori tidak wajib 100% pas, namun tubuh membutuhkan minimal ~1.200 kkal/hari agar tidak lemas dan metabolisme tetap terjaga. Disarankan menambah porsi atau mencatat camilan sehat.';
    } elseif ($persen > 105 || $sisa < 0) {
        $status_gizi  = 'lebih';
        $status_ikon  = 'alert';
        $status_judul = 'Melewati Target';
        $status_ket   = 'Asupan hari ini telah melebihi target perkiraan. Kamu bisa mengimbanginya dengan aktivitas fisik ringan atau menyesuaikan porsi makanan berikutnya.';
    }

    // Saran menu yang masih muat di sisa kalori (sesuai kategori BMI user)
    if ($tanggal_pilihan === $hari_ini && $sisa > 0) {
        $q = $pdo->prepare('SELECT DISTINCT m.* FROM menu_diet m
                            JOIN menu_kategori mk ON mk.menu_id = m.id
                            WHERE mk.kategori_id = ? AND m.kalori <= ?
                            ORDER BY RAND() LIMIT 6');
        $q->execute([$terakhir['kategori_id'], $sisa]);
        $saran = $q->fetchAll();
    }
}

$tanggal_teks = date('j', strtotime($tanggal_pilihan)) . ' ' . $bulan[(int)date('n', strtotime($tanggal_pilihan))] . ' ' . date('Y', strtotime($tanggal_pilihan));
$q = $pdo->prepare('SELECT DISTINCT tanggal FROM catatan_makan WHERE user_id = ? AND tanggal <= ? ORDER BY tanggal DESC LIMIT 5');
$q->execute([$uid, $tanggal_maks_lihat]);
$tanggal_riwayat = $q->fetchAll(PDO::FETCH_COLUMN);

$judul = 'Catatan Kalori';
include BASE_DIR . 'includes/header.php';
?>

<div class="page-hero-header">
  <div class="page-hero-icon">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="13" y2="11"/></svg>
  </div>
  <div class="page-hero-teks">
    <h2>Catatan Kalori</h2>
    <p>Pantau asupan kalori harianmu secara konsisten.</p>
  </div>
  <?php if ($terakhir && $tanggal_pilihan === $hari_ini): ?>
    <div class="catatan-tanggal-badge" style="margin-left:auto;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span><?= h($tanggal_teks) ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= h($pesan) ?></div>
<?php endif; ?>

<?php if (!$terakhir): ?>
  <div class="kosong-state">
    <div class="kosong-state-ikon">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="13" y2="11"/></svg>
    </div>
    <h3>Belum ada target kalori</h3>
    <p>Hitung BMI dulu agar sistem bisa menentukan target kalori harianmu.</p>
    <a class="tombol" href="bmi.php">Hitung BMI sekarang &rarr;</a>
  </div>

<?php else: ?>

  <?php if ($tanggal_pilihan === $hari_ini): ?>
    <!-- Ringkasan kalori hari ini -->
    <div class="catatan-ringkasan-card">
      <div class="catatan-ringkasan-info">
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">Target harian</span>
          <span class="catatan-stat-nilai"><?= nf($target) ?> <small>kkal</small></span>
        </div>
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">Sudah dicatat</span>
          <span class="catatan-stat-nilai"><?= nf($total) ?> <small>kkal</small></span>
        </div>
        <div class="catatan-stat-item <?= $sisa < 0 ? 'stat-melebihi' : '' ?>">
          <span class="catatan-stat-label"><?= $sisa >= 0 ? 'Sisa kalori' : 'Melebihi target' ?></span>
          <span class="catatan-stat-nilai"><?= nf(abs($sisa)) ?> <small>kkal</small></span>
        </div>
      </div>
      <div class="catatan-progress-area">
        <div class="catatan-progress-label">
          <span><?= (int)$persen ?>% dari target (<?= nf($total) ?> / <?= nf($target) ?> kkal)</span>
          <span class="catatan-status-badge status-<?= $status_gizi ?>">
            <?= status_svg($status_ikon) ?><?= h($status_judul) ?>
          </span>
        </div>
        <div class="progres" style="margin-top:6px">
          <div class="progres-isi <?= $kelas ?>" style="width:<?= min(100, (int)$persen) ?>%"></div>
        </div>
        <div class="catatan-edukasi-box status-<?= $status_gizi ?>">
          <p><?= status_svg($status_ikon) ?><strong>Catatan Kebutuhan Kalori:</strong> <?= h($status_ket) ?></p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- Ringkasan tanggal lampau -->
    <div class="catatan-ringkasan-card catatan-riwayat-mode">
      <div class="catatan-riwayat-badge">Melihat riwayat: <?= h($tanggal_teks) ?></div>
      <div class="catatan-ringkasan-info" style="margin-top:14px">
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">Total tercatat</span>
          <span class="catatan-stat-nilai"><?= nf($total) ?> <small>kkal</small></span>
        </div>
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">Jumlah catatan</span>
          <span class="catatan-stat-nilai"><?= count($catatan) ?> <small>menu</small></span>
        </div>
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">Rata-rata per menu</span>
          <span class="catatan-stat-nilai"><?= count($catatan) > 0 ? nf(round($total / count($catatan))) : 0 ?> <small>kkal</small></span>
        </div>
      </div>
      <p class="catatan" style="margin-top:8px">Ini adalah riwayat catatan untuk tanggal tersebut. Target kalori saat ini tidak digunakan untuk menilai tanggal lampau.</p>
    </div>
  <?php endif; ?>

  <!-- Toolbar Filter Tanggal -->
  <div class="catatan-filter-bar">
    <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <label for="tanggal-riwayat" style="font-size:0.88rem; font-weight:700; color:var(--gelap); margin:0;">Tanggal Catatan:</label>
      </div>
      <input type="date" id="tanggal-riwayat" name="tanggal" value="<?= h($tanggal_pilihan) ?>" max="<?= h($tanggal_maks_lihat) ?>" style="width:auto; padding:6px 10px; font-size:0.85rem;">
      <button type="submit" class="tombol kecil" style="padding:7px 14px;">Buka</button>
      <?php if ($tanggal_pilihan !== $hari_ini): ?>
        <a class="tombol tombol-sekunder kecil" href="catatan.php" style="padding:7px 12px;">Hari ini</a>
      <?php endif; ?>
    </form>
    <?php if ($tanggal_riwayat): ?>
      <span class="catatan-filter-divider" aria-hidden="true"></span>
      <div class="catatan-filter-riwayat">
        <span style="font-size:0.75rem; font-weight:700; color:var(--teks-redup); text-transform:uppercase; letter-spacing:0.04em;">Riwayat:</span>
        <nav class="tanggal-cepat" aria-label="Tanggal catatan terbaru">
          <?php foreach ($tanggal_riwayat as $tanggal): ?>
            <a class="<?= $tanggal === $tanggal_pilihan ? 'aktif' : '' ?>" href="catatan.php?<?= h(http_build_query(['tanggal' => $tanggal])) ?>" style="padding:4px 10px; font-size:0.78rem;">
              <?= h(date('j M', strtotime($tanggal))) ?>
            </a>
          <?php endforeach; ?>
        </nav>
      </div>
    <?php endif; ?>
  </div>

  <div class="catatan-two-col">

    <!-- Kolom kiri: Panel navigasi & saran cepat -->
    <div class="catatan-col-form">

      <?php if ($tanggal_pilihan === $hari_ini): ?>

        <?php if ($saran): ?>
          <!-- Saran cepat berdasarkan sisa kalori -->
          <div class="kartu catatan-saran-card">
            <div>
              <div class="catatan-saran-card-header">
                <span class="catatan-saran-card-title">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                  Masih muat di sisa kalorimu
                </span>
                <span class="catatan-saran-card-sub">Cocok untuk BMI-mu &middot; Sisa <?= nf($sisa) ?> kkal</span>
              </div>
              <div class="catatan-saran-grid">
                <?php foreach ($saran as $m): ?>
                  <div class="catatan-saran-item">
                    <div class="catatan-saran-info">
                      <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>" style="font-size:0.72rem; margin-bottom:2px;"><?= h(ucfirst($m['waktu_makan'])) ?></span>
                      <strong class="catatan-saran-nama"><?= h($m['nama_menu']) ?></strong>
                      <span class="catatan-saran-kal"><?= (int)$m['kalori'] ?> kkal</span>
                    </div>
                    <form method="post">
                      <input type="hidden" name="aksi" value="catat_saran">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                      <input type="hidden" name="tanggal" value="<?= h($tanggal_pilihan) ?>">
                      <input type="hidden" name="menu_id" value="<?= (int)$m['id'] ?>">
                      <input type="hidden" name="porsi" value="1">
                      <button type="submit" class="tombol kecil" style="white-space:nowrap;">+ Catat</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="margin-top:16px; padding-top:12px; border-top:1px solid var(--garis);">
              <a href="menu.php" class="tombol tombol-sekunder penuh" style="display:block; text-align:center; font-size:0.85rem;">
                Lihat Semua Menu Rekomendasi &rarr;
              </a>
            </div>
          </div>
        <?php elseif ($sisa !== null && $sisa <= 0): ?>
          <!-- Target sudah terpenuhi / terlewati -->
          <div class="kartu catatan-target-penuh">
            <div>
              <div style="display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:14px;background:var(--mint);color:var(--hijau);margin:0 auto 14px;">
                <?= $sisa === 0
                  ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
                  : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                ?>
              </div>
              <h3 style="font-size:1.1rem; margin-bottom:8px; color:var(--gelap);"><?= $sisa === 0 ? 'Target Kalori Terpenuhi!' : 'Target Kalori Terlewati' ?></h3>
              <p class="teks-redup" style="font-size:0.88rem; line-height:1.55;">
                <?= $sisa === 0 ? 'Kamu sudah memenuhi target kalori harianmu dengan baik.' : 'Konsumsi hari ini telah melebihi target perkiraan. Pertimbangkan aktivitas fisik tambahan.' ?>
              </p>
            </div>
            <div style="margin-top:16px;">
              <a href="menu.php" class="tombol tombol-sekunder penuh" style="display:block; text-align:center;">Lihat Menu Rekomendasi</a>
            </div>
          </div>
        <?php else: ?>
          <div class="kartu catatan-saran-card">
            <div>
              <div style="display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:14px;background:var(--mint);color:var(--hijau);margin:0 auto 12px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
              </div>
              <h3 style="font-size:1.05rem; text-align:center; margin-bottom:6px; color:var(--gelap);">Cari Menu Makanan</h3>
              <p class="teks-redup" style="font-size:0.85rem; text-align:center; line-height:1.5; margin-bottom:14px;">
                Jelajahi beragam pilihan resep bergizi yang disesuaikan dengan kategori BMI-mu.
              </p>
            </div>
            <div style="margin-top:16px;">
              <a href="menu.php" class="tombol penuh" style="display:block; text-align:center;">Buka Menu Rekomendasi &rarr;</a>
            </div>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <!-- Mode riwayat: tidak ada aksi tambah -->
        <div class="kartu catatan-riwayat-info-card">
          <div>
            <div style="display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:14px;background:var(--mint);color:var(--hijau);margin:0 auto 12px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <h3 style="font-size:1.1rem; text-align:center; margin-bottom:8px; color:var(--gelap);">Mode Riwayat</h3>
            <p class="teks-redup" style="font-size:0.85rem; text-align:center; line-height:1.55;">
              Kamu sedang melihat catatan untuk <strong><?= h($tanggal_teks) ?></strong>.<br>
              Catatan tanggal lampau hanya bersifat arsip dan tidak dapat diubah dari sini.
            </p>
          </div>
          <div style="margin-top:16px;">
            <a href="catatan.php" class="tombol penuh" style="display:block; text-align:center;">
              &larr; Kembali ke Hari Ini
            </a>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- Kolom kanan: Daftar catatan -->
    <div class="catatan-col-list">
      <div class="kartu catatan-list-card">
        <div>
          <div class="catatan-list-header">
            <h3>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-3px;margin-right:6px;color:var(--hijau)"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
              Makanan Tercatat
            </h3>
            <span class="catatan-list-count"><?= count($catatan) ?> item &middot; <?= nf($total) ?> kkal</span>
          </div>

          <?php if (!$catatan): ?>
            <div class="catatan-kosong-state" style="padding: 44px 16px; text-align:center;">
              <div style="display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:16px;background:var(--mint);color:var(--hijau);margin:0 auto 12px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
              </div>
              <strong style="font-size:1rem; color:var(--gelap); display:block; margin-bottom:4px;">Belum Ada Makanan Tercatat</strong>
              <p class="teks-redup" style="font-size:0.85rem; max-width:320px; margin:0 auto; line-height:1.5;">
                Pilih menu dari panel saran di sebelah kiri dengan klik tombol <strong>+ Catat</strong>, atau jelajahi Menu Rekomendasi.
              </p>
            </div>
          <?php else: ?>
            <?php
            // Kelompokkan per waktu makan
            $per_waktu = [];
            foreach ($catatan as $c) {
                $per_waktu[$c['waktu_makan']][] = $c;
            }
            $urutan_waktu = ['pagi', 'siang', 'malam', 'camilan'];
            $ikon_waktu = ['pagi' => '', 'siang' => '', 'malam' => '', 'camilan' => ''];
            foreach ($urutan_waktu as $wkt):
                if (empty($per_waktu[$wkt])) continue;
                $sub_total = array_sum(array_map(fn($c) => $c['kalori'] * $c['porsi'], $per_waktu[$wkt]));
            ?>
              <div class="catatan-waktu-group">
                <div class="catatan-waktu-label">
                  <span><?= $ikon_waktu[$wkt] ?> <?= ucfirst($wkt) ?></span>
                  <span class="catatan-waktu-kal"><?= nf($sub_total) ?> kkal</span>
                </div>
                <?php foreach ($per_waktu[$wkt] as $c):
                  $porsi_teks = rtrim(rtrim(number_format($c['porsi'], 1, ',', ''), '0'), ',');
                ?>
                  <div class="baris-catatan-modern">
                    <div class="catatan-item-info">
                      <span class="catatan-item-nama"><?= h($c['nama_menu']) ?></span>
                      <span class="catatan-item-detail"><?= h($porsi_teks) ?> porsi &middot; <?= nf($c['kalori'] * $c['porsi']) ?> kkal</span>
                    </div>
                    <form method="post" style="flex-shrink:0">
                      <input type="hidden" name="aksi" value="hapus">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                      <input type="hidden" name="tanggal" value="<?= h($tanggal_pilihan) ?>">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <button type="submit" class="tombol-hapus-kecil" onclick="return confirm('Hapus catatan ini?')" title="Hapus">&times;</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div style="margin-top:16px; padding-top:12px; border-top:1px solid var(--garis);">
          <p class="catatan" style="margin:0; font-size:0.78rem; text-align:center;">
            Estimasi kalori per porsi standar &middot; Bersifat edukatif dan acuan mandiri.
          </p>
        </div>
      </div>
    </div>

  </div>

<?php endif; ?>

<?php include BASE_DIR . 'includes/footer.php'; ?>


