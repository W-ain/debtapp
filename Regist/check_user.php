<?php
session_start();
// データベース接続情報 (config.phpなど) を読み込む
require_once '../config.php'; // $pdo がここで定義されている前提

header('Content-Type: application/json');

$response = ['exists' => false, 'user_name' => ''];

// ログイン中のユーザーIDを取得
$creditor_id = $_SESSION['user_id'] ?? null;

// 必要な情報が不足している場合は処理を中断
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['email']) || !$creditor_id) {
    echo json_encode($response);
    exit;
}

$debtor_email = $_POST['email'];

try {
    // -----------------------------------------------------------
    // ★ 修正ロジック: debtsテーブルから、ログイン中のユーザーが過去に使用した名前を探す ★
    // -----------------------------------------------------------
    $sql = "
        SELECT 
            debtor_name 
        FROM 
            debts 
        WHERE 
            creditor_id = :creditor_id AND debtor_email = :debtor_email 
        ORDER BY 
            created_at DESC 
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':creditor_id', $creditor_id, PDO::PARAM_INT);
    $stmt->bindParam(':debtor_email', $debtor_email, PDO::PARAM_STR);
    $stmt->execute();
    
    $debt_history = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($debt_history) {
        // 過去の取引履歴が見つかった場合（2回目以降）
        $response['exists'] = true;
        // debtsテーブルの debtor_name を返す
        $response['user_name'] = $debt_history['debtor_name']; 
    } else {
        // 取引履歴がない場合（初回取引）
        $response['exists'] = false;
        $response['user_name'] = ''; 
    }

} catch (PDOException $e) {
    // DBエラー時は exists: false で返す
    // error_log("DB Error in check_user.php: " . $e->getMessage());
}

echo json_encode($response);
?>