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
// $host = "localhost";
// $dbname = "mydb";   // ← 現在のDB名
// $dbuser = "general_user";   // XAMPP/MAMP のデフォルトユーザー
// $dbpass = "general_password";       // パスワードなし（デフォルト）

// try {
//     $pdo = new PDO(
//         "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
//         $dbuser,
//         $dbpass,
//         [
//             PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
//         ]
//     );
// } catch (PDOException $e) {
//     exit("❌ DB接続エラー: " . $e->getMessage());
// }

// ================================
// 🗄️ データベース接続設定（本番環境）
// ================================
    // Cloud Run (Cloud SQL) 用の設定
    // 値は Cloud Run の環境変数から取得
// $host = "/cloudsql/moonlit-academy-477401-t5:us-central1:myapp-sql";
// $dbname = "mydb";   // DB名
// $dbuser = "dev_user";   // CloudSQL上のユーザー
// $dbpass = "nv1a_NV1A";  
$host = getenv('CLOUD_SQL_CONNECTION_NAME');
$dbname = getenv('DB_NAME');
$dbuser = getenv('DB_USER');
$dbpass = getenv('DB_PASS');

try {
    $pdo = new PDO(
        "mysql:unix_socket=$host;dbname=$dbname;charset=utf8mb4",
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
// define('DSN', "mysql:host={$host};dbname={$dbname};charset=utf8mb4");
define('DSN', "mysql:unix_socket={$host};dbname={$dbname};charset=utf8mb4");

// ================================
// ✉️ メール設定（PHPMailer 用）
// ================================
define('MAIL_HOST', 'smtp.gmail.com');
// define('MAIL_USERNAME', 'debtapp005@gmail.com');
// define('MAIL_PASSWORD', 'anbi lvnm cykn vnsd'); // Gmailの「アプリパスワード」
// define('MAIL_FROM', 'debtapp005@gmail.com');
define('MAIL_USERNAME', getenv('MAIL_USERNAME')); // 環境変数から取得
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD')); 
define('MAIL_FROM', getenv('MAIL_FROM'));
define('MAIL_FROM_NAME', 'DebtApp 通知');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls');

// ================================
// 🔐 Google OAuth 設定
// ================================
// define('GOOGLE_CLIENT_ID', '887906658821-1spgtqg6mu506eslavhjpbntc3hb9bar.apps.googleusercontent.com');
// define('GOOGLE_CLIENT_SECRET', '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');
// define('GOOGLE_REDIRECT_URI', 'https://debtapp-565547399529.asia-northeast1.run.app/login/google_callback.php');
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI'));
// Google API 認証URL
$google_auth_endpoint = "https://accounts.google.com/o/oauth2/v2/auth";
$google_token_endpoint = "https://oauth2.googleapis.com/token";
$google_userinfo_endpoint = "https://www.googleapis.com/oauth2/v2/userinfo";

require_once 'SessionHandler.php';

// セッションがまだ開始されていない場合のみ設定を行う
if (session_status() === PHP_SESSION_NONE) {
    
    // DB接続 ($pdo) が存在することを確認
    if (isset($pdo)) {
        $handler = new DatabaseSessionHandler($pdo);
        session_set_save_handler($handler, true);
    }

    $timeout = 1800; // 30分
    ini_set('session.gc_maxlifetime', $timeout);

    // クッキーの設定
    session_set_cookie_params([
        'lifetime' => $timeout,
        'path' => '/',
        'secure' => true,      // Cloud RunはHTTPSなのでtrue
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// タイムアウト判定
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    // ここでリダイレクトする場合、すでにHTMLが出力されていないことが条件
    if (!headers_sent()) {
        header("Location: /login/google_login.php?timeout=1");
        exit;
    }
}
$_SESSION['last_activity'] = time();

?>












