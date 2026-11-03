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
    $semua_kategori_bmi = $pdo->query("SELECT id, nama, kode FROM kategori_bmi ORDER BY id ASC")->fetchAll();
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
                    $st_up = $pdo->prepare("
                        UPDATE menu_diet
                        SET nama_menu = ?, waktu_makan = ?, kalori = ?, berat_gram = ?, takaran_saji = ?, harga = ?, vegetarian = ?, deskripsi = ?
                        WHERE id = ? AND pakar_id = ?
                    ");
                    $st_up->execute([$nama_menu, $waktu_makan, $kalori, $berat_gram, $takaran_saji, $harga, $vegetarian, $deskripsi, $id_menu, $pakar_id]);
                    $del_kat = $pdo->prepare("DELETE FROM menu_kategori WHERE menu_id = ?");
                    $del_kat->execute([$id_menu]);
                    $target_id = $id_menu;
                    $_SESSION['pesan_pakar_menu'] = 'Menu makanan <strong>' . h($nama_menu) . '</strong> berhasil diperbarui.';
                } else {
                    $st_in = $pdo->prepare("
                        INSERT INTO menu_diet (pakar_id, nama_menu, waktu_makan, kalori, berat_gram, takaran_saji, harga, vegetarian, deskripsi)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $st_in->execute([$pakar_id, $nama_menu, $waktu_makan, $kalori, $berat_gram, $takaran_saji, $harga, $vegetarian, $deskripsi]);
                    $target_id = (int)$pdo->lastInsertId();

                    if ($catatan_awal !== '') {
                        $ins_cg = $pdo->prepare("INSERT INTO catatan_gizi (menu_id, pakar_id, catatan, status) VALUES (?, ?, ?, 'disetujui')");
                        $ins_cg->execute([$target_id, $pakar_id, $catatan_awal]);
                    }

                    $_SESSION['pesan_pakar_menu'] = 'Menu klinis baru <strong>' . h($nama_menu) . '</strong> berhasil ditambahkan dan langsung aktif direkomendasikan ke pengguna!';
                }

                if (!empty($kategori_ids) && is_array($kategori_ids)) {
                    $ins_kat = $pdo->prepare("INSERT IGNORE INTO menu_kategori (menu_id, kategori_id) VALUES (?, ?)");
                    foreach ($kategori_ids as $kid) {
                        $kid = (int)$kid;
                        if ($kid > 0) $ins_kat->execute([$target_id, $kid]);
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
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM menu_kategori WHERE menu_id = ?")->execute([$id_hapus]);
                $pdo->prepare("DELETE FROM catatan_gizi WHERE menu_id = ? AND pakar_id = ?")->execute([$id_hapus, $pakar_id]);
                $pdo->prepare("DELETE FROM menu_diet WHERE id = ? AND pakar_id = ?")->execute([$id_hapus, $pakar_id]);
                $pdo->commit();
                $_SESSION['pesan_pakar_menu'] = 'Menu makanan berhasil dihapus.';
                $_SESSION['pesan_pakar_menu_tipe'] = 'sukses';
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['pesan_pakar_menu'] = 'Gagal menghapus menu makanan.';
                $_SESSION['pesan_pakar_menu_tipe'] = 'error';
            }
        }
        header('Location: ' . base_url('pakar/menu.php'));
        exit;
    }
}

// ── Filter & Pencarian ──────────────────────────────────────────────────
$cari     = trim($_GET['cari'] ?? '');
$wkt      = $_GET['waktu'] ?? '';
$status_f = $_GET['status'] ?? '';
$sumber_f = $_GET['sumber'] ?? '';

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

// ── Hitung Total untuk Pagination ───────────────────────────────────────
$sql_count = "
    SELECT COUNT(*)
    FROM menu_diet md
    LEFT JOIN catatan_gizi cg ON cg.menu_id = md.id AND cg.pakar_id = ?
    WHERE " . implode(' AND ', $kondisi);
$count_params = array_merge([$pakar_id], $params);
$st_count = $pdo->prepare($sql_count);
$st_count->execute($count_params);
$total_data = (int)$st_count->fetchColumn();

// Konfigurasi Pagination (10 baris per halaman)
$per_halaman = 10;
$total_halaman = max(1, (int)ceil($total_data / $per_halaman));
$halaman_saat_ini = max(1, min((int)($_GET['halaman'] ?? 1), $total_halaman));
$offset = ($halaman_saat_ini - 1) * $per_halaman;
$mulai_baris = $total_data > 0 ? $offset + 1 : 0;
$akhir_baris = min($offset + $per_halaman, $total_data);

// Helper URL pagination
$buat_url_hal = function($hal) use ($cari, $wkt, $status_f, $sumber_f) {
    $qs = ['halaman' => $hal];
    if ($cari !== '') $qs['cari'] = $cari;
    if ($wkt !== '') $qs['waktu'] = $wkt;
    if ($status_f !== '') $qs['status'] = $status_f;
    if ($sumber_f !== '') $qs['sumber'] = $sumber_f;
    return base_url('pakar/menu.php?' . http_build_query($qs));
};

// ── Ambil Data ─────────────────────────────────────────────────────────
$sql = "
    SELECT md.*,
           u.nama AS pembuat_nama,
           u.gelar AS pembuat_gelar,
           cg.id AS catatan_id,
           cg.catatan AS isi_catatan,
           cg.status  AS status_catatan,
           cg.created_at AS catatan_tgl,
           (SELECT GROUP_CONCAT(kategori_id) FROM menu_kategori WHERE menu_id = md.id) AS kategori_bmi_list
    FROM menu_diet md
    LEFT JOIN users u ON u.id = md.pakar_id
    LEFT JOIN catatan_gizi cg ON cg.menu_id = md.id AND cg.pakar_id = ?
    WHERE " . implode(' AND ', $kondisi) . "
    ORDER BY (md.pakar_id = ?) DESC, md.id DESC
    LIMIT " . (int)$per_halaman . " OFFSET " . (int)$offset . "
";
$query_params = array_merge([$pakar_id], $params, [$pakar_id]);
$st = $pdo->prepare($sql);
$st->execute($query_params);
$daftar_menu = $st->fetchAll();

$label_status = ['disetujui'=>'Disetujui','perlu_tinjau'=>'Perlu Tinjau','ditandai'=>'Ditandai'];
$label_waktu  = ['pagi'=>'Pagi','siang'=>'Siang','malam'=>'Malam','camilan'=>'Camilan'];

$judul = 'Kelola & Tinjauan Menu Gizi - Calorie Lab';
include BASE_DIR . 'includes/header.php';
include __DIR__ . '/nav.php';
?>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= $pesan ?></div>
<?php endif; ?>

<!-- ══════════════ FILTER & TOOLBAR PENCARIAN ══════════════ -->
<div class="kartu" style="padding: 20px 24px; margin-bottom: 20px; background: #FFFFFF !important;">
  <form method="get" action="<?= base_url('pakar/menu.php') ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin: 0;">
    <div style="position: relative; flex: 2; min-width: 200px;">
      <input type="text" name="cari" value="<?= h($cari) ?>" placeholder="Cari nama menu..." style="width: 100%; margin: 0; padding-left: 36px; height: 42px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--teks-redup); pointer-events: none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    </div>

    <select name="sumber" style="flex: 1; min-width: 160px; margin: 0; height: 42px;">
      <option value="">Semua Menu (Sistem & Pakar)</option>
      <option value="saya" <?= $sumber_f === 'saya' ? 'selected' : '' ?>>Hanya Kreasi Saya</option>
    </select>

    <select name="waktu" style="flex: 1; min-width: 140px; margin: 0; height: 42px;">
      <option value="">Semua Waktu Makan</option>
      <option value="pagi" <?= $wkt === 'pagi' ? 'selected' : '' ?>>Sarapan (Pagi)</option>
      <option value="siang" <?= $wkt === 'siang' ? 'selected' : '' ?>>Makan Siang</option>
      <option value="malam" <?= $wkt === 'malam' ? 'selected' : '' ?>>Makan Malam</option>
      <option value="camilan" <?= $wkt === 'camilan' ? 'selected' : '' ?>>Camilan</option>
    </select>

    <select name="status" style="flex: 1.2; min-width: 170px; margin: 0; height: 42px;">
      <option value="">Semua Status Catatan</option>
      <option value="tanpa_catatan" <?= $status_f === 'tanpa_catatan' ? 'selected' : '' ?>>Belum Ada Catatan</option>
      <option value="ada_catatan"   <?= $status_f === 'ada_catatan'   ? 'selected' : '' ?>>Ada Catatan</option>
      <option value="perlu_tinjau"  <?= $status_f === 'perlu_tinjau'  ? 'selected' : '' ?>>Perlu Tinjau</option>
    </select>

    <button type="submit" class="tombol kecil" style="margin: 0; padding: 0 20px; height: 42px; display: inline-flex; align-items: center; gap: 6px;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Filter
    </button>

    <?php if ($cari !== '' || $wkt !== '' || $status_f !== '' || $sumber_f !== ''): ?>
      <a href="<?= base_url('pakar/menu.php') ?>" class="tombol sekunder kecil" style="margin: 0; height: 42px; display: inline-flex; align-items: center; gap: 4px; padding: 0 14px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        Reset
      </a>
    <?php endif; ?>
  </form>
</div>

<!-- ══════════════ ACTION HEADER & COUNTER ══════════════ -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
  <div>
    <span class="teks-redup" style="font-size: 0.92rem;">
      Menampilkan <b><?= $mulai_baris ?> - <?= $akhir_baris ?></b> dari total <b><?= number_format($total_data, 0, ',', '.') ?></b> menu makanan
    </span>
  </div>
  <button type="button" class="tombol" onclick="bukaModalMenu()" style="display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; font-weight: 700;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Tambah Menu Baru
  </button>
</div>

<!-- ══════════════ TABEL DATA MENU ══════════════ -->
<div class="kartu" style="padding: 0; overflow-x: hidden; background: #FFFFFF !important; border: 1px solid var(--garis); border-radius: var(--radius-kartu); box-shadow: var(--bayangan-kartu);">
  <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem; table-layout: auto;">
    <thead>
      <tr style="background: #F8FAF8; border-bottom: 2px solid var(--garis);">
        <th style="padding: 14px 18px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 30%;">Menu &amp; Penyusun</th>
        <th style="padding: 14px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 10%;">Waktu</th>
        <th style="padding: 14px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 13%;">Kalori &amp; Berat</th>
        <th style="padding: 14px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 10%;">Harga</th>
        <th style="padding: 14px 12px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 15%;">Status Catatan</th>
        <th style="padding: 14px 18px; text-align: right; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 22%;">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($daftar_menu)): ?>
        <tr>
          <td colspan="6" style="padding: 40px 20px; text-align: center; color: var(--teks-redup);">
            Tidak ada menu yang sesuai filter.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($daftar_menu as $m):
          $is_milik_saya = ((int)($m['pakar_id'] ?? 0) === $pakar_id);
        ?>
          <tr style="border-bottom: 1px solid var(--garis); transition: background 0.15s ease;" onmouseover="this.style.background='var(--latar)'" onmouseout="this.style.background=''">

            <!-- MENU & PENYUSUN -->
            <td style="padding: 14px 18px;">
              <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                <strong style="color: var(--gelap); font-size: 0.95rem;"><?= h($m['nama_menu']) ?></strong>
                <?php if ($is_milik_saya): ?>
                  <span class="badge" style="background: var(--mint); color: var(--hijau); font-size: 0.68rem; font-weight: 800; padding: 2px 7px; border: 1px solid #A7F3D0;">Kreasi Saya</span>
                <?php elseif (!empty($m['pembuat_nama'])): ?>
                  <span class="badge" style="background: #E0E7FF; color: #3730A3; font-size: 0.68rem; font-weight: 700; padding: 2px 7px;">
                    Oleh <?= h($m['pembuat_gelar'] ? $m['pembuat_gelar'] . ' ' : '') ?><?= h($m['pembuat_nama']) ?>
                  </span>
                <?php endif; ?>
                <?php if ($m['vegetarian']): ?>
                  <span class="badge" style="background: #E8F5E9; color: #2E7D32; font-size: 0.68rem; font-weight: 700; padding: 2px 7px; border: 1px solid #C8E6C9;">Vegetarian</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($m['deskripsi'])): ?>
                <div style="font-size: 0.76rem; color: #64748B; margin-top: 3px; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= h($m['deskripsi']) ?>">
                  <?= h($m['deskripsi']) ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- WAKTU -->
            <td style="padding: 14px 10px; text-align: center;">
              <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>" style="font-size: 0.75rem;">
                <?= ucfirst(h($label_waktu[$m['waktu_makan']] ?? $m['waktu_makan'])) ?>
              </span>
            </td>

            <!-- KALORI & BERAT -->
            <td style="padding: 14px 10px; text-align: center;">
              <div style="font-weight: 750; color: #166534; font-size: 0.92rem; display: inline-flex; align-items: center; gap: 3px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <?= number_format($m['kalori'], 0, ',', '.') ?> kkal
              </div>
              <?php if (!empty($m['berat_gram'])): ?>
                <div style="font-size: 0.76rem; color: var(--teks-redup); margin-top: 2px;"><?= (int)$m['berat_gram'] ?> gram</div>
              <?php endif; ?>
            </td>

            <!-- HARGA -->
            <td style="padding: 14px 10px; text-align: center; color: var(--teks); font-size: 0.84rem; font-weight: 600;">
              <?= $m['harga'] > 0 ? 'Rp ' . number_format($m['harga'], 0, ',', '.') : '<span style="color:var(--teks-redup);">-</span>' ?>
            </td>

            <!-- STATUS CATATAN -->
            <td style="padding: 14px 12px; text-align: center;">
              <?php if ($m['catatan_id']): ?>
                <?php
                  $bg_map = ['disetujui'=>'#DCFCE7;color:#15803D;border:1px solid #BBF7D0', 'perlu_tinjau'=>'#FEF3C7;color:#92400E;border:1px solid #FDE68A', 'ditandai'=>'#FEE2E2;color:#991B1B;border:1px solid #FECACA'];
                  $bg_style = $bg_map[$m['status_catatan']] ?? '#F1F5F9;color:#64748B;border:1px solid #E2E8F0';
                ?>
                <span class="badge" style="font-size: 0.74rem; font-weight: 700; padding: 3px 9px; background: <?= $bg_style ?>;">
                  <?= h($label_status[$m['status_catatan']] ?? $m['status_catatan']) ?>
                </span>
              <?php else: ?>
                <span class="badge" style="background: #F1F5F9; color: #64748B; font-size: 0.74rem; font-weight: 600; padding: 3px 9px; border: 1px solid #E2E8F0;">
                  Belum Ditinjau
                </span>
              <?php endif; ?>
            </td>

            <!-- AKSI -->
            <td style="padding: 14px 18px; text-align: right; white-space: nowrap;">
              <div style="display: inline-flex; gap: 6px; align-items: center; flex-wrap: nowrap;">
                <?php if ($is_milik_saya): ?>
                  <button type="button" class="tombol sekunder kecil"
                          style="padding: 6px 10px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px; border-radius: var(--radius-pil);"
                          onclick="editMenu(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                  </button>
                  <form method="post" action="<?= base_url('pakar/menu.php') ?>" style="display: inline; margin: 0;"
                        onsubmit="return confirm('Hapus menu kreasi Anda ini dari sistem?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="aksi" value="hapus_menu_pakar">
                    <input type="hidden" name="id_menu" value="<?= (int)$m['id'] ?>">
                    <button type="submit" class="tombol kecil" style="background: #DC2626; padding: 6px 10px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 3px; border-radius: var(--radius-pil);">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                      Hapus
                    </button>
                  </form>
                <?php endif; ?>

                <button type="button"
                        class="tombol <?= $m['catatan_id'] ? 'sekunder' : '' ?> kecil"
                        style="padding: 6px 10px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px; border-radius: var(--radius-pil);"
                        onclick="bukaModal(<?= $m['id'] ?>, <?= h(json_encode($m['nama_menu'])) ?>, <?= h(json_encode($m['isi_catatan'] ?? '')) ?>, <?= h(json_encode($m['status_catatan'] ?? 'disetujui')) ?>, <?= (int)($m['catatan_id'] ?? 0) ?>)">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                  <?= $m['catatan_id'] ? 'Catatan' : '+ Catatan' ?>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- TABLE FOOTER PAGINATION BAR -->
  <?php if ($total_halaman > 1): ?>
    <?php
    $max_angka = 5;
    $mulai_hal = max(1, $halaman_saat_ini - 2);
    $akhir_hal = min($total_halaman, $mulai_hal + $max_angka - 1);
    if ($akhir_hal - $mulai_hal + 1 < $max_angka) {
        $mulai_hal = max(1, $akhir_hal - $max_angka + 1);
    }
    ?>
    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 18px 20px; border-top: 1px solid var(--garis); background: #F8FAF8; gap: 8px; border-bottom-left-radius: var(--radius-kartu); border-bottom-right-radius: var(--radius-kartu);">
      <div style="display: flex; gap: 6px; align-items: center; justify-content: center; flex-wrap: wrap;">
        <?php if ($halaman_saat_ini > 1): ?>
          <a href="<?= h($buat_url_hal($halaman_saat_ini - 1)) ?>" class="pagination-nav-btn" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">← Sebelumnya</a>
        <?php else: ?>
          <span class="pagination-nav-btn disabled" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">← Sebelumnya</span>
        <?php endif; ?>

        <?php if ($mulai_hal > 1): ?>
          <a href="<?= h($buat_url_hal(1)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;">1</a>
          <?php if ($mulai_hal > 2): ?>
            <span class="pagination-ellipsis" style="width: 18px; font-size: 0.82rem;">&hellip;</span>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $mulai_hal; $i <= $akhir_hal; $i++): ?>
          <?php if ($i === $halaman_saat_ini): ?>
            <span class="pagination-number-btn aktif" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $i ?></span>
          <?php else: ?>
            <a href="<?= h($buat_url_hal($i)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($akhir_hal < $total_halaman): ?>
          <?php if ($akhir_hal < $total_halaman - 1): ?>
            <span class="pagination-ellipsis" style="width: 18px; font-size: 0.82rem;">&hellip;</span>
          <?php endif; ?>
          <a href="<?= h($buat_url_hal($total_halaman)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $total_halaman ?></a>
        <?php endif; ?>

        <?php if ($halaman_saat_ini < $total_halaman): ?>
          <a href="<?= h($buat_url_hal($halaman_saat_ini + 1)) ?>" class="pagination-nav-btn" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">Selanjutnya →</a>
        <?php else: ?>
          <span class="pagination-nav-btn disabled" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">Selanjutnya →</span>
        <?php endif; ?>
      </div>

      <span class="teks-redup" style="font-size: 0.8rem;">
        Halaman <b><?= $halaman_saat_ini ?></b> dari <b><?= $total_halaman ?></b> &middot; Menampilkan menu <b><?= $mulai_baris ?> - <?= $akhir_baris ?></b> dari total <b><?= number_format($total_data, 0, ',', '.') ?></b>
      </span>
    </div>
  <?php endif; ?>
</div>

<!-- ══════════════ MODAL TAMBAH / EDIT MENU PAKAR ══════════════ -->
<div id="modal-menu-pakar" style="display:none; position:fixed; inset:0; background:rgba(18,38,29,0.45); backdrop-filter:blur(4px); z-index:400; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#FFFFFF !important; border:1px solid var(--garis); border-radius:var(--radius-kartu); padding:28px; max-width:640px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.22);">
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
        <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:8px;">Target Rekomendasi Kategori BMI</label>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; background:var(--latar); padding:14px; border-radius:10px; border:1px solid var(--garis);">
          <?php foreach ($semua_kategori_bmi as $kb): ?>
            <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none;">
              <input type="checkbox" name="kategori_bmi[]" value="<?= $kb['id'] ?>" class="check-kategori-bmi" id="kat-bmi-<?= $kb['id'] ?>"
                     style="width:15px; height:15px; accent-color:var(--hijau); flex-shrink:0; cursor:pointer;">
              <span class="badge kategori-<?= h($kb['kode'] ?? 'normal') ?>" style="font-size:0.82rem; font-weight:600; padding:3px 10px; border-radius:20px;">
                <?= h($kb['nama']) ?>
              </span>
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
<div id="modal-catatan" style="display:none; position:fixed; inset:0; background:rgba(18,38,29,0.4); backdrop-filter:blur(4px); z-index:400; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#FFFFFF !important; border:1px solid var(--garis); border-radius:var(--radius-kartu); padding:28px; max-width:520px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
      <div>
        <h3 id="modal-judul" style="margin:0 0 4px; font-size:1.1rem; font-weight:800; color:var(--gelap);"></h3>
        <p style="margin:0; font-size:0.82rem; color:var(--teks-redup);">Tambahkan catatan atau penilaian gizi untuk menu ini.</p>
      </div>
      <button type="button" onclick="tutupModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--teks-redup); line-height:1;">&times;</button>
    </div>

    <form method="post" action="<?= base_url('pakar/menu.php') ?>" id="form-catatan">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="aksi" value="simpan_catatan">
      <input type="hidden" name="menu_id" id="modal-menu-id" value="">

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Catatan Gizi</label>
        <textarea id="modal-catatan-isi" name="catatan" rows="4"
                  placeholder="Contoh: Menu ini tinggi serat, cocok untuk pengguna dengan kategori gemuk. Kurangi garam..."
                  style="width:100%; font-family:inherit; font-size:0.88rem; padding:10px 14px; border:1.5px solid var(--garis); border-radius:10px; resize:vertical;"></textarea>
      </div>

      <div style="margin-bottom:18px;">
        <label style="display:block; font-size:0.84rem; font-weight:700; color:var(--gelap); margin-bottom:5px;">Status Penilaian</label>
        <select name="status" id="modal-status"
                style="width:100%; padding:10px 14px; font-size:0.88rem; border:1.5px solid var(--garis); border-radius:10px; font-family:inherit; background:#fff;">
          <option value="disetujui">Disetujui — Menu gizi sudah baik</option>
          <option value="perlu_tinjau">Perlu Tinjau — Ada yang perlu diperhatikan</option>
          <option value="ditandai">Ditandai — Perlu perbaikan atau peringatan</option>
        </select>
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
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
    if (e.key === 'Escape') { tutupModal(); tutupModalMenu(); }
  });
</script>

<?php include BASE_DIR . 'includes/footer.php'; ?>
