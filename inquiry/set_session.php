<?php
session_start();

$debt_id = $_GET['id'] ?? null;

if ($debt_id) {
    // 貸付IDをセッションに保存
    // (record.phpはこのセッション変数を使ってIDを取得します)
    $_SESSION['view_debt_id'] = $debt_id;
    
    // IDを含まないクリーンなURLで record.php へリダイレクト
    // ユーザーがブラウザで見るURLは「../debt_change/record.php」だけになります
    header('Location: ../debt_change/record.php'); 
    exit;
} else {
    // IDが渡されなかった場合（不正アクセスなど）は一覧に戻す
    header('Location: inquiry.php');
    exit;
}
?>