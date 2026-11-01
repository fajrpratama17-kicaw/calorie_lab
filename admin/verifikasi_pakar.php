<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_admin();

$csrf = csrf_token('csrf_token_verif_pakar');
$pesan = $_SESSION['pesan_verif_pakar'] ?? '';
$pesan_tipe = $_SESSION['pesan_verif_pakar_tipe'] ?? 'sukses';
unset($_SESSION['pesan_verif_pakar'], $_SESSION['pesan_verif_pakar_tipe']);

// ── Pastikan kolom pakar tersedia (auto-migrate) ─────────────────────────
$kolom_migrate = [
    'gelar'        => "ALTER TABLE users ADD COLUMN gelar VARCHAR(50) NULL AFTER nama",
    'nomor_str'    => "ALTER TABLE users ADD COLUMN nomor_str VARCHAR(20) NULL AFTER role",
    'spesialisasi' => "ALTER TABLE users ADD COLUMN spesialisasi VARCHAR(100) NULL AFTER nomor_str",
    'institusi'    => "ALTER TABLE users ADD COLUMN institusi VARCHAR(150) NULL AFTER spesialisasi",
    'status_akun'  => "ALTER TABLE users ADD COLUMN status_akun ENUM('aktif','pending','ditolak') NOT NULL DEFAULT 'aktif' AFTER institusi",
    'catatan_admin'=> "ALTER TABLE users ADD COLUMN catatan_admin TEXT NULL AFTER status_akun",
];
foreach ($kolom_migrate as $kol => $sql) {
    try {
        $res = $pdo->query("SHOW COLUMNS FROM users LIKE '$kol'");
        if (!$res->fetch()) { $pdo->exec($sql); }
    } catch (Exception $e) {}
}

// ── Proses POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $aksi  = $_POST['aksi'] ?? '';
    $uid   = (int)($_POST['target_id'] ?? 0);

    if (!csrf_valid($token, 'csrf_token_verif_pakar') || $uid <= 0) {
        $_SESSION['pesan_verif_pakar'] = 'Permintaan tidak valid atau sesi kedaluwarsa.';
        $_SESSION['pesan_verif_pakar_tipe'] = 'error';
        header('Location: ' . base_url('admin/verifikasi_pakar.php'));
        exit;
    }

    // Ambil data pakar target
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'pakar'");
    $st->execute([$uid]);
    $pakar = $st->fetch();

    if (!$pakar) {
        $_SESSION['pesan_verif_pakar'] = 'Data pakar tidak ditemukan.';
        $_SESSION['pesan_verif_pakar_tipe'] = 'error';
        header('Location: ' . base_url('admin/verifikasi_pakar.php'));
        exit;
    }

    if ($aksi === 'setujui') {
        $up = $pdo->prepare("UPDATE users SET status_akun = 'aktif', catatan_admin = NULL WHERE id = ?");
        $up->execute([$uid]);
        $_SESSION['pesan_verif_pakar'] = 'Akun pakar <strong>' . h($pakar['nama']) . '</strong> berhasil disetujui dan sekarang aktif.';
        $_SESSION['pesan_verif_pakar_tipe'] = 'sukses';

    } elseif ($aksi === 'tolak') {
        $catatan = trim($_POST['catatan_admin'] ?? '');
        $up = $pdo->prepare("UPDATE users SET status_akun = 'ditolak', catatan_admin = ? WHERE id = ?");
        $up->execute([$catatan ?: null, $uid]);
        $_SESSION['pesan_verif_pakar'] = 'Akun pakar <strong>' . h($pakar['nama']) . '</strong> telah ditolak.';
        $_SESSION['pesan_verif_pakar_tipe'] = 'sukses';

    } elseif ($aksi === 'aktifkan_ulang') {
        $up = $pdo->prepare("UPDATE users SET status_akun = 'aktif', catatan_admin = NULL WHERE id = ?");
        $up->execute([$uid]);
        $_SESSION['pesan_verif_pakar'] = 'Akun pakar <strong>' . h($pakar['nama']) . '</strong> berhasil diaktifkan ulang.';
        $_SESSION['pesan_verif_pakar_tipe'] = 'sukses';

    } elseif ($aksi === 'pending_ulang') {
        $up = $pdo->prepare("UPDATE users SET status_akun = 'pending' WHERE id = ?");
        $up->execute([$uid]);
        $_SESSION['pesan_verif_pakar'] = 'Status akun pakar <strong>' . h($pakar['nama']) . '</strong> dikembalikan ke Menunggu.';
        $_SESSION['pesan_verif_pakar_tipe'] = 'sukses';
    }

    header('Location: ' . base_url('admin/verifikasi_pakar.php') . '?tab=' . ($_POST['tab_asal'] ?? 'pending'));
    exit;
}

// ── Filter Tab ───────────────────────────────────────────────────────────
$tab_aktif = $_GET['tab'] ?? 'pending';
if (!in_array($tab_aktif, ['pending', 'aktif', 'ditolak'], true)) $tab_aktif = 'pending';

// ── Query data pakar per tab ─────────────────────────────────────────────
$daftar = [];
try {
    $st2 = $pdo->prepare("SELECT * FROM users WHERE role = 'pakar' AND status_akun = ? ORDER BY created_at DESC");
    $st2->execute([$tab_aktif]);
    $daftar = $st2->fetchAll();
} catch (Exception $e) {
    // Kolom belum ada
}

// Hitung badge tiap tab
$badge = ['pending' => 0, 'aktif' => 0, 'ditolak' => 0];
try {
    $st3 = $pdo->query("SELECT status_akun, COUNT(*) AS jml FROM users WHERE role = 'pakar' GROUP BY status_akun");
    while ($r = $st3->fetch()) {
        if (isset($badge[$r['status_akun']])) $badge[$r['status_akun']] = (int)$r['jml'];
    }
} catch (Exception $e) {}

$judul = 'Verifikasi Pakar &mdash; Admin';
include BASE_DIR . 'includes/header.php';
?>

<?php include __DIR__ . '/nav.php'; ?>

<style>
  .konten-utama { padding-bottom: 60px; }

  /* Alert */
  .admin-alert { padding: 12px 16px; border-radius: 12px; font-size: 0.87rem; font-weight: 500; margin-bottom: 22px; line-height: 1.5; }
  .admin-alert.sukses { background: #E8F2EC; color: #264A38; border: 1px solid #B8D9C5; }
  .admin-alert.error  { background: #FEF0EF; color: #B45148; border: 1px solid #FACACB; }

  /* Tab */
  .tab-bar { display: flex; gap: 8px; margin-bottom: 22px; flex-wrap: wrap; }
  .tab-bar a {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 20px; border-radius: 10px; font-size: 0.84rem; font-weight: 600;
    color: var(--teks-redup); background: var(--permukaan); border: 1.5px solid var(--garis);
    text-decoration: none; transition: all 0.18s;
  }
  .tab-bar a:hover { color: var(--hijau); border-color: var(--hijau); }
  .tab-bar a.aktif { background: var(--hijau); color: #fff; border-color: var(--hijau); }
  .tab-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 20px; padding: 0 5px;
    border-radius: 999px; font-size: 0.72rem; font-weight: 700;
  }
  .tab-bar a.aktif .tab-badge { background: rgba(255,255,255,0.25); color: #fff; }
  .tab-bar a:not(.aktif) .tab-badge { background: var(--mint); color: var(--hijau); }
  .tab-bar a[data-tab="pending"]:not(.aktif) .tab-badge.ada { background: #FEF0EF; color: #B45148; }

  /* Kartu Pakar */
  .grid-pakar { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; }
  .kartu-pakar {
    background: var(--permukaan); border: 1px solid var(--garis); border-radius: 16px;
    padding: 22px; position: relative; transition: box-shadow 0.18s;
  }
  .kartu-pakar:hover { box-shadow: 0 8px 28px rgba(34,56,45,0.08); }

  /* Status badge */
  .status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em;
    padding: 4px 12px; border-radius: 999px; margin-bottom: 14px; text-transform: uppercase;
  }
  .status-badge.pending  { background: #FEF9EC; color: #A86800; border: 1px solid #F5DFA0; }
  .status-badge.aktif    { background: #E8F2EC; color: #264A38; border: 1px solid #B8D9C5; }
  .status-badge.ditolak  { background: #FEF0EF; color: #B45148; border: 1px solid #FACACB; }

  /* Avatar & nama */
  .pakar-header { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 14px; }
  .pakar-avatar {
    width: 48px; height: 48px; border-radius: 14px; background: var(--mint);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; font-weight: 800; color: var(--hijau); flex-shrink: 0;
  }
  .pakar-nama-wrap { flex: 1; min-width: 0; }
  .pakar-nama { font-size: 1rem; font-weight: 700; color: var(--gelap); margin: 0 0 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .pakar-gelar { font-size: 0.8rem; color: var(--teks-redup); font-weight: 500; }

  /* Detail info */
  .pakar-detail { display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }
  .pakar-detail-row { display: flex; gap: 8px; font-size: 0.82rem; }
  .pakar-detail-row .label { color: var(--teks-redup); font-weight: 600; min-width: 90px; flex-shrink: 0; }
  .pakar-detail-row .value { color: var(--teks); font-weight: 500; word-break: break-all; }

  /* STR chip */
  .str-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: #F0F4FF; border: 1px solid #C8D5FF; border-radius: 8px;
    font-size: 0.78rem; font-weight: 700; color: #3A5ADB; padding: 4px 10px;
    font-family: monospace; letter-spacing: 0.05em;
  }

  /* Catatan ditolak */
  .catatan-panel {
    background: #FEF0EF; border: 1px solid #FACACB; border-radius: 10px;
    padding: 10px 12px; margin-bottom: 12px; font-size: 0.81rem; color: #B45148; line-height: 1.5;
  }

  /* Tombol aksi */
  .aksi-group { display: flex; gap: 8px; flex-wrap: wrap; border-top: 1px solid var(--garis); padding-top: 14px; margin-top: 4px; }
  .aksi-group form { margin: 0; }
  .btn-setujui {
    background: var(--hijau); color: #fff; border: none; border-radius: 9px;
    padding: 8px 18px; font-size: 0.82rem; font-weight: 700; font-family: var(--font-utama);
    cursor: pointer; transition: all 0.18s; box-shadow: 0 3px 10px rgba(58,105,83,0.18);
  }
  .btn-setujui:hover { background: var(--hijau-hover); transform: translateY(-1px); }
  .btn-tolak {
    background: #fff; color: #B45148; border: 1.5px solid #FACACB; border-radius: 9px;
    padding: 8px 18px; font-size: 0.82rem; font-weight: 700; font-family: var(--font-utama);
    cursor: pointer; transition: all 0.18s;
  }
  .btn-tolak:hover { background: #FEF0EF; border-color: #B45148; }
  .btn-sekunder {
    background: var(--permukaan); color: var(--teks-redup); border: 1.5px solid var(--garis); border-radius: 9px;
    padding: 8px 16px; font-size: 0.82rem; font-weight: 600; font-family: var(--font-utama);
    cursor: pointer; transition: all 0.18s;
  }
  .btn-sekunder:hover { border-color: var(--hijau); color: var(--hijau); }

  /* Modal Tolak */
  .modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(34,56,45,0.45); backdrop-filter: blur(3px);
    z-index: 1000; align-items: center; justify-content: center;
  }
  .modal-overlay.buka { display: flex; }
  .modal-box {
    background: #fff; border-radius: 20px; padding: 32px; max-width: 440px; width: 90%;
    box-shadow: 0 24px 64px rgba(34,56,45,0.18);
  }
  .modal-box h3 { margin: 0 0 8px; font-size: 1.1rem; font-weight: 800; color: var(--gelap); }
  .modal-box p { margin: 0 0 18px; font-size: 0.87rem; color: var(--teks-redup); line-height: 1.6; }
  .modal-box textarea {
    width: 100%; border: 1.5px solid var(--garis); border-radius: 12px; padding: 11px 14px;
    font-size: 0.87rem; font-family: var(--font-utama); resize: vertical; min-height: 90px;
    background: #FAFAF9; margin-bottom: 18px; outline: none;
  }
  .modal-box textarea:focus { border-color: var(--hijau); box-shadow: 0 0 0 3px rgba(58,105,83,0.12); }
  .modal-aksi { display: flex; gap: 10px; justify-content: flex-end; }

  /* Kosong state */
  .kosong-state {
    text-align: center; padding: 60px 20px; background: var(--permukaan);
    border: 1.5px dashed var(--garis); border-radius: 16px;
  }
  .kosong-state svg { color: var(--garis); margin-bottom: 14px; }
  .kosong-state p { color: var(--teks-redup); font-size: 0.88rem; margin: 0; }

  .tgl-daftar { font-size: 0.75rem; color: var(--teks-redup); margin-top: 8px; }
</style>

<div class="konten-utama">

  <!-- Judul Halaman -->
  <div style="margin-bottom: 22px;">
    <h1 style="font-size:1.65rem;font-weight:800;color:var(--gelap);margin:0 0 5px;">Verifikasi Akun Pakar</h1>
    <p class="teks-redup" style="margin:0;font-size:0.9rem;">Tinjau dan kelola pendaftaran tenaga medis &amp; ahli gizi.</p>
  </div>

  <?php if ($pesan): ?>
    <div class="admin-alert <?= h($pesan_tipe) ?>"><?= $pesan ?></div>
  <?php endif; ?>

  <!-- Tab Bar -->
  <div class="tab-bar">
    <a href="?tab=pending" data-tab="pending" class="<?= $tab_aktif === 'pending' ? 'aktif' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
      Menunggu Persetujuan
      <span class="tab-badge <?= $badge['pending'] > 0 ? 'ada' : '' ?>"><?= $badge['pending'] ?></span>
    </a>
    <a href="?tab=aktif" data-tab="aktif" class="<?= $tab_aktif === 'aktif' ? 'aktif' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Pakar Aktif
      <span class="tab-badge"><?= $badge['aktif'] ?></span>
    </a>
    <a href="?tab=ditolak" data-tab="ditolak" class="<?= $tab_aktif === 'ditolak' ? 'aktif' : '' ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      Ditolak
      <span class="tab-badge"><?= $badge['ditolak'] ?></span>
    </a>
  </div>

  <!-- Grid Kartu Pakar -->
  <?php if (empty($daftar)): ?>
    <div class="kosong-state">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <p>
        <?php
        $teks_kosong = ['pending' => 'Tidak ada pendaftaran pakar yang menunggu persetujuan.', 'aktif' => 'Belum ada pakar yang aktif.', 'ditolak' => 'Tidak ada akun pakar yang ditolak.'];
        echo h($teks_kosong[$tab_aktif] ?? 'Tidak ada data.');
        ?>
      </p>
    </div>
  <?php else: ?>
    <div class="grid-pakar">
      <?php foreach ($daftar as $p):
        $inisial = mb_strtoupper(mb_substr($p['nama'] ?? 'P', 0, 1));
        $status  = $p['status_akun'] ?? 'pending';
        $tgl_daftar = !empty($p['created_at']) ? date('d M Y, H:i', strtotime($p['created_at'])) : '-';
      ?>
      <div class="kartu-pakar">
        <span class="status-badge <?= h($status) ?>">
          <?php
          $label_status = ['pending' => '⏳ Menunggu', 'aktif' => '✓ Aktif', 'ditolak' => '✗ Ditolak'];
          echo $label_status[$status] ?? $status;
          ?>
        </span>

        <div class="pakar-header">
          <div class="pakar-avatar"><?= $inisial ?></div>
          <div class="pakar-nama-wrap">
            <div class="pakar-nama"><?= h($p['nama']) ?></div>
            <div class="pakar-gelar"><?= h($p['gelar'] ?? '-') ?> &bull; <?= h($p['spesialisasi'] ?? '-') ?></div>
          </div>
        </div>

        <div class="pakar-detail">
          <div class="pakar-detail-row">
            <span class="label">Email</span>
            <span class="value"><?= h($p['email']) ?></span>
          </div>
          <div class="pakar-detail-row">
            <span class="label">Institusi</span>
            <span class="value"><?= h($p['institusi'] ?? '-') ?></span>
          </div>
          <div class="pakar-detail-row">
            <span class="label">Nomor STR</span>
            <span class="value">
              <span class="str-chip">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <?= h($p['nomor_str'] ?? '-') ?>
              </span>
            </span>
          </div>
        </div>

        <?php if ($status === 'ditolak' && !empty($p['catatan_admin'])): ?>
          <div class="catatan-panel">
            <strong>Alasan penolakan:</strong> <?= h($p['catatan_admin']) ?>
          </div>
        <?php endif; ?>

        <div class="tgl-daftar">Terdaftar: <?= $tgl_daftar ?></div>

        <!-- Tombol Aksi -->
        <div class="aksi-group">
          <?php if ($status === 'pending'): ?>
            <!-- Setujui -->
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="aksi" value="setujui">
              <input type="hidden" name="target_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="tab_asal" value="pending">
              <button type="submit" class="btn-setujui"
                      onclick="return confirm('Setujui akun pakar <?= h(addslashes($p['nama'])) ?>?')">
                ✓ Setujui
              </button>
            </form>
            <!-- Tolak — buka modal -->
            <button type="button" class="btn-tolak" onclick="bukaModalTolak(<?= (int)$p['id'] ?>, '<?= h(addslashes($p['nama'])) ?>')">
              ✗ Tolak
            </button>

          <?php elseif ($status === 'aktif'): ?>
            <!-- Kembalikan ke pending -->
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="aksi" value="pending_ulang">
              <input type="hidden" name="target_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="tab_asal" value="aktif">
              <button type="submit" class="btn-sekunder"
                      onclick="return confirm('Kembalikan ke status Menunggu?')">
                Tinjau Ulang
              </button>
            </form>
            <!-- Tolak langsung dari aktif -->
            <button type="button" class="btn-tolak" onclick="bukaModalTolak(<?= (int)$p['id'] ?>, '<?= h(addslashes($p['nama'])) ?>', 'aktif')">
              Cabut Akses
            </button>

          <?php elseif ($status === 'ditolak'): ?>
            <!-- Aktifkan ulang -->
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="aksi" value="aktifkan_ulang">
              <input type="hidden" name="target_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="tab_asal" value="ditolak">
              <button type="submit" class="btn-setujui"
                      onclick="return confirm('Aktifkan kembali akun pakar <?= h(addslashes($p['nama'])) ?>?')">
                Aktifkan Ulang
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<!-- ── Modal Tolak ── -->
<div class="modal-overlay" id="modal-tolak">
  <div class="modal-box">
    <h3>Tolak Pendaftaran Pakar</h3>
    <p id="modal-tolak-desc">Masukkan alasan penolakan (opsional). Alasan ini akan ditampilkan kepada pakar saat mereka mencoba masuk.</p>
    <form method="post" id="form-tolak">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="aksi" value="tolak">
      <input type="hidden" name="target_id" id="modal-tolak-id" value="">
      <input type="hidden" name="tab_asal" id="modal-tolak-tab" value="pending">
      <textarea name="catatan_admin" id="modal-catatan" placeholder="Contoh: Nomor STR tidak dapat diverifikasi. Silakan hubungi admin untuk klarifikasi."></textarea>
      <div class="modal-aksi">
        <button type="button" class="btn-sekunder" onclick="tutupModal()">Batal</button>
        <button type="submit" class="btn-tolak">Konfirmasi Tolak</button>
      </div>
    </form>
  </div>
</div>

<script>
function bukaModalTolak(id, nama, tabAsal) {
  document.getElementById('modal-tolak-id').value = id;
  document.getElementById('modal-tolak-tab').value = tabAsal || 'pending';
  document.getElementById('modal-tolak-desc').textContent =
    'Masukkan alasan penolakan untuk akun "' + nama + '" (opsional).';
  document.getElementById('modal-catatan').value = '';
  document.getElementById('modal-tolak').classList.add('buka');
}
function tutupModal() {
  document.getElementById('modal-tolak').classList.remove('buka');
}
document.getElementById('modal-tolak').addEventListener('click', function(e) {
  if (e.target === this) tutupModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') tutupModal();
});
</script>

<?php include BASE_DIR . 'includes/footer.php'; ?>
