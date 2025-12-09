<?php
session_start();

// PHPの読み込みパス
require_once '../config.php';

$user_id = $_SESSION['user_id'] ?? null;
$user_email = $_SESSION['email'] ?? null;
$debt_id = $_SESSION['view_debt_id'] ?? null;

if (!$user_id || !$debt_id) {
    exit('エラー: 必要な情報が不足しています。<a href="/debtapp/inquiry/inquiry.php">inquiry.php</a>から再度アクセスしてください。');
}

try {
    // 【変更点1】created_at をSELECTに追加
    $stmt = $pdo->prepare("
        SELECT 
            d.debt_id,
            d.debtor_name,
            d.debtor_email,
            d.money,
            d.date,
            d.created_at, 
            d.creditor_id,
            d.debt_hash,
            COALESCE(SUM(dc.change_money), 0) AS total_repaid_amount
        FROM debts d
        LEFT JOIN debt_change dc ON d.debt_id = dc.debt_id
        WHERE d.debt_id = ? 
        AND (d.creditor_id = ? OR d.debtor_email = ?)
        GROUP BY d.debt_id
    ");

    $stmt->execute([$debt_id, $user_id, $user_email]);
    $debt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$debt) {
        exit('エラー: 該当する貸付が見つからないか、アクセス権がありません。');
    }

    $remaining_amount = $debt['money'] - $debt['total_repaid_amount'];

    // 貸主判定
    $is_creditor = ($debt['creditor_id'] == $user_id);

    // 改ざんチェック
    // 【変更点2】IDではなく保存日時(created_at)で次のデータを検索
    $stmtNext = $pdo->prepare("SELECT * FROM debts WHERE created_at > ? ORDER BY created_at ASC LIMIT 1");

    // 【変更点3】現在のデータの created_at を基準にする
    $stmtNext->execute([$debt['created_at']]);
    $next = $stmtNext->fetch(PDO::FETCH_ASSOC);

    $tamperClass = "tamper-ok";
    $tamperMessage = "このデータは改ざんされていません";

    if ($next) {
        $hash_data = [
            'debt_id' => $debt['debt_id'],
            'debtor_name' => $debt['debtor_name'],
            'debtor_email' => $debt['debtor_email'],
            'money' => $debt['money'],
            'date' => $debt['date'],
            'creditor_id' => $debt['creditor_id'],
            'debt_hash' => $debt['debt_hash']
        ];
        $hash_input = json_encode($hash_data, JSON_UNESCAPED_UNICODE);
        $calc_hash = hash('sha256', $hash_input);
        if ($next['debt_hash'] !== $calc_hash) {
            $tamperClass = "tamper-warning";
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
    <link rel="stylesheet" href="/debtapp/styles.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="container">
        <div class="detail-card">
            <a href="/debtapp/inquiry/inquiry.php" class="back-link">＜ 戻る</a>

            <h2>返済を記録</h2>
            <div class="detail-row"><span class="label">借主</span><span
                    class="value"><?= htmlspecialchars($debt['debtor_name']) ?></span></div>
            <div class="detail-row"><span class="label">元金</span><span
                    class="value">¥<?= number_format($debt['money']) ?></span></div>
            <div class="detail-row"><span class="label">返済済み額</span><span
                    class="value">¥<?= number_format($debt['total_repaid_amount']) ?></span></div>
            <div class="detail-row"
                style="background:#f9f9f9; border-bottom:1px solid #ddd; margin-top:10px; padding-top:12px; padding-bottom:12px;">
                <span class="label highlight">現在の残り金額</span><span
                    class="value highlight">¥<?= number_format($remaining_amount) ?></span>
            </div>

            <div class="tamper-check <?= $tamperClass ?>">
                <?= htmlspecialchars($tamperMessage) ?>
            </div>

            <?php if ($remaining_amount > 0): ?>
                <?php if ($is_creditor): ?>
                    <form action="/debtapp/debt_change/process.php" method="POST">
                        <label for="repay_amount">今回返済された金額 (¥)</label>
                        <input type="number" id="repay_amount" name="repay_amount" min="1" max="<?= $remaining_amount ?>"
                            required>
                        <input type="hidden" name="debt_id" value="<?= $debt_id ?>">
                        <input type="hidden" name="remaining_amount" value="<?= $remaining_amount ?>">
                        <button type="submit">返済を確定する</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p style="text-align:center; color:green; font-weight:bold; margin-top:20px;">🎉 この貸付は既に完済済みです。</p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>