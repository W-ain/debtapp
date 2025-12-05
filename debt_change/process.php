<?php
session_start();
require_once '../config.php'; // $pdo が定義されている
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -------------------------------------------------------------------
// 1. 入力値の取得とバリデーション
// -------------------------------------------------------------------

// ⚠️ 修正点: 最初の行の構文エラーと変数名（$user_id）の定義を修正
$user_id = $_SESSION['user_id'] ?? null;
$debt_id = $_POST['debt_id'] ?? null; 

$repay_amount = (int)($_POST['repay_amount'] ?? 0);
$current_remaining_amount = (int)($_POST['remaining_amount'] ?? 0);

if (!$user_id || !$debt_id || $repay_amount <= 0) {
    exit("エラー: 不正な操作または金額です。");
}
if ($repay_amount > $current_remaining_amount) {
    exit("エラー: 返済額が残り金額 (¥" . number_format($current_remaining_amount) . ") を超えています。");
}

$new_remaining_amount = $current_remaining_amount - $repay_amount;
$is_full_repayment = ($new_remaining_amount === 0);

$redirect_url = '../inquiry/inquiry.php'; // 処理後のリダイレクト先

try {
    // ---------------------------------------------------------------
    // 2. DB処理 (トランザクション開始)
    // ---------------------------------------------------------------
    $pdo->beginTransaction();

    // ---------------------------------------------------------------
    // 2-1. チェーン型ハッシュの計算 (debt_change用)
    // ---------------------------------------------------------------
    
    $current_timestamp = time(); 

    // 最後に登録された返済記録を取得
    $stmt_last = $pdo->prepare("
        SELECT change_id, change_money, created_at, debt_change_hash 
        FROM debt_change 
        WHERE debt_id = ? 
        ORDER BY change_id DESC LIMIT 1
    ");
    $stmt_last->execute([$debt_id]);
    $last_change = $stmt_last->fetch(PDO::FETCH_ASSOC);
    
    // 前回データに基づいてハッシュを生成
    if ($last_change) {
        $prev_hash_input = $last_change['debt_change_hash'];
    } else {
        // 初回返済時は、debt_idを基に初期ハッシュを生成
        $prev_hash_input = hash('sha256', "DEBT_START:{$debt_id}");
    }

    // 今回の返済記録のハッシュを計算
    $current_change_hash = hash('sha256', json_encode([
        'debt_id' => $debt_id,
        'change_money' => $repay_amount,
        'prev_hash' => $prev_hash_input, // 前回のハッシュ
        'timestamp' => $current_timestamp // 今回のタイムスタンプ
    ], JSON_UNESCAPED_UNICODE));


    // ---------------------------------------------------------------
    // 2-2. debt_change テーブルに返済記録を挿入
    // ---------------------------------------------------------------
    $stmt_insert = $pdo->prepare("
        INSERT INTO debt_change (debt_id, change_money, debt_change_hash, created_at)
        VALUES (?, ?, ?, FROM_UNIXTIME(?))
    ");
    $stmt_insert->execute([$debt_id, $repay_amount, $current_change_hash, $current_timestamp]);


    // ---------------------------------------------------------------
    // 2-3. 完済の場合、debtsテーブルのステータスを更新
    // ---------------------------------------------------------------
    if ($is_full_repayment) {
        $stmt_update_debt = $pdo->prepare("
            UPDATE debts SET status = 'repaid', closed_at = NOW() WHERE debt_id = ?
        ");
        $stmt_update_debt->execute([$debt_id]);
        $alert_message = "全額 (¥" . number_format($repay_amount) . ") の返済を記録し、貸付を完済としました！";
    } else {
        $alert_message = "返済 (¥" . number_format($repay_amount) . ") を記録しました。残り金額は ¥" . number_format($new_remaining_amount) . " です。";
    }
    
    $pdo->commit();
    
    // ---------------------------------------------------------------
    // 3. メール送信に必要な情報を取得 (⚠️ SQLカラム名修正)
    // ---------------------------------------------------------------
    $stmt_info = $pdo->prepare("
        SELECT 
            d.debtor_name, d.debtor_email, d.money, d.date, 
            u.user_name AS creditor_name, u.email AS creditor_email
        FROM debts d
        JOIN users u ON d.creditor_id = u.user_id
        WHERE d.debt_id = ?
    ");
    $stmt_info->execute([$debt_id]);
    $debt_info = $stmt_info->fetch(PDO::FETCH_ASSOC);

    if (!$debt_info) {
        throw new Exception("貸付情報の取得に失敗しましたが、返済記録は完了しています。");
    }

    // ---------------------------------------------------------------
    // 4. メール送信処理 (両者へ通知)
    // ---------------------------------------------------------------
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'debtapp005@gmail.com'; 
    $mail->Password   = 'anbi lvnm cykn vnsd'; 
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';
    $mail->setFrom('debtapp005@gmail.com', 'DebtApp運営チーム');
    $mail->isHTML(true);

    $status_text = $is_full_repayment ? "完済" : "一部返済";
    $subject = "【DebtApp】返済通知: {$status_text}のお知らせ";
    
    // メール本文のテンプレート関数
    $body_template = function ($is_creditor) use ($debt_info, $repay_amount, $new_remaining_amount, $is_full_repayment, $status_text) {
        $recipient_name = $is_creditor ? $debt_info['creditor_name'] : $debt_info['debtor_name'];
        $partner_name = $is_creditor ? $debt_info['debtor_name'] : $debt_info['creditor_name'];
        
        $body = "<p>{$recipient_name} 様</p>";
        $body .= "<p>以下の貸付について、{$partner_name}から{$status_text}がありました。</p>";
        $body .= "<ul>";
        $body .= "<li>今回返済額：¥" . number_format($repay_amount) . "</li>";
        $body .= "<li>元金：¥" . number_format($debt_info['money']) . "</li>";
        
        if ($is_full_repayment) {
            $body .= "<li style='color: green; font-weight: bold;'>最終的な残高：¥0（完済）🎉</li>";
        } else {
            $body .= "<li style='color: #d9534f; font-weight: bold;'>返済後の残高：¥" . number_format($new_remaining_amount) . "</li>";
        }
        $body .= "</ul>";
        $body .= "<p>ご確認いただきありがとうございます。</p>";
        $body .= "<hr><small>このメールはDebtAppからの自動送信メールです。</small>";
        return $body;
    };

    // 4-1. 貸主 (Creditor) へ送信
    $mail->addAddress($debt_info['creditor_email'], $debt_info['creditor_name']);
    $mail->Subject = $subject;
    $mail->Body = $body_template(true);
    $mail->send();

    // 4-2. 借主 (Debtor) へ送信
    $mail->clearAllRecipients();
    $mail->addAddress($debt_info['debtor_email'], $debt_info['debtor_name']);
    $mail->Subject = $subject;
    $mail->Body = $body_template(false);
    $mail->send();
    
    $alert_message .= " 両者に確認メールを送信しました。";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack(); // DB処理が失敗した場合、ロールバック
        $alert_message = "DB処理中にエラーが発生し、返済は記録されませんでした: " . strip_tags($e->getMessage());
    } else {
        // DB処理は成功したが、メール送信が失敗した場合
        $alert_message .= " ただし、メール送信に失敗しました: " . strip_tags($e->getMessage());
    }
}

// -------------------------------------------------------------------
// 5. JavaScriptによるアラート表示とリダイレクト
// -------------------------------------------------------------------
echo "<!DOCTYPE html><html><head><title>処理完了</title></head><body>";
echo "<script>";
echo "alert(" . json_encode($alert_message) . ");"; 
echo "window.location.href = " . json_encode($redirect_url) . ";"; 
echo "</script>";
echo "</body></html>";
exit;
?>