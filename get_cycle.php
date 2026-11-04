<?php
// get_cycle.php
// Slaves obtain the latest active cycle created by ESP0001.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function reply($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        reply(405, ['ok' => false, 'error' => 'GET requests only']);
    }

    $expected = trim((string)getenv('ESP_API_TOKEN'));
    $provided = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));

    if ($expected === '' || !hash_equals($expected, $provided)) {
        reply(401, ['ok' => false, 'error' => 'Invalid API token']);
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection unavailable');
    }

    // Only cycles created in the last 120 seconds are eligible.
    $sql = "
        SELECT cycle_id
        FROM sensor_cycles
        WHERE completed = 0
          AND created_at >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
        ORDER BY created_at DESC, cycle_id DESC
        LIMIT 1
    ";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $row = $result->fetch_assoc();

    if (!$row) {
        reply(200, [
            'ok' => false,
            'available' => false,
            'message' => 'Waiting for ESP0001 to start a cycle'
        ]);
    }

    reply(200, [
        'ok' => true,
        'available' => true,
        'cycle_id' => (string)$row['cycle_id']
    ]);

} catch (Throwable $e) {
    error_log('get_cycle.php: ' . $e->getMessage());
    reply(500, ['ok' => false, 'error' => 'Server error']);
}
