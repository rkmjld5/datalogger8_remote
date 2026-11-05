<?php
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

// Authenticate.
$expectedToken = dataLoggerEnv('ESP_API_TOKEN');
$receivedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if ($expectedToken === '' ||
    !hash_equals($expectedToken, $receivedToken)) {
    reply(['ok' => false, 'message' => 'Invalid API token'], 401);
}

// Only slave controllers can retrieve a cycle.
$deviceId = $_GET['device_id'] ?? '';

if (!in_array($deviceId, ['ESP0002', 'ESP0003'], true)) {
    reply(['ok' => false, 'message' => 'Invalid slave device'], 403);
}

try {
    // Expire old cycles.
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

} catch (Throwable $e) {
    error_log('get_cycle3.php: ' . $e->getMessage());

    reply([
        'ok' => false,
        'message' => 'Database query failed'
    ], 500);
}
