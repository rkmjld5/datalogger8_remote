<?php
// get_cycle3.php
// Returns the latest active cycle to the slave controllers.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function reply($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    reply(['ok' => false, 'message' => 'GET requests only'], 405);
}

$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if (
    !isset($_ENV['ESP_API_TOKEN']) ||
    !hash_equals($_ENV['ESP_API_TOKEN'], $token)
) {
    reply(['ok' => false, 'message' => 'Invalid API token'], 401);
}

$deviceId = $_GET['device_id'] ?? '';

if (!in_array($deviceId, ['ESP0002', 'ESP0003'], true)) {
    reply(['ok' => false, 'message' => 'Invalid slave device'], 403);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    reply(['ok' => false, 'message' => 'Database connection unavailable'], 500);
}

// Expire cycles older than 120 seconds.
$conn->query("
    UPDATE sensor_cycles3
    SET completed = 2
    WHERE completed = 0
      AND created_at < DATE_SUB(NOW(), INTERVAL 120 SECOND)
");

$result = $conn->query("
    SELECT cycle_id
    FROM sensor_cycles3
    WHERE completed = 0
      AND created_at >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
    ORDER BY created_at DESC
    LIMIT 1
");

if (!$result) {
    reply(['ok' => false, 'message' => 'Database query failed'], 500);
}

$row = $result->fetch_assoc();

if (!$row) {
    reply([
        'ok' => true,
        'available' => false,
        'message' => 'No active cycle'
    ]);
}

reply([
    'ok' => true,
    'available' => true,
    'cycle_id' => (string)$row['cycle_id']
]);
