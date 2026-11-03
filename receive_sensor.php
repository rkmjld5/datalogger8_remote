<?php
declare(strict_types=1);

/*
 * Project: datalogger6_remote
 * File: receive_sensor.php
 *
 * Receives readings from ESP0001, ESP0002 and ESP0003.
 * Stores individual readings in sensor_pending.
 * Inserts one combined record into logs when all
 * three readings for a cycle have arrived.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    respond(405, [
        'ok' => false,
        'message' => 'POST requests only'
    ]);
}

/* Load the independent project's database configuration. */
require_once __DIR__ . '/config.php';

/* Authenticate the controller. */
$expectedToken = trim((string)getenv('ESP_API_TOKEN'));

if ($expectedToken === '') {
    error_log('ESP_API_TOKEN is not configured.');

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

/* Read JSON sent by the ESP8266. */
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

/* Validate controller ID. */
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

/* Validate the potentiometer reading. */
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

/* Validate collection-cycle information. */
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

/* Validate the IANA timezone supplied by the controller. */
$timezone = (string)($data['timezone'] ?? '');

try {
    $tz = new DateTimeZone($timezone);
} catch (Throwable $e) {
    respond(400, [
        'ok' => false,
        'message' => 'Invalid timezone'
    ]);
}

/*
 * Every controller must use the same UTC-based cycle ID.
 * Firmware will use five-second collection intervals.
 */
if ($cycleId !== intdiv($sampleEpoch, 5)) {
    respond(400, [
        'ok' => false,
        'message' => 'Cycle ID does not match sample time'
    ]);
}

/* Prevent extremely old or future-dated submissions. */
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

/* Calculate the installation-local date and time. */
$sampleDateTime = (new DateTimeImmutable('@' . $sampleEpoch))
    ->setTimezone($tz);

$localDate = $sampleDateTime->format('Y-m-d');
$localTime = $sampleDateTime->format('H:i:s');

try {
    $conn->begin_transaction();

    /*
     * Create the cycle record if it does not exist.
     * Lock it so simultaneous requests cannot complete
     * the same cycle twice.
     */
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO sensor_cycles (cycle_id)
         VALUES (?)'
    );

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $stmt->close();

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
            'Unable to lock collection cycle'
        );
    }

    if ((int)$cycle['completed'] === 1) {
        $conn->commit();

        respond(200, [
            'ok' => true,
            'cycle_id' => $cycleId,
            'message' => 'Cycle already completed',
            'completed' => true
        ]);
    }

    /*
     * Insert or update this controller's reading.
     * The primary key prevents duplicate controller
     * readings within the same cycle.
     */
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

    /* Count distinct controllers received for this cycle. */
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

    if ($total < 3) {
        $conn->commit();

        respond(200, [
            'ok' => true,
            'cycle_id' => $cycleId,
            'device_id' => $deviceId,
            'received' => $total,
            'required' => 3,
            'completed' => false,
            'message' => 'Reading stored; waiting for other controllers'
        ]);
    }

    /*
     * Retrieve the three readings.
     * A complete cycle must contain exactly the three
     * expected controller IDs.
     */
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

    foreach ($allowedDevices as $requiredDevice) {
        if (!isset($readings[$requiredDevice])) {
            $conn->commit();

            respond(200, [
                'ok' => true,
                'cycle_id' => $cycleId,
                'completed' => false,
                'message' => 'Waiting for a required controller'
            ]);
        }
    }

    /*
     * All three controllers must refer to the same
     * five-second collection interval.
     */
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

    /*
     * Use the timezone from the common collection time.
     * This example uses ESP0001's timezone as the
     * display timezone for the combined record.
     */
    $finalTimezone = $readings['ESP0001']['timezone'];

    $finalDateTime = (new DateTimeImmutable('@' . $sampleEpoch))
        ->setTimezone(new DateTimeZone($finalTimezone));

    $finalDate = $finalDateTime->format('Y-m-d');
    $finalTime = $finalDateTime->format('H:i:s');

    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    $combinedDeviceIds = 'ESP0001,ESP0002,ESP0003';

    /*
     * Insert the combined record into the existing logs
     * table. The id column is omitted because it is
     * AUTO_INCREMENT.
     */
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

    /* Mark the cycle complete in the same transaction. */
    $stmt = $conn->prepare(
        'UPDATE sensor_cycles
         SET completed = 1
         WHERE cycle_id = ?'
    );

    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

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
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // Preserve the original error.
    }

    error_log(
        'receive_sensor.php failed: ' . $e->getMessage()
    );

    respond(500, [
        'ok' => false,
        'message' => 'Server could not process the reading'
    ]);
}