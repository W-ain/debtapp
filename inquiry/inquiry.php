<?php
// ファイルの場所に合わせてパスを調整してください（inquiryフォルダ内なら '../config.php'）
require_once '../config.php';

// ログインユーザー確認
$user_id = $_SESSION['user_id'] ?? null;
$user_email = $_SESSION['email'] ?? null;

if (!$user_id) {
    header("Location: /login/google_login.php");
    exit;
}

$all_debts = [];
// ソート順の取得（デフォルトは日付が近い順）
$sort_order = $_GET['sort'] ?? 'date_asc';

try {
    // 返済合計額を計算するサブクエリ
    $repaid_subquery = "
        SELECT 
            debt_id, 
            SUM(change_money) AS total_repaid_amount
        FROM debt_change
        GROUP BY debt_id
    ";

    // 1. 貸付データ取得（承認済み verified = 1 のみ）
    // ★ created_at を追加
    $sql_loans = "
        SELECT 
            d.debt_id, 
            d.debtor_name AS counterparty_name, 
            d.debtor_email AS counterparty_email, 
            d.money AS original_amount,
            d.date,
            d.created_at, 
            COALESCE(r.total_repaid_amount, 0) AS total_repaid_amount,
            CASE WHEN d.money <= COALESCE(r.total_repaid_amount, 0) THEN 1 ELSE 0 END AS is_completed_sort,
            0 AS is_borrowing
        FROM debts d 
        LEFT JOIN ({$repaid_subquery}) AS r ON d.debt_id = r.debt_id
        WHERE d.creditor_id = ? AND d.verified = 1
    ";
    $stmt_loans = $pdo->prepare($sql_loans);
    $stmt_loans->execute([$user_id]);
    $loans = $stmt_loans->fetchAll(PDO::FETCH_ASSOC);

    // 2. 借入データ取得（承認済み verified = 1 のみ）
    $borrowings = [];
    if ($user_email) {
        // ★ created_at を追加
        $sql_borrowings = "
            SELECT 
                d.debt_id, 
                u.user_name AS counterparty_name, 
                u.email AS counterparty_email, 
                d.money AS original_amount,
                d.date,
                d.created_at,
                COALESCE(r.total_repaid_amount, 0) AS total_repaid_amount,
                CASE WHEN d.money <= COALESCE(r.total_repaid_amount, 0) THEN 1 ELSE 0 END AS is_completed_sort,
                1 AS is_borrowing
            FROM debts d 
            JOIN users u ON d.creditor_id = u.user_id 
            LEFT JOIN ({$repaid_subquery}) AS r ON d.debt_id = r.debt_id
            WHERE d.debtor_email = ? AND d.verified = 1
        ";
        $stmt_borrowings = $pdo->prepare($sql_borrowings);
        $stmt_borrowings->execute([$user_email]);
        $borrowings = $stmt_borrowings->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. データを統合
    $all_debts = array_merge($loans, $borrowings);

    // 4. ソート処理
    usort($all_debts, function ($a, $b) use ($sort_order) {
        switch ($sort_order) {
            case 'money_desc': // 金額が高い順
                return $b['original_amount'] - $a['original_amount'];

            case 'money_asc': // 金額が低い順
                return $a['original_amount'] - $b['original_amount'];

            case 'created_desc': // 登録日が新しい順
                return strtotime($b['created_at']) - strtotime($a['created_at']);

            case 'created_asc': // 登録日が古い順
                return strtotime($a['created_at']) - strtotime($b['created_at']);

            case 'date_desc': // 返済期日が遠い順
                return strtotime($b['date']) - strtotime($a['date']);

            case 'date_asc': // 返済期日が近い順 (デフォルト)
            default:
                // デフォルトのみ「完済済み」を後ろに回すロジックを適用
                if ($a['is_completed_sort'] !== $b['is_completed_sort']) {
                    return $a['is_completed_sort'] - $b['is_completed_sort'];
                }
                return strtotime($a['date']) - strtotime($b['date']);
        }
    });

} catch (PDOException $e) {
    error_log("データベースエラーが発生しました。エラー内容: " . $e->getMessage());
    exit("
        <script>
            alert('データベースエラーが発生しました。\\n\\n少し時間をおいて再度お試しください。');
            window.location.href = '/home/home.php';
        </script>
    ");
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>トリタテくん</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="/styles.css?v=<?php echo time(); ?>">
    <link rel="icon" href="../favicon.ico?v=1">
</head>

<body>

    <div class="menu-btn" onclick="toggleMenu()">
        <span class="material-icons">menu</span>
    </div>

    <div class="menu-overlay" onclick="toggleMenu()"></div>

    <div class="menu-drawer" id="menuDrawer">
        <div class="menu-close" onclick="toggleMenu()">
            <span class="material-icons">close</span>
        </div>

        <ul class="menu-list">
            <li class="menu-item">
                <a href="/home/home.php" class="menu-link">
                    <span class="material-icons">home</span>
                    ホーム
                </a>
            </li>
            <li class="menu-item">
                <a href="/Regist/Regist.php" class="menu-link">
                    <span class="material-icons">add_circle</span>
                    貸付
                </a>
            </li>
            <li class="menu-item">
                <a href="/inquiry/inquiry.php" class="menu-link">
                    <span class="material-icons">payment</span>
                    返済
                </a>
            </li>
            <li style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;"></li>
            <li class="menu-item">
                <a href="/login/google_login.php" class="menu-link logout">
                    <span class="material-icons">logout</span>
                    ログアウト
                </a>
            </li>
        </ul>
    </div>
    <div class="container">
        <div class="header-card">
            <h2>
                <a href="/home/home.php" class="title-link">トリタテくん</a>
            </h2>
            <p>友達との金銭のやり取りを記録</p>
        </div>

        <div class="section">
            <div class="btn-group">
                <a class="btn new-loan" href="/Regist/Regist.php">貸付</a>
            </div>
        </div>

        <div class="sort-section">
            <form method="GET" action="" id="sortForm">
                <label for="sortSelect" class="sort-label">
                    <span class="material-icons icon-sm">sort</span>並び替え
                </label>
                <select name="sort" id="sortSelect" class="sort-select"
                    onchange="document.getElementById('sortForm').submit()">
                    <option value="date_asc" <?= $sort_order === 'date_asc' ? 'selected' : '' ?>>返済期日が近い順</option>
                    <option value="date_desc" <?= $sort_order === 'date_desc' ? 'selected' : '' ?>>返済期日が遠い順</option>
                    <option value="money_desc" <?= $sort_order === 'money_desc' ? 'selected' : '' ?>>金額が高い順</option>
                    <option value="money_asc" <?= $sort_order === 'money_asc' ? 'selected' : '' ?>>金額が低い順</option>
                    <option value="created_desc" <?= $sort_order === 'created_desc' ? 'selected' : '' ?>>登録が新しい順</option>
                    <option value="created_asc" <?= $sort_order === 'created_asc' ? 'selected' : '' ?>>登録が古い順</option>
                </select>
            </form>
        </div>
        <div class="filter-section-title">種類</div>
        <div class="filter-tabs type-tabs">
            <button class="filter-tab type-tab active" data-filter-type="all">すべて</button>
            <button class="filter-tab type-tab" data-filter-type="loan">貸付</button>
            <button class="filter-tab type-tab" data-filter-type="borrow">借入</button>
        </div>

        <div class="filter-section-title">状態</div>
        <div class="filter-tabs status-tabs">
            <button class="filter-tab status-tab active" data-filter-status="all">すべて</button>
            <button class="filter-tab status-tab" data-filter-status="unpaid">未返済</button>
            <button class="filter-tab status-tab" data-filter-status="partial">返済中</button>
            <button class="filter-tab status-tab" data-filter-status="completed">完済</button>
        </div>

        <?php if (!empty($all_debts)): ?>
            <div id="debtList">
                <?php foreach ($all_debts as $debt): ?>
                    <?php
                    $original_money = $debt['original_amount'];
                    $total_repaid = $debt['total_repaid_amount'];
                    $remaining_money = $original_money - $total_repaid;

                    $is_partial_repaid = ($total_repaid > 0 && $remaining_money > 0);
                    $is_completed = ($remaining_money <= 0);

                    $is_borrowing = (bool) $debt['is_borrowing'];
                    $type_label = $is_borrowing ? '借入' : '貸付';
                    $type_class = $is_borrowing ? 'label-borrow' : 'label-loan';

                    $amount_prefix = '¥';
                    $amount_class = $is_borrowing ? 'borrowing-amount' : 'loan-amount';

                    if ($is_completed) {
                        $status_slug = 'completed';
                    } elseif ($is_partial_repaid) {
                        $status_slug = 'partial';
                    } else {
                        $status_slug = 'unpaid';
                    }

                    $type_slug = $is_borrowing ? 'borrow' : 'loan';
                    ?>

                    <div class="debt-card card-<?= $status_slug ?>" data-status="<?= $status_slug ?>"
                        data-type="<?= $type_slug ?>">

                        <div class="card-header">
                            <div class="debtor-info">
                                <div class="name">
                                    <strong><?= htmlspecialchars($debt['counterparty_name']) ?></strong>
                                    <span class="transaction-label <?= $type_class ?>"><?= $type_label ?></span>
                                </div>
                                <div class="email-address">
                                    <span class="material-icons icon-xs">mail_outline</span>
                                    <span><?= htmlspecialchars($debt['counterparty_email']) ?></span>
                                </div>
                            </div>
                            <div class="amount-info">
                                <strong class="current-amount <?= $amount_class ?>">
                                    <?= $amount_prefix ?>         <?= number_format(max(0, $remaining_money)) ?>
                                </strong>
                                <?php if ($total_repaid > 0): ?>
                                    <span class="original-amount">
                                        元: <span
                                            class="<?= $amount_class ?>"><?= $amount_prefix ?><?= number_format($original_money) ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="due-date-row">
                                <span class="material-icons icon-xs">schedule</span>
                                <span>返済期日: <?= htmlspecialchars($debt['date']) ?></span>

                                <span
                                    class="icon-tag <?= $is_completed ? 'tag-completed' : ($is_partial_repaid ? 'tag-partial' : 'tag-unpaid') ?>">
                                    <span class="material-icons icon-xs">
                                        <?= $is_completed ? 'check_circle' : ($is_partial_repaid ? 'timelapse' : 'receipt_long') ?>
                                    </span>
                                    <?= $is_completed ? '完済' : ($is_partial_repaid ? '返済中' : '未返済') ?>
                                </span>
                            </div>

                            <?php if ($is_partial_repaid): ?>
                                <div class="status-partial" style="background-color: rgba(255,255,255,0.7);">
                                    <span class="material-icons icon-sm">info_outline</span>
                                    <span>一部返済済み (残り<span><?= $amount_prefix ?><?= number_format($remaining_money) ?></span>)</span>
                                </div>
                            <?php elseif ($is_completed): ?>
                                <div class="status-partial" style="background-color: rgba(255,255,255,0.7); color:#2e7d32;">
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
            </div>

            <p id="noDataMessage" style="text-align:center; color:#888; display:none; margin-top:20px;">
                該当するデータはありません
            </p>

        <?php else: ?>
            <div class="section">
                <p style="text-align:center; color:#888;">取引データがありません</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const typeTabs = document.querySelectorAll('.type-tab');
            const statusTabs = document.querySelectorAll('.status-tab');
            const cards = document.querySelectorAll('.debt-card');
            const noDataMsg = document.getElementById('noDataMessage');

            let currentTypeFilter = 'all';
            let currentStatusFilter = 'all';

            function applyFilters() {
                let visibleCount = 0;

                cards.forEach(card => {
                    const cardType = card.getAttribute('data-type');
                    const cardStatus = card.getAttribute('data-status');

                    const typeMatch = (currentTypeFilter === 'all' || currentTypeFilter === cardType);
                    const statusMatch = (currentStatusFilter === 'all' || currentStatusFilter === cardStatus);

                    if (typeMatch && statusMatch) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                if (visibleCount === 0 && cards.length > 0) {
                    if (noDataMsg) noDataMsg.style.display = 'block';
                } else {
                    if (noDataMsg) noDataMsg.style.display = 'none';
                }
            }

            typeTabs.forEach(tab => {
                tab.addEventListener('click', function () {
                    typeTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    currentTypeFilter = this.getAttribute('data-filter-type');
                    applyFilters();
                });
            });

            statusTabs.forEach(tab => {
                tab.addEventListener('click', function () {
                    statusTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    currentStatusFilter = this.getAttribute('data-filter-status');
                    applyFilters();
                });
            });
        });
    </script>

    <script>
        function toggleMenu() {
            const drawer = document.getElementById('menuDrawer');
            const overlay = document.querySelector('.menu-overlay');

            drawer.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        function closeMenu() {
            const drawer = document.getElementById('menuDrawer');
            const overlay = document.querySelector('.menu-overlay');

            drawer.classList.remove('active');
            overlay.classList.remove('active');
        }

        const menuLinks = document.querySelectorAll('.menu-link');
        menuLinks.forEach(link => {
            link.addEventListener('click', () => {
                closeMenu();
            });
        });

        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                closeMenu();
            }
        });
    </script>
</body>


</html>





