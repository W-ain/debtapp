<?php
session_start();
require_once 'config.php';
// require_once 'db.php';


// // DBからユーザー取得
$sql = "SELECT id, password_hash, is_verified FROM users WHERE email = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("メールアドレスまたはパスワードが違います。");
}

// メール認証済みかチェック
if ($user['is_verified'] != 1) {
    die("メール認証が完了していません。メールをご確認ください。");
}

// パスワードチェック
if (!password_verify($password, $user['password_hash'])) {
    die("メールアドレスまたはパスワードが違います。");
}

// ログイン成功
$_SESSION['user_id'] = $user['id'];

header("Location: dashboard.php");
exit;
