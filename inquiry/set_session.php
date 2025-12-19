<?php
require_once '../config.php';

if (isset($_GET['id'])) {
    $_SESSION['view_debt_id'] = $_GET['id'];
    // 修正箇所: debt_changeフォルダ内のrecord.phpへ遷移
    header("Location: /debt_change/record.php");
    exit;
} else {
    // そのまま（直下のinquiry.phpへ）
    header("Location: inquiry.php");
    exit;
}

?>

