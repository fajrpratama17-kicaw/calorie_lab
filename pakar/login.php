<?php
// Redirect ke halaman login terpadu
require_once dirname(__DIR__) . '/config/koneksi.php';
header('Location: ' . base_url('login.php'));
exit;
