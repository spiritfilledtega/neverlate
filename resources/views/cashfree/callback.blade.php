<!DOCTYPE html>
<html>
<head>
    <title>Cashfree Payment Callback</title>
</head>
<body>
    <h1>Payment Status</h1>
    <p>Status: {{ $response['txStatus'] }}</p>
    <p>Order ID: {{ $response['orderId'] }}</p>
    <p>Amount: {{ $response['orderAmount'] }}</p>
    <p>Transaction ID: {{ $response['referenceId'] }}</p>
    <p>Payment Mode: {{ $response['paymentMode'] }}</p>
    <p>Message: {{ $response['txMsg'] }}</p>
</body>
</html>
