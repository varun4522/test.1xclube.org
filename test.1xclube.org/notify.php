<?php
require_once '../config.php';
require_once 'sunpay.php';
file_put_contents(__DIR__.'/sunpay_log.txt', json_encode($_POST).PHP_EOL, FILE_APPEND);

$data = $_POST;

$sign = $data['sign'] ?? '';
unset($data['sign'], $data['signType']);

if (sunpaySign($data) !== $sign) {
    exit('invalid sign');
}

if ($data['tradeResult'] != '1') {
    exit('fail');
}

$conn = getDBConnection();
$orderId = $data['mchOrderNo'];
$amount  = $data['amount'];

/* update recharge */
$conn->query("
    UPDATE recharge 
    SET status='SUCCESS' 
    WHERE order_id='$orderId'
");

/* credit wallet */
$conn->query("
    UPDATE profiles p
    JOIN recharge r ON p.id = r.user_id
    SET p.balance = p.balance + $amount
    WHERE r.order_id='$orderId'
");

echo "success";
