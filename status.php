
<?php
declare(strict_types=1);

/*
 * Project: datalogger8_remote
 * File: status.php
 *
 * Read-only status and diagnostic page.
 * No API token is required to view this page.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            'Database connection is not available.'
        );
    }

    $conn->query("SET time_zone = '+00:00'");
    $conn->query("SELECT 1");

    /* Check required tables */

    $requiredTables = [
        'logs',
        'sensor_pending8',
        'sensor_cycles8'
    ];

    $tableStatus = [];

    foreach ($requiredTables as $table) {
        $safeTable = $conn->real_escape_string($table);

        $result = $conn->query(
            "SHOW TABLES LIKE '$safeTable'"
        );

        $tableStatus[$table] = (
            $result && $result->num_rows > 0
        );
    }

    foreach ($tableStatus as $table => $exists) {
        if (!$exists) {
            throw new RuntimeException(
                'Required table is missing: ' . $table
            );
        }
    }

    /* API token configured? Do not display its value. */

    $token = trim((string)getenv('ESP_API_TOKEN'));

    $tokenConfigured = ($token !== '');

    /* Total pending readings */

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM sensor_pending8"
    );

    $pendingCount = (int)$result->fetch_assoc()['total'];

    /* Cycle statistics */

    $result = $conn->query(
        "SELECT
            COUNT(*) AS total_cycles,
            COALESCE(SUM(completed = 1), 0) AS completed_cycles,
            COALESCE(SUM(completed = 0), 0) AS incomplete_cycles
         FROM sensor_cycles8"
    );

    $cycleStats = $result->fetch_assoc();

    /* Logs count */

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM logs"
    );

    $logsCount = (int)$result->fetch_assoc()['total'];

    /* Latest reading from each controller */

    $result = $conn->query(
        "SELECT
            device_id,
            cycle_id,
            sensor_value,
            sample_epoch,
            timezone,
            received_at
         FROM sensor_pending8
         ORDER BY received_at DESC, cycle_id DESC
         LIMIT 1000"
    );

    $latestByDevice = [];

    while ($row = $result->fetch_assoc()) {
        $id = $row['device_id'];

        if (!isset($latestByDevice[$id])) {
            $latestByDevice[$id] = $row;
        }
    }

    /* Latest 20 collection cycles */

    $result = $conn->query(
        "SELECT
            c.cycle_id,
            c.completed,
            c.created_at,
            COUNT(p.device_id) AS reading_count,
            GROUP_CONCAT(
                p.device_id
                ORDER BY p.device_id
            ) AS devices_received
         FROM sensor_cycles8 c
         LEFT JOIN sensor_pending8 p
            ON p.cycle_id = c.cycle_id
         GROUP BY
            c.cycle_id,
            c.completed,
            c.created_at
         ORDER BY c.cycle_id DESC
         LIMIT 20"
    );

    $recentCycles = [];

    while ($row = $result->fetch_assoc()) {
        $row['completed'] = (int)$row['completed'];
        $row['reading_count'] = (int)$row['reading_count'];

        $recentCycles[] = $row;
    }

    /* Latest 20 combined logs */

    $result = $conn->query(
        "SELECT
            id,
            value1,
            value2,
            value3,
            `Date`,
            `Time`,
            `TimeStamp`,
            timezone,
            device_id
         FROM logs
         ORDER BY id DESC
         LIMIT 20"
    );

    $recentLogs = [];

    while ($row = $result->fetch_assoc()) {
        $recentLogs[] = $row;
    }

    /* Server information */

    echo json_encode([
        'status' => 'success',
        'project' => 'datalogger8_remote',
        'database_connection' => 'OK',
        'database_name' => dataLoggerEnv(
            'DB_NAME',
            'espdemo2'
        ),
        'required_tables' => $tableStatus,
        'api_token_configured' => $tokenConfigured,
        'server_time_utc' => gmdate('Y-m-d H:i:s'),

        'summary' => [
            'pending_readings' => $pendingCount,
            'total_cycles' => (int)$cycleStats['total_cycles'],
            'completed_cycles' => (int)$cycleStats['completed_cycles'],
            'incomplete_cycles' => (int)$cycleStats['incomplete_cycles'],
            'total_logs_records' => $logsCount
        ],

        'latest_readings_by_device' => $latestByDevice,
        'recent_cycles' => $recentCycles,
        'recent_logs' => $recentLogs,

        'message' => 'Read-only status check completed.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);

    error_log(
        'datalogger8 status.php failed: ' .
        $e->getMessage()
    );

    echo json_encode([
        'status' => 'error',
        'project' => 'datalogger8_remote',
        'message' => 'Status check failed.',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
