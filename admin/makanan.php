<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_admin();

$csrf = csrf_token('csrf_token_admin_makanan');
$pesan = $_SESSION['pesan_admin'] ?? '';
$pesan_tipe = $_SESSION['pesan_admin_tipe'] ?? 'sukses';
unset($_SESSION['pesan_admin'], $_SESSION['pesan_admin_tipe']);

// Ambil semua kategori BMI untuk checkbox target rekomendasi
$semua_kategori_bmi = $pdo->query("SELECT * FROM kategori_bmi ORDER BY id ASC")->fetchAll();

// ── PROSES POST (SIMPAN & HAPUS MENU) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $aksi  = $_POST['aksi'] ?? '';

    if (!csrf_valid($token, 'csrf_token_admin_makanan')) {
        $_SESSION['pesan_admin'] = 'Sesi formulir tidak valid atau kedaluwarsa. Silakan ulangi.';
        $_SESSION['pesan_admin_tipe'] = 'error';
        header('Location: ' . base_url('admin/makanan.php'));
        exit;
    }

    if ($aksi === 'hapus') {
        $id_hapus = (int)($_POST['id'] ?? 0);
        if ($id_hapus > 0) {
            try {
                $pdo->beginTransaction();
                // Bersihkan relasi kategori dan catatan gizi terkait
                $pdo->prepare("DELETE FROM menu_kategori WHERE menu_id = ?")->execute([$id_hapus]);
                $pdo->prepare("DELETE FROM catatan_gizi WHERE menu_id = ?")->execute([$id_hapus]);
                $st_del = $pdo->prepare("DELETE FROM menu_diet WHERE id = ?");
                $st_del->execute([$id_hapus]);
                $pdo->commit();
                $_SESSION['pesan_admin'] = 'Menu makanan berhasil dihapus dari basis data.';
                $_SESSION['pesan_admin_tipe'] = 'sukses';
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['pesan_admin'] = 'Gagal menghapus menu makanan: data masih terhubung dengan riwayat konsumsi pengguna.';
                $_SESSION['pesan_admin_tipe'] = 'error';
            }
        }
        header('Location: ' . base_url('admin/makanan.php'));
        exit;
    }

    if ($aksi === 'simpan') {
        $id_menu      = (int)($_POST['id'] ?? 0);
        $nama_menu    = trim($_POST['nama_menu'] ?? '');
        $waktu_makan  = $_POST['waktu_makan'] ?? 'pagi';
        $kalori       = (int)($_POST['kalori'] ?? 0);
        $berat_gram   = !empty($_POST['berat_gram']) ? (int)$_POST['berat_gram'] : null;
        $takaran_saji = trim($_POST['takaran_saji'] ?? '');
        $harga        = (int)($_POST['harga'] ?? 0);
        $vegetarian   = isset($_POST['vegetarian']) ? 1 : 0;
        $deskripsi    = trim($_POST['deskripsi'] ?? '');
        $kategori_ids = $_POST['kategori_bmi'] ?? [];

        // Validasi input batas keamanan & integritas
        $waktu_valid = ['pagi', 'siang', 'malam', 'camilan'];
        if (mb_strlen($nama_menu) < 3 || mb_strlen($nama_menu) > 120) {
            $_SESSION['pesan_admin'] = 'Nama menu harus berisi antara 3 sampai 120 karakter.';
            $_SESSION['pesan_admin_tipe'] = 'error';
        } elseif (!in_array($waktu_makan, $waktu_valid, true)) {
            $_SESSION['pesan_admin'] = 'Waktu makan yang dipilih tidak valid.';
            $_SESSION['pesan_admin_tipe'] = 'error';
        } elseif ($kalori < 10 || $kalori > 3000) {
            $_SESSION['pesan_admin'] = 'Nilai kalori harus berada di rentang 10 - 3000 kkal.';
            $_SESSION['pesan_admin_tipe'] = 'error';
        } elseif ($harga < 0 || $harga > 500000) {
            $_SESSION['pesan_admin'] = 'Perkiraan harga harus berada di rentang Rp 0 - Rp 500.000.';
            $_SESSION['pesan_admin_tipe'] = 'error';
        } else {
            $pdo->beginTransaction();
            try {
                if ($id_menu > 0) {
                    // UPDATE
                    $st_up = $pdo->prepare("
                        UPDATE menu_diet
                        SET nama_menu = ?, waktu_makan = ?, kalori = ?, berat_gram = ?, takaran_saji = ?, harga = ?, vegetarian = ?, deskripsi = ?
                        WHERE id = ?
                    ");
                    $st_up->execute([$nama_menu, $waktu_makan, $kalori, $berat_gram, $takaran_saji, $harga, $vegetarian, $deskripsi, $id_menu]);

                    // Hapus relasi lama dan perbarui
                    $del_kat = $pdo->prepare("DELETE FROM menu_kategori WHERE menu_id = ?");
                    $del_kat->execute([$id_menu]);
                    $target_id = $id_menu;
                    $_SESSION['pesan_admin'] = 'Menu makanan <strong>' . h($nama_menu) . '</strong> berhasil diperbarui.';
                } else {
                    // INSERT (Menu baru dari admin, pakar_id = NULL)
                    $st_in = $pdo->prepare("
                        INSERT INTO menu_diet (pakar_id, nama_menu, waktu_makan, kalori, berat_gram, takaran_saji, harga, vegetarian, deskripsi)
                        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $st_in->execute([$nama_menu, $waktu_makan, $kalori, $berat_gram, $takaran_saji, $harga, $vegetarian, $deskripsi]);
                    $target_id = (int)$pdo->lastInsertId();
                    $_SESSION['pesan_admin'] = 'Menu makanan baru <strong>' . h($nama_menu) . '</strong> berhasil ditambahkan ke basis data.';
                }

                // Simpan relasi kategori BMI
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
                $_SESSION['pesan_admin_tipe'] = 'sukses';
                header('Location: ' . base_url('admin/makanan.php'));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['pesan_admin'] = 'Terjadi kesalahan basis data saat menyimpan menu.';
                $_SESSION['pesan_admin_tipe'] = 'error';
            }
        }
    }
}

// ── FILTER & PENCARIAN ──────────────────────────────────────────────────
$kata_kunci        = trim($_GET['cari'] ?? '');
$filter_waktu      = $_GET['waktu'] ?? '';
$filter_verifikasi = $_GET['status_verifikasi'] ?? '';

$kondisi = ['1=1'];
$params  = [];

if ($kata_kunci !== '') {
    $kondisi[] = 'md.nama_menu LIKE ?';
    $params[]  = '%' . $kata_kunci . '%';
}
if ($filter_waktu !== '' && in_array($filter_waktu, ['pagi', 'siang', 'malam', 'camilan'], true)) {
    $kondisi[] = 'md.waktu_makan = ?';
    $params[]  = $filter_waktu;
}
if ($filter_verifikasi === 'terverifikasi') {
    $kondisi[] = "(md.pakar_id IS NOT NULL OR (SELECT status FROM catatan_gizi WHERE menu_id = md.id ORDER BY id DESC LIMIT 1) = 'disetujui')";
} elseif ($filter_verifikasi === 'belum_ditinjau') {
    $kondisi[] = "(md.pakar_id IS NULL AND (SELECT id FROM catatan_gizi WHERE menu_id = md.id LIMIT 1) IS NULL)";
} elseif ($filter_verifikasi === 'perlu_tinjau') {
    $kondisi[] = "((SELECT status FROM catatan_gizi WHERE menu_id = md.id ORDER BY id DESC LIMIT 1) = 'perlu_tinjau')";
}

// ── HITUNG TOTAL DATA UNTUK PAGINATION ──────────────────────────────────
$sql_count = "SELECT COUNT(*) FROM menu_diet md WHERE " . implode(' AND ', $kondisi);
$st_count = $pdo->prepare($sql_count);
$st_count->execute($params);
$total_data = (int)$st_count->fetchColumn();

// Konfigurasi Pagination (10 baris per halaman agar pas 1 layar tanpa scroll jauh)
$per_halaman = 10;
$total_halaman = max(1, (int)ceil($total_data / $per_halaman));
$halaman_saat_ini = max(1, min((int)($_GET['halaman'] ?? 1), $total_halaman));
$offset = ($halaman_saat_ini - 1) * $per_halaman;
$mulai_baris = $total_data > 0 ? $offset + 1 : 0;
$akhir_baris = min($offset + $per_halaman, $total_data);

// Helper pembentuk URL pagination
$buat_url_hal = function($hal) use ($kata_kunci, $filter_waktu, $filter_verifikasi) {
    $qs = ['halaman' => $hal];
    if ($kata_kunci !== '') $qs['cari'] = $kata_kunci;
    if ($filter_waktu !== '') $qs['waktu'] = $filter_waktu;
    if ($filter_verifikasi !== '') $qs['status_verifikasi'] = $filter_verifikasi;
    return base_url('admin/makanan.php?' . http_build_query($qs));
};

// ── AMBIL DATA DENGAN STATUS VERIFIKASI PAKAR ───────────────────────────
$sql = "
    SELECT md.*,
           u.nama AS pembuat_nama,
           u.gelar AS pembuat_gelar,
           (SELECT cg.status FROM catatan_gizi cg WHERE cg.menu_id = md.id ORDER BY cg.id DESC LIMIT 1) AS status_catatan,
           (SELECT pu.nama FROM catatan_gizi cg JOIN users pu ON pu.id = cg.pakar_id WHERE cg.menu_id = md.id ORDER BY cg.id DESC LIMIT 1) AS reviewer_nama,
           (SELECT pu.gelar FROM catatan_gizi cg JOIN users pu ON pu.id = cg.pakar_id WHERE cg.menu_id = md.id ORDER BY cg.id DESC LIMIT 1) AS reviewer_gelar,
           GROUP_CONCAT(DISTINCT kb.nama ORDER BY kb.id SEPARATOR ', ') AS cocok_kategori,
           GROUP_CONCAT(DISTINCT kb.kode ORDER BY kb.id SEPARATOR ',') AS kode_kategori,
           GROUP_CONCAT(DISTINCT mk.kategori_id ORDER BY mk.kategori_id SEPARATOR ',') AS kategori_ids_str
    FROM menu_diet md
    LEFT JOIN users u ON u.id = md.pakar_id
    LEFT JOIN menu_kategori mk ON mk.menu_id = md.id
    LEFT JOIN kategori_bmi kb ON kb.id = mk.kategori_id
    WHERE " . implode(' AND ', $kondisi) . "
    GROUP BY md.id
    ORDER BY md.id DESC
    LIMIT " . (int)$per_halaman . " OFFSET " . (int)$offset . "
";
$st_daftar = $pdo->prepare($sql);
$st_daftar->execute($params);
$daftar_menu = $st_daftar->fetchAll();

$label_waktu = ['pagi' => 'Sarapan (Pagi)', 'siang' => 'Makan Siang', 'malam' => 'Makan Malam', 'camilan' => 'Camilan'];

$judul = 'Katalog Makanan & Verifikasi - Admin';
include BASE_DIR . 'includes/header.php';
?>

<?php include __DIR__ . '/nav.php'; ?>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= $pesan ?></div>
<?php endif; ?>

<!-- ══════════════ FILTER & TOOLBAR PENCARIAN ══════════════ -->
<div class="kartu" style="padding: 20px 24px; margin-bottom: 20px; background: #FFFFFF !important;">
  <form method="get" action="<?= base_url('admin/makanan.php') ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin: 0;">
    <div style="position: relative; flex: 2; min-width: 200px;">
      <input type="text" name="cari" value="<?= h($kata_kunci) ?>" placeholder="Cari nama makanan..." style="width: 100%; margin: 0; padding-left: 36px; height: 42px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--teks-redup); pointer-events: none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    </div>

    <select name="waktu" style="flex: 1; min-width: 140px; margin: 0; height: 42px;">
      <option value="">Semua Waktu Makan</option>
      <option value="pagi" <?= $filter_waktu === 'pagi' ? 'selected' : '' ?>>Pagi (Sarapan)</option>
      <option value="siang" <?= $filter_waktu === 'siang' ? 'selected' : '' ?>>Makan Siang</option>
      <option value="malam" <?= $filter_waktu === 'malam' ? 'selected' : '' ?>>Makan Malam</option>
      <option value="camilan" <?= $filter_waktu === 'camilan' ? 'selected' : '' ?>>Camilan</option>
    </select>

    <select name="status_verifikasi" style="flex: 1.2; min-width: 170px; margin: 0; height: 42px;">
      <option value="">Semua Status Verifikasi Gizi</option>
      <option value="terverifikasi" <?= $filter_verifikasi === 'terverifikasi' ? 'selected' : '' ?>>Terverifikasi Pakar</option>
      <option value="belum_ditinjau" <?= $filter_verifikasi === 'belum_ditinjau' ? 'selected' : '' ?>>Belum Ditinjau (Draft/Admin)</option>
      <option value="perlu_tinjau"  <?= $filter_verifikasi === 'perlu_tinjau'  ? 'selected' : '' ?>>Perlu Tinjau Ulang</option>
    </select>

    <button type="submit" class="tombol kecil" style="margin: 0; padding: 0 20px; height: 42px; display: inline-flex; align-items: center; gap: 6px;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Filter
    </button>

    <?php if ($kata_kunci !== '' || $filter_waktu !== '' || $filter_verifikasi !== ''): ?>
      <a href="<?= base_url('admin/makanan.php') ?>" class="tombol sekunder kecil" style="margin: 0; height: 42px; display: inline-flex; align-items: center; gap: 4px; padding: 0 14px;">
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

  <button type="button" class="tombol" onclick="bukaModalTambahMenu()" style="display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; font-weight: 700;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Tambah Menu Baru
  </button>
</div>

<!-- ══════════════ TABEL DATA MAKANAN ══════════════ -->
<div class="kartu" style="padding: 0; overflow-x: auto; background: #FFFFFF !important; border: 1px solid var(--garis); border-radius: var(--radius-kartu); box-shadow: var(--bayangan-kartu);">
  <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem; table-layout: auto;">
    <thead>
      <tr style="background: #F8FAF8; border-bottom: 2px solid var(--garis);">
        <th style="padding: 14px 18px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 27%;">Menu &amp; Takaran</th>
        <th style="padding: 14px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 11%;">Waktu</th>
        <th style="padding: 14px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 13%;">Kalori &amp; Berat</th>
        <th style="padding: 14px 10px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 10%;">Harga</th>
        <th style="padding: 14px 12px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 17%;">Target BMI</th>
        <th style="padding: 14px 12px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 13%;">Verifikasi Pakar</th>
        <th style="padding: 14px 18px; text-align: right; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 9%;">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($daftar_menu)): ?>
        <tr>
          <td colspan="7" style="padding: 40px 20px; text-align: center; color: var(--teks-redup);">
            Tidak ada data makanan yang cocok dengan kriteria pencarian.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($daftar_menu as $m): 
          $is_pakar_menu = !empty($m['pakar_id']);
          $is_disetujui  = ($m['status_catatan'] ?? '') === 'disetujui' || $is_pakar_menu;
        ?>
          <tr style="border-bottom: 1px solid var(--garis); transition: background 0.15s ease;">
            <!-- MENU & TAKARAN -->
            <td style="padding: 14px 18px;">
              <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                <strong style="color: var(--gelap); font-size: 0.95rem;"><?= h($m['nama_menu']) ?></strong>
                <?php if ($m['vegetarian']): ?>
                  <span class="badge" style="background: #E8F5E9; color: #2E7D32; font-size: 0.68rem; font-weight: 700; padding: 2px 7px; border: 1px solid #C8E6C9;">Vegetarian</span>
                <?php endif; ?>
              </div>

              <?php if (!empty($m['takaran_saji'])): ?>
                <div style="font-size: 0.78rem; color: var(--teks-redup); margin-top: 3px;">
                  Porsi: <?= h($m['takaran_saji']) ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($m['deskripsi'])): ?>
                <div style="font-size: 0.76rem; color: #64748B; margin-top: 2px; max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= h($m['deskripsi']) ?>">
                  <?= h($m['deskripsi']) ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- WAKTU -->
            <td style="padding: 14px 10px; text-align: center;">
              <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>" style="font-size: 0.75rem;">
                <?= ucfirst(h($m['waktu_makan'])) ?>
              </span>
            </td>

            <!-- KALORI & BERAT -->
            <td style="padding: 14px 10px; text-align: center;">
              <div style="font-weight: 750; color: #166534; font-size: 0.92rem; display: inline-flex; align-items: center; gap: 3px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <?= number_format($m['kalori'], 0, ',', '.') ?> kkal
              </div>
              <?php if (!empty($m['berat_gram'])): ?>
                <div style="font-size: 0.76rem; color: var(--teks-redup); margin-top: 2px;">
                  <?= (int)$m['berat_gram'] ?> gram
                </div>
              <?php endif; ?>
            </td>

            <!-- HARGA -->
            <td style="padding: 14px 10px; text-align: center; color: var(--teks); font-size: 0.84rem; font-weight: 600;">
              <?= $m['harga'] > 0 ? 'Rp ' . number_format($m['harga'], 0, ',', '.') : '<span style="color:var(--teks-redup);">-</span>' ?>
            </td>

            <!-- TARGET BMI -->
            <td style="padding: 14px 12px; text-align: left;">
              <?php if ($m['cocok_kategori']): ?>
                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                  <?php 
                  $kodes = explode(',', $m['kode_kategori']);
                  $namas = explode(', ', $m['cocok_kategori']);
                  foreach ($namas as $idx => $nama_kat): 
                    $kode_kat = $kodes[$idx] ?? 'normal';
                  ?>
                    <span class="badge kategori-<?= h(trim($kode_kat)) ?>" style="font-size: 0.7rem; font-weight: 700; padding: 2px 7px;">
                      <?= h(trim($nama_kat)) ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span style="font-size: 0.75rem; color: var(--teks-redup);">Umum (Semua BMI)</span>
              <?php endif; ?>
            </td>

            <!-- STATUS VERIFIKASI GIZI -->
            <td style="padding: 14px 12px; text-align: center;">
              <?php if ($is_pakar_menu): ?>
                <span class="badge" style="background: #DCFCE7; color: #15803D; font-size: 0.74rem; font-weight: 700; padding: 3px 9px; border: 1px solid #BBF7D0; display: inline-flex; align-items: center; gap: 4px;">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Kreasi Pakar
                </span>
                <div style="font-size: 0.72rem; color: #4338CA; font-weight: 600; margin-top: 3px;">
                  <?= h($m['pembuat_gelar'] ? $m['pembuat_gelar'] . ' ' : '') ?><?= h($m['pembuat_nama']) ?>
                </div>
              <?php elseif (($m['status_catatan'] ?? '') === 'disetujui'): ?>
                <span class="badge" style="background: #DCFCE7; color: #15803D; font-size: 0.74rem; font-weight: 700; padding: 3px 9px; border: 1px solid #BBF7D0; display: inline-flex; align-items: center; gap: 4px;">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Terverifikasi
                </span>
                <?php if (!empty($m['reviewer_nama'])): ?>
                  <div style="font-size: 0.72rem; color: var(--teks-redup); margin-top: 3px;">
                    Oleh <?= h($m['reviewer_gelar'] ? $m['reviewer_gelar'] . ' ' : '') ?><?= h($m['reviewer_nama']) ?>
                  </div>
                <?php endif; ?>
              <?php elseif (($m['status_catatan'] ?? '') === 'perlu_tinjau'): ?>
                <span class="badge" style="background: #FEF3C7; color: #92400E; font-size: 0.74rem; font-weight: 700; padding: 3px 9px; border: 1px solid #FDE68A;">
                  Perlu Tinjau
                </span>
              <?php else: ?>
                <span class="badge" style="background: #F1F5F9; color: #64748B; font-size: 0.74rem; font-weight: 600; padding: 3px 9px; border: 1px solid #E2E8F0;">
                  Belum Ditinjau
                </span>
                <div style="font-size: 0.72rem; color: var(--teks-redup); margin-top: 3px;">
                  Input Admin / Sistem
                </div>
              <?php endif; ?>
            </td>

            <!-- AKSI -->
            <td style="padding: 14px 18px; text-align: right; white-space: nowrap;">
              <div style="display: inline-flex; gap: 6px; align-items: center;">
                <button type="button" class="tombol sekunder kecil" style="padding: 6px 10px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px; border-radius: var(--radius-pil);"
                        onclick='bukaModalEditMenu(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, "UTF-8") ?>)'>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  Edit
                </button>

                <form method="post" action="<?= base_url('admin/makanan.php') ?>" style="display: inline; margin: 0;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus menu \'<?= h(addslashes($m['nama_menu'])) ?>\' dari sistem?');">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <button type="submit" class="tombol kecil" style="background: #DC2626; padding: 6px 10px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 3px; border-radius: var(--radius-pil);">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    Hapus
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- TABLE FOOTER PAGINATION BAR (Di Dalam Kartu Tabel) -->
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

<!-- ══════════════ MODAL TAMBAH / EDIT MENU ADMIN ══════════════ -->
<div id="modal-menu-admin" style="display: none; position: fixed; inset: 0; background: rgba(18, 38, 29, 0.45); backdrop-filter: blur(4px); z-index: 400; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: #FFFFFF !important; border: 1px solid var(--garis); border-radius: var(--radius-kartu); padding: 28px; max-width: 640px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 64px rgba(0,0,0,0.22);">
    
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
      <div>
        <h3 id="form-menu-judul" style="margin: 0; font-size: 1.25rem; font-weight: 800; color: var(--gelap); display: flex; align-items: center; gap: 8px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Tambah Menu Baru
        </h3>
        <p id="form-menu-subjudul" class="teks-redup" style="margin: 4px 0 0; font-size: 0.84rem;">
          Lengkapi data nutrisi, takaran porsi, dan target kategori BMI.
        </p>
      </div>
      <button type="button" onclick="tutupModalMenu()" style="background: none; border: none; font-size: 1.6rem; cursor: pointer; color: var(--teks-redup); line-height: 1; padding: 0 4px;">&times;</button>
    </div>

    <!-- Banner Info Verifikasi Pakar -->
    <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 0.82rem; color: #166534; display: flex; align-items: center; gap: 8px;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      <span>Menu yang baru ditambahkan oleh Administrator akan berstatus <strong>Belum Ditinjau</strong> hingga ditinjau dan divalidasi oleh Pakar Gizi klinis.</span>
    </div>

    <form method="post" action="<?= base_url('admin/makanan.php') ?>" id="form-crud-makanan">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" id="input-menu-id" value="0">

      <div style="margin-bottom: 14px;">
        <label for="input-nama-menu" style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 5px;">
          Nama Menu / Masakan <span style="color: #DC2626;">*</span>
        </label>
        <input type="text" id="input-nama-menu" name="nama_menu" required placeholder="Contoh: Dada Ayam Panggang & Brokoli Rebus" style="width: 100%; padding: 10px 14px; font-size: 0.88rem;">
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
        <div>
          <label for="input-waktu-makan" style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 5px;">
            Waktu Makan <span style="color: #DC2626;">*</span>
          </label>
          <select id="input-waktu-makan" name="waktu_makan" required style="width: 100%; padding: 10px 14px; font-size: 0.88rem;">
            <option value="pagi">Sarapan (Pagi)</option>
            <option value="siang">Makan Siang</option>
            <option value="malam">Makan Malam</option>
            <option value="camilan">Camilan / Snack</option>
          </select>
        </div>

        <div>
          <label for="input-kalori" style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 5px;">
            Kalori (kkal) <span style="color: #DC2626;">*</span>
          </label>
          <input type="number" id="input-kalori" name="kalori" required min="10" max="3000" placeholder="Contoh: 350" style="width: 100%; padding: 10px 14px; font-size: 0.88rem;">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 14px; margin-bottom: 14px;">
        <div>
          <label for="input-berat-gram" style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 5px;">
            Total Berat (Gram)
          </label>
          <input type="number" id="input-berat-gram" name="berat_gram" min="0" max="3000" placeholder="Contoh: 250" style="width: 100%; padding: 10px 14px; font-size: 0.88rem;">
        </div>
        <div>
          <label for="input-takaran-saji" style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 5px;">
            Takaran Porsi Standar
          </label>
          <input type="text" id="input-takaran-saji" name="takaran_saji" placeholder="Contoh: 1 piring (185g) + 1 butir telur (65g)" style="width: 100%; padding: 10px 14px; font-size: 0.88rem;">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
        <div>
          <label for="input-harga" style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 5px;">
            Perkiraan Harga (Rp)
          </label>
          <input type="number" id="input-harga" name="harga" step="500" min="0" max="500000" placeholder="Contoh: 25000" style="width: 100%; padding: 10px 14px; font-size: 0.88rem;">
        </div>
        <div style="display: flex; align-items: flex-end; padding-bottom: 8px;">
          <label style="display: flex; align-items: center; gap: 8px; font-size: 0.86rem; font-weight: 600; cursor: pointer; margin: 0;">
            <input type="checkbox" id="input-vegetarian" name="vegetarian" value="1" style="width: 18px; height: 18px; margin: 0;">
            Menu Ramah Vegetarian
          </label>
        </div>
      </div>

      <div style="margin-bottom: 14px;">
        <label for="input-deskripsi" style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 5px;">
          Deskripsi / Komposisi Singkat
        </label>
        <textarea id="input-deskripsi" name="deskripsi" rows="3" placeholder="Informasi gizi, saran pengolahan, atau porsi..." style="width: 100%; padding: 10px 14px; font-size: 0.88rem; resize: vertical;"></textarea>
      </div>

      <div style="margin-bottom: 18px;">
        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 8px;">
          Target Rekomendasi Kategori BMI
        </label>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; background: var(--latar); padding: 12px; border-radius: 10px; border: 1px solid var(--garis);">
          <?php foreach ($semua_kategori_bmi as $kat): ?>
            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer; margin: 0;">
              <input type="checkbox" name="kategori_bmi[]" value="<?= $kat['id'] ?>" class="check-kategori-admin" id="admin-kat-<?= $kat['id'] ?>" style="width: auto; margin: 0;">
              <span class="badge kategori-<?= h($kat['kode']) ?>" style="font-size: 0.74rem;"><?= h($kat['nama']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 10px; border-top: 1px solid var(--garis);">
        <button type="button" onclick="tutupModalMenu()" class="tombol sekunder kecil" style="padding: 10px 20px;">Batal</button>
        <button type="submit" id="btn-submit-makanan" class="tombol kecil" style="padding: 10px 24px; font-weight: 700;">Simpan Menu Makanan</button>
      </div>
    </form>
  </div>
</div>

<script>
  function bukaModalTambahMenu() {
    document.getElementById('form-menu-judul').innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Tambah Menu Baru';
    document.getElementById('form-menu-subjudul').textContent = 'Tambahkan item baru ke dalam katalog sistem rekomendasi diet.';
    document.getElementById('input-menu-id').value = '0';
    document.getElementById('input-nama-menu').value = '';
    document.getElementById('input-waktu-makan').value = 'pagi';
    document.getElementById('input-kalori').value = '';
    document.getElementById('input-berat-gram').value = '';
    document.getElementById('input-takaran-saji').value = '';
    document.getElementById('input-harga').value = '0';
    document.getElementById('input-vegetarian').checked = false;
    document.getElementById('input-deskripsi').value = '';
    document.getElementById('btn-submit-makanan').textContent = 'Tambahkan Makanan';

    // Kosongkan semua checkbox BMI
    document.querySelectorAll('.check-kategori-admin').forEach(function(el) {
      el.checked = false;
    });

    document.getElementById('modal-menu-admin').style.display = 'flex';
    document.getElementById('input-nama-menu').focus();
  }

  function bukaModalEditMenu(item) {
    document.getElementById('form-menu-judul').innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Ubah Menu #' + item.id;
    document.getElementById('form-menu-subjudul').textContent = 'Perbarui takaran nutrisi, porsi saji, atau kecocokan kategori BMI.';
    document.getElementById('input-menu-id').value = item.id;
    document.getElementById('input-nama-menu').value = item.nama_menu;
    document.getElementById('input-waktu-makan').value = item.waktu_makan;
    document.getElementById('input-kalori').value = item.kalori;
    document.getElementById('input-berat-gram').value = item.berat_gram || '';
    document.getElementById('input-takaran-saji').value = item.takaran_saji || '';
    document.getElementById('input-harga').value = item.harga || '0';
    document.getElementById('input-vegetarian').checked = (parseInt(item.vegetarian) === 1);
    document.getElementById('input-deskripsi').value = item.deskripsi || '';
    document.getElementById('btn-submit-makanan').textContent = 'Simpan Perubahan';

    // Set centang kategori BMI berdasarkan kategori_ids_str
    var listKat = (item.kategori_ids_str || '').split(',');
    document.querySelectorAll('.check-kategori-admin').forEach(function(el) {
      el.checked = listKat.includes(el.value);
    });

    document.getElementById('modal-menu-admin').style.display = 'flex';
    document.getElementById('input-nama-menu').focus();
  }

  function tutupModalMenu() {
    document.getElementById('modal-menu-admin').style.display = 'none';
  }

  document.getElementById('modal-menu-admin').addEventListener('click', function(e) {
    if (e.target === this) tutupModalMenu();
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      tutupModalMenu();
    }
  });
</script>

<?php include BASE_DIR . 'includes/footer.php'; ?>
