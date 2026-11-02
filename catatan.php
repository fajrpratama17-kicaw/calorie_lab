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
    } elseif ($aksi === 'tambah') {
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
        $pdo->prepare('UPDATE menu_harian_item SET catatan_makan_id = NULL WHERE catatan_makan_id = ?')->execute([$id]);
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

    // Daftar menu untuk form
    $semua_menu = $pdo->query("SELECT id, nama_menu, waktu_makan, kalori FROM menu_diet
                               ORDER BY FIELD(waktu_makan,'pagi','siang','malam','camilan'), nama_menu")->fetchAll();

    // Saran menu yang masih muat di sisa kalori
    if ($tanggal_pilihan === $hari_ini && $sisa > 0) {
        $q = $pdo->prepare('SELECT DISTINCT m.* FROM menu_diet m
                            JOIN menu_kategori mk ON mk.menu_id = m.id
                            WHERE mk.kategori_id = ? AND m.kalori <= ?
                            ORDER BY RAND() LIMIT 4');
        $q->execute([$terakhir['kategori_id'], $sisa]);
        $saran = $q->fetchAll();
    }
}

$tanggal_teks = date('j', strtotime($tanggal_pilihan)) . ' ' . $bulan[(int)date('n', strtotime($tanggal_pilihan))] . ' ' . date('Y', strtotime($tanggal_pilihan));
$q = $pdo->prepare('SELECT DISTINCT tanggal FROM catatan_makan WHERE user_id = ? AND tanggal <= ? ORDER BY tanggal DESC LIMIT 14');
$q->execute([$uid, $tanggal_maks_lihat]);
$tanggal_riwayat = $q->fetchAll(PDO::FETCH_COLUMN);

$judul = 'Catatan Kalori';
include BASE_DIR . 'includes/header.php';
?>

<div class="catatan-page-header">
  <div>
    <h2>Catatan Kalori</h2>
    <p class="teks-redup">Pantau asupan kalori harianmu secara konsisten.</p>
  </div>
  <?php if ($terakhir && $tanggal_pilihan === $hari_ini): ?>
    <div class="catatan-tanggal-badge">
      <span>📅</span>
      <span><?= h($tanggal_teks) ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= h($pesan) ?></div>
<?php endif; ?>

<?php if (!$terakhir): ?>
  <div class="kosong-state">
    <div class="kosong-state-ikon">🎯</div>
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
          <span class="catatan-stat-label">🎯 Target harian</span>
          <span class="catatan-stat-nilai"><?= nf($target) ?> <small>kkal</small></span>
        </div>
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">✅ Sudah dicatat</span>
          <span class="catatan-stat-nilai"><?= nf($total) ?> <small>kkal</small></span>
        </div>
        <div class="catatan-stat-item <?= $sisa < 0 ? 'stat-melebihi' : '' ?>">
          <span class="catatan-stat-label"><?= $sisa >= 0 ? '🟢 Sisa kalori' : '🔴 Melebihi target' ?></span>
          <span class="catatan-stat-nilai"><?= nf(abs($sisa)) ?> <small>kkal</small></span>
        </div>
      </div>
      <div class="catatan-progress-area">
        <div class="catatan-progress-label">
          <span><?= (int)$persen ?>% dari target</span>
          <?php if ($sisa < 0): ?><span class="catatan-over-tag">Melewati target</span><?php endif; ?>
        </div>
        <div class="progres" style="margin-top:6px">
          <div class="progres-isi <?= $kelas ?>" style="width:<?= min(100, (int)$persen) ?>%"></div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- Ringkasan tanggal lampau -->
    <div class="catatan-ringkasan-card catatan-riwayat-mode">
      <div class="catatan-riwayat-badge">📆 Melihat riwayat: <?= h($tanggal_teks) ?></div>
      <div class="catatan-ringkasan-info" style="margin-top:14px">
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">📊 Total tercatat</span>
          <span class="catatan-stat-nilai"><?= nf($total) ?> <small>kkal</small></span>
        </div>
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">🍽️ Jumlah catatan</span>
          <span class="catatan-stat-nilai"><?= count($catatan) ?> <small>menu</small></span>
        </div>
        <div class="catatan-stat-item">
          <span class="catatan-stat-label">📈 Rata-rata per menu</span>
          <span class="catatan-stat-nilai"><?= count($catatan) > 0 ? nf(round($total / count($catatan))) : 0 ?> <small>kkal</small></span>
        </div>
      </div>
      <p class="catatan" style="margin-top:8px">Ini adalah riwayat catatan untuk tanggal tersebut. Target kalori saat ini tidak digunakan untuk menilai tanggal lampau.</p>
    </div>
  <?php endif; ?>

  <!-- Filter tanggal -->
  <div class="catatan-filter-bar">
    <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <label for="tanggal-riwayat" style="font-size:0.85rem; font-weight:600; color:var(--teks-redup);">Lihat tanggal lain:</label>
      <input type="date" id="tanggal-riwayat" name="tanggal" value="<?= h($tanggal_pilihan) ?>" max="<?= h($tanggal_maks_lihat) ?>" style="width:auto;">
      <button type="submit" class="tombol" style="padding:8px 18px;">Tampilkan</button>
      <?php if ($tanggal_pilihan !== $hari_ini): ?>
        <a class="tombol tombol-sekunder" href="catatan.php" style="padding:8px 18px;">Kembali ke hari ini</a>
      <?php endif; ?>
    </form>
    <?php if ($tanggal_riwayat): ?>
      <nav class="tanggal-cepat" aria-label="Tanggal catatan terbaru">
        <?php foreach ($tanggal_riwayat as $tanggal): ?>
          <a class="<?= $tanggal === $tanggal_pilihan ? 'aktif' : '' ?>" href="catatan.php?<?= h(http_build_query(['tanggal' => $tanggal])) ?>">
            <?= h(date('j M', strtotime($tanggal))) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </div>

  <div class="catatan-two-col">

    <!-- Kolom kiri: Form tambah -->
    <div class="catatan-col-form">
      <div class="kartu catatan-form-card">
        <div class="catatan-form-header">
          <h3>➕ Catat Makanan</h3>
          <p class="teks-redup" style="font-size:0.85rem; margin-top:4px;">Tambah makanan yang kamu konsumsi pada <strong><?= h($tanggal_teks) ?></strong>.</p>
        </div>
        <form method="post" class="catatan-form-body">
          <input type="hidden" name="aksi" value="tambah">
          <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
          <input type="hidden" name="tanggal" value="<?= h($tanggal_pilihan) ?>">
          <div>
            <label for="menu_id">Pilih menu</label>
            <select id="menu_id" name="menu_id" required>
              <option value="">-- Pilih menu --</option>
              <?php $w_sebelum = '';
              foreach ($semua_menu as $m):
                  if ($m['waktu_makan'] !== $w_sebelum):
                      if ($w_sebelum !== '') echo '</optgroup>';
                      echo '<optgroup label="' . h(ucfirst($m['waktu_makan'])) . '">';
                      $w_sebelum = $m['waktu_makan'];
                  endif; ?>
                <option value="<?= (int)$m['id'] ?>"><?= h($m['nama_menu']) ?> &mdash; <?= (int)$m['kalori'] ?> kkal</option>
              <?php endforeach;
              if ($w_sebelum !== '') echo '</optgroup>'; ?>
            </select>
          </div>
          <div>
            <label for="porsi">Jumlah porsi</label>
            <select id="porsi" name="porsi">
              <option value="0.5">½ porsi</option>
              <option value="1" selected>1 porsi</option>
              <option value="1.5">1,5 porsi</option>
              <option value="2">2 porsi</option>
            </select>
          </div>
          <button type="submit" class="tombol penuh" style="margin-top:8px;">Tambah ke catatan</button>
        </form>

        <?php if ($saran): ?>
          <div class="catatan-saran-section">
            <div class="catatan-saran-header">
              <span>💡 Masih muat di sisa kalorimu</span>
            </div>
            <div class="catatan-saran-grid">
              <?php foreach ($saran as $m): ?>
                <div class="catatan-saran-item">
                  <div class="catatan-saran-info">
                    <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>" style="font-size:0.72rem;"><?= h(ucfirst($m['waktu_makan'])) ?></span>
                    <strong class="catatan-saran-nama"><?= h($m['nama_menu']) ?></strong>
                    <span class="catatan-saran-kal"><?= (int)$m['kalori'] ?> kkal</span>
                  </div>
                  <form method="post">
                    <input type="hidden" name="aksi" value="tambah">
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
        <?php endif; ?>
      </div>
    </div>

    <!-- Kolom kanan: Daftar catatan -->
    <div class="catatan-col-list">
      <div class="kartu catatan-list-card">
        <div class="catatan-list-header">
          <h3>🍽️ Makanan Tercatat</h3>
          <span class="catatan-list-count"><?= count($catatan) ?> item &middot; <?= nf($total) ?> kkal</span>
        </div>

        <?php if (!$catatan): ?>
          <div class="catatan-kosong-state">
            <div style="font-size:2.5rem; margin-bottom:10px;">🥗</div>
            <p>Belum ada makanan yang dicatat untuk tanggal ini.</p>
            <p class="teks-redup" style="font-size:0.85rem;">Tambahkan di kolom sebelah kiri.</p>
          </div>
        <?php else: ?>
          <?php
          // Kelompokkan per waktu makan
          $per_waktu = [];
          foreach ($catatan as $c) {
              $per_waktu[$c['waktu_makan']][] = $c;
          }
          $urutan_waktu = ['pagi', 'siang', 'malam', 'camilan'];
          $ikon_waktu = ['pagi' => '🌅', 'siang' => '☀️', 'malam' => '🌙', 'camilan' => '🍪'];
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

      <p class="catatan" style="margin-top:12px;">Kalori merupakan perkiraan per porsi standar dan bersifat edukatif, bukan pengganti saran ahli gizi.</p>
    </div>

  </div>

<?php endif; ?>

<?php include BASE_DIR . 'includes/footer.php'; ?>


