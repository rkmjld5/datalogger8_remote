<?php
/*
  PROJECT: datalogger8_remote

  PURPOSE:
  Receive readings from ESP0001, ESP0002 and ESP0003.
  Store readings in sensor_pending3.
  Insert ONE combined row into logs when all three readings
  are available.

  IMPORTANT:
  - A cycle marked completed=2 can still be recovered.
  - All three readings are processed regardless of cycle age.
  - Older complete pending cycles are also checked for recovery.
  - Each cycle is protected by a database transaction and row lock.

  Tables used:
    sensor_cycles3
    sensor_pending3
    logs

  Does NOT modify:
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

/*
  Insert one combined logs row and mark its cycle completed.

  IMPORTANT:
  This function must be called inside an active transaction
  after the corresponding sensor_cycles3 row is locked.
*/
function finishCycleIfReady($conn, $cycleId, $ageSeconds)
{
    $stmt = $conn->prepare("
        SELECT device_id, sensor_value, sample_epoch, timezone
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

    $requiredDevices = [
        'ESP0001',
        'ESP0002',
        'ESP0003'
    ];

    // Check that all three controllers have submitted.
    foreach ($requiredDevices as $device) {

        if (!isset($readings[$device])) {

            // Expire incomplete cycles after 120 seconds.
            if ($ageSeconds > 120) {

                $stmt = $conn->prepare("
                    UPDATE sensor_cycles3
                    SET completed = 2
                    WHERE cycle_id = ?
                      AND completed = 0
                ");

                $stmt->bind_param("s", $cycleId);
                $stmt->execute();
                $stmt->close();
            }

            return [
                'completed' => false,
                'received' => count($readings),
                'required' => 3,
                'message' => 'Waiting for all three controllers'
            ];
        }
    }

    // All three readings are available.
    $value1 = $readings['ESP0001']['sensor_value'];
    $value2 = $readings['ESP0002']['sensor_value'];
    $value3 = $readings['ESP0003']['sensor_value'];

    // ESP0001 provides the date/time reference.
    $masterEpoch = (int)$readings['ESP0001']['sample_epoch'];
    $masterTimezone = $readings['ESP0001']['timezone'];

    if ($masterEpoch <= 0) {
        throw new Exception('Invalid ESP0001 sample epoch');
    }

    try {

        $dateTimeZone = new DateTimeZone($masterTimezone);

        $sampleDateTime = (
            new DateTimeImmutable('@' . $masterEpoch)
        )->setTimezone($dateTimeZone);

        $logDate = $sampleDateTime->format('Y-m-d');
        $logTime = $sampleDateTime->format('H:i:s');

    } catch (Throwable $e) {

        throw new Exception(
            'Unable to convert ESP0001 sample time: ' .
            $e->getMessage()
        );
    }

    // Combined device identification.
    $logDeviceId = 'ESP0001,ESP0002,ESP0003';

    // Insert exactly one combined row.
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

    // Mark completed only after successful INSERT.
    $stmt = $conn->prepare("
        UPDATE sensor_cycles3
        SET completed = 1
        WHERE cycle_id = ?
    ");

    $stmt->bind_param("s", $cycleId);
    $stmt->execute();
    $stmt->close();

    return [
        'completed' => true,
        'log_id' => $logId,
        'value1' => $value1,
        'value2' => $value2,
        'value3' => $value3,
        'received' => 3,
        'required' => 3,
        'message' => 'Combined logs record inserted successfully'
    ];
}


/*
  Recover older cycles which already have all three readings.

  This runs whenever a new reading is received.
  Each cycle is processed in its own transaction.
*/
function recoverOlderCycles($conn)
{
    $recovered = 0;

    try {

        $sql = "
            SELECT c.cycle_id
            FROM sensor_cycles3 c
            INNER JOIN sensor_pending3 p
                ON p.cycle_id = c.cycle_id
            WHERE c.completed IN (0, 2)
              AND p.device_id IN (
                  'ESP0001',
                  'ESP0002',
                  'ESP0003'
              )
            GROUP BY c.cycle_id
            HAVING COUNT(DISTINCT p.device_id) = 3
            ORDER BY c.cycle_id ASC
            LIMIT 100
        ";

        $result = $conn->query($sql);

        $cycleIds = [];

        while ($row = $result->fetch_assoc()) {
            $cycleIds[] = (string)$row['cycle_id'];
        }

        $result->free();

        foreach ($cycleIds as $oldCycleId) {

            try {

                $conn->begin_transaction();

                // Lock this cycle to prevent concurrent insertion.
                $stmt = $conn->prepare("
                    SELECT
                        completed,
                        TIMESTAMPDIFF(
                            SECOND,
                            created_at,
                            CURRENT_TIMESTAMP
                        ) AS age_seconds
                    FROM sensor_cycles3
                    WHERE cycle_id = ?
                    FOR UPDATE
                ");

                $stmt->bind_param("s", $oldCycleId);
                $stmt->execute();

                $cycle = $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if (!$cycle) {
                    $conn->rollback();
                    continue;
                }

                // Already completed: do not insert again.
                if ((int)$cycle['completed'] === 1) {
                    $conn->commit();
                    continue;
                }

                $ageSeconds = max(
                    0,
                    (int)$cycle['age_seconds']
                );

                $outcome = finishCycleIfReady(
                    $conn,
                    $oldCycleId,
                    $ageSeconds
                );

                $conn->commit();

                if ($outcome['completed']) {
                    $recovered++;
                    error_log(
                        'Recovered cycle ' . $oldCycleId .
                        ', log ID ' . $outcome['log_id']
                    );
                }

            } catch (Throwable $e) {

                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }

                error_log(
                    'Recovery error for cycle ' .
                    $oldCycleId . ': ' . $e->getMessage()
                );
            }
        }

    } catch (Throwable $e) {

        error_log(
            'recoverOlderCycles error: ' . $e->getMessage()
        );
    }

    return $recovered;
}


// =====================================================
// MAIN REQUEST
// =====================================================

$inTransaction = false;

try {

    // 1. POST only.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(405, [
            'ok' => false,
            'message' => 'POST requests only'
        ]);
    }

    // 2. API token authentication.
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

    // 3. Read JSON.
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

    // 4. Validate cycle ID.
    if (!preg_match('/^[0-9]{1,19}$/', $cycleId)) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid cycle_id'
        ]);
    }

    // 5. Validate controller.
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

    // 6. Validate sensor value.
    if ($sensorValue === '' || strlen($sensorValue) > 50) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid sensor_value'
        ]);
    }

    // 7. Validate epoch.
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

    // 8. Validate timezone.
    if ($timezone === '' || strlen($timezone) > 64) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid timezone'
        ]);
    }

    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        sendResponse(400, [
            'ok' => false,
            'message' => 'Invalid timezone name'
        ]);
    }

    // =================================================
    // 9. START TRANSACTION AND LOCK CYCLE
    // =================================================

    $conn->begin_transaction();
    $inTransaction = true;

    $stmt = $conn->prepare("
        SELECT
            completed,
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
    $ageSeconds = max(0, (int)$cycle['age_seconds']);

    // Already completed: never insert the same cycle again.
    if ($completed === 1) {

        $conn->commit();
        $inTransaction = false;

        $recovered = recoverOlderCycles($conn);

        sendResponse(200, [
            'ok' => true,
            'completed' => true,
            'cycle_id' => $cycleId,
            'message' => 'Cycle already completed',
            'older_cycles_recovered' => $recovered
        ]);
    }

    // =================================================
    // 10. STORE OR UPDATE THE READING
    // =================================================

    // IMPORTANT:
    // Do not reject a cycle just because completed=2.
    // Store the reading and check whether all three exist.

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

    // =================================================
    // 11. CHECK AND COMPLETE THE CYCLE
    // =================================================

    $outcome = finishCycleIfReady(
        $conn,
        $cycleId,
        $ageSeconds
    );

    // INSERT and completed=1 are committed together.
    $conn->commit();
    $inTransaction = false;

    // =================================================
    // 12. RECOVER OLDER COMPLETE CYCLES
    // =================================================

    $recovered = recoverOlderCycles($conn);

    // =================================================
    // 13. RESPONSE
    // =================================================

    sendResponse(200, [
        'ok' => true,
        'cycle_id' => $cycleId,
        'completed' => $outcome['completed'],
        'received' => $outcome['received'],
        'required' => $outcome['required'],
        'log_id' => $outcome['log_id'] ?? null,
        'value1' => $outcome['value1'] ?? null,
        'value2' => $outcome['value2'] ?? null,
        'value3' => $outcome['value3'] ?? null,
        'message' => $outcome['message'],
        'older_cycles_recovered' => $recovered
    ]);

} catch (Throwable $e) {

    if ($inTransaction) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                'Rollback error: ' .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        'receive_sensor3.php error: ' .
        $e->getMessage()
    );

    sendResponse(500, [
        'ok' => false,
        'message' => 'Server database error. Check Render logs.'
    ]);
}
