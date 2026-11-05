<?php
// receive_sensor3.php
// Receives readings from ESP0001, ESP0002 and ESP0003.
// Inserts one combined row into logs after all three arrive.

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

if (!isset($conn) || !($conn instanceof mysqli)) {
    reply(['ok' => false, 'message' => 'Database connection unavailable'], 500);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    reply(['ok' => false, 'message' => 'Invalid JSON'], 400);
}

$cycleId = (string)($input['cycle_id'] ?? '');
$deviceId = (string)($input['device_id'] ?? '');
$sensorValue = (string)($input['sensor_value'] ?? '');
$sampleEpoch = (string)($input['sample_epoch'] ?? '0');
$timezone = (string)($input['timezone'] ?? 'UTC');

if (!preg_match('/^[0-9]{1,20}$/', $cycleId)) {
    reply(['ok' => false, 'message' => 'Invalid cycle ID'], 400);
}

$allowedDevices = ['ESP0001', 'ESP0002', 'ESP0003'];

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

$conn->begin_transaction();

try {

    // Lock the cycle row so simultaneous controller submissions
    // cannot create duplicate combined log records.
    $stmt = $conn->prepare("
        SELECT completed, created_at
        FROM sensor_cycles3
        WHERE cycle_id = ?
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('s', $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $cycle = $result->fetch_assoc();

    $stmt->close();

    if (!$cycle) {
        throw new Exception('Cycle not found');
    }

    $state = (int)$cycle['completed'];

    // Already completed or expired: do not insert another log.
    if ($state !== 0) {

        $conn->commit();

        reply([
            'ok' => true,
            'completed' => true,
            'expired' => ($state === 2),
            'message' => 'Cycle already closed'
        ]);
    }

    // Expire a cycle that has waited too long.
    $created = strtotime($cycle['created_at']);

    if ($created !== false && (time() - $created) > 120) {

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

    // Store or update this device's reading for this cycle.
    $stmt = $conn->prepare("
        INSERT INTO sensor_pending3
        (cycle_id, device_id, sensor_value, sample_epoch, timezone)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sensor_value = VALUES(sensor_value),
            sample_epoch = VALUES(sample_epoch),
            timezone = VALUES(timezone)
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        'sssss',
        $cycleId,
        $deviceId,
        $sensorValue,
        $sampleEpoch,
        $timezone
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $stmt->close();

    // Count the distinct controller readings received.
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

    // Wait until all three controllers have submitted.
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

    // Retrieve all three readings.
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

    // Confirm that all required device IDs are present.
    foreach ($allowedDevices as $requiredDevice) {

        if (!isset($readings[$requiredDevice])) {
            throw new Exception('Missing reading from ' . $requiredDevice);
        }
    }

    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    // Use ESP0001's epoch and timezone for the combined log row.
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

    // Insert exactly one combined record.
    $stmt = $conn->prepare("
        INSERT INTO logs
        (value1, value2, value3, Date, Time, timezone, device_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

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

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $logId = $conn->insert_id;

    $stmt->close();

    // Mark this cycle completed in the same transaction.
    $stmt = $conn->prepare("
        UPDATE sensor_cycles3
        SET completed = 1
        WHERE cycle_id = ?
          AND completed = 0
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
        'completed' => true,
        'received' => 3,
        'required' => 3,
        'cycle_id' => $cycleId,
        'log_id' => (string)$logId,
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
