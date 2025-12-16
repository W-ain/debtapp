<?php
// ==========================================
// 🚀 アプリ内ブラウザ（LINEなど）対策
// ==========================================
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// LINE、Instagram、Facebookなどのアプリ内ブラウザかチェック
if (strpos($userAgent, 'Line') !== false || 
    strpos($userAgent, 'Instagram') !== false || 
    strpos($userAgent, 'FB') !== false) {

    // 現在のURL（http://.../debtapp/）を取得
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    
    // まだ「外部ブラウザで開く」パラメータがついていない場合
    if (strpos($currentUrl, 'openExternalBrowser=1') === false) {
        // パラメータを追加 (? または & でつなぐ)
        $separator = (strpos($currentUrl, '?') === false) ? '?' : '&';
        $redirectUrl = $currentUrl . $separator . 'openExternalBrowser=1';
        
        // 自分自身にリダイレクト（これでLINE等が外部ブラウザを起動します）
        header("Location: $redirectUrl");
        exit;
    }
}
// ==========================================
// 案内役ファイル
// ユーザーがトップページに来たら、
// loginフォルダの中にある login.html へ転送する
// ==========================================

header('Location: login/google_login.php');
exit;
?>
