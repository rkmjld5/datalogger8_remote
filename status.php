<?php
/*
    PROJECT: datalogger8_remote
    FILE: status.php

    PURPOSE:
    Read-only monitoring page for the three ESP8266 controllers.

    ACTIVE TABLES:
      1. logs
      2. sensor_pending3
      3. sensor_cycles3

    ESP CONTROLLERS:
      ESP0001
      ESP0002
      ESP0003

    This file does not modify any database records.
*/

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* Return JSON response */
function reply($data, $code = 200)
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/* Check whether a table exists */
function tableExists($conn, $table)
{
    $allowedTables = [
        'logs',
        'sensor_pending3',
        'sensor_cycles3'
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");

    $stmt->bind_param('s', $table);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return ((int)$row['total'] > 0);
}

try {

    /* Check database connection */
    if (!isset($conn) || !$conn instanceof mysqli) {
        reply([
            'ok' => false,
            'message' => 'Database connection not available'
        ], 500);
    }

    if ($conn->connect_error) {
        reply([
            'ok' => false,
            'message' => 'Database connection failed'
        ], 500);
    }

    /* Check API token configuration */
    $apiToken = function_exists('dataLoggerEnv')
        ? dataLoggerEnv('ESP_API_TOKEN')
        : '';

    $tokenConfigured = ($apiToken !== '');

    /* Check required tables */
    $requiredTables = [
        'logs',
        'sensor_pending3',
        'sensor_cycles3'
    ];

    $tableStatus = [];

    foreach ($requiredTables as $table) {
        $tableStatus[$table] = tableExists($conn, $table);
    }

    $missingTables = [];

    foreach ($tableStatus as $table => $exists) {
        if (!$exists) {
            $missingTables[] = $table;
        }
    }

    if (!empty($missingTables)) {
        reply([
            'ok' => false,
            'project' => 'datalogger8_remote',
            'message' => 'One or more required tables are missing',
            'required_tables' => $tableStatus,
            'missing_tables' => $missingTables
        ], 500);
    }

    /* Server time */
    $serverUtc = gmdate('Y-m-d H:i:s');

    $indiaTime = new DateTime(
        'now',
        new DateTimeZone('Asia/Kolkata')
    );

    /* -----------------------------------------
       SUMMARY COUNTS
    ----------------------------------------- */

    /* Total cycles */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM sensor_cycles3
    ");

    $totalCycles = (int)$result->fetch_assoc()['total'];

    /* Completed cycles */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM sensor_cycles3
        WHERE completed = 1
    ");

    $completedCycles = (int)$result->fetch_assoc()['total'];

    /* Incomplete cycles */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM sensor_cycles3
        WHERE completed = 0
    ");

    $incompleteCycles = (int)$result->fetch_assoc()['total'];

    /* Expired cycles */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM sensor_cycles3
        WHERE completed = 2
    ");

    $expiredCycles = (int)$result->fetch_assoc()['total'];

    /* Pending readings */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM sensor_pending3
    ");

    $pendingReadings = (int)$result->fetch_assoc()['total'];

    /* Total records in final logs table */
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM logs
    ");

    $totalLogs = (int)$result->fetch_assoc()['total'];

    /* -----------------------------------------
       LATEST READINGS BY DEVICE
    ----------------------------------------- */

    $latestReadings = [];

    $result = $conn->query("
        SELECT
            device_id,
            cycle_id,
            sensor_value,
            sample_epoch,
            timezone,
            received_at
        FROM sensor_pending3
        ORDER BY received_at DESC
        LIMIT 300
    ");

    while ($row = $result->fetch_assoc()) {
        $latestReadings[] = $row;
    }

    /* -----------------------------------------
       RECENT CYCLES
    ----------------------------------------- */

    $recentCycles = [];

    $result = $conn->query("
        SELECT
            c.cycle_id,
            c.completed,
            c.created_at,
            COUNT(p.device_id) AS readings_received,
            GROUP_CONCAT(
                p.device_id
                ORDER BY p.device_id
            ) AS devices_received
        FROM sensor_cycles3 c
        LEFT JOIN sensor_pending3 p
            ON c.cycle_id = p.cycle_id
        GROUP BY
            c.cycle_id,
            c.completed,
            c.created_at
        ORDER BY c.created_at DESC
        LIMIT 20
    ");

    while ($row = $result->fetch_assoc()) {

        $row['completed'] = (int)$row['completed'];
        $row['readings_received'] = (int)$row['readings_received'];

        $recentCycles[] = $row;
    }

    /* -----------------------------------------
       RECENT FINAL LOG RECORDS
    ----------------------------------------- */

    $recentLogs = [];

    $result = $conn->query("
        SELECT
            id,
            value1,
            value2,
            value3,
            Date,
            Time,
            TimeStamp,
            timezone,
            device_id
        FROM logs
        ORDER BY id DESC
        LIMIT 20
    ");

    while ($row = $result->fetch_assoc()) {
        $recentLogs[] = $row;
    }

    /* -----------------------------------------
       FINAL JSON RESPONSE
    ----------------------------------------- */

    reply([

        'ok' => true,

        'project' => 'datalogger8_remote',

        'message' => 'Three-controller data logger status',

        'database' => 'espdemo2',

        'api_token_configured' => $tokenConfigured,

        'required_tables' => $tableStatus,

        'server_time_utc' => $serverUtc,

        'server_time_india' => $indiaTime->format('Y-m-d H:i:s'),

        'controllers' => [
            'ESP0001' => 'Master',
            'ESP0002' => 'Slave',
            'ESP0003' => 'Slave'
        ],

        'summary' => [
            'total_cycles' => $totalCycles,
            'completed_cycles' => $completedCycles,
            'incomplete_cycles' => $incompleteCycles,
            'expired_cycles' => $expiredCycles,
            'pending_readings' => $pendingReadings,
            'total_logs_records' => $totalLogs
        ],

        'latest_readings_by_device' => $latestReadings,

        'recent_cycles' => $recentCycles,

        'recent_logs' => $recentLogs

    ]);

} catch (Throwable $e) {

    error_log(
        'datalogger8 status.php error: ' . $e->getMessage()
    );

    reply([
        'ok' => false,
        'project' => 'datalogger8_remote',
        'message' => 'Status query failed. Check Render logs.'
    ], 500);
}
