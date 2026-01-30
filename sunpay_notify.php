<?php
require_once '../config.php';

define('SUNPAY_KEY', '71663c49ad884f4ca3c7ac29462ddc70');

/* VERIFY SIGN */
function verifySunPay($data) {
    $sign = $data['sign'];
    unset($data['sign'], $data['signType']);

    ksort($data);
    $signStr = urldecode(http_build_query($data)) . '&key=' . SUNPAY_KEY;
    return md5($signStr) === $sign;
}

$data = $_POST;

if ($data['tradeResult'] != '1') {
    exit('fail');
}

if (!verifySunPay($data)) {
    exit('fail');
}

$conn = getDBConnection();

/* UPDATE TRANSACTION - DISABLED: transaction_id dependency removed */
/*
$stmt = $conn->prepare("
    UPDATE transaction_history
    SET status='success'
    WHERE transaction_id=? AND status='pending'
");
$stmt->bind_param("s", $data['mchOrderNo']);
$stmt->execute();
*/

/* CREDIT USER - DISABLED: transaction_id dependency removed */
/*
$stmt = $conn->prepare("
    UPDATE user_wallet uw
    JOIN transaction_history th ON th.user_id = uw.user_id
    SET uw.current_balance = uw.current_balance + th.amount
    WHERE th.transaction_id = ?
");
$stmt->bind_param("s", $data['mchOrderNo']);
$stmt->execute();
*/

echo "success";
