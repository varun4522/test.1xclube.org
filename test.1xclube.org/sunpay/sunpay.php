<?php
// ===== SUNPAY CREDENTIALS =====
define('SUNPAY_MERCHANT_ID', '202111011');
define('SUNPAY_KEY', '71663c49ad884f4ca3c7ac29462ddc70');
define('SUNPAY_CHANNEL_CODE', '102');
define('SUNPAY_PAYMENT_URL', 'https://pay.sunpayonline.xyz/pay/web');
define('SUNPAY_NOTIFY_URL', 'https://trade.1xclube.org/sunpay/notify.php
');

// ===== ORDER ID =====
function generateOrderId() {
    return 'DEP' . date('YmdHis') . rand(1000,9999);
}

// ===== SIGN BUILDER =====
function sunpaySign(array $params): string {
    unset($params['sign'], $params['sign_type']);
    ksort($params);
    $query = urldecode(http_build_query($params));
    return md5($query . '&key=' . SUNPAY_KEY);
}

// ===== PAYMENT REQUEST =====
function buildSunpayRequest($orderId, $amount) {
    $data = [
        'version'       => '1.0',
        'mch_id'        => SUNPAY_MERCHANT_ID,
        'mch_order_no'  => $orderId,
        'pay_type'      => SUNPAY_CHANNEL_CODE,
        'trade_amount'  => number_format($amount, 2, '.', ''),
        'order_date'    => date('Y-m-d H:i:s'),
        'notify_url'    => SUNPAY_NOTIFY_URL,
        'goods_name'    => 'Wallet Recharge',
        'sign_type'     => 'MD5'
    ];
    $data['sign'] = sunpaySign($data);
    return $data;
}
