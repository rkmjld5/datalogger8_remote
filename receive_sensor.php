<?php
declare(strict_types=1);

/*
 * PROJECT: datalogger8_remote
 * FILE: receive_sensor.php
 *
 * ESP0001 -> value1
 * ESP0002 -> value2
 * ESP0003 -> value3
 *
 * Live staging:
 *   sensor_cycles8
 *   sensor_pending8
 *
 * Final table:
 *   logs
 *
 * All three readings must have the same cycle_id
 * and sample_epoch.
 *
 * TimeStamp is explicitly stored from sample_epoch.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('UTC');


/* =========================================================
   1. JSON RESPONSE
   ========================================================= */

function respond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* =========================================================
   2. POST ONLY
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    respond(405, [
        'ok' => false,
        'message' => 'POST requests only'
    ]);
}


/* =========================================================
   3. DATABASE CONNECTION
   ========================================================= */

require_once __DIR__ . '/config.php';


/* =========================================================
   4. SET DATABASE SESSION TIMEZONE TO UTC
   ========================================================= */

if (!$conn->query("SET time_zone = '+00:00'")) {
    error_log(
        'datalogger8: Unable to set database UTC timezone: ' .
        $conn->error
    );

    respond(500, [
        'ok' => false,
        'message' => 'Database timezone configuration failed'
    ]);
}


/* =========================================================
   5. API TOKEN
   ========================================================= */

$expectedToken = trim((string)getenv('ESP_API_TOKEN'));

if ($expectedToken === '') {
    error_log('datalogger8: ESP_API_TOKEN is not configured');

    respond(500, [
        'ok' => false,
        'message' => 'Server API token is not configured'
    ]);
}

$providedToken = (string)(
    $_SERVER['HTTP_X_API_TOKEN'] ?? ''
);

if (
    $providedToken === '' ||
    !hash_equals($expectedToken, $providedToken)
) {
    respond(401, [
        'ok' => false,
        'message' => 'Invalid API token'
    ]);
}


/* =========================================================
   6. READ JSON
   ========================================================= */

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


/* =========================================================
   7. VALIDATE DEVICE
   ========================================================= */

$deviceId = (string)($data['device_id'] ?? '');

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


/* =========================================================
   8. VALIDATE SENSOR VALUE
   ========================================================= */

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


/* =========================================================
   9. VALIDATE CYCLE AND EPOCH
   ========================================================= */

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


/* =========================================================
   10. VALIDATE TIMEZONE
   ========================================================= */

$timezone = (string)($data['timezone'] ?? '');

try {
    new DateTimeZone($timezone);
} catch (Throwable $e) {
    respond(400, [
        'ok' => false,
        'message' => 'Invalid timezone'
    ]);
}


/* =========================================================
   11. VALIDATE FIVE-SECOND CYCLE
   ========================================================= */

if ($cycleId !== intdiv($sampleEpoch, 5)) {
    respond(400, [
        'ok' => false,
        'message' => 'Cycle ID does not match sample time'
    ]);
}


/* =========================================================
   12. VALIDATE LIVE DATA AGE
   ========================================================= */

$now = time();

if (
    $sampleEpoch < $now - 300 ||
    $sampleEpoch > $now + 30
) {
    respond(400, [
        'ok' => false,
        'message' => 'Sample time is outside allowed window'
    ]);
}


/* =========================================================
   13. PREPARE UTC TIMESTAMP
   ========================================================= */

$utcDateTime = (
    new DateTimeImmutable('@' . $sampleEpoch)
)->setTimezone(new DateTimeZone('UTC'));

$finalTimestamp = $utcDateTime->format('Y-m-d H:i:s');


/* =========================================================
   14. MAIN TRANSACTION
   ========================================================= */

try {

    $conn->begin_transaction();


    /* -----------------------------------------------------
       A. CREATE CYCLE IF IT DOES NOT EXIST
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'INSERT IGNORE INTO sensor_cycles8
            (cycle_id, completed)
         VALUES (?, 0)'
    );

    $cycleIdString = (string)$cycleId;

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();
    $stmt->close();


    /* -----------------------------------------------------
       B. LOCK THE CYCLE ROW
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'SELECT completed
         FROM sensor_cycles8
         WHERE cycle_id = ?
         FOR UPDATE'
    );

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();

    $cycleResult = $stmt->get_result();
    $cycleRow = $cycleResult->fetch_assoc();

    $stmt->close();

    if (!$cycleRow) {
        throw new RuntimeException(
            'Cycle row could not be found'
        );
    }


    /* -----------------------------------------------------
       C. CHECK IF ALREADY COMPLETED
       ----------------------------------------------------- */

    if ((int)$cycleRow['completed'] === 1) {

        $conn->commit();

        respond(200, [
            'ok' => true,
            'cycle_id' => $cycleId,
            'completed' => true,
            'message' => 'Cycle already completed'
        ]);
    }


    /* -----------------------------------------------------
       D. CHECK EXISTING READING
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'SELECT sensor_value, sample_epoch, timezone
         FROM sensor_pending8
         WHERE cycle_id = ?
           AND device_id = ?
         FOR UPDATE'
    );

    $stmt->bind_param(
        'ss',
        $cycleIdString,
        $deviceId
    );

    $stmt->execute();

    $existingResult = $stmt->get_result();
    $existing = $existingResult->fetch_assoc();

    $stmt->close();


    if ($existing) {

        /*
         * Identical retry is allowed.
         * Different reading is rejected.
         */

        if (
            (string)$existing['sensor_value'] !== $sensorValue ||
            (string)$existing['sample_epoch'] !== (string)$sampleEpoch ||
            (string)$existing['timezone'] !== $timezone
        ) {

            $conn->rollback();

            respond(409, [
                'ok' => false,
                'cycle_id' => $cycleId,
                'device_id' => $deviceId,
                'message' =>
                    'Conflicting reading already exists for this cycle'
            ]);
        }

    } else {

        /* -------------------------------------------------
           E. INSERT NEW READING
           ------------------------------------------------- */

        $stmt = $conn->prepare(
            'INSERT INTO sensor_pending8
                (
                    cycle_id,
                    device_id,
                    sensor_value,
                    sample_epoch,
                    timezone
                )
             VALUES (?, ?, ?, ?, ?)'
        );

        $epochString = (string)$sampleEpoch;

        $stmt->bind_param(
            'sssss',
            $cycleIdString,
            $deviceId,
            $sensorValue,
            $epochString,
            $timezone
        );

        $stmt->execute();
        $stmt->close();
    }


    /* -----------------------------------------------------
       F. READ ALL READINGS FOR THIS CYCLE
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'SELECT
            device_id,
            sensor_value,
            sample_epoch,
            timezone
         FROM sensor_pending8
         WHERE cycle_id = ?
         ORDER BY device_id'
    );

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();

    $readingResult = $stmt->get_result();

    $readings = [];

    while ($row = $readingResult->fetch_assoc()) {
        $readings[$row['device_id']] = $row;
    }

    $stmt->close();


    /* -----------------------------------------------------
       G. CHECK ALL THREE CONTROLLERS
       ----------------------------------------------------- */

    foreach ($allowedDevices as $requiredDevice) {

        if (!isset($readings[$requiredDevice])) {

            $conn->commit();

            respond(200, [
                'ok' => true,
                'cycle_id' => $cycleId,
                'received' => count($readings),
                'required' => 3,
                'completed' => false,
                'message' => 'Waiting for other controllers'
            ]);
        }
    }


    /* -----------------------------------------------------
       H. VERIFY IDENTICAL SAMPLE EPOCH
       ----------------------------------------------------- */

    foreach ($allowedDevices as $requiredDevice) {

        if (
            (string)$readings[$requiredDevice]['sample_epoch']
            !== (string)$sampleEpoch
        ) {
            throw new RuntimeException(
                'All three sample epochs do not match'
            );
        }
    }


    /* -----------------------------------------------------
       I. USE ESP0001 TIMEZONE FOR FINAL LOCAL TIME
       ----------------------------------------------------- */

    $finalTimezone = (string)(
        $readings['ESP0001']['timezone']
    );

    $finalTz = new DateTimeZone($finalTimezone);

    $localDateTime = (
        new DateTimeImmutable('@' . $sampleEpoch)
    )->setTimezone($finalTz);

    $finalDate = $localDateTime->format('Y-m-d');
    $finalTime = $localDateTime->format('H:i:s');


    /* -----------------------------------------------------
       J. ASSIGN VALUES
       ----------------------------------------------------- */

    $value1 = (string)$readings['ESP0001']['sensor_value'];
    $value2 = (string)$readings['ESP0002']['sensor_value'];
    $value3 = (string)$readings['ESP0003']['sensor_value'];

    $combinedDeviceIds = 'ESP0001,ESP0002,ESP0003';


    /* -----------------------------------------------------
       K. INSERT COMBINED RECORD INTO LOGS
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'INSERT INTO logs
            (
                value1,
                value2,
                value3,
                `Date`,
                `Time`,
                `TimeStamp`,
                timezone,
                device_id
            )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->bind_param(
        'ssssssss',
        $value1,
        $value2,
        $value3,
        $finalDate,
        $finalTime,
        $finalTimestamp,
        $finalTimezone,
        $combinedDeviceIds
    );

    $stmt->execute();

    $insertedLogId = $conn->insert_id;

    $stmt->close();


    /* -----------------------------------------------------
       L. MARK CYCLE COMPLETED
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'UPDATE sensor_cycles8
         SET completed = 1
         WHERE cycle_id = ?'
    );

    $stmt->bind_param('s', $cycleIdString);
    $stmt->execute();

    $stmt->close();


    /* -----------------------------------------------------
       M. COMMIT
       ----------------------------------------------------- */

    $conn->commit();


    /* -----------------------------------------------------
       N. SUCCESS RESPONSE
       ----------------------------------------------------- */

    respond(200, [
        'ok' => true,
        'cycle_id' => $cycleId,
        'completed' => true,
        'log_id' => $insertedLogId,
        'utc_timestamp' => $finalTimestamp,
        'local_date' => $finalDate,
        'local_time' => $finalTime,
        'timezone' => $finalTimezone,
        'readings' => [
            'value1' => $value1,
            'value2' => $value2,
            'value3' => $value3
        ],
        'message' => 'Combined record inserted successfully'
    ]);


} catch (Throwable $e) {

    /* -----------------------------------------------------
       O. ROLLBACK AND DIAGNOSTIC LOG
       ----------------------------------------------------- */

    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // Ignore rollback error.
    }

    error_log(
        'datalogger8 receive_sensor.php ERROR: ' .
        $e->getMessage() .
        ' | cycle_id=' . (string)$cycleId .
        ' | device_id=' . $deviceId
    );

    respond(500, [
        'ok' => false,
        'cycle_id' => $cycleId,
        'message' => 'Database processing failed',
        'detail' => $e->getMessage()
    ]);
}
