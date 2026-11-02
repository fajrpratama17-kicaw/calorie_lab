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

$filter = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_menu_harian' ? $_POST : $_GET;
$waktu  = in_array($filter['waktu'] ?? '', $daftar_waktu, true) ? $filter['waktu'] : '';
$harga  = max(0, (int)($filter['harga'] ?? 0));
$veg    = isset($filter['veg']) && $filter['veg'] === '1';
$budget = max(0, (int)($filter['budget'] ?? 0));
$hari_ini = date('Y-m-d');

$csrf_token = csrf_token('csrf_token_menu_harian');

$error_menu_harian = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'catat_dimakan') {
    $token = $_POST['csrf_token'] ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);
    $porsi_dimakan = (float)($_POST['porsi'] ?? 1);

    if (!csrf_valid($token, 'csrf_token_menu_harian')) {
        $_SESSION['pesan_menu_harian'] = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $_SESSION['pesan_menu_harian_tipe'] = 'error';
        $item_tanggal = $hari_ini;
    } elseif (!in_array($porsi_dimakan, [0.5, 1.0, 1.5, 2.0], true)) {
        $_SESSION['pesan_menu_harian'] = 'Jumlah porsi tidak valid. Pilih jumlah porsi yang tersedia.';
        $_SESSION['pesan_menu_harian_tipe'] = 'error';
        $item_tanggal = $hari_ini;
    } else {
        $pdo->beginTransaction();
        $st = $pdo->prepare(
            'SELECT i.menu_id, i.catatan_makan_id, mh.tanggal
             FROM menu_harian_item i
             JOIN menu_harian mh ON mh.id = i.menu_harian_id
             WHERE i.id = ? AND mh.user_id = ?
             FOR UPDATE'
        );
        $st->execute([$item_id, $_SESSION['user_id']]);
        $item = $st->fetch();

        if (!$item) {
            $pdo->rollBack();
            $_SESSION['pesan_menu_harian'] = 'Menu tidak ditemukan di rencana harian akunmu.';
            $_SESSION['pesan_menu_harian_tipe'] = 'error';
            $item_tanggal = $hari_ini;
        } elseif ($item['tanggal'] > $hari_ini) {
            $pdo->rollBack();
            $_SESSION['pesan_menu_harian'] = 'Menu pada rencana tanggal mendatang belum dapat dicatat sebagai makanan yang telah dikonsumsi.';
            $_SESSION['pesan_menu_harian_tipe'] = 'error';
            $item_tanggal = $item['tanggal'];
        } elseif ($item['catatan_makan_id'] !== null) {
            $pdo->commit();
            $_SESSION['pesan_menu_harian'] = 'Menu tersebut sudah tercatat sebagai makanan.';
            $_SESSION['pesan_menu_harian_tipe'] = 'sukses';
            $item_tanggal = $item['tanggal'];
        } else {
            $st = $pdo->prepare('INSERT INTO catatan_makan (user_id, menu_id, porsi, tanggal) VALUES (?, ?, ?, ?)');
            $st->execute([$_SESSION['user_id'], $item['menu_id'], $porsi_dimakan, $item['tanggal']]);
            $catatan_makan_id = (int)$pdo->lastInsertId();
            $st = $pdo->prepare('UPDATE menu_harian_item SET catatan_makan_id = ? WHERE id = ?');
            $st->execute([$catatan_makan_id, $item_id]);
            $pdo->commit();
            $_SESSION['pesan_menu_harian'] = 'Menu tercatat di Catatan Kalori sebagai makanan yang kamu konsumsi.';
            $_SESSION['pesan_menu_harian_tipe'] = 'sukses';
            $item_tanggal = $item['tanggal'];
        }
    }

    $anchor = ($item_tanggal === $hari_ini) ? '' : '#riwayat-menu';
    header('Location: menu.php' . $anchor);
    exit;
}

// â”€â”€ Catat makanan manual langsung dari kartu menu â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'catat_manual') {
    $token   = $_POST['csrf_token'] ?? '';
    $menu_id = (int)($_POST['menu_id'] ?? 0);
    $porsi   = (float)($_POST['porsi'] ?? 1);

    if (!csrf_valid($token, 'csrf_token_menu_harian')) {
        $_SESSION['pesan_menu_harian']      = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $_SESSION['pesan_menu_harian_tipe'] = 'error';
    } elseif (!in_array($porsi, [0.5, 1.0, 1.5, 2.0], true)) {
        $_SESSION['pesan_menu_harian']      = 'Jumlah porsi tidak valid.';
        $_SESSION['pesan_menu_harian_tipe'] = 'error';
    } else {
        $st_cek = $pdo->prepare('SELECT nama_menu FROM menu_diet WHERE id = ?');
        $st_cek->execute([$menu_id]);
        $m_nama = $st_cek->fetchColumn();

        if ($m_nama) {
            $ins = $pdo->prepare('INSERT INTO catatan_makan (user_id, menu_id, porsi, tanggal) VALUES (?, ?, ?, ?)');
            $ins->execute([$_SESSION['user_id'], $menu_id, $porsi, $hari_ini]);
            $_SESSION['pesan_menu_harian']      = "Berhasil mencatat '{$m_nama}' ($porsi porsi) ke Catatan Kalori hari ini!";
            $_SESSION['pesan_menu_harian_tipe'] = 'sukses';
        } else {
            $_SESSION['pesan_menu_harian']      = 'Menu makanan tidak ditemukan.';
            $_SESSION['pesan_menu_harian_tipe'] = 'error';
        }
    }
    $query_str = $_GET;
    header('Location: menu.php?' . http_build_query($query_str));
    exit;
}

// â”€â”€ Batalkan menu harian hari ini â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_menu_harian') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_valid($token, 'csrf_token_menu_harian')) {
        $_SESSION['pesan_menu_harian']      = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $_SESSION['pesan_menu_harian_tipe'] = 'error';
    } else {
        // Hanya boleh hapus menu hari ini yang belum ada item dicatat
        $st = $pdo->prepare(
            'SELECT mh.id FROM menu_harian mh
             WHERE mh.user_id = ? AND mh.tanggal = ?'
        );
        $st->execute([$_SESSION['user_id'], $hari_ini]);
        $mh_id_hapus = $st->fetchColumn();

        if ($mh_id_hapus) {
            // Cek apakah ada item yang sudah dicatat ke catatan_makan
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM menu_harian_item WHERE menu_harian_id = ? AND catatan_makan_id IS NOT NULL'
            );
            $st->execute([$mh_id_hapus]);
            $ada_tercatat = (int)$st->fetchColumn();

            if ($ada_tercatat > 0) {
                $_SESSION['pesan_menu_harian']      = 'Menu tidak dapat dibatalkan karena sebagian sudah dicatat sebagai makanan yang dikonsumsi.';
                $_SESSION['pesan_menu_harian_tipe'] = 'error';
            } else {
                // Hapus item dan rencana harian (CASCADE jika tidak, hapus manual)
                $pdo->prepare('DELETE FROM menu_harian_item WHERE menu_harian_id = ?')->execute([$mh_id_hapus]);
                $pdo->prepare('DELETE FROM menu_harian WHERE id = ?')->execute([$mh_id_hapus]);
                $_SESSION['pesan_menu_harian']      = 'Menu harian hari ini berhasil dibatalkan.';
                $_SESSION['pesan_menu_harian_tipe'] = 'sukses';
            }
        } else {
            $_SESSION['pesan_menu_harian']      = 'Tidak ada menu harian hari ini yang dapat dibatalkan.';
            $_SESSION['pesan_menu_harian_tipe'] = 'error';
        }
    }
    header('Location: menu.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_menu_harian') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_valid($token, 'csrf_token_menu_harian')) {
        $error_menu_harian = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (!$terakhir) {
        $error_menu_harian = 'Hitung BMI terlebih dahulu sebelum membuat menu harian.';
    } else {
        $harga_post  = max(0, (int)($_POST['harga'] ?? 0));
        $budget_post = max(0, (int)($_POST['budget'] ?? 0));
        $veg_post    = ($_POST['veg'] ?? '') === '1';

        $kandidat = [];
        foreach ($daftar_waktu as $w) {
            $daftar = cari_menu($pdo, (int)$terakhir['kategori_id'], $w, $harga_post, $veg_post);
            if ($daftar) {
                $kandidat[] = $daftar;
            }
        }

        $menu_baru = [];
        if ($kandidat) {
            for ($i = 0; $i < 300; $i++) {
                $kombinasi = [];
                foreach ($kandidat as $daftar) {
                    $kombinasi[] = $daftar[array_rand($daftar)];
                }
                $total_harga = array_sum(array_column($kombinasi, 'harga'));
                if ($budget_post === 0 || $total_harga <= $budget_post) {
                    $menu_baru = $kombinasi;
                    break;
                }
            }

            // Fallback deterministik: jika sampling acak belum dapat kombinasi di bawah budget,
            // uji kombinasi termurah dari setiap kelompok waktu makan.
            if (!$menu_baru) {
                $termurah = [];
                foreach ($kandidat as $daftar) {
                    usort($daftar, fn($a, $b) => $a['harga'] <=> $b['harga']);
                    $termurah[] = $daftar[0];
                }
                if ($budget_post === 0 || array_sum(array_column($termurah, 'harga')) <= $budget_post) {
                    $menu_baru = $termurah;
                }
            }
        }

        if (!$menu_baru) {
            $error_menu_harian = 'Belum ada kombinasi menu yang cocok dengan filter dan budget. Coba longgarkan batas harga atau naikkan budget.';
        } else {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT id FROM menu_harian WHERE user_id = ? AND tanggal = ? FOR UPDATE');
            $st->execute([$_SESSION['user_id'], $hari_ini]);
            $menu_harian_id = (int)$st->fetchColumn();

            if ($menu_harian_id) {
                $st_cek = $pdo->prepare('SELECT COUNT(*) FROM menu_harian_item WHERE menu_harian_id = ? AND catatan_makan_id IS NOT NULL');
                $st_cek->execute([$menu_harian_id]);
                if ((int)$st_cek->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $_SESSION['pesan_menu_harian'] = 'Kombinasi menu tidak dapat diubah karena sebagian menu hari ini sudah dicatat sebagai makanan yang dikonsumsi.';
                    $_SESSION['pesan_menu_harian_tipe'] = 'error';
                    header('Location: menu.php');
                    exit;
                }
            } else {
                $simpan_rencana = $pdo->prepare('INSERT INTO menu_harian (user_id, tanggal) VALUES (?, ?)');
                $simpan_rencana->execute([$_SESSION['user_id'], $hari_ini]);
                $menu_harian_id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM menu_harian_item WHERE menu_harian_id = ?')->execute([$menu_harian_id]);
            $simpan_item = $pdo->prepare(
                'INSERT INTO menu_harian_item (menu_harian_id, menu_id, urutan) VALUES (?, ?, ?)'
            );
            foreach ($menu_baru as $urutan => $item) {
                $simpan_item->execute([$menu_harian_id, $item['id'], $urutan + 1]);
            }
            $pdo->commit();

            $_SESSION['pesan_menu_harian'] = 'Menu harian tersimpan. Menu ini tetap tersedia saat kamu kembali ke halaman rekomendasi.';
            $_SESSION['pesan_menu_harian_tipe'] = 'sukses';
            $params = ['hari_ini' => 1, 'harga' => $harga_post, 'budget' => $budget_post];
            if (!empty($_POST['waktu'])) {
                $params['waktu'] = $_POST['waktu'];
            }
            if ($veg_post) {
                $params['veg'] = 1;
            }
            header('Location: menu.php?' . http_build_query($params));
            exit;
        }
    }
}

$menu = [];
$menu_hari = [];
$menu_harian_id = null;
$pesan_menu_harian = $_SESSION['pesan_menu_harian'] ?? '';
$pesan_menu_harian_tipe = $_SESSION['pesan_menu_harian_tipe'] ?? 'sukses';
unset($_SESSION['pesan_menu_harian']);
unset($_SESSION['pesan_menu_harian_tipe']);

$st = $pdo->prepare('SELECT id FROM menu_harian WHERE user_id = ? AND tanggal = ?');
$st->execute([$_SESSION['user_id'], $hari_ini]);
$menu_harian_id = $st->fetchColumn();

if ($menu_harian_id) {
    $st = $pdo->prepare('SELECT i.id AS item_id, i.catatan_makan_id, m.* FROM menu_harian_item i
                         JOIN menu_diet m ON m.id = i.menu_id
                         WHERE i.menu_harian_id = ? ORDER BY i.urutan');
    $st->execute([$menu_harian_id]);
    $menu_hari = $st->fetchAll();
}

$st = $pdo->prepare(
    'SELECT mh.id AS plan_id, mh.tanggal, i.id AS item_id, i.catatan_makan_id,
            m.id AS menu_id, m.nama_menu, m.waktu_makan, m.kalori, m.harga
     FROM (
         SELECT id, user_id, tanggal
         FROM menu_harian
         WHERE user_id = ? AND tanggal <> ?
         ORDER BY tanggal DESC
         LIMIT 35
     ) mh
     JOIN menu_harian_item i ON i.menu_harian_id = mh.id
     JOIN menu_diet m ON m.id = i.menu_id
     ORDER BY mh.tanggal DESC, i.urutan'
);
$st->execute([$_SESSION['user_id'], $hari_ini]);
$riwayat_menu = [];
foreach ($st->fetchAll() as $item) {
    $plan_id = (int)$item['plan_id'];
    if (!isset($riwayat_menu[$plan_id])) {
        $riwayat_menu[$plan_id] = [
            'tanggal' => $item['tanggal'],
            'items' => [],
            'total_kalori' => 0,
            'total_harga' => 0,
        ];
    }
    $riwayat_menu[$plan_id]['items'][] = $item;
    $riwayat_menu[$plan_id]['total_kalori'] += (int)$item['kalori'];
    $riwayat_menu[$plan_id]['total_harga'] += (int)$item['harga'];
}

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

$tampilan = $_GET['tampilan'] ?? 'grid';
if (!in_array($tampilan, ['grid', 'list'], true)) {
    $tampilan = 'grid';
}

// Paginasi tampilan daftar menu (10 per halaman)
$halaman    = max(1, (int)($_GET['hal'] ?? 1));
$per_hal    = 10;
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

<h2>Menu rekomendasi</h2>

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
    <input type="hidden" name="tampilan" value="<?= h($tampilan) ?>">
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

    <div>
      <label for="budget" title="Digunakan sebagai batas maksimal saat membuat menu harian">Budget menu sehari <small class="teks-redup">(rencana)</small></label>
      <select id="budget" name="budget">
        <option value="0">Bebas</option>
        <?php foreach ([30000, 40000, 50000, 60000, 75000, 90000] as $b): ?>
          <option value="<?= $b ?>" <?= $budget === $b ? 'selected' : '' ?>><?= rp($b) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="cek">
      <label><input type="checkbox" name="veg" value="1" <?= $veg ? 'checked' : '' ?>> Vegetarian saja</label>
    </div>

    <button type="submit" class="tombol">Terapkan filter</button>
  </form>

  <?php if ($error_menu_harian): ?>
    <div class="alert error"><?= h($error_menu_harian) ?></div>
  <?php endif; ?>

  <?php if ($pesan_menu_harian): ?>
    <div class="alert <?= $pesan_menu_harian_tipe === 'error' ? 'error' : 'sukses' ?>"><?= h($pesan_menu_harian) ?></div>
  <?php endif; ?>

  <?php if ($menu_hari):
      $total_kal = array_sum(array_column($menu_hari, 'kalori'));
      $total_hrg = array_sum(array_column($menu_hari, 'harga')); ?>
    <div class="menu-hari" id="menu-hari">
      <div class="menu-hari-heading">
        <div>
          <small>TERSIMPAN UNTUK HARI INI &middot; <?= h(date('d-m-Y', strtotime($hari_ini))) ?></small>
          <h3>Menu harianmu</h3>
        </div>
        <span class="menu-hari-status">Tersimpan</span>
      </div>
      <ul>
        <?php foreach ($menu_hari as $m): ?>
          <li>
            <span><b><?= h(ucfirst($m['waktu_makan'])) ?></b>: <?= h($m['nama_menu']) ?> (<?= (int)$m['kalori'] ?> kkal)</span>
            <span class="menu-hari-item-aksi">
              <?= rp($m['harga']) ?>
              <?php if ($m['catatan_makan_id'] !== null): ?>
                <span class="menu-tercatat">Sudah dicatat</span>
              <?php else: ?>
                <form method="post">
                  <input type="hidden" name="aksi" value="catat_dimakan">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$m['item_id'] ?>">
                  <select class="menu-porsi" name="porsi" aria-label="Porsi yang dimakan">
                    <option value="0.5">&frac12; porsi</option>
                    <option value="1" selected>1 porsi</option>
                    <option value="1.5">1,5 porsi</option>
                    <option value="2">2 porsi</option>
                  </select>
                  <button type="submit" class="tombol kecil">Catat dimakan</button>
                </form>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <b>Total <?= number_format($total_kal, 0, ',', '.') ?> kkal &middot; <?= rp($total_hrg) ?></b>
      <p class="catatan">Target harianmu <?= number_format((int)$terakhir['target_kalori'], 0, ',', '.') ?> kkal. Rencana ini tidak otomatis masuk ke catatan makanan.</p>
      <?php
      $ada_tercatat_hari_ini = false;
      foreach ($menu_hari as $m) {
          if ($m['catatan_makan_id'] !== null) {
              $ada_tercatat_hari_ini = true;
              break;
          }
      }
      ?>
      <?php if ($ada_tercatat_hari_ini): ?>
        <p class="catatan" style="margin-top:10px;color:var(--teks-redup)">
          <em>Sebagian menu hari ini telah dicatat ke Catatan Kalori, sehingga rencana menu hari ini dikunci agar riwayat makanan tetap akurat.</em>
        </p>
      <?php else: ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">
          <form method="post" class="buat-menu-harian">
            <input type="hidden" name="aksi" value="simpan_menu_harian">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="waktu" value="<?= h($waktu) ?>">
            <input type="hidden" name="harga" value="<?= $harga ?>">
            <input type="hidden" name="budget" value="<?= $budget ?>">
            <?php if ($veg): ?><input type="hidden" name="veg" value="1"><?php endif; ?>
            <button type="submit" class="tombol kuning">Buat kombinasi lain</button>
          </form>
          <form method="post" onsubmit="return confirm('Batalkan menu harian hari ini? Tindakan ini tidak bisa diurungkan.')">
            <input type="hidden" name="aksi" value="hapus_menu_harian">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <button type="submit" class="tombol tombol-bahaya kecil">Batalkan menu harian</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="menu-hari menu-hari-kosong">
      <div>
        <h3>Rencanakan menu sehari</h3>
        <p class="catatan">Buat kombinasi menu berdasarkan kategori BMI dan budget. Rencana tersimpan untuk hari ini dan bisa dibuka lagi nanti.</p>
      </div>
      <form method="post" class="buat-menu-harian">
        <input type="hidden" name="aksi" value="simpan_menu_harian">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
        <input type="hidden" name="waktu" value="<?= h($waktu) ?>">
        <input type="hidden" name="harga" value="<?= $harga ?>">
        <input type="hidden" name="budget" value="<?= $budget ?>">
        <?php if ($veg): ?><input type="hidden" name="veg" value="1"><?php endif; ?>
        <button type="submit" class="tombol kuning">Buat menu 1 hari</button>
      </form>
    </div>
  <?php endif; ?>

  <section class="riwayat-menu" id="riwayat-menu" aria-labelledby="riwayat-menu-judul">
    <div class="dashboard-section-heading">
      <div>
        <h3 id="riwayat-menu-judul">Rencana & riwayat menu harian</h3>
        <p class="teks-redup">Menampilkan rencana menu tersimpan. Catat hanya menu yang benar-benar kamu makan.</p>
      </div>
    </div>
    <?php if ($riwayat_menu): ?>
      <div class="riwayat-menu-daftar">
        <?php foreach ($riwayat_menu as $plan_id => $plan): ?>
          <article class="riwayat-menu-kartu">
            <header>
              <div>
                <small>MENU TERSIMPAN</small>
                <h4><?= h(date('j', strtotime($plan['tanggal'])) . ' ' . $bulan[(int)date('n', strtotime($plan['tanggal']))] . ' ' . date('Y', strtotime($plan['tanggal']))) ?></h4>
              </div>
              <div class="riwayat-menu-ringkasan">
                <strong><?= number_format($plan['total_kalori'], 0, ',', '.') ?> kkal &middot; <?= rp($plan['total_harga']) ?></strong>
                <a href="catatan.php?<?= h(http_build_query(['tanggal' => $plan['tanggal']])) ?>">Lihat catatan tanggal ini</a>
              </div>
            </header>
            <ul>
              <?php foreach ($plan['items'] as $item): ?>
                <li>
                  <span><b><?= h(ucfirst($item['waktu_makan'])) ?></b>: <?= h($item['nama_menu']) ?> &middot; <?= (int)$item['kalori'] ?> kkal</span>
                  <?php if ($item['catatan_makan_id'] !== null): ?>
                    <span class="menu-tercatat">Sudah dicatat</span>
                  <?php elseif ($plan['tanggal'] > $hari_ini): ?>
                    <span class="teks-redup" style="font-size:.85rem;font-weight:600">Rencana mendatang</span>
                  <?php else: ?>
                    <form method="post">
                      <input type="hidden" name="aksi" value="catat_dimakan">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                      <input type="hidden" name="item_id" value="<?= (int)$item['item_id'] ?>">
                      <select class="menu-porsi" name="porsi" aria-label="Porsi yang dimakan">
                        <option value="0.5">&frac12; porsi</option>
                        <option value="1" selected>1 porsi</option>
                        <option value="1.5">1,5 porsi</option>
                        <option value="2">2 porsi</option>
                      </select>
                      <button type="submit" class="tombol kecil">Catat dimakan</button>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="kosong">Belum ada rencana menu tersimpan lainnya.</div>
    <?php endif; ?>
  </section>

  <?php if ($menu): ?>
    <?php
    $params_nav = array_filter(['waktu' => $waktu ?: null, 'harga' => $harga ?: null, 'budget' => $budget ?: null, 'veg' => $veg ? '1' : null, 'lingkup' => $lingkup !== 'rekomendasi' ? $lingkup : null], fn($v) => $v !== null && $v !== '');
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
      <p class="teks-redup" style="margin:0">
        Menampilkan <?= number_format(count($menu_tampil), 0, ',', '.') ?> dari <?= number_format($total_menu, 0, ',', '.') ?> menu
        <?php if ($waktu !== ''): ?>
          kategori <b><?= h(ucfirst($waktu)) ?></b>
        <?php endif; ?>
        <?php if ($total_hal > 1): ?> &middot; Halaman <?= $halaman ?> / <?= $total_hal ?><?php endif; ?>
      </p>
      <div class="view-toggle" style="display:flex;gap:4px">
        <a href="?<?= h(http_build_query($params_nav + ['tampilan' => 'grid', 'hal' => $halaman])) ?>" class="tombol kecil <?= $tampilan === 'grid' ? '' : 'tombol-sekunder' ?>" title="Tampilan Grid Menu">Grid</a>
        <a href="?<?= h(http_build_query($params_nav + ['tampilan' => 'list', 'hal' => $halaman])) ?>" class="tombol kecil <?= $tampilan === 'list' ? '' : 'tombol-sekunder' ?>" title="Tampilan List Menu">List</a>
      </div>
    </div>

    <?php if ($tampilan === 'grid'): ?>
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

            <div style="margin-top:auto;">
              <div class="menu-info" style="margin-bottom:8px;">
                <b><?= (int)$m['kalori'] ?> kkal <?= !empty($m['berat_gram']) ? '<span style="font-size:0.8rem; font-weight:500; color:var(--abu);">(' . (int)$m['berat_gram'] . 'g)</span>' : '' ?></b>
                <span><?= rp($m['harga']) ?></span>
              </div>

              <!-- Tombol Rapi untuk Melihat Rincian Takaran -->
              <button type="button" class="tombol sekunder kecil"
                      onclick="bukaModalTakaran(<?= (int)$m['id'] ?>, parseFloat(this.closest('article').querySelector('select.menu-porsi').value))"
                      style="width:100%; margin-bottom:8px; font-size:0.78rem; padding:6px 10px; font-weight:600; background:var(--latar); border:1px solid var(--garis); cursor:pointer;">
                Lihat Takaran
              </button>

              <!-- Tombol Pilih & Catat Manual -->
              <form method="post" style="display: flex; gap: 6px; align-items: center; margin:0;">
                <input type="hidden" name="aksi" value="catat_manual">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="menu_id" value="<?= (int)$m['id'] ?>">
                <select name="porsi" class="menu-porsi" style="height: 34px; padding: 2px 8px; font-size: 0.8rem; margin: 0; flex: 1;">
                  <option value="0.5">&frac12; porsi</option>
                  <option value="1" selected>1 porsi</option>
                  <option value="1.5">1,5 porsi</option>
                  <option value="2">2 porsi</option>
                </select>
                <button type="submit" class="tombol kecil" style="padding: 0 12px; height: 34px; font-size: 0.8rem; margin: 0; white-space: nowrap;">
                  + Catat Makan
                </button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <!-- List Tabel Menu Makanan -->
      <div class="gulir">
        <table class="tabel" style="width:100%">
          <thead>
            <tr>
              <th>Kategori Waktu</th>
              <th>Nama Menu</th>
              <th>Deskripsi</th>
              <th style="text-align:right">Kalori</th>
              <th style="text-align:right">Harga</th>
              <th>Info</th>
              <th style="text-align:center">Aksi Catat</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($menu_tampil as $m): ?>
              <tr>
                <td>
                  <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>">
                    <?= $ikon_waktu[$m['waktu_makan']] ?? '' ?> <?= h(ucfirst($m['waktu_makan'])) ?>
                  </span>
                </td>
                <td>
                  <strong><?= h($m['nama_menu']) ?></strong>
                  <?php if (!empty($m['nama_pakar'])): ?>
                    <div style="font-size: 0.72rem; color: var(--hijau); font-weight: 700; margin-top: 2px;">
                      Disusun oleh <?= h($m['gelar_pakar'] ? $m['gelar_pakar'] . ' ' : '') ?><?= h($m['nama_pakar']) ?>
                    </div>
                  <?php endif; ?>
                  <div>
                    <button type="button" class="tombol sekunder kecil" onclick="bukaModalTakaran(<?= (int)$m['id'] ?>, parseFloat(this.closest('tr').querySelector('select.menu-porsi').value))" style="padding:2px 8px; font-size:0.72rem; margin-top:4px;">
                      Lihat Takaran
                    </button>
                  </div>
                </td>
                <td class="teks-redup" style="font-size:.85em"><?= h($m['deskripsi']) ?></td>
                <td style="text-align:right;white-space:nowrap">
                  <b><?= (int)$m['kalori'] ?> kkal</b>
                  <?= !empty($m['berat_gram']) ? '<br><small class="teks-redup">' . (int)$m['berat_gram'] . 'g</small>' : '' ?>
                </td>
                <td style="text-align:right;white-space:nowrap"><?= rp($m['harga']) ?></td>
                <td><?= $m['vegetarian'] ? '<span class="badge kategori-normal" style="font-size:.75em">Veg</span>' : '' ?></td>
                <td style="text-align:center">
                  <form method="post" style="display:inline-flex;gap:4px;align-items:center;margin:0">
                    <input type="hidden" name="aksi" value="catat_manual">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="menu_id" value="<?= (int)$m['id'] ?>">
                    <select name="porsi" class="menu-porsi" style="height:30px;padding:1px 6px;font-size:0.75rem;margin:0">
                      <option value="0.5">&frac12;</option>
                      <option value="1" selected>1</option>
                      <option value="1.5">1&frac12;</option>
                      <option value="2">2</option>
                    </select>
                    <button type="submit" class="tombol kecil" style="padding:0 8px;height:30px;font-size:0.75rem;margin:0">
                      + Catat
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($total_hal > 1): ?>
      <nav class="paginasi" style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php
        $params_nav_hal = $params_nav + ['tampilan' => $tampilan];
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

  <p class="catatan">Kalori merupakan perkiraan per porsi standar dan bersifat edukatif, bukan pengganti saran ahli gizi.</p>
<?php endif; ?>

<!-- MODAL DETAIL TAKARAN & KOMPOSISI BAHAN -->
<div id="modal-takaran-menu" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(2px);">
  <div style="background:var(--permukaan); border-radius:var(--radius-kartu); padding:26px; max-width:580px; width:100%; box-shadow:0 24px 60px rgba(0,0,0,0.25); border:1px solid var(--garis); max-height:90vh; overflow-y:auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; gap:12px;">
      <div>
        <span id="modal-badge-waktu" class="badge-waktu" style="margin-bottom:6px; display:inline-block;"></span>
        <h3 id="modal-nama-menu" style="margin:0; font-size:1.25rem; font-weight:800; color:var(--gelap);"></h3>
        <p id="modal-deskripsi-menu" class="teks-redup" style="margin:4px 0 0; font-size:0.86rem; line-height:1.4;"></p>
      </div>
      <button type="button" onclick="tutupModalTakaran()" style="background:none; border:none; font-size:1.6rem; line-height:1; color:var(--teks-redup); cursor:pointer; padding:2px 6px;" aria-label="Tutup">&times;</button>
    </div>

    <!-- Pilih Porsi di Modal -->
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px; background:var(--latar); border:1px solid var(--garis); border-radius:var(--radius-kecil); padding:10px 14px;">
      <label for="modal-pilih-porsi" style="font-size:0.82rem; font-weight:700; color:var(--gelap); white-space:nowrap;">Lihat takaran untuk:</label>
      <select id="modal-pilih-porsi" onchange="renderModalPorsi()" style="flex:1; height:34px; padding:2px 10px; font-size:0.85rem; border:1px solid var(--garis); border-radius:var(--radius-kecil); background:var(--permukaan); cursor:pointer;">
        <option value="0.5">&frac12; porsi</option>
        <option value="1" selected>1 porsi (standar)</option>
        <option value="1.5">1&frac12; porsi</option>
        <option value="2">2 porsi</option>
      </select>
    </div>

    <!-- Ringkasan Gizi & Porsi (dinamis) -->
    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px; margin-bottom:16px; background:var(--latar); padding:12px; border-radius:var(--radius-kecil); border:1px solid var(--garis); text-align:center;">
      <div>
        <div class="teks-redup" style="font-size:0.72rem; font-weight:700; text-transform:uppercase;">Kalori</div>
        <div id="modal-kalori-menu" style="font-size:1.15rem; font-weight:800; color:var(--hijau); margin-top:2px;"></div>
      </div>
      <div>
        <div class="teks-redup" style="font-size:0.72rem; font-weight:700; text-transform:uppercase;">Total Berat</div>
        <div id="modal-berat-menu" style="font-size:1.15rem; font-weight:800; color:var(--gelap); margin-top:2px;"></div>
      </div>
      <div>
        <div class="teks-redup" style="font-size:0.72rem; font-weight:700; text-transform:uppercase;">Perkiraan Harga</div>
        <div id="modal-harga-menu" style="font-size:1.15rem; font-weight:800; color:var(--abu); margin-top:2px;"></div>
      </div>
    </div>

    <!-- Kotak Ringkasan Takaran (dinamis) -->
    <div style="margin-bottom:16px; background:var(--latar); border:1px solid var(--garis); padding:12px 14px; border-radius:var(--radius-kecil);">
      <div id="modal-takaran-label" style="font-size:0.72rem; font-weight:700; color:var(--abu); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;">Takaran 1 Porsi Standar:</div>
      <div id="modal-takaran-teks" style="font-size:0.9rem; font-weight:600; color:var(--gelap); line-height:1.45;"></div>
    </div>

    <!-- Rincian Komponen Bahan (dinamis) -->
    <div id="modal-komponen-area" style="margin-bottom:16px;">
      <div style="font-size:0.78rem; font-weight:700; color:var(--gelap); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.04em;">Komposisi &amp; Rincian Bahan:</div>
      <div style="overflow-x:auto;">
        <table class="tabel" style="width:100%; font-size:0.82rem; margin:0;">
          <thead>
            <tr style="background:var(--latar);">
              <th style="padding:6px 10px;">Komponen</th>
              <th style="padding:6px 10px;">Takaran</th>
              <th style="padding:6px 10px; text-align:right;">Berat</th>
              <th style="padding:6px 10px;">Kondisi</th>
              <th style="padding:6px 10px; text-align:right;">Kalori</th>
            </tr>
          </thead>
          <tbody id="modal-komponen-tbody"></tbody>
        </table>
      </div>
    </div>

    <div style="display:flex; justify-content:flex-end;">
      <button type="button" onclick="tutupModalTakaran()" class="tombol sekunder kecil" style="padding:7px 22px;">Tutup</button>
    </div>
  </div>
</div>

<script>
  const daftarTakaran = <?= json_encode($data_takaran_json ?? [], JSON_UNESCAPED_UNICODE) ?>;
  let modalMenuAktif = null;

  // Format angka: hilangkan desimal nol (mis 300.0 -> "300", 97.5 -> "97,5")
  function fmtAngka(n) {
    if (n % 1 === 0) return Math.round(n).toString();
    return n.toFixed(1).replace('.', ',');
  }

  // Format rupiah dengan pemisah ribuan
  function fmtRupiah(n) {
    return 'Rp' + Math.round(n).toLocaleString('id-ID');
  }

  // Label teks untuk porsi
  function labelPorsi(p) {
    if (p === 0.5)  return '\u00BD porsi';
    if (p === 1)    return '1 porsi (standar)';
    if (p === 1.5)  return '1\u00BD porsi';
    if (p === 2)    return '2 porsi';
    return p + ' porsi';
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Skala angka gram dalam kurung: "(185g)" -> "(370g)"
  function skalaGram(teks, porsi) {
    return teks.replace(/\((\d+(?:[.,]\d+)?)g\)/g, (match, angka) => {
      const n = parseFloat(angka.replace(',', '.'));
      return '(' + fmtAngka(n * porsi) + 'g)';
    });
  }

  // Skala angka sebelum satuan: "1 piring" -> "2 piring", "2,5 lembar" -> "5 lembar"
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
    document.getElementById('modal-berat-menu').textContent  = berat  > 0 ? berat + 'g' : '-';
    document.getElementById('modal-harga-menu').textContent  = fmtRupiah(harga);

    // Label takaran
    document.getElementById('modal-takaran-label').textContent =
      'Takaran ' + labelPorsi(porsi) + ':';

    // Teks takaran deskriptif
    document.getElementById('modal-takaran-teks').textContent =
      skalakanTakaranTeks(item.takaran, porsi, item.berat);

    // Tabel komponen
    const tbody = document.getElementById('modal-komponen-tbody');
    tbody.innerHTML = '';

    if (item.komponen && item.komponen.length > 0) {
      document.getElementById('modal-komponen-area').style.display = 'block';
      item.komponen.forEach(k => {
        const beratK  = parseFloat(k.berat_gram)      > 0 ? parseFloat(k.berat_gram)      * porsi : 0;
        const kaloriK = parseFloat(k.kalori_komponen) > 0 ? parseFloat(k.kalori_komponen) * porsi : 0;

        const gramStr   = beratK  > 0 ? fmtAngka(beratK)  + 'g'     : '-';
        const kaloriStr = kaloriK > 0 ? fmtAngka(kaloriK) + ' kkal' : '-';

        // Skala teks takaran komponen
        let takaranK = k.takaran || '-';
        if (porsi !== 1 && takaranK !== '-') {
          takaranK = skalaGram(takaranK, porsi);
          takaranK = skalaSatuan(takaranK, porsi);
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td style="padding:6px 10px;"><strong>${escapeHtml(k.nama_komponen)}</strong></td>
          <td style="padding:6px 10px;">${escapeHtml(takaranK)}</td>
          <td style="padding:6px 10px; text-align:right;">${gramStr}</td>
          <td style="padding:6px 10px;"><span class="teks-redup">${escapeHtml(k.kondisi || '-')}</span></td>
          <td style="padding:6px 10px; text-align:right; font-weight:700; color:var(--hijau);">${kaloriStr}</td>
        `;
        tbody.appendChild(tr);
      });
    } else {
      document.getElementById('modal-komponen-area').style.display = 'none';
    }
  }

  function bukaModalTakaran(id, porsiAwal) {
    const item = daftarTakaran[id];
    if (!item) return;

    modalMenuAktif = id;

    // Set header (statis)
    document.getElementById('modal-nama-menu').textContent      = item.nama;
    document.getElementById('modal-deskripsi-menu').textContent = item.deskripsi;
    const badgeWaktu = document.getElementById('modal-badge-waktu');
    badgeWaktu.className   = 'badge-waktu badge-waktu-' + item.waktu_raw;
    badgeWaktu.textContent = item.waktu;

    // Set porsi awal pada selector modal (sinkron dengan kartu)
    const porsiValid = [0.5, 1, 1.5, 2];
    const porsi = porsiValid.includes(porsiAwal) ? porsiAwal : 1;
    document.getElementById('modal-pilih-porsi').value = porsi;

    // Render semua isi modal
    renderModalPorsi();

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



