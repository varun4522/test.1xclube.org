<?php
session_start();
header('Content-Type: application/json');

require_once '../config.php';
require_once '../sunpay/sunpay.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$amount = floatval($_POST['amount'] ?? 0);
if ($amount < 100) {
    echo json_encode(['success' => false, 'message' => 'Minimum ₹100']);
    exit;
}

$conn = getDBConnection();
$orderId = generateOrderId();

/* save pending recharge */
$stmt = $conn->prepare("
    INSERT INTO recharge (user_id, order_id, amount, status, gateway, created_at)
    VALUES (?, ?, ?, 'PENDING', 'SUNPAY', NOW())
");
$stmt->bind_param("isd", $_SESSION['user_id'], $orderId, $amount);
$stmt->execute();

/* SunPay request data */
$params = buildSunpayRequest($orderId, $amount);

/* CURL request to SunPay */
$ch = curl_init(SUNPAY_PAYMENT_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($params),
    CURLOPT_TIMEOUT        => 30
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!$data || $data['respCode'] !== 'SUCCESS') {
    echo json_encode([
        'success' => false,
        'message' => 'SunPay error',
        'raw'     => $response
    ]);
    exit;
}

/* IMPORTANT: payInfo is the real payment page */
echo json_encode([
    'success' => true,
    'payUrl'  => $data['payInfo']
]);
