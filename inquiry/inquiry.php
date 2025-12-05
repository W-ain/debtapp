<?php
session_start();
require_once '../config.php';

// ログインユーザー確認
$user_id = $_SESSION['user_id'] ?? null;
// 【注】メールアドレスをセッションキー 'email' から取得するロジックを維持
$user_email = $_SESSION['email'] ?? null; // 借入データ取得に必要

if (!$user_id) {
    // 【修正】login.phpが存在しないため、Google認証ページにリダイレクト
    header("Location: ../login/google_login.php");
    exit;
}

$all_debts = [];

try {
    // 返済合計額を計算するサブクエリ (貸付・借入で共通)
    $repaid_subquery = "
        SELECT
            `debt_id`,
            SUM(`change_money`) AS total_repaid_amount
        FROM `debt_change`
        GROUP BY `debt_id`
    ";

    // ----------------------------------------------------
    // 1. 貸付データ取得 (ログイン中のユーザーが貸しているリスト)
    // ----------------------------------------------------
    $sql_loans = "
        SELECT 
            d.debt_id, 
            d.debtor_name AS counterparty_name, 
            d.debtor_email AS counterparty_email, 
            d.money AS original_amount,
            d.date,
            COALESCE(r.total_repaid_amount, 0) AS total_repaid_amount,
            CASE WHEN d.money <= COALESCE(r.total_repaid_amount, 0) THEN 1 ELSE 0 END AS is_completed_sort,
            0 AS is_borrowing
        FROM debts d 
        LEFT JOIN ({$repaid_subquery}) AS r ON d.debt_id = r.debt_id
        WHERE d.creditor_id = ?
    ";
    $stmt_loans = $pdo->prepare($sql_loans);
    $stmt_loans->execute([$user_id]);
    $loans = $stmt_loans->fetchAll(PDO::FETCH_ASSOC);

    // ----------------------------------------------------
    // 2. 借入データ取得 (ログイン中のユーザーが借りているリスト)
    // ----------------------------------------------------
    $borrowings = [];
    if ($user_email) {
        $sql_borrowings = "
            SELECT 
                d.debt_id, 
                u.user_name AS counterparty_name,
                u.email AS counterparty_email,
                d.money AS original_amount,
                d.date,
                COALESCE(r.total_repaid_amount, 0) AS total_repaid_amount,
                CASE WHEN d.money <= COALESCE(r.total_repaid_amount, 0) THEN 1 ELSE 0 END AS is_completed_sort,
                1 AS is_borrowing
            FROM debts d 
            JOIN users u ON d.creditor_id = u.user_id 
            LEFT JOIN ({$repaid_subquery}) AS r ON d.debt_id = r.debt_id
            WHERE d.debtor_email = ?
        ";
        $stmt_borrowings = $pdo->prepare($sql_borrowings);
        $stmt_borrowings->execute([$user_email]);
        $borrowings = $stmt_borrowings->fetchAll(PDO::FETCH_ASSOC);
    }

    // ----------------------------------------------------
    // 3. データを統合しソート
    // ----------------------------------------------------
    $all_debts = array_merge($loans, $borrowings);

    usort($all_debts, function ($a, $b) {
        if ($a['is_completed_sort'] !== $b['is_completed_sort']) {
            return $a['is_completed_sort'] - $b['is_completed_sort'];
        }
        return strtotime($a['date']) - strtotime($b['date']);
    });
} catch (PDOException $e) {
    exit("データベースエラーが発生しました。エラー内容: " . $e->getMessage());
} catch (Exception $e) {
    exit("エラーが発生しました: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>貸し借り管理</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="inquiry.css">
    <style>
        .loan-amount {
            color: #4285f4;
        }

        .borrowing-amount {
            color: #d9534f;
        }

        /* ---------------------------------------------------- */
        /* 修正: ×ボタンのスタイルと親要素の設定 */
        /* ---------------------------------------------------- */
        .container {
            max-width: 520px;
            margin: 0 auto;
            padding: 20px;
        }

        .header-card {
            position: relative;
            padding-bottom: 30px;
        }

        .close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 1.8rem;
            color: #ccc;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }

        .close-btn:hover {
            color: #333;
        }
    </style>
</head>

<body>
    <div class="container">

        <div class="header-card">
            <a href="../home/home.php" class="close-btn">×</a>
            <h2>貸し借り管理 (照会)</h2>
            <p>友達との金銭のやり取りを記録</p>
        </div>

        <div class="section">
            <div class="btn-group">
                <a class="btn new-loan" href="../Regist/Regist.php?back=inquiry">＋ 新規貸付</a>
            </div>
        </div>

        <?php if (!empty($all_debts)): ?>
            <?php foreach ($all_debts as $debt): ?>
                <?php
                $original_money = $debt['original_amount'];
                $total_repaid = $debt['total_repaid_amount'];
                $remaining_money = $original_money - $total_repaid;

                $is_partial_repaid = ($total_repaid > 0 && $remaining_money > 0);
                $is_completed = ($remaining_money <= 0);

                $is_borrowing = (bool) $debt['is_borrowing'];
                $transaction_label = $is_borrowing ? 'からの借入' : 'への貸付';

                $amount_prefix = '¥';
                $amount_class = $is_borrowing ? 'borrowing-amount' : 'loan-amount';
                ?>

                <div class="debt-card">
                    <div class="card-header">
                        <div class="debtor-info">
                            <div class="name">
                                <strong><?= htmlspecialchars($debt['counterparty_name']) ?></strong>
                                <span class="transaction-label"><?= $transaction_label ?></span>
                            </div>
                            <div class="email-address">
                                <span class="material-icons icon-xs">mail_outline</span>
                                <span><?= htmlspecialchars($debt['counterparty_email']) ?></span>
                            </div>
                        </div>

                        <div class="amount-info">
                            <strong class="current-amount <?= $amount_class ?>">
                                <?= $amount_prefix ?><?= number_format(max(0, $remaining_money)) ?>
                            </strong>

                            <?php if ($total_repaid > 0): ?>
                                <span class="original-amount">
                                    元: <span class="<?= $amount_class ?>"><?= $amount_prefix ?><?= number_format($original_money) ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="due-date-row">
                            <span class="material-icons icon-xs">schedule</span>
                            <span>返済期日: <?= htmlspecialchars($debt['date']) ?></span>

                            <span class="icon-tag <?= $is_completed ? 'tag-receipt' : ($is_partial_repaid ? 'tag-voice' : 'tag-receipt') ?>">
                                <span class="material-icons icon-xs">
                                    <?= $is_completed ? 'check_circle' : ($is_partial_repaid ? 'mic' : 'receipt_long') ?>
                                </span>
                                <?= $is_completed ? '完済' : ($is_partial_repaid ? '返済中' : '未返済') ?>
                            </span>
                        </div>

                        <?php if ($is_partial_repaid): ?>
                            <div class="status-partial">
                                <span class="material-icons icon-sm">info_outline</span>
                                <span>一部返済済み (残り<span><?= $amount_prefix ?><?= number_format($remaining_money) ?></span>)</span>
                            </div>
                        <?php elseif ($is_completed): ?>
                            <div class="status-partial" style="background:#e6ffe6; color:#3c9c3c;">
                                <span class="material-icons icon-sm">check_circle</span>
                                <span>この取引は完済しています。</span>
                            </div>
                        <?php endif; ?>

                        <a href="set_session.php?id=<?= $debt['debt_id'] ?>" class="btn btn-primary">
                            返済を記録・確認
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="section">
                <p style="text-align:center; color:#888;">取引データがありません</p>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>