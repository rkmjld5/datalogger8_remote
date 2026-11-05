
<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

function reply($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Only POST is accepted.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['ok' => false, 'message' => 'POST requests only'], 405);
}

// Authenticate.
$expectedToken = dataLoggerEnv('ESP_API_TOKEN');
$receivedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if ($expectedToken === '' ||
    !hash_equals($expectedToken, $receivedToken)) {
    reply(['ok' => false, 'message' => 'Invalid API token'], 401);
}

// Only ESP0001 can start a cycle.
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) ||
    ($input['device_id'] ?? '') !== 'ESP0001') {
    reply(['ok' => false, 'message' => 'Only ESP0001 can start a cycle'], 403);
}

try {
    $conn->begin_transaction();

    // Expire old unfinished cycles.
    $conn->query("
        UPDATE sensor_cycles3
        SET completed = 2
        WHERE completed = 0
          AND created_at < DATE_SUB(NOW(), INTERVAL 120 SECOND)
    ");

    // Find an existing active cycle.
    $result = $conn->query("
        SELECT cycle_id
        FROM sensor_cycles3
        WHERE completed = 0
        ORDER BY created_at DESC
        LIMIT 1
        FOR UPDATE
    ");

    if ($row = $result->fetch_assoc()) {
        $cycleId = (string)$row['cycle_id'];
        $conn->commit();

        reply([
            'ok' => true,
            'cycle_id' => $cycleId,
            'reused' => true,
            'message' => 'Existing active cycle returned'
        ]);
    }

    // Create a new unique cycle ID.
    $cycleId = sprintf('%.0f', microtime(true) * 1000000);

    $stmt = $conn->prepare("
        INSERT INTO sensor_cycles3 (cycle_id, completed)
        VALUES (?, 0)
    ");

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    reply([
        'ok' => true,
        'cycle_id' => $cycleId,
        'reused' => false,
        'message' => 'New cycle created'
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    error_log('start_cycle3.php: ' . $e->getMessage());

    reply([
        'ok' => false,
        'message' => 'Could not start cycle'
    ], 500);
}
