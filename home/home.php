<?php
session_start();
// ファイルの位置に合わせてパスを調整してください
// もし home.php が debtapp直下にあるなら 'config.php'
// もし debtapp/home/ フォルダの中にあるなら '../config.php' です
require_once '../config.php';

// ログインユーザー確認
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  // ログインしていない場合はログイン画面へ（ルートパス指定）
  header("Location: /login/login.php");
  exit;
}

// -----------------------------------------------------------
// Cookieを使った未読チェックロジック (本番用)
// -----------------------------------------------------------
$modal_data = null;
$target_debt_id = null;

// Cookieから「すでに通知済みのIDリスト」を取得
$cookie_name = 'notified_approval_ids';
$notified_ids_cookie = $_COOKIE[$cookie_name] ?? '';
$notified_ids = explode(',', $notified_ids_cookie);

try {
  // 自分宛(creditor_id)で、承認済み(verified=1)のデータを全て取得
  // created_at が新しい順（直近の承認）
  $stmt_check = $pdo->prepare("
      SELECT * FROM debts 
      WHERE creditor_id = ? AND verified = 1 
      ORDER BY created_at DESC
  ");
  $stmt_check->execute([$user_id]);
  $approved_debts = $stmt_check->fetchAll(PDO::FETCH_ASSOC);

  // 取得した承認済みデータの中で、CookieにIDがないものを探す
  foreach ($approved_debts as $ad) {
    // ID (debt_id) がCookieのリストに含まれていなければ「未読」とみなす
    // ※debt_id が null の場合は id を見るようにフォールバック
    $current_id = $ad['debt_id'] ?? $ad['id'];

    if (!in_array((string) $current_id, $notified_ids)) {

      $modal_data = [
        'title' => '承認のお知らせ',
        'message' => "借主（" . htmlspecialchars($ad['debtor_name']) . "）が<br>貸付（¥" . number_format($ad['money']) . "）を承認しました！"
      ];

      $target_debt_id = $current_id;
      break; // 1回のリロードにつき1件ずつ表示する
    }
  }

} catch (PDOException $e) {
  // エラー時は通知を出さない
}
  

// 1. 貸付データ取得（期限順、承認済み verified = 1 のもの）
try {
  $stmt = $pdo->prepare("
        SELECT debtor_name, money, date 
        FROM debts 
        WHERE creditor_id = ? AND verified = 1
        ORDER BY date ASC
    ");
  $stmt->execute([$user_id]);
  $debts = $stmt->fetchAll();
} catch (PDOException $e) {
  exit("Database query failed: " . $e->getMessage());
}

// 2. 承認待ちデータ取得（verified = 0 のもの）
try {
  $stmt_pending = $pdo->prepare("
        SELECT debtor_name, money, date 
        FROM debts 
        WHERE creditor_id = ? AND verified = 0
        ORDER BY created_at DESC
    ");
  $stmt_pending->execute([$user_id]);
  $pending_debts = $stmt_pending->fetchAll();
} catch (PDOException $e) {
  $pending_debts = [];
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ホーム | 借金管理アプリ</title>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link rel="stylesheet" href="/styles.css?v=<?php echo time(); ?>">
</head>

<body>
  <div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
      <span class="material-icons"
        style="font-size: 60px; color: #4CAF50; margin-bottom: 10px;">notifications_active</span>
      <h3 id="modalTitle">完了</h3>
      <p id="modalMessage">処理が完了しました。</p>
      <button class="modal-close-btn" onclick="closeNotificationModal()">確認しました</button>
    </div>
  </div>
  
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

    <div class="section">
      <div class="btn-group">
        <a class="btn new-loan" href="/Regist/Regist.php">貸付</a>
        <a class="btn view-list" href="/inquiry/inquiry.php">返済</a>
      </div>
    </div>

    <div class="section">
      <h3>期限が近い貸付</h3>
      <?php if (!empty($debts)): ?>
        <?php foreach ($debts as $debt): ?>
          <div class="item">
            <div>
              <strong><?= htmlspecialchars($debt['debtor_name']); ?></strong><br>
              <span>📅 <?= htmlspecialchars($debt['date']); ?></span>
            </div>
            <strong style="color:#4285f4;">¥<?= number_format($debt['money']); ?></strong>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="text-align:center; color:#888;">貸付データがありません</p>
      <?php endif; ?>
    </div>

    <div class="section">
      <h3 style="display:flex; align-items:center;">
        <span class="material-icons" style="margin-right:5px; color:#ffa000;">hourglass_top</span>
        承認待ちリスト
      </h3>

      <?php if (!empty($pending_debts)): ?>
        <?php foreach ($pending_debts as $pending): ?>
          <div class="item pending-item">
            <div>
              <strong><?= htmlspecialchars($pending['debtor_name']); ?></strong>
              <span class="pending-badge">確認中</span><br>
              <span style="font-size: 0.8rem; color: #666;">申請日: <?= htmlspecialchars($pending['date']); ?></span>
            </div>
            <strong style="color:#e67e22;">¥<?= number_format($pending['money']); ?></strong>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="text-align:center; color:#888; font-size:0.9rem;">現在、承認待ちの項目はありません。</p>
      <?php endif; ?>
    </div>

  </div>

  <script>
    // メニューの開閉を切り替える関数
    function toggleMenu() {
      const drawer = document.getElementById('menuDrawer');
      const overlay = document.querySelector('.menu-overlay');

      drawer.classList.toggle('active');
      overlay.classList.toggle('active');
    }

    // メニューを「強制的に閉じる」関数
    function closeMenu() {
      const drawer = document.getElementById('menuDrawer');
      const overlay = document.querySelector('.menu-overlay');

      drawer.classList.remove('active');
      overlay.classList.remove('active');
    }

    // 1. メニュー内のリンクをクリックしたら自動で閉じる
    const menuLinks = document.querySelectorAll('.menu-link');
    menuLinks.forEach(link => {
      link.addEventListener('click', () => {
        closeMenu();
      });
    });

    // 2. ブラウザの「戻る」ボタンで戻ってきた時にメニューを閉じる
    window.addEventListener('pageshow', (event) => {
      // event.persisted はキャッシュから読み込まれた（戻るボタン等）場合に true
      if (event.persisted) {
        closeMenu();
      }
    });
  </script>

</body>


</html>


