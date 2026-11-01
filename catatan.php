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

<h2>Catatan kalori</h2>

<?php if ($pesan): ?><div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= h($pesan) ?></div><?php endif; ?>

<?php if (!$terakhir): ?>
  <div class="kosong">
    Kamu belum punya target kalori. Hitung BMI dulu supaya sistem bisa menentukan targetmu.<br><br>
    <a class="tombol" href="bmi.php">Hitung BMI dulu</a>
  </div>

<?php else: ?>
  <form method="get" class="filter-bar catatan-tanggal">
    <div>
      <label for="tanggal-riwayat">Lihat catatan tanggal</label>
      <input type="date" id="tanggal-riwayat" name="tanggal" value="<?= h($tanggal_pilihan) ?>" max="<?= h($tanggal_maks_lihat) ?>">
    </div>
    <button type="submit" class="tombol">Tampilkan</button>
    <?php if ($tanggal_pilihan !== $hari_ini): ?>
      <a class="tombol tombol-sekunder" href="catatan.php">Kembali ke hari ini</a>
    <?php endif; ?>
  </form>

  <?php if ($tanggal_riwayat): ?>
    <nav class="tanggal-cepat" aria-label="Tanggal catatan terbaru">
      <span>Tanggal tercatat:</span>
      <?php foreach ($tanggal_riwayat as $tanggal): ?>
        <a class="<?= $tanggal === $tanggal_pilihan ? 'aktif' : '' ?>" href="catatan.php?<?= h(http_build_query(['tanggal' => $tanggal])) ?>">
          <?= h(date('j M', strtotime($tanggal))) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <p class="teks-redup">
    <?= h($tanggal_teks) ?> &middot; Kategori BMI:
    <span class="badge kategori-<?= h($terakhir['kat_kode']) ?>"><?= h($terakhir['kat_nama']) ?></span>
  </p>

  <div class="ringkas">
    <?php if ($tanggal_pilihan === $hari_ini): ?>
      <div class="kartu"><small>Target harian</small><div class="angka-besar"><?= nf($target) ?></div>kkal</div>
      <div class="kartu"><small>Sudah dicatat</small><div class="angka-besar"><?= nf($total) ?></div>kkal</div>
      <div class="kartu">
        <small><?= $sisa >= 0 ? 'Sisa kalori' : 'Melebihi target' ?></small>
        <div class="angka-besar"><?= nf(abs($sisa)) ?></div>kkal
      </div>
    <?php else: ?>
      <div class="kartu"><small>Total tercatat</small><div class="angka-besar"><?= nf($total) ?></div>kkal</div>
      <div class="kartu"><small>Jumlah catatan</small><div class="angka-besar"><?= count($catatan) ?></div>makanan</div>
      <div class="kartu">
        <small>Rata-rata per makanan</small>
        <div class="angka-besar"><?= count($catatan) > 0 ? nf(round($total / count($catatan))) : 0 ?></div>kkal
      </div>
    <?php endif; ?>
  </div>

  <?php if ($tanggal_pilihan === $hari_ini): ?>
    <div class="progres"><div class="progres-isi <?= $kelas ?>" style="width:<?= min(100, (int)$persen) ?>%"></div></div>
    <p class="catatan"><?= (int)$persen ?>% dari target
      <?php if ($sisa < 0): ?> &middot; Catatan hari ini sudah melewati target kalori perkiraan.<?php endif; ?>
    </p>
  <?php else: ?>
    <p class="catatan">Riwayat ini berisi makanan yang kamu catat untuk tanggal tersebut. Target kalori saat ini tidak digunakan untuk menilai tanggal lampau.</p>
  <?php endif; ?>

  <div class="hasil" style="margin-bottom:20px">
    <h3 style="margin-bottom:6px">Catat makanan lain</h3>
    <p class="teks-redup">Gunakan bagian ini untuk makanan yang belum kamu catat dari Menu Rekomendasi. Catatan disimpan untuk <?= h($tanggal_teks) ?>.</p>
    <form method="post" class="form-tambah">
      <input type="hidden" name="aksi" value="tambah">
      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
      <input type="hidden" name="tanggal" value="<?= h($tanggal_pilihan) ?>">
      <div>
        <label for="menu_id">Menu</label>
        <select id="menu_id" name="menu_id" required>
          <option value="">Pilih menu...</option>
          <?php $w_sebelum = '';
          foreach ($semua_menu as $m):
              if ($m['waktu_makan'] !== $w_sebelum):
                  if ($w_sebelum !== '') echo '</optgroup>';
                  echo '<optgroup label="' . h(ucfirst($m['waktu_makan'])) . '">';
                  $w_sebelum = $m['waktu_makan'];
              endif; ?>
            <option value="<?= (int)$m['id'] ?>"><?= h($m['nama_menu']) ?> (<?= (int)$m['kalori'] ?> kkal)</option>
          <?php endforeach;
          if ($w_sebelum !== '') echo '</optgroup>'; ?>
        </select>
      </div>
      <div style="max-width:180px">
        <label for="porsi">Porsi</label>
        <select id="porsi" name="porsi">
          <option value="0.5">&frac12; porsi</option>
          <option value="1" selected>1 porsi</option>
          <option value="1.5">1,5 porsi</option>
          <option value="2">2 porsi</option>
        </select>
      </div>
      <button type="submit" class="tombol">Tambah</button>
    </form>
  </div>

  <div class="hasil" style="margin-bottom:20px">
    <h3 style="margin-bottom:6px">Makanan yang dicatat &middot; <?= h($tanggal_teks) ?></h3>
    <?php if (!$catatan): ?>
      <p class="teks-redup" style="margin:8px 0 0">Belum ada makanan yang dicatat untuk tanggal ini. Tambahkan di atas jika kamu ingin mengisi riwayatnya.</p>
    <?php else: foreach ($catatan as $c):
        $porsi_teks = rtrim(rtrim(number_format($c['porsi'], 1, ',', ''), '0'), ','); ?>
      <div class="baris-catatan">
        <div>
          <b><?= h($c['nama_menu']) ?></b><br>
          <small><?= h(ucfirst($c['waktu_makan'])) ?> &middot; <?= h($porsi_teks) ?> porsi</small>
        </div>
        <div style="text-align:right">
          <b><?= nf($c['kalori'] * $c['porsi']) ?> kkal</b>
          <form method="post" style="margin-top:6px">
            <input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="tanggal" value="<?= h($tanggal_pilihan) ?>">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="tombol hapus" onclick="return confirm('Hapus catatan ini?')">Hapus</button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($saran): ?>
    <h3 style="margin-bottom:10px">Masih muat di sisa kalorimu</h3>
    <div class="daftar-menu">
      <?php foreach ($saran as $m): ?>
        <article class="menu-kartu">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:4px">
            <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>">
              <?= h(ucfirst($m['waktu_makan'])) ?>
            </span>
            <?php if ($m['vegetarian']): ?>
              <span class="badge kategori-normal" style="font-size:.72rem;padding:2px 8px">Veg</span>
            <?php endif; ?>
          </div>
          <h3><?= h($m['nama_menu']) ?></h3>
          <div class="menu-info"><b><?= (int)$m['kalori'] ?> kkal</b></div>
          <form method="post">
            <input type="hidden" name="aksi" value="tambah">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="tanggal" value="<?= h($tanggal_pilihan) ?>">
            <input type="hidden" name="menu_id" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="porsi" value="1">
            <button type="submit" class="tombol kecil">Catat ini</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p class="catatan">Kalori merupakan perkiraan per porsi standar dan bersifat edukatif, bukan pengganti saran ahli gizi.</p>
<?php endif; ?>

<?php include BASE_DIR . 'includes/footer.php'; ?>


