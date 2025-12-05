<?php
session_start();
require_once '../config.php';

$user_id    = $_SESSION['user_id'] ?? null;
$user_email = $_SESSION['email'] ?? null;
$debt_id    = $_SESSION['view_debt_id'] ?? null;

if (!$user_id || !$debt_id || !$user_email) {
    exit('エラー: 必要な情報が不足しているか、直接アクセスされています。');
}

$remaining_amount = 0;

try {
    // データと返済記録取得
    $stmt = $pdo->prepare("
        SELECT 
            d.debt_id,
            d.debtor_name,
            d.debtor_email,
            d.money,
            d.date,
            d.creditor_id,
            d.debt_hash,
            u.user_name AS creditor_name,     
            u.email AS creditor_email,        
            COALESCE(SUM(dc.change_money), 0) AS total_repaid_amount
        FROM debts d
        LEFT JOIN debt_change dc ON d.debt_id = dc.debt_id
        JOIN users u ON d.creditor_id = u.user_id 
        WHERE d.debt_id = ? 
        AND (
            d.creditor_id = ? OR d.debtor_email = ? 
        )
        GROUP BY 
            d.debt_id, d.debtor_name, d.debtor_email, d.money, d.date, 
            d.creditor_id, d.debt_hash, u.user_name, u.email
    ");
    $stmt->execute([$debt_id, $user_id, $user_email]);
    $debt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$debt) {
        exit('エラー: 該当する取引が見つからないか、アクセス権がありません。');
    }

    $original_amount   = $debt['money'];
    $total_repaid      = $debt['total_repaid_amount'];
    $remaining_amount  = $original_amount - $total_repaid;

    // ロール判定と表示設定
    $is_creditor = ($debt['creditor_id'] == $user_id);

    if ($is_creditor) {
        $counterparty_name    = $debt['debtor_name'];
        $original_label       = "貸した金額 (元金)";
        $repaid_label         = "返済された金額";
        $remaining_label      = "回収すべき金額 (残り)";
        $label_prefix         = '¥';
        $highlight_class      = 'loan-highlight';
        $is_repayment_allowed = ($remaining_amount > 0);
        $role_message         = "あなたは貸主です。";
    } else {
        $counterparty_name    = $debt['creditor_name'];
        $original_label       = "借りた金額 (元金)";
        $repaid_label         = "返済した金額";
        $remaining_label      = "返済すべき金額 (残り)";
        $label_prefix         = '¥';
        $highlight_class      = 'borrowing-highlight';
        $is_repayment_allowed = false;
        $role_message         = "あなたは借主です。";
    }

    // 改ざんチェック
    $stmtNext = $pdo->prepare("SELECT debt_hash FROM debts WHERE debt_id > ? ORDER BY debt_id ASC LIMIT 1");
    $stmtNext->execute([$debt_id]);
    $next = $stmtNext->fetch(PDO::FETCH_ASSOC);

    $tamperClass   = "tamper-ok";
    $tamperMessage = "このデータは改ざんされていません";

    if ($next) {
        $hash_data = [
            'debt_id'      => $debt['debt_id'],
            'debtor_name'  => $debt['debtor_name'],
            'debtor_email' => $debt['debtor_email'],
            'money'        => $debt['money'],
            'date'         => $debt['date'],
            'creditor_id'  => $debt['creditor_id'],
            'debt_hash'    => $debt['debt_hash']
        ];

        $hash_input = json_encode($hash_data, JSON_UNESCAPED_UNICODE);
        $calc_hash  = hash('sha256', $hash_input);

        if ($next['debt_hash'] !== $calc_hash) {
            $tamperClass   = "tamper-warning";
            $tamperMessage = "⚠️ このデータは改ざんされています";
        }
    }

} catch (PDOException $e) {
    exit("DBエラー: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>返済記録</title>
    <link rel="stylesheet" href="../styles.css">
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementsByTagName('form')[0];
            const submitButton = form ? form.querySelector('button[type="submit"]') : null;

            if (form && submitButton) {
                form.addEventListener('submit', function(event) {
                    if (submitButton.disabled) {
                        event.preventDefault();
                        return;
                    }
                    submitButton.disabled = true;
                    submitButton.innerHTML = '処理中...';
                });
            }
        });
    </script>
    <style>
        /* Updated to clean white design with subtle shadows */
        body {
            background: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #1a1a1a;
        }
        
        .detail-card {
            max-width: 560px;
            margin: 40px auto;
            background: #ffffff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 8px 24px rgba(0, 0, 0, 0.06);
            position: relative;
        }
        
        .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #999;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 8px;
            line-height: 1;
        }
        
        .close-btn:hover {
            color: #333;
            background: #f5f5f5;
        }
        
        h2 {
            margin: 0 0 32px 0;
            font-size: 24px;
            font-weight: 600;
            color: #1a1a1a;
            letter-spacing: -0.02em;
        }
        
        .role-info {
            text-align: center;
            padding: 12px 20px;
            margin-bottom: 28px;
            background: #f8f9fa;
            border-radius: 12px;
            font-weight: 500;
            font-size: 14px;
            color: #495057;
            border: 1px solid #e9ecef;
        }
        
        .detail-section {
            margin-bottom: 24px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 15px;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .label {
            color: #6c757d;
            font-weight: 500;
            font-size: 14px;
        }
        
        .value {
            font-weight: 600;
            color: #212529;
            font-size: 16px;
        }
        
        .remaining-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
        }
        
        .remaining-card .detail-row {
            border: none;
            padding: 0;
        }
        
        .remaining-card .label {
            font-size: 15px;
            font-weight: 600;
            color: #495057;
        }
        
        .remaining-card .value {
            font-size: 28px;
            font-weight: 700;
        }
        
        .loan-highlight {
            color: #0066cc;
        }
        
        .borrowing-highlight {
            color: #dc3545;
        }
        
        .tamper-check {
            margin-top: 20px;
            padding: 12px 16px;
            border-radius: 10px;
            font-weight: 500;
            text-align: center;
            font-size: 13px;
        }
        
        .tamper-ok {
            background: #d1f4e0;
            color: #0d6832;
            border: 1px solid #9ce5b9;
        }
        
        .tamper-warning {
            background: #ffe5e5;
            color: #c41e3a;
            border: 1px solid #ffb3b3;
        }
        
        form {
            margin-top: 32px;
            padding-top: 32px;
            border-top: 1px solid #e9ecef;
        }
        
        label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            font-size: 14px;
            color: #495057;
        }
        
        input[type=number] {
            width: 100%;
            padding: 14px 16px;
            margin-bottom: 20px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 16px;
            box-sizing: border-box;
            transition: all 0.2s ease;
            font-family: inherit;
        }
        
        input[type=number]:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        
        button[type=submit] {
            width: 100%;
            padding: 16px;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }
        
        button[type=submit]:hover {
            background: #0052a3;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 102, 204, 0.3);
        }
        
        button[type=submit]:active {
            transform: translateY(0);
        }
        
        button[type=submit]:disabled {
            background: #adb5bd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .complete-message {
            text-align: center;
            padding: 24px;
            margin-top: 24px;
            background: #d1f4e0;
            border: 2px solid #9ce5b9;
            border-radius: 12px;
            color: #0d6832;
            font-weight: 600;
            font-size: 15px;
        }
        
        .repay-disabled-message {
            text-align: center;
            padding: 20px;
            margin-top: 24px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }
        
        @media (max-width: 640px) {
            .detail-card {
                padding: 28px 24px;
                margin: 20px auto;
            }
            
            h2 {
                font-size: 20px;
            }
            
            .remaining-card .value {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="detail-card">
        <a href="../inquiry/inquiry.php" class="close-btn">×</a>
        <h2>取引詳細・返済記録</h2>

        <div class="role-info">
            <?= $role_message ?>
        </div>

        <div class="detail-section">
            <div class="detail-row">
                <span class="label">相手</span>
                <span class="value"><?= htmlspecialchars($counterparty_name) ?></span>
            </div>

            <div class="detail-row">
                <span class="label"><?= $original_label ?></span>
                <span class="value <?= $highlight_class ?>">
                    <?= $label_prefix ?><?= number_format($original_amount) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="label"><?= $repaid_label ?></span>
                <span class="value <?= $highlight_class ?>">
                    <?= $label_prefix ?><?= number_format($total_repaid) ?>
                </span>
            </div>
        </div>

        <div class="remaining-card">
            <div class="detail-row">
                <span class="label"><?= $remaining_label ?></span>
                <span class="value <?= $highlight_class ?>">
                    <?= $label_prefix ?><?= number_format($remaining_amount) ?>
                </span>
            </div>
        </div>

        <div class="tamper-check <?= $tamperClass ?>">
            <?= htmlspecialchars($tamperMessage) ?>
        </div>

        <?php if ($is_repayment_allowed): ?>
            <form action="process.php" method="POST">
                <label for="repay_amount">今回返済された金額 (¥)</label>
                <input type="number"
                       id="repay_amount"
                       name="repay_amount"
                       min="1"
                       max="<?= $remaining_amount ?>"
                       required>
                <input type="hidden" name="debt_id" value="<?= $debt_id ?>">
                <input type="hidden" name="remaining_amount" value="<?= $remaining_amount ?>">
                <button type="submit">返済を確定する</button>
            </form>

        <?php elseif ($remaining_amount <= 0): ?>
            <div class="complete-message">
                この取引は既に完済済みです。
            </div>

        <?php else: ?>
            <div class="repay-disabled-message">
                あなたは借主です。返済記録は貸主のみが行えます。
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
