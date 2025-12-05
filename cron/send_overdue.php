<?php
/**
 * ============================================================
 * 期限切れチェック＆メール送信（翌日＆1週間後のみ）
 * ============================================================
 * 
 * ブラウザでアクセス:
 * http://localhost/debtapp/cron/send_overdue.php
 */

// ============================================================
// 1. 必要なファイルを読み込み
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ====================================================================================
// 実行制御：Cloud Scheduler用（本番環境）※開発環境は本ファイル実行毎にメール送信されます
// ====================================================================================

// 【本番環境用：GCPデプロイ後にコメント解除】
// // Cloud Schedulerからのリクエストのみ許可
// $allowed = false;
// if (isset($_SERVER['HTTP_X_CLOUDSCHEDULER']) || php_sapi_name() === 'cli') {
//     $allowed = true;
// }
// 
// if (!$allowed) {
//     http_response_code(403);
//     echo "Access Denied: This endpoint is only accessible via Cloud Scheduler";
//     exit;
// }

// Cloud Scheduler設定メモ（デプロイ後にやる）
// **期限切れチェック用：**
// ```
// 名前: overdue-check-daily
// 頻度: 0 10 * * * (毎日午前10時)
// タイムゾーン: Asia/Tokyo
// URL: https://your-cloudrun-url/check_overdue_local.php
// HTTPメソッド: GET
// ヘッダー:
//   キー: X-CloudScheduler
//   値: true
// ```

// **期日前日リマインダー用：**
// ```
// 名前: remind-daily
// 頻度: 0 9 * * * (毎日午前9時)
// タイムゾーン: Asia/Tokyo
// URL: https://your-cloudrun-url/remind.php
// HTTPメソッド: GET
// ヘッダー:
//   キー: X-CloudScheduler
//   値: true

echo "期限切れチェック開始（翌日＆1週間後のみ送信）...\n<br>";

// ============================================================
// 2. データベースから期限切れ債務を取得
// ============================================================

try {
    $sql = "
        SELECT 
            d.debt_id,
            d.debtor_name,
            d.debtor_email,
            d.money,
            d.date AS due_date,
            u.user_name AS creditor_name,
            u.email AS creditor_email,
            DATEDIFF(CURDATE(), d.date) AS overdue_days
        FROM debts d
        JOIN users u ON d.creditor_id = u.user_id
        WHERE d.status = 'active'
            AND d.date < CURDATE()
            AND d.verified = 1
            AND DATEDIFF(CURDATE(), d.date) IN (1, 7)
        ORDER BY d.date ASC
    ";
    
    $stmt = $pdo->query($sql);
    $overdue_debts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = count($overdue_debts);
    
    if ($count === 0) {
        echo "本日送信対象の期限切れ債務はありません（翌日または1週間後のものなし）。\n<br>";
        exit;
    }
    
    echo "{$count}件の送信対象債務を検出しました。\n<br><br>";
    
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n<br>";
    exit;
}

// ============================================================
// 3. メール送信処理
// ============================================================

$mail = new PHPMailer(true);

// SMTP設定
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

$success_count = 0;
$fail_count = 0;

// 各債務に対してメール送信
foreach ($overdue_debts as $debt) {
    $overdue_days = $debt['overdue_days'];
    $timing_label = ($overdue_days == 1) ? '翌日' : '1週間後';	


    echo "--- debt_id={$debt['debt_id']} 処理中 ({$timing_label}) ---\n<br>";
    
    try {
        // --------------------------------------------------------
        // 借主へメール送信
        // --------------------------------------------------------
        $mail->clearAllRecipients();
        $mail->addAddress($debt['debtor_email'], $debt['debtor_name']);
        
        $mail->Subject = '【DebtApp】返済期限超過のお知らせ';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif;'>
                <h2 style='color: #d9534f;'>⚠ 返済期限超過のお知らせ</h2>
                <p>{$debt['debtor_name']} 様</p>
                <p style='color: #d9534f; font-weight: bold;'>
                    返済期限を{$overdue_days}日超過しています。
                </p>
                <ul>
                    <li>貸主: {$debt['creditor_name']}</li>
                    <li>金額: ¥" . number_format($debt['money']) . "</li>
                    <li>期限: {$debt['due_date']}</li>
                    <li>超過日数: {$overdue_days}日</li>
                </ul>
                <p>早急にご対応をお願いいたします。</p>
                <hr>
                <small>このメールはDebtAppからの自動送信です。</small>
            </div>
        ";
        
        $mail->send();
        echo "✓ 借主({$debt['debtor_name']})へ送信成功\n<br>";
        
        // --------------------------------------------------------
        // 貸主へメール送信
        // --------------------------------------------------------
        $mail->clearAllRecipients();
        $mail->addAddress($debt['creditor_email'], $debt['creditor_name']);
        
        $mail->Subject = '【DebtApp】返済期限超過のお知らせ（貸主向け）';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif;'>
                <h2 style='color: #f0ad4e;'>📢 返済期限超過のお知らせ</h2>
                <p>{$debt['creditor_name']} 様</p>
                <p>以下の貸付が返済期限を超過しています。</p>
                <ul>
                    <li>借主: {$debt['debtor_name']}</li>
                    <li>金額: ¥" . number_format($debt['money']) . "</li>
                    <li>期限: {$debt['due_date']}</li>
                    <li>超過日数: {$overdue_days}日</li>
                </ul>
                <p>必要に応じて借主へ連絡をお願いいたします。</p>
                <hr>
                <small>このメールはDebtAppからの自動送信です。</small>
            </div>
        ";
        
        $mail->send();
        echo "✓ 貸主({$debt['creditor_name']})へ送信成功\n<br>";
        
        $success_count++;
        echo "✓ debt_id={$debt['debt_id']} 完了 ({$timing_label})\n<br><br>";
        
    } catch (Exception $e) {
        $fail_count++;
        echo "✗ 送信失敗: " . $e->getMessage() . "\n<br><br>";
    }
    
    // 連続送信の負荷軽減
    sleep(1);
}

// ============================================================
// 4. 実行結果
// ============================================================

echo "============================================================\n<br>";
echo "処理完了\n<br>";
echo "成功: {$success_count}件 / 失敗: {$fail_count}件\n<br>";
echo "============================================================\n<br>";
?>