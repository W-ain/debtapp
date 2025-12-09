<?php
session_start();
require_once '../config.php'; // DB接続

// ログインユーザー確認
if (!isset($_SESSION['user_id'])) {
  header("Location: ../login/google_login.php");
  exit;
}

// オートコンプリート用の候補リスト取得
$past_partners = [];
try {
  $stmt = $pdo->prepare("
        SELECT DISTINCT debtor_email 
        FROM debts 
        WHERE creditor_id = ? AND debtor_email != ''
        ORDER BY debt_id DESC
    ");
  $stmt->execute([$_SESSION['user_id']]);
  $past_partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  // エラー時は無視
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>新規貸付</title>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementsByTagName('form')[0];
      const submitButton = document.getElementById('submitButton');

      // 送信ボタン制御
      if (form && submitButton) {
        form.addEventListener('submit', function (event) {
          submitButton.disabled = true;
          submitButton.innerHTML = '処理中...📩 送信中...</span>';
        });
      }

      // カメラ・ファイル選択関連
      const cameraInput = document.getElementById('camera-capture');
      const fileInput = document.getElementById('file-select');
      const btnContainer = document.querySelector('.icon-btn-container');
      const previewContainer = document.getElementById('preview-container');
      const previewImage = document.getElementById('preview-image');

      function handleFileSelection(event) {
        let file = null;
        if (event && event.target && event.target.files.length > 0) {
          file = event.target.files[0];
        } else if (cameraInput.files.length > 0) {
          file = cameraInput.files[0];
        } else if (fileInput.files.length > 0) {
          file = fileInput.files[0];
        }

        if (file) {
          btnContainer.style.display = 'none';
          const reader = new FileReader();
          reader.onload = function (e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
          };
          reader.readAsDataURL(file);
        } else {
          btnContainer.style.display = 'flex';
          previewContainer.style.display = 'none';
          previewImage.src = '';
        }
      }

      cameraInput.addEventListener('change', handleFileSelection);
      fileInput.addEventListener('change', handleFileSelection);

      // ---------------------------------------------------------
      // ★★★ 名前自動入力 & ロック機能 (Ajax通信) ★★★
      // ---------------------------------------------------------
      const emailInput = document.querySelector('input[name="partner_email"]');
      const nameInput = document.querySelector('input[name="partner_name"]');
      const autoFillMsg = document.getElementById('auto-fill-msg'); // 「自動入力」表示用要素

      if (emailInput && nameInput) {
        emailInput.addEventListener('change', function () {
          const email = this.value;

          // メールアドレスが空になったらリセット
          if (!email) {
            nameInput.readOnly = false;
            nameInput.style.backgroundColor = '';
            nameInput.style.color = '';
            if (autoFillMsg) autoFillMsg.style.display = 'none';
            return;
          }

          // サーバーに問い合わせ
          fetch('check_user.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'email=' + encodeURIComponent(email)
          })
            .then(response => response.json())
            .then(data => {
              if (data.exists && data.user_name) {
                // --- 既存ユーザーが見つかった場合 ---
                nameInput.value = data.user_name;
                nameInput.readOnly = true;

                // 背景は少しグレーにするが、文字色は黒(#333)のままにする
                nameInput.style.backgroundColor = '#e9ecef';
                nameInput.style.color = '#333';

                // 「自動入力」メッセージを表示
                if (autoFillMsg) autoFillMsg.style.display = 'block';

              } else {
                // --- 新規または見つからない場合 ---
                nameInput.readOnly = false;
                nameInput.style.backgroundColor = '';
                nameInput.style.color = '';
                if (autoFillMsg) autoFillMsg.style.display = 'none';
              }
            })
            .catch(error => {
              console.error('Error:', error);
              nameInput.readOnly = false;
              nameInput.style.backgroundColor = '';
              if (autoFillMsg) autoFillMsg.style.display = 'none';
            });
        });
      }
    });
  </script>
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    body {
      font-family: sans-serif;
      background: #eef3ff;
      margin: 0;
      padding: 20px;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
    }

    .card {
      background: #ffffff;
      width: 410px;
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
      max-height: 90vh;
      overflow-y: auto;
    }

    h2 {
      margin-top: 0;
      margin-bottom: 20px;
      text-align: center;
    }

    label {
      display: block;
      font-size: 14px;
      margin-bottom: 6px;
      font-weight: bold;
    }

    input[type="text"],
    input[type="email"],
    input[type="number"],
    input[type="date"] {
      width: 100%;
      padding: 12px;
      border: 1px solid #ccd4ff;
      border-radius: 10px;
      margin-bottom: 15px;
      font-size: 14px;
      color: #333;
      /* デフォルトの文字色 */
    }

    /* ロックされた入力欄のスタイル */
    input[readonly] {
      background-color: #e9ecef;
      cursor: not-allowed;
      /* 文字色はJSで#333に上書きしますが、念のためここでも濃い色を指定 */
      color: #333;
    }

    /* 「自動入力」メッセージのスタイル */
    #auto-fill-msg {
      font-size: 12px;
      color: #4285f4;
      /* 青色で目立たせる */
      font-weight: bold;
      margin-top: -10px;
      margin-bottom: 15px;
      display: none;
      /* 初期状態は非表示 */
    }

    .icon-btn-container {
      display: flex;
      gap: 10px;
      margin-bottom: 15px;
    }

    .icon-btn {
      flex: 1;
      padding: 14px;
      background: #f8faff;
      border: 1px dashed #b6c2ff;
      border-radius: 10px;
      text-align: center;
      cursor: pointer;
      font-size: 14px;
      line-height: 1.5;
    }

    .icon-btn .material-icons {
      display: block;
      font-size: 24px;
      margin-bottom: 4px;
    }

    #preview-container {
      display: none;
      margin-bottom: 15px;
      text-align: center;
      background: #f0f8ff;
      padding: 10px;
      border-radius: 10px;
      border: 1px dashed #5b7cff;
      cursor: pointer;
      transition: background-color 0.2s;
    }

    #preview-container:hover {
      background-color: #e0f0ff;
    }

    #preview-image {
      max-width: 100%;
      max-height: 200px;
      border-radius: 8px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      display: block;
      margin: 0 auto;
    }

    .preview-hint {
      font-size: 12px;
      color: #5b7cff;
      margin-top: 6px;
      font-weight: bold;
    }

    .submit-btn {
      width: 100%;
      padding: 14px;
      background: linear-gradient(135deg, #5b7cff, #6af1ff);
      border: none;
      border-radius: 10px;
      color: #fff;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      margin-top: 10px;
    }

    .submit-btn:disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      cursor: pointer;
      font-size: 14px;
      color: #666;
      margin-bottom: 15px;
      font-weight: bold;
      transition: color 0.2s;
      text-decoration: none;
    }

    .back-btn:hover {
      color: #333;
    }

    .back-btn .material-icons {
      font-size: 16px;
      margin-right: 4px;
    }

    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 1000;
    }

    .modal-content {
      background: #ffffff;
      padding: 24px;
      border-radius: 16px;
      width: 300px;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
      text-align: center;
    }

    .modal-content h3 {
      margin-top: 0;
      margin-bottom: 20px;
      font-size: 18px;
    }

    .modal-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      padding: 12px;
      margin-bottom: 10px;
      background: #5b7cff;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 16px;
      font-weight: bold;
    }

    .modal-btn.cancel {
      background: #ccc;
      color: #333;
    }
  </style>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>

<body>

  <div class="card">
    <div class="back-btn" onclick="history.back()">
      <span class="material-icons">arrow_back_ios</span>戻る
    </div>

    <h2>新規貸付</h2>

    <form method="POST" action="save_debt.php" enctype="multipart/form-data">
      <datalist id="partner-list">
        <?php foreach ($past_partners as $partner): ?>
          <option value="<?= htmlspecialchars($partner['debtor_email']) ?>"></option>
        <?php endforeach; ?>
      </datalist>

      <label>相手のメールアドレス</label>
      <input type="email" name="partner_email" list="partner-list" placeholder="例）example@email.com" required
        autocomplete="off">

      <label>相手の名前</label>
      <input type="text" name="partner_name" placeholder="例）山田太郎" required>
      <div id="auto-fill-msg">自動入力</div>

      <label>貸付金額</label>
      <input type="number" name="amount" placeholder="例）5000" required>

      <label>返済期日</label>
      <input type="date" name="due_date" required>

      <input type="file" id="camera-capture" name="proof_camera" accept="image/*" capture="camera"
        style="display:none;">
      <input type="file" id="file-select" name="proof_file" accept="image/*" style="display:none;">

      <label>証拠資料 (オプション)</label>

      <div class="icon-btn-container">
        <div class="icon-btn" id="cameraButton" onclick="openSourceModal()">
          <span class="material-icons">camera_alt</span>
          レシート撮影
        </div>
      </div>

      <div id="preview-container" onclick="openSourceModal()">
        <img id="preview-image" src="" alt="証拠資料プレビュー">
        <div class="preview-hint">タップして写真を変更</div>
      </div>

      <button type="submit" id="submitButton" class="submit-btn">
        記録を作成
      </button>
    </form>
  </div>

  <div id="source-modal" class="modal-overlay">
    <div class="modal-content">
      <h3>証拠資料の取得方法を選択</h3>

      <button type="button" class="modal-btn" onclick="triggerCamera();">
        <span class="material-icons">photo_camera</span> カメラを起動
      </button>

      <button type="button" class="modal-btn" onclick="triggerFileSelect();">
        <span class="material-icons">folder_open</span> 写真フォルダから選択
      </button>

      <button type="button" class="modal-btn cancel" onclick="closeSourceModal();">キャンセル</button>
    </div>
  </div>
  <script>
    function openSourceModal() {
      document.getElementById('source-modal').style.display = 'flex';
    }
    function closeSourceModal() {
      document.getElementById('source-modal').style.display = 'none';
    }
    function triggerCamera() {
      document.getElementById('camera-capture').click();
      closeSourceModal();
    }
    function triggerFileSelect() {
      document.getElementById('file-select').click();
      closeSourceModal();
    }
  </script>

</body>

</html>