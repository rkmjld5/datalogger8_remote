
<?php
declare(strict_types=1);

/*
 * Project: datalogger6_remote
 * File: sync_sdcard.php
 *
 * Historical SD-card synchronization for ESP0001, ESP0002, ESP0003.
 *
 * SD CSV format (25 columns):
 * id,value1,value2,value3,Date,Time,TimeStamp,timezone,device_id,
 * CH1,CH2,CH3,CH4,CH5,CH6,CH7,CH8,CH9,CH10,CH11,CH12,CH13,CH14,CH15,CH16
 *
 * This endpoint:
 * 1. Accepts one historical record per POST request.
 * 2. Authenticates using ESP_API_TOKEN.
 * 3. Does not apply the live 300-second restriction.
 * 4. Stores each controller's reading in sensor_pending.
 * 5. Combines all three controllers into one logs row.
 * 6. Uses sensor_cycles.completed to prevent duplicate combined rows.
 *
 * Existing receive_sensor.php is not modified.
 * Existing database and logs table are not modified.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('UTC');


/* -------------------------------------------------------
   JSON RESPONSE
------------------------------------------------------- */

function syncRespond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* -------------------------------------------------------
   POST METHOD
------------------------------------------------------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

    header('Allow: POST');

    syncRespond(405, [
        'ok' => false,
        'message' => 'POST requests only'
    ]);
}


/* -------------------------------------------------------
   DATABASE CONFIGURATION
------------------------------------------------------- */

require_once __DIR__ . '/config.php';


/* -------------------------------------------------------
   API TOKEN AUTHENTICATION

   Supports the same HTTP header used by receive_sensor.php.
   Also accepts api_token as a POST field for compatibility.
------------------------------------------------------- */

$expectedToken = trim((string)getenv('ESP_API_TOKEN'));

if ($expectedToken === '') {

    error_log('ESP_API_TOKEN is not configured.');

    syncRespond(500, [
        'ok' => false,
        'message' => 'Server API token is not configured'
    ]);
}

$providedToken = (string)(
    $_SERVER['HTTP_X_API_TOKEN']
    ?? $_POST['api_token']
    ?? ''
);

if (
    $providedToken === '' ||
    !hash_equals($expectedToken, $providedToken)
) {

    syncRespond(401, [
        'ok' => false,
        'message' => 'Invalid API token'
    ]);
}


/* -------------------------------------------------------
   READ POST DATA

   The ESP sends the relevant fields from its 25-column
   SD CSV record. CH1 to CH16 are accepted but are not
   inserted into the existing logs table.
------------------------------------------------------- */

$csvId = trim((string)($_POST['csv_id'] ?? $_POST['id'] ?? ''));

$value1 = trim((string)($_POST['value1'] ?? ''));
$value2 = trim((string)($_POST['value2'] ?? ''));
$value3 = trim((string)($_POST['value3'] ?? ''));

$timezone = trim((string)($_POST['timezone'] ?? ''));
$deviceId = trim((string)($_POST['device_id'] ?? ''));

$timestamp = trim((string)($_POST['TimeStamp'] ?? ''));

$sampleEpochInput = $_POST['sample_epoch'] ?? null;
$cycleIdInput = $_POST['cycle_id'] ?? null;


/* -------------------------------------------------------
   VALIDATE CSV ID
------------------------------------------------------- */

if (
    $csvId === '' ||
    !ctype_digit($csvId)
) {
    syncRespond(400, [
        'ok' => false,
        'message' => 'Invalid CSV record ID'
    ]);
}


/* -------------------------------------------------------
   VALIDATE DEVICE ID
------------------------------------------------------- */

$allowedDevices = [
    'ESP0001',
    'ESP0002',
    'ESP0003'
];

if (!in_array($deviceId, $allowedDevices, true)) {

    syncRespond(400, [
        'ok' => false,
        'message' => 'Unknown device ID'
    ]);
}


/* -------------------------------------------------------
   SELECT THE CORRECT SENSOR VALUE

   ESP0001 -> value1
   ESP0002 -> value2
   ESP0003 -> value3
------------------------------------------------------- */

if ($deviceId === 'ESP0001') {
    $sensorValue = $value1;
} elseif ($deviceId === 'ESP0002') {
    $sensorValue = $value2;
} else {
    $sensorValue = $value3;
}

if (
    $sensorValue === '' ||
    !is_numeric($sensorValue) ||
    !is_finite((float)$sensorValue)
) {
    syncRespond(400, [
        'ok' => false,
        'message' => 'Invalid sensor value for this controller'
    ]);
}


/* -------------------------------------------------------
   VALIDATE FIELD LENGTHS
------------------------------------------------------- */

if (
    strlen($deviceId) > 32 ||
    strlen($value1) > 30 ||
    strlen($value2) > 30 ||
    strlen($value3) > 50 ||
    strlen($timezone) > 64
) {
    syncRespond(400, [
        'ok' => false,
        'message' => 'One or more fields exceed database field lengths'
    ]);
}


/* -------------------------------------------------------
   VALIDATE TIMEZONE
------------------------------------------------------- */

try {

    $tz = new DateTimeZone($timezone);

} catch (Throwable $e) {

    syncRespond(400, [
        'ok' => false,
        'message' => 'Invalid timezone'
    ]);
}


/* -------------------------------------------------------
   VALIDATE TIMESTAMP

   IMPORTANT:
   This code assumes the CSV TimeStamp represents UTC,
   in the format YYYY-MM-DD HH:MM:SS.
------------------------------------------------------- */

if (
    !preg_match(
        '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
        $timestamp
    )
) {
    syncRespond(400, [
        'ok' => false,
        'message' => 'Invalid UTC TimeStamp format'
    ]);
}

$dateTimeUTC = DateTimeImmutable::createFromFormat(
    '!Y-m-d H:i:s',
    $timestamp,
    new DateTimeZone('UTC')
);

$dateErrors = DateTimeImmutable::getLastErrors();

if (
    $dateTimeUTC === false ||
    (
        $dateErrors !== false &&
        (
            $dateErrors['warning_count'] > 0 ||
            $dateErrors['error_count'] > 0
        )
    ) ||
    $dateTimeUTC->format('Y-m-d H:i:s') !== $timestamp
) {
    syncRespond(400, [
        'ok' => false,
        'message' => 'Invalid timestamp date or time'
    ]);
}


/* -------------------------------------------------------
   CALCULATE FIVE-SECOND COLLECTION CYCLE

   Match the cycle calculation used by receive_sensor.php.
------------------------------------------------------- */

$originalEpoch = $dateTimeUTC->getTimestamp();

$sampleEpoch = intdiv($originalEpoch, 5) * 5;

$cycleId = intdiv($sampleEpoch, 5);


/* -------------------------------------------------------
   OPTIONAL FIRMWARE CYCLE VALIDATION

   If supplied, the firmware values must match the
   cycle calculated from the CSV timestamp.
------------------------------------------------------- */

if ($sampleEpochInput !== null && $sampleEpochInput !== '') {

    $givenEpoch = filter_var(
        $sampleEpochInput,
        FILTER_VALIDATE_INT
    );

    if (
        $givenEpoch === false ||
        $givenEpoch !== $sampleEpoch
    ) {
        syncRespond(400, [
            'ok' => false,
            'message' => 'sample_epoch does not match CSV timestamp'
        ]);
    }
}

if ($cycleIdInput !== null && $cycleIdInput !== '') {

    $givenCycle = filter_var(
        $cycleIdInput,
        FILTER_VALIDATE_INT
    );

    if (
        $givenCycle === false ||
        $givenCycle !== $cycleId
    ) {
        syncRespond(400, [
            'ok' => false,
            'message' => 'cycle_id does not match CSV timestamp'
        ]);
    }
}


/* -------------------------------------------------------
   CALCULATE INSTALLATION-LOCAL DATE AND TIME

   Use the original five-second sample time.
------------------------------------------------------- */

$localDateTime = (
    new DateTimeImmutable('@' . $sampleEpoch)
)->setTimezone($tz);

$localDate = $localDateTime->format('Y-m-d');
$localTime = $localDateTime->format('H:i:s');


/* -------------------------------------------------------
   CHECK DATABASE CONNECTION
------------------------------------------------------- */

if (!isset($conn) || !($conn instanceof mysqli)) {

    error_log('Database connection $conn is unavailable.');

    syncRespond(500, [
        'ok' => false,
        'message' => 'Database connection is unavailable'
    ]);
}


/* -------------------------------------------------------
   HISTORICAL SYNCHRONIZATION

   Unlike receive_sensor.php, this endpoint deliberately
   does not reject old sample timestamps.

   The same transaction and cycle-locking approach is used.
------------------------------------------------------- */

try {

    $conn->query("SET time_zone = '+00:00'");

    $conn->begin_transaction();


    /* Create cycle if it does not exist. */

    $stmt = $conn->prepare(
        'INSERT IGNORE INTO sensor_cycles (cycle_id)
         VALUES (?)'
    );

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $stmt->close();


    /* Lock cycle to prevent simultaneous duplicate inserts. */

    $stmt = $conn->prepare(
        'SELECT completed
         FROM sensor_cycles
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
            'Unable to lock historical collection cycle'
        );
    }


    /* Already completed: do not insert another logs row. */

    if ((int)$cycle['completed'] === 1) {

        $conn->commit();

        syncRespond(200, [
            'ok' => true,
            'historical' => true,
            'csv_id' => $csvId,
            'cycle_id' => $cycleId,
            'device_id' => $deviceId,
            'completed' => true,
            'message' => 'Historical cycle already completed; duplicate skipped'
        ]);
    }


    /* Insert or update this controller's historical reading. */

    $stmt = $conn->prepare(
        'INSERT INTO sensor_pending
            (cycle_id, device_id, sensor_value,
             sample_epoch, timezone)
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


    /* Count readings received for this cycle. */

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM sensor_pending
         WHERE cycle_id = ?'
    );

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $countRow = $result->fetch_assoc();

    $stmt->close();

    $total = (int)($countRow['total'] ?? 0);


    /* Wait for the other controllers. */

    if ($total < 3) {

        $conn->commit();

        syncRespond(200, [
            'ok' => true,
            'historical' => true,
            'csv_id' => $csvId,
            'cycle_id' => $cycleId,
            'device_id' => $deviceId,
            'received' => $total,
            'required' => 3,
            'completed' => false,
            'message' => 'Historical reading stored; waiting for other controllers'
        ]);
    }


    /* Retrieve the three readings. */

    $stmt = $conn->prepare(
        'SELECT device_id, sensor_value,
                sample_epoch, timezone
         FROM sensor_pending
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


    /* Verify all three required controllers. */

    foreach ($allowedDevices as $requiredDevice) {

        if (!isset($readings[$requiredDevice])) {

            $conn->commit();

            syncRespond(200, [
                'ok' => true,
                'historical' => true,
                'cycle_id' => $cycleId,
                'completed' => false,
                'message' => 'Waiting for a required controller'
            ]);
        }
    }


    /* All three must have the same five-second sample time. */

    foreach ($allowedDevices as $requiredDevice) {

        if (
            (int)$readings[$requiredDevice]['sample_epoch']
            !== $sampleEpoch
        ) {
            throw new RuntimeException(
                'Historical controller sample times do not match'
            );
        }
    }


    /* Use ESP0001 timezone, as in receive_sensor.php. */

    $finalTimezone = $readings['ESP0001']['timezone'];

    $finalDateTime = (
        new DateTimeImmutable('@' . $sampleEpoch)
    )->setTimezone(
        new DateTimeZone($finalTimezone)
    );

    $finalDate = $finalDateTime->format('Y-m-d');
    $finalTime = $finalDateTime->format('H:i:s');


    /* Assign the three sensor readings. */

    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    $combinedDeviceIds = 'ESP0001,ESP0002,ESP0003';


    /* Insert exactly one combined logs record. */

    $stmt = $conn->prepare(
        'INSERT INTO logs
            (value1, value2, value3, `Date`, `Time`,
             timezone, device_id)
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


    /* Mark the cycle completed in the same transaction. */

    $stmt = $conn->prepare(
        'UPDATE sensor_cycles
         SET completed = 1
         WHERE cycle_id = ?'
    );

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $stmt->close();


    $conn->commit();


    syncRespond(200, [
        'ok' => true,
        'historical' => true,
        'csv_id' => $csvId,
        'cycle_id' => $cycleId,
        'completed' => true,
        'message' => 'All three historical readings stored in logs',
        'readings' => [
            'value1' => $value1,
            'value2' => $value2,
            'value3' => $value3
        ]
    ]);


} catch (Throwable $e) {

    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // Preserve original error.
    }

    error_log(
        'sync_sdcard.php failed: ' . $e->getMessage()
    );

    syncRespond(500, [
        'ok' => false,
        'message' => 'Server could not process historical reading'
    ]);
}

?>
