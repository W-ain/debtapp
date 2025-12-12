<?php
session_start();
require_once 'config.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$token = $_POST['token'] ?? '';
$email = $_POST['email'] ?? '';
$success_message = '';
$error_message = '';
$debt = null;
$lender_email_sent = false;
$borrower_email_sent = false;

if (!$token || !$email) {
    $error_message = '不正なアクセスです。トークンまたはメールアドレスが指定されていません。';
}

if (!$error_message) {
    try {
        // 貸付情報を取得（貸主情報もJOIN）
        $stmt = $pdo->prepare("
            SELECT 
                d.*, 
                u.user_name AS lender_name, 
                u.email AS lender_email 
            FROM debts d 
            JOIN users u ON d.creditor_id = u.user_id 
            WHERE d.token = ?
        ");
        $stmt->execute([$token]);
        $debt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$debt) {
            $error_message = "該当する貸付情報が見つかりません。";
        }

        if (!$error_message) {
            // 承認状態を更新
            $update = $pdo->prepare("UPDATE debts SET verified = 1, token = NULL WHERE token = ?");
            $update->execute([$token]);

            $success_message = "承認が完了しました！";

            // ----------------------------------------------------------------------
            // ✅ 借主（あなた）に承認完了メールを送信 (新規追加)
            // ----------------------------------------------------------------------
            $mail_borrower = new PHPMailer(true);
            try {
                // Gmail SMTP設定
                $mail_borrower->isSMTP();
                $mail_borrower->Host       = 'smtp.gmail.com';
                $mail_borrower->SMTPAuth   = true;
                $mail_borrower->Username   = 'debtapp005@gmail.com';
                $mail_borrower->Password   = 'anbi lvnm cykn vnsd';
                $mail_borrower->SMTPSecure = 'tls';
                $mail_borrower->Port       = 587;

                // 送信者・宛先
                $mail_borrower->setFrom('debtapp005@gmail.com', 'DebtApp運営チーム');
                $mail_borrower->addAddress($email); // 宛先は借主のメールアドレス ($email)
                $mail_borrower->isHTML(true);
                $mail_borrower->CharSet = 'UTF-8';
                $mail_borrower->Encoding = 'base64';
                $mail_borrower->Subject = '【DebtApp】貸付の承認が完了しました';
                $mail_borrower->Body = "
                    <p>ご担当者様</p>
                    <p>貸付（貸主: {$debt['lender_name']} 様）の承認処理が完了しました。</p>
                    <p>以下の内容で正式に記録されましたので、ご確認ください。</p>
                    <ul>
                        <li>金額：¥" . number_format($debt['money']) . "</li>
                        <li>返済期限：{$debt['date']}</li>
                    </ul>
                    <p>今後ともDebtAppをご利用ください。</p>
                    <hr>
                    <small>このメールは自動送信されています。</small>
                ";
                $mail_borrower->send();
                $borrower_email_sent = true;
            } catch (Exception $e) {
                // エラーメッセージは画面下部に表示する
                $error_message .= " <br>借主への通知メール送信エラー: {$mail_borrower->ErrorInfo}";
            }

            // ----------------------------------------------------------------------
            // ✅ 貸主に通知 (既存の処理)
            // ----------------------------------------------------------------------
            $mail_lender = new PHPMailer(true);
            try {
                // Gmail SMTP設定 (既存設定を使用)
                $mail_lender->isSMTP();
                $mail_lender->Host       = 'smtp.gmail.com';
                $mail_lender->SMTPAuth   = true;
                $mail_lender->Username   = 'debtapp005@gmail.com';
                $mail_lender->Password   = 'anbi lvnm cykn vnsd';
                $mail_lender->SMTPSecure = 'tls';
                $mail_lender->Port       = 587;

                // 送信者・宛先
                $mail_lender->setFrom('debtapp005@gmail.com', 'DebtApp運営チーム');
                $mail_lender->addAddress($debt['lender_email'], $debt['lender_name']);

                // メール内容
                $mail_lender->isHTML(true);
                $mail_lender->CharSet = 'UTF-8';
                $mail_lender->Encoding = 'base64';
                $mail_lender->Subject = '【DebtApp】借主が貸付を承認しました';
                $mail_lender->Body = "
                    <p>{$debt['lender_name']} 様</p>
                    <p>借主（{$email}）が以下の貸付内容を承認しました。</p>
                    <ul>
                        <li>金額：¥" . number_format($debt['money']) . "</li>
                        <li>返済期限：{$debt['date']}</li>
                    </ul>
                    <p>これにより貸付が正式に成立しました。</p>
                    <hr>
                    <small>このメールは自動送信されています。</small>
                ";
                $mail_lender->send();
                $lender_email_sent = true;
            } catch (Exception $e) {
                // エラーメッセージは画面下部に表示する
                $error_message .= " <br>貸主への通知メール送信エラー: {$mail_lender->ErrorInfo}";
            }
        }
    } catch (PDOException $e) {
        $error_message = "DBエラー: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>貸付承認完了</title>
    <style>
        body {
            font-family: sans-serif;
            background: #eef3ff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            text-align: center;
        }
        .card {
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            width: 450px;
            max-width: 90%;
        }
        .success-icon {
            color: #4CAF50;
            font-size: 80px;
            margin-bottom: 15px;
        }
        .error-icon {
            color: #F44336;
            font-size: 80px;
            margin-bottom: 15px;
        }
        h2 { 
            margin-top: 0;
            color: #333;
            font-size: 24px;
        }
        p {
            color: #555;
            line-height: 1.6;
            margin-bottom: 10px;
            text-align: left;
        }
        .details {
            background: #f8faff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: left;
        }
        .details strong {
            display: inline-block;
            width: 100px;
            font-weight: bold;
        }
        .error-box {
            background-color: #ffebee;
            color: #c62828;
            padding: 10px;
            border-radius: 4px;
            margin-top: 20px;
            text-align: left;
            border: 1px solid #ef9a9a;
        }
        .note {	
            font-size: 0.9em;	
            color: #888;	
            margin-top: 20px;	
        }
    </style>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body>
    <div class="card">
        <?php if ($success_message && !$error_message): ?>
            <span class="material-icons success-icon">check_circle</span>
            <h2><?= htmlspecialchars($success_message) ?></h2>
            <p>以下の内容で貸付が正式に認証され、記録されました。</p>
            
            <div class="details">
                <?php if ($debt): ?>
                    <p><strong>貸主:</strong> <?= htmlspecialchars($debt['lender_name']) ?></p>
                    <p><strong>借主:</strong> <?= htmlspecialchars($email) ?></p>
                    <p><strong>金額:</strong> ¥<?= number_format($debt['money']) ?></p>
                    <p><strong>返済期限:</strong> <?= htmlspecialchars($debt['date']) ?></p>
                <?php endif; ?>
            </div>

            <div class="details" style="margin-top: 15px;">
                <p style="text-align: center; font-size: 14px;">
                    メール通知状況:
                    <br>借主（あなた）: <?= $borrower_email_sent ? '✅ 送信済み' : '❌ 送信エラー' ?>
                    <br>貸主（<?= htmlspecialchars($debt['lender_name'] ?? '---') ?>）: <?= $lender_email_sent ? '✅ 送信済み' : '❌ 送信エラー' ?>
                </p>
            </div>
        
            <p class="note">貸主に承認通知が送信されます。<br>この画面は閉じて構いません。</p>

        <?php elseif ($error_message): ?>
            <span class="material-icons error-icon">error</span>
            <h2>処理中にエラーが発生しました</h2>
            <div class="error-box">
                **詳細:** <?= $error_message ?>
            </div>
            <p>お手数ですが、貸主に連絡して状況をご確認ください。</p>
        
        <?php else: ?>
             <span class="material-icons error-icon">warning</span>
            <h2>処理が正しく完了しませんでした</h2>
            <p>不正なアクセス、または何らかの予期せぬエラーが発生しました。</p>
        <?php endif; ?>
    </div>
</body>

</html>

