<?php
/**
 * ============================================================
 * 期日前日＆当日リマインダー＆メール送信
 * ============================================================
 * 
 * ブラウザでアクセス:
 * http://localhost/debtapp/cron/remind.php
 */

// ============================================================
// 1. 必要なファイルを読み込み
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =============================================================================================
// 実行制御：Cloud Scheduler用（本番環境）	※開発環境は本ファイル実行毎にメール送信されます
// =============================================================================================
// 【本番環境用：GCPデプロイ後にコメント解除】	
// // Cloud Schedulerからのリクエストのみ許可	
// $allowed = false;	
// if (isset($_SERVER['HTTP_X_CLOUDSCHEDULER']) || php_sapi_name() === 'cli') {	
// $allowed = true;	
// }	
//	
// if (!$allowed) {	
// http_response_code(403);	
// echo "Access Denied: This endpoint is only accessible via Cloud Scheduler";	
// exit;	
// }


// Cloud Scheduler設定メモ（デプロイ後にやる）
// 名前: remind-daily
// 頻度: 0 9 * * * (毎日午前9時)
// タイムゾーン: Asia/Tokyo
// ターゲット: HTTP
// URL: https://your-cloudrun-url/remind.php
// HTTPメソッド: GET
// ヘッダー追加:
//   キー: X-CloudScheduler
//   値: true


echo "期日前日＆当日リマインダーチェック開始...\n<br>";

// ============================================================
// 2. データベースから明日期限＆今日期限の債務を取得
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
            CASE 
                WHEN d.date = CURDATE() THEN 0
                WHEN d.date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1
            END AS days_until_due
        FROM debts d
        JOIN users u ON d.creditor_id = u.user_id
        WHERE d.status = 'active'
            AND d.verified = 1
            AND (
                d.date = CURDATE() 
                OR d.date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            )
        ORDER BY d.date ASC
    ";
    
    $stmt = $pdo->query($sql);
    $upcoming_debts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = count($upcoming_debts);
    
    if ($count === 0) {
        echo "本日または明日期限の債務はありません。\n<br>";
        exit;
    }
    
    echo "{$count}件のリマインダー送信対象債務を検出しました。\n<br><br>";
    
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
foreach ($upcoming_debts as $debt) {
    $days_until = $debt['days_until_due'];
    $timing_label = ($days_until == 0) ? '当日' : '前日';
    $timing_text = ($days_until == 0) ? '本日' : '明日';
    
    echo "--- debt_id={$debt['debt_id']} 処理中 (期限{$timing_label}) ---\n<br>";
    
    try {
        // --------------------------------------------------------
        // 借主へリマインダーメール送信
        // --------------------------------------------------------
        $mail->clearAllRecipients();
        $mail->addAddress($debt['debtor_email'], $debt['debtor_name']);
        
        // 当日の場合は緊急度を上げる
        $urgency_color = ($days_until == 0) ? '#d9534f' : '#f0ad4e';
        $urgency_icon = ($days_until == 0) ? '⚠️' : '🔔';
        
        $mail->Subject = "【DebtApp】返済期限リマインダー（{$timing_text}が期限）";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif;'>
                <h2 style='color: {$urgency_color};'>{$urgency_icon} 返済期限リマインダー</h2>
                <p>{$debt['debtor_name']} 様</p>
                <p style='color: {$urgency_color}; font-weight: bold;'>
                    {$timing_text}が返済期限です。お忘れなくご対応ください。
                </p>
                <ul>
                    <li>貸主: {$debt['creditor_name']}</li>
                    <li>金額: ¥" . number_format($debt['money']) . "</li>
                    <li>期限: {$debt['due_date']}（{$timing_text}）</li>
                </ul>
                <p>期限までに返済のご対応をお願いいたします。</p>
                <hr>
                <small>このメールはDebtAppからの自動送信です。</small>
            </div>
        ";
        
        $mail->send();
        echo "✓ 借主({$debt['debtor_name']})へ送信成功\n<br>";
        
        // --------------------------------------------------------
        // 貸主へリマインダーメール送信
        // --------------------------------------------------------
        $mail->clearAllRecipients();
        $mail->addAddress($debt['creditor_email'], $debt['creditor_name']);
        
        $mail->Subject = "【DebtApp】返済期限リマインダー（貸主向け・{$timing_text}が期限）";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif;'>
                <h2 style='color: #5bc0de;'>📋 返済期限リマインダー</h2>
                <p>{$debt['creditor_name']} 様</p>
                <p>以下の貸付が{$timing_text}返済期限を迎えます。</p>
                <ul>
                    <li>借主: {$debt['debtor_name']}</li>
                    <li>金額: ¥" . number_format($debt['money']) . "</li>
                    <li>期限: {$debt['due_date']}（{$timing_text}）</li>
                </ul>
                <p>返済状況をご確認ください。</p>
                <hr>
                <small>このメールはDebtAppからの自動送信です。</small>
            </div>
        ";
        
        $mail->send();
        echo "✓ 貸主({$debt['creditor_name']})へ送信成功\n<br>";
        
        $success_count++;
        echo "✓ debt_id={$debt['debt_id']} 完了 (期限{$timing_label})\n<br><br>";
        
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