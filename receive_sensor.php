<?php
declare(strict_types=1);

/*
 * Project: datalogger8_remote
 * File: receive_sensor.php
 *
 * Receives readings from ESP0001, ESP0002 and ESP0003.
 * Stores readings in sensor_pending8.
 * Combines all three readings into one logs row.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('UTC');

function respond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/* POST only */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');

    respond(405, [
        'ok' => false,
        'message' => 'POST requests only'
    ]);
}

/* Load database configuration */

require_once __DIR__ . '/config.php';

/* Authenticate controller */

$expectedToken = trim((string)getenv('ESP_API_TOKEN'));

if ($expectedToken === '') {
    error_log('datalogger8: ESP_API_TOKEN is not configured.');

    respond(500, [
        'ok' => false,
        'message' => 'Server API token is not configured'
    ]);
}

$providedToken = (string)($_SERVER['HTTP_X_API_TOKEN'] ?? '');

if (
    $providedToken === '' ||
    !hash_equals($expectedToken, $providedToken)
) {
    respond(401, [
        'ok' => false,
        'message' => 'Invalid API token'
    ]);
}

/* Read JSON */

$raw = file_get_contents('php://input');

if ($raw === false || strlen($raw) > 4096) {
    respond(400, [
        'ok' => false,
        'message' => 'Invalid request body'
    ]);
}

$data = json_decode($raw, true);

if (!is_array($data)) {
    respond(400, [
        'ok' => false,
        'message' => 'Valid JSON is required'
    ]);
}

/* Validate device ID */

$deviceId = strtoupper(
    trim((string)($data['device_id'] ?? ''))
);

$allowedDevices = [
    'ESP0001',
    'ESP0002',
    'ESP0003'
];

if (!in_array($deviceId, $allowedDevices, true)) {
    respond(400, [
        'ok' => false,
        'message' => 'Unknown device ID'
    ]);
}

/* Validate sensor value */

$value = $data['value'] ?? null;

if (
    !is_numeric($value) ||
    !is_finite((float)$value)
) {
    respond(400, [
        'ok' => false,
        'message' => 'Invalid sensor value'
    ]);
}

$sensorValue = (string)$value;

if (strlen($sensorValue) > 50) {
    respond(400, [
        'ok' => false,
        'message' => 'Sensor value is too long'
    ]);
}

/* Validate cycle information */

$cycleId = filter_var(
    $data['cycle_id'] ?? null,
    FILTER_VALIDATE_INT
);

$sampleEpoch = filter_var(
    $data['sample_epoch'] ?? null,
    FILTER_VALIDATE_INT
);

if (
    $cycleId === false ||
    $cycleId === null ||
    $cycleId <= 0 ||
    $sampleEpoch === false ||
    $sampleEpoch === null ||
    $sampleEpoch <= 0
) {
    respond(400, [
        'ok' => false,
        'message' => 'Invalid cycle_id or sample_epoch'
    ]);
}

/* All devices must use the same five-second UTC cycle */

if ($cycleId !== intdiv($sampleEpoch, 5)) {
    respond(400, [
        'ok' => false,
        'message' => 'Cycle ID does not match sample time'
    ]);
}

/* Validate timezone */

$timezone = trim((string)($data['timezone'] ?? ''));

try {
    new DateTimeZone($timezone);
} catch (Throwable $e) {
    respond(400, [
        'ok' => false,
        'message' => 'Invalid timezone'
    ]);
}

/*
 * Live-reading time validation.
 *
 * This retains the existing five-minute old-reading
 * and 30-second future-reading limits.
 *
 * Historical SD card / LittleFS uploads need their own
 * separately designed upload handling if older records
 * are to be accepted.
 */

$now = time();

if (
    $sampleEpoch < $now - 300 ||
    $sampleEpoch > $now + 30
) {
    respond(400, [
        'ok' => false,
        'message' => 'Sample time is outside the allowed window',
        'server_epoch' => $now,
        'sample_epoch' => $sampleEpoch
    ]);
}

/* Calculate UTC and local time */

$utcDateTime = gmdate('Y-m-d H:i:s', $sampleEpoch);

try {
    $localDateTime = (new DateTimeImmutable('@' . $sampleEpoch))
        ->setTimezone(new DateTimeZone($timezone));

    $localDate = $localDateTime->format('Y-m-d');
    $localTime = $localDateTime->format('H:i:s');

} catch (Throwable $e) {
    respond(400, [
        'ok' => false,
        'message' => 'Unable to calculate local time'
    ]);
}

/* Database transaction */

$transactionStarted = false;

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            'Database connection is unavailable'
        );
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+00:00'");

    $conn->begin_transaction();
    $transactionStarted = true;

    $cycleIdString = (string)$cycleId;
    $sampleEpochString = (string)$sampleEpoch;

    /*
     * Create cycle row if absent.
     */

    $stmt = $conn->prepare(
        "INSERT INTO sensor_cycles8 (cycle_id, completed)
         VALUES (?, 0)
         ON DUPLICATE KEY UPDATE cycle_id = VALUES(cycle_id)"
    );

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();
    $stmt->close();

    /*
     * Lock this cycle during processing.
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
        throw new RuntimeException(
            'Unable to find collection cycle'
        );
    }

    if ((int)$cycleRow['completed'] === 1) {
        $conn->commit();
        $transactionStarted = false;

        respond(200, [
            'ok' => true,
            'cycle_id' => $cycleId,
            'completed' => true,
            'message' => 'Cycle already completed'
        ]);
    }

    /*
     * Check whether this controller already submitted
     * its reading for this cycle.
     */

    $stmt = $conn->prepare(
        "SELECT sensor_value, sample_epoch
         FROM sensor_pending8
         WHERE cycle_id = ? AND device_id = ?
         FOR UPDATE"
    );

    $stmt->bind_param(
        'ss',
        $cycleIdString,
        $deviceId
    );

    $stmt->execute();

    $existingResult = $stmt->get_result();
    $existingRow = $existingResult->fetch_assoc();

    $stmt->close();

    if ($existingRow) {
        if (
            (string)$existingRow['sensor_value'] !== $sensorValue ||
            (string)$existingRow['sample_epoch'] !== $sampleEpochString
        ) {
            throw new RuntimeException(
                'Conflicting duplicate reading for this device and cycle'
            );
        }
    } else {
        /*
         * Insert this controller's reading.
         */

        $stmt = $conn->prepare(
            "INSERT INTO sensor_pending8
             (cycle_id, device_id, sensor_value,
              sample_epoch, timezone)
             VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'sssss',
            $cycleIdString,
            $deviceId,
            $sensorValue,
            $sampleEpochString,
            $timezone
        );

        $stmt->execute();
        $stmt->close();
    }

    /*
     * Read all submitted readings for this cycle.
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

    /*
     * Wait until all three controllers have submitted.
     */

    foreach ($allowedDevices as $requiredDevice) {
        if (!isset($readings[$requiredDevice])) {
            $conn->commit();
            $transactionStarted = false;

            respond(200, [
                'ok' => true,
                'cycle_id' => $cycleId,
                'device_id' => $deviceId,
                'received' => count($readings),
                'required' => 3,
                'completed' => false,
                'stored' => true,
                'message' => 'Reading stored; waiting for other controllers'
            ]);
        }
    }

    /*
     * Confirm all three controllers used the same sample time.
     */

    foreach ($allowedDevices as $requiredDevice) {
        if (
            (string)$readings[$requiredDevice]['sample_epoch'] !==
            $sampleEpochString
        ) {
            throw new RuntimeException(
                'Controller sample times do not match'
            );
        }
    }

    /*
     * Use ESP0001 timezone for the combined logs row.
     */

    $finalTimezone = $readings['ESP0001']['timezone'];

    $finalDateTime = (new DateTimeImmutable('@' . $sampleEpoch))
        ->setTimezone(new DateTimeZone($finalTimezone));

    $finalDate = $finalDateTime->format('Y-m-d');
    $finalTime = $finalDateTime->format('H:i:s');

    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    $combinedDeviceId = 'ESP0001,ESP0002,ESP0003';

    /*
     * Check for an existing combined record at this UTC timestamp.
     */

    $stmt = $conn->prepare(
        "SELECT id
         FROM logs
         WHERE TimeStamp = ?
           AND device_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->bind_param(
        'ss',
        $utcDateTime,
        $combinedDeviceId
    );

    $stmt->execute();

    $duplicateResult = $stmt->get_result();
    $duplicateRow = $duplicateResult->fetch_assoc();

    $stmt->close();

    $logsInserted = false;

    if (!$duplicateRow) {
        /*
         * Insert one combined row.
         * id is omitted because it is AUTO_INCREMENT.
         */

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

        $logsInserted = true;
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

    respond(200, [
        'ok' => true,
        'stored' => true,
        'cycle_id' => $cycleId,
        'completed' => true,
        'message' => 'All three readings combined successfully',
        'value1' => $value1,
        'value2' => $value2,
        'value3' => $value3,
        'timestamp_utc' => $utcDateTime,
        'local_datetime' => $finalDate . ' ' . $finalTime,
        'timezone' => $finalTimezone,
        'logs_inserted' => $logsInserted
    ]);

} catch (Throwable $e) {
    if (
        $transactionStarted &&
        isset($conn) &&
        $conn instanceof mysqli
    ) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                'datalogger8 rollback error: ' .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        'datalogger8 receive_sensor.php failed: ' .
        $e->getMessage() .
        ' | cycle_id=' . (string)$cycleId .
        ' | device_id=' . $deviceId
    );

    respond(500, [
        'ok' => false,
        'message' => 'Server could not process the reading'
    ]);
}
