<?php
// start_cycle3.php
// Starts or returns the active three-controller cycle.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function reply($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['ok' => false, 'message' => 'POST requests only'], 405);
}

$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if (
    !isset($_ENV['ESP_API_TOKEN']) ||
    !hash_equals($_ENV['ESP_API_TOKEN'], $token)
) {
    reply(['ok' => false, 'message' => 'Invalid API token'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || ($input['device_id'] ?? '') !== 'ESP0001') {
    reply(['ok' => false, 'message' => 'Only ESP0001 can start a cycle'], 403);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    reply(['ok' => false, 'message' => 'Database connection unavailable'], 500);
}

$conn->begin_transaction();

try {
    // Expire cycles older than 120 seconds.
    $conn->query("
        UPDATE sensor_cycles3
        SET completed = 2
        WHERE completed = 0
          AND created_at < DATE_SUB(NOW(), INTERVAL 120 SECOND)
    ");

    // Reuse an existing active cycle, if one exists.
    $result = $conn->query("
        SELECT cycle_id
        FROM sensor_cycles3
        WHERE completed = 0
        ORDER BY created_at DESC
        LIMIT 1
        FOR UPDATE
    ");

    if (!$result) {
        throw new Exception($conn->error);
    }

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

    // Generate a unique BIGINT cycle ID.
    $cycleId = sprintf('%.0f', microtime(true) * 1000000);

    $stmt = $conn->prepare("
        INSERT INTO sensor_cycles3 (cycle_id, completed)
        VALUES (?, 0)
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('s', $cycleId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

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
