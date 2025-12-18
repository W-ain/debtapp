<?php
/**
 * ============================================================
 * メール送信サービスクラス
 * ============================================================
 * 
 * リマインダーや期限切れ通知メールの作成・送信を担当
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    
    private $mail;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->configureSMTP();
    }
    
    /**
     * SMTP設定
     */
    private function configureSMTP() {
        $this->mail->isSMTP();
        $this->mail->Host       = 'smtp.gmail.com';
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = 'debtapp005@gmail.com';
        $this->mail->Password   = 'anbi lvnm cykn vnsd';
        $this->mail->SMTPSecure = 'tls';
        $this->mail->Port       = 587;
        $this->mail->CharSet    = 'UTF-8';
        $this->mail->Encoding   = 'base64';
        $this->mail->setFrom('debtapp005@gmail.com', 'トリタテくん運営チーム');
        $this->mail->isHTML(true);
    }
    
    /**
     * 借主へ期限前リマインダー送信
     * 
     * @param array $debt 債務情報
     * @param int $days_until_due 期限までの日数（0=当日, 1=前日, 2以上=それ以上）
     * @return bool 送信成功/失敗
     */
    public function sendDebtorReminder($debt, $days_until_due) {
        // 日数に応じて表示テキストを変更
        if ($days_until_due == 0) {
            $timing_text = '本日';
            $timing_detail = '本日';
            $urgency_color = '#d9534f';
            $urgency_icon = '⚠️';
        } elseif ($days_until_due == 1) {
            $timing_text = '明日';
            $timing_detail = '明日';
            $urgency_color = '#f0ad4e';
            $urgency_icon = '🔔';
        } else {
            $timing_text = 'まもなく';
            $timing_detail = 'あと' . $days_until_due . '日後';
            $urgency_color = '#5bc0de';
            $urgency_icon = '📅';
        }
        
        try {
            $this->mail->clearAllRecipients();
            $this->mail->addAddress($debt['debtor_email'], $debt['debtor_name']);
            
            $this->mail->Subject = "【トリタテくん】返済期限リマインダー（{$timing_detail}が期限）";
            $this->mail->Body = "
                <div style='font-family: Arial, sans-serif;'>
                    <h2 style='color: {$urgency_color};'>{$urgency_icon} 返済期限リマインダー</h2>
                    <p>{$debt['debtor_name']} 様</p>
                    <p style='color: {$urgency_color}; font-weight: bold;'>
                        返済期限が{$timing_detail}に迫っています。お忘れなくご対応ください。
                    </p>
                    <ul>
                        <li>貸主: {$debt['creditor_name']}</li>
                        <li>金額: ¥" . number_format($debt['money']) . "</li>
                        <li>期限: {$debt['date']}（{$timing_detail}）</li>
                    </ul>
                    <p>期限までに返済のご対応をお願いいたします。</p>
                    <hr>
                    <small>このメールはトリタテくんからの自動送信です。</small>
                </div>
            ";
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("借主へのリマインダー送信失敗: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 貸主へ期限前リマインダー送信
     * 
     * @param array $debt 債務情報
     * @param int $days_until_due 期限までの日数（0=当日, 1=前日, 2以上=それ以上）
     * @return bool 送信成功/失敗
     */
    public function sendCreditorReminder($debt, $days_until_due) {
        // 日数に応じて表示テキストを変更
        if ($days_until_due == 0) {
            $timing_text = '本日';
            $timing_detail = '本日';
        } elseif ($days_until_due == 1) {
            $timing_text = '明日';
            $timing_detail = '明日';
        } else {
            $timing_text = 'まもなく';
            $timing_detail = 'あと' . $days_until_due . '日後';
        }
        
        try {
            $this->mail->clearAllRecipients();
            $this->mail->addAddress($debt['creditor_email'], $debt['creditor_name']);
            
            $this->mail->Subject = "【トリタテくん】返済期限リマインダー（貸主向け・{$timing_detail}が期限）";
            $this->mail->Body = "
                <div style='font-family: Arial, sans-serif;'>
                    <h2 style='color: #5bc0de;'>📋 返済期限リマインダー</h2>
                    <p>{$debt['creditor_name']} 様</p>
                    <p>以下の貸付が{$timing_detail}返済期限を迎えます。</p>
                    <ul>
                        <li>借主: {$debt['debtor_name']}</li>
                        <li>金額: ¥" . number_format($debt['money']) . "</li>
                        <li>期限: {$debt['date']}（{$timing_detail}）</li>
                    </ul>
                    <p>返済状況をご確認ください。</p>
                    <hr>
                    <small>このメールはトリタテくんからの自動送信です。</small>
                </div>
            ";
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("貸主へのリマインダー送信失敗: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 借主へ期限切れ通知送信
     * 
     * @param array $debt 債務情報
     * @param int $overdue_days 超過日数
     * @return bool 送信成功/失敗
     */
    public function sendDebtorOverdueNotice($debt, $overdue_days) {
        try {
            $this->mail->clearAllRecipients();
            $this->mail->addAddress($debt['debtor_email'], $debt['debtor_name']);
            
            $this->mail->Subject = '【トリタテくん】返済期限超過のお知らせ';
            $this->mail->Body = "
                <div style='font-family: Arial, sans-serif;'>
                    <h2 style='color: #d9534f;'>⚠ 返済期限超過のお知らせ</h2>
                    <p>{$debt['debtor_name']} 様</p>
                    <p style='color: #d9534f; font-weight: bold;'>
                        返済期限を{$overdue_days}日超過しています。
                    </p>
                    <ul>
                        <li>貸主: {$debt['creditor_name']}</li>
                        <li>金額: ¥" . number_format($debt['money']) . "</li>
                        <li>期限: {$debt['date']}</li>
                        <li>超過日数: {$overdue_days}日</li>
                    </ul>
                    <p>早急にご対応をお願いいたします。</p>
                    <hr>
                    <small>このメールはトリタテくんからの自動送信です。</small>
                </div>
            ";
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("借主への期限切れ通知送信失敗: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 貸主へ期限切れ通知送信
     * 
     * @param array $debt 債務情報
     * @param int $overdue_days 超過日数
     * @return bool 送信成功/失敗
     */
    public function sendCreditorOverdueNotice($debt, $overdue_days) {
        try {
            $this->mail->clearAllRecipients();
            $this->mail->addAddress($debt['creditor_email'], $debt['creditor_name']);
            
            $this->mail->Subject = '【トリタテくん】返済期限超過のお知らせ（貸主向け）';
            $this->mail->Body = "
                <div style='font-family: Arial, sans-serif;'>
                    <h2 style='color: #f0ad4e;'>📢 返済期限超過のお知らせ</h2>
                    <p>{$debt['creditor_name']} 様</p>
                    <p>以下の貸付が返済期限を超過しています。</p>
                    <ul>
                        <li>借主: {$debt['debtor_name']}</li>
                        <li>金額: ¥" . number_format($debt['money']) . "</li>
                        <li>期限: {$debt['date']}</li>
                        <li>超過日数: {$overdue_days}日</li>
                    </ul>
                    <p>必要に応じて借主へ連絡をお願いいたします。</p>
                    <hr>
                    <small>このメールはトリタテくんからの自動送信です。</small>
                </div>
            ";
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("貸主への期限切れ通知送信失敗: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 特定の債務IDに対してリマインダー送信（手動送信用）
     * 
     * @param int $debt_id 債務ID
     * @param PDO $pdo データベース接続
     * @return array 結果情報 ['success' => bool, 'message' => string, 'details' => array]
     */
    public function sendManualReminder($debt_id, $pdo) {
        try {
            // 債務情報を取得
            $sql = "
                SELECT 
                    d.debt_id,
                    d.debtor_name,
                    d.debtor_email,
                    d.money,
                    d.date AS date,
                    u.user_name AS creditor_name,
                    u.email AS creditor_email,
                    DATEDIFF(d.date, CURDATE()) AS days_until_due,
                    d.status,
                    d.verified
                FROM debts d
                JOIN users u ON d.creditor_id = u.user_id
                WHERE d.debt_id = :debt_id
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['debt_id' => $debt_id]);
            $debt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$debt) {
                return [
                    'success' => false,
                    'message' => '指定された債務が見つかりません。',
                    'details' => []
                ];
            }
            
            // ステータスチェック
            if ($debt['status'] !== 'active') {
                return [
                    'success' => false,
                    'message' => 'この債務はアクティブではありません。',
                    'details' => ['status' => $debt['status']]
                ];
            }
            
            if ($debt['verified'] != 1) {
                return [
                    'success' => false,
                    'message' => 'この債務は未承認です。',
                    'details' => []
                ];
            }
            
            $days_until = $debt['days_until_due'];
            $debtor_success = false;
            $creditor_success = false;
            
            // 期限前か期限切れかで処理を分岐
            if ($days_until >= 0) {
                // 期限前リマインダー
                $debtor_success = $this->sendDebtorReminder($debt, $days_until);
                $creditor_success = $this->sendCreditorReminder($debt, $days_until);
                $mail_type = '期限前リマインダー';
            } else {
                // 期限切れ通知
                $overdue_days = abs($days_until);
                $debtor_success = $this->sendDebtorOverdueNotice($debt, $overdue_days);
                $creditor_success = $this->sendCreditorOverdueNotice($debt, $overdue_days);
                $mail_type = '期限切れ通知';
            }
            
            // 結果をまとめる
            if ($debtor_success && $creditor_success) {
                return [
                    'success' => true,
                    'message' => "{$mail_type}メールを送信しました。",
                    'details' => [
                        'debt_id' => $debt_id,
                        'debtor_name' => $debt['debtor_name'],
                        'creditor_name' => $debt['creditor_name'],
                        'debtor_email_sent' => true,
                        'creditor_email_sent' => true,
                        'mail_type' => $mail_type
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'メール送信に一部失敗しました。',
                    'details' => [
                        'debt_id' => $debt_id,
                        'debtor_email_sent' => $debtor_success,
                        'creditor_email_sent' => $creditor_success,
                        'mail_type' => $mail_type
                    ]
                ];
            }
            
        } catch (PDOException $e) {
            error_log("手動リマインダー送信エラー: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'データベースエラーが発生しました。',
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }
}
?>

