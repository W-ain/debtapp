<?php
session_start();
require_once '../config.php'; // ⬅️ Cloud SQL Proxy接続の $pdo がここで定義される

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

if (!$creditor_id || !$debtor_name || !$debtor_email || $money <= 0 || !$date) {
    exit("エラー: 必要な情報が不足しているか、金額が不正です。");
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
    $file_extension     = pathinfo($upload_file['name'], PATHINFO_EXTENSION);
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array(strtolower($file_extension), $allowed_extensions)) {
        $hash_input   = $upload_file['name'] . time() . $creditor_id . microtime();
        $hashed_name  = hash('sha256', $hash_input);

        $current_year  = date('Y');
        $current_month = date('m');

        $first_char_dir = substr($hashed_name, 0, 1);
        $dynamic_dir    = $base_upload_dir . $current_year . '/' . $current_month . '/' . $first_char_dir . '/';

        if (!is_dir($dynamic_dir)) {
            if (!mkdir($dynamic_dir, 0755, true)) {
                exit("エラー: アップロードディレクトリの作成に失敗しました。");
            }
        }

        $unique_filename  = $hashed_name . '.' . $file_extension;
        $destination_path = $dynamic_dir . $unique_filename;

        if (resize_and_save_image($upload_file['tmp_name'], $destination_path, $file_extension, 800, 80)) {
            $proof_image_path = $destination_path;
        }
    }
}

// -------------------------------------------------------------------
// ★★★ 新規パートナーの自動登録 & 相手のユーザーID取得 ★★★
// -------------------------------------------------------------------

$debtor_user_id = null;

try {
    // usersテーブルから、メールアドレスに紐づくIDを取得する
    $sql_user = "SELECT user_id FROM users WHERE email = ?";
    $stmt_user = $pdo->prepare($sql_user);
    $stmt_user->execute([$debtor_email]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        // ユーザーが存在する場合、IDを取得
        $debtor_user_id = $user_data['user_id'];
    }
    // ユーザーが存在しない場合、debtor_user_id は null のまま次の処理へ進む
    
} catch (PDOException $e) {
    // エラー処理（エラーログの記録など）
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
// データ登録 (変更なし)
// -------------------------------------------------------------------

try {
    $sql = "
        INSERT INTO debts (
            creditor_id,
            debtor_user_id,
            debtor_name,
            debtor_email,
            title,
            money,
            date,
            verified,
            status,
            debt_hash,
            token,
            proof_image_path,
            proof_audio_path
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'active', ?, ?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $creditor_id,
        $debtor_user_id, // 新規登録されたID、または既存のIDが使用される
        $debtor_name,
        $debtor_email,
        $title,
        $money,
        $date,
        $debt_hash,
        $token,
        $proof_image_path,
        $proof_audio_path
    ]);
} catch (PDOException $e) {
    exit("DB実行エラー: " . $e->getMessage());
}

// -------------------------------------------------------------------
// メール送信 (変更なし)
// -------------------------------------------------------------------

$mail = new PHPMailer(true);
$success_message = "確認メールを {$debtor_email} に送信しました。";

try {
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
    $file_path  = dirname(__DIR__) . '/' . $proof_image_path;

    if ($proof_image_path && file_exists($file_path)) {
        $mail->addEmbeddedImage($file_path, 'proof_receipt', 'receipt.jpg');

        $image_html = '
            <p style="margin-top: 15px;">【レシート画像】</p>
            <img src="cid:proof_receipt" 
                style="max-width: 100%; border:1px solid #ddd; border-radius:5px;">
        ';
    }

    $mail->isHTML(true);
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';
    $mail->Subject    = '【DebtApp】貸付確認のお願い';
    $mail->Body = "
        <p>{$debtor_name} 様</p>
        <p>以下の内容で貸付が登録されました：</p>
        <ul>
            <li>金額：¥" . number_format($money) . "</li>
            <li>返済期限：{$date}</li>
        </ul>
        {$image_html}
        <p style='margin-top: 20px;'>以下のリンクから認証をお願いします。</p>
        <p><a href='http://localhost/debtapp/verify_email.php?token={$token}'>
        貸付を確認する</a></p>
        <hr>
        <small>このメールに心当たりがない場合は無視してください。</small>
    ";

    $mail->send();
    $response_message = $success_message;
    $redirect_url     = '../home/home.php';

} catch (Exception $e) {
    $response_message = "メール送信失敗: " . strip_tags($e->getMessage());
    $redirect_url     = '../dashboard.php';
}

// -------------------------------------------------------------------
// 完了ページ (変更なし)
// -------------------------------------------------------------------

echo "<!DOCTYPE html><html><head><title>処理完了</title></head><body>";
echo "<script>";
echo "alert(" . json_encode($response_message) . ");";
echo "window.location.href = " . json_encode($redirect_url) . ";";
echo "</script>";
echo "</body></html>";
exit;
?>