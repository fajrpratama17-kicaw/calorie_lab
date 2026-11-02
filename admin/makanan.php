<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_admin();

$csrf = csrf_token('csrf_token_admin_makanan');
$pesan = $_SESSION['pesan_admin'] ?? '';
$pesan_tipe = $_SESSION['pesan_admin_tipe'] ?? 'sukses';
unset($_SESSION['pesan_admin'], $_SESSION['pesan_admin_tipe']);

// Ambil semua kategori BMI untuk relasi checkbox
$semua_kategori_bmi = $pdo->query("SELECT * FROM kategori_bmi ORDER BY id ASC")->fetchAll();

// MODE FORM: Tambah atau Edit
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$item_edit = null;
$kategori_terpilih = [];

if ($edit_id > 0) {
    $st_edit = $pdo->prepare("SELECT * FROM menu_diet WHERE id = ?");
    $st_edit->execute([$edit_id]);
    $item_edit = $st_edit->fetch();

    if ($item_edit) {
        $st_kat = $pdo->prepare("SELECT kategori_id FROM menu_kategori WHERE menu_id = ?");
        $st_kat->execute([$edit_id]);
        $kategori_terpilih = $st_kat->fetchAll(PDO::FETCH_COLUMN);
    }
}

// PROSES POST (CREATE, UPDATE, DELETE)
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
            $st_del = $pdo->prepare("DELETE FROM menu_diet WHERE id = ?");
            $st_del->execute([$id_hapus]);
            $_SESSION['pesan_admin'] = 'Menu makanan berhasil dihapus dari basis data.';
            $_SESSION['pesan_admin_tipe'] = 'sukses';
        }
        header('Location: ' . base_url('admin/makanan.php'));
        exit;
    }

    if ($aksi === 'simpan') {
        $id_menu     = (int)($_POST['id'] ?? 0);
        $nama_menu   = trim($_POST['nama_menu'] ?? '');
        $waktu_makan = $_POST['waktu_makan'] ?? 'pagi';
        $kalori      = (int)($_POST['kalori'] ?? 0);
        $berat_gram  = !empty($_POST['berat_gram']) ? (int)$_POST['berat_gram'] : null;
        $takaran_saji = trim($_POST['takaran_saji'] ?? '');
        $harga       = (int)($_POST['harga'] ?? 0);
        $vegetarian  = isset($_POST['vegetarian']) ? 1 : 0;
        $deskripsi   = trim($_POST['deskripsi'] ?? '');
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

                    // Hapus asosiasi lama dan perbarui
                    $del_kat = $pdo->prepare("DELETE FROM menu_kategori WHERE menu_id = ?");
                    $del_kat->execute([$id_menu]);
                    $target_id = $id_menu;
                    $_SESSION['pesan_admin'] = 'Menu makanan berhasil diperbarui.';
                } else {
                    // INSERT
                    $st_in = $pdo->prepare("
                        INSERT INTO menu_diet (nama_menu, waktu_makan, kalori, berat_gram, takaran_saji, harga, vegetarian, deskripsi)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $st_in->execute([$nama_menu, $waktu_makan, $kalori, $berat_gram, $takaran_saji, $harga, $vegetarian, $deskripsi]);
                    $target_id = (int)$pdo->lastInsertId();
                    $_SESSION['pesan_admin'] = 'Menu makanan baru berhasil ditambahkan.';
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

// Pencarian dan Filter
$kata_kunci = trim($_GET['cari'] ?? '');
$filter_waktu = $_GET['waktu'] ?? '';

// Hitung Total Data untuk Pagination
$sql_count = "SELECT COUNT(*) FROM menu_diet md WHERE 1=1";
$params_count = [];

if ($kata_kunci !== '') {
    $sql_count .= " AND md.nama_menu LIKE ?";
    $params_count[] = '%' . $kata_kunci . '%';
}
if ($filter_waktu !== '' && in_array($filter_waktu, ['pagi', 'siang', 'malam', 'camilan'], true)) {
    $sql_count .= " AND md.waktu_makan = ?";
    $params_count[] = $filter_waktu;
}

$st_count = $pdo->prepare($sql_count);
$st_count->execute($params_count);
$total_data = (int)$st_count->fetchColumn();

// Konfigurasi Pagination (6 item per halaman)
$per_halaman = 6;
$total_halaman = max(1, (int)ceil($total_data / $per_halaman));
$halaman_saat_ini = max(1, min((int)($_GET['halaman'] ?? 1), $total_halaman));
$offset = ($halaman_saat_ini - 1) * $per_halaman;

$sql = "
    SELECT md.*, u.nama AS pembuat_nama, u.gelar AS pembuat_gelar,
           GROUP_CONCAT(kb.nama SEPARATOR ', ') AS cocok_kategori,
           GROUP_CONCAT(kb.kode SEPARATOR ',') AS kode_kategori
    FROM menu_diet md
    LEFT JOIN users u ON u.id = md.pakar_id
    LEFT JOIN menu_kategori mk ON mk.menu_id = md.id
    LEFT JOIN kategori_bmi kb ON kb.id = mk.kategori_id
    WHERE 1=1
";
$params = [];

if ($kata_kunci !== '') {
    $sql .= " AND md.nama_menu LIKE ?";
    $params[] = '%' . $kata_kunci . '%';
}
if ($filter_waktu !== '' && in_array($filter_waktu, ['pagi', 'siang', 'malam', 'camilan'], true)) {
    $sql .= " AND md.waktu_makan = ?";
    $params[] = $filter_waktu;
}

$sql .= " GROUP BY md.id ORDER BY md.id DESC LIMIT " . (int)$per_halaman . " OFFSET " . (int)$offset;
$st_daftar = $pdo->prepare($sql);
$st_daftar->execute($params);
$daftar_menu = $st_daftar->fetchAll();

$judul = 'Kelola Makanan - Admin';
include BASE_DIR . 'includes/header.php';
?>

<?php include __DIR__ . '/nav.php'; ?>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= h($pesan) ?></div>
<?php endif; ?>

<div class="dua-kolom" style="align-items: start;">
  <!-- FORM INPUT / EDIT MENU MAKANAN -->
  <div class="kotak-form" style="margin: 0; max-width: none;">
    <h3 style="margin-top: 0; font-size: 1.25rem;">
      <?= $item_edit ? 'Ubah Menu Makanan' : 'Tambah Menu Baru' ?>
    </h3>
    <p class="teks-redup" style="font-size: 0.88rem; margin-bottom: 20px;">
      <?= $item_edit ? 'Edit rincian data makanan #' . $item_edit['id'] : 'Tambahkan item baru ke dalam katalog sistem rekomendasi diet.' ?>
    </p>

    <form method="post" action="<?= base_url('admin/makanan.php') ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= (int)($item_edit['id'] ?? 0) ?>">

      <label for="nama_menu">Nama Menu / Masakan</label>
      <input type="text" id="nama_menu" name="nama_menu" value="<?= h($item_edit['nama_menu'] ?? '') ?>" required placeholder="Contoh: Dada Ayam Panggang & Brokoli">

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
        <div>
          <label for="waktu_makan">Waktu Makan</label>
          <select id="waktu_makan" name="waktu_makan" required>
            <?php 
            $waktu_opsi = ['pagi' => 'Sarapan (Pagi)', 'siang' => 'Makan Siang', 'malam' => 'Makan Malam', 'camilan' => 'Camilan / Snack'];
            $val_waktu = $item_edit['waktu_makan'] ?? 'pagi';
            foreach ($waktu_opsi as $k => $v): 
            ?>
              <option value="<?= $k ?>" <?= $val_waktu === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="kalori">Kalori (kkal)</label>
          <input type="number" id="kalori" name="kalori" value="<?= h($item_edit['kalori'] ?? '') ?>" required min="10" max="3000" placeholder="Contoh: 350">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 14px;">
        <div>
          <label for="berat_gram">Total Berat (Gram)</label>
          <input type="number" id="berat_gram" name="berat_gram" value="<?= h($item_edit['berat_gram'] ?? '') ?>" min="0" max="3000" placeholder="Contoh: 250">
        </div>
        <div>
          <label for="takaran_saji">Takaran Porsi Standar</label>
          <input type="text" id="takaran_saji" name="takaran_saji" value="<?= h($item_edit['takaran_saji'] ?? '') ?>" placeholder="Contoh: 1 piring (185g) + 1 butir telur (65g)">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
        <div>
          <label for="harga">Perkiraan Harga (Rp)</label>
          <input type="number" id="harga" name="harga" value="<?= h($item_edit['harga'] ?? '0') ?>" step="500" min="0" max="500000">
        </div>
        <div>
          <label for="vegetarian">Opsi Vegetarian</label>
          <div style="display: flex; align-items: center; gap: 10px; height: 48px;">
            <input type="checkbox" id="vegetarian" name="vegetarian" value="1" <?= (!empty($item_edit['vegetarian'])) ? 'checked' : '' ?> style="width: auto; margin: 0;">
            <label for="vegetarian" style="margin: 0; font-weight: 500; cursor: pointer;">Menu Ramah Vegetarian</label>
          </div>
        </div>
      </div>

      <label for="deskripsi">Deskripsi / Komposisi Singkat</label>
      <textarea id="deskripsi" name="deskripsi" rows="3" placeholder="Informasi gizi, saran pengolahan, atau porsi..."><?= h($item_edit['deskripsi'] ?? '') ?></textarea>

      <label style="margin-top: 14px; margin-bottom: 8px;">Kesesuaian Kategori BMI (Target Rekomendasi)</label>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 20px; background: var(--latar); padding: 12px; border-radius: var(--radius-kecil);">
        <?php foreach ($semua_kategori_bmi as $kat): 
          $is_checked = in_array((int)$kat['id'], array_map('intval', $kategori_terpilih), true);
        ?>
          <label style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; cursor: pointer; margin: 0;">
            <input type="checkbox" name="kategori_bmi[]" value="<?= $kat['id'] ?>" <?= $is_checked ? 'checked' : '' ?> style="width: auto; margin: 0;">
            <span class="badge kategori-<?= h($kat['kode']) ?>" style="font-size: 0.76rem;"><?= h($kat['nama']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div style="display: flex; gap: 10px;">
        <button type="submit" class="tombol penuh"><?= $item_edit ? 'Simpan Perubahan' : 'Tambahkan Makanan' ?></button>
        <?php if ($item_edit): ?>
          <a href="<?= base_url('admin/makanan.php') ?>" class="tombol sekunder" style="text-align: center;">Batal</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- DAFTAR & PENCARIAN MAKANAN -->
  <div>
    <div class="kartu" style="padding: 20px; margin-bottom: 16px;">
      <form method="get" action="<?= base_url('admin/makanan.php') ?>" style="display: flex; gap: 10px; flex-wrap: wrap;">
        <input type="text" name="cari" value="<?= h($kata_kunci) ?>" placeholder="Cari nama makanan..." style="flex: 2; min-width: 160px; margin: 0;">
        <select name="waktu" style="flex: 1; min-width: 130px; margin: 0;">
          <option value="">Semua Waktu</option>
          <option value="pagi" <?= $filter_waktu === 'pagi' ? 'selected' : '' ?>>Pagi</option>
          <option value="siang" <?= $filter_waktu === 'siang' ? 'selected' : '' ?>>Siang</option>
          <option value="malam" <?= $filter_waktu === 'malam' ? 'selected' : '' ?>>Malam</option>
          <option value="camilan" <?= $filter_waktu === 'camilan' ? 'selected' : '' ?>>Camilan</option>
        </select>
        <button type="submit" class="tombol kecil" style="margin: 0; padding: 0 20px;">Cari</button>
        <?php if ($kata_kunci !== '' || $filter_waktu !== ''): ?>
          <a href="<?= base_url('admin/makanan.php') ?>" class="tombol sekunder kecil" style="margin: 0;">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
      <span class="teks-redup" style="font-size: 0.9rem;">
        Menampilkan <b><?= count($daftar_menu) ?></b> dari total <b><?= $total_data ?></b> menu makanan
        <?= $total_halaman > 1 ? "(Halaman {$halaman_saat_ini} dari {$total_halaman})" : '' ?>
      </span>
    </div>

    <?php if (empty($daftar_menu)): ?>
      <div class="kosong">Tidak ada makanan yang cocok dengan kriteria pencarian.</div>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 12px;">
        <?php foreach ($daftar_menu as $m): ?>
          <div class="kartu" style="padding: 18px 20px; display: flex; flex-direction: column; gap: 10px;">
            <div style="display: flex; justify-content: space-between; align-items: start; gap: 12px;">
              <div>
                <h4 style="margin: 0 0 4px; font-size: 1.05rem;"><?= h($m['nama_menu']) ?></h4>
                <?php if (!empty($m['pembuat_nama'])): ?>
                  <div style="margin-bottom: 6px;">
                    <span class="badge" style="background: #E0E7FF; color: #3730A3; font-size: 0.72rem; font-weight: 700; border: 1px solid #C7D2FE;">
                      Disusun oleh: <?= h($m['pembuat_gelar'] ? $m['pembuat_gelar'] . ' ' : '') ?><?= h($m['pembuat_nama']) ?>
                    </span>
                  </div>
                <?php endif; ?>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                  <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>">
                    <?= ucfirst(h($m['waktu_makan'])) ?>
                  </span>
                  <span style="font-size: 0.85rem; font-weight: 700; color: var(--hijau);">
                    <?= number_format($m['kalori'], 0, ',', '.') ?> kkal
                  </span>
                  <?php if ($m['harga'] > 0): ?>
                    <span style="font-size: 0.85rem; color: var(--abu);">
                      Rp <?= number_format($m['harga'], 0, ',', '.') ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($m['vegetarian']): ?>
                    <span class="badge" style="background: #E8F5E9; color: #2E7D32; font-size: 0.75rem; border: 1px solid #C8E6C9;">Vegetarian</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($m['takaran_saji'])): ?>
                  <div style="font-size: 0.8rem; color: var(--abu); margin-top: 4px;">
                    <strong>Porsi:</strong> <?= h($m['takaran_saji']) ?>
                  </div>
                <?php endif; ?>
              </div>

              <!-- AKSI EDIT & HAPUS -->
              <div style="display: flex; gap: 8px; align-items: center;">
                <a href="<?= base_url('admin/makanan.php?edit=' . $m['id']) ?>" class="tombol sekunder kecil" style="padding: 6px 12px; font-size: 0.8rem;">
                  Edit
                </a>
                <form method="post" action="<?= base_url('admin/makanan.php') ?>" onsubmit="return confirm('Apakah Anda yakin ingin menghapus menu \'<?= h(addslashes($m['nama_menu'])) ?>\'?');" style="margin:0;">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <button type="submit" class="tombol kecil" style="background: #C44536; padding: 6px 12px; font-size: 0.8rem;">
                    Hapus
                  </button>
                </form>
              </div>
            </div>

            <?php if ($m['deskripsi']): ?>
              <p class="teks-redup" style="margin: 0; font-size: 0.86rem;"><?= h($m['deskripsi']) ?></p>
            <?php endif; ?>

            <?php if ($m['cocok_kategori']): ?>
              <div class="cocok-kategori-bar">
                <span class="cocok-kategori-label">Cocok untuk:</span>
                <div class="cocok-kategori-list">
                  <?php 
                  $kodes = explode(',', $m['kode_kategori']);
                  $namas = explode(', ', $m['cocok_kategori']);
                  foreach ($namas as $idx => $nama_kat): 
                    $kode_kat = $kodes[$idx] ?? 'normal';
                  ?>
                    <span class="badge kategori-<?= h(trim($kode_kat)) ?>"><?= h(trim($nama_kat)) ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- KONTROL PAGINATION -->
      <?php if ($total_halaman > 1): ?>
        <?php
        // Helper pembentuk URL dengan parameter filter
        $buat_url_hal = function($hal) use ($kata_kunci, $filter_waktu) {
            $qs = ['halaman' => $hal];
            if ($kata_kunci !== '') $qs['cari'] = $kata_kunci;
            if ($filter_waktu !== '') $qs['waktu'] = $filter_waktu;
            return base_url('admin/makanan.php?' . http_build_query($qs));
        };

        // Window pagination maksimal 5 angka di sekitar halaman saat ini
        $max_angka = 5;
        $mulai_hal = max(1, $halaman_saat_ini - 2);
        $akhir_hal = min($total_halaman, $mulai_hal + $max_angka - 1);

        // Sesuaikan jika mendekati batas akhir
        if ($akhir_hal - $mulai_hal + 1 < $max_angka) {
            $mulai_hal = max(1, $akhir_hal - $max_angka + 1);
        }
        ?>
        <div class="pagination-wrapper">
          <!-- Tombol Sebelumnya -->
          <?php if ($halaman_saat_ini > 1): ?>
            <a href="<?= h($buat_url_hal($halaman_saat_ini - 1)) ?>" class="pagination-nav-btn">
              ← Sebelumnya
            </a>
          <?php else: ?>
            <span class="pagination-nav-btn disabled">
              ← Sebelumnya
            </span>
          <?php endif; ?>

          <div style="display: flex; gap: 6px; align-items: center;">
            <!-- Halaman Pertama & Ellipsis jika melompat jauh -->
            <?php if ($mulai_hal > 1): ?>
              <a href="<?= h($buat_url_hal(1)) ?>" class="pagination-number-btn">1</a>
              <?php if ($mulai_hal > 2): ?>
                <span class="pagination-ellipsis">&hellip;</span>
              <?php endif; ?>
            <?php endif; ?>

            <!-- Rentang Angka Pagination (Maksimal 5) -->
            <?php for ($i = $mulai_hal; $i <= $akhir_hal; $i++): ?>
              <?php if ($i === $halaman_saat_ini): ?>
                <span class="pagination-number-btn aktif"><?= $i ?></span>
              <?php else: ?>
                <a href="<?= h($buat_url_hal($i)) ?>" class="pagination-number-btn"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <!-- Halaman Terakhir & Ellipsis jika masih ada sisa -->
            <?php if ($akhir_hal < $total_halaman): ?>
              <?php if ($akhir_hal < $total_halaman - 1): ?>
                <span class="pagination-ellipsis">&hellip;</span>
              <?php endif; ?>
              <a href="<?= h($buat_url_hal($total_halaman)) ?>" class="pagination-number-btn"><?= $total_halaman ?></a>
            <?php endif; ?>
          </div>

          <!-- Tombol Selanjutnya -->
          <?php if ($halaman_saat_ini < $total_halaman): ?>
            <a href="<?= h($buat_url_hal($halaman_saat_ini + 1)) ?>" class="pagination-nav-btn">
              Selanjutnya →
            </a>
          <?php else: ?>
            <span class="pagination-nav-btn disabled">
              Selanjutnya →
            </span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php include BASE_DIR . 'includes/footer.php'; ?>
