<?php
require_once '../config.php';

// Googleから返されたcodeを取得
if (!isset($_GET['code'])) {
    // exit('Error: No code provided.');
    error_log("Error: No code provided.");
    exit("
        <script>
            alert('ログイン処理が中断されました。\\n\\n再度ログインしてください。');
            window.location.href = '/login/google_login.html';
        </script>
    ");
}

// --- 認証コードを使ってアクセストークン取得 (変更なし) ---
$token_data = [
    'code' => $_GET['code'],
    'client_id' => '887906658821-1spgtqg6mu506eslavhjpbntc3hb9bar.apps.googleusercontent.com',
    'client_secret' => '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',
    // 'redirect_uri' => 'http://localhost/debtapp/login/google_callback.php',
    'redirect_uri' => 'https://debtapp-565547399529.asia-northeast1.run.app/login/google_callback.php',
    'grant_type' => 'authorization_code'
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$token = json_decode($response, true);

if (!isset($token['access_token'])) {
    // exit('Error: Failed to get access token.');
    error_log("Error: Failed to get access token.");
    exit("
        <script>
            alert('システムエラーにより、Googleログインが完了しませんでした。\\n\\n少し時間をおいて再度お試しください。');
            window.location.href = '/login/google_login.html';
        </script>
    ");
}

// --- Googleユーザー情報取得 (変更なし) ---
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo?access_token='.$token['access_token']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userinfo = json_decode(curl_exec($ch), true);
curl_close($ch);

// データベースへ接続
// $pdo は config.php にあるものを使用
$email = $userinfo['email'];
$name  = $userinfo['name'];

// 既存ユーザーをチェック
// 【修正点 1/3】 id -> user_id に変更
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

// いなければユーザー登録
if (!$user) {
    // 【修正点 2/3】 name -> user_name に変更。is_verifiedはテーブルにないので削除。
    $stmt = $pdo->prepare("INSERT INTO users (user_name, email) VALUES (?, ?)");
    $stmt->execute([$name, $email]);
    $user_id = $pdo->lastInsertId();
} else {
    // 【修正点 3/3】 $user['id'] -> $user['user_id'] に変更
    $user_id = $user['user_id'];
}

// ログインセッション作成 (変更なし)
$_SESSION['user_id'] = $user_id;
$_SESSION['email']   = $email;
$_SESSION['name']    = $name;

// ホーム画面へリダイレクト (変更なし)
header("Location: /home/home.php");

exit;







