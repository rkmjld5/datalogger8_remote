
<?php
// receive_sensor.php
// Receives one reading from each ESP8266 and creates one combined logs row.

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

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection unavailable');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        reply(400, ['ok' => false, 'error' => 'Invalid JSON']);
    }

    $device = trim((string)($input['device_id'] ?? ''));
    $value = trim((string)($input['value'] ?? ''));
    $cycleId = trim((string)($input['cycle_id'] ?? ''));
    $sampleEpoch = filter_var(
        $input['sample_epoch'] ?? null,
        FILTER_VALIDATE_INT
    );
    $timezone = trim((string)($input['timezone'] ?? ''));

    $allowed = ['ESP0001', 'ESP0002', 'ESP0003'];

    if (!in_array($device, $allowed, true)) {
        reply(400, ['ok' => false, 'error' => 'Unknown device ID']);
    }

    if ($value === '' || !is_numeric($value) ||
        strlen($value) > 50) {
        reply(400, ['ok' => false, 'error' => 'Invalid sensor value']);
    }

    if ($cycleId === '' || !ctype_digit($cycleId) ||
        $sampleEpoch === false || $sampleEpoch <= 0) {
        reply(400, ['ok' => false, 'error' => 'Invalid cycle or sample time']);
    }

    if ($timezone === '') {
        reply(400, ['ok' => false, 'error' => 'Timezone is required']);
    }

    // Validate IANA timezone.
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Throwable $e) {
        reply(400, ['ok' => false, 'error' => 'Invalid timezone']);
    }

    $conn->begin_transaction();

    // Lock the cycle row while processing this reading.
    $stmt = $conn->prepare(
        "SELECT completed, created_at
         FROM sensor_cycles
         WHERE cycle_id = ?
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $cycleResult = $stmt->get_result();
    $cycle = $cycleResult->fetch_assoc();
    $stmt->close();

    if (!$cycle) {
        $conn->rollback();
        reply(409, [
            'ok' => false,
            'error' => 'Cycle not found. Obtain a current cycle ID.'
        ]);
    }

    if ((int)$cycle['completed'] === 1) {
        $conn->commit();
        reply(200, [
            'ok' => true,
            'completed' => true,
            'message' => 'This cycle was already completed'
        ]);
    }

    // Reject old cycles.
    $created = strtotime($cycle['created_at']);
    if ($created === false || (time() - $created) > 120) {
        $conn->rollback();
        reply(409, [
            'ok' => false,
            'error' => 'Cycle expired. Wait for a new cycle.'
        ]);
    }

    // One reading per device per cycle.
    // A retry from the same device updates its pending reading.
    $stmt = $conn->prepare(
        "INSERT INTO sensor_pending
            (cycle_id, device_id, sensor_value, sample_epoch, timezone)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            sensor_value = VALUES(sensor_value),
            sample_epoch = VALUES(sample_epoch),
            timezone = VALUES(timezone),
            received_at = CURRENT_TIMESTAMP"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        'issis',
        $cycleId,
        $device,
        $value,
        $sampleEpoch,
        $timezone
    );

    $stmt->execute();
    $stmt->close();

    // Count distinct controllers received for this cycle.
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT device_id) AS total
         FROM sensor_pending
         WHERE cycle_id = ?"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $countResult = $stmt->get_result();
    $countRow = $countResult->fetch_assoc();
    $stmt->close();

    $received = (int)$countRow['total'];

    if ($received < 3) {
        $conn->commit();
        reply(200, [
            'ok' => true,
            'received' => $received,
            'required' => 3,
            'completed' => false,
            'cycle_id' => $cycleId
        ]);
    }

    // Read all three values.
    $stmt = $conn->prepare(
        "SELECT device_id, sensor_value, sample_epoch, timezone
         FROM sensor_pending
         WHERE cycle_id = ?"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $rowsResult = $stmt->get_result();

    $readings = [];

    while ($row = $rowsResult->fetch_assoc()) {
        $readings[$row['device_id']] = $row;
    }

    $stmt->close();

    foreach ($allowed as $requiredDevice) {
        if (!isset($readings[$requiredDevice])) {
            $conn->commit();
            reply(200, [
                'ok' => true,
                'received' => count($readings),
                'required' => 3,
                'completed' => false,
                'cycle_id' => $cycleId
            ]);
        }
    }

    // Use ESP0001's sample time and timezone for the combined row.
    $masterEpoch = (int)$readings['ESP0001']['sample_epoch'];
    $masterTimezone = $readings['ESP0001']['timezone'];

    $masterTz = new DateTimeZone($masterTimezone);
    $localTime = new DateTime('@' . $masterEpoch);
    $localTime->setTimezone($masterTz);

    $dateValue = $localTime->format('Y-m-d');
    $timeValue = $localTime->format('H:i:s');

    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    // Exactly one combined row for the three devices.
    $stmt = $conn->prepare(
        "INSERT INTO logs
            (value1, value2, value3, Date, Time, timezone, device_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $allDevices = 'ESP0001,ESP0002,ESP0003';

    $stmt->bind_param(
        'sssssss',
        $value1,
        $value2,
        $value3,
        $dateValue,
        $timeValue,
        $masterTimezone,
        $allDevices
    );

    $stmt->execute();
    $logId = $conn->insert_id;
    $stmt->close();

    // Mark this cycle completed in the same transaction.
    $stmt = $conn->prepare(
        "UPDATE sensor_cycles
         SET completed = 1
         WHERE cycle_id = ?"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    reply(200, [
        'ok' => true,
        'received' => 3,
        'required' => 3,
        'completed' => true,
        'cycle_id' => $cycleId,
        'log_id' => $logId,
        'value1' => $value1,
        'value2' => $value2,
        'value3' => $value3,
        'message' => 'Combined row inserted into logs'
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    error_log('receive_sensor.php: ' . $e->getMessage());

    reply(500, [
        'ok' => false,
        'error' => 'Server processing failed. Check Render logs.'
    ]);
}
