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

// Apakah form diblokir?
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

<div class="page-hero-header">
  <div class="page-hero-icon">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m16 12-4-4-4 4"/><path d="M12 16V8"/></svg>
  </div>
  <div class="page-hero-teks">
    <h2>Hitung BMI</h2>
    <p>Isi data tubuhmu untuk mengetahui kategori BMI dan target kalori harian.</p>
  </div>
</div>

<?php if ($pesan): ?>
  <div class="alert <?= $pesan_tipe === 'error' ? 'error' : 'sukses' ?>"><?= h($pesan) ?></div>
<?php endif; ?>

<div class="dua-kolom bmi-grid-harmonized">
  <!-- Kolom Kiri: Form Input atau Status Terkunci -->
  <div class="kotak-form bmi-card-equal" style="margin:0; max-width:none;">

    <?php if ($form_diblokir): ?>
      <!-- Form diblokir: user masih punya rencana diet aktif -->
      <div style="display:flex; flex-direction:column; justify-content:space-between; height:100%;">
        <div>
          <div style="display:inline-flex; align-items:center; gap:6px; background:var(--mint); color:var(--hijau); font-weight:700; font-size:0.75rem; padding:4px 10px; border-radius:var(--radius-pil); margin-bottom:14px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Program Diet Berlangsung
          </div>
          <h3 style="font-size:1.2rem; margin-bottom:8px; color:var(--gelap);">Pembaruan BMI Terkunci</h3>
          <p class="teks-redup" style="font-size:0.88rem; line-height:1.55; margin-bottom:16px;">
            Kamu sedang menjalankan program diet aktif<?php if (!empty($rencana_aktif['berat_target'])): ?> dengan target <strong><?= h(number_format((float)$rencana_aktif['berat_target'], 1, ',', '.')) ?> kg</strong><?php endif; ?>.
            Untuk menjaga konsistensi kalkulasi target asupan harian, data BMI tidak dapat diubah selama rencana masih aktif.
          </p>
          <div style="background:var(--latar); border:1px solid var(--garis); border-radius:var(--radius-kartu); padding:14px; font-size:0.83rem; color:var(--teks-redup); line-height:1.5;">
            <span style="font-weight:700; color:var(--gelap); display:block; margin-bottom:2px;">Catatan:</span>
            Jika target telah tercapai atau ingin mengubah target baru, tandai program diet sebagai selesai pada menu Rencana Diet.
          </div>
        </div>
        <div style="margin-top:20px;">
          <a class="tombol penuh" href="rencana.php" style="text-align:center; display:block;">Kelola Rencana Diet &rarr;</a>
        </div>
      </div>

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

  <!-- Kolom Kanan: Hasil Perhitungan BMI & Target Kalori -->
  <div>
    <?php if ($riwayat_terakhir): ?>
      <div class="hasil bmi-card-equal" style="margin:0;">
        <div>
          <small>BMI terakhir tersimpan</small>
          <div style="display:flex; align-items:baseline; gap:12px; margin-top:4px;">
            <div class="angka-besar" style="margin:0;"><?= h(number_format((float)$riwayat_terakhir['bmi'], 1, ',', '.')) ?></div>
            <span class="badge kategori-<?= h($riwayat_terakhir['kat_kode']) ?>" style="font-size:0.9rem; padding:4px 12px;"><?= h($riwayat_terakhir['kat_nama']) ?></span>
          </div>

          <p style="margin-top:12px; line-height:1.6; font-size:0.9rem; color:var(--gelap);"><?= h($riwayat_terakhir['kat_saran']) ?></p>
          
          <hr style="margin:16px 0;">
          
          <small>Target kalori harian</small>
          <div class="angka-besar" style="margin:4px 0 6px;"><?= h(number_format((int)$riwayat_terakhir['target_kalori'], 0, ',', '.')) ?> <small>kkal</small></div>
          <?php if ($rencana_aktif): ?>
            <p class="catatan" style="margin:6px 0 14px">
              Target aktif di Dashboard &amp; Catatan: <strong><?= number_format((int)$rencana_aktif['target_kalori'], 0, ',', '.') ?> kkal</strong> (Program Diet).
            </p>
          <?php endif; ?>
        </div>

        <div style="margin-top:16px;">
          <a class="tombol penuh" href="menu.php" style="text-align:center; display:block;">Lihat menu yang cocok &rarr;</a>
        </div>
      </div>
    <?php else: ?>
      <div class="kosong bmi-card-equal" style="margin:0; justify-content:center; text-align:center; padding:32px 20px;">
        <p>Hasilnya akan muncul di sini setelah kamu mengisi formulir dan menekan tombol <strong>Simpan &amp; Hitung BMI</strong>.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Bagian Bawah: Klasifikasi Standar BMI (Kemenkes RI) Full-Width 4 Kolom -->
<div class="kartu bmi-klasifikasi-card">
  <div class="bmi-klasifikasi-item-header">
    <div>
      <h3 style="font-size: 1.05rem; margin-bottom: 4px; display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        Klasifikasi Standar BMI (Kemenkes RI)
      </h3>
      <p class="teks-redup" style="font-size: 0.85rem; margin: 0;">
        Rentang acuan indeks massa tubuh klinis yang digunakan sebagai acuan gizi:
      </p>
    </div>
  </div>

  <div class="bmi-klasifikasi-grid">
    <!-- 1. Kurus -->
    <?php $is_kurus = ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'kurus'); ?>
    <div class="bmi-klasifikasi-item <?= $is_kurus ? 'aktif' : '' ?>">
      <div class="bmi-klasifikasi-item-header">
        <span class="badge kategori-kurus" style="font-size:0.76rem;">Kurus</span>
        <?php if ($is_kurus): ?>
          <span class="bmi-klasifikasi-tag-aktif">&check; Kategori Tubuhmu</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="bmi-klasifikasi-item-range">&lt; 18,5</div>
        <div class="teks-redup" style="font-size:0.8rem; margin-top:2px;">Underweight</div>
      </div>
    </div>

    <!-- 2. Normal -->
    <?php $is_normal = ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'normal'); ?>
    <div class="bmi-klasifikasi-item <?= $is_normal ? 'aktif' : '' ?>">
      <div class="bmi-klasifikasi-item-header">
        <span class="badge kategori-normal" style="font-size:0.76rem;">Normal</span>
        <?php if ($is_normal): ?>
          <span class="bmi-klasifikasi-tag-aktif">&check; Kategori Tubuhmu</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="bmi-klasifikasi-item-range">18,5 &ndash; 25,0</div>
        <div class="teks-redup" style="font-size:0.8rem; margin-top:2px;">Ideal / Sehat</div>
      </div>
    </div>

    <!-- 3. Gemuk -->
    <?php $is_gemuk = ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'gemuk'); ?>
    <div class="bmi-klasifikasi-item <?= $is_gemuk ? 'aktif' : '' ?>">
      <div class="bmi-klasifikasi-item-header">
        <span class="badge kategori-gemuk" style="font-size:0.76rem;">Gemuk</span>
        <?php if ($is_gemuk): ?>
          <span class="bmi-klasifikasi-tag-aktif">&check; Kategori Tubuhmu</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="bmi-klasifikasi-item-range">25,1 &ndash; 27,0</div>
        <div class="teks-redup" style="font-size:0.8rem; margin-top:2px;">Overweight</div>
      </div>
    </div>

    <!-- 4. Obesitas -->
    <?php $is_obesitas = ($riwayat_terakhir && $riwayat_terakhir['kat_kode'] === 'obesitas'); ?>
    <div class="bmi-klasifikasi-item <?= $is_obesitas ? 'aktif' : '' ?>">
      <div class="bmi-klasifikasi-item-header">
        <span class="badge kategori-obesitas" style="font-size:0.76rem;">Obesitas</span>
        <?php if ($is_obesitas): ?>
          <span class="bmi-klasifikasi-tag-aktif">&check; Kategori Tubuhmu</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="bmi-klasifikasi-item-range">&gt; 27,0</div>
        <div class="teks-redup" style="font-size:0.8rem; margin-top:2px;">Perlu Pengawasan</div>
      </div>
    </div>
  </div>
</div>

<p class="catatan" style="margin-top:14px;">BMI hanya indikator kasar dan kurang cocok untuk atlet, ibu hamil, dan usia di bawah 18 tahun. Target kalori bersifat perkiraan edukatif.</p>

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



