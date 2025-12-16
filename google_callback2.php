<?php
session_start();

require_once 'vendor/autoload.php';
require_once 'config.php';

use Google\Client;
use Google\Service\Oauth2;

// ----------------------------------------------------------------------
// Google認証設定
// ----------------------------------------------------------------------
$client = new Client();
$client->setClientId('887906658821-1spgtqg6mu506eslavhjpbntc3hb9bar.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri(
    'https://debtapp-565547399529.asia-northeast1.run.app/google_callback2.php'
);

$url_has_token = isset($_GET['token']);
$url_has_code  = isset($_GET['code']);

// ----------------------------------------------------------------------
// 1. token付き初回アクセス → Google認証開始
// ----------------------------------------------------------------------
if ($url_has_token && !$url_has_code) {
    $verified_token = $_GET['token'];

    // トークンをセッションに一時保存
    $_SESSION['verification_token'] = $verified_token;

    // Google認証へリダイレクト
    $auth_url = $client->createAuthUrl(['email', 'profile']);
    header('Location: ' . $auth_url);
    exit;
}

// ----------------------------------------------------------------------
// 2. Googleからのリダイレクト（code取得）
// ----------------------------------------------------------------------
if ($url_has_code) {
    $_SESSION['google_auth_code'] = $_GET['code'];

    // codeをURLから除去
    header(
        'Location: https://debtapp-565547399529.asia-northeast1.run.app/google_callback2.php'
    );
    exit;
}

// ----------------------------------------------------------------------
// 3. セッションから token / code を取得
// ----------------------------------------------------------------------
$verified_token = $_SESSION['verification_token'] ?? null;
$auth_code      = $_SESSION['google_auth_code'] ?? null;

if (!$verified_token || !$auth_code) {
    unset($_SESSION['verification_token'], $_SESSION['google_auth_code']);
    exit('エラー: 認証情報が不足しています。最初からやり直してください。');
}

// ----------------------------------------------------------------------
// Google認証処理
// ----------------------------------------------------------------------
$tokenData = $client->fetchAccessTokenWithAuthCode($auth_code);

if (isset($tokenData['error'])) {
    unset($_SESSION['verification_token'], $_SESSION['google_auth_code']);
    exit('Google認証エラー: ' . htmlspecialchars($tokenData['error']));
}

$client->setAccessToken($tokenData['access_token']);

$oauth    = new Oauth2($client);
$userInfo = $oauth->userinfo->get();

$email = $userInfo->email;
$name  = $userInfo->name;

// 認証完了後はセッションクリア
unset($_SESSION['verification_token'], $_SESSION['google_auth_code']);

// ----------------------------------------------------------------------
// DB確認処理
// ----------------------------------------------------------------------
try {
    $stmt = $pdo->prepare(
        "
        SELECT
            d.*,
            u.user_name AS lender_name
        FROM debts d
        JOIN users u ON d.creditor_id = u.user_id
        WHERE d.token = ?
        "
    );
    $stmt->execute([$verified_token]);
    $debt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$debt) {
        exit('該当する貸付情報が見つかりません。');
    }

    if ($debt['debtor_email'] !== $email) {
        exit(
            '認証されたGoogleアカウントのメールアドレスが、'
            . '貸付情報に登録されたメールアドレスと一致しません。'
        );
    }
} catch (PDOException $e) {
    exit('DBエラー: ' . $e->getMessage());
}

// ----------------------------------------------------------------------
// 証拠画像HTML生成
// ----------------------------------------------------------------------
$image_html = '';
$proof_image_path_db = $debt['proof_image_path'] ?? null;

if ($proof_image_path_db) {
    $image_src = str_replace('../', '', $proof_image_path_db);

    $image_html = '
        <div class="info-item image-item">
            <span class="label">証拠画像:</span>
            <div class="proof-image-wrapper">
                <img
                    src="' . htmlspecialchars($image_src) . '"
                    alt="証拠画像"
                    class="proof-image"
                />
            </div>
        </div>
    ';
}
?>
