<?php
session_start();
require_once '../config.php'; //  Cloud SQL Proxy接続の $pdo がここで定義される

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Google\Cloud\Storage\StorageClient;

require '../vendor/autoload.php'; // composerでインストールしたPHPMailerを読み込み

$base_upload_dir = '../uploads/proofs/';

// -------------------------------------------------------------------
// 画像リサイズ関数 (変更なし)
// -------------------------------------------------------------------

/**
 * アップロードされた一時ファイルをリサイズして指定のパスに保存する
 * @param string $temp_path 一時ファイルのパス
 * @param string $dest_path 保存先のパス
 * @param string $extension ファイル拡張子
 * @param int    $max_width 最大幅
 * @param int    $quality JPEG品質
 * @return bool 成功したか
 */
function resize_and_save_image($temp_path, $dest_path, $extension, $max_width, $quality)
{
    switch (strtolower($extension)) {
        case 'jpeg':
        case 'jpg':
            $source_image = imagecreatefromjpeg($temp_path);
            break;
        case 'png':
            $source_image = imagecreatefrompng($temp_path);
            break;
        case 'gif':
            $source_image = imagecreatefromgif($temp_path);
            break;
        default:
            return false;
    }

    if (!$source_image) return false;

    $width  = imagesx($source_image);
    $height = imagesy($source_image);

    if ($width > $max_width) {
        $new_width  = $max_width;
        $new_height = floor($height * ($new_width / $width));
    } else {
        $new_width  = $width;
        $new_height = $height;
    }

    $new_image = imagecreatetruecolor($new_width, $new_height);

    if (strtolower($extension) === 'png') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
    }

    imagecopyresampled($new_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch (strtolower($extension)) {
        case 'jpeg':
        case 'jpg':
            $success = imagejpeg($new_image, $dest_path, $quality);
            break;
        case 'png':
            $success = imagepng($new_image, $dest_path, 9);
            break;
        case 'gif':
            $success = imagegif($new_image, $dest_path);
            break;
    }

    imagedestroy($source_image);
    imagedestroy($new_image);

    return $success;
}

// -------------------------------------------------------------------
// 入力データとファイル処理 (変更なし)
// -------------------------------------------------------------------

$creditor_id  = $_SESSION['user_id'] ?? null;
$debtor_name  = $_POST['partner_name'] ?? '';
$debtor_email = $_POST['partner_email'] ?? '';
$money        = $_POST['amount'] ?? 0;
$date         = $_POST['due_date'] ?? '';
// リマインダー設定の取得
// 何も選択されていない場合は空文字にする
$remind_settings_arr = $_POST['remind_settings'] ?? [];
$remind_settings_str = implode(',', $remind_settings_arr);

if (!$creditor_id || !$debtor_name || !$debtor_email || !$date) {
    exit("<script>
        alert('必要な情報が不足しています。\\n\\nもう一度お試しください。');
        window.location.href = '/login/google_login.php';
    </script>");
}
// 数値ではない、または 1 未満の場合はエラー
if (!is_numeric($money) || $money < 1) {
    exit("<script>
        alert('金額には1以上の数値を入力してください。');
        window.history.back();
    </script>");
}
// 過去の日付チェック
$today = date('Y-m-d');
if ($date < $today) {
    exit("<script>
        alert('返済期日に過去の日付は指定できません。');
        window.history.back();
    </script>");
}

$proof_image_path = null;
$proof_audio_path = null;

if (isset($_FILES['proof_camera']) && $_FILES['proof_camera']['error'] === UPLOAD_ERR_OK) {
    $upload_file = $_FILES['proof_camera'];
} elseif (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
    $upload_file = $_FILES['proof_file'];
} else {
    $upload_file = null;
}

if ($upload_file) {
    $file_extension = strtolower(pathinfo($upload_file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array($file_extension, $allowed_extensions)) {
        $hash_input = $upload_file['name'] . time() . $creditor_id . microtime();
        $hashed_name = hash('sha256', $hash_input);
        $unique_filename = $hashed_name . '.' . $file_extension;
        $temp_local_path = '/tmp/' . $unique_filename;

        // --- GCSの設定 ---
        $bucketName = 'my-debt-app-storage';
        $storage = new StorageClient();
        $bucket = $storage->bucket($bucketName);

        // リサイズして一旦 /tmp に保存
        if (resize_and_save_image($upload_file['tmp_name'], $temp_local_path, $file_extension, 800, 80)) {
            
            // GCS上のパス（日付/ハッシュ頭文字/ファイル名）
            $current_year = date('Y');
            $current_month = date('m');
            $first_char = substr($hashed_name, 0, 1);
            $gcs_object_path = "{$current_year}/{$current_month}/{$first_char}/{$unique_filename}";

            // GCSへアップロード
            $bucket->upload(
                fopen($temp_local_path, 'r'),
                ['name' => $gcs_object_path]
            );

            // DBに保存するパス（後で表示に使う）
            $proof_image_path = $gcs_object_path;

            // PHPMailer用の添付ファイルパス（/tmpにあるものを使用）
            $file_path = $temp_local_path; 
        }
    }
}

// -------------------------------------------------------------------
// 新規パートナーの自動登録 & 相手のユーザーID取得
// -------------------------------------------------------------------

$debtor_user_id = null;

try {
    $sql_user  = "SELECT user_id FROM users WHERE email = ?";
    $stmt_user = $pdo->prepare($sql_user);
    $stmt_user->execute([$debtor_email]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        $debtor_user_id = $user_data['user_id'];
    }

} catch (PDOException $e) {
    // error_log("ユーザーID検索エラー: " . $e->getMessage());
}

// -------------------------------------------------------------------
// 改ざん検知ハッシュ生成 (変更なし)
// -------------------------------------------------------------------

$token = bin2hex(random_bytes(16));

$sql_last = "SELECT * FROM debts ORDER BY debt_id DESC LIMIT 1";
$stmt_last = $pdo->query($sql_last);
$last_data = $stmt_last->fetch();

if ($last_data) {
    $hash_data = [
        'debt_id'      => $last_data['debt_id'],
        'debtor_name'  => $last_data['debtor_name'],
        'debtor_email' => $last_data['debtor_email'],
        'money'        => $last_data['money'],
        'date'         => $last_data['date'],
        'creditor_id'  => $last_data['creditor_id'],
        'debt_hash'    => $last_data['debt_hash'],
    ];
    $debt_hash = hash('sha256', json_encode($hash_data, JSON_UNESCAPED_UNICODE));
} else {
    $debt_hash = hash('sha256', uniqid(rand(), true));
}

$title = "{$debtor_name}への貸付 (¥" . number_format($money) . ")";

// -------------------------------------------------------------------
// データ登録とメール送信（トランザクション管理）
// -------------------------------------------------------------------

try {
    // 1. トランザクション開始
    $pdo->beginTransaction();

    // 2. データベースへの登録
    $sql = "
        INSERT INTO debts (
            creditor_id,
            debtor_user_id,
            debtor_name,
            debtor_email,
            title,
            money,
            date,
            remind_settings,
            verified,
            status,
            debt_hash,
            token,
            proof_image_path,
            proof_audio_path
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'active', ?, ?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $creditor_id,
        $debtor_user_id,
        $debtor_name,
        $debtor_email,
        $title,
        $money,
        $date,
        $remind_settings_str,
        $debt_hash,
        $token,
        $proof_image_path,
        $proof_audio_path
    ]);

    // 3. メール送信の準備と実行
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'debtapp005@gmail.com';
    $mail->Password   = 'anbi lvnm cykn vnsd';
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('debtapp005@gmail.com', 'DebtApp運営チーム');
    $mail->addAddress($debtor_email, $debtor_name);

    $image_html = '';
    
    if ($proof_image_path && isset($temp_local_path) && file_exists($temp_local_path)) {
        // ✅ ここで /tmp の画像を参照してメールに埋め込む
        $mail->addEmbeddedImage($temp_local_path, 'proof_receipt', 'receipt.jpg');
        $image_html = '
            <p style="margin-top: 15px;">【レシート画像】</p>
            <img src="cid:proof_receipt" style="max-width: 100%; border:1px solid #ddd; border-radius:5px;">
        ';
    }

    $mail->isHTML(true);
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->Subject  = '【DebtApp】貸付確認のお願い';
    $mail->Body = "
        <p>{$debtor_name} 様</p>
        <p>以下の内容で貸付が登録されました：</p>
        <ul>
            <li>金額：¥" . number_format($money) . "</li>
            <li>返済期限：{$date}</li>
        </ul>
        {$image_html}
        <p style='margin-top: 20px;'>以下のリンクから認証をお願いします。</p>
        <p><a href='https://debtapp-565547399529.asia-northeast1.run.app/verify_email.php?token={$token}'>貸付を確認する</a></p>
        <hr>
        <small>このメールに心当たりがない場合は無視してください。</small>
    ";
    
    // メール送信実行
    $mail->send();

    // 4. すべて成功したならコミット（DBに書き込み確定）
    $pdo->commit();
    
    $response_message = "確認メールを {$debtor_email} に送信しました。";
    $redirect_url     = '../home/home.php';
    
} catch (Exception $e) {
    // 5. どこかでエラーが起きたらロールバック（DB登録をキャンセル）
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // エラーメッセージの振り分け
    if ($e instanceof PDOException) {
        $response_message = "データベースエラーが発生しました。";
    } else {
        $response_message = "メール送信失敗: " . strip_tags($e->getMessage());
    }

    error_log("エラー: " . $e->getMessage());
    $redirect_url     = '../home/home.php';
}

if (isset($temp_local_path) && file_exists($temp_local_path)) {
    unlink($temp_local_path);
}
// -------------------------------------------------------------------
// 完了モーダル表示とリダイレクト (修正箇所)
// -------------------------------------------------------------------

$response_message_js = json_encode($response_message);
$redirect_url_js     = json_encode($redirect_url);

$is_error = (strpos($response_message, '失敗') !== false || strpos($response_message, 'エラー') !== false);
$icon     = $is_error ? 'error' : 'check_circle';
$color    = $is_error ? '#e74c3c' : '#4CAF50';
$title    = $is_error ? '処理失敗' : '処理完了';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>処理結果</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #eef3ff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: sans-serif;
        }
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-box {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            width: 80%;
            max-width: 350px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            animation: fadeIn 0.3s ease-out;
        }
        .modal-box .material-icons {
            font-size: 60px;
            margin-bottom: 15px;
        }
        #modalTitle {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }
        #modalMessage {
            margin-bottom: 25px;
            font-size: 1rem;
            color: #555;
            line-height: 1.5;
        }
        .modal-close-btn {
            background: #5b7cff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
            transition: background-color 0.2s;
        }
        .modal-close-btn:hover {
            background: #4a6ee6;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to   { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>

<div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
        <span class="material-icons" style="color: <?= $color ?>;"><?= $icon ?></span>
        <h3 id="modalTitle"><?= $title ?></h3>
        <p id="modalMessage"><?= nl2br(htmlspecialchars($response_message)) ?></p> 
        <button class="modal-close-btn" onclick="redirectToHome()">確認しました</button>
    </div>
</div>

<script>
function redirectToHome() {
    window.location.href = <?= $redirect_url_js ?>;
}
</script>

</body>
</html>
<?php
exit;
?>
