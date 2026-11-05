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

$cycleId = $_GET['cycle_id'] ?? '';

if (!preg_match('/^[0-9]{1,20}$/', $cycleId)) {
    reply(['ok' => false, 'message' => 'Invalid cycle ID'], 400);
}

try {
    // Expire the requested cycle if necessary.
    $stmt = $conn->prepare("
        UPDATE sensor_cycles3
        SET completed = 2
        WHERE cycle_id = ?
          AND completed = 0
          AND created_at < DATE_SUB(NOW(), INTERVAL 120 SECOND)
    ");

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT completed
        FROM sensor_cycles3
        WHERE cycle_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        reply([
            'ok' => false,
            'message' => 'Cycle not found'
        ], 404);
    }

    $state = (int)$row['completed'];

    reply([
        'ok' => true,
        'cycle_id' => $cycleId,
        'completed' => ($state !== 0),
        'expired' => ($state === 2),
        'state' => $state
    ]);

} catch (Throwable $e) {
    error_log('cycle_status3.php: ' . $e->getMessage());

    reply([
        'ok' => false,
        'message' => 'Database query failed'
    ], 500);
}
