<?php
// logistics-service/index.php
header('Content-Type: application/json');
$headers = getallheaders();
$apiKey = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? '';

if ($apiKey !== 'ELLIANA_LOGISTICS_SECRET_2026') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized API Key']);
    exit;
}

$uri = $_SERVER['REQUEST_URI'] ?? '';

// 1. Payment Endpoint
if (strpos($uri, '/v1/payments') !== false) {
    $payRef = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    http_response_code(200);
    echo json_encode([
        'status' => 'payment_approved',
        'payment_reference' => $payRef
    ]);
    exit;
}

// 2. Logistics Waybill Endpoint (with Fault Injection Triggers)
$input = json_decode(file_get_contents('php://input'), true);
$city = strtoupper(trim($input['city'] ?? 'QUEZON CITY'));

// Simulate Upstream HTTP 500 Server Error
if ($city === 'FAIL500') {
    http_response_code(500);
    echo json_encode(['error' => 'Simulated Courier Internal Server Error']);
    exit;
}

// Simulate Corrupt / Malformed JSON Response
if ($city === 'BADJSON') {
    http_response_code(200);
    echo '{"status": "waybill_created", "tracking_number": BROKEN_JSON_STREAM';
    exit;
}

// Normal Nominal Response
$trackingNumber = 'JNT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
http_response_code(201);
echo json_encode([
    'status' => 'waybill_created',
    'tracking_number' => $trackingNumber,
    'calculated_fee' => 120.00
]);