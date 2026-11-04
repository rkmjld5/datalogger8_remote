<?php
// start_cycle.php
// ESP0001 (master) requests a new server-generated cycle.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function reply($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        reply(405, ['ok' => false, 'error' => 'POST requests only']);
    }

    $expected = trim((string)getenv('ESP_API_TOKEN'));
    $provided = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));

    if ($expected === '' || !hash_equals($expected, $provided)) {
        reply(401, ['ok' => false, 'error' => 'Invalid API token']);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input) || ($input['device_id'] ?? '') !== 'ESP0001') {
        reply(403, ['ok' => false, 'error' => 'Only ESP0001 can start a cycle']);
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection unavailable');
    }

    // Microsecond-based unique BIGINT cycle ID.
    $cycleId = (int)floor(microtime(true) * 1000000);

    $stmt = $conn->prepare(
        "INSERT INTO sensor_cycles (cycle_id, completed) VALUES (?, 0)"
    );
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $stmt->close();

    reply(200, [
        'ok' => true,
        'cycle_id' => $cycleId,
        'message' => 'New cycle created'
    ]);

} catch (Throwable $e) {
    error_log('start_cycle.php: ' . $e->getMessage());
    reply(500, ['ok' => false, 'error' => 'Server error']);
}
