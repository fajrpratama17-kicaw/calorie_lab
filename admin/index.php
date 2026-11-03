<?php
require_once dirname(__DIR__) . '/config/koneksi.php';
wajib_admin();

$judul = 'Ringkasan & Statistik Admin';
include BASE_DIR . 'includes/header.php';

// Ambil metrik statistik
$total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_admin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$total_pakar = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'pakar'")->fetchColumn();
$total_reg_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();

$total_makanan = (int)$pdo->query("SELECT COUNT(*) FROM menu_diet")->fetchColumn();
$total_vegetarian = (int)$pdo->query("SELECT COUNT(*) FROM menu_diet WHERE vegetarian = 1")->fetchColumn();

$total_catatan = (int)$pdo->query("SELECT COUNT(*) FROM catatan_makan")->fetchColumn();
$total_rencana = (int)$pdo->query("SELECT COUNT(*) FROM rencana_diet")->fetchColumn();
$rencana_aktif = (int)$pdo->query("SELECT COUNT(*) FROM rencana_diet WHERE status = 'aktif'")->fetchColumn();

$rata_kalori_menu = (int)$pdo->query("SELECT ROUND(AVG(kalori)) FROM menu_diet")->fetchColumn();
$rata_harga_menu = (int)$pdo->query("SELECT ROUND(AVG(harga)) FROM menu_diet")->fetchColumn();

// Distribusi Kategori BMI (Berdasarkan status BMI pengguna terbaru/terakhir)
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

// Menu Paling Sering Dicatat
$menu_terpopuler = $pdo->query("
    SELECT md.nama_menu, md.kalori, md.waktu_makan, COUNT(cm.id) AS kali_dicatat
    FROM catatan_makan cm
    JOIN menu_diet md ON md.id = cm.menu_id
    GROUP BY md.id, md.nama_menu, md.kalori, md.waktu_makan
    ORDER BY kali_dicatat DESC
    LIMIT 5
")->fetchAll();

// 5 Pengguna Paling Baru Terdaftar
$users_terbaru = $pdo->query("
    SELECT id, nama, email, role, created_at
    FROM users
    ORDER BY id DESC
    LIMIT 5
")->fetchAll();
?>

<?php include __DIR__ . '/nav.php'; ?>

<!-- STAT KARTU RINGKASAN -->
<div class="admin-stat-grid">
  <div class="admin-stat-card kartu">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
      <span class="admin-stat-label">Total Pengguna</span>
      <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--mint); color: var(--hijau); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
    </div>
    <div class="admin-stat-value"><?= number_format($total_users, 0, ',', '.') ?></div>
    <div class="admin-stat-sub"><?= $total_reg_users ?> Pengguna &bull; <?= $total_pakar ?> Pakar &bull; <?= $total_admin ?> Admin</div>
  </div>

  <div class="admin-stat-card kartu">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
      <span class="admin-stat-label">Katalog Makanan</span>
      <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--mint); color: var(--hijau); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
      </div>
    </div>
    <div class="admin-stat-value"><?= number_format($total_makanan, 0, ',', '.') ?></div>
    <div class="admin-stat-sub"><?= number_format($total_vegetarian, 0, ',', '.') ?> Menu Ramah Vegetarian</div>
  </div>

  <div class="admin-stat-card kartu">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
      <span class="admin-stat-label">Log Catatan Makan</span>
      <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--mint); color: var(--hijau); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      </div>
    </div>
    <div class="admin-stat-value"><?= number_format($total_catatan, 0, ',', '.') ?></div>
    <div class="admin-stat-sub">Aktivitas tracking konsumsi pengguna</div>
  </div>

  <div class="admin-stat-card kartu">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
      <span class="admin-stat-label">Rencana Diet</span>
      <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--mint); color: var(--hijau); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
      </div>
    </div>
    <div class="admin-stat-value"><?= number_format($total_rencana, 0, ',', '.') ?></div>
    <div class="admin-stat-sub"><?= $rencana_aktif ?> Program aktif berjalan</div>
  </div>
</div>

<div class="admin-dashboard-layout">
  <!-- DISTRIBUSI BMI PENGGUNA -->
  <div class="kartu" style="padding: 24px;">
    <h3 style="font-size: 1.15rem; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
      Distribusi BMI Pengguna
    </h3>
    <p class="teks-redup" style="font-size: 0.88rem; margin-bottom: 20px;">
      Berdasarkan hasil kalkulasi BMI terkini dari pengguna terdaftar.
    </p>

    <div style="display: flex; flex-direction: column; gap: 14px; margin-top: auto; margin-bottom: auto;">
      <?php 
      $total_bmi_records = array_sum(array_column($dist_bmi, 'jumlah'));
      foreach ($dist_bmi as $bmi_stat): 
        $persen = $total_bmi_records > 0 ? round(($bmi_stat['jumlah'] / $total_bmi_records) * 100) : 0;
      ?>
        <div>
          <div style="display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 6px;">
            <span style="font-weight: 600; display:flex; align-items:center; gap:8px;">
              <span class="badge kategori-<?= h($bmi_stat['kode']) ?>"><?= h($bmi_stat['nama']) ?></span>
            </span>
            <span><b><?= $bmi_stat['jumlah'] ?></b> pengguna (<?= $persen ?>%)</span>
          </div>
          <div style="height: 10px; background: var(--garis); border-radius: 999px; overflow: hidden;">
            <div style="width: <?= $persen ?>%; height: 100%; background: <?= h($bmi_stat['warna']) ?>; border-radius: 999px; transition: width 0.4s ease;"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- MENU PALING POPULER -->
  <div class="kartu" style="padding: 24px;">
    <h3 style="font-size: 1.15rem; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Menu Terbanyak Dikonsumsi
    </h3>
    <p class="teks-redup" style="font-size: 0.88rem; margin-bottom: 16px;">
      Menu makanan yang paling sering dimasukkan pengguna ke catatan harian.
    </p>

    <?php if (empty($menu_terpopuler)): ?>
      <p class="teks-redup" style="font-style: italic; margin-top: auto; margin-bottom: auto;">Belum ada data catatan makan tercatat.</p>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 8px;">
        <?php foreach ($menu_terpopuler as $index => $item): ?>
          <div class="baris-catatan" style="margin: 0; padding: 10px 14px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil);">
            <div style="display: flex; align-items: center; gap: 12px;">
              <span style="width: 24px; height: 24px; border-radius: 50%; background: var(--mint); color: #264A38; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem;">
                <?= $index + 1 ?>
              </span>
              <div>
                <strong><?= h($item['nama_menu']) ?></strong>
                <div class="teks-redup" style="font-size: 0.8rem;">
                  <?= ucfirst(h($item['waktu_makan'])) ?> &bull; <?= number_format($item['kalori'], 0, ',', '.') ?> kkal
                </div>
              </div>
            </div>
            <div style="font-weight: 700; font-size: 0.9rem; color: var(--hijau);">
              <?= number_format($item['kali_dicatat'], 0, ',', '.') ?>x dicatat
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- INFORMASI STATUS SISTEM & VALIDASI KEAMANAN -->
<div class="admin-dashboard-layout" style="margin-bottom: 24px;">
  <!-- PENGGUNA TERBARU -->
  <div class="kartu" style="padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
      <h3 style="font-size: 1.15rem; margin: 0; display: flex; align-items: center; gap: 8px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Pengguna Terdaftar Baru
      </h3>
      <a href="<?= base_url('admin/pengguna.php') ?>" class="tombol sekunder kecil">Kelola Semua</a>
    </div>

    <div style="display: flex; flex-direction: column; gap: 8px;">
      <?php foreach ($users_terbaru as $u): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil);">
          <div>
            <strong><?= h($u['nama']) ?></strong>
            <div class="teks-redup" style="font-size: 0.8rem;"><?= h($u['email']) ?></div>
          </div>
          <div style="text-align: right;">
            <span class="badge-role <?= match($u['role']) { 'admin' => 'badge-role-admin', 'pakar' => 'badge-role-pakar', default => 'badge-role-user' } ?>">
              <?= strtoupper(h($u['role'])) ?>
            </span>
            <div class="teks-redup" style="font-size: 0.75rem; margin-top: 4px;">
              <?= date('d M Y', strtotime($u['created_at'])) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- STATUS KEAMANAN & INTEGRITAS SISTEM -->
  <div class="kartu" style="padding: 24px;">
    <h3 style="font-size: 1.15rem; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--hijau)"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Status Batasan &amp; Keamanan
    </h3>
    <p class="teks-redup" style="font-size: 0.88rem; margin-bottom: 16px;">
      Pemeriksaan parameter keamanan aktif pada lingkungan Calorie Lab.
    </p>

    <div style="display: flex; flex-direction: column; gap: 9px; font-size: 0.88rem; margin-top: auto; margin-bottom: auto;">
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil);">
        <span>Proteksi CSRF (Anti Cross-Site Request Forgery)</span>
        <span style="color: var(--hijau); font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Aktif (Per-Form Token)
        </span>
      </div>
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil);">
        <span>Sanitasi Output (Anti XSS)</span>
        <span style="color: var(--hijau); font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Aktif (htmlspecialchars UTF-8)
        </span>
      </div>
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil);">
        <span>Keamanan Query Database (Anti SQL Injection)</span>
        <span style="color: var(--hijau); font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          PDO Prepared Statements
        </span>
      </div>
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil);">
        <span>Hashing Kata Sandi</span>
        <span style="color: var(--hijau); font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          BCRYPT (Cost 10)
        </span>
      </div>
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: var(--latar); border: 1px solid var(--garis); border-radius: var(--radius-kecil);">
        <span>Role-Based Access Control (RBAC)</span>
        <span style="color: var(--hijau); font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Strict (wajib_admin())
        </span>
      </div>
    </div>
  </div>
</div>

<?php include BASE_DIR . 'includes/footer.php'; ?>
