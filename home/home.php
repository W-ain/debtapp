<?php
session_start();

// -----------------------------------------------------------
// サーバー環境に合わせたパス設定
// -----------------------------------------------------------
// 設定ファイルの読み込み
require_once '../config.php';

// ★重要: PHPMailerの読み込み
// サーバー上の vendor フォルダの位置に合わせてください
// 一般的には config.php と同じ階層にあることが多いです
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -----------------------------------------------------------
// 0. メール再送処理 (AJAXリクエスト)
// -----------------------------------------------------------
$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

if (isset($data['action']) && $data['action'] === 'resend_email') {
  header('Content-Type: application/json; charset=utf-8');

  $debtor_name = $data['name'] ?? '';
  $email       = $data['email'] ?? '';
  $money       = $data['money'] ?? 0;
  $date        = $data['date'] ?? '';
  $token       = $data['token'] ?? '';

  // 金額の整形
  $money_val = (int)preg_replace('/[^0-9]/', '', $money);

  if (!$email || !$token) {
    echo json_encode(['success' => false, 'message' => 'データ不足エラー']);
    exit;
  }

  $mail = new PHPMailer(true);

  try {
    // --------------------------------------------------
    // SMTP設定 (動作確認済みの設定)
    // --------------------------------------------------
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com'; 
    $mail->SMTPAuth   = true;
    $mail->Username   = 'debtapp005@gmail.com'; 
    $mail->Password   = 'anbi lvnm cykn vnsd';
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('debtapp005@gmail.com', 'DebtApp運営チーム');
    $mail->addAddress($email, $debtor_name);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->Subject = '【DebtApp】貸付確認のお願い';
    
    // ドメイン部分は実際のサーバーURLに合わせて変更が必要な場合があります
    // ここでは汎用的に http://localhost/debtapp/... となっていますが
    // 本番環境なら https://your-domain.com/... に適宜読み替えてください
    $link_url = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . "/debtapp/verify_email.php?token={$token}";

    $mail->Body = "
    <p>{$debtor_name} 様</p>
    <p>以下の内容で貸付が登録されました：</p>
    <ul>
        <li>金額：¥" . number_format($money_val) . "</li>
        <li>返済期限：{$date}</li>
    </ul>
    <p style=\"margin-top: 20px;\">上記の内容をご確認の上、以下のリンクから認証をお願いします。</p>
    <p><a href='{$link_url}'>貸付を確認する</a></p>
    <hr>
    <small>このメールに心当たりがない場合は無視してください。</small>
    ";

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'メール再送成功']);

  } catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => "メール送信失敗: " . $mail->ErrorInfo]);
  }
  exit;
}

// -----------------------------------------------------------
// 1. ログインチェック
// -----------------------------------------------------------
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  header("Location: /login/login.php");
  exit;
}

// -----------------------------------------------------------
// 2. Cookieを使った未読チェックロジック
// -----------------------------------------------------------
$modal_data = null;
$target_debt_id = null;

$cookie_name = 'notified_approval_ids';
$notified_ids_cookie = $_COOKIE[$cookie_name] ?? '';
$notified_ids = explode(',', $notified_ids_cookie);

try {
  // ★変更: idが必要なため SELECT * に変更しました
  $stmt_check = $pdo->prepare("
      SELECT * FROM debts 
      WHERE creditor_id = ? AND verified = 1 
      ORDER BY created_at DESC
  ");
  $stmt_check->execute([$user_id]);
  $approved_debts = $stmt_check->fetchAll(PDO::FETCH_ASSOC);

  foreach ($approved_debts as $ad) {
    // IDカラム名の揺らぎ対応
    $current_id = $ad['debt_id'] ?? $ad['id']; 

    if (!in_array((string) $current_id, $notified_ids)) {
      $modal_data = [
        'title' => '承認のお知らせ',
        'message' => "借主（" . htmlspecialchars($ad['debtor_name']) . "）が<br>貸付（¥" . number_format($ad['money']) . "）を承認しました！"
      ];
      $target_debt_id = $current_id;
      break; 
    }
  }
} catch (PDOException $e) {
  // エラー時は通知無視
}

// -----------------------------------------------------------
// 3. 貸付データ取得（承認済みリスト）
// -----------------------------------------------------------
try {
  $stmt = $pdo->prepare("
        SELECT * FROM debts 
        WHERE creditor_id = ? AND verified = 1
        ORDER BY date ASC
    ");
  $stmt->execute([$user_id]);
  $debts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("データ取得エラー: " . $e->getMessage());
  // 必要に応じてエラー表示
  $debts = [];
}

// -----------------------------------------------------------
// 4. 承認待ちデータ取得（再送機能用）
// -----------------------------------------------------------
$error_message_pending = null;
try {
  // ★変更: emailやtokenが必要なため SELECT * に変更しました
  $stmt_pending = $pdo->prepare("
        SELECT *
        FROM debts 
        WHERE creditor_id = ? AND verified = 0
        ORDER BY created_at DESC
    ");
  $stmt_pending->execute([$user_id]);
  $pending_debts = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $pending_debts = [];
  $error_message_pending = "データ取得エラー";
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
  <style>
    /* 詳細モーダル関連 */
    #resendBtn {
      background-color: #2196F3;
      color: white;
      margin-top: 20px;
    }
    #resendBtn:disabled {
      background-color: #ccc;
      cursor: not-allowed;
    }
    .modal-close-icon {
      position: absolute;
      top: 10px;
      right: 15px;
      cursor: pointer;
      font-size: 24px;
      color: #aaa;
    }
    .detail-content {
      width: 100%;
      margin: 10px 0 20px 0;
      text-align: left;
    }
    .detail-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid #eee;
      padding: 12px 0;
    }
    .detail-label {
      color: #666;
      font-size: 0.9rem;
      font-weight: bold;
    }
    .detail-value {
      color: #333;
      font-weight: bold;
      font-size: 1rem;
    }
  </style>
</head>

<body>

  <div class="modal-overlay" id="notificationModal">
    <div class="modal-box">
      <span class="material-icons" id="modalIcon" style="font-size: 60px; color: #4CAF50; margin-bottom: 10px;">check_circle</span>
      <h3 id="modalTitle">完了</h3>
      <p id="modalMessage">処理が完了しました。</p>
      <button class="modal-close-btn" onclick="closeNotificationModal()">閉じる</button>
    </div>
  </div>

  <div class="modal-overlay" id="detailModal">
    <div class="modal-box" style="position:relative; padding-top:40px;">
      <span class="material-icons modal-close-icon" onclick="closeDetailModal()">close</span>
      <span class="material-icons" style="font-size: 50px; color: #ffa000; margin-bottom: 10px;">mail</span>
      
      <div class="detail-content">
        <div class="detail-row">
          <span class="detail-label">借主</span>
          <span class="detail-value" id="modalDetailName"></span>
        </div>
        <div class="detail-row">
          <span class="detail-label">金額</span>
          <span class="detail-value" id="modalDetailMoney"></span>
        </div>
        <div class="detail-row">
          <span class="detail-label">申請日</span>
          <span class="detail-value" id="modalDetailDate"></span>
        </div>
        <p style="margin-top:15px; font-size:0.85rem; color:#666;">
          承認メールが届いていない場合、以下のボタンから再送できます。
        </p>
      </div>

      <input type="hidden" id="modalDetailEmail">
      <input type="hidden" id="modalDetailToken">

      <button class="modal-close-btn" id="resendBtn" onclick="resendEmail()">メール再送</button>
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
      <li class="menu-item"><a href="/home/home.php" class="menu-link"><span class="material-icons">home</span>ホーム</a></li>
      <li class="menu-item"><a href="/Regist/Regist.php" class="menu-link"><span class="material-icons">add_circle</span>貸付</a></li>
      <li class="menu-item"><a href="/inquiry/inquiry.php" class="menu-link"><span class="material-icons">payment</span>返済</a></li>
      <li style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;"></li>
      <li class="menu-item"><a href="/login/google_login.php" class="menu-link logout"><span class="material-icons">logout</span>ログアウト</a></li>
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

      <?php if ($error_message_pending): ?>
         <p style="color:red; text-align:center; font-size:0.9rem;"><?= htmlspecialchars($error_message_pending) ?></p>
      <?php elseif (!empty($pending_debts)): ?>
        <?php foreach ($pending_debts as $pending): ?>
          <div class="item pending-item" 
               onclick="openDetailModal(this)"
               data-name="<?= htmlspecialchars($pending['debtor_name'] ?? ''); ?>"
               data-money="¥<?= number_format($pending['money'] ?? 0); ?>"
               data-date="<?= htmlspecialchars($pending['date'] ?? ''); ?>"
               data-email="<?= htmlspecialchars($pending['debtor_email'] ?? ''); ?>" 
               data-token="<?= htmlspecialchars($pending['token'] ?? ''); ?>">
            <div>
              <strong><?= htmlspecialchars($pending['debtor_name'] ?? '名称不明'); ?></strong>
              <span class="pending-badge">確認中</span><br>
              <span style="font-size: 0.8rem; color: #666;">申請日: <?= htmlspecialchars($pending['date'] ?? ''); ?></span>
            </div>
            <strong style="color:#e67e22;">¥<?= number_format($pending['money'] ?? 0); ?></strong>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="text-align:center; color:#888; font-size:0.9rem;">現在、承認待ちの項目はありません。</p>
      <?php endif; ?>
    </div>

  </div>

  <script>
    // -----------------------------------------------------------
    // メニュー制御
    // -----------------------------------------------------------
    function toggleMenu() {
      document.getElementById('menuDrawer').classList.toggle('active');
      document.querySelector('.menu-overlay').classList.toggle('active');
    }
    function closeMenu() {
      document.getElementById('menuDrawer').classList.remove('active');
      document.querySelector('.menu-overlay').classList.remove('active');
    }
    document.querySelectorAll('.menu-link').forEach(link => link.addEventListener('click', closeMenu));
    window.addEventListener('pageshow', (event) => { if (event.persisted) closeMenu(); });

    // -----------------------------------------------------------
    // Cookie通知 & 完了モーダル制御
    // -----------------------------------------------------------
    const modal = document.getElementById('notificationModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalIcon = document.getElementById('modalIcon');
    
    // PHPデータ
    const modalData = <?= json_encode($modal_data) ?>;
    const targetDebtId = <?= json_encode($target_debt_id) ?>;
    const cookieName = 'notified_approval_ids';

    function getCookie(name) {
      const value = `; ${document.cookie}`;
      const parts = value.split(`; ${name}=`);
      if (parts.length === 2) return parts.pop().split(';').shift();
      return '';
    }
    function addIdToCookie(id) {
      let currentIds = getCookie(cookieName);
      if (currentIds && currentIds !== '') { currentIds += ',' + id; } 
      else { currentIds = id; }
      document.cookie = `${cookieName}=${currentIds}; path=/; max-age=31536000`;
    }

    // ロード時の承認通知
    if (modalData) {
      modalIcon.textContent = 'notifications_active';
      modalTitle.textContent = modalData.title;
      modalMessage.innerHTML = modalData.message;
      setTimeout(() => { modal.classList.add('active'); }, 100);
      if (targetDebtId) { addIdToCookie(targetDebtId); }
    }

    function closeNotificationModal() {
      modal.classList.remove('active');
    }

    // 完了モーダル表示関数
    function showSuccessModal() {
      modalIcon.textContent = 'check_circle';
      modalTitle.textContent = '送信完了';
      modalMessage.textContent = 'メールが再送されました。';
      modal.classList.add('active');
    }

    // -----------------------------------------------------------
    // 詳細・メール再送モーダル制御
    // -----------------------------------------------------------
    const detailModal = document.getElementById('detailModal');
    const detailName = document.getElementById('modalDetailName');
    const detailMoney = document.getElementById('modalDetailMoney');
    const detailDate = document.getElementById('modalDetailDate');
    const detailEmail = document.getElementById('modalDetailEmail'); 
    const detailToken = document.getElementById('modalDetailToken'); 
    const resendBtn = document.getElementById('resendBtn');

    function openDetailModal(element) {
      detailName.textContent = element.getAttribute('data-name');
      detailMoney.textContent = element.getAttribute('data-money');
      detailDate.textContent = element.getAttribute('data-date');
      detailEmail.value = element.getAttribute('data-email');
      detailToken.value = element.getAttribute('data-token');
      
      resendBtn.disabled = false;
      resendBtn.textContent = 'メール再送';
      detailModal.classList.add('active');
    }

    function closeDetailModal() {
      detailModal.classList.remove('active');
    }

    detailModal.addEventListener('click', function(e) {
      if (e.target === detailModal) closeDetailModal();
    });

    // -----------------------------------------------------------
    // メール再送処理
    // -----------------------------------------------------------
    async function resendEmail() {
      const email = detailEmail.value;
      const token = detailToken.value;
      const name = detailName.textContent;
      const money = detailMoney.textContent;
      const date = detailDate.textContent;

      if(!email || !token) {
        alert("エラー：必要なデータ（メールアドレス等）が見つかりません。");
        return;
      }

      // 送信開始（確認ダイアログなし）
      resendBtn.disabled = true;
      resendBtn.textContent = '送信中...';

      try {
        const response = await fetch('home.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'resend_email', 
            email: email, token: token, name: name, money: money, date: date
          })
        });

        const result = await response.json();

        if (result.success) {
          // 成功時：詳細モーダルを閉じて、完了モーダルを表示
          closeDetailModal();
          showSuccessModal(); 
        } else {
          alert('エラー: ' + result.message);
          resendBtn.disabled = false;
          resendBtn.textContent = 'メール再送';
        }

      } catch (error) {
        console.error('Error:', error);
        alert('通信エラーが発生しました。');
        resendBtn.disabled = false;
        resendBtn.textContent = 'メール再送';
      }
    }
  </script>

</body>
</html>
