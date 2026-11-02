<?php
require_once __DIR__ . '/config/koneksi.php';
wajib_login();

$uid  = $_SESSION['user_id'];
$csrf = csrf_token('csrf_token_bmi');

// Ambil rencana diet aktif
$st_rencana = $pdo->prepare("SELECT rd.*, kb.nama AS kat_nama, kb.kode AS kat_kode
                              FROM rencana_diet rd
                              LEFT JOIN kategori_bmi kb ON kb.id = (
                                  SELECT kategori_id FROM riwayat_bmi WHERE user_id = rd.user_id ORDER BY id DESC LIMIT 1
                              )
                              WHERE rd.user_id = ? AND rd.status = 'aktif'
                              ORDER BY rd.id DESC LIMIT 1");
$st_rencana->execute([$uid]);
$rencana_aktif = $st_rencana->fetch();

// Flash message dari redirect
$pesan      = $_SESSION['pesan_bmi']      ?? '';
$pesan_tipe = $_SESSION['pesan_bmi_tipe'] ?? 'sukses';
unset($_SESSION['pesan_bmi'], $_SESSION['pesan_bmi_tipe']);

// Riwayat BMI terakhir (untuk tampilan hasil kolom kanan)
$st_last = $pdo->prepare('SELECT rb.*, kb.nama AS kat_nama, kb.kode AS kat_kode, kb.saran AS kat_saran
                           FROM riwayat_bmi rb
                           JOIN kategori_bmi kb ON kb.id = rb.kategori_id
                           WHERE rb.user_id = ? ORDER BY rb.id DESC LIMIT 1');
$st_last->execute([$uid]);
$riwayat_terakhir = $st_last->fetch();

// Hitung posisi jarum skala BMI visual (rentang klinis 14 - 34)
$posisi_persen = 0;
if ($riwayat_terakhir) {
    $bmi_val = (float)$riwayat_terakhir['bmi'];
    if ($bmi_val <= 14) {
        $posisi_persen = 3;
    } elseif ($bmi_val >= 34) {
        $posisi_persen = 97;
    } elseif ($bmi_val < 18.5) {
        $posisi_persen = 3 + (($bmi_val - 14) / (18.5 - 14)) * 19.5;
    } elseif ($bmi_val <= 25.0) {
        $posisi_persen = 22.5 + (($bmi_val - 18.5) / (25.0 - 18.5)) * 32.5;
    } elseif ($bmi_val <= 27.0) {
        $posisi_persen = 55 + (($bmi_val - 25.0) / (27.0 - 25.0)) * 10;
    } else {
        $posisi_persen = 65 + (($bmi_val - 27.0) / (34 - 27.0)) * 32;
    }
    $posisi_persen = round(max(3, min(97, $posisi_persen)), 1);
}

// â”€â”€ Apakah form diblokir? â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Blokir jika: sudah pernah input BMI DAN masih punya rencana diet aktif
// (artinya target belum selesai/diselesaikan)
$form_diblokir = $riwayat_terakhir && $rencana_aktif;

// --- Proses POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';

    if (!csrf_valid($token, 'csrf_token_bmi')) {
        $_SESSION['pesan_bmi']      = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $_SESSION['pesan_bmi_tipe'] = 'error';
    } elseif ($form_diblokir) {
        // Tolak jika form seharusnya diblokir (bypass via curl, dsb.)
        $_SESSION['pesan_bmi']      = 'Kamu masih memiliki rencana diet aktif. Selesaikan rencana tersebut sebelum memperbarui data BMI.';
        $_SESSION['pesan_bmi_tipe'] = 'error';
    } else {
        $tinggi = (float)($_POST['tinggi'] ?? 0);
        $berat  = (float)($_POST['berat'] ?? 0);
        $usia   = (int)($_POST['usia'] ?? 0);
        $jk     = $_POST['jk'] ?? '';
        $aktif  = (float)($_POST['aktivitas'] ?? 1.2);

        if ($tinggi < 100 || $tinggi > 230 || $berat < 20 || $berat > 250
            || $usia < 10 || $usia > 100 || !in_array($jk, ['L', 'P'], true)) {
            $_SESSION['pesan_bmi']      = 'Data tidak valid. Tinggi badan harus 100–230 cm, berat badan 20–250 kg, dan usia 10–100 tahun.';
            $_SESSION['pesan_bmi_tipe'] = 'error';
            $_SESSION['old_bmi_input']  = [
                'tinggi'    => $_POST['tinggi'] ?? '',
                'berat'     => $_POST['berat'] ?? '',
                'usia'      => $_POST['usia'] ?? '',
                'jk'        => $jk,
                'aktivitas' => $aktif,
            ];
        } else {
            // 1. Hitung BMI
            $bmi       = $berat / pow($tinggi / 100, 2);
            $bmi_nilai = round($bmi, 2);

            $q = $pdo->prepare('SELECT * FROM kategori_bmi WHERE ? BETWEEN bmi_min AND bmi_max LIMIT 1');
            $q->execute([$bmi_nilai]);
            $kat = $q->fetch();

            // Fallback jika nilai BMI di luar rentang tabel
            if (!$kat) {
                $kat = ($bmi_nilai >= 27.01)
                    ? $pdo->query("SELECT * FROM kategori_bmi WHERE kode = 'obesitas' LIMIT 1")->fetch()
                    : $pdo->query("SELECT * FROM kategori_bmi ORDER BY bmi_min ASC LIMIT 1")->fetch();
            }

            // 2. Hitung kebutuhan kalori (Mifflin-St Jeor)
            $bmr  = 10 * $berat + 6.25 * $tinggi - 5 * $usia + ($jk === 'L' ? 5 : -161);
            $tdee = $bmr * $aktif;
            $penyesuaian = ['kurus' => 300, 'normal' => 0, 'gemuk' => -300, 'obesitas' => -500];
            $batas_bawah = ($jk === 'L') ? 1500 : 1200;
            $target = (int)(round(max($tdee + ($penyesuaian[$kat['kode']] ?? 0), $batas_bawah) / 10) * 10);

            // 3. Simpan ke riwayat_bmi
            $simpan = $pdo->prepare(
                'INSERT INTO riwayat_bmi (user_id, tinggi_cm, berat_kg, usia, jenis_kelamin, bmi, kategori_id, target_kalori)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $simpan->execute([$uid, $tinggi, $berat, $usia, $jk, round($bmi, 1), $kat['id'], $target]);

            $_SESSION['pesan_bmi']      = 'Data BMI berhasil disimpan.';
            $_SESSION['pesan_bmi_tipe'] = 'sukses';
        }
    }

    // Redirect PRG â€” mencegah submit ulang saat refresh
    header('Location: bmi.php');
    exit;
}

// Pre-fill form dari input lama (jika ada error) atau dari riwayat terakhir
$old_input  = $_SESSION['old_bmi_input'] ?? null;
unset($_SESSION['old_bmi_input']);

$val_tinggi = $old_input['tinggi']    ?? ($riwayat_terakhir['tinggi_cm']    ?? '');
$val_berat  = $old_input['berat']     ?? ($riwayat_terakhir['berat_kg']     ?? '');
$val_usia   = $old_input['usia']      ?? ($riwayat_terakhir['usia']          ?? '');
$val_jk     = $old_input['jk']        ?? ($riwayat_terakhir['jenis_kelamin'] ?? 'L');
$val_aktif  = $old_input['aktivitas'] ?? '1.2';

$judul = 'Hitung BMI';
include BASE_DIR . 'includes/header.php';
?>

<h2>Hitung BMI</h2>
<p class="teks-redup">Isi data tubuhmu untuk mengetahui kategori BMI dan target kalori harian.</p>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= h($pesan) ?></div>
<?php endif; ?>

<div class="dua-kolom">
  <div class="kotak-form" style="margin:0; max-width:none;">

    <?php if ($form_diblokir): ?>
      <!-- Form diblokir: user masih punya rencana aktif -->
      <div class="alert error" style="margin-bottom:16px">
        <strong>Pembaruan BMI tidak tersedia.</strong><br>
        Kamu masih memiliki rencana diet aktif<?php if ($rencana_aktif['berat_target']): ?> (target berat: <b><?= h(number_format((float)$rencana_aktif['berat_target'], 1, ',', '.')) ?>&nbsp;kg</b>)<?php endif; ?>. Selesaikan atau tandai rencana tersebut sebagai selesai terlebih dahulu sebelum memperbarui data BMI.
      </div>
      <a class="tombol penuh" href="rencana.php">Lihat rencana diet aktif</a>

    <?php else: ?>
      <!-- Form aktif: belum ada BMI atau rencana sudah selesai -->
      <form method="post" id="form-bmi">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <div class="form-row-2">
          <div>
            <label for="tinggi">Tinggi badan (cm)</label>
            <div class="input-unit-wrapper">
              <input type="number" id="tinggi" name="tinggi" min="100" max="230" step="0.1" placeholder="Contoh: 170" value="<?= h($val_tinggi) ?>" required>
              <span class="unit-suffix">cm</span>
            </div>
          </div>

          <div>
            <label for="berat">Berat badan (kg)</label>
            <div class="input-unit-wrapper">
              <input type="number" id="berat" name="berat" min="20" max="250" step="0.1" placeholder="Contoh: 65" value="<?= h($val_berat) ?>" required>
              <span class="unit-suffix">kg</span>
            </div>
          </div>
        </div>

        <div class="form-row-2" style="margin-top: 14px;">
          <div>
            <label for="usia">Usia (tahun)</label>
            <div class="input-unit-wrapper">
              <input type="number" id="usia" name="usia" min="10" max="100" step="1" placeholder="Contoh: 25" value="<?= h($val_usia) ?>" required>
              <span class="unit-suffix">thn</span>
            </div>
          </div>

          <div>
            <label for="jk">Jenis kelamin</label>
            <select id="jk" name="jk" required>
              <option value="L" <?= $val_jk === 'L' ? 'selected' : '' ?>>Laki-laki</option>
              <option value="P" <?= $val_jk === 'P' ? 'selected' : '' ?>>Perempuan</option>
            </select>
          </div>
        </div>

        <div style="margin-top: 14px;">
          <label for="aktivitas">Aktivitas harian</label>
          <select id="aktivitas" name="aktivitas">
            <option value="1.2"   <?= (string)$val_aktif === '1.2'   ? 'selected' : '' ?>>Jarang olahraga (banyak duduk)</option>
            <option value="1.375" <?= (string)$val_aktif === '1.375' ? 'selected' : '' ?>>Olahraga ringan 1-3x seminggu</option>
            <option value="1.55"  <?= (string)$val_aktif === '1.55'  ? 'selected' : '' ?>>Olahraga sedang 3-5x seminggu</option>
          </select>
        </div>

        <button type="submit" id="btn-submit-bmi" class="tombol penuh" style="margin-top: 20px;">
          Simpan &amp; Hitung BMI &rarr;
        </button>
      </form>
    <?php endif; ?>

  </div>

  <div>
    <?php if ($riwayat_terakhir): ?>
      <div class="hasil">
        <small>BMI terakhir tersimpan</small>
        <div style="display:flex; align-items:baseline; gap:12px; margin-top:4px;">
          <div class="angka-besar" style="margin:0;"><?= h(number_format((float)$riwayat_terakhir['bmi'], 1, ',', '.')) ?></div>
          <span class="badge kategori-<?= h($riwayat_terakhir['kat_kode']) ?>" style="font-size:0.9rem; padding:4px 12px;"><?= h($riwayat_terakhir['kat_nama']) ?></span>
        </div>

        <!-- Indikator Skala Warna Visual BMI Meter -->
        <div class="bmi-gauge-wrapper">
          <div class="bmi-gauge-bar" title="Indeks Massa Tubuh: <?= h($riwayat_terakhir['bmi']) ?>">
            <div class="bmi-gauge-segment kurus" title="Kurus (< 18.5)"></div>
            <div class="bmi-gauge-segment normal" title="Normal (18.5 - 25.0)"></div>
            <div class="bmi-gauge-segment gemuk" title="Gemuk (25.1 - 27.0)"></div>
            <div class="bmi-gauge-segment obesitas" title="Obesitas (> 27.0)"></div>
            <div class="bmi-gauge-pin" style="left: <?= $posisi_persen ?>%;">
              <div class="bmi-gauge-pin-dot"></div>
            </div>
          </div>
          <div class="bmi-gauge-labels">
            <span>Kurus &lt;18,5</span>
            <span style="color:#059669;">Normal 18,5-25</span>
            <span style="color:#D97706;">Gemuk 25-27</span>
            <span style="color:#DC2626;">Obesitas &gt;27</span>
          </div>
        </div>

        <p style="margin-top:14px; line-height:1.6;"><?= h($riwayat_terakhir['kat_saran']) ?></p>
        <hr>
        <small>Target kalori harian</small>
        <div class="angka-besar"><?= h(number_format((int)$riwayat_terakhir['target_kalori'], 0, ',', '.')) ?> <small>kkal</small></div>
        <?php if ($rencana_aktif): ?>
          <p class="catatan" style="margin:10px 0 14px">
            Kamu memiliki rencana diet aktif dengan target <b><?= number_format((int)$rencana_aktif['target_kalori'], 0, ',', '.') ?> kkal</b>.
            Target pada rencana aktif tersebut tetap menjadi acuan utama pada Dashboard dan Catatan.
          </p>
        <?php endif; ?>
        <a class="tombol" href="menu.php" style="margin-top:6px;">Lihat menu yang cocok &rarr;</a>
      </div>
    <?php else: ?>
      <div class="kosong">Hasilnya akan muncul di sini setelah kamu menekan <b>Simpan &amp; Hitung BMI</b>.</div>
    <?php endif; ?>

    <div class="kartu" style="margin-top: 20px; padding: 20px;">
      <h3 style="font-size: 1.05rem; margin-bottom: 8px;">
        Klasifikasi Standar BMI (Kemenkes RI)
      </h3>
      <p class="teks-redup" style="font-size: 0.85rem; margin-bottom: 14px;">
        Berikut adalah rentang acuan indeks massa tubuh yang digunakan dalam sistem:
      </p>
      <div style="display: flex; flex-direction: column; gap: 8px;">
        <div class="baris-standar-bmi <?= ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'kurus') ? 'aktif-kategori' : '' ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border-radius: var(--radius-kecil); font-size: 0.88rem;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span class="badge kategori-kurus" style="font-size: 0.78rem;">Kurus / Underweight</span>
            <?php if ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'kurus'): ?>
              <span class="tag-posisi-anda">&check; Kategori Tubuhmu</span>
            <?php endif; ?>
          </div>
          <strong>&lt; 18,5</strong>
        </div>

        <div class="baris-standar-bmi <?= ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'normal') ? 'aktif-kategori' : '' ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border-radius: var(--radius-kecil); font-size: 0.88rem;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span class="badge kategori-normal" style="font-size: 0.78rem;">Normal (Ideal)</span>
            <?php if ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'normal'): ?>
              <span class="tag-posisi-anda">&check; Kategori Tubuhmu</span>
            <?php endif; ?>
          </div>
          <strong>18,5 &ndash; 25,0</strong>
        </div>

        <div class="baris-standar-bmi <?= ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'gemuk') ? 'aktif-kategori' : '' ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border-radius: var(--radius-kecil); font-size: 0.88rem;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span class="badge kategori-gemuk" style="font-size: 0.78rem;">Gemuk / Overweight</span>
            <?php if ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'gemuk'): ?>
              <span class="tag-posisi-anda">&check; Kategori Tubuhmu</span>
            <?php endif; ?>
          </div>
          <strong>25,1 &ndash; 27,0</strong>
        </div>

        <div class="baris-standar-bmi <?= ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'obesitas') ? 'aktif-kategori' : '' ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border-radius: var(--radius-kecil); font-size: 0.88rem;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span class="badge kategori-obesitas" style="font-size: 0.78rem;">Obesitas</span>
            <?php if ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'obesitas'): ?>
              <span class="tag-posisi-anda">&check; Kategori Tubuhmu</span>
            <?php endif; ?>
          </div>
          <strong>&gt; 27,0</strong>
        </div>
      </div>
    </div>

    <p class="catatan">BMI hanya indikator kasar dan kurang cocok untuk atlet, ibu hamil, dan usia di bawah 18 tahun. Target kalori bersifat perkiraan.</p>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var formBmi = document.getElementById('form-bmi');
  if (!formBmi) return;

  var inputs = formBmi.querySelectorAll('input[type="number"]');

  inputs.forEach(function (inp) {
    // 1. Nonaktifkan pengubahan angka via scroll wheel mouse
    inp.addEventListener('wheel', function (e) {
      e.preventDefault();
      inp.blur();
    }, { passive: false });

    // 2. Blokir pengetikan karakter minus (-), plus (+), dan notasi eksponensial (e, E)
    inp.addEventListener('keydown', function (e) {
      if (e.key === '-' || e.key === '+' || e.key === 'e' || e.key === 'E') {
        e.preventDefault();
      }
    });

    // 3. Sanitasi nilai jika ditempel (paste) angka negatif
    inp.addEventListener('input', function () {
      if (this.value !== '') {
        var val = parseFloat(this.value);
        if (val < 0) {
          this.value = Math.abs(val) || '';
        }
      }
    });
  });

  // 4. Mencegah submit form saat menekan tombol Enter di dalam input
  // Pengguna harus secara sadar menekan tombol "Simpan" secara manual
  formBmi.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      return false;
    }
  });

  // 5. Validasi sebelum formulir dikirimkan
  formBmi.addEventListener('submit', function (e) {
    var tinggi = parseFloat(document.getElementById('tinggi').value);
    var berat  = parseFloat(document.getElementById('berat').value);
    var usia   = parseInt(document.getElementById('usia').value, 10);

    var pesan = '';
    if (isNaN(tinggi) || tinggi < 100 || tinggi > 230) {
      pesan = 'Tinggi badan harus diisi antara 100 sampai 230 cm.';
    } else if (isNaN(berat) || berat < 20 || berat > 250) {
      pesan = 'Berat badan harus diisi antara 20 sampai 250 kg.';
    } else if (isNaN(usia) || usia < 10 || usia > 100) {
      pesan = 'Usia harus diisi antara 10 sampai 100 tahun.';
    }

    if (pesan) {
      e.preventDefault();
      alert(pesan);
      return false;
    }
  });
});
</script>

<?php include BASE_DIR . 'includes/footer.php'; ?>



