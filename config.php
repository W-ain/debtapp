<?php
// ================================
// ⚙️ 開発用設定（エラー表示 ON）
// ================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ================================
// 🗄️ データベース接続設定（ローカル）
// ================================
$host = "localhost";
$dbname = "mydb";   // ← 現在のDB名
$dbuser = "general_user";   // XAMPP/MAMP のデフォルトユーザー
$dbpass = "general_password";       // パスワードなし（デフォルト）

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    exit("❌ DB接続エラー: " . $e->getMessage());
}

// ================================
// 🔑 定数としても定義（他ファイルで使用可能）
// ================================
define('DB_HOST', $host);
define('DB_NAME', $dbname);
define('DB_USER', $dbuser);
define('DB_PASS', $dbpass);
define('DSN', "mysql:host={$host};dbname={$dbname};charset=utf8mb4");

// ================================
// ✉️ メール設定（PHPMailer 用）
// ================================
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_USERNAME', 'debtapp005@gmail.com');
define('MAIL_PASSWORD', 'anbi lvnm cykn vnsd'); // Gmailの「アプリパスワード」
define('MAIL_FROM', 'debtapp005@gmail.com');
define('MAIL_FROM_NAME', 'DebtApp 通知');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls');

// ================================
// 🔐 Google OAuth 設定
// ================================
define('GOOGLE_CLIENT_ID', '887906658821-1spgtqg6mu506eslavhjpbntc3hb9bar.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI', 'http://localhost/debtapp/login/google_callback.php');

// Google API 認証URL
$google_auth_endpoint = "https://accounts.google.com/o/oauth2/v2/auth";
$google_token_endpoint = "https://oauth2.googleapis.com/token";
$google_userinfo_endpoint = "https://www.googleapis.com/oauth2/v2/userinfo";
?>