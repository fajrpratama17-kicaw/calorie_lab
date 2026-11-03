<?php
/**
 * Konfigurasi Pengiriman Email Calorie Lab via Brevo HTTPS REST API
 * 
 * Bekerja 100% di localhost maupun di hosting gratis seperti InfinityFree
 * (karena menggunakan protokol HTTPS port 443 yang tidak pernah diblokir).
 */

// Muat kredensial privat jika ada (misal di lokal atau di server hosting)
if (file_exists(__DIR__ . '/secret.php')) {
    require_once __DIR__ . '/secret.php';
}

// Fallback aman untuk repository publik (jika secret.php tidak disertakan)
if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', 'MASUKKAN_BREVO_API_KEY_DI_SINI');
}
if (!defined('BREVO_SENDER_EMAIL')) {
    define('BREVO_SENDER_EMAIL', 'admincalorielab@gmail.com');
}
if (!defined('BREVO_SENDER_NAME')) {
    define('BREVO_SENDER_NAME', 'Calorie Lab Support');
}

/**
 * Mengirim email reset kata sandi menggunakan Brevo REST API v3
 *
 * @param string $email_tujuan
 * @param string $nama_penerima
 * @param string $link_reset
 * @return array ['sukses' => bool, 'error' => string]
 */
function kirim_email_reset($email_tujuan, $nama_penerima, $link_reset) {
    if (empty(BREVO_API_KEY) || strpos(BREVO_API_KEY, 'xkeysib-') !== 0) {
        return [
            'sukses' => false,
            'error' => 'API Key Brevo belum dikonfigurasikan dengan benar di config/email.php.'
        ];
    }

    $subject = 'Permintaan Atur Ulang Kata Sandi - Calorie Lab';

    // Template HTML Email Responsif & Elegan
    $htmlContent = '
    <!DOCTYPE html>
    <html lang="id">
    <head>
      <meta charset="UTF-8">
      <style>
        body { margin: 0; padding: 0; background-color: #F8FAF8; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #2D3748; }
        .email-container { max-width: 560px; margin: 30px auto; background: #ffffff; border-radius: 18px; border: 1px solid #E2E8F0; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
        .header-banner { background: #3A6953; padding: 32px 28px; text-align: center; color: #ffffff; }
        .logo-pill { display: inline-block; background: #ffffff; color: #3A6953; font-weight: 800; font-size: 16px; padding: 6px 14px; border-radius: 999px; margin-bottom: 12px; }
        .email-content { padding: 32px 28px; }
        .btn-reset { display: inline-block; background: #3A6953; color: #ffffff !important; font-weight: 700; font-size: 15px; text-decoration: none; padding: 13px 28px; border-radius: 12px; margin: 24px 0; }
        .token-box { background: #F7FAFC; border: 1px dashed #CBD5E0; border-radius: 10px; padding: 12px; font-size: 12px; word-break: break-all; color: #718096; margin-top: 16px; }
        .email-footer { background: #F7FAFC; padding: 20px 28px; text-align: center; font-size: 12px; color: #A0AEC0; border-top: 1px solid #EDF2F7; }
      </style>
    </head>
    <body>
      <div class="email-container">
        <div class="header-banner">
          <div class="logo-pill">CL Calorie Lab</div>
          <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">Atur Ulang Kata Sandi Anda</h2>
        </div>
        <div class="email-content">
          <p style="font-size: 15px; margin-top: 0;">Halo, <strong>' . htmlspecialchars($nama_penerima) . '</strong>!</p>
          <p style="font-size: 14px; line-height: 1.6; color: #4A5568;">
            Kami menerima permintaan untuk mengatur ulang kata sandi akun Calorie Lab Anda. Klik tombol di bawah ini untuk membuat kata sandi baru:
          </p>
          <div style="text-align: center;">
            <a href="' . $link_reset . '" class="btn-reset" target="_blank">Atur Ulang Kata Sandi Saya</a>
          </div>
          <p style="font-size: 13px; color: #718096; line-height: 1.5;">
            Tautan ini berlaku selama <strong>30 menit</strong>. Jika Anda tidak merasa mengajukan permintaan ini, silakan abaikan email ini dan akun Anda akan tetap aman.
          </p>
          <div class="token-box">
            Jika tombol di atas tidak dapat diklik, salin tautan berikut ke peramban Anda:<br>
            <a href="' . $link_reset . '" style="color: #3A6953;">' . $link_reset . '</a>
          </div>
        </div>
        <div class="email-footer">
          &copy; ' . date('Y') . ' Calorie Lab &middot; Platform Nutrisi & Gaya Hidup Sehat
        </div>
      </div>
    </body>
    </html>';

    $textContent = "Halo " . $nama_penerima . ",\n\nKami menerima permintaan untuk mengatur ulang kata sandi akun Calorie Lab Anda.\n\nBuka tautan berikut untuk membuat kata sandi baru (berlaku 30 menit):\n" . $link_reset . "\n\nJika Anda tidak mengajukan ini, abaikan pesan ini.";

    $payload = [
        'sender' => [
            'name'  => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL
        ],
        'to' => [
            [
                'email' => $email_tujuan,
                'name'  => $nama_penerima
            ]
        ],
        'subject'     => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => $textContent
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false // Menjamin kompatibilitas di localhost Windows XAMPP
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'sukses' => false,
            'error'  => 'Gagal koneksi HTTP: ' . $curlErr
        ];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['sukses' => true];
    } else {
        $resData = json_decode($response, true);
        $errMsg  = $resData['message'] ?? ('HTTP Error ' . $httpCode . ': ' . $response);
        return [
            'sukses' => false,
            'error'  => $errMsg
        ];
    }
}
