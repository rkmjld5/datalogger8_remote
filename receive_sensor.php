<?php
declare(strict_types=1);

/*
 * Project: datalogger8_remote
 * File: receive_sensor.php
 *
 * Receives readings from ESP0001, ESP0002 and ESP0003.
 *
 * ESP0001 -> value1
 * ESP0002 -> value2
 * ESP0003 -> value3
 *
 * Uses:
 * sensor_pending8
 * sensor_cycles8
 *
 * Final combined records are stored in:
 * espdemo2.logs
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('UTC');


/* ---------------------------------------------------------
   1. JSON RESPONSE FUNCTION
   --------------------------------------------------------- */

function respond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* ---------------------------------------------------------
   2. ACCEPT POST REQUESTS ONLY
   --------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Allow: POST');

    respond(405, [
        'ok' => false,
        'message' => 'POST requests only'
    ]);
}


/* ---------------------------------------------------------
   3. DATABASE CONNECTION
   --------------------------------------------------------- */

require_once __DIR__ . '/config.php';


/* ---------------------------------------------------------
   4. API TOKEN AUTHENTICATION
   --------------------------------------------------------- */

$expectedToken = trim((string)getenv('ESP_API_TOKEN'));

if ($expectedToken === '') {

    error_log('ESP_API_TOKEN is not configured.');

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


/* ---------------------------------------------------------
   5. READ JSON DATA FROM ESP8266
   --------------------------------------------------------- */

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


/* ---------------------------------------------------------
   6. VALIDATE DEVICE ID
   --------------------------------------------------------- */

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


/* ---------------------------------------------------------
   7. VALIDATE SENSOR VALUE
   --------------------------------------------------------- */

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


/* ---------------------------------------------------------
   8. VALIDATE CYCLE ID AND SAMPLE EPOCH
   --------------------------------------------------------- */

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


/* ---------------------------------------------------------
   9. VALIDATE TIMEZONE
   --------------------------------------------------------- */

$timezone = (string)($data['timezone'] ?? '');

try {

    $tz = new DateTimeZone($timezone);

} catch (Throwable $e) {

    respond(400, [
        'ok' => false,
        'message' => 'Invalid timezone'
    ]);
}


/* ---------------------------------------------------------
   10. CHECK FIVE-SECOND CYCLE
   --------------------------------------------------------- */

/*
 * All three controllers must use the same UTC sample time.
 *
 * cycle_id = sample_epoch / 5
 */

if ($cycleId !== intdiv($sampleEpoch, 5)) {

    respond(400, [
        'ok' => false,
        'message' => 'Cycle ID does not match sample time'
    ]);
}


/* ---------------------------------------------------------
   11. LIVE DATA TIME VALIDATION
   --------------------------------------------------------- */

/*
 * Live endpoint accepts:
 * Up to 300 seconds old.
 * Up to 30 seconds in the future.
 *
 * Historical SD card and LittleFS uploads
 * will use separate synchronization files.
 */

$now = time();

if (
    $sampleEpoch < $now - 300 ||
    $sampleEpoch > $now + 30
) {

    respond(400, [
        'ok' => false,
        'message' => 'Sample time is outside the allowed window'
    ]);
}


/* ---------------------------------------------------------
   12. CALCULATE LOCAL DATE AND TIME
   --------------------------------------------------------- */

$sampleDateTime = (
    new DateTimeImmutable('@' . $sampleEpoch)
)->setTimezone($tz);

$localDate = $sampleDateTime->format('Y-m-d');

$localTime = $sampleDateTime->format('H:i:s');


/* ---------------------------------------------------------
   13. PROCESS THE SENSOR READING
   --------------------------------------------------------- */

try {

    $conn->begin_transaction();


    /* -----------------------------------------------------
       13A. CREATE CYCLE RECORD
       ----------------------------------------------------- */

    /*
     * New project uses sensor_cycles8.
     * It does not use sensor_cycles from datalogger6.
     */

    $stmt = $conn->prepare(
        'INSERT IGNORE INTO sensor_cycles8
            (cycle_id)
         VALUES (?)'
    );

    $stmt->bind_param('i', $cycleId);

    $stmt->execute();

    $stmt->close();


    /* -----------------------------------------------------
       13B. LOCK THE CYCLE
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'SELECT completed
         FROM sensor_cycles8
         WHERE cycle_id = ?
         FOR UPDATE'
    );

    $stmt->bind_param('i', $cycleId);

    $stmt->execute();

    $result = $stmt->get_result();

    $cycle = $result->fetch_assoc();

    $stmt->close();


    if (!$cycle) {

        throw new RuntimeException(
            'Unable to lock collection cycle'
        );
    }


    /* -----------------------------------------------------
       13C. CHECK WHETHER CYCLE IS ALREADY COMPLETED
       ----------------------------------------------------- */

    if ((int)$cycle['completed'] === 1) {

        $conn->commit();

        respond(200, [
            'ok' => true,
            'cycle_id' => $cycleId,
            'message' => 'Cycle already completed',
            'completed' => true
        ]);
    }


    /* -----------------------------------------------------
       13D. SAVE INDIVIDUAL SENSOR READING
       ----------------------------------------------------- */

    /*
     * New project uses sensor_pending8.
     *
     * One record per controller per five-second cycle.
     */

    $stmt = $conn->prepare(
        'INSERT INTO sensor_pending8
            (
                cycle_id,
                device_id,
                sensor_value,
                sample_epoch,
                timezone
            )
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            sensor_value = VALUES(sensor_value),
            sample_epoch = VALUES(sample_epoch),
            timezone = VALUES(timezone)'
    );

    $stmt->bind_param(
        'issis',
        $cycleId,
        $deviceId,
        $sensorValue,
        $sampleEpoch,
        $timezone
    );

    $stmt->execute();

    $stmt->close();


    /* -----------------------------------------------------
       13E. COUNT RECEIVED CONTROLLERS
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM sensor_pending8
         WHERE cycle_id = ?'
    );

    $stmt->bind_param('i', $cycleId);

    $stmt->execute();

    $result = $stmt->get_result();

    $countRow = $result->fetch_assoc();

    $stmt->close();

    $total = (int)($countRow['total'] ?? 0);


    /* -----------------------------------------------------
       13F. WAIT FOR ALL THREE CONTROLLERS
       ----------------------------------------------------- */

    if ($total < 3) {

        $conn->commit();

        respond(200, [
            'ok' => true,
            'cycle_id' => $cycleId,
            'device_id' => $deviceId,
            'received' => $total,
            'required' => 3,
            'completed' => false,
            'message' =>
                'Reading stored; waiting for other controllers'
        ]);
    }


    /* -----------------------------------------------------
       13G. READ ALL THREE SENSOR VALUES
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

    $stmt->bind_param('i', $cycleId);

    $stmt->execute();

    $result = $stmt->get_result();

    $readings = [];

    while ($row = $result->fetch_assoc()) {

        $readings[$row['device_id']] = $row;
    }

    $stmt->close();


    /* -----------------------------------------------------
       13H. VERIFY ALL REQUIRED CONTROLLERS
       ----------------------------------------------------- */

    foreach ($allowedDevices as $requiredDevice) {

        if (!isset($readings[$requiredDevice])) {

            $conn->commit();

            respond(200, [
                'ok' => true,
                'cycle_id' => $cycleId,
                'completed' => false,
                'message' =>
                    'Waiting for a required controller'
            ]);
        }
    }


    /* -----------------------------------------------------
       13I. VERIFY COMMON SAMPLE TIME
       ----------------------------------------------------- */

    foreach ($allowedDevices as $requiredDevice) {

        if (
            (int)$readings[$requiredDevice]['sample_epoch']
            !== $sampleEpoch
        ) {

            throw new RuntimeException(
                'Controller sample times do not match'
            );
        }
    }


    /* -----------------------------------------------------
       13J. ASSIGN THREE VALUES
       ----------------------------------------------------- */

    $finalTimezone = $readings['ESP0001']['timezone'];

    $finalDateTime = (
        new DateTimeImmutable('@' . $sampleEpoch)
    )->setTimezone(
        new DateTimeZone($finalTimezone)
    );

    $finalDate = $finalDateTime->format('Y-m-d');

    $finalTime = $finalDateTime->format('H:i:s');

    $value1 = $readings['ESP0001']['sensor_value'];

    $value2 = $readings['ESP0002']['sensor_value'];

    $value3 = $readings['ESP0003']['sensor_value'];

    $combinedDeviceIds = 'ESP0001,ESP0002,ESP0003';


    /* -----------------------------------------------------
       13K. INSERT ONE COMBINED RECORD INTO LOGS
       ----------------------------------------------------- */

    /*
     * Existing espdemo2.logs table is used.
     *
     * The id column is omitted because it is AUTO_INCREMENT.
     * TimeStamp is generated by the database.
     */

    $stmt = $conn->prepare(
        'INSERT INTO logs
            (
                value1,
                value2,
                value3,
                `Date`,
                `Time`,
                timezone,
                device_id
            )
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->bind_param(
        'sssssss',
        $value1,
        $value2,
        $value3,
        $finalDate,
        $finalTime,
        $finalTimezone,
        $combinedDeviceIds
    );

    $stmt->execute();

    $stmt->close();


    /* -----------------------------------------------------
       13L. MARK CYCLE AS COMPLETED
       ----------------------------------------------------- */

    $stmt = $conn->prepare(
        'UPDATE sensor_cycles8
         SET completed = 1
         WHERE cycle_id = ?'
    );

    $stmt->bind_param('i', $cycleId);

    $stmt->execute();

    $stmt->close();


    /* -----------------------------------------------------
       13M. COMMIT TRANSACTION
       ----------------------------------------------------- */

    $conn->commit();


    /* -----------------------------------------------------
       13N. SUCCESS RESPONSE
       ----------------------------------------------------- */

    respond(200, [
        'ok' => true,
        'cycle_id' => $cycleId,
        'completed' => true,
        'message' => 'All three readings stored in logs',
        'readings' => [
            'value1' => $value1,
            'value2' => $value2,
            'value3' => $value3
        ]
    ]);


} catch (Throwable $e) {

    /* -----------------------------------------------------
       14. ERROR HANDLING
       ----------------------------------------------------- */

    try {

        $conn->rollback();

    } catch (Throwable $ignored) {

        // Preserve the original error.
    }

    error_log(
        'datalogger8 receive_sensor.php failed: ' .
        $e->getMessage()
    );

    respond(500, [
        'ok' => false,
        'message' => 'Server could not process the reading'
    ]);
}
