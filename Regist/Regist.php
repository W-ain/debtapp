<?php
// URLパラメータ 'back' をチェックし、戻り先を決定します。
// デフォルトの戻り先はホーム画面
$close_link = '../home/home.php';

if (isset($_GET['back']) && $_GET['back'] === 'inquiry') {
    // inquiry.phpから来た場合、inquiry.phpへ戻る
    $close_link = '../inquiry/inquiry.php';
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>新規貸付記録</title>

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
            height: 100vh;
        }

        .card {
            background: #ffffff;
            width: 410px;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        h2 {
            margin-top: 0;
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 14px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        input[readonly] {
            background-color: #e9ecef;
            cursor: not-allowed;
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

        .close-btn {
            float: right;
            cursor: pointer;
            font-size: 18px;
            color: #666;
        }

        /* モーダル */
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
        <span class="close-btn" onclick="window.location.href='<?= htmlspecialchars($close_link) ?>'">×</span>
        <h2>新規貸付記録</h2>

        <form method="POST" action="save_debt.php" enctype="multipart/form-data">

            <label for="partner_email">相手のメールアドレス</label>
            <input type="email" id="partner_email" name="partner_email" placeholder="example@email.com"
                required>

            <label for="partner_name">相手の名前</label>
            <input type="text" id="partner_name" name="partner_name" placeholder="山田太郎" required>
            <small id="name_status"
                style="display: block; margin-top: -10px; margin-bottom: 15px; font-size: 12px;"></small>

            <label>貸付金額</label>
            <input type="number" name="amount" placeholder="5000" required>

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

                <div class="icon-btn" onclick="alert('録音を開始します（実装が必要です）')">
                    <span class="material-icons">mic</span>
                    音声録音
                </div>
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

            <button type="button" class="modal-btn cancel" onclick="closeSourceModal();">
                キャンセル
            </button>
        </div>
    </div>

    <script>
        const emailInput = document.getElementById('partner_email');
        const nameInput = document.getElementById('partner_name');
        const nameStatus = document.getElementById('name_status');

        document.addEventListener('DOMContentLoaded', function() {
            if (emailInput) {
                emailInput.addEventListener('blur', checkEmailSync);
                console.log('[DEBUG] Email blur listener successfully attached.');
            }
        });

        function checkEmailSync() {
            console.log('[DEBUG] checkEmailSync started.');

            if (!emailInput || !nameInput || !nameStatus) {
                console.error('Error: Required input elements are missing.');
                return;
            }

            const email = emailInput.value.trim();

            nameInput.readOnly = false;
            nameStatus.textContent = '';
            nameInput.value = '';

            if (email === '' || !email.includes('@')) {
                nameStatus.textContent = 'メールアドレスを入力してください。';
                nameStatus.style.color = '#999';
                return;
            }

            nameStatus.textContent = '確認中...';
            nameStatus.style.color = '#5b7cff';

            fetch('check_user.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'email=' + encodeURIComponent(email)
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    if (data.exists) {
                        // ✅ 登録済みユーザーが見つかった場合 (2回目以降)
                        nameInput.value = data.user_name;
                        nameInput.readOnly = true; // 名前は編集不可にする
                        nameStatus.textContent = `✅ 登録済みユーザー: ${data.user_name} (自動入力)`;
                        nameStatus.style.color = 'green';
                    } else {
                        // 登録済みユーザーが見つからなかった場合 (初回取引)
                        // 初回は名前を自動入力せず、手動入力を許可する
                        nameInput.value = '';
                        nameInput.readOnly = false; // 名前は編集可のまま
                        nameStatus.textContent = '新規取引先です。名前を手動で入力し、登録してください。';
                        nameStatus.style.color = '#cc0000';
                    }
                })
        }

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