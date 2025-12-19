<?php
require_once '../config.php'; // パス修正

header('Content-Type: application/json');

$response = ['exists' => false, 'user_name' => ''];

$creditor_id = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['email']) || !$creditor_id) {
    echo json_encode($response);
    exit;
}

$debtor_email = $_POST['email'];

try {
    // メールアドレスから過去の名前を探す
    $sql = "
        SELECT debtor_name 
        FROM debts 
        WHERE creditor_id = :creditor_id 
          AND debtor_email = :debtor_email 
          AND debtor_name != ''
        ORDER BY debt_id DESC 
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':creditor_id', $creditor_id, PDO::PARAM_INT);
    $stmt->bindParam(':debtor_email', $debtor_email, PDO::PARAM_STR);
    $stmt->execute();

    $debt_history = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($debt_history) {
        $response['exists'] = true;
        $response['user_name'] = $debt_history['debtor_name'];
    }

} catch (PDOException $e) {
    // エラー処理なし
}

echo json_encode($response);

?>

