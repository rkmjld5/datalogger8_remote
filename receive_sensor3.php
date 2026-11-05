<?php
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

// Authenticate.
$expectedToken = dataLoggerEnv('ESP_API_TOKEN');
$receivedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if ($expectedToken === '' ||
    !hash_equals($expectedToken, $receivedToken)) {
    reply(['ok' => false, 'message' => 'Invalid API token'], 401);
}

// Read JSON.
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    reply(['ok' => false, 'message' => 'Invalid JSON'], 400);
}

$cycleId = (string)($input['cycle_id'] ?? '');
$deviceId = (string)($input['device_id'] ?? '');
$sensorValue = trim((string)($input['sensor_value'] ?? ''));
$sampleEpoch = (string)($input['sample_epoch'] ?? '');
$timezone = trim((string)($input['timezone'] ?? 'UTC'));

$allowedDevices = ['ESP0001', 'ESP0002', 'ESP0003'];

if (!preg_match('/^[0-9]{1,20}$/', $cycleId)) {
    reply(['ok' => false, 'message' => 'Invalid cycle ID'], 400);
}

if (!in_array($deviceId, $allowedDevices, true)) {
    reply(['ok' => false, 'message' => 'Invalid device ID'], 400);
}

if ($sensorValue === '' || strlen($sensorValue) > 50) {
    reply(['ok' => false, 'message' => 'Invalid sensor value'], 400);
}

if (!preg_match('/^[0-9]{1,20}$/', $sampleEpoch)) {
    reply(['ok' => false, 'message' => 'Invalid sample epoch'], 400);
}

if (strlen($timezone) > 64) {
    reply(['ok' => false, 'message' => 'Invalid timezone'], 400);
}

try {
    $conn->begin_transaction();

    // Lock the cycle row.
    $stmt = $conn->prepare("
        SELECT
            completed,
            (created_at < DATE_SUB(NOW(), INTERVAL 120 SECOND))
                AS is_expired
        FROM sensor_cycles3
        WHERE cycle_id = ?
        FOR UPDATE
    ");

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $cycle = $result->fetch_assoc();
    $stmt->close();

    if (!$cycle) {
        throw new Exception('Cycle not found');
    }

    $state = (int)$cycle['completed'];

    // Do not accept readings for closed cycles.
    if ($state !== 0) {
        $conn->commit();

        reply([
            'ok' => true,
            'completed' => true,
            'expired' => ($state === 2),
            'message' => 'Cycle already closed'
        ]);
    }

    // Expire old cycles.
    if ((int)$cycle['is_expired'] === 1) {

        $stmt = $conn->prepare("
            UPDATE sensor_cycles3
            SET completed = 2
            WHERE cycle_id = ?
        ");

        $stmt->bind_param('s', $cycleId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        reply([
            'ok' => true,
            'completed' => true,
            'expired' => true,
            'message' => 'Cycle expired'
        ]);
    }

    // Store this controller's reading.
    $stmt = $conn->prepare("
        INSERT INTO sensor_pending3
        (cycle_id, device_id, sensor_value, sample_epoch, timezone)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sensor_value = VALUES(sensor_value),
            sample_epoch = VALUES(sample_epoch),
            timezone = VALUES(timezone)
    ");

    $stmt->bind_param(
        'sssss',
        $cycleId,
        $deviceId,
        $sensorValue,
        $sampleEpoch,
        $timezone
    );

    $stmt->execute();
    $stmt->close();

    // Count readings received.
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM sensor_pending3
        WHERE cycle_id = ?
    ");

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $countRow = $result->fetch_assoc();
    $stmt->close();

    $received = (int)$countRow['total'];

    // Wait for the remaining controllers.
    if ($received < 3) {
        $conn->commit();

        reply([
            'ok' => true,
            'completed' => false,
            'received' => $received,
            'required' => 3,
            'message' => 'Waiting for remaining controllers'
        ]);
    }

    // Read all three controller values.
    $stmt = $conn->prepare("
        SELECT device_id, sensor_value, sample_epoch, timezone
        FROM sensor_pending3
        WHERE cycle_id = ?
    ");

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $readings = [];

    while ($row = $result->fetch_assoc()) {
        $readings[$row['device_id']] = $row;
    }

    $stmt->close();

    foreach ($allowedDevices as $requiredDevice) {
        if (!isset($readings[$requiredDevice])) {
            throw new Exception(
                'Missing reading from ' . $requiredDevice
            );
        }
    }

    // Map readings to the existing logs columns.
    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    // Use master controller's sample time.
    $masterEpoch = (int)$readings['ESP0001']['sample_epoch'];
    $masterTimezone = $readings['ESP0001']['timezone'];

    if ($masterEpoch <= 0) {
        $masterEpoch = time();
    }

    try {
        $tz = new DateTimeZone($masterTimezone);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
        $masterTimezone = 'UTC';
    }

    $dateTime = new DateTime('@' . $masterEpoch);
    $dateTime->setTimezone($tz);

    $logDate = $dateTime->format('Y-m-d');
    $logTime = $dateTime->format('H:i:s');

    $logDevice = 'ESP0001,ESP0002,ESP0003';

    // Insert exactly ONE combined record.
    $stmt = $conn->prepare("
        INSERT INTO logs
        (value1, value2, value3, Date, Time, timezone, device_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        'sssssss',
        $value1,
        $value2,
        $value3,
        $logDate,
        $logTime,
        $masterTimezone,
        $logDevice
    );

    $stmt->execute();

    $logId = (string)$conn->insert_id;
    $stmt->close();

    // Mark cycle completed.
    $stmt = $conn->prepare("
        UPDATE sensor_cycles3
        SET completed = 1
        WHERE cycle_id = ?
          AND completed = 0
    ");

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    reply([
        'ok' => true,
        'completed' => true,
        'received' => 3,
        'required' => 3,
        'cycle_id' => $cycleId,
        'log_id' => $logId,
        'value1' => $value1,
        'value2' => $value2,
        'value3' => $value3,
        'message' => 'All three readings saved in logs'
    ]);

} catch (Throwable $e) {

    $conn->rollback();

    error_log('receive_sensor3.php: ' . $e->getMessage());

    reply([
        'ok' => false,
        'message' => 'Could not save sensor reading'
    ], 500);
}
