<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_pakar();

$judul = 'Ringkasan & Statistik Pakar Gizi';
include BASE_DIR . 'includes/header.php';

// ── 1. Metrik Statistik Umum (Anonim) ───────────────────────────
$total_user = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$total_catatan = (int)$pdo->query("SELECT COUNT(*) FROM catatan_makan")->fetchColumn();

// Rata-rata kalori harian per pengguna (30 hari terakhir)
$rata_kalori = $pdo->query("
    SELECT AVG(jml) FROM (
        SELECT SUM(md.kalori * cm.porsi) AS jml
        FROM catatan_makan cm
        JOIN menu_diet md ON cm.menu_id = md.id
        WHERE cm.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY cm.user_id, cm.tanggal
    ) AS sub
")->fetchColumn();
$rata_kalori = $rata_kalori ? (int)round($rata_kalori) : 0;

// Rata-rata BMI terkini dari pengguna terdaftar
$rata_bmi = $pdo->query("
    SELECT AVG(rb.bmi) FROM riwayat_bmi rb
    INNER JOIN (
        SELECT user_id, MAX(id) AS max_id
        FROM riwayat_bmi
        GROUP BY user_id
    ) last_rb ON rb.id = last_rb.max_id
")->fetchColumn();
$rata_bmi = $rata_bmi ? number_format((float)$rata_bmi, 1) : '-';

// Jumlah pengguna dengan rencana diet aktif
$user_rencana_aktif = (int)$pdo->query("
    SELECT COUNT(DISTINCT user_id) FROM rencana_diet WHERE status = 'aktif'
")->fetchColumn();

// Pengguna yang belum pernah mencatat makanan
$user_belum_catat = (int)$pdo->query("
    SELECT COUNT(*) FROM users u
    WHERE u.role = 'user'
    AND NOT EXISTS (SELECT 1 FROM catatan_makan cm WHERE cm.user_id = u.id)
")->fetchColumn();

// ── 2. Distribusi Kategori BMI Pengguna Terkini ──────────────────
$dist_bmi = $pdo->query("
    SELECT kb.nama, kb.kode, kb.warna, 
           COUNT(latest_rb.user_id) AS jumlah
    FROM kategori_bmi kb
    LEFT JOIN (
        SELECT rb.user_id, rb.kategori_id
        FROM riwayat_bmi rb
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id
            FROM riwayat_bmi
            GROUP BY user_id
        ) last_rb ON rb.id = last_rb.max_id
    ) latest_rb ON latest_rb.kategori_id = kb.id
    GROUP BY kb.id, kb.nama, kb.kode, kb.warna
    ORDER BY kb.id ASC
")->fetchAll();

// ── 3. Distribusi Pola Waktu Makan ──────────────────────────────
$dist_waktu = $pdo->query("
    SELECT md.waktu_makan, COUNT(cm.id) AS jumlah
    FROM catatan_makan cm
    JOIN menu_diet md ON cm.menu_id = md.id
    GROUP BY md.waktu_makan
    ORDER BY jumlah DESC
")->fetchAll();

// ── 4. 10 Menu Paling Sering Dicatat ────────────────────────────
$menu_populer = $pdo->query("
    SELECT md.nama_menu, md.waktu_makan, md.kalori, md.vegetarian, COUNT(cm.id) AS frekuensi
    FROM catatan_makan cm
    JOIN menu_diet md ON cm.menu_id = md.id
    GROUP BY md.id, md.nama_menu, md.waktu_makan, md.kalori, md.vegetarian
    ORDER BY frekuensi DESC
    LIMIT 10
")->fetchAll();

// ── 5. Saran Terakhir dari Pakar yang Sedang Login ───────────────
$pakar_id = (int)$_SESSION['user_id'];
$saran_terbaru = $pdo->prepare("
    SELECT sp.id, sp.judul, sp.isi_saran, sp.target_kategori, sp.user_id, sp.created_at, u.nama AS nama_target_user
    FROM saran_pakar sp
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.pakar_id = ?
    ORDER BY sp.created_at DESC
    LIMIT 4
");
$saran_terbaru->execute([$pakar_id]);
$saran_terbaru = $saran_terbaru->fetchAll();

// Tinjauan Gizi Menu oleh Pakar
$total_tinjauan_pakar = (int)$pdo->query("SELECT COUNT(*) FROM catatan_gizi WHERE pakar_id = $pakar_id")->fetchColumn();
?>

<?php include __DIR__ . '/nav.php'; ?>

<!-- STATISTIK UTAMA (Tema Calorie Lab) -->
<div class="admin-stat-grid" style="margin-bottom: 24px;">
  <div class="admin-stat-card">
    <div class="admin-stat-label">Total Pengguna Terdaftar</div>
    <div class="admin-stat-value"><?= number_format($total_user, 0, ',', '.') ?></div>
    <div class="admin-stat-sub">Akun role pengguna biasa</div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-label">Total Catatan Makan</div>
    <div class="admin-stat-value"><?= number_format($total_catatan, 0, ',', '.') ?></div>
    <div class="admin-stat-sub">Log aktivitas konsumsi pengguna</div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-label">Rata-Rata Kalori Harian</div>
    <div class="admin-stat-value">
      <?= number_format($rata_kalori, 0, ',', '.') ?>
      <span style="font-size: 0.95rem; font-weight: 600; color: var(--teks-redup);">kkal</span>
    </div>
    <div class="admin-stat-sub">Rata-rata 30 hari terakhir</div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-label">Rata-Rata BMI Pengguna</div>
    <div class="admin-stat-value"><?= $rata_bmi ?></div>
    <div class="admin-stat-sub">Berdasarkan data ukur terkini</div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-label">Rencana Diet Aktif</div>
    <div class="admin-stat-value" style="color: var(--hijau);"><?= number_format($user_rencana_aktif, 0, ',', '.') ?></div>
    <div class="admin-stat-sub">Pengguna sedang menjalani program</div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-label">Belum Pernah Mencatat</div>
    <div class="admin-stat-value" style="color: #92400E;"><?= number_format($user_belum_catat, 0, ',', '.') ?></div>
    <div class="admin-stat-sub">Perlu edukasi & motivasi pola makan</div>
  </div>
</div>

<!-- GRID DUA KOLOM: DISTRIBUSI BMI & POLA WAKTU MAKAN -->
<div class="admin-dashboard-layout" style="margin-bottom: 24px;">

  <!-- DISTRIBUSI KATEGORI BMI -->
  <div class="kartu" style="padding: 24px;">
    <h3 style="font-size: 1.15rem; margin-bottom: 6px;">Distribusi Kategori BMI Pengguna</h3>
    <p class="teks-redup" style="font-size: 0.88rem; margin-bottom: 20px;">
      Rangkuman kondisi klinis tubuh seluruh pengguna terdaftar (data anonim).
    </p>

    <?php 
    $total_bmi_records = array_sum(array_column($dist_bmi, 'jumlah'));
    ?>
    <?php if ($total_bmi_records === 0): ?>
      <p class="teks-redup" style="font-style: italic; margin-top: auto; margin-bottom: auto;">Belum ada riwayat kalkulasi BMI pengguna.</p>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 14px; margin-top: auto; margin-bottom: auto;">
        <?php foreach ($dist_bmi as $d): 
          $pct = $total_bmi_records > 0 ? round(($d['jumlah'] / $total_bmi_records) * 100) : 0;
        ?>
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.88rem; margin-bottom: 6px;">
              <span class="badge kategori-<?= h($d['kode']) ?>"><?= h($d['nama']) ?></span>
              <span style="color: var(--gelap); font-size: 0.84rem;">
                <b><?= $d['jumlah'] ?></b> pengguna <span class="teks-redup">(<?= $pct ?>%)</span>
              </span>
            </div>
            <div style="height: 10px; background: var(--garis); border-radius: 999px; overflow: hidden;">
              <div style="width: <?= $pct ?>%; height: 100%; background: <?= h($d['warna']) ?>; border-radius: 999px; transition: width 0.4s ease;"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- POLA WAKTU MAKAN PENGGUNA -->
  <div class="kartu" style="padding: 24px;">
    <h3 style="font-size: 1.15rem; margin-bottom: 6px;">Pola Waktu Makan Pengguna</h3>
    <p class="teks-redup" style="font-size: 0.88rem; margin-bottom: 20px;">
      Proporsi waktu makan yang paling aktif dicatat oleh pengguna.
    </p>

    <?php 
    $total_waktu_records = array_sum(array_column($dist_waktu, 'jumlah'));
    $label_waktu = ['pagi' => 'Sarapan (Pagi)', 'siang' => 'Makan Siang', 'malam' => 'Makan Malam', 'camilan' => 'Camilan Sehat'];
    $warna_waktu = ['pagi' => '#2563EB', 'siang' => 'var(--hijau)', 'malam' => '#7C3AED', 'camilan' => '#D97706'];
    ?>
    <?php if ($total_waktu_records === 0): ?>
      <p class="teks-redup" style="font-style: italic; margin-top: auto; margin-bottom: auto;">Belum ada entri catatan makan tercatat.</p>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 14px; margin-top: auto; margin-bottom: auto;">
        <?php foreach ($dist_waktu as $w): 
          $pct = $total_waktu_records > 0 ? round(($w['jumlah'] / $total_waktu_records) * 100) : 0;
          $w_key = $w['waktu_makan'];
        ?>
          <div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.88rem; margin-bottom: 6px;">
              <span class="badge-waktu badge-waktu-<?= h($w_key) ?>">
                <?= h($label_waktu[$w_key] ?? ucfirst($w_key)) ?>
              </span>
              <span style="color: var(--gelap); font-size: 0.84rem;">
                <b><?= number_format($w['jumlah'], 0, ',', '.') ?></b> log <span class="teks-redup">(<?= $pct ?>%)</span>
              </span>
            </div>
            <div style="height: 10px; background: var(--garis); border-radius: 999px; overflow: hidden;">
              <div style="width: <?= $pct ?>%; height: 100%; background: <?= $warna_waktu[$w_key] ?? 'var(--hijau)' ?>; border-radius: 999px; transition: width 0.4s ease;"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- 10 MENU PALING SERING DICATAT -->
<div class="kartu" style="padding: 24px; margin-bottom: 24px;">
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
    <div>
      <h3 style="font-size: 1.15rem; margin: 0 0 4px;">10 Menu Paling Sering Dikonsumsi</h3>
      <p class="teks-redup" style="font-size: 0.88rem; margin: 0;">
        Menu makanan yang paling banyak dipilih pengguna. Jadikan acuan dalam memberikan rekomendasi dan evaluasi nutrisi.
      </p>
    </div>
    <a href="<?= base_url('pakar/menu.php') ?>" class="tombol sekunder kecil">Buka Tinjauan Menu</a>
  </div>

  <?php if (empty($menu_populer)): ?>
    <p class="teks-redup" style="font-style: italic;">Belum ada menu yang dicatat dalam log makanan pengguna.</p>
  <?php else: ?>
    <?php 
    $max_frekuensi = max(array_column($menu_populer, 'frekuensi'));
    ?>
    <div style="display: flex; flex-direction: column; gap: 10px;">
      <?php foreach ($menu_populer as $idx => $m): 
        $pct_bar = $max_frekuensi > 0 ? round(($m['frekuensi'] / $max_frekuensi) * 100) : 0;
      ?>
        <div class="baris-catatan" style="margin: 0; padding: 12px 16px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil); display: flex; align-items: center; gap: 14px;">
          <span style="width: 26px; height: 26px; border-radius: 50%; background: var(--mint); color: #264A38; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0;">
            <?= $idx + 1 ?>
          </span>

          <div style="flex: 1; min-width: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; flex-wrap: wrap; gap: 8px;">
              <div style="display: flex; align-items: center; gap: 8px;">
                <strong style="color: var(--gelap); font-size: 0.92rem;"><?= h($m['nama_menu']) ?></strong>
                <?php if ($m['vegetarian']): ?>
                  <span class="badge" style="background: #E8F5E9; color: #2E7D32; font-size: 0.7rem; padding: 2px 8px;">Vegetarian</span>
                <?php endif; ?>
              </div>
              <div style="display: flex; align-items: center; gap: 10px; font-size: 0.84rem;">
                <span class="badge-waktu badge-waktu-<?= h($m['waktu_makan']) ?>" style="font-size: 0.72rem; padding: 2px 8px;">
                  <?= ucfirst(h($m['waktu_makan'])) ?>
                </span>
                <span class="teks-redup"><?= number_format($m['kalori'], 0, ',', '.') ?> kkal</span>
                <span style="font-weight: 700; color: var(--hijau);"><?= number_format($m['frekuensi'], 0, ',', '.') ?>x dicatat</span>
              </div>
            </div>

            <div style="height: 6px; background: var(--garis); border-radius: 999px; overflow: hidden;">
              <div style="width: <?= $pct_bar ?>%; height: 100%; background: var(--hijau); border-radius: 999px;"></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- SARAN TERAKHIR OLEH PAKAR INI -->
<div class="kartu" style="padding: 24px;">
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
    <div>
      <h3 style="font-size: 1.15rem; margin: 0 0 4px;">Saran Edukasi Nutrisi Terbaru Anda</h3>
      <p class="teks-redup" style="font-size: 0.88rem; margin: 0;">
        Saran yang Anda tulis akan otomatis tampil di dashboard pengguna sesuai kategori BMI mereka.
      </p>
    </div>
    <a href="<?= base_url('pakar/saran.php') ?>" class="tombol sekunder kecil">Kelola Semua Saran</a>
  </div>

  <?php if (empty($saran_terbaru)): ?>
    <div style="padding: 20px; background: var(--latar); border: 1px dashed var(--garis); border-radius: var(--radius-kecil); text-align: center;">
      <p class="teks-redup" style="margin: 0 0 10px; font-size: 0.9rem;">Anda belum membuat catatan saran edukasi untuk pengguna.</p>
      <a href="<?= base_url('pakar/saran.php') ?>" class="tombol kecil">Tulis Saran Pertama</a>
    </div>
  <?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px;">
      <?php foreach ($saran_terbaru as $s): ?>
        <div style="background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil); padding: 16px; display: flex; flex-direction: column; justify-content: space-between;">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 8px;">
              <strong style="color: var(--gelap); font-size: 0.92rem; line-height: 1.4;"><?= h($s['judul']) ?></strong>
              <?php if (!empty($s['user_id'])): ?>
                <span class="badge" style="background: #EDE9FE; color: #5B21B6; font-size: 0.7rem; font-weight: 700; padding: 2px 8px; white-space: nowrap; border: 1px solid #DDD6FE;">
                  Khusus: <?= h($s['nama_target_user'] ?? 'User') ?>
                </span>
              <?php else: ?>
                <span class="badge" style="background: var(--mint); color: #264A38; font-size: 0.7rem; padding: 2px 8px; white-space: nowrap;">
                  <?= ucfirst(h($s['target_kategori'])) ?>
                </span>
              <?php endif; ?>
            </div>
            <p class="teks-redup" style="font-size: 0.84rem; margin: 0 0 12px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
              <?= h($s['isi_saran']) ?>
            </p>
          </div>
          <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; color: var(--teks-redup); border-top: 1px solid var(--garis); padding-top: 8px; margin-top: auto;">
            <span><?= date('d M Y', strtotime($s['created_at'])) ?></span>
            <a href="<?= base_url('pakar/saran.php?edit=' . (int)$s['id']) ?>" style="color: var(--hijau); font-weight: 600; text-decoration: none;">Ubah Saran</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include BASE_DIR . 'includes/footer.php'; ?>
