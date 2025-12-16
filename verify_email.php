<?php
session_start();
require_once 'config.php';
require_once 'vendor/autoload.php';

use Google\Client;

// ----------------------------------------------------------------------
// 1. 初回アクセス時のリダイレクト処理 (クエリ文字列を除去)
// ----------------------------------------------------------------------
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // トークンをセッションに一時保存
    $_SESSION['verification_token'] = $token;
    
    // クエリ文字列を含まないURL（現在のファイル自身）にリダイレクト
    // header('Location: ' . $_SERVER['PHP_SELF']) は現在のファイル名にリダイレクトする
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
// ----------------------------------------------------------------------


// ----------------------------------------------------------------------
// 2. メイン処理開始 (セッションからトークンを取得)
// ----------------------------------------------------------------------
$token = $_SESSION['verification_token'] ?? null;

if (!$token) {
    // リダイレクト後、セッションにトークンがない場合はエラー
    // exit("エラー: 認証情報が不足しています。リンクが不正か、セッションが切れました。");
    exit("
        <script>
            alert('エラー: 認証情報が不足しています。リンクが不正か、セッションが切れました。\\n\\nメールのリンクから再度お試しください。');
            window.close();  
        </script>
    ");
}

try {
    // 貸付情報 + 貸主名を取得
    $sql = "SELECT d.*, u.user_name AS lender_name 
             FROM debts d
             JOIN users u ON d.creditor_id = u.user_id 
             WHERE d.token = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$token]);
    $debt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$debt) {
        // exit("無効なトークンです。");
        exit("
            <script>
                alert('無効なトークンです。\\n\\nすでに承認済み、または期限切れの可能性があります。\\n貸主に確認してください。');
                window.close();  
            </script>
        ");
    }
} catch (PDOException $e) {
    // exit("DBエラー: " . $e->getMessage());
    error_log("DBエラー: " . $e->getMessage());
    exit("
        <script>
            alert('システムエラーが発生しました。\\n\\n少し時間をおいて再度お試しください。');
            window.close();
        </script>
    ");
}

// Google OAuth 設定 (変更なし)
$client = new Client();
$client->setClientId('887906658821-1spgtqg6mu506eslavhjpbntc3hb9bar.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('https://debtapp-565547399529.asia-northeast1.run.app/google_callback2.php'); 
$client->addScope('email');
$client->addScope('profile');

// 認証URL生成
$authUrl = $client->createAuthUrl();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>貸付確認ページ</title>
<style>
/* ★修正: スマホで見やすくするためのスタイル調整 */
* {
    box-sizing: border-box; /* 幅の計算を楽にする設定 */
}
/* スタイルは省略 */
body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    background: #f7f9fc;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
    padding: 20px; /* スマホで端っこがくっつかないように余白を追加 */
}

.card {
    background: #ffffff;
    /* padding: 30px 40px; */
    padding: 30px 20px; /* スマホ用に左右の余白を少し減らす */
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    /* width: 420px;  */
    /* 幅の固定をやめて、最大幅を指定する */
    width: 100%;
    max-width: 420px;
    text-align: center;
}

h2 {
    font-size: 22px;
    color: #333;
    margin-bottom: 30px;
    font-weight: 600;
}

.info-card {
    background: #f4f6fa;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    text-align: left;
    font-size: 15px;
}

.info-item {
    display: flex;
    justify-content: space-between; /* ラベルと値を左右に離す */
    margin-bottom: 12px;
    color: #555;
    align-items: center;
}

.label {
    font-weight: 500;
    color: #333;
    /* width: 100px;
    flex-shrink: 0; */
    /* 固定幅をやめて、最低限の幅を確保 */
    min-width: 80px; 
    margin-right: 10px;
}

.value {
    font-weight: 400;
    /* flex-grow: 1; */
    text-align: right; /* スマホで見やすいよう右寄せに */
    word-break: break-all; /* 長いメールアドレスなどがはみ出ないように改行 */
}


.button {
    display: block;
    width: 100%;
    background: #4285f4; 
    color: white;
    text-align: center;
    /* padding: 14px 18px; */
    padding: 14px 0; /* 左右パディングを消して中央揃えを確実に */
    border-radius: 8px;
    font-weight: bold;
    font-size: 16px;
    margin-top: 25px;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.3s;
    /* box-sizing: border-box;
    margin-left: auto;
    margin-right: auto; */
}

.button:hover {
    background: #3c78d8;
}

</style>
</head>
<body>
<div class="card">
    <h2>貸付内容の確認</h2>
    
    <div class="info-card">
        <div class="info-item">
            <span class="label">貸主:</span>
            <span class="value"><?= htmlspecialchars($debt['lender_name'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-item">
            <span class="label">借主:</span>
            <span class="value"><?= htmlspecialchars($debt['debtor_name'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-item" style="font-size: 18px; margin-top: 15px; margin-bottom: 0;">
            <span class="label" style="font-weight: 600; color: #000;">金額:</span>
            <span class="value" style="font-weight: 600; color: #000;">¥<?= number_format($debt['money']) ?></span>
        </div>
        <div class="info-item" style="margin-top: 10px; margin-bottom: 0;">
            <span class="label">返済期日:</span>
            <span class="value"><?= htmlspecialchars($debt['date'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <a href="<?= htmlspecialchars($authUrl) ?>" class="button">Googleで認証する</a>
</div>
</body>

</html>


