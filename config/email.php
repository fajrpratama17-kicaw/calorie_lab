<?php
/**
 * Konfigurasi SMTP Email Calorie Lab
 * 
 * Untuk menggunakan Gmail:
 * 1. Aktifkan 'Verifikasi 2 Langkah' pada Akun Google Anda.
 * 2. Buka https://myaccount.google.com/apppasswords
 * 3. Buat Sandi Aplikasi (App Password) baru (misal diberi nama "Calorie Lab").
 * 4. Salin 16 digit sandi aplikasi ke konstanta SMTP_PASS di bawah ini.
 */

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587); // 587 untuk TLS, 465 untuk SSL
define('SMTP_SECURE', 'tls'); // 'tls' atau 'ssl'

// Ganti dengan email Gmail Anda yang bertindak sebagai pengirim resmi sistem
define('SMTP_USER', 'calorielab.official@gmail.com');

// Ganti dengan 16 digit Sandi Aplikasi (App Password) dari Google (bukan password login biasa)
define('SMTP_PASS', 'xxxx xxxx xxxx xxxx');

define('SMTP_FROM_EMAIL', SMTP_USER);
define('SMTP_FROM_NAME', 'Calorie Lab Support');

require_once dirname(__DIR__) . '/includes/PHPMailer/Exception.php';
require_once dirname(__DIR__) . '/includes/PHPMailer/PHPMailer.php';
require_once dirname(__DIR__) . '/includes/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Mengirim email reset kata sandi dengan template HTML Calorie Lab
 *
 * @param string $email_tujuan
 * @param string $nama_penerima
 * @param string $link_reset
 * @return array ['sukses' => bool, 'error' => string]
 */
function kirim_email_reset($email_tujuan, $nama_penerima, $link_reset) {
    // Cek jika konfigurasi masih berupa placeholder default
    if (SMTP_PASS === 'xxxx xxxx xxxx xxxx') {
        return [
            'sukses' => false,
            'is_placeholder' => true,
            'error' => 'Sandi aplikasi (App Password) Google belum dikonfigurasikan di config/email.php.'
        ];
    }

    $mail = new PHPMailer(true);

    try {
        // Konfigurasi Server SMTP
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = str_replace(' ', '', SMTP_PASS); // Hapus spasi jika ada
        $mail->SMTPSecure = (SMTP_SECURE === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // Pengirim & Penerima
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email_tujuan, $nama_penerima);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        // Konten Email HTML
        $mail->isHTML(true);
        $mail->Subject = 'Permintaan Atur Ulang Kata Sandi - Calorie Lab';

        // Template HTML Email Responsif & Elegan
        $mail->Body = '
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
              <h2 style="margin: 0; font-size: 20px; font-weight: 700;">Atur Ulang Kata Sandi Anda</h2>
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

        $mail->AltBody = "Halo " . $nama_penerima . ",\n\nKami menerima permintaan untuk mengatur ulang kata sandi akun Calorie Lab Anda.\n\nBuka tautan berikut untuk membuat kata sandi baru (berlaku 30 menit):\n" . $link_reset . "\n\nJika Anda tidak mengajukan ini, abaikan pesan ini.";

        $mail->send();
        return ['sukses' => true];
    } catch (Exception $e) {
        return [
            'sukses' => false,
            'is_placeholder' => false,
            'error' => $mail->ErrorInfo
        ];
    }
}
