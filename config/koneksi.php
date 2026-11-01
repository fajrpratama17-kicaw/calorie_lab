<?php
date_default_timezone_set('Asia/Jakarta');

// Konfigurasi Database
$host = 'localhost';
$db   = 'calorie_lab';
$user = 'root';   
$pass = '';       

if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    session_start();
}

// Konstanta Path Direktori Proyek
if (!defined('BASE_DIR')) {
    define('BASE_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// Deteksi Otomatis Base URL Proyek (Bekerja di localhost/calorie_lab/ maupun di root domain hosting)
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host_name = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Normalisasi script path
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Hilangkan subfolder modul admin, pakar, config, includes jika script dipanggil dari dalamnya
    $root_path = preg_replace('#/(admin|pakar|config|includes|assets.*)$#i', '', $script_dir);
    $root_path = rtrim($root_path, '/') . '/';
    
    define('BASE_URL', $protocol . $host_name . $root_path);
}

function base_url($path = '') {
    return BASE_URL . ltrim((string)$path, '/');
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Koneksi database gagal. Periksa pengaturan koneksi di config/koneksi.php');
}

function h($teks) {
    return htmlspecialchars((string)$teks, ENT_QUOTES, 'UTF-8');
}

function wajib_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

function wajib_admin() {
    wajib_login();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: ' . base_url('dashboard.php'));
        exit;
    }
}

function wajib_pakar() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
    if (!in_array($_SESSION['role'] ?? '', ['pakar', 'admin'], true)) {
        header('Location: ' . base_url('dashboard.php'));
        exit;
    }
    // Untuk role pakar, verifikasi status_akun dari DB
    if ($_SESSION['role'] === 'pakar') {
        try {
            $st = $pdo->prepare("SELECT status_akun, catatan_admin FROM users WHERE id = ?");
            $st->execute([$_SESSION['user_id']]);
            $u = $st->fetch();
            if (!$u || $u['status_akun'] !== 'aktif') {
                session_destroy();
                $pesan = 'Akun Anda belum aktif atau akses telah dicabut. Hubungi administrator.';
                session_start();
                $_SESSION['pesan'] = $pesan;
                header('Location: ' . base_url('login.php'));
                exit;
            }
        } catch (Exception $e) {
            // Kolom belum ada — lewatkan pengecekan ini
        }
    }
}

function csrf_token($kunci = 'csrf_token') {
    if (empty($_SESSION[$kunci])) {
        $_SESSION[$kunci] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$kunci];
}

function csrf_valid($token, $kunci = 'csrf_token') {
    return is_string($token) && !empty($_SESSION[$kunci]) && hash_equals($_SESSION[$kunci], $token);
}
