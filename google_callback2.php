<?php
require_once 'vendor/autoload.php';
require_once 'config.php';

use Google\Client;
use Google\Service\Oauth2;

// Google認証設定
$client = new Client();
$client->setClientId('887906658821-1spgtqg6mu506eslavhjpbntc3hb9bar.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('https://debtapp-565547399529.asia-northeast1.run.app/google_callback2.php');

$url_has_token = isset($_GET['token']);
$url_has_code  = isset($_GET['code']);

if ($url_has_token && !$url_has_code) {
    $verified_token = $_GET['token'];

    // トークンをセッションに一時保存
    $_SESSION['verification_token'] = $verified_token;

    // トークンをセッションに保存した後、Google認証を開始する
    $auth_url = $client->createAuthUrl(['email', 'profile']);
    header("Location: " . $auth_url);
    exit;
}

if ($url_has_code) {
    // codeをセッションに一時保存
    $_SESSION['google_auth_code'] = $_GET['code'];

    // クリーンなURL（クエリなし）にリダイレクトし、ブラウザのURLからcodeを削除
    header('Location: https://debtapp-565547399529.asia-northeast1.run.app/google_callback2.php');
    exit;
}

// ----------------------------------------------------------------------
// 3. 最終処理段階: セッションから code と token を取得し、処理を実行
// ----------------------------------------------------------------------
$verified_token = $_SESSION['verification_token'] ?? null;
$auth_code      = $_SESSION['google_auth_code'] ?? null;

// トークンもコードもなければエラー
if (!$verified_token || !$auth_code) {
    // 処理が完了したらセッションのキーをクリアしておく（安全のため）
    unset($_SESSION['verification_token']);
    unset($_SESSION['google_auth_code']);
    exit("
        <script>
            alert('エラー: 認証情報が不足しています。\\n最初からやり直してください。');
            window.close();  
        </script>
    ");
}

// セッションから認証コードを使ってアクセストークンを取得
$tokenData = $client->fetchAccessTokenWithAuthCode($auth_code);
if (isset($tokenData['error'])) {
    // エラー時はセッションをクリア
    unset($_SESSION['verification_token']);
    unset($_SESSION['google_auth_code']);
    error_log('Google認証エラー: ' . htmlspecialchars($tokenData['error']));
    exit("
        <script>
            alert('Google認証に失敗しました。\\nもう一度お試しください。');
            window.location.href = '/verify_email.php';
        </script>
    ");
}

$client->setAccessToken($tokenData['access_token']);
$oauth    = new Oauth2($client);
$userInfo = $oauth->userinfo->get();
$email    = $userInfo->email;
$name     = $userInfo->name;

// 処理が完了したのでセッションをクリア
unset($_SESSION['verification_token']);
unset($_SESSION['google_auth_code']);

// ----------------------------------------------------------------------
// DB接続と確認 (ここから元の処理)
// ----------------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        SELECT d.*, u.user_name AS lender_name
        FROM debts d
        JOIN users u ON d.creditor_id = u.user_id
        WHERE d.token = ?
    ");
    $stmt->execute([$verified_token]);
    $debt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$debt) {
        exit("
            <script>
                alert('該当する貸付情報が見つかりません。\\n\\nすでに承認済み、または無効なリンクです。\\n貸主に確認してください。');
                window.close();
            </script>
        ");
    }

    if ($debt['debtor_email'] !== $email) {
        // exit("認証されたGoogleアカウントのメールアドレスが、貸付情報に登録されたメールアドレスと一致しません。");
        // セッションにエラー情報を保存
        $_SESSION['verification_token'] = $verified_token; // トークンを再セット
        $_SESSION['email_mismatch'] = true;
        $_SESSION['registered_email'] = $debt['debtor_email'];
        $_SESSION['authenticated_email'] = $email;

        // 認証ページに戻す（Google認証からやり直し）
        header("Location: verify_email.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("DBエラー: " . $e->getMessage());  // サーバーログに記録
    exit("
        <script>
            alert('システムエラーが発生しました。\\n\\n少し時間をおいて再度お試しください。\\n改善しない場合は貸主に連絡してください。');
            history.back();
        </script>
    ");
}

// ===================================================================
// 証拠画像パスの処理とHTML生成
// ===================================================================
$image_html          = '';
$proof_image_path_db = $debt['proof_image_path'] ?? null;

$bucketName = 'my-debt-app-storage';

if ($proof_image_path_db) {
    $image_src = "https://storage.googleapis.com/{$bucketName}/" . $proof_image_path_db;

    $image_html = '
<div class="info-item image-item">
 <span class="label">証拠画像:</span>
 <div class="proof-image-wrapper">
<img src="' . htmlspecialchars($image_src) . '" alt="証拠画像" class="proof-image"/>
 </div>
</div>
 ';
}
?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>貸付確認ページ</title>
    <link rel="icon" href="../favicon.ico?v=1">

    <script>
        function openApprovalModal() {
            document.getElementById('approvalModal').style.display = 'flex';
        }

        function closeApprovalModal() {
            document.getElementById('approvalModal').style.display = 'none';
        }

        function submitApproval() {
            closeApprovalModal();
            document.getElementById("approveForm").submit();
        }
    </script>

    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background: #f7f9fc;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }

        .card {
            background: #ffffff;
            padding: 30px 40px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        h2 {
            font-size: 24px;
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
            margin-bottom: 12px;
            color: #555;
        }

        .label {
            font-weight: 500;
            color: #333;
            width: 100px;
            flex-shrink: 0;
        }

        .value {
            font-weight: 400;
            flex-grow: 1;
        }

        .proof-image-wrapper {
            margin-top: 10px;
            text-align: center;
            width: 100%;
        }

        .proof-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .button {
            display: block;
            width: 100%;
            background: linear-gradient(135deg, #5b7cff, #6af1ff);
            color: white;
            text-align: center;
            padding: 14px 18px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 16px;
            margin-top: 25px;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
        }

        .button:hover {
            opacity: 0.9;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            width: 80%;
            max-width: 350px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            animation: fadeIn 0.3s ease-out;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .modal-content h3 {
            margin-top: 0;
            font-size: 1.5rem;
            color: #333;
        }

        .modal-content p {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
            text-align: center;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .modal-actions button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-approve {
            background: #4CAF50;
            /* 緑 */
            color: white;
        }

        .btn-approve:hover {
            background: #45a049;
        }

        .btn-cancel {
            background: #e0e0e0;
            /* グレー */
            color: #333;
        }

        .btn-cancel:hover {
            background: #ccc;
        }


        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>

<body>
    <div class="card">
        <h2>貸付内容の確認</h2>

        <div class="info-card">
            <div class="info-item">
                <span class="label">貸主:</span>
                <span class="value"><?= htmlspecialchars($debt['lender_name']) ?></span>
            </div>

            <div class="info-item">
                <span class="label">借主:</span>
                <span class="value"><?= htmlspecialchars($name) ?></span>
            </div>

            <div class="info-item">
                <span class="label">メール:</span>
                <span class="value"><?= htmlspecialchars($email) ?></span>
            </div>

            <div class="info-item" style="font-size: 18px; margin-top: 15px; margin-bottom: 0;">
                <span class="label" style="font-weight: 600; color: #000;">金額:</span>
                <span class="value" style="font-weight: 600; color: #000;">
                    ¥<?= number_format($debt['money']) ?>
                </span>
            </div>

            <div class="info-item" style="margin-top: 10px;">
                <span class="label">返済期限:</span>
                <span class="value"><?= htmlspecialchars($debt['date']) ?></span>
            </div>

            <?= $image_html ?>
        </div>

        <form id="approveForm" method="POST" action="verify_confirm.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($verified_token) ?>">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button type="button" class="button" onclick="openApprovalModal()">承認する</button>
        </form>
    </div>

    <div id="approvalModal" class="modal-overlay">
        <div class="modal-content">
            <h3>承認の確認</h3>
            <p>この内容で間違いありませんか？承認すると、貸付が正式に登録されます。</p>
            <div class="modal-actions">
                <button type="button" class="btn-approve" onclick="submitApproval()">承認する</button>
                <button type="button" class="btn-cancel" onclick="closeApprovalModal()">キャンセル</button>
            </div>
        </div>
    </div>
</body>

</html>




