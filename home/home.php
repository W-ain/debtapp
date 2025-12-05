<?php
session_start();
require_once '../config.php';

// ログインユーザー確認
$user_id = $_SESSION['user_id'] ?? null;
$user_email = $_SESSION['email'] ?? null;

// 認証チェック
if (!$user_id) {
    header("Location: ../login/google_login.php");
    exit;
}

$debts = [];
// ★ 追加: 承認待ちデータ用変数
$pending_loans = [];

try {
    // 現在の日付から7日後までの日付を計算
    $today = date('Y-m-d');
    $one_week_later = date('Y-m-d', strtotime('+7 days'));

    // --- 1. 期限が1週間以内の取引を取得 (既存ロジック) ---

    // 貸付データ (Creditorとして) の取得 - 期限が1週間以内のもの
    $sql_loans = "
        SELECT 
            d.debt_id,
            d.money, 
            d.date, 
            d.debtor_name AS counterparty_name, 
            'loan' AS type
        FROM debts d 
        WHERE d.creditor_id = ? 
            AND d.verified = 1 
            AND d.status = 'active'
            AND d.date BETWEEN ? AND ?
    ";

    $stmt_loans = $pdo->prepare($sql_loans);
    $stmt_loans->execute([$user_id, $today, $one_week_later]);
    $loans = $stmt_loans->fetchAll(PDO::FETCH_ASSOC);

    // 借入データ (Debtorとして) の取得 - 期限が1週間以内のもの
    $borrowings = [];
    if ($user_email) {
        $sql_borrowings = "
            SELECT 
                d.debt_id,
                d.money, 
                d.date, 
                u.user_name AS counterparty_name, 
                'borrowing' AS type
            FROM debts d
            JOIN users u ON d.creditor_id = u.user_id 
            WHERE d.debtor_email = ? 
                AND d.verified = 1 
                AND d.status = 'active'
                AND d.date BETWEEN ? AND ?
        ";

        $stmt_borrowings = $pdo->prepare($sql_borrowings);
        $stmt_borrowings->execute([$user_email, $today, $one_week_later]);
        $borrowings = $stmt_borrowings->fetchAll(PDO::FETCH_ASSOC);
    }

    // 両方のデータを統合し、期限が近い順に並べ替える
    $combined_debts = array_merge($loans, $borrowings);

    usort($combined_debts, function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    $debts = $combined_debts;

    // --- 2. 貸主側の承認待ち取引を取得 (新規追加) ---

    $sql_pending_loans = "
        SELECT 
            d.debt_id,
            d.money, 
            d.date, 
            d.debtor_name AS counterparty_name
        FROM debts d 
        WHERE d.creditor_id = ? 
            AND d.verified = 0 
            AND d.status = 'active'
        ORDER BY d.debt_id DESC
    ";

    $stmt_pending_loans = $pdo->prepare($sql_pending_loans);
    $stmt_pending_loans->execute([$user_id]);
    $pending_loans = $stmt_pending_loans->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    exit("データ取得中にエラーが発生しました: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>ホーム | 借金管理アプリ</title>
<style>
/* Clean white background design with modern styling */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Kaku Gothic ProN", sans-serif;
    background: #ffffff;
    color: #1a1a1a;
    line-height: 1.6;
    padding: 20px;
    min-height: 100vh;
}

.container {
    max-width: 600px;
    margin: 0 auto;
}

.section {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.06);
    border: 1px solid #f0f0f0;
}

.section h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 16px;
    letter-spacing: -0.01em;
}

.btn-group {
    display: flex;
    gap: 12px;
}

.btn {
    flex: 1;
    padding: 16px 20px;
    border-radius: 10px;
    text-align: center;
    font-weight: 600;
    font-size: 0.95rem;
    color: #fff;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.btn:active {
    transform: translateY(0);
}

.new-loan {
    background: #4285f4;
}

.new-loan:hover {
    background: #357ae8;
}

.view-list {
    background: #5f6368;
}

.view-list:hover {
    background: #4d5156;
}

.item {
    border-radius: 12px;
    padding: 16px;
    background: #fafafa;
    border: 1px solid #e8e8e8;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    transition: all 0.2s ease;
}

/* ★ 承認待ちアイテムのスタイル (新規追加) ★ */
.pending-item {
    background: #f1e7feff;
    border: 1px solid #e3d3f6ff;
    box-shadow: 0 2px 4px rgba(255, 193, 7, 0.2);
}

.pending-item strong {
    color: #b64cf4ff;
}

.item:hover {
    background: #f5f5f5;
    border-color: #d0d0d0;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.item:first-of-type {
    margin-top: 0;
}

.item > div {
    flex: 1;
}

.item strong {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1a1a1a;
    display: block;
    margin-bottom: 4px;
}

.item span {
    font-size: 0.85rem;
    color: #666;
}

.activity {
    background: #fafafa;
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 12px;
    border: 1px solid #e8e8e8;
    color: #666;
    text-align: center;
}

.color-borrowing {
    color: #d9534f;
    font-size: 1.1rem;
    font-weight: 700;
    white-space: nowrap;
}

.color-loan {
    color: #4285f4;
    font-size: 1.1rem;
    font-weight: 700;
    white-space: nowrap;
}

.empty-state {
    text-align: center;
    padding: 32px 16px;
    color: #999;
    font-size: 0.9rem;
}

.logout-link {
    display: inline-block;
    margin-top: 12px;
    color: #d9534f;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s ease;
}

.logout-link:hover {
    color: #c9302c;
    text-decoration: underline;
}

@media (max-width: 600px) {
    body {
        padding: 12px;
    }

    .section {
        padding: 20px;
    }

    .btn-group {
        flex-direction: column;
    }

    .btn {
        width: 100%;
    }
}

.deadline-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 8px;
}

.deadline-urgent {
    background: #fee;
    color: #c00;
}

.deadline-warning {
    background: #fff4e6;
    color: #e67700;
}

.deadline-normal {
    background: #e8f5e9;
    color: #2e7d32;
}
</style>
</head>

<body>
<div class="container">

    <div class="section">
        <div class="btn-group">
            <a class="btn new-loan" href="../Regist/Regist.php">+ 新規貸付</a>
            <a class="btn view-list" href="../inquiry/inquiry.php">照会</a>
        </div>
    </div>

    <div class="section">
        <h3> 承認待ちの貸付</h3>

        <?php if (!empty($pending_loans)): ?>
            <?php foreach ($pending_loans as $loan): ?>
                <div class="item pending-item">
                    <div>
                        <strong>
                            承認待ち: <?= htmlspecialchars($loan['counterparty_name']); ?>
                        </strong>
                        <span><?= htmlspecialchars($loan['date']); ?> 期限</span>
                    </div>
                    <strong class="color-loan">
                        ¥<?= number_format($loan['money']); ?>
                    </strong>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">現在、相手の承認を待っている取引はありません</div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h3>期限が近い取引 (1週間以内)</h3>

        <?php if (!empty($debts)): ?>
            <?php foreach ($debts as $debt): 
                $amount_class = ($debt['type'] === 'loan') ? 'color-loan' : 'color-borrowing';

                $deadline_date = strtotime($debt['date']);
                $today_timestamp = strtotime($today);
                $days_until = floor(($deadline_date - $today_timestamp) / (60 * 60 * 24));

                if ($days_until <= 2) {
                    $badge_class = 'deadline-urgent';
                    $badge_text = $days_until == 0 ? '今日期限' : '残り' . $days_until . '日';
                } elseif ($days_until <= 4) {
                    $badge_class = 'deadline-warning';
                    $badge_text = '残り' . $days_until . '日';
                } else {
                    $badge_class = 'deadline-normal';
                    $badge_text = '残り' . $days_until . '日';
                }
            ?>
                <div class="item">
                    <div>
                        <strong>
                            <?php 
                                if ($debt['type'] === 'loan') {
                                    echo '貸付: ' . htmlspecialchars($debt['counterparty_name']);
                                } else {
                                    echo '借入: ' . htmlspecialchars($debt['counterparty_name']);
                                }
                            ?>
                        </strong>

                        <span>
                            📅 <?= htmlspecialchars($debt['date']); ?>
                            <span class="deadline-badge <?= $badge_class ?>"><?= $badge_text ?></span>
                        </span>
                    </div>
                    <strong class="<?= $amount_class; ?>">
                        ¥<?= number_format($debt['money']); ?>
                    </strong>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="empty-state">1週間以内に期限が来る取引はありません</div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h3>その他のアクティビティ</h3>
        <div class="activity">
            その他のアクティビティ機能は未実装です。
        </div>
    </div>

</div>
</body>
</html>
