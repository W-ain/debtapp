<?php
require_once 'config.php';
// require_once 'db.php'; // config.phpで$pdoが定義されていることを前提とします。

$token = $_GET['token'] ?? '';

if (!$token) {
    die("無効なアクセスです");
}

// 【データベースに is_verified と verify_token カラムが存在することを前提とします】
$sql = "UPDATE users SET is_verified = 1 WHERE verify_token = ?";
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$token]);

    if ($stmt->rowCount() > 0) {
        // トークン使用後、セキュリティのためにトークンをクリアすることが推奨されます
        $clear_token_sql = "UPDATE users SET verify_token = NULL WHERE verify_token = ?";
        $clear_stmt = $pdo->prepare($clear_token_sql);
        $clear_stmt->execute([$token]);
        
        echo "メール認証が完了しました。<a href='login.html'>ログインはこちら</a>";
    } else {
        echo "無効なトークンです。";
    }
} catch (PDOException $e) {
    // DBエラーハンドリング
    die("データベースエラーが発生しました。");
}
?>