<?php
require_once __DIR__ . '/config/koneksi.php';
wajib_login();

if (!function_exists('rp')) {
    function rp($n) { return 'Rp' . number_format($n, 0, ',', '.'); }
}

if (!function_exists('cari_menu')) {
    function cari_menu($pdo, $kid, $waktu, $harga, $veg, $acak = false, $semua = false) {
        if ($semua) {
            $sql = 'SELECT DISTINCT m.*, u.nama AS nama_pakar, u.gelar AS gelar_pakar 
                    FROM menu_diet m 
                    LEFT JOIN users u ON u.id = m.pakar_id 
                    WHERE 1=1';
            $param = [];
        } else {
            $sql = 'SELECT DISTINCT m.*, u.nama AS nama_pakar, u.gelar AS gelar_pakar 
                    FROM menu_diet m
                    JOIN menu_kategori mk ON mk.menu_id = m.id
                    LEFT JOIN users u ON u.id = m.pakar_id
                    WHERE mk.kategori_id = ?';
            $param = [$kid];
        }
        if ($waktu !== '') { $sql .= ' AND m.waktu_makan = ?'; $param[] = $waktu; }
        if ($harga > 0)    { $sql .= ' AND m.harga <= ?';      $param[] = $harga; }
        if ($veg)          { $sql .= ' AND m.vegetarian = 1'; }
        $sql .= $acak ? ' ORDER BY RAND() LIMIT 1' : ' ORDER BY m.waktu_makan, m.kalori';
        $q = $pdo->prepare($sql);
        $q->execute($param);
        return $q->fetchAll();
    }
}

$q = $pdo->prepare('SELECT r.*, k.nama AS kat_nama, k.kode AS kat_kode
                    FROM riwayat_bmi r JOIN kategori_bmi k ON k.id = r.kategori_id
                    WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1');
$q->execute([$_SESSION['user_id']]);
$terakhir = $q->fetch();

$q = $pdo->prepare("SELECT target_kalori FROM rencana_diet WHERE user_id = ? AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$q->execute([$_SESSION['user_id']]);
$rencana = $q->fetch();
if ($rencana && $terakhir) $terakhir['target_kalori'] = $rencana['target_kalori'];

$daftar_waktu = ['pagi', 'siang', 'malam', 'camilan'];
$bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
          'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$waktu  = in_array($_GET['waktu'] ?? '', $daftar_waktu, true) ? $_GET['waktu'] : '';
$harga  = max(0, (int)($_GET['harga'] ?? 0));
$veg    = isset($_GET['veg']) && $_GET['veg'] === '1';
$hari_ini = date('Y-m-d');

$csrf_token = csrf_token('csrf_token_menu');

// ── Catat makanan langsung dari kartu rekomendasi menu ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'catat_manual') {
    $token   = $_POST['csrf_token'] ?? '';
    $menu_id = (int)($_POST['menu_id'] ?? 0);
    $porsi   = (float)($_POST['porsi'] ?? 1);

    if (!csrf_valid($token, 'csrf_token_menu')) {
        $_SESSION['pesan_menu']      = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $_SESSION['pesan_menu_tipe'] = 'error';
    } elseif (!in_array($porsi, [0.5, 1.0, 1.5, 2.0], true)) {
        $_SESSION['pesan_menu']      = 'Jumlah porsi tidak valid.';
        $_SESSION['pesan_menu_tipe'] = 'error';
    } else {
        $st_cek = $pdo->prepare('SELECT nama_menu, kalori FROM menu_diet WHERE id = ?');
        $st_cek->execute([$menu_id]);
        $menu_info = $st_cek->fetch();

        if ($menu_info) {
            $ins = $pdo->prepare('INSERT INTO catatan_makan (user_id, menu_id, porsi, tanggal) VALUES (?, ?, ?, ?)');
            $ins->execute([$_SESSION['user_id'], $menu_id, $porsi, $hari_ini]);
            $kalori_masuk = (int)round($menu_info['kalori'] * $porsi);
            $_SESSION['pesan_menu']      = "Berhasil mencatat '{$menu_info['nama_menu']}' ({$porsi} porsi · {$kalori_masuk} kkal) ke Catatan Kalori hari ini!";
            $_SESSION['pesan_menu_tipe'] = 'sukses';
        } else {
            $_SESSION['pesan_menu']      = 'Menu makanan tidak ditemukan.';
            $_SESSION['pesan_menu_tipe'] = 'error';
        }
    }
    $query_str = $_GET;
    header('Location: menu.php?' . http_build_query($query_str));
    exit;
}

$pesan_menu      = $_SESSION['pesan_menu'] ?? '';
$pesan_menu_tipe = $_SESSION['pesan_menu_tipe'] ?? 'sukses';
unset($_SESSION['pesan_menu'], $_SESSION['pesan_menu_tipe']);

// Hitung kalori hari ini untuk fitur Cerdas (Kontribusi & Sisa Kalori)
$tercatat_hari_ini = 0;
try {
    $st_kal = $pdo->prepare('SELECT SUM(m.kalori * c.porsi) FROM catatan_makan c JOIN menu_diet m ON m.id = c.menu_id WHERE c.user_id = ? AND c.tanggal = ?');
    $st_kal->execute([$_SESSION['user_id'], $hari_ini]);
    $tercatat_hari_ini = (int)round($st_kal->fetchColumn() ?: 0);
} catch (Exception $e) {
    $tercatat_hari_ini = 0;
}
$target_hari_ini = (int)($terakhir['target_kalori'] ?? 2000);
$sisa_hari_ini   = max(0, $target_hari_ini - $tercatat_hari_ini);

$lingkup = $_GET['lingkup'] ?? 'rekomendasi';
if (!in_array($lingkup, ['rekomendasi', 'semua'], true)) {
    $lingkup = 'rekomendasi';
}

if ($terakhir) {
    $kid = (int)$terakhir['kategori_id'];
    $menu = cari_menu($pdo, $kid, $waktu, $harga, $veg, false, ($lingkup === 'semua'));
} else {
    // Jika belum ada BMI, tampilkan semua menu
    $menu = cari_menu($pdo, 0, $waktu, $harga, $veg, false, true);
}

$ikon_waktu = [
    'pagi'    => '',
    'siang'   => '',
    'malam'   => '',
    'camilan' => '',
];


// Paginasi tampilan daftar menu (8 per halaman - 2 baris x 4 kolom)
$halaman    = max(1, (int)($_GET['hal'] ?? 1));
$per_hal    = 8;
$total_menu = count($menu);
$total_hal  = (int)ceil($total_menu / $per_hal);
$halaman    = min($halaman, max(1, $total_hal));
$menu_tampil = array_slice($menu, ($halaman - 1) * $per_hal, $per_hal);

// Ambil rincian komponen takaran untuk menu yang sedang tampil di halaman ini
$ids_tampil = array_column($menu_tampil, 'id');
$komponen_per_menu = [];
if (!empty($ids_tampil)) {
    try {
        $in_clause = implode(',', array_fill(0, count($ids_tampil), '?'));
        $st_komp = $pdo->prepare("SELECT * FROM menu_komponen WHERE menu_id IN ($in_clause) ORDER BY id ASC");
        $st_komp->execute($ids_tampil);
        while ($row = $st_komp->fetch()) {
            $komponen_per_menu[$row['menu_id']][] = $row;
        }
    } catch (Exception $e) {
        $komponen_per_menu = [];
    }
}

// Data terstruktur untuk modal takaran interaktif
$data_takaran_json = [];
foreach ($menu_tampil as $m) {
    $mid = (int)$m['id'];
    $data_takaran_json[$mid] = [
        'id'          => $mid,
        'nama'        => $m['nama_menu'],
        'waktu'       => ucfirst($m['waktu_makan']),
        'waktu_raw'   => $m['waktu_makan'],
        'deskripsi'   => $m['deskripsi'] ?? '',
        'kalori'      => (int)$m['kalori'],
        'berat'       => !empty($m['berat_gram']) ? (int)$m['berat_gram'] : 0,
        'harga'       => rp($m['harga']),
        'harga_raw'   => (int)$m['harga'],
        'takaran'     => $m['takaran_saji'] ?? '',
        'vegetarian'  => !empty($m['vegetarian']),
        'komponen'    => $komponen_per_menu[$mid] ?? []
    ];
}

$judul = 'Menu Rekomendasi';
include BASE_DIR . 'includes/header.php';
?>

<div class="page-hero-header">
  <div class="page-hero-icon">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
  </div>
  <div class="page-hero-teks">
    <h2>Menu Rekomendasi</h2>
    <p>Pilih menu sarapan, makan siang, malam, dan camilan sesuai target kalori dan BMI-mu.</p>
  </div>
</div>

<?php if (!$terakhir): ?>
  <div class="kosong">
    Kamu belum menghitung BMI, jadi sistem belum tahu menu yang cocok.<br><br>
    <a class="tombol" href="bmi.php">Hitung BMI dulu</a>
  </div>

<?php else: ?>
  <div class="kartu" style="margin-bottom: 20px; padding: 18px 22px; background: linear-gradient(135deg, #FFFFFF 0%, #FAF8F5 100%);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
      <div>
        <p style="margin: 0 0 6px; font-size: 0.95rem;">
          Berdasarkan BMI terakhirmu (<b><?= h(number_format($terakhir['bmi'], 1, ',', '.')) ?></b>):
          <span class="badge kategori-<?= h($terakhir['kat_kode']) ?>"><?= h($terakhir['kat_nama']) ?></span>
          &middot; Target kalori harian: <b><?= h(number_format((int)$terakhir['target_kalori'], 0, ',', '.')) ?> kkal</b>
        </p>
        <p class="teks-redup" style="margin: 0; font-size: 0.86rem; line-height: 1.5;">
          <b>Mengapa menu difilter?</b> Sistem otomatis menyaring makanan yang paling sesuai dengan kebutuhan nutrisimu (misal: porsi defisit aman bagi kategori Gemuk/Obesitas, atau padat kalori bagi kategori Kurus). Kamu tetap bebas melihat dan memilih seluruh menu dari katalog lengkap.
        </p>
      </div>

      <!-- Tab Beralih: Khusus Rekomendasi vs Seluruh Katalog -->
      <div style="display: flex; gap: 6px; background: var(--latar); padding: 4px; border-radius: var(--radius-pil); border: 1px solid var(--garis);">
        <a href="?<?= h(http_build_query(array_merge($_GET, ['lingkup' => 'rekomendasi', 'hal' => 1]))) ?>" 
           class="tombol kecil <?= $lingkup === 'rekomendasi' ? '' : 'tombol-sekunder' ?>" 
           style="border-radius: var(--radius-pil); min-height: 32px; padding: 4px 14px; font-size: 0.82rem; text-decoration: none;">
          Sesuai BMI Saya
        </a>
        <a href="?<?= h(http_build_query(array_merge($_GET, ['lingkup' => 'semua', 'hal' => 1]))) ?>" 
           class="tombol kecil <?= $lingkup === 'semua' ? '' : 'tombol-sekunder' ?>" 
           style="border-radius: var(--radius-pil); min-height: 32px; padding: 4px 14px; font-size: 0.82rem; text-decoration: none;">
          Semua 240+ Menu
        </a>
      </div>
    </div>
  </div>

  <form method="get" class="filter-bar">
    <input type="hidden" name="lingkup" value="<?= h($lingkup) ?>">
    <div>
      <label for="waktu">Waktu makan</label>
      <select id="waktu" name="waktu">
        <option value="">Semua</option>
        <?php foreach ($daftar_waktu as $w): ?>
          <option value="<?= $w ?>" <?= $waktu === $w ? 'selected' : '' ?>><?= ucfirst($w) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="harga">Harga maks. per menu</label>
      <select id="harga" name="harga">
        <option value="0">Bebas</option>
        <?php foreach ([8000, 10000, 13000, 15000, 18000, 20000, 25000] as $hg): ?>
          <option value="<?= $hg ?>" <?= $harga === $hg ? 'selected' : '' ?>><?= rp($hg) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="cek">
      <label><input type="checkbox" name="veg" value="1" <?= $veg ? 'checked' : '' ?>> Vegetarian saja</label>
    </div>

    <button type="submit" class="tombol">Terapkan filter</button>
  </form>

  <?php if ($pesan_menu): ?>
    <div class="alert <?= $pesan_menu_tipe === 'error' ? 'error' : 'sukses' ?>" style="margin-top: 16px;"><?= h($pesan_menu) ?></div>
  <?php endif; ?>

  <div class="kartu" style="margin: 16px 0 20px; padding: 16px 20px; background: var(--permukaan); border: 1px solid var(--garis); border-radius: var(--radius-kartu);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
      <div style="display: flex; gap: 28px; flex-wrap: wrap; align-items: center;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--teks-redup); text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 2px;">Target Kalori</span>
          <strong style="font-size: 1.2rem; color: var(--gelap);"><?= number_format($target_hari_ini, 0, ',', '.') ?> <small style="font-size:0.75rem; font-weight:600; color:var(--teks-redup);">kkal</small></strong>
        </div>
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--teks-redup); text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 2px;">Tercatat Hari Ini</span>
          <strong style="font-size: 1.2rem; color: var(--gelap);"><?= number_format($tercatat_hari_ini, 0, ',', '.') ?> <small style="font-size:0.75rem; font-weight:600; color:var(--teks-redup);">kkal</small></strong>
        </div>
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; color: var(--teks-redup); text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 2px;">Sisa Kalori</span>
          <strong style="font-size: 1.2rem; color: <?= $sisa_hari_ini > 0 ? 'var(--hijau)' : '#DC2626' ?>;"><?= number_format($sisa_hari_ini, 0, ',', '.') ?> <small style="font-size:0.75rem; font-weight:600; color:var(--teks-redup);">kkal</small></strong>
        </div>
      </div>
      <div>
        <a href="catatan.php" class="tombol kecil tombol-sekunder" style="text-decoration: none; padding: 7px 16px; font-weight: 600;">
          Buka Catatan Kalori &rarr;
        </a>
      </div>
    </div>
  </div>

  <?php if ($menu): ?>
    <?php
    $params_nav = array_filter(['waktu' => $waktu ?: null, 'harga' => $harga ?: null, 'veg' => $veg ? '1' : null, 'lingkup' => $lingkup !== 'rekomendasi' ? $lingkup : null], fn($v) => $v !== null && $v !== '');
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
      <p class="teks-redup" style="margin:0">
        Menampilkan <?= number_format(count($menu_tampil), 0, ',', '.') ?> dari <?= number_format($total_menu, 0, ',', '.') ?> menu
        <?php if ($waktu !== ''): ?>
          kategori <b><?= h(ucfirst($waktu)) ?></b>
        <?php endif; ?>
        <?php if ($total_hal > 1): ?> &middot; Halaman <?= $halaman ?> / <?= $total_hal ?><?php endif; ?>
      </p>
    </div>

    <!-- Grid Menu Makanan dengan Kategori Sesuai Waktu Makan -->
    <div class="daftar-menu">
        <?php foreach ($menu_tampil as $m): ?>
          <article class="menu-kartu" style="display:flex; flex-direction:column; justify-content:space-between; height:100%;">
            <div>
              <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:6px">
                <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>">
                  <?= $ikon_waktu[$m['waktu_makan']] ?? '' ?> <?= h(ucfirst($m['waktu_makan'])) ?>
                </span>
                <?php if ($m['vegetarian']): ?>
                  <span class="badge kategori-normal" style="font-size:.72rem;padding:2px 8px">Vegetarian</span>
                <?php endif; ?>
              </div>
              <h3 style="margin: 0 0 6px; font-size: 1.08rem; line-height: 1.35;"><?= h($m['nama_menu']) ?></h3>
              <?php if (!empty($m['nama_pakar'])): ?>
                <div style="margin-bottom: 8px;">
                  <span class="badge" style="background: #EAF3EE; color: var(--hijau); font-weight: 700; font-size: 0.7rem; padding: 2px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Disusun oleh <?= h($m['gelar_pakar'] ? $m['gelar_pakar'] . ' ' : '') ?><?= h($m['nama_pakar']) ?>
                  </span>
                </div>
              <?php endif; ?>
              <p class="teks-redup" style="font-size:0.85rem; margin:0 0 14px; line-height:1.45; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;"><?= h($m['deskripsi']) ?></p>
            </div>

            <div style="margin-top:auto; padding-top:10px; border-top:1px solid var(--garis);">
              <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:6px;">
                <div>
                  <strong style="font-size:1.15rem; color:var(--gelap);"><?= (int)$m['kalori'] ?></strong>
                  <span style="font-size:0.75rem; font-weight:600; color:var(--teks-redup);">kkal</span>
                  <?php if (!empty($m['berat_gram'])): ?>
                    <span style="font-size:0.78rem; font-weight:500; color:var(--teks-redup); margin-left:2px;">(<?= (int)$m['berat_gram'] ?>g)</span>
                  <?php endif; ?>
                </div>
                <strong style="font-size:0.95rem; color:var(--gelap);"><?= rp($m['harga']) ?></strong>
              </div>

              <!-- Micro Pill Badges (Tanpa icon target) -->
              <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px;">
                <span style="display:inline-flex; align-items:center; background:var(--latar); color:var(--teks-redup); border:1px solid var(--garis); padding:3px 9px; border-radius:var(--radius-pil); font-size:0.72rem; font-weight:600; white-space:nowrap;">
                  ~<?= round(((int)$m['kalori'] / max(1, $target_hari_ini)) * 100) ?>% Kebutuhan
                </span>
                <?php if ($sisa_hari_ini > 0 && (int)$m['kalori'] <= $sisa_hari_ini): ?>
                  <span style="display:inline-flex; align-items:center; background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0; padding:3px 9px; border-radius:var(--radius-pil); font-size:0.72rem; font-weight:700; white-space:nowrap;">
                    ✓ Muat di Sisa
                  </span>
                <?php endif; ?>
              </div>

              <!-- Tombol Rapi untuk Melihat Rincian Takaran -->
              <button type="button" class="tombol sekunder kecil"
                      onclick="bukaModalTakaran(<?= (int)$m['id'] ?>, parseFloat(this.closest('article').querySelector('select.menu-porsi').value))"
                      style="width:100%; height:36px; margin-bottom:8px; font-size:0.8rem; font-weight:600; background:var(--latar); border:1px solid var(--garis); border-radius:var(--radius-kecil); display:inline-flex; align-items:center; justify-content:center; cursor:pointer;">
                Lihat Takaran
              </button>

              <!-- Tombol Pilih & Catat Manual (Sejajar sempurna) -->
              <form method="post" style="display: flex; gap: 6px; align-items: center; margin:0;">
                <input type="hidden" name="aksi" value="catat_manual">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="menu_id" value="<?= (int)$m['id'] ?>">
                <select name="porsi" class="menu-porsi" style="height: 36px; padding: 0 8px; font-size: 0.8rem; margin: 0; width: 95px; flex-shrink: 0; border: 1px solid var(--garis); border-radius: var(--radius-kecil); background: var(--latar); cursor: pointer;">
                  <option value="0.5">&frac12; porsi</option>
                  <option value="1" selected>1 porsi</option>
                  <option value="1.5">1,5 porsi</option>
                  <option value="2">2 porsi</option>
                </select>
                <button type="submit" class="tombol kecil" style="padding: 0 12px; height: 36px; font-size: 0.8rem; font-weight: 700; margin: 0; flex: 1; border-radius: var(--radius-kecil); white-space: nowrap; display: inline-flex; align-items: center; justify-content: center;">
                  + Catat Makan
                </button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

    <?php if ($total_hal > 1): ?>
      <nav class="paginasi" style="margin-top:32px; margin-bottom:18px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:center;">
        <?php
        $params_nav_hal = $params_nav;
        if ($halaman > 1): ?>
          <a class="tombol tombol-sekunder kecil" href="?<?= h(http_build_query($params_nav_hal + ['hal' => $halaman - 1])) ?>">&larr; Sebelumnya</a>
        <?php endif;
        $start = max(1, $halaman - 2);
        $end   = min($total_hal, $halaman + 2);
        for ($p = $start; $p <= $end; $p++): ?>
          <a class="tombol <?= $p === $halaman ? '' : 'tombol-sekunder' ?> kecil" href="?<?= h(http_build_query($params_nav_hal + ['hal' => $p])) ?>"><?= $p ?></a>
        <?php endfor;
        if ($halaman < $total_hal): ?>
          <a class="tombol tombol-sekunder kecil" href="?<?= h(http_build_query($params_nav_hal + ['hal' => $halaman + 1])) ?>">Berikutnya &rarr;</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>

  <?php else: ?>
    <div class="kosong">Tidak ada menu yang cocok. Coba longgarkan filter harga atau pilih kategori waktu makan lainnya.</div>
  <?php endif; ?>

  <p class="catatan" style="text-align:center; margin-top:12px;">Kalori merupakan perkiraan per porsi standar dan bersifat edukatif, bukan pengganti saran ahli gizi.</p>
<?php endif; ?>

<!-- MODAL DETAIL TAKARAN & KOMPOSISI BAHAN -->
<div id="modal-takaran-menu" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:999; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(3px);">
  <div style="background:var(--permukaan); border-radius:var(--radius-kartu); padding:24px; max-width:540px; width:100%; box-shadow:0 24px 60px rgba(0,0,0,0.25); border:1px solid var(--garis); max-height:90vh; overflow-y:auto; box-sizing:border-box;">
    
    <!-- Header Modal -->
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px;">
      <div>
        <span id="modal-badge-waktu" class="badge-waktu" style="margin-bottom:6px; display:inline-block;"></span>
        <h3 id="modal-nama-menu" style="margin:0; font-size:1.25rem; font-weight:800; color:var(--gelap); line-height:1.3;"></h3>
        <p id="modal-deskripsi-menu" class="teks-redup" style="margin:4px 0 0; font-size:0.85rem; line-height:1.45;"></p>
      </div>
      <button type="button" onclick="tutupModalTakaran()" style="background:none; border:none; font-size:1.6rem; line-height:1; color:var(--teks-redup); cursor:pointer; padding:2px 6px;" aria-label="Tutup">&times;</button>
    </div>

    <!-- Pilihan Porsi Segmented Pills -->
    <div style="margin-bottom:16px;">
      <div style="font-size:0.75rem; font-weight:700; color:var(--teks-redup); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:8px;">Pilih Takaran Porsi:</div>
      <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:6px;">
        <button type="button" class="btn-porsi-modal" data-porsi="0.5" onclick="setModalPorsi(0.5)" style="height:34px; font-size:0.8rem; font-weight:600; border:1px solid var(--garis); border-radius:var(--radius-kecil); background:var(--latar); color:var(--gelap); cursor:pointer; transition:all 0.15s ease;">½ Porsi</button>
        <button type="button" class="btn-porsi-modal" data-porsi="1" onclick="setModalPorsi(1)" style="height:34px; font-size:0.8rem; font-weight:600; border:1px solid var(--garis); border-radius:var(--radius-kecil); background:var(--latar); color:var(--gelap); cursor:pointer; transition:all 0.15s ease;">1 Porsi</button>
        <button type="button" class="btn-porsi-modal" data-porsi="1.5" onclick="setModalPorsi(1.5)" style="height:34px; font-size:0.8rem; font-weight:600; border:1px solid var(--garis); border-radius:var(--radius-kecil); background:var(--latar); color:var(--gelap); cursor:pointer; transition:all 0.15s ease;">1½ Porsi</button>
        <button type="button" class="btn-porsi-modal" data-porsi="2" onclick="setModalPorsi(2)" style="height:34px; font-size:0.8rem; font-weight:600; border:1px solid var(--garis); border-radius:var(--radius-kecil); background:var(--latar); color:var(--gelap); cursor:pointer; transition:all 0.15s ease;">2 Porsi</button>
      </div>
      <input type="hidden" id="modal-pilih-porsi" value="1">
    </div>

    <!-- Ringkasan Gizi & Porsi (Stat Cards Dinamis) -->
    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-bottom:16px;">
      <div style="background:#ECFDF5; border:1px solid #A7F3D0; border-radius:var(--radius-kecil); padding:10px 8px; text-align:center;">
        <span style="font-size:0.7rem; font-weight:700; color:#065F46; text-transform:uppercase; letter-spacing:0.04em; display:block;">Kalori</span>
        <strong id="modal-kalori-menu" style="font-size:1.15rem; color:#065F46; display:block; margin-top:2px;"></strong>
      </div>
      <div style="background:var(--latar); border:1px solid var(--garis); border-radius:var(--radius-kecil); padding:10px 8px; text-align:center;">
        <span style="font-size:0.7rem; font-weight:700; color:var(--teks-redup); text-transform:uppercase; letter-spacing:0.04em; display:block;">Total Berat</span>
        <strong id="modal-berat-menu" style="font-size:1.15rem; color:var(--gelap); display:block; margin-top:2px;"></strong>
      </div>
      <div style="background:var(--latar); border:1px solid var(--garis); border-radius:var(--radius-kecil); padding:10px 8px; text-align:center;">
        <span style="font-size:0.7rem; font-weight:700; color:var(--teks-redup); text-transform:uppercase; letter-spacing:0.04em; display:block;">Perkiraan Harga</span>
        <strong id="modal-harga-menu" style="font-size:1.15rem; color:var(--gelap); display:block; margin-top:2px;"></strong>
      </div>
    </div>

    <!-- Kotak Ringkasan Takaran (dinamis) -->
    <div style="margin-bottom:16px; background:var(--latar); border:1px solid var(--garis); padding:12px 14px; border-radius:var(--radius-kecil);">
      <div id="modal-takaran-label" style="font-size:0.72rem; font-weight:700; color:var(--teks-redup); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;">Takaran 1 Porsi Standar:</div>
      <div id="modal-takaran-teks" style="font-size:0.88rem; font-weight:600; color:var(--gelap); line-height:1.45;"></div>
    </div>

    <!-- Rincian Komponen Bahan Modern (Bebas horizontal scrollbar) -->
    <div id="modal-komponen-area" style="margin-bottom:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <span style="font-size:0.75rem; font-weight:700; color:var(--teks-redup); text-transform:uppercase; letter-spacing:0.04em;">Komposisi &amp; Rincian Bahan</span>
        <span id="modal-komponen-count" style="font-size:0.75rem; color:var(--teks-redup); font-weight:600;"></span>
      </div>
      <div id="modal-komponen-list" style="display:flex; flex-direction:column; gap:6px;"></div>
    </div>

    <!-- Footer Modal dengan Tombol Catat Langsung -->
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding-top:14px; border-top:1px solid var(--garis); margin-top:18px;">
      <button type="button" onclick="tutupModalTakaran()" class="tombol sekunder kecil" style="padding:8px 18px; font-weight:600;">Tutup</button>
      <form method="post" id="modal-form-catat" style="margin:0;">
        <input type="hidden" name="aksi" value="catat_manual">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
        <input type="hidden" name="menu_id" id="modal-catat-menu-id" value="">
        <input type="hidden" name="porsi" id="modal-catat-porsi" value="1">
        <button type="submit" class="tombol kecil" style="padding:8px 20px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
          + Catat Menu Ini (<span id="modal-catat-kalori-btn"></span>)
        </button>
      </form>
    </div>
  </div>
</div>

<script>
  const daftarTakaran = <?= json_encode($data_takaran_json ?? [], JSON_UNESCAPED_UNICODE) ?>;
  let modalMenuAktif = null;

  function fmtAngka(n) {
    if (n % 1 === 0) return Math.round(n).toString();
    return n.toFixed(1).replace('.', ',');
  }

  function fmtRupiah(n) {
    return 'Rp' + Math.round(n).toLocaleString('id-ID');
  }

  function labelPorsi(p) {
    if (p === 0.5)  return '½ porsi';
    if (p === 1)    return '1 porsi (standar)';
    if (p === 1.5)  return '1½ porsi';
    if (p === 2)    return '2 porsi';
    return p + ' porsi';
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function skalaGram(teks, porsi) {
    return teks.replace(/\((\d+(?:[.,]\d+)?)g\)/g, (match, angka) => {
      const n = parseFloat(angka.replace(',', '.'));
      return '(' + fmtAngka(n * porsi) + 'g)';
    });
  }

  function skalaSatuan(teks, porsi) {
    const satuan = 'piring|mangkuk|porsi|butir|potong|gelas|lembar|buah|sdm|sendok|ekor|bungkus|sachet';
    return teks.replace(
      new RegExp('(\\d+(?:[,.]\\d+)?)\\s(' + satuan + ')', 'g'),
      (match, angka, sat) => {
        const n = parseFloat(angka.replace(',', '.'));
        return fmtAngka(n * porsi) + ' ' + sat;
      }
    );
  }

  function skalakanTakaranTeks(teks, porsi, beratTotal) {
    if (!teks) {
      return beratTotal > 0
        ? labelPorsi(porsi) + ' (' + Math.round(beratTotal * porsi) + 'g)'
        : labelPorsi(porsi);
    }
    let hasil = skalaGram(teks, porsi);
    hasil = skalaSatuan(hasil, porsi);
    return hasil;
  }

  function setModalPorsi(p) {
    document.getElementById('modal-pilih-porsi').value = p;
    document.querySelectorAll('.btn-porsi-modal').forEach(btn => {
      const isAktif = parseFloat(btn.getAttribute('data-porsi')) === parseFloat(p);
      if (isAktif) {
        btn.style.background = 'var(--gelap)';
        btn.style.color = '#ffffff';
        btn.style.borderColor = 'var(--gelap)';
      } else {
        btn.style.background = 'var(--latar)';
        btn.style.color = 'var(--gelap)';
        btn.style.borderColor = 'var(--garis)';
      }
    });
    renderModalPorsi();
  }

  function renderModalPorsi() {
    if (!modalMenuAktif) return;
    const item   = daftarTakaran[modalMenuAktif];
    if (!item) return;

    const porsi  = parseFloat(document.getElementById('modal-pilih-porsi').value);

    // Kalori, berat, harga
    const kalori = Math.round(item.kalori * porsi);
    const berat  = item.berat > 0 ? Math.round(item.berat * porsi) : 0;
    const harga  = item.harga_raw * porsi;

    document.getElementById('modal-kalori-menu').textContent = kalori + ' kkal';
    document.getElementById('modal-berat-menu').textContent  = berat > 0 ? berat + 'g' : '-';
    document.getElementById('modal-harga-menu').textContent  = fmtRupiah(harga);

    // Label & teks ringkasan takaran
    document.getElementById('modal-takaran-label').textContent = 'Takaran ' + labelPorsi(porsi) + ':';
    document.getElementById('modal-takaran-teks').textContent = skalakanTakaranTeks(item.takaran, porsi, item.berat);

    // Update form catat di footer modal
    document.getElementById('modal-catat-menu-id').value = item.id;
    document.getElementById('modal-catat-porsi').value   = porsi;
    document.getElementById('modal-catat-kalori-btn').textContent = kalori + ' kkal';

    // Render daftar komponen bahan bergaya card (bebas horizontal scrollbar)
    const kompList = document.getElementById('modal-komponen-list');
    kompList.innerHTML = '';

    if (item.komponen && item.komponen.length > 0) {
      document.getElementById('modal-komponen-area').style.display = 'block';
      document.getElementById('modal-komponen-count').textContent = item.komponen.length + ' bahan';

      item.komponen.forEach(k => {
        const beratK  = parseFloat(k.berat_gram)      > 0 ? parseFloat(k.berat_gram)      * porsi : 0;
        const kaloriK = parseFloat(k.kalori_komponen) > 0 ? parseFloat(k.kalori_komponen) * porsi : 0;

        const gramStr   = beratK  > 0 ? fmtAngka(beratK)  + 'g'     : '';
        const kaloriStr = kaloriK > 0 ? fmtAngka(kaloriK) + ' kkal' : '';

        let takaranK = k.takaran || '-';
        if (porsi !== 1 && takaranK !== '-') {
          takaranK = skalaGram(takaranK, porsi);
          takaranK = skalaSatuan(takaranK, porsi);
        }

        let subParts = [takaranK, gramStr, k.kondisi].filter(Boolean);
        let subDetail = subParts.join(' · ');

        const card = document.createElement('div');
        card.style.cssText = 'display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--latar); border:1px solid var(--garis); border-radius:var(--radius-kecil); gap:12px;';
        card.innerHTML = `
          <div style="min-width:0;">
            <strong style="font-size:0.88rem; color:var(--gelap); display:block; margin-bottom:2px;">${escapeHtml(k.nama_komponen)}</strong>
            <span style="font-size:0.78rem; color:var(--teks-redup);">${escapeHtml(subDetail)}</span>
          </div>
          <div style="text-align:right; flex-shrink:0;">
            <strong style="font-size:0.88rem; color:var(--hijau);">${escapeHtml(kaloriStr)}</strong>
          </div>
        `;
        kompList.appendChild(card);
      });
    } else {
      document.getElementById('modal-komponen-area').style.display = 'none';
    }
  }

  function bukaModalTakaran(id, porsiAwal) {
    const item = daftarTakaran[id];
    if (!item) return;

    modalMenuAktif = id;

    // Set header
    document.getElementById('modal-nama-menu').textContent      = item.nama;
    document.getElementById('modal-deskripsi-menu').textContent = item.deskripsi;
    const badgeWaktu = document.getElementById('modal-badge-waktu');
    badgeWaktu.className   = 'badge-waktu badge-waktu-' + item.waktu_raw;
    badgeWaktu.textContent = item.waktu;

    // Set porsi awal (sinkron dengan pilihan di kartu)
    const porsiValid = [0.5, 1, 1.5, 2];
    const porsi = porsiValid.includes(porsiAwal) ? porsiAwal : 1;
    setModalPorsi(porsi);

    document.getElementById('modal-takaran-menu').style.display = 'flex';
  }

  function tutupModalTakaran() {
    const modal = document.getElementById('modal-takaran-menu');
    if (modal) modal.style.display = 'none';
    modalMenuAktif = null;
  }

  window.addEventListener('click', function(e) {
    const modal = document.getElementById('modal-takaran-menu');
    if (e.target === modal) tutupModalTakaran();
  });

  window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') tutupModalTakaran();
  });
</script>

<?php include BASE_DIR . 'includes/footer.php'; ?>



