<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_pakar();

$pakar_id = (int)$_SESSION['user_id'];
$csrf = csrf_token('csrf_pakar_saran');

// ── Auto-migrate kolom user_id di saran_pakar jika belum ada ─────────────
try {
    $res = $pdo->query("SHOW COLUMNS FROM saran_pakar LIKE 'user_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE saran_pakar ADD COLUMN user_id INT NULL DEFAULT NULL AFTER pakar_id");
    }
} catch (Exception $e) {}

$pesan = $_SESSION['pesan_pakar_saran'] ?? '';
$pesan_tipe = $_SESSION['pesan_pakar_saran_tipe'] ?? 'sukses';
unset($_SESSION['pesan_pakar_saran'], $_SESSION['pesan_pakar_saran_tipe']);

// Tab aktif: 'monitoring' (default) atau 'riwayat'
$tab = $_GET['tab'] ?? 'monitoring';
if (!in_array($tab, ['monitoring', 'riwayat'], true)) {
    $tab = 'monitoring';
}

// Ambil daftar seluruh pengguna biasa aktif untuk modal autocomplete
$daftar_user_pilihan = [];
try {
    $st_u = $pdo->query("SELECT id, nama, email FROM users WHERE role = 'user' AND status_akun = 'aktif' ORDER BY nama ASC");
    $daftar_user_pilihan = $st_u->fetchAll();
} catch (Exception $e) {}

// ── PROSES FORM: SIMPAN / EDIT / HAPUS SARAN ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $aksi  = $_POST['aksi'] ?? '';
    $tab_asal = $_POST['tab_asal'] ?? 'monitoring';

    if (!csrf_valid($token, 'csrf_pakar_saran')) {
        $_SESSION['pesan_pakar_saran'] = 'Sesi formulir tidak valid atau telah kedaluwarsa.';
        $_SESSION['pesan_pakar_saran_tipe'] = 'error';
        header('Location: ' . base_url('pakar/saran.php?tab=' . urlencode($tab_asal)));
        exit;
    }

    if ($aksi === 'simpan') {
        $saran_id      = (int)($_POST['saran_id'] ?? 0);
        $judul_saran   = trim($_POST['judul_saran'] ?? '');
        $isi_saran     = trim($_POST['isi_saran'] ?? '');
        $tipe_target   = $_POST['tipe_target'] ?? 'bmi';

        $target_user_id = null;
        $target_kat     = 'semua';

        if ($tipe_target === 'user') {
            $uid_post = (int)($_POST['target_user_id'] ?? 0);
            if ($uid_post > 0) {
                $cek_u = $pdo->prepare("SELECT id, nama FROM users WHERE id = ? AND role = 'user'");
                $cek_u->execute([$uid_post]);
                $u_found = $cek_u->fetch();
                if ($u_found) {
                    $target_user_id = $uid_post;
                    $target_kat     = 'semua';
                } else {
                    $_SESSION['pesan_pakar_saran'] = 'Pengguna yang dipilih tidak valid.';
                    $_SESSION['pesan_pakar_saran_tipe'] = 'error';
                    header('Location: ' . base_url('pakar/saran.php?tab=' . urlencode($tab_asal)));
                    exit;
                }
            } else {
                $_SESSION['pesan_pakar_saran'] = 'Silakan pilih pengguna sasaran untuk saran spesifik.';
                $_SESSION['pesan_pakar_saran_tipe'] = 'error';
                header('Location: ' . base_url('pakar/saran.php?tab=' . urlencode($tab_asal)));
                exit;
            }
        } else {
            $target_kat = $_POST['target_kategori'] ?? 'semua';
            $valid_kat  = ['semua', 'kurus', 'normal', 'gemuk', 'obesitas'];
            if (!in_array($target_kat, $valid_kat, true)) $target_kat = 'semua';
            $target_user_id = null;
        }

        if ($judul_saran === '' || $isi_saran === '') {
            $_SESSION['pesan_pakar_saran'] = 'Judul dan isi saran tidak boleh kosong.';
            $_SESSION['pesan_pakar_saran_tipe'] = 'error';
        } elseif (mb_strlen($judul_saran) > 200) {
            $_SESSION['pesan_pakar_saran'] = 'Judul saran terlalu panjang (maksimum 200 karakter).';
            $_SESSION['pesan_pakar_saran_tipe'] = 'error';
        } else {
            try {
                if ($saran_id > 0) {
                    $upd = $pdo->prepare("UPDATE saran_pakar SET user_id = ?, judul = ?, isi_saran = ?, target_kategori = ? WHERE id = ? AND pakar_id = ?");
                    $upd->execute([$target_user_id, $judul_saran, $isi_saran, $target_kat, $saran_id, $pakar_id]);
                    $_SESSION['pesan_pakar_saran'] = 'Saran edukasi <strong>' . h($judul_saran) . '</strong> berhasil diperbarui.';
                } else {
                    $ins = $pdo->prepare("INSERT INTO saran_pakar (pakar_id, user_id, judul, isi_saran, target_kategori) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$pakar_id, $target_user_id, $judul_saran, $isi_saran, $target_kat]);
                    $_SESSION['pesan_pakar_saran'] = 'Saran edukasi <strong>' . h($judul_saran) . '</strong> berhasil diterbitkan.';
                }
                $_SESSION['pesan_pakar_saran_tipe'] = 'sukses';
            } catch (Exception $e) {
                $_SESSION['pesan_pakar_saran'] = 'Gagal menyimpan saran edukasi: ' . $e->getMessage();
                $_SESSION['pesan_pakar_saran_tipe'] = 'error';
            }
        }
        header('Location: ' . base_url('pakar/saran.php?tab=' . urlencode($tab_asal)));
        exit;
    }

    if ($aksi === 'hapus') {
        $saran_id = (int)($_POST['saran_id'] ?? 0);
        if ($saran_id > 0) {
            try {
                $del = $pdo->prepare("DELETE FROM saran_pakar WHERE id = ? AND pakar_id = ?");
                $del->execute([$saran_id, $pakar_id]);
                $_SESSION['pesan_pakar_saran'] = 'Saran edukasi berhasil dihapus.';
                $_SESSION['pesan_pakar_saran_tipe'] = 'sukses';
            } catch (Exception $e) {
                $_SESSION['pesan_pakar_saran'] = 'Gagal menghapus saran edukasi.';
                $_SESSION['pesan_pakar_saran_tipe'] = 'error';
            }
        }
        header('Location: ' . base_url('pakar/saran.php?tab=riwayat'));
        exit;
    }
}

// ── TOTAL RINGKASAN UNTUK BADGE TAB ──────────────────────────────────────
$total_monitoring_all = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND status_akun = 'aktif'")->fetchColumn();
$total_saran_all      = (int)$pdo->query("SELECT COUNT(*) FROM saran_pakar WHERE pakar_id = $pakar_id")->fetchColumn();

// ── LOGIKA TAB 1: MONITORING PENGGUNA ────────────────────────────────────
if ($tab === 'monitoring') {
    $cari_user   = trim($_GET['cari'] ?? '');
    $filter_bmi  = $_GET['filter_bmi'] ?? '';

    $kondisi_u = ["u.role = 'user'", "u.status_akun = 'aktif'"];
    $params_u  = [];

    if ($cari_user !== '') {
        $kondisi_u[] = '(u.nama LIKE ? OR u.email LIKE ?)';
        $params_u[]  = '%' . $cari_user . '%';
        $params_u[]  = '%' . $cari_user . '%';
    }

    if ($filter_bmi !== '' && in_array($filter_bmi, ['kurus', 'normal', 'gemuk', 'obesitas'], true)) {
        $kondisi_u[] = "EXISTS (
            SELECT 1 FROM riwayat_bmi rb2
            JOIN kategori_bmi kb2 ON kb2.id = rb2.kategori_id
            WHERE rb2.user_id = u.id AND kb2.kode = ?
            AND rb2.id = (SELECT MAX(id) FROM riwayat_bmi WHERE user_id = u.id)
        )";
        $params_u[] = $filter_bmi;
    } elseif ($filter_bmi === 'belum_cek') {
        $kondisi_u[] = "NOT EXISTS (SELECT 1 FROM riwayat_bmi rb2 WHERE rb2.user_id = u.id)";
    }

    // Count
    $sql_count_u = "SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $kondisi_u);
    $st_count_u  = $pdo->prepare($sql_count_u);
    $st_count_u->execute($params_u);
    $total_data_u = (int)$st_count_u->fetchColumn();

    $per_halaman_u = 10;
    $total_halaman_u = max(1, (int)ceil($total_data_u / $per_halaman_u));
    $hal_u = max(1, min((int)($_GET['halaman'] ?? 1), $total_halaman_u));
    $offset_u = ($hal_u - 1) * $per_halaman_u;
    $mulai_u = $total_data_u > 0 ? $offset_u + 1 : 0;
    $akhir_u = min($offset_u + $per_halaman_u, $total_data_u);

    $buat_url_hal_u = function($h) use ($cari_user, $filter_bmi) {
        $qs = ['tab' => 'monitoring', 'halaman' => $h];
        if ($cari_user !== '') $qs['cari'] = $cari_user;
        if ($filter_bmi !== '') $qs['filter_bmi'] = $filter_bmi;
        return base_url('pakar/saran.php?' . http_build_query($qs));
    };

    // Ambil data pasien/pengguna lengkap dengan status gizi klinis
    $sql_user_data = "
        SELECT u.id, u.nama, u.email, u.created_at,
               (SELECT COUNT(*) FROM catatan_makan WHERE user_id = u.id) AS total_catat,
               (SELECT rd.target_kalori FROM rencana_diet rd WHERE rd.user_id = u.id AND rd.status = 'aktif' ORDER BY rd.id DESC LIMIT 1) AS target_kalori,
               (SELECT rd.tujuan FROM rencana_diet rd WHERE rd.user_id = u.id AND rd.status = 'aktif' ORDER BY rd.id DESC LIMIT 1) AS tujuan_rencana,
               (SELECT rb.bmi FROM riwayat_bmi rb WHERE rb.user_id = u.id ORDER BY rb.id DESC LIMIT 1) AS bmi_terakhir,
               (SELECT kb.nama FROM riwayat_bmi rb JOIN kategori_bmi kb ON kb.id = rb.kategori_id WHERE rb.user_id = u.id ORDER BY rb.id DESC LIMIT 1) AS bmi_kat_nama,
               (SELECT kb.kode FROM riwayat_bmi rb JOIN kategori_bmi kb ON kb.id = rb.kategori_id WHERE rb.user_id = u.id ORDER BY rb.id DESC LIMIT 1) AS bmi_kat_kode,
               (SELECT COUNT(*) FROM saran_pakar sp WHERE sp.user_id = u.id AND sp.pakar_id = ?) AS jumlah_saran_personal
        FROM users u
        WHERE " . implode(' AND ', $kondisi_u) . "
        ORDER BY u.nama ASC
        LIMIT " . (int)$per_halaman_u . " OFFSET " . (int)$offset_u . "
    ";
    $query_params_u = array_merge([$pakar_id], $params_u);
    $st_u_data = $pdo->prepare($sql_user_data);
    $st_u_data->execute($query_params_u);
    $daftar_monitoring = $st_u_data->fetchAll();
}

// ── LOGIKA TAB 2: RIWAYAT SARAN TERBIT ───────────────────────────────────
if ($tab === 'riwayat') {
    $cari_saran    = trim($_GET['cari'] ?? '');
    $filter_target = $_GET['target'] ?? '';

    $kondisi_s = ['sp.pakar_id = ?'];
    $params_s  = [$pakar_id];

    if ($cari_saran !== '') {
        $kondisi_s[] = '(sp.judul LIKE ? OR sp.isi_saran LIKE ? OR u.nama LIKE ? OR u.email LIKE ?)';
        $wildcard    = '%' . $cari_saran . '%';
        $params_s[]  = $wildcard;
        $params_s[]  = $wildcard;
        $params_s[]  = $wildcard;
        $params_s[]  = $wildcard;
    }

    if ($filter_target === 'spesifik') {
        $kondisi_s[] = 'sp.user_id IS NOT NULL';
    } elseif ($filter_target === 'semua_bmi') {
        $kondisi_s[] = "sp.user_id IS NULL AND sp.target_kategori = 'semua'";
    } elseif (in_array($filter_target, ['kurus', 'normal', 'gemuk', 'obesitas'], true)) {
        $kondisi_s[] = 'sp.user_id IS NULL AND sp.target_kategori = ?';
        $params_s[]  = $filter_target;
    }

    $sql_count_s = "
        SELECT COUNT(*) 
        FROM saran_pakar sp
        LEFT JOIN users u ON u.id = sp.user_id
        WHERE " . implode(' AND ', $kondisi_s);
    $st_count_s = $pdo->prepare($sql_count_s);
    $st_count_s->execute($params_s);
    $total_data_s = (int)$st_count_s->fetchColumn();

    $per_halaman_s   = 10;
    $total_halaman_s = max(1, (int)ceil($total_data_s / $per_halaman_s));
    $hal_s           = max(1, min((int)($_GET['halaman'] ?? 1), $total_halaman_s));
    $offset_s        = ($hal_s - 1) * $per_halaman_s;
    $mulai_s         = $total_data_s > 0 ? $offset_s + 1 : 0;
    $akhir_s         = min($offset_s + $per_halaman_s, $total_data_s);

    $buat_url_hal_s = function($h) use ($cari_saran, $filter_target) {
        $qs = ['tab' => 'riwayat', 'halaman' => $h];
        if ($cari_saran !== '') $qs['cari'] = $cari_saran;
        if ($filter_target !== '') $qs['target'] = $filter_target;
        return base_url('pakar/saran.php?' . http_build_query($qs));
    };

    $sql_saran_data = "
        SELECT sp.*, u.nama AS nama_target_user, u.email AS email_target_user
        FROM saran_pakar sp
        LEFT JOIN users u ON u.id = sp.user_id
        WHERE " . implode(' AND ', $kondisi_s) . "
        ORDER BY sp.created_at DESC
        LIMIT " . (int)$per_halaman_s . " OFFSET " . (int)$offset_s . "
    ";
    $st_s_data = $pdo->prepare($sql_saran_data);
    $st_s_data->execute($params_s);
    $daftar_saran = $st_s_data->fetchAll();
}

$label_kat = [
    'semua'    => 'Semua Pengguna',
    'kurus'    => 'Kurus',
    'normal'   => 'Normal',
    'gemuk'    => 'Gemuk',
    'obesitas' => 'Obesitas',
];

$label_tujuan = [
    'turun' => 'Turun Berat Badan',
    'naik'  => 'Naik Berat Badan',
    'jaga'  => 'Jaga Berat Badan',
];

$judul = 'Monitoring & Saran Edukasi Gizi - Calorie Lab';
include BASE_DIR . 'includes/header.php';
include __DIR__ . '/nav.php';
?>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= $pesan ?></div>
<?php endif; ?>

<!-- ══════════════ TAB SWITCHER PORTAL PAKAR ══════════════ -->
<div style="display: flex; gap: 10px; margin-bottom: 22px; border-bottom: 2px solid var(--garis); padding-bottom: 14px; flex-wrap: wrap; align-items: center;">
  <a href="<?= base_url('pakar/saran.php?tab=monitoring') ?>" 
     class="tombol <?= $tab === 'monitoring' ? '' : 'sekunder' ?>" 
     style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; border-radius: var(--radius-pil); padding: 8px 18px; font-size: 0.9rem;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Pantau Pasien &amp; Pengguna (<?= number_format($total_monitoring_all, 0, ',', '.') ?>)
  </a>
  <a href="<?= base_url('pakar/saran.php?tab=riwayat') ?>" 
     class="tombol <?= $tab === 'riwayat' ? '' : 'sekunder' ?>" 
     style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; border-radius: var(--radius-pil); padding: 8px 18px; font-size: 0.9rem;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    Riwayat Saran Terbit (<?= number_format($total_saran_all, 0, ',', '.') ?>)
  </a>
</div>

<?php if ($tab === 'monitoring'): ?>
  <!-- ══════════════════════════════════════════════════════════
       TAB 1: PANTAU PASIEN & STATUS GIZI
       ══════════════════════════════════════════════════════════ -->

  <!-- FILTER PENGGUNA -->
  <div class="kartu" style="padding: 20px 24px; margin-bottom: 20px; background: #FFFFFF !important;">
    <form method="get" action="<?= base_url('pakar/saran.php') ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin: 0;">
      <input type="hidden" name="tab" value="monitoring">
      
      <div style="position: relative; flex: 2; min-width: 200px;">
        <input type="text" name="cari" value="<?= h($cari_user) ?>" placeholder="Cari nama atau email pengguna..." style="width: 100%; margin: 0; padding-left: 36px; height: 42px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--teks-redup); pointer-events: none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>

      <select name="filter_bmi" style="flex: 1.2; min-width: 180px; margin: 0; height: 42px;">
        <option value="">Semua Kategori BMI</option>
        <option value="kurus" <?= $filter_bmi === 'kurus' ? 'selected' : '' ?>>Kurus (&lt; 18.5)</option>
        <option value="normal" <?= $filter_bmi === 'normal' ? 'selected' : '' ?>>Normal (18.5 - 24.9)</option>
        <option value="gemuk" <?= $filter_bmi === 'gemuk' ? 'selected' : '' ?>>Gemuk (25.0 - 29.9)</option>
        <option value="obesitas" <?= $filter_bmi === 'obesitas' ? 'selected' : '' ?>>Obesitas (&ge; 30.0)</option>
        <option value="belum_cek" <?= $filter_bmi === 'belum_cek' ? 'selected' : '' ?>>Belum Cek BMI</option>
      </select>

      <button type="submit" class="tombol kecil" style="margin: 0; padding: 0 20px; height: 42px; display: inline-flex; align-items: center; gap: 6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Filter
      </button>

      <?php if ($cari_user !== '' || $filter_bmi !== ''): ?>
        <a href="<?= base_url('pakar/saran.php?tab=monitoring') ?>" class="tombol sekunder kecil" style="margin: 0; height: 42px; display: inline-flex; align-items: center; gap: 4px; padding: 0 14px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Reset
        </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- COUNTER -->
  <div style="margin-bottom: 16px;">
    <span class="teks-redup" style="font-size: 0.92rem;">
      Menampilkan <b><?= $mulai_u ?> - <?= $akhir_u ?></b> dari total <b><?= number_format($total_data_u, 0, ',', '.') ?></b> pengguna diet terdaftar
    </span>
  </div>

  <!-- TABEL MONITORING STATUS GIZI -->
  <div class="kartu" style="padding: 0; overflow-x: hidden; background: #FFFFFF !important; border: 1px solid var(--garis); border-radius: var(--radius-kartu); box-shadow: var(--bayangan-kartu);">
    <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem; table-layout: auto;">
      <thead>
        <tr style="background: #F8FAF8; border-bottom: 2px solid var(--garis);">
          <th style="padding: 14px 18px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 28%;">Profil Pasien / Pengguna</th>
          <th style="padding: 14px 12px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 18%;">Status BMI Terkini</th>
          <th style="padding: 14px 12px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 20%;">Rencana Diet &amp; Target</th>
          <th style="padding: 14px 12px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 16%;">Aktivitas &amp; Saran</th>
          <th style="padding: 14px 18px; text-align: right; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 18%;">Aksi Klinis</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($daftar_monitoring)): ?>
          <tr>
            <td colspan="5" style="padding: 40px 20px; text-align: center; color: var(--teks-redup);">
              Tidak ada data pengguna yang cocok dengan kriteria pencarian.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($daftar_monitoring as $u): ?>
            <tr style="border-bottom: 1px solid var(--garis); transition: background 0.15s ease;" onmouseover="this.style.background='var(--latar)'" onmouseout="this.style.background=''">
              
              <!-- NAMA & EMAIL -->
              <td style="padding: 14px 18px;">
                <strong style="color: var(--gelap); font-size: 0.95rem; display: block; margin-bottom: 2px;"><?= h($u['nama']) ?></strong>
                <span style="font-size: 0.78rem; color: var(--teks-redup);"><?= h($u['email']) ?></span>
              </td>

              <!-- BMI TERKINI -->
              <td style="padding: 14px 12px; text-align: center;">
                <?php if ($u['bmi_terakhir']): ?>
                  <div style="font-weight: 750; font-size: 0.95rem; color: var(--gelap); margin-bottom: 3px;">
                    BMI <?= number_format((float)$u['bmi_terakhir'], 1) ?>
                  </div>
                  <span class="badge kategori-<?= h($u['bmi_kat_kode'] ?? 'normal') ?>" style="font-size: 0.72rem; font-weight: 700; padding: 2px 9px;">
                    <?= h($u['bmi_kat_nama'] ?? 'Normal') ?>
                  </span>
                <?php else: ?>
                  <span style="font-size: 0.8rem; color: var(--teks-redup); font-style: italic;">Belum Cek BMI</span>
                <?php endif; ?>
              </td>

              <!-- RENCANA DIET -->
              <td style="padding: 14px 12px; text-align: center;">
                <?php if (!empty($u['target_kalori'])): ?>
                  <div style="font-weight: 750; color: #166534; font-size: 0.92rem;">
                    <?= number_format((int)$u['target_kalori'], 0, ',', '.') ?> kkal/hari
                  </div>
                  <div style="font-size: 0.74rem; color: var(--teks-redup); margin-top: 2px;">
                    <?= h($label_tujuan[$u['tujuan_rencana']] ?? 'Program Aktif') ?>
                  </div>
                <?php else: ?>
                  <span style="font-size: 0.8rem; color: var(--teks-redup);">Belum Ada Rencana</span>
                <?php endif; ?>
              </td>

              <!-- AKTIVITAS & STATUS SARAN -->
              <td style="padding: 14px 12px; text-align: center;">
                <div style="font-size: 0.82rem; color: var(--teks); margin-bottom: 4px;">
                  <b><?= (int)$u['total_catat'] ?></b> Catatan Makan
                </div>
                <?php if ((int)$u['jumlah_saran_personal'] > 0): ?>
                  <span class="badge" style="background: #EDE9FE; color: #5B21B6; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border: 1px solid #DDD6FE;">
                    <?= (int)$u['jumlah_saran_personal'] ?> Saran Terkirim
                  </span>
                <?php else: ?>
                  <span style="font-size: 0.74rem; color: var(--teks-redup);">Belum ada saran</span>
                <?php endif; ?>
              </td>

              <!-- AKSI BERI SARAN -->
              <td style="padding: 14px 18px; text-align: right; white-space: nowrap;">
                <button type="button" class="tombol kecil" style="padding: 7px 14px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 5px; border-radius: var(--radius-pil); font-weight: 700;"
                        onclick='bukaModalBeriSaranDariPasien(<?= (int)$u['id'] ?>, <?= json_encode($u['nama']) ?>, <?= json_encode($u['email']) ?>, <?= json_encode($u['bmi_terakhir'] ? number_format((float)$u['bmi_terakhir'], 1) : '-') ?>, <?= json_encode($u['bmi_kat_nama'] ?? 'Belum Cek') ?>)'>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Beri Saran
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- FOOTER PAGINATION TAB MONITORING -->
    <?php if ($total_halaman_u > 1): ?>
      <?php
      $max_angka = 5;
      $mulai_hal = max(1, $hal_u - 2);
      $akhir_hal = min($total_halaman_u, $mulai_hal + $max_angka - 1);
      if ($akhir_hal - $mulai_hal + 1 < $max_angka) {
          $mulai_hal = max(1, $akhir_hal - $max_angka + 1);
      }
      ?>
      <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 18px 20px; border-top: 1px solid var(--garis); background: #F8FAF8; gap: 8px; border-bottom-left-radius: var(--radius-kartu); border-bottom-right-radius: var(--radius-kartu);">
        <div style="display: flex; gap: 6px; align-items: center; justify-content: center; flex-wrap: wrap;">
          <?php if ($hal_u > 1): ?>
            <a href="<?= h($buat_url_hal_u($hal_u - 1)) ?>" class="pagination-nav-btn" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">← Sebelumnya</a>
          <?php else: ?>
            <span class="pagination-nav-btn disabled" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">← Sebelumnya</span>
          <?php endif; ?>

          <?php if ($mulai_hal > 1): ?>
            <a href="<?= h($buat_url_hal_u(1)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;">1</a>
            <?php if ($mulai_hal > 2): ?>
              <span class="pagination-ellipsis" style="width: 18px; font-size: 0.82rem;">&hellip;</span>
            <?php endif; ?>
          <?php endif; ?>

          <?php for ($i = $mulai_hal; $i <= $akhir_hal; $i++): ?>
            <?php if ($i === $hal_u): ?>
              <span class="pagination-number-btn aktif" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $i ?></span>
            <?php else: ?>
              <a href="<?= h($buat_url_hal_u($i)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($akhir_hal < $total_halaman_u): ?>
            <?php if ($akhir_hal < $total_halaman_u - 1): ?>
              <span class="pagination-ellipsis" style="width: 18px; font-size: 0.82rem;">&hellip;</span>
            <?php endif; ?>
            <a href="<?= h($buat_url_hal_u($total_halaman_u)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $total_halaman_u ?></a>
          <?php endif; ?>

          <?php if ($hal_u < $total_halaman_u): ?>
            <a href="<?= h($buat_url_hal_u($hal_u + 1)) ?>" class="pagination-nav-btn" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">Selanjutnya →</a>
          <?php else: ?>
            <span class="pagination-nav-btn disabled" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">Selanjutnya →</span>
          <?php endif; ?>
        </div>

        <span class="teks-redup" style="font-size: 0.8rem;">
          Halaman <b><?= $hal_u ?></b> dari <b><?= $total_halaman_u ?></b> &middot; Menampilkan <b><?= $mulai_u ?> - <?= $akhir_u ?></b> dari total <b><?= number_format($total_data_u, 0, ',', '.') ?></b> pengguna
        </span>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- ══════════════════════════════════════════════════════════
       TAB 2: RIWAYAT SARAN EDUKASI TERBIT
       ══════════════════════════════════════════════════════════ -->

  <!-- FILTER RIWAYAT SARAN -->
  <div class="kartu" style="padding: 20px 24px; margin-bottom: 20px; background: #FFFFFF !important;">
    <form method="get" action="<?= base_url('pakar/saran.php') ?>" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin: 0;">
      <input type="hidden" name="tab" value="riwayat">

      <div style="position: relative; flex: 2; min-width: 200px;">
        <input type="text" name="cari" value="<?= h($cari_saran) ?>" placeholder="Cari judul saran, kata kunci, atau nama pengguna..." style="width: 100%; margin: 0; padding-left: 36px; height: 42px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--teks-redup); pointer-events: none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>

      <select name="target" style="flex: 1.2; min-width: 180px; margin: 0; height: 42px;">
        <option value="">Semua Target Sasaran</option>
        <option value="spesifik" <?= $filter_target === 'spesifik' ? 'selected' : '' ?>>Khusus Pengguna Tertentu</option>
        <option value="semua_bmi" <?= $filter_target === 'semua_bmi' ? 'selected' : '' ?>>Grup: Semua Pengguna</option>
        <option value="kurus" <?= $filter_target === 'kurus' ? 'selected' : '' ?>>Grup: BMI Kurus</option>
        <option value="normal" <?= $filter_target === 'normal' ? 'selected' : '' ?>>Grup: BMI Normal</option>
        <option value="gemuk" <?= $filter_target === 'gemuk' ? 'selected' : '' ?>>Grup: BMI Gemuk</option>
        <option value="obesitas" <?= $filter_target === 'obesitas' ? 'selected' : '' ?>>Grup: BMI Obesitas</option>
      </select>

      <button type="submit" class="tombol kecil" style="margin: 0; padding: 0 20px; height: 42px; display: inline-flex; align-items: center; gap: 6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Filter
      </button>

      <?php if ($cari_saran !== '' || $filter_target !== ''): ?>
        <a href="<?= base_url('pakar/saran.php?tab=riwayat') ?>" class="tombol sekunder kecil" style="margin: 0; height: 42px; display: inline-flex; align-items: center; gap: 4px; padding: 0 14px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Reset
        </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ACTION HEADER TAB RIWAYAT -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
    <div>
      <span class="teks-redup" style="font-size: 0.92rem;">
        Menampilkan <b><?= $mulai_s ?> - <?= $akhir_s ?></b> dari total <b><?= number_format($total_data_s, 0, ',', '.') ?></b> saran edukasi terbit
      </span>
    </div>

    <button type="button" class="tombol" onclick="bukaModalTambahSaranUmum()" style="display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; font-weight: 700;">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Tulis Saran Baru
    </button>
  </div>

  <!-- TABEL RIWAYAT SARAN -->
  <div class="kartu" style="padding: 0; overflow-x: hidden; background: #FFFFFF !important; border: 1px solid var(--garis); border-radius: var(--radius-kartu); box-shadow: var(--bayangan-kartu);">
    <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem; table-layout: auto;">
      <thead>
        <tr style="background: #F8FAF8; border-bottom: 2px solid var(--garis);">
          <th style="padding: 14px 18px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 48%;">Judul &amp; Ringkasan Edukasi</th>
          <th style="padding: 14px 12px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 22%;">Target Sasaran</th>
          <th style="padding: 14px 12px; text-align: center; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 14%;">Tanggal Dibuat</th>
          <th style="padding: 14px 18px; text-align: right; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gelap); width: 16%;">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($daftar_saran)): ?>
          <tr>
            <td colspan="4" style="padding: 40px 20px; text-align: center; color: var(--teks-redup);">
              Tidak ada saran edukasi yang cocok dengan kriteria pencarian.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($daftar_saran as $s): ?>
            <tr style="border-bottom: 1px solid var(--garis); transition: background 0.15s ease;" onmouseover="this.style.background='var(--latar)'" onmouseout="this.style.background=''">
              
              <!-- JUDUL & ISI -->
              <td style="padding: 14px 18px;">
                <strong style="color: var(--gelap); font-size: 0.95rem; display: block; margin-bottom: 3px;"><?= h($s['judul']) ?></strong>
                <div style="font-size: 0.82rem; color: #64748B; line-height: 1.5; max-width: 520px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;" title="<?= h($s['isi_saran']) ?>">
                  <?= h($s['isi_saran']) ?>
                </div>
              </td>

              <!-- TARGET SASARAN -->
              <td style="padding: 14px 12px; text-align: left;">
                <?php if (!empty($s['user_id'])): ?>
                  <span class="badge" style="background: #EDE9FE; color: #5B21B6; font-size: 0.74rem; font-weight: 700; padding: 3px 10px; border: 1px solid #DDD6FE; display: inline-flex; align-items: center; gap: 4px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Khusus Pengguna
                  </span>
                  <div style="font-size: 0.78rem; font-weight: 700; color: var(--gelap); margin-top: 4px;">
                    <?= h($s['nama_target_user'] ?? ('User #' . $s['user_id'])) ?>
                  </div>
                  <?php if (!empty($s['email_target_user'])): ?>
                    <div style="font-size: 0.72rem; color: var(--teks-redup);"><?= h($s['email_target_user']) ?></div>
                  <?php endif; ?>
                <?php elseif ($s['target_kategori'] === 'semua'): ?>
                  <span class="badge" style="background: var(--mint); color: #264A38; font-size: 0.74rem; font-weight: 700; padding: 3px 10px; border: 1px solid #A7F3D0;">
                    Semua Pengguna
                  </span>
                  <div style="font-size: 0.72rem; color: var(--teks-redup); margin-top: 3px;">Tampil di seluruh akun</div>
                <?php else: ?>
                  <span class="badge kategori-<?= h($s['target_kategori']) ?>" style="font-size: 0.74rem; font-weight: 700; padding: 3px 10px;">
                    BMI: <?= h($label_kat[$s['target_kategori']] ?? $s['target_kategori']) ?>
                  </span>
                  <div style="font-size: 0.72rem; color: var(--teks-redup); margin-top: 3px;">Sesuai profil gizi</div>
                <?php endif; ?>
              </td>

              <!-- TANGGAL DIBUAT -->
              <td style="padding: 14px 12px; text-align: center; color: var(--teks); font-size: 0.82rem; white-space: nowrap;">
                <div><?= date('d M Y', strtotime($s['created_at'])) ?></div>
                <div style="font-size: 0.74rem; color: var(--teks-redup);"><?= date('H:i', strtotime($s['created_at'])) ?> WIB</div>
              </td>

              <!-- AKSI -->
              <td style="padding: 14px 18px; text-align: right; white-space: nowrap;">
                <div style="display: inline-flex; gap: 6px; align-items: center;">
                  <button type="button" class="tombol sekunder kecil" style="padding: 6px 12px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px; border-radius: var(--radius-pil);"
                          onclick='bukaModalEditSaran(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, "UTF-8") ?>)'>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                  </button>

                  <form method="post" action="<?= base_url('pakar/saran.php') ?>" style="display: inline; margin: 0;"
                        onsubmit="return confirm('Apakah Anda yakin ingin menghapus saran edukasi \'<?= h(addslashes($s['judul'])) ?>\'?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="aksi" value="hapus">
                    <input type="hidden" name="saran_id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="tombol kecil" style="background: #DC2626; padding: 6px 12px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 3px; border-radius: var(--radius-pil);">
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

    <!-- FOOTER PAGINATION TAB RIWAYAT -->
    <?php if ($total_halaman_s > 1): ?>
      <?php
      $max_angka = 5;
      $mulai_hal = max(1, $hal_s - 2);
      $akhir_hal = min($total_halaman_s, $mulai_hal + $max_angka - 1);
      if ($akhir_hal - $mulai_hal + 1 < $max_angka) {
          $mulai_hal = max(1, $akhir_hal - $max_angka + 1);
      }
      ?>
      <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 18px 20px; border-top: 1px solid var(--garis); background: #F8FAF8; gap: 8px; border-bottom-left-radius: var(--radius-kartu); border-bottom-right-radius: var(--radius-kartu);">
        <div style="display: flex; gap: 6px; align-items: center; justify-content: center; flex-wrap: wrap;">
          <?php if ($hal_s > 1): ?>
            <a href="<?= h($buat_url_hal_s($hal_s - 1)) ?>" class="pagination-nav-btn" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">← Sebelumnya</a>
          <?php else: ?>
            <span class="pagination-nav-btn disabled" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">← Sebelumnya</span>
          <?php endif; ?>

          <?php if ($mulai_hal > 1): ?>
            <a href="<?= h($buat_url_hal_s(1)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;">1</a>
            <?php if ($mulai_hal > 2): ?>
              <span class="pagination-ellipsis" style="width: 18px; font-size: 0.82rem;">&hellip;</span>
            <?php endif; ?>
          <?php endif; ?>

          <?php for ($i = $mulai_hal; $i <= $akhir_hal; $i++): ?>
            <?php if ($i === $hal_s): ?>
              <span class="pagination-number-btn aktif" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $i ?></span>
            <?php else: ?>
              <a href="<?= h($buat_url_hal_s($i)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($akhir_hal < $total_halaman_s): ?>
            <?php if ($akhir_hal < $total_halaman_s - 1): ?>
              <span class="pagination-ellipsis" style="width: 18px; font-size: 0.82rem;">&hellip;</span>
            <?php endif; ?>
            <a href="<?= h($buat_url_hal_s($total_halaman_s)) ?>" class="pagination-number-btn" style="width: 34px; height: 34px; font-size: 0.82rem;"><?= $total_halaman_s ?></a>
          <?php endif; ?>

          <?php if ($hal_s < $total_halaman_s): ?>
            <a href="<?= h($buat_url_hal_s($hal_s + 1)) ?>" class="pagination-nav-btn" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">Selanjutnya →</a>
          <?php else: ?>
            <span class="pagination-nav-btn disabled" style="height: 34px; padding: 0 14px; font-size: 0.82rem;">Selanjutnya →</span>
          <?php endif; ?>
        </div>

        <span class="teks-redup" style="font-size: 0.8rem;">
          Halaman <b><?= $hal_s ?></b> dari <b><?= $total_halaman_s ?></b> &middot; Menampilkan <b><?= $mulai_s ?> - <?= $akhir_s ?></b> dari total <b><?= number_format($total_data_s, 0, ',', '.') ?></b> saran terbit
        </span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ══════════════ MODAL FORM TAMBAH / EDIT SARAN EDUKASI ══════════════ -->
<div id="modal-saran-pakar" style="display: none; position: fixed; inset: 0; background: rgba(18, 38, 29, 0.45); backdrop-filter: blur(4px); z-index: 400; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: #FFFFFF !important; border: 1px solid var(--garis); border-radius: var(--radius-kartu); padding: 28px; max-width: 620px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 64px rgba(0,0,0,0.22);">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--garis); padding-bottom: 14px;">
      <div>
        <h3 id="modal-saran-judul" style="margin: 0; font-size: 1.25rem; font-weight: 800; color: var(--gelap);">Tulis Saran Edukasi Gizi</h3>
        <p id="modal-saran-subjudul" class="teks-redup" style="margin: 2px 0 0; font-size: 0.84rem;">Saran akan otomatis terdistribusi sesuai sasaran yang Anda tentukan.</p>
      </div>
      <button type="button" onclick="tutupModalSaran()" style="background: none; border: none; font-size: 1.6rem; cursor: pointer; color: var(--teks-redup); line-height: 1;">&times;</button>
    </div>

    <form method="post" action="<?= base_url('pakar/saran.php') ?>" id="form-saran">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="saran_id" id="input-saran-id" value="0">
      <input type="hidden" name="tab_asal" id="input-tab-asal" value="<?= h($tab) ?>">

      <!-- Judul Saran -->
      <div style="margin-bottom: 16px;">
        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 6px;">Judul Saran Edukasi <span style="color:#C44536;">*</span></label>
        <input type="text" name="judul_saran" id="input-judul-saran" required maxlength="200"
               placeholder="Contoh: Tips Pola Makan Sehat Menjaga Keseimbangan Kalori"
               style="width: 100%; padding: 10px 14px; font-size: 0.88rem; border: 1.5px solid var(--garis); border-radius: 10px;">
      </div>

      <!-- Tipe Target Sasaran (Radio) -->
      <div style="margin-bottom: 16px;" id="wrapper-pilih-tipe-target">
        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 6px;">Target Sasaran Saran <span style="color:#C44536;">*</span></label>
        <div style="display: flex; gap: 20px; padding: 12px 14px; background: var(--latar); border: 1px solid var(--garis); border-radius: 10px;">
          <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.86rem; cursor: pointer; font-weight: 600; color: var(--gelap);">
            <input type="radio" name="tipe_target" value="bmi" id="radio-target-bmi" checked onchange="toggleTipeTargetModal()" style="accent-color: var(--hijau); cursor: pointer; width: 16px; height: 16px;">
            Grup Kategori BMI
          </label>
          <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.86rem; cursor: pointer; font-weight: 600; color: var(--gelap);">
            <input type="radio" name="tipe_target" value="user" id="radio-target-user" onchange="toggleTipeTargetModal()" style="accent-color: var(--hijau); cursor: pointer; width: 16px; height: 16px;">
            Pengguna Spesifik
          </label>
        </div>
      </div>

      <!-- Kontainer Dropdown Kategori BMI -->
      <div id="modal-wrapper-bmi" style="margin-bottom: 16px;">
        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 6px;">Kategori BMI Sasaran</label>
        <select name="target_kategori" id="input-target-kategori" style="width: 100%; padding: 10px 14px; font-size: 0.88rem; border: 1.5px solid var(--garis); border-radius: 10px; background: #fff;">
          <option value="semua">Semua Pengguna (Umum)</option>
          <option value="kurus">Kategori BMI Kurus (&lt; 18.5)</option>
          <option value="normal">Kategori BMI Normal (18.5 - 24.9)</option>
          <option value="gemuk">Kategori BMI Gemuk (25.0 - 29.9)</option>
          <option value="obesitas">Kategori BMI Obesitas (&ge; 30.0)</option>
        </select>
      </div>

      <!-- Kontainer Autocomplete & Pemilihan Pengguna Spesifik -->
      <div id="modal-wrapper-user" style="display: none; margin-bottom: 16px;">
        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 6px;">Cari &amp; Pilih Pengguna Sasaran <span style="color:#C44536;">*</span></label>
        
        <!-- Input Searchable Autocomplete -->
        <div style="position: relative; margin-bottom: 8px;">
          <input type="text" id="input-search-user" placeholder="Ketik nama atau email pengguna untuk memfilter..."
                 style="width: 100%; padding: 10px 14px 10px 36px; font-size: 0.88rem; border: 1.5px solid var(--garis); border-radius: 10px;"
                 oninput="filterPilihanUser(this.value)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--teks-redup); pointer-events: none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>

        <!-- Dropdown Select yang Terhubung & Terfilter -->
        <select name="target_user_id" id="select-target-user-id" style="width: 100%; padding: 10px 14px; font-size: 0.88rem; border: 1.5px solid var(--garis); border-radius: 10px; background: #fff;" onchange="updateUserTerpilih()">
          <option value="">-- Pilih Akun Pengguna Terdaftar --</option>
          <?php foreach ($daftar_user_pilihan as $du): ?>
            <option value="<?= $du['id'] ?>" data-nama="<?= h($du['nama']) ?>" data-email="<?= h($du['email']) ?>">
              <?= h($du['nama']) ?> — (<?= h($du['email']) ?>)
            </option>
          <?php endforeach; ?>
        </select>

        <div id="badge-user-terpilih" style="display: none; margin-top: 8px;">
          <span class="badge" style="background: #EDE9FE; color: #5B21B6; font-size: 0.78rem; font-weight: 700; padding: 4px 10px; border: 1px solid #DDD6FE;">
            Sasaran Terpilih: <strong id="nama-user-terpilih">-</strong>
          </span>
        </div>
      </div>

      <!-- Isi Saran Edukasi -->
      <div style="margin-bottom: 20px;">
        <label style="display: block; font-size: 0.85rem; font-weight: 700; color: var(--gelap); margin-bottom: 6px;">Isi Saran &amp; Rekomendasi Nutrisi <span style="color:#C44536;">*</span></label>
        <textarea name="isi_saran" id="input-isi-saran" rows="5" required
                  placeholder="Tuliskan saran medis, panduan asupan kalori, pola makan sehat, atau evaluasi nutrisi..."
                  style="width: 100%; font-family: inherit; font-size: 0.88rem; padding: 12px 14px; border: 1.5px solid var(--garis); border-radius: 10px; resize: vertical; line-height: 1.5;"></textarea>
      </div>

      <div style="display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid var(--garis); padding-top: 16px;">
        <button type="button" onclick="tutupModalSaran()" class="tombol sekunder kecil" style="padding: 10px 20px;">Batal</button>
        <button type="submit" class="tombol kecil" id="btn-submit-saran" style="padding: 10px 24px; font-weight: 700;">Simpan &amp; Terbitkan</button>
      </div>
    </form>
  </div>
</div>

<script>
// Cache data opsi user untuk filter instan
const rawUserOptions = [];
document.addEventListener('DOMContentLoaded', function() {
  const selectEl = document.getElementById('select-target-user-id');
  if (selectEl) {
    for (let i = 1; i < selectEl.options.length; i++) {
      const opt = selectEl.options[i];
      rawUserOptions.push({
        id: opt.value,
        nama: opt.getAttribute('data-nama') || '',
        email: opt.getAttribute('data-email') || '',
        text: opt.text
      });
    }
  }
});

function filterPilihanUser(keyword) {
  const selectEl = document.getElementById('select-target-user-id');
  const term = (keyword || '').toLowerCase().trim();
  const currentVal = selectEl.value;

  selectEl.innerHTML = '<option value="">-- Pilih Akun Pengguna Terdaftar --</option>';

  rawUserOptions.forEach(item => {
    if (!term || item.nama.toLowerCase().includes(term) || item.email.toLowerCase().includes(term)) {
      const opt = document.createElement('option');
      opt.value = item.id;
      opt.setAttribute('data-nama', item.nama);
      opt.setAttribute('data-email', item.email);
      opt.textContent = item.text;
      if (item.id === currentVal) opt.selected = true;
      selectEl.appendChild(opt);
    }
  });

  updateUserTerpilih();
}

function updateUserTerpilih() {
  const selectEl = document.getElementById('select-target-user-id');
  const badgeWrap = document.getElementById('badge-user-terpilih');
  const namaTxt = document.getElementById('nama-user-terpilih');

  if (selectEl && selectEl.value) {
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    namaTxt.textContent = selectedOpt.getAttribute('data-nama') || selectedOpt.text;
    badgeWrap.style.display = 'block';
  } else {
    badgeWrap.style.display = 'none';
  }
}

function toggleTipeTargetModal() {
  const isUser = document.getElementById('radio-target-user').checked;
  const wrapBmi = document.getElementById('modal-wrapper-bmi');
  const wrapUser = document.getElementById('modal-wrapper-user');

  if (isUser) {
    wrapBmi.style.display = 'none';
    wrapUser.style.display = 'block';
  } else {
    wrapBmi.style.display = 'block';
    wrapUser.style.display = 'none';
  }
}

// 1. Buka Modal dari Tab 1: Pasien Spesifik (Nama & ID otomatis terkunci)
function bukaModalBeriSaranDariPasien(userId, nama, email, bmi, katBmi) {
  document.getElementById('modal-saran-judul').textContent = 'Beri Saran Personal untuk ' + nama;
  document.getElementById('modal-saran-subjudul').textContent = 'Profil Klinis: BMI ' + bmi + ' (' + katBmi + ') · Akun: ' + email;
  document.getElementById('btn-submit-saran').textContent = 'Kirim Saran Personal';
  document.getElementById('input-saran-id').value = '0';
  document.getElementById('input-tab-asal').value = 'monitoring';
  document.getElementById('input-judul-saran').value = '';
  document.getElementById('input-isi-saran').value = '';

  // Kunci ke Pengguna Spesifik
  document.getElementById('radio-target-user').checked = true;
  toggleTipeTargetModal();

  filterPilihanUser('');
  document.getElementById('select-target-user-id').value = userId;
  updateUserTerpilih();

  document.getElementById('modal-saran-pakar').style.display = 'flex';
  document.getElementById('input-judul-saran').focus();
}

// 2. Buka Modal Tambah Saran Bebas dari Tab 2
function bukaModalTambahSaranUmum() {
  document.getElementById('modal-saran-judul').textContent = 'Tulis Saran Edukasi Baru';
  document.getElementById('modal-saran-subjudul').textContent = 'Saran akan otomatis terdistribusi sesuai sasaran yang Anda tentukan.';
  document.getElementById('btn-submit-saran').textContent = 'Simpan & Terbitkan';
  document.getElementById('input-saran-id').value = '0';
  document.getElementById('input-tab-asal').value = 'riwayat';
  document.getElementById('input-judul-saran').value = '';
  document.getElementById('input-isi-saran').value = '';
  document.getElementById('radio-target-bmi').checked = true;
  document.getElementById('input-target-kategori').value = 'semua';
  document.getElementById('input-search-user').value = '';

  filterPilihanUser('');
  document.getElementById('select-target-user-id').value = '';
  toggleTipeTargetModal();
  updateUserTerpilih();

  document.getElementById('modal-saran-pakar').style.display = 'flex';
  document.getElementById('input-judul-saran').focus();
}

// 3. Buka Modal Edit Saran
function bukaModalEditSaran(saran) {
  document.getElementById('modal-saran-judul').textContent = 'Edit Saran: ' + saran.judul;
  document.getElementById('modal-saran-subjudul').textContent = 'Perbarui konten panduan atau target sasaran saran ini.';
  document.getElementById('btn-submit-saran').textContent = 'Perbarui Saran';
  document.getElementById('input-saran-id').value = saran.id;
  document.getElementById('input-tab-asal').value = 'riwayat';
  document.getElementById('input-judul-saran').value = saran.judul;
  document.getElementById('input-isi-saran').value = saran.isi_saran;

  if (saran.user_id && parseInt(saran.user_id) > 0) {
    document.getElementById('radio-target-user').checked = true;
    toggleTipeTargetModal();
    filterPilihanUser('');
    document.getElementById('select-target-user-id').value = saran.user_id;
    updateUserTerpilih();
  } else {
    document.getElementById('radio-target-bmi').checked = true;
    document.getElementById('input-target-kategori').value = saran.target_kategori || 'semua';
    toggleTipeTargetModal();
  }

  document.getElementById('modal-saran-pakar').style.display = 'flex';
  document.getElementById('input-judul-saran').focus();
}

function tutupModalSaran() {
  document.getElementById('modal-saran-pakar').style.display = 'none';
}

document.getElementById('modal-saran-pakar').addEventListener('click', function(e) {
  if (e.target === this) tutupModalSaran();
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') tutupModalSaran();
});
</script>

<?php include BASE_DIR . 'includes/footer.php'; ?>
