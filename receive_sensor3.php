<?php
/*
  PROJECT: datalogger8_remote

  PURPOSE:
  Receive readings from ESP0001, ESP0002 and ESP0003.
  Store them in sensor_pending3.
  When all three readings arrive, insert ONE combined row
  into logs and mark sensor_cycles3 as completed.

  Database: espdemo2

  Tables:
    sensor_cycles3
    sensor_pending3
    logs

  Does NOT use:
    sensor_cycles8
    sensor_pending8
*/

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function sendResponse($httpCode, $data)
{
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

$inTransaction = false;

try {

    // -------------------------------------------------
    // 1. CHECK HTTP METHOD
    // -------------------------------------------------

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(405, [
            'ok' => false,
            'message' => 'POST requests only'
        ]);
    }

    // -------------------------------------------------
    // 2. CHECK API TOKEN
    // -------------------------------------------------

    $expectedToken = dataLoggerEnv('ESP_API_TOKEN');

    $receivedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

    if (
        $expectedToken === '' ||
        !hash_equals($expectedToken, $receivedToken)
    ) {
        sendResponse(401, [
            'ok' => false,
            'message' => 'Invalid API token'
        ]);
    }

    // -------------------------------------------------
    // 3. READ JSON REQUEST
    // -------------------------------------------------

    $rawInput = file_get_contents('php://input');

    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid JSON'
        ]);
    }

    $cycleId = trim((string)($data['cycle_id'] ?? ''));
    $deviceId = trim((string)($data['device_id'] ?? ''));
    $sensorValue = trim((string)($data['sensor_value'] ?? ''));
    $sampleEpoch = trim((string)($data['sample_epoch'] ?? ''));
    $timezone = trim((string)($data['timezone'] ?? ''));

    // -------------------------------------------------
    // 4. VALIDATE INPUT
    // -------------------------------------------------

    if (!preg_match('/^[0-9]{1,19}$/', $cycleId)) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid cycle_id'
        ]);
    }

    $allowedDevices = [
        'ESP0001',
        'ESP0002',
        'ESP0003'
    ];

    if (!in_array($deviceId, $allowedDevices, true)) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid device_id'
        ]);
    }

    if ($sensorValue === '' || strlen($sensorValue) > 50) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid sensor_value'
        ]);
    }

    if (!preg_match('/^[0-9]{1,12}$/', $sampleEpoch)) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid sample_epoch'
        ]);
    }

    if ((int)$sampleEpoch <= 0) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Time not synchronized on controller'
        ]);
    }

    if ($timezone === '' || strlen($timezone) > 64) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid timezone'
        ]);
    }

    // Validate timezone name.
    try {
        new DateTimeZone($timezone);
    } catch (Exception $e) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid timezone name'
        ]);
    }

    // -------------------------------------------------
    // 5. START DATABASE TRANSACTION
    // -------------------------------------------------

    $conn->begin_transaction();
    $inTransaction = true;

    // -------------------------------------------------
    // 6. LOCK CYCLE AND CHECK AGE
    //
    // CURRENT_TIMESTAMP and created_at use the same
    // database session timezone.
    //
    // This avoids comparing PHP server time with
    // database time.
    // -------------------------------------------------

    $stmt = $conn->prepare("
        SELECT
            completed,
            created_at,
            TIMESTAMPDIFF(
                SECOND,
                created_at,
                CURRENT_TIMESTAMP
            ) AS age_seconds
        FROM sensor_cycles3
        WHERE cycle_id = ?
        FOR UPDATE
    ");

    $stmt->bind_param("s", $cycleId);
    $stmt->execute();

    $cycle = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$cycle) {

        $conn->rollback();
        $inTransaction = false;

        sendResponse(404, [
            'ok' => false,
            'message' => 'Cycle not found'
        ]);
    }

    $completed = (int)$cycle['completed'];
    $ageSeconds = (int)$cycle['age_seconds'];

    // -------------------------------------------------
    // 7. ALREADY COMPLETED?
    // -------------------------------------------------

    if ($completed === 1) {

        $conn->commit();
        $inTransaction = false;

        sendResponse(200, [
            'ok' => true,
            'completed' => true,
            'cycle_id' => $cycleId,
            'message' => 'Cycle already completed'
        ]);
    }

    // -------------------------------------------------
    // 8. CHECK 120-SECOND EXPIRY
    //
    // If a cycle was marked expired incorrectly but
    // database age is still within 120 seconds,
    // reopen it.
    // -------------------------------------------------

    if ($ageSeconds < 0) {
        $ageSeconds = 0;
    }

    if ($ageSeconds > 120) {

        $stmt = $conn->prepare("
            UPDATE sensor_cycles3
            SET completed = 2
            WHERE cycle_id = ?
              AND completed <> 1
        ");

        $stmt->bind_param("s", $cycleId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $inTransaction = false;

        sendResponse(410, [
            'ok' => false,
            'completed' => false,
            'expired' => true,
            'cycle_id' => $cycleId,
            'age_seconds' => $ageSeconds,
            'message' => 'Cycle expired'
        ]);
    }

    // If it was marked expired but is still within
    // the valid time window, reopen it.
    if ($completed === 2) {

        $stmt = $conn->prepare("
            UPDATE sensor_cycles3
            SET completed = 0
            WHERE cycle_id = ?
        ");

        $stmt->bind_param("s", $cycleId);
        $stmt->execute();
        $stmt->close();
    }

    // -------------------------------------------------
    // 9. STORE / UPDATE THIS DEVICE READING
    // -------------------------------------------------

    $stmt = $conn->prepare("
        INSERT INTO sensor_pending3
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
            timezone = VALUES(timezone)
    ");

    $stmt->bind_param(
        "sssss",
        $cycleId,
        $deviceId,
        $sensorValue,
        $sampleEpoch,
        $timezone
    );

    $stmt->execute();
    $stmt->close();

    // -------------------------------------------------
    // 10. COUNT RECEIVED READINGS
    // -------------------------------------------------

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM sensor_pending3
        WHERE cycle_id = ?
    ");

    $stmt->bind_param("s", $cycleId);
    $stmt->execute();

    $countResult = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    $totalReadings = (int)$countResult['total'];

    // -------------------------------------------------
    // 11. WAIT IF FEWER THAN THREE READINGS
    // -------------------------------------------------

    if ($totalReadings < 3) {

        $conn->commit();
        $inTransaction = false;

        sendResponse(200, [
            'ok' => true,
            'completed' => false,
            'cycle_id' => $cycleId,
            'received' => $totalReadings,
            'required' => 3,
            'message' => 'Reading stored. Waiting for other controllers.'
        ]);
    }

    // -------------------------------------------------
    // 12. FETCH ALL THREE READINGS
    // -------------------------------------------------

    $stmt = $conn->prepare("
        SELECT
            device_id,
            sensor_value,
            sample_epoch,
            timezone
        FROM sensor_pending3
        WHERE cycle_id = ?
    ");

    $stmt->bind_param("s", $cycleId);
    $stmt->execute();

    $result = $stmt->get_result();

    $readings = [];

    while ($row = $result->fetch_assoc()) {
        $readings[$row['device_id']] = $row;
    }

    $stmt->close();

    // -------------------------------------------------
    // 13. VERIFY ALL THREE DEVICE IDS
    // -------------------------------------------------

    foreach ($allowedDevices as $requiredDevice) {

        if (!isset($readings[$requiredDevice])) {

            $conn->commit();
            $inTransaction = false;

            sendResponse(200, [
                'ok' => true,
                'completed' => false,
                'cycle_id' => $cycleId,
                'received' => count($readings),
                'required' => 3,
                'message' => 'Waiting for missing controller'
            ]);
        }
    }

    // -------------------------------------------------
    // 14. ASSIGN VALUES IN CORRECT ORDER
    // -------------------------------------------------

    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    // Master ESP0001 provides the date/time reference.
    $masterEpoch = (int)$readings['ESP0001']['sample_epoch'];
    $masterTimezone = $readings['ESP0001']['timezone'];

    // -------------------------------------------------
    // 15. CONVERT MASTER EPOCH TO LOCAL DATE AND TIME
    // -------------------------------------------------

    try {

        $dateTimeZone = new DateTimeZone($masterTimezone);

        $sampleDateTime = (
            new DateTimeImmutable('@' . $masterEpoch)
        )->setTimezone($dateTimeZone);

        $logDate = $sampleDateTime->format('Y-m-d');
        $logTime = $sampleDateTime->format('H:i:s');

    } catch (Exception $e) {

        throw new Exception('Unable to convert master sample time');
    }

    // -------------------------------------------------
    // 16. INSERT ONE COMBINED ROW INTO logs
    // -------------------------------------------------

    $logDeviceId = 'ESP0001,ESP0002,ESP0003';

    $stmt = $conn->prepare("
        INSERT INTO logs
        (
            value1,
            value2,
            value3,
            `Date`,
            `Time`,
            timezone,
            device_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "sssssss",
        $value1,
        $value2,
        $value3,
        $logDate,
        $logTime,
        $masterTimezone,
        $logDeviceId
    );

    $stmt->execute();

    $logId = $conn->insert_id;

    $stmt->close();

    // -------------------------------------------------
    // 17. MARK CYCLE COMPLETED
    // -------------------------------------------------

    $stmt = $conn->prepare("
        UPDATE sensor_cycles3
        SET completed = 1
        WHERE cycle_id = ?
    ");

    $stmt->bind_param("s", $cycleId);
    $stmt->execute();
    $stmt->close();

    // -------------------------------------------------
    // 18. COMMIT BOTH OPERATIONS TOGETHER
    //
    // If the log insert or cycle update fails,
    // the transaction rolls back.
    // -------------------------------------------------

    $conn->commit();
    $inTransaction = false;

    // -------------------------------------------------
    // 19. SUCCESS RESPONSE
    // -------------------------------------------------

    sendResponse(200, [
        'ok' => true,
        'completed' => true,
        'cycle_id' => $cycleId,
        'log_id' => $logId,
        'value1' => $value1,
        'value2' => $value2,
        'value3' => $value3,
        'received' => 3,
        'required' => 3,
        'message' => 'All three readings stored. Combined logs row inserted.'
    ]);

}
catch (Throwable $e) {

    if ($inTransaction) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('Rollback error: ' . $rollbackError->getMessage());
        }
    }

    error_log(
        'receive_sensor3.php error: ' . $e->getMessage()
    );

    sendResponse(500, [
        'ok' => false,
        'message' => 'Server database error. Check Render logs.'
    ]);
}
