<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_pakar();

$pakar_id = (int)$_SESSION['user_id'];
$csrf = csrf_token('csrf_pakar_menu');

// ── Auto-migrate kolom pakar_id di menu_diet jika belum ada ───────────────
try {
    $res = $pdo->query("SHOW COLUMNS FROM menu_diet LIKE 'pakar_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE menu_diet ADD COLUMN pakar_id INT NULL AFTER id");
    }
} catch (Exception $e) {}

$pesan = $_SESSION['pesan_pakar_menu'] ?? '';
$pesan_tipe = $_SESSION['pesan_pakar_menu_tipe'] ?? 'sukses';
unset($_SESSION['pesan_pakar_menu'], $_SESSION['pesan_pakar_menu_tipe']);

// Ambil semua kategori BMI untuk pilihan checkbox
$semua_kategori_bmi = [];
try {
    $semua_kategori_bmi = $pdo->query("SELECT * FROM kategori_bmi ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {}

// ── Proses Form: Simpan / Edit Catatan Gizi & CRUD Menu Makanan ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $aksi  = $_POST['aksi'] ?? '';

    if (!csrf_valid($token, 'csrf_pakar_menu')) {
        $_SESSION['pesan_pakar_menu'] = 'Sesi formulir tidak valid atau kedaluwarsa.';
        $_SESSION['pesan_pakar_menu_tipe'] = 'error';
        header('Location: ' . base_url('pakar/menu.php'));
        exit;
    }

    // 1. Simpan Catatan Gizi untuk Menu yang Ada
    if ($aksi === 'simpan_catatan') {
        $menu_id = (int)($_POST['menu_id'] ?? 0);
        $catatan = trim($_POST['catatan'] ?? '');
        $status  = $_POST['status'] ?? 'disetujui';
        if (!in_array($status, ['disetujui','perlu_tinjau','ditandai'], true)) $status = 'disetujui';

        if ($menu_id <= 0 || $catatan === '') {
            $_SESSION['pesan_pakar_menu'] = 'Catatan nutrisi tidak boleh kosong.';
            $_SESSION['pesan_pakar_menu_tipe'] = 'error';
        } else {
            $cek = $pdo->prepare("SELECT id FROM catatan_gizi WHERE menu_id = ? AND pakar_id = ?");
            $cek->execute([$menu_id, $pakar_id]);
            $ada = $cek->fetch();

            if ($ada) {
                $upd = $pdo->prepare("UPDATE catatan_gizi SET catatan = ?, status = ?, created_at = NOW() WHERE id = ?");
                $upd->execute([$catatan, $status, $ada['id']]);
                $_SESSION['pesan_pakar_menu'] = 'Catatan gizi berhasil diperbarui.';
            } else {
                $ins = $pdo->prepare("INSERT INTO catatan_gizi (menu_id, pakar_id, catatan, status) VALUES (?, ?, ?, ?)");
                $ins->execute([$menu_id, $pakar_id, $catatan, $status]);
                $_SESSION['pesan_pakar_menu'] = 'Catatan gizi berhasil disimpan.';
            }
            $_SESSION['pesan_pakar_menu_tipe'] = 'sukses';
        }
        header('Location: ' . base_url('pakar/menu.php'));
        exit;
    }

    // 2. Hapus Catatan Gizi
    if ($aksi === 'hapus_catatan') {
        $catatan_id = (int)($_POST['catatan_id'] ?? 0);
        if ($catatan_id > 0) {
            $del = $pdo->prepare("DELETE FROM catatan_gizi WHERE id = ? AND pakar_id = ?");
            $del->execute([$catatan_id, $pakar_id]);
            $_SESSION['pesan_pakar_menu'] = 'Catatan gizi berhasil dihapus.';
            $_SESSION['pesan_pakar_menu_tipe'] = 'sukses';
        }
        header('Location: ' . base_url('pakar/menu.php'));
        exit;
    }

    // 3. Tambah / Edit Menu Makanan oleh Pakar
    if ($aksi === 'simpan_menu_pakar') {
        $id_menu     = (int)($_POST['id_menu'] ?? 0);
        $nama_menu   = trim($_POST['nama_menu'] ?? '');
        $waktu_makan = $_POST['waktu_makan'] ?? 'pagi';
        $kalori      = (int)($_POST['kalori'] ?? 0);
        $berat_gram  = !empty($_POST['berat_gram']) ? (int)$_POST['berat_gram'] : null;
        $takaran_saji = trim($_POST['takaran_saji'] ?? '');
        $harga       = (int)($_POST['harga'] ?? 0);
        $vegetarian  = isset($_POST['vegetarian']) ? 1 : 0;
        $deskripsi   = trim($_POST['deskripsi'] ?? '');
        $kategori_ids = $_POST['kategori_bmi'] ?? [];
        $catatan_awal = trim($_POST['catatan_pakar_awal'] ?? '');

        $waktu_valid = ['pagi', 'siang', 'malam', 'camilan'];
        if (mb_strlen($nama_menu) < 3 || mb_strlen($nama_menu) > 120) {
            $_SESSION['pesan_pakar_menu'] = 'Nama menu harus berisi antara 3 sampai 120 karakter.';
            $_SESSION['pesan_pakar_menu_tipe'] = 'error';
        } elseif (!in_array($waktu_makan, $waktu_valid, true)) {
            $_SESSION['pesan_pakar_menu'] = 'Waktu makan tidak valid.';
            $_SESSION['pesan_pakar_menu_tipe'] = 'error';
        } elseif ($kalori < 10 || $kalori > 3000) {
            $_SESSION['pesan_pakar_menu'] = 'Nilai kalori harus berada di rentang 10 - 3000 kkal.';
            $_SESSION['pesan_pakar_menu_tipe'] = 'error';
        } elseif ($harga < 0 || $harga > 500000) {
            $_SESSION['pesan_pakar_menu'] = 'Perkiraan harga harus berada di rentang Rp 0 - Rp 500.000.';
            $_SESSION['pesan_pakar_menu_tipe'] = 'error';
        } else {
            $pdo->beginTransaction();
            try {
                if ($id_menu > 0) {
                    // Update menu milik pakar ini
                    $st_up = $pdo->prepare("
                        UPDATE menu_diet
                        SET nama_menu = ?, waktu_makan = ?, kalori = ?, berat_gram = ?, takaran_saji = ?, harga = ?, vegetarian = ?, deskripsi = ?
                        WHERE id = ? AND pakar_id = ?
                    ");
                    $st_up->execute([$nama_menu, $waktu_makan, $kalori, $berat_gram, $takaran_saji, $harga, $vegetarian, $deskripsi, $id_menu, $pakar_id]);

                    // Update kategori BMI
                    $del_kat = $pdo->prepare("DELETE FROM menu_kategori WHERE menu_id = ?");
                    $del_kat->execute([$id_menu]);
                    $target_id = $id_menu;

                    $_SESSION['pesan_pakar_menu'] = 'Menu makanan <strong>' . h($nama_menu) . '</strong> berhasil diperbarui.';
                } else {
                    // Insert menu baru dengan pakar_id
                    $st_in = $pdo->prepare("
                        INSERT INTO menu_diet (pakar_id, nama_menu, waktu_makan, kalori, berat_gram, takaran_saji, harga, vegetarian, deskripsi)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $st_in->execute([$pakar_id, $nama_menu, $waktu_makan, $kalori, $berat_gram, $takaran_saji, $harga, $vegetarian, $deskripsi]);
                    $target_id = (int)$pdo->lastInsertId();

                    // Simpan catatan klinis pakar pembuat jika diisi
                    if ($catatan_awal !== '') {
                        $ins_cg = $pdo->prepare("INSERT INTO catatan_gizi (menu_id, pakar_id, catatan, status) VALUES (?, ?, ?, 'disetujui')");
                        $ins_cg->execute([$target_id, $pakar_id, $catatan_awal]);
                    }

                    $_SESSION['pesan_pakar_menu'] = 'Menu klinis baru <strong>' . h($nama_menu) . '</strong> berhasil ditambahkan dan langsung aktif direkomendasikan ke pengguna!';
                }

                // Relasi kategori BMI
                if (!empty($kategori_ids) && is_array($kategori_ids)) {
                    $ins_kat = $pdo->prepare("INSERT IGNORE INTO menu_kategori (menu_id, kategori_id) VALUES (?, ?)");
                    foreach ($kategori_ids as $kid) {
                        $kid = (int)$kid;
                        if ($kid > 0) {
                            $ins_kat->execute([$target_id, $kid]);
                        }
                    }
                }

                $pdo->commit();
                $_SESSION['pesan_pakar_menu_tipe'] = 'sukses';
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['pesan_pakar_menu'] = 'Gagal menyimpan menu makanan: ' . $e->getMessage();
                $_SESSION['pesan_pakar_menu_tipe'] = 'error';
            }
        }
        header('Location: ' . base_url('pakar/menu.php'));
        exit;
    }

    // 4. Hapus Menu Makanan Milik Pakar
    if ($aksi === 'hapus_menu_pakar') {
        $id_hapus = (int)($_POST['id_menu'] ?? 0);
        if ($id_hapus > 0) {
            $del = $pdo->prepare("DELETE FROM menu_diet WHERE id = ? AND pakar_id = ?");
            $del->execute([$id_hapus, $pakar_id]);
            $_SESSION['pesan_pakar_menu'] = 'Menu makanan berhasil dihapus.';
            $_SESSION['pesan_pakar_menu_tipe'] = 'sukses';
        }
        header('Location: ' . base_url('pakar/menu.php'));
        exit;
    }
}

// ── Filter & Pencarian ──────────────────────────────────────────────────
$cari     = trim($_GET['cari'] ?? '');
$wkt      = $_GET['waktu'] ?? '';
$veg      = $_GET['veg'] ?? '';
$status_f = $_GET['status'] ?? '';
$sumber_f = $_GET['sumber'] ?? ''; // 'saya', 'semua'

$kondisi = ['1=1'];
$params  = [];

if ($cari !== '') {
    $kondisi[] = 'md.nama_menu LIKE ?';
    $params[] = '%' . $cari . '%';
}
if ($wkt !== '' && in_array($wkt, ['pagi','siang','malam','camilan'], true)) {
    $kondisi[] = 'md.waktu_makan = ?';
    $params[] = $wkt;
}
if ($veg === '1') {
    $kondisi[] = 'md.vegetarian = 1';
}
if ($status_f === 'ada_catatan') {
    $kondisi[] = 'cg.id IS NOT NULL';
} elseif ($status_f === 'perlu_tinjau') {
    $kondisi[] = "cg.status = 'perlu_tinjau'";
} elseif ($status_f === 'ditandai') {
    $kondisi[] = "cg.status = 'ditandai'";
} elseif ($status_f === 'tanpa_catatan') {
    $kondisi[] = 'cg.id IS NULL';
}
if ($sumber_f === 'saya') {
    $kondisi[] = 'md.pakar_id = ?';
    $params[] = $pakar_id;
}

$sql = "
    SELECT md.*,
           u.nama AS pembuat_nama,
           u.gelar AS pembuat_gelar,
           cg.id AS catatan_id,
           cg.catatan AS isi_catatan,
           cg.status  AS status_catatan,
           cg.created_at AS catatan_tgl,
           (SELECT COUNT(*) FROM catatan_makan cm WHERE cm.menu_id = md.id) AS frekuensi,
           (SELECT GROUP_CONCAT(kategori_id) FROM menu_kategori WHERE menu_id = md.id) AS kategori_bmi_list
    FROM menu_diet md
    LEFT JOIN users u ON u.id = md.pakar_id
    LEFT JOIN catatan_gizi cg ON cg.menu_id = md.id AND cg.pakar_id = ?
    WHERE " . implode(' AND ', $kondisi) . "
    ORDER BY (md.pakar_id = ?) DESC, md.id DESC
";
array_unshift($params, $pakar_id, $pakar_id);

$st = $pdo->prepare($sql);
$st->execute($params);
$daftar_menu = $st->fetchAll();

// Pagination
$per_hal = 20;
$total   = count($daftar_menu);
$hal     = max(1, (int)($_GET['hal'] ?? 1));
$total_hal = max(1, (int)ceil($total / $per_hal));
$hal     = min($hal, $total_hal);
$offset  = ($hal - 1) * $per_hal;
$daftar_halaman = array_slice($daftar_menu, $offset, $per_hal);

$label_status = ['disetujui'=>'Disetujui','perlu_tinjau'=>'Perlu Tinjau','ditandai'=>'Ditandai'];
$warna_status = ['disetujui'=>'#D1FAE5;color:#065F46','perlu_tinjau'=>'#FEF3C7;color:#92400E','ditandai'=>'#FEE2E2;color:#991B1B'];
$label_waktu  = ['pagi'=>'Pagi','siang'=>'Siang','malam'=>'Malam','camilan'=>'Camilan'];

$judul = 'Kelola & Tinjauan Menu Gizi - Calorie Lab';
include BASE_DIR . 'includes/header.php';
include __DIR__ . '/nav.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;">
  <div>
    <h1 style="font-size: 1.6rem; font-weight: 800; color: var(--gelap); margin: 0 0 4px;">Kelola &amp; Tinjauan Menu Gizi</h1>
    <p class="teks-redup" style="margin: 0; font-size: 0.9rem;">Sebagai pakar gizi terverifikasi, Anda dapat menambahkan menu diet klinis baru atau meninjau menu yang ada.</p>
  </div>
  <div style="display: flex; gap: 10px; align-items: center;">
    <button type="button" onclick="bukaModalMenu()" class="tombol" style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; font-weight: 700;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Tambah Menu Baru
    </button>
  </div>
</div>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : '' ?>" style="margin-bottom: 16px;"><?= $pesan ?></div>
<?php endif; ?>

<!-- Filter Bar -->
<form method="get" action="<?= base_url('pakar/menu.php') ?>" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
  <input type="text" name="cari" placeholder="Cari nama menu..." value="<?= h($cari) ?>"
         style="flex: 2; min-width: 180px; padding: 8px 12px; font-size: 0.88rem;">
  
  <select name="sumber" style="padding: 8px 12px; font-size: 0.88rem; border: 1px solid var(--garis); border-radius: var(--radius-kecil); background: var(--permukaan);">
    <option value="">Semua Menu (Sistem &amp; Pakar)</option>
    <option value="saya" <?= $sumber_f === 'saya' ? 'selected' : '' ?>>Hanya Kreasi Saya</option>
  </select>

  <select name="waktu" style="padding: 8px 12px; font-size: 0.88rem; border: 1px solid var(--garis); border-radius: var(--radius-kecil); background: var(--permukaan);">
    <option value="">Semua Waktu</option>
    <?php foreach (['pagi','siang','malam','camilan'] as $w): ?>
      <option value="<?= $w ?>" <?= $wkt === $w ? 'selected' : '' ?>><?= $label_waktu[$w] ?></option>
    <?php endforeach; ?>
  </select>
  
  <select name="status" style="padding: 8px 12px; font-size: 0.88rem; border: 1px solid var(--garis); border-radius: var(--radius-kecil); background: var(--permukaan);">
    <option value="">Semua Status Catatan</option>
    <option value="tanpa_catatan" <?= $status_f === 'tanpa_catatan' ? 'selected' : '' ?>>Belum Ada Catatan</option>
    <option value="ada_catatan"   <?= $status_f === 'ada_catatan'   ? 'selected' : '' ?>>Ada Catatan</option>
    <option value="perlu_tinjau"  <?= $status_f === 'perlu_tinjau'  ? 'selected' : '' ?>>Perlu Tinjau</option>
  </select>

  <button type="submit" class="tombol kecil" style="padding: 8px 18px; font-size: 0.88rem;">Filter</button>
  <?php if ($cari || $wkt || $veg || $status_f || $sumber_f): ?>
    <a href="<?= base_url('pakar/menu.php') ?>" class="tombol sekunder kecil" style="padding: 8px 14px; font-size: 0.88rem;">Reset</a>
  <?php endif; ?>
</form>

<!-- Tabel Menu -->
<div class="kartu" style="padding: 0; overflow-x: auto;">
  <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
    <thead>
      <tr style="background: var(--latar); border-bottom: 2px solid var(--garis);">
        <th style="padding: 12px 16px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gelap);">Menu &amp; Penyusun</th>
        <th style="padding: 12px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gelap);">Waktu</th>
        <th style="padding: 12px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gelap);">Kalori</th>
        <th style="padding: 12px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gelap);">Takaran / Harga</th>
        <th style="padding: 12px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gelap);">Status Gizi</th>
        <th style="padding: 12px 16px; text-align: right; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gelap);">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($daftar_halaman)): ?>
        <tr><td colspan="6" style="padding: 32px; text-align: center; color: var(--teks-redup);">Tidak ada menu yang sesuai filter.</td></tr>
      <?php else: ?>
        <?php foreach ($daftar_halaman as $m): 
          $is_milik_saya = ((int)($m['pakar_id'] ?? 0) === $pakar_id);
        ?>
          <tr style="border-bottom: 1px solid var(--garis); transition: background 0.15s; <?= $is_milik_saya ? 'background: rgba(58,105,83,0.03);' : '' ?>" onmouseover="this.style.background='var(--latar)'" onmouseout="this.style.background='<?= $is_milik_saya ? 'rgba(58,105,83,0.03)' : '' ?>'">
            <td style="padding: 12px 16px;">
              <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                <strong style="color: var(--gelap);"><?= h($m['nama_menu']) ?></strong>
                <?php if ($is_milik_saya): ?>
                  <span class="badge" style="background: var(--mint); color: var(--hijau); font-size: 0.68rem; font-weight: 800; padding: 2px 7px;">Kreasi Saya</span>
                <?php elseif (!empty($m['pembuat_nama'])): ?>
                  <span class="badge" style="background: #E0E7FF; color: #3730A3; font-size: 0.68rem; font-weight: 700; padding: 2px 7px;">
                    Oleh <?= h($m['pembuat_gelar'] ? $m['pembuat_gelar'] . ' ' : '') ?><?= h($m['pembuat_nama']) ?>
                  </span>
                <?php endif; ?>
                <?php if ($m['vegetarian']): ?>
                  <span class="badge" style="background: #E8F5E9; color: #2E7D32; font-size: 0.68rem; padding: 2px 7px;">Vegetarian</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($m['deskripsi'])): ?>
                <div style="font-size: 0.78rem; color: var(--teks-redup); margin-top: 3px; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                  <?= h($m['deskripsi']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="padding: 12px 10px; text-align: center;">
              <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>"><?= h($label_waktu[$m['waktu_makan']] ?? $m['waktu_makan']) ?></span>
            </td>
            <td style="padding: 12px 10px; text-align: center; font-weight: 700; color: var(--hijau);"><?= $m['kalori'] ?> kkal</td>
            <td style="padding: 12px 10px; text-align: center; color: var(--teks); font-size: 0.82rem;">
              <?= $m['berat_gram'] ? $m['berat_gram'] . 'g' : '-' ?> &middot; Rp <?= number_format($m['harga'], 0, ',', '.') ?>
            </td>
            <td style="padding: 12px 10px; text-align: center;">
              <?php if ($m['catatan_id']): ?>
                <span style="font-size: 0.75rem; font-weight: 700; padding: 3px 9px; border-radius: 10px; background: <?= $warna_status[$m['status_catatan']] ?? '#eee;color:#333' ?>;">
                  <?= h($label_status[$m['status_catatan']] ?? $m['status_catatan']) ?>
                </span>
              <?php else: ?>
                <span style="font-size: 0.75rem; color: var(--teks-redup);">Belum ditinjau</span>
              <?php endif; ?>
            </td>
            <td style="padding: 12px 16px; text-align: right; white-space: nowrap;">
              <?php if ($is_milik_saya): ?>
                <button type="button" class="tombol kecil" style="font-size: 0.75rem; padding: 5px 10px; margin-right: 4px;"
                        onclick="editMenu(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)">
                  Edit Menu
                </button>
                <form method="post" action="<?= base_url('pakar/menu.php') ?>" style="display:inline; margin-right:4px;"
                      onsubmit="return confirm('Hapus menu kreasi Anda ini dari sistem?');">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="aksi" value="hapus_menu_pakar">
                  <input type="hidden" name="id_menu" value="<?= (int)$m['id'] ?>">
                  <button type="submit" class="tombol kecil" style="font-size: 0.75rem; padding: 5px 8px; background: #C44536;">Hapus</button>
                </form>
              <?php endif; ?>

              <button type="button" class="tombol sekunder kecil"
                      style="font-size: 0.75rem; padding: 5px 10px;"
                      onclick="bukaModal(<?= $m['id'] ?>, <?= h(json_encode($m['nama_menu'])) ?>, <?= h(json_encode($m['isi_catatan'] ?? '')) ?>, <?= h(json_encode($m['status_catatan'] ?? 'disetujui')) ?>, <?= (int)($m['catatan_id'] ?? 0) ?>)">
                <?= $m['catatan_id'] ? 'Catatan' : '+ Catatan' ?>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($total_hal > 1): ?>
  <?php
    $qs = http_build_query(array_filter(['cari'=>$cari,'waktu'=>$wkt,'veg'=>$veg,'status'=>$status_f,'sumber'=>$sumber_f]));
    $qs_prefix = $qs ? $qs . '&' : '';
    $awal = max(1, $hal - 2);
    $akhir = min($total_hal, $hal + 2);
  ?>
  <div style="display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap;">
    <?php if ($hal > 1): ?>
      <a href="<?= base_url('pakar/menu.php?' . $qs_prefix . 'hal=' . ($hal - 1)) ?>" class="tombol sekunder kecil" style="padding: 6px 12px;">Sebelumnya</a>
    <?php endif; ?>
    <?php for ($p = $awal; $p <= $akhir; $p++): ?>
      <a href="<?= base_url('pakar/menu.php?' . $qs_prefix . 'hal=' . $p) ?>"
         class="tombol <?= $p === $hal ? '' : 'sekunder' ?> kecil"
         style="padding: 6px 12px;"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($hal < $total_hal): ?>
      <a href="<?= base_url('pakar/menu.php?' . $qs_prefix . 'hal=' . ($hal + 1)) ?>" class="tombol sekunder kecil" style="padding: 6px 12px;">Berikutnya</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ══════════════ MODAL TAMBAH / EDIT MENU PAKAR ══════════════ -->
<div id="modal-menu-pakar" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); backdrop-filter:blur(3px); z-index:300; align-items:center; justify-content:center; padding:16px;">
  <div style="background:#fff; border-radius:20px; padding:28px; max-width:640px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.25);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div>
        <h3 id="form-menu-judul" style="margin:0; font-size:1.3rem; font-weight:800; color:var(--gelap);">Tambah Menu Diet Klinis</h3>
        <p class="teks-redup" style="margin:2px 0 0; font-size:0.83rem;">Menu yang Anda buat akan langsung terverifikasi dan tayang untuk pengguna.</p>
      </div>
      <button type="button" onclick="tutupModalMenu()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--teks-redup);">&times;</button>
    </div>

    <form method="post" action="<?= base_url('pakar/menu.php') ?>" id="form-crud-menu">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="aksi" value="simpan_menu_pakar">
      <input type="hidden" name="id_menu" id="input-menu-id" value="0">

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Nama Menu Makanan <span style="color:#C44536;">*</span></label>
        <input type="text" name="nama_menu" id="input-nama-menu" required placeholder="Contoh: Dada Ayam Panggang Brokoli Rebus" style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px;">
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Waktu Makan <span style="color:#C44536;">*</span></label>
          <select name="waktu_makan" id="input-waktu-makan" required style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px; background:#fff;">
            <option value="pagi">Sarapan Pagi</option>
            <option value="siang">Makan Siang</option>
            <option value="malam">Makan Malam</option>
            <option value="camilan">Camilan Sehat</option>
          </select>
        </div>
        <div>
          <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Estimasi Kalori (kkal) <span style="color:#C44536;">*</span></label>
          <input type="number" name="kalori" id="input-kalori" required min="10" max="3000" placeholder="Contoh: 350" style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px;">
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Berat Bersih (gram)</label>
          <input type="number" name="berat_gram" id="input-berat-gram" min="1" max="5000" placeholder="Contoh: 250" style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px;">
        </div>
        <div>
          <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Takaran Saji</label>
          <input type="text" name="takaran_saji" id="input-takaran-saji" placeholder="Contoh: 1 piring sedang" style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px;">
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
        <div>
          <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Perkiraan Biaya (Rp)</label>
          <input type="number" name="harga" id="input-harga" min="0" step="500" placeholder="Contoh: 25000" style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px;">
        </div>
        <div style="display:flex; align-items:flex-end; padding-bottom:8px;">
          <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; font-weight:600; cursor:pointer;">
            <input type="checkbox" name="vegetarian" id="input-vegetarian" value="1" style="width:18px; height:18px;">
            Menu Ramah Vegetarian
          </label>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Deskripsi / Saran Penyajian</label>
        <textarea name="deskripsi" id="input-deskripsi" rows="3" placeholder="Jelaskan kandungan nutrisi atau cara memasak sehat..." style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px; resize:vertical;"></textarea>
      </div>

      <div style="margin-bottom:16px;">
        <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:8px;">Rekomendasi Kategori BMI Pengguna</label>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:8px; background:var(--latar); padding:12px; border-radius:10px; border:1px solid var(--garis);">
          <?php foreach ($semua_kategori_bmi as $kb): ?>
            <label style="display:flex; align-items:center; gap:6px; font-size:0.82rem; cursor:pointer;">
              <input type="checkbox" name="kategori_bmi[]" value="<?= $kb['id'] ?>" class="check-kategori-bmi" id="kat-bmi-<?= $kb['id'] ?>">
              <?= h($kb['nama']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="field-catatan-awal" style="margin-bottom:18px;">
        <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Catatan Nutrisi Klinis Anda (Opsional)</label>
        <textarea name="catatan_pakar_awal" id="input-catatan-awal" rows="2" placeholder="Catatan medis atau saran dokter untuk menu ini..." style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px; resize:vertical;"></textarea>
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" onclick="tutupModalMenu()" class="tombol sekunder kecil" style="padding:10px 20px;">Batal</button>
        <button type="submit" class="tombol kecil" style="padding:10px 24px; font-weight:700;">Simpan &amp; Terbitkan Menu</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════ MODAL CATATAN GIZI ══════════════ -->
<div id="modal-catatan" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:300; align-items:center; justify-content:center; padding:20px;">
  <div style="background:var(--permukaan); border-radius:var(--radius-kartu); padding:28px; max-width:520px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 id="modal-judul" style="margin:0 0 4px; font-size:1.1rem; font-weight:800; color:var(--gelap);"></h3>
    <p style="margin:0 0 18px; font-size:0.82rem; color:var(--teks-redup);">Tambahkan catatan atau penilaian gizi untuk menu ini.</p>

    <form method="post" action="<?= base_url('pakar/menu.php') ?>" id="form-catatan">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="aksi" value="simpan_catatan">
      <input type="hidden" name="menu_id" id="modal-menu-id" value="">

      <label style="font-size:0.88rem;">Catatan Gizi</label>
      <textarea id="modal-catatan-isi" name="catatan" rows="4"
                placeholder="Contoh: Menu ini tinggi serat, cocok untuk pengguna dengan kategori gemuk. Kurangi garam..."
                style="width:100%; font-family:inherit; font-size:0.88rem; resize:vertical;"></textarea>

      <label style="font-size:0.88rem; margin-top:12px;">Status Penilaian</label>
      <select name="status" id="modal-status"
              style="width:100%; padding:10px 12px; font-size:0.88rem; border:1.5px solid #DFD9CE; border-radius:var(--radius-kecil); font-family:inherit; background:var(--permukaan-subtle);">
        <option value="disetujui">Disetujui — Menu gizi sudah baik</option>
        <option value="perlu_tinjau">Perlu Tinjau — Ada yang perlu diperhatikan</option>
        <option value="ditandai">Ditandai — Perlu perbaikan atau peringatan</option>
      </select>

      <div style="display:flex; gap:10px; margin-top:18px; justify-content:flex-end;">
        <button type="button" onclick="tutupModal()" class="tombol sekunder kecil" style="padding:8px 18px;">Batal</button>
        <button type="submit" class="tombol kecil" style="padding:8px 20px;">Simpan Catatan</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Modal Catatan Gizi
  function bukaModal(menuId, namaMenu, isiCatatan, statusCatatan, catatanId) {
    document.getElementById('modal-judul').textContent = namaMenu;
    document.getElementById('modal-menu-id').value = menuId;
    document.getElementById('modal-catatan-isi').value = isiCatatan || '';
    document.getElementById('modal-status').value = statusCatatan || 'disetujui';
    document.getElementById('modal-catatan').style.display = 'flex';
    document.getElementById('modal-catatan-isi').focus();
  }
  function tutupModal() {
    document.getElementById('modal-catatan').style.display = 'none';
  }

  // Modal Tambah / Edit Menu Pakar
  function bukaModalMenu() {
    document.getElementById('form-menu-judul').textContent = 'Tambah Menu Diet Klinis';
    document.getElementById('input-menu-id').value = '0';
    document.getElementById('input-nama-menu').value = '';
    document.getElementById('input-waktu-makan').value = 'pagi';
    document.getElementById('input-kalori').value = '';
    document.getElementById('input-berat-gram').value = '';
    document.getElementById('input-takaran-saji').value = '';
    document.getElementById('input-harga').value = '';
    document.getElementById('input-vegetarian').checked = false;
    document.getElementById('input-deskripsi').value = '';
    document.getElementById('input-catatan-awal').value = '';
    document.getElementById('field-catatan-awal').style.display = 'block';

    // Uncheck semua kategori BMI
    document.querySelectorAll('.check-kategori-bmi').forEach(el => el.checked = false);

    document.getElementById('modal-menu-pakar').style.display = 'flex';
    document.getElementById('input-nama-menu').focus();
  }

  function editMenu(menu) {
    document.getElementById('form-menu-judul').textContent = 'Edit Menu: ' + menu.nama_menu;
    document.getElementById('input-menu-id').value = menu.id;
    document.getElementById('input-nama-menu').value = menu.nama_menu;
    document.getElementById('input-waktu-makan').value = menu.waktu_makan;
    document.getElementById('input-kalori').value = menu.kalori;
    document.getElementById('input-berat-gram').value = menu.berat_gram || '';
    document.getElementById('input-takaran-saji').value = menu.takaran_saji || '';
    document.getElementById('input-harga').value = menu.harga || '';
    document.getElementById('input-vegetarian').checked = (parseInt(menu.vegetarian) === 1);
    document.getElementById('input-deskripsi').value = menu.deskripsi || '';
    document.getElementById('field-catatan-awal').style.display = 'none';

    // Set centang kategori BMI
    const listKat = (menu.kategori_bmi_list || '').split(',');
    document.querySelectorAll('.check-kategori-bmi').forEach(el => {
      el.checked = listKat.includes(el.value);
    });

    document.getElementById('modal-menu-pakar').style.display = 'flex';
    document.getElementById('input-nama-menu').focus();
  }

  function tutupModalMenu() {
    document.getElementById('modal-menu-pakar').style.display = 'none';
  }

  document.getElementById('modal-menu-pakar').addEventListener('click', function(e) {
    if (e.target === this) tutupModalMenu();
  });
  document.getElementById('modal-catatan').addEventListener('click', function(e) {
    if (e.target === this) tutupModal();
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      tutupModal();
      tutupModalMenu();
    }
  });
</script>

<?php include BASE_DIR . 'includes/footer.php'; ?>
