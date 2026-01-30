<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>

<form action="sunpay_request.php" method="POST">
    <input type="number" name="amount" placeholder="Enter Amount" required min="100">
    <button type="submit">Deposit</button>
</form>
