<?php
declare(strict_types=1);

/*
 * datalogger8_remote
 * Receives sensor readings from ESP0001, ESP0002 and ESP0003.
 * Combines the three readings into one row in espdemo2.logs.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function sendJson(int $httpCode, array $data): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function logReceiveError(string $message, int $cycleId = 0, string $deviceId = ''): void
{
    error_log(
        'datalogger8 receive_sensor.php ERROR: ' .
        $message .
        ' | cycle_id=' . $cycleId .
        ' | device_id=' . $deviceId
    );
}

/* -------------------------------------------------
   1. Accept POST only
------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(405, [
        'ok' => false,
        'message' => 'POST requests only'
    ]);
}

/* -------------------------------------------------
   2. Check API token
------------------------------------------------- */

$serverToken = getenv('ESP_API_TOKEN');

if (!$serverToken) {
    sendJson(500, [
        'ok' => false,
        'message' => 'ESP_API_TOKEN is not configured on server'
    ]);
}

$receivedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if (!hash_equals($serverToken, $receivedToken)) {
    sendJson(401, [
        'ok' => false,
        'message' => 'Invalid API token'
    ]);
}

/* -------------------------------------------------
   3. Read JSON request
------------------------------------------------- */

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    sendJson(400, [
        'ok' => false,
        'message' => 'Invalid JSON'
    ]);
}

$deviceId = strtoupper(trim((string)($data['device_id'] ?? '')));
$value = trim((string)($data['value'] ?? ''));
$cycleId = filter_var($data['cycle_id'] ?? null, FILTER_VALIDATE_INT);
$sampleEpoch = filter_var($data['sample_epoch'] ?? null, FILTER_VALIDATE_INT);
$timezone = trim((string)($data['timezone'] ?? ''));

if (!in_array($deviceId, ['ESP0001', 'ESP0002', 'ESP0003'], true)) {
    sendJson(400, [
        'ok' => false,
        'message' => 'Invalid device_id'
    ]);
}

if ($value === '' || !is_numeric($value) || strlen($value) > 50) {
    sendJson(400, [
        'ok' => false,
        'message' => 'Invalid sensor value'
    ]);
}

if ($cycleId === false || $sampleEpoch === false ||
    $cycleId === null || $sampleEpoch === null ||
    $cycleId < 0 || $sampleEpoch < 0) {
    sendJson(400, [
        'ok' => false,
        'message' => 'Invalid cycle_id or sample_epoch'
    ]);
}

if (intdiv($sampleEpoch, 5) !== $cycleId) {
    sendJson(400, [
        'ok' => false,
        'message' => 'cycle_id does not match sample_epoch'
    ]);
}

try {
    new DateTimeZone($timezone);
} catch (Throwable $e) {
    sendJson(400, [
        'ok' => false,
        'message' => 'Invalid timezone'
    ]);
}

/* -------------------------------------------------
   4. Validate sample time
------------------------------------------------- */

$now = time();

if ($sampleEpoch < ($now - 300) || $sampleEpoch > ($now + 30)) {
    sendJson(400, [
        'ok' => false,
        'message' => 'Sample time outside permitted range',
        'server_epoch' => $now,
        'sample_epoch' => $sampleEpoch
    ]);
}

/* -------------------------------------------------
   5. Prepare UTC and local time
------------------------------------------------- */

$utcDateTime = gmdate('Y-m-d H:i:s', $sampleEpoch);

try {
    $localDateTime = new DateTimeImmutable('@' . $sampleEpoch);
    $localDateTime = $localDateTime->setTimezone(new DateTimeZone($timezone));

    $localDate = $localDateTime->format('Y-m-d');
    $localTime = $localDateTime->format('H:i:s');
} catch (Throwable $e) {
    sendJson(400, [
        'ok' => false,
        'message' => 'Unable to calculate local time'
    ]);
}

/* -------------------------------------------------
   6. Database transaction
------------------------------------------------- */

$transactionStarted = false;

try {

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection $conn is unavailable');
    }

    $conn->set_charset('utf8mb4');

    // Store and compare TIMESTAMP values in UTC.
    $conn->query("SET time_zone = '+00:00'");

    $conn->begin_transaction();
    $transactionStarted = true;

    /*
     * Create cycle row if it does not exist.
     */
    $stmt = $conn->prepare(
        "INSERT INTO sensor_cycles8 (cycle_id, completed)
         VALUES (?, 0)
         ON DUPLICATE KEY UPDATE cycle_id = VALUES(cycle_id)"
    );

    $cycleIdString = (string)$cycleId;
    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();
    $stmt->close();

    /*
     * Lock this cycle while processing it.
     */
    $stmt = $conn->prepare(
        "SELECT completed
         FROM sensor_cycles8
         WHERE cycle_id = ?
         FOR UPDATE"
    );

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();
    $cycleResult = $stmt->get_result();
    $cycleRow = $cycleResult->fetch_assoc();
    $stmt->close();

    if (!$cycleRow) {
        throw new RuntimeException('Unable to find cycle row');
    }

    if ((int)$cycleRow['completed'] === 1) {
        $conn->commit();
        $transactionStarted = false;

        sendJson(200, [
            'ok' => true,
            'message' => 'Cycle already completed',
            'cycle_id' => $cycleId
        ]);
    }

    /*
     * Check whether this controller has already submitted
     * a reading for this cycle.
     */
    $stmt = $conn->prepare(
        "SELECT sensor_value, sample_epoch
         FROM sensor_pending8
         WHERE cycle_id = ? AND device_id = ?
         FOR UPDATE"
    );

    $stmt->bind_param('ss', $cycleIdString, $deviceId);
    $stmt->execute();
    $existingResult = $stmt->get_result();
    $existingRow = $existingResult->fetch_assoc();
    $stmt->close();

    if ($existingRow) {

        if ((string)$existingRow['sensor_value'] !== $value ||
            (string)$existingRow['sample_epoch'] !== (string)$sampleEpoch) {

            throw new RuntimeException(
                'Conflicting duplicate reading for this device and cycle'
            );
        }

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO sensor_pending8
             (cycle_id, device_id, sensor_value, sample_epoch, timezone)
             VALUES (?, ?, ?, ?, ?)"
        );

        $sampleEpochString = (string)$sampleEpoch;

        $stmt->bind_param(
            'sssss',
            $cycleIdString,
            $deviceId,
            $value,
            $sampleEpochString,
            $timezone
        );

        $stmt->execute();
        $stmt->close();
    }

    /*
     * Fetch all readings for this cycle.
     */
    $stmt = $conn->prepare(
        "SELECT device_id, sensor_value, sample_epoch, timezone
         FROM sensor_pending8
         WHERE cycle_id = ?
         ORDER BY device_id
         FOR UPDATE"
    );

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();
    $pendingResult = $stmt->get_result();

    $readings = [];

    while ($row = $pendingResult->fetch_assoc()) {
        $readings[$row['device_id']] = $row;
    }

    $stmt->close();

    $requiredDevices = ['ESP0001', 'ESP0002', 'ESP0003'];

    foreach ($requiredDevices as $requiredDevice) {
        if (!isset($readings[$requiredDevice])) {
            $conn->commit();
            $transactionStarted = false;

            sendJson(200, [
                'ok' => true,
                'message' => 'Reading stored; waiting for other controllers',
                'cycle_id' => $cycleId,
                'received_readings' => count($readings),
                'required_readings' => 3
            ]);
        }
    }

    /*
     * Confirm all three sample epochs match.
     */
    foreach ($requiredDevices as $requiredDevice) {
        if ((string)$readings[$requiredDevice]['sample_epoch'] !==
            (string)$sampleEpoch) {

            throw new RuntimeException(
                'Sensor readings have different sample_epoch values'
            );
        }
    }

    /*
     * Use ESP0001 timezone for the combined log row.
     */
    $finalTimezone = $readings['ESP0001']['timezone'];

    $finalDateTime = new DateTimeImmutable('@' . $sampleEpoch);
    $finalDateTime = $finalDateTime->setTimezone(
        new DateTimeZone($finalTimezone)
    );

    $finalDate = $finalDateTime->format('Y-m-d');
    $finalTime = $finalDateTime->format('H:i:s');

    /*
     * Check if this exact timestamp has already been inserted.
     * This is a safeguard for retries.
     */
    $stmt = $conn->prepare(
        "SELECT id
         FROM logs
         WHERE TimeStamp = ?
           AND device_id = 'ESP0001,ESP0002,ESP0003'
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->bind_param('s', $utcDateTime);
    $stmt->execute();
    $duplicateResult = $stmt->get_result();
    $duplicateRow = $duplicateResult->fetch_assoc();
    $stmt->close();

    if (!$duplicateRow) {

        /*
         * Insert one combined record.
         */
        $value1 = $readings['ESP0001']['sensor_value'];
        $value2 = $readings['ESP0002']['sensor_value'];
        $value3 = $readings['ESP0003']['sensor_value'];

        $combinedDeviceId = 'ESP0001,ESP0002,ESP0003';

        $stmt = $conn->prepare(
            "INSERT INTO logs
             (value1, value2, value3, `Date`, `Time`,
              `TimeStamp`, timezone, device_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'ssssssss',
            $value1,
            $value2,
            $value3,
            $finalDate,
            $finalTime,
            $utcDateTime,
            $finalTimezone,
            $combinedDeviceId
        );

        $stmt->execute();
        $stmt->close();
    }

    /*
     * Mark cycle complete only after successful insertion
     * or confirmation that the combined row already exists.
     */
    $stmt = $conn->prepare(
        "UPDATE sensor_cycles8
         SET completed = 1
         WHERE cycle_id = ?"
    );

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    $transactionStarted = false;

    sendJson(200, [
        'ok' => true,
        'message' => 'All three readings combined successfully',
        'cycle_id' => $cycleId,
        'value1' => $readings['ESP0001']['sensor_value'],
        'value2' => $readings['ESP0002']['sensor_value'],
        'value3' => $readings['ESP0003']['sensor_value'],
        'timestamp_utc' => $utcDateTime,
        'local_datetime' => $finalDate . ' ' . $finalTime,
        'timezone' => $finalTimezone,
        'logs_inserted' => empty($duplicateRow)
    ]);

} catch (Throwable $e) {

    if ($transactionStarted && isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                'datalogger8 rollback ERROR: ' .
                $rollbackError->getMessage()
            );
        }
    }

    logReceiveError(
        $e->getMessage(),
        (int)$cycleId,
        $deviceId
    );

    sendJson(500, [
        'ok' => false,
        'message' => 'Sensor recording failed',
        'detail' => $e->getMessage(),
        'cycle_id' => $cycleId
    ]);
}
