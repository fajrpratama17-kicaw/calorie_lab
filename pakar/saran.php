<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_pakar();

$pakar_id = (int)$_SESSION['user_id'];
$csrf = csrf_token('csrf_pakar_saran');

$pesan = $_SESSION['pesan_pakar_saran'] ?? '';
$pesan_tipe = $_SESSION['pesan_pakar_saran_tipe'] ?? 'sukses';
unset($_SESSION['pesan_pakar_saran'], $_SESSION['pesan_pakar_saran_tipe']);

// ── Proses Form ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $aksi  = $_POST['aksi'] ?? '';

    if (!csrf_valid($token, 'csrf_pakar_saran')) {
        $_SESSION['pesan_pakar_saran'] = 'Sesi formulir tidak valid.';
        $_SESSION['pesan_pakar_saran_tipe'] = 'error';
        header('Location: ' . base_url('pakar/saran.php'));
        exit;
    }

    if ($aksi === 'tambah' || $aksi === 'edit') {
        $judul_saran   = trim($_POST['judul_saran'] ?? '');
        $isi_saran     = trim($_POST['isi_saran'] ?? '');
        $target_kat    = $_POST['target_kategori'] ?? 'semua';
        $saran_id      = (int)($_POST['saran_id'] ?? 0);

        $valid_kat = ['semua','kurus','normal','gemuk','obesitas'];
        if (!in_array($target_kat, $valid_kat, true)) $target_kat = 'semua';

        if ($judul_saran === '' || $isi_saran === '') {
            $_SESSION['pesan_pakar_saran'] = 'Judul dan isi saran tidak boleh kosong.';
            $_SESSION['pesan_pakar_saran_tipe'] = 'error';
        } elseif (mb_strlen($judul_saran) > 200) {
            $_SESSION['pesan_pakar_saran'] = 'Judul saran terlalu panjang (maks 200 karakter).';
            $_SESSION['pesan_pakar_saran_tipe'] = 'error';
        } else {
            if ($aksi === 'edit' && $saran_id > 0) {
                $upd = $pdo->prepare("UPDATE saran_pakar SET judul=?, isi_saran=?, target_kategori=? WHERE id=? AND pakar_id=?");
                $upd->execute([$judul_saran, $isi_saran, $target_kat, $saran_id, $pakar_id]);
                $_SESSION['pesan_pakar_saran'] = 'Saran berhasil diperbarui.';
            } else {
                $ins = $pdo->prepare("INSERT INTO saran_pakar (pakar_id, judul, isi_saran, target_kategori) VALUES (?, ?, ?, ?)");
                $ins->execute([$pakar_id, $judul_saran, $isi_saran, $target_kat]);
                $_SESSION['pesan_pakar_saran'] = 'Saran berhasil ditambahkan dan akan terlihat oleh pengguna yang sesuai.';
            }
            $_SESSION['pesan_pakar_saran_tipe'] = 'sukses';
        }
        header('Location: ' . base_url('pakar/saran.php'));
        exit;
    }

    if ($aksi === 'hapus') {
        $saran_id = (int)($_POST['saran_id'] ?? 0);
        if ($saran_id > 0) {
            $del = $pdo->prepare("DELETE FROM saran_pakar WHERE id = ? AND pakar_id = ?");
            $del->execute([$saran_id, $pakar_id]);
            $_SESSION['pesan_pakar_saran'] = 'Saran berhasil dihapus.';
            $_SESSION['pesan_pakar_saran_tipe'] = 'sukses';
        }
        header('Location: ' . base_url('pakar/saran.php'));
        exit;
    }
}

// ── Ambil Daftar Saran ────────────────────────────────────────────────────
$filter_kat = $_GET['kat'] ?? '';
$valid_kat = ['semua','kurus','normal','gemuk','obesitas'];

$params_q = [$pakar_id];
$where_extra = '';
if ($filter_kat !== '' && in_array($filter_kat, $valid_kat, true)) {
    $where_extra = ' AND target_kategori = ?';
    $params_q[] = $filter_kat;
}

$st = $pdo->prepare("
    SELECT * FROM saran_pakar
    WHERE pakar_id = ? $where_extra
    ORDER BY created_at DESC
");
$st->execute($params_q);
$daftar_saran = $st->fetchAll();

// Edit mode — ambil data saran yang akan diedit
$edit_data = null;
$edit_id = (int)($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $stEdit = $pdo->prepare("SELECT * FROM saran_pakar WHERE id = ? AND pakar_id = ?");
    $stEdit->execute([$edit_id, $pakar_id]);
    $edit_data = $stEdit->fetch();
}

$label_kat = [
    'semua'    => 'Semua Pengguna',
    'kurus'    => 'Kurus',
    'normal'   => 'Normal',
    'gemuk'    => 'Gemuk',
    'obesitas' => 'Obesitas',
];

$judul = 'Saran untuk Pengguna - Calorie Lab';
include BASE_DIR . 'includes/header.php';
include __DIR__ . '/nav.php';
?>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : '' ?>" style="margin-bottom: 16px;"><?= h($pesan) ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: flex-start;">

  <!-- Kolom Kiri: Daftar Saran -->
  <div>
    <!-- Filter Kategori -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
      <a href="<?= base_url('pakar/saran.php') ?>" class="tombol <?= $filter_kat === '' ? '' : 'sekunder' ?> kecil" style="font-size:0.82rem; padding:6px 14px;">Semua Kategori</a>
      <?php foreach (['kurus','normal','gemuk','obesitas'] as $k): ?>
        <a href="<?= base_url('pakar/saran.php?kat=' . $k) ?>"
           class="tombol <?= $filter_kat === $k ? '' : 'sekunder' ?> kecil"
           style="font-size:0.82rem; padding:6px 14px;">
          <?= h($label_kat[$k]) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($daftar_saran)): ?>
      <div class="kartu" style="padding: 32px; text-align: center;">
        <p class="teks-redup" style="font-size: 0.9rem; margin: 0;">
          Belum ada saran edukasi<?= $filter_kat ? ' untuk target ' . h($label_kat[$filter_kat] ?? $filter_kat) : '' ?>.
          Gunakan formulir di sebelah kanan untuk menulis saran baru.
        </p>
      </div>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 14px;">
        <?php foreach ($daftar_saran as $s): ?>
          <div class="kartu" style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 10px;">
              <div>
                <h3 style="margin: 0 0 6px; font-size: 1.05rem; font-weight: 700; color: var(--gelap);"><?= h($s['judul']) ?></h3>
                <?php if ($s['target_kategori'] === 'semua'): ?>
                  <span class="badge" style="background: var(--mint); color: #264A38; font-weight: 700; font-size: 0.72rem; padding: 2px 10px;">
                    Semua Pengguna
                  </span>
                <?php else: ?>
                  <span class="badge kategori-<?= h($s['target_kategori']) ?>" style="font-size: 0.72rem; padding: 2px 10px;">
                    <?= h($label_kat[$s['target_kategori']] ?? $s['target_kategori']) ?>
                  </span>
                <?php endif; ?>
              </div>
              <span style="font-size: 0.78rem; color: var(--teks-redup); white-space: nowrap; margin-top: 2px;">
                <?= date('d M Y, H:i', strtotime($s['created_at'])) ?>
              </span>
            </div>
            <p style="margin: 0 0 16px; font-size: 0.9rem; color: var(--gelap); line-height: 1.65; white-space: pre-wrap;"><?= h($s['isi_saran']) ?></p>
            <div style="display: flex; gap: 8px;">
              <a href="<?= base_url('pakar/saran.php?edit=' . $s['id']) ?>"
                 class="tombol sekunder kecil"
                 style="font-size: 0.78rem; padding: 6px 14px;">Edit Saran</a>
              <form method="post" action="<?= base_url('pakar/saran.php') ?>" style="margin:0;" onsubmit="return confirm('Hapus saran edukasi ini?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="saran_id" value="<?= $s['id'] ?>">
                <button type="submit" class="tombol kecil" style="font-size: 0.78rem; padding: 6px 14px; background: #C44536;">Hapus</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Kolom Kanan: Form Tambah / Edit -->
  <div>
    <div class="kartu" style="padding: 22px; position: sticky; top: 80px;">
      <h3 style="margin: 0 0 16px; font-size: 1.1rem; font-weight: 700; color: var(--gelap);">
        <?= $edit_data ? 'Edit Saran Edukasi' : 'Tulis Saran Baru' ?>
      </h3>

      <form method="post" action="<?= base_url('pakar/saran.php') ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="aksi" value="<?= $edit_data ? 'edit' : 'tambah' ?>">
        <?php if ($edit_data): ?>
          <input type="hidden" name="saran_id" value="<?= $edit_data['id'] ?>">
        <?php endif; ?>

        <label style="font-size: 0.85rem; margin-top: 0;">Judul Saran</label>
        <input type="text" name="judul_saran" maxlength="200" required
               value="<?= h($edit_data['judul'] ?? '') ?>"
               placeholder="Contoh: Tips Pola Makan Sehat untuk Menjaga Kalori">

        <label style="font-size: 0.85rem; margin-top: 14px;">Target Kategori BMI</label>
        <select name="target_kategori" style="width:100%; padding:10px 12px; font-size:0.88rem; border:1.5px solid #DFD9CE; border-radius:var(--radius-kecil); font-family:inherit; background:var(--permukaan-subtle);">
          <?php foreach ($label_kat as $k => $lab): ?>
            <option value="<?= $k ?>" <?= ($edit_data['target_kategori'] ?? 'semua') === $k ? 'selected' : '' ?>>
              <?= h($lab) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label style="font-size: 0.85rem; margin-top: 14px;">Isi Saran Edukasi</label>
        <textarea name="isi_saran" rows="7" required
                  placeholder="Tuliskan saran, panduan konsumsi makanan, atau edukasi gizi untuk pengguna..."
                  style="width:100%; font-family:inherit; font-size:0.88rem; resize:vertical;"><?= h($edit_data['isi_saran'] ?? '') ?></textarea>

        <div style="display: flex; gap: 8px; margin-top: 16px;">
          <button type="submit" class="tombol penuh" style="flex:1;">
            <?= $edit_data ? 'Perbarui Saran' : 'Simpan & Publikasikan' ?>
          </button>
          <?php if ($edit_data): ?>
            <a href="<?= base_url('pakar/saran.php') ?>" class="tombol sekunder kecil" style="padding: 10px 14px;">Batal</a>
          <?php endif; ?>
        </div>
      </form>

      <?php if (!$edit_data): ?>
        <div style="margin-top: 16px; padding: 12px 14px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil); font-size: 0.82rem; color: var(--teks-redup); line-height: 1.55;">
          <strong style="color: var(--gelap);">Informasi Target:</strong> Saran dengan target <em>"Semua Pengguna"</em> akan tampil di dashboard seluruh pengguna. Saran dengan target BMI khusus hanya muncul di dashboard pengguna yang masuk ke kategori tersebut.
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include BASE_DIR . 'includes/footer.php'; ?>
