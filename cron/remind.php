<?php
/**
 * ============================================================
 * 汎用リマインダー＆期限切れ通知一括処理 (daily_alert.php)
 * ============================================================
 * * データベースの `remind_settings` (例: "-3,-1,0,1,7") に基づき、
 * 期限前・当日・期限後を問わず、対象となる全ての債務に対してメールを送信します。
 * * Cloud Scheduler設定:
 * 頻度: 毎日 09:00 (0 9 * * *)
 */

// ===========================================================
// 1. 設定・ファイル読み込み
// ===========================================================

// エラー表示（デバッグ用・本番では適宜調整）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// 必要なファイルを読み込み
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mail_service.php';

use PHPMailer\PHPMailer\Exception;

// =============================================================================================
// 2. 実行制御：Cloud Scheduler用（本番環境）
// =============================================================================================

// 【本番環境用：GCPデプロイ後にコメント解除してください】
// Cloud SchedulerまたはCLI(コマンドライン)からのリクエストのみ許可
$allowed = false;
if (isset($_SERVER['HTTP_X_CLOUDSCHEDULER']) || php_sapi_name() === 'cli') {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo "Access Denied: This endpoint is only accessible via Cloud Scheduler";
    exit;
}

// Cloud Scheduler設定メモ（デプロイ後にやる）
// 名前: remind
// 頻度: 0 9 * * * (毎日午前9時)
// タイムゾーン: Asia/Tokyo
// ターゲット: HTTP
// URL: https://your-cloudrun-url/cron/remind.php
// HTTPメソッド: GET
// ヘッダー追加:
//   キー: X-CloudScheduler
//   値: true

echo "--- リマインダー処理開始: " . date('Y-m-d H:i:s') . " ---\n<br>";

// ===========================================================
// 3. メイン処理
// ===========================================================

try {
    // メールサービスクラスの初期化
    $emailService = new EmailService(); 

    /**
     * SQLロジックの解説:
     * DATEDIFF(CURDATE(), d.date) で「今日 - 期限日」の日数を計算します。
     * - 結果が「-3」なら「期限の3日前」
     * - 結果が「0」なら「当日」
     * - 結果が「1」なら「期限翌日（1日延滞）」
     * * FIND_IN_SET で、計算された日数が `remind_settings` (例: "-1,0,1") に含まれているか確認します。
     */
    $sql = "
        SELECT 
            d.*,
            u.user_name as creditor_name,
            u.email as creditor_email,
            DATEDIFF(CURDATE(), d.date) as diff_days
        FROM debts d
        JOIN users u ON d.creditor_id = u.user_id
        WHERE d.status = 'active'
        AND d.verified = 1
        -- 計算した差分(diff_days)が設定値に含まれているレコードだけ抽出
        AND FIND_IN_SET(DATEDIFF(CURDATE(), d.date), d.remind_settings)
    ";

    $stmt = $pdo->query($sql);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = count($targets);
    echo "送信対象: {$count}件\n<br><br>";

    foreach ($targets as $debt) {
        $diff = (int)$debt['diff_days'];
        $debt_id = $debt['debt_id'];
        
        // ログ出力
        $status_label = ($diff <= 0) ? "期限前/当日(あと" . abs($diff) . "日)" : "延滞({$diff}日目)";
        echo "処理中: ID {$debt_id} [{$status_label}] ... ";

        $debtor_success = false;
        $creditor_success = false;

        // ---------------------------------------------------
        // 分岐: 期限内(<=0) か 期限切れ(>0) か
        // ---------------------------------------------------
        if ($diff <= 0) {
            // ■ リマインダー（前日・当日など）
            // MailServiceには「あと何日(正の数)」で渡す
            $days_until = abs($diff); 
            
            // MailServiceの実装に合わせてメソッドを呼ぶ
            // (まだメソッドがない場合は追加してください)
            $debtor_success = $emailService->sendDebtorReminder($debt, $days_until);
            $creditor_success = $emailService->sendCreditorReminder($debt, $days_until);
            
            echo "→ [リマインダー] ";

        } else {
            // ■ 期限切れ通知（翌日・1週間後など）
            // MailServiceには「超過日数(正の数)」で渡す
            $overdue_days = $diff;

            // MailServiceの実装に合わせてメソッドを呼ぶ
            $debtor_success = $emailService->sendDebtorOverdueNotice($debt, $overdue_days);
            $creditor_success = $emailService->sendCreditorOverdueNotice($debt, $overdue_days);
            
            echo "→ [延滞通知] ";
        }

        // 結果表示
        if ($debtor_success && $creditor_success) {
            echo "送信成功 ✅\n<br>";
        } else {
            echo "一部送信失敗 ⚠️\n<br>";
        }

        // 連続送信によるサーバー負荷軽減 (0.5秒待機)
        usleep(500000); 
    }

} catch (Exception $e) {
    echo "システムエラー発生: " . $e->getMessage() . "\n";
    error_log("DailyAlert Error: " . $e->getMessage());
} catch (PDOException $e) {
    echo "データベースエラー: " . $e->getMessage() . "\n";
}

echo "<br>--- 処理終了 ---\n";

