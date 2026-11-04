
<?php
declare(strict_types=1);

/*
 * DATALOGGER8 REMOTE - STATUS API
 *
 * Database: espdemo2
 * Tables:
 *   sensor_pending8
 *   sensor_cycles8
 *   logs
 *
 * Authentication:
 *   Render environment variable: ESP_API_TOKEN
 *   HTTP header: X-API-Token
 *
 * Request:
 *   GET /status.php
 *
 * This file only reads database records.
 * It does not insert, update or delete records.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function statusJson(int $httpCode, array $data): void
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/* ---------------------------------------------------------
   1. Allow GET requests only
   --------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    statusJson(405, [
        'ok' => false,
        'message' => 'GET requests only'
    ]);
}

/* ---------------------------------------------------------
   2. Load database configuration
   --------------------------------------------------------- */

require_once __DIR__ . '/config.php';

/* ---------------------------------------------------------
   3. Verify API token
   --------------------------------------------------------- */

$serverToken = getenv('ESP_API_TOKEN');

if ($serverToken === false || trim($serverToken) === '') {
    statusJson(500, [
        'ok' => false,
        'message' => 'ESP_API_TOKEN is not configured on server'
    ]);
}

$receivedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

if (
    $receivedToken === '' ||
    !hash_equals(trim($serverToken), trim($receivedToken))
) {
    statusJson(401, [
        'ok' => false,
        'message' => 'Invalid or missing API token'
    ]);
}

/* ---------------------------------------------------------
   4. Check database connection
   --------------------------------------------------------- */

if (!isset($conn) || !($conn instanceof mysqli)) {
    statusJson(500, [
        'ok' => false,
        'message' => 'Database connection unavailable'
    ]);
}

/* ---------------------------------------------------------
   5. Read status information
   --------------------------------------------------------- */

try {

    $conn->set_charset('utf8mb4');

    // Make TIMESTAMP displays consistent with UTC.
    $conn->query("SET time_zone = '+00:00'");

    $status = [];

    /* -----------------------------------------------------
       A. Database information
       ----------------------------------------------------- */

    $status['database'] = [
        'name' => 'espdemo2',
        'connection' => 'connected'
    ];

    /* -----------------------------------------------------
       B. Overall pending reading count
       ----------------------------------------------------- */

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM sensor_pending8"
    );

    $row = $result->fetch_assoc();

    $status['summary']['total_pending_readings'] =
        (int)$row['total'];

    $result->free();

    /* -----------------------------------------------------
       C. Completed and incomplete cycles
       ----------------------------------------------------- */

    $result = $conn->query(
        "SELECT
            COUNT(*) AS total_cycles,
            COALESCE(SUM(completed = 1), 0) AS completed_cycles,
            COALESCE(SUM(completed = 0), 0) AS incomplete_cycles
         FROM sensor_cycles8"
    );

    $row = $result->fetch_assoc();

    $status['summary']['total_cycles'] =
        (int)$row['total_cycles'];

    $status['summary']['completed_cycles'] =
        (int)$row['completed_cycles'];

    $status['summary']['incomplete_cycles'] =
        (int)$row['incomplete_cycles'];

    $result->free();

    /* -----------------------------------------------------
       D. Total records in logs
       ----------------------------------------------------- */

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM logs"
    );

    $row = $result->fetch_assoc();

    $status['summary']['total_logs_records'] =
        (int)$row['total'];

    $result->free();

    /* -----------------------------------------------------
       E. Latest reading from each controller
       ----------------------------------------------------- */

    $latestReadings = [
        'ESP0001' => null,
        'ESP0002' => null,
        'ESP0003' => null
    ];

    $result = $conn->query(
        "SELECT
            cycle_id,
            device_id,
            sensor_value,
            sample_epoch,
            timezone,
            received_at
         FROM sensor_pending8
         ORDER BY received_at DESC, cycle_id DESC
         LIMIT 1000"
    );

    while ($row = $result->fetch_assoc()) {

        $device = $row['device_id'];

        // First row encountered for each device is its latest.
        if (
            array_key_exists($device, $latestReadings) &&
            $latestReadings[$device] === null
        ) {
            $latestReadings[$device] = [
                'cycle_id' => (string)$row['cycle_id'],
                'device_id' => $device,
                'sensor_value' => $row['sensor_value'],
                'sample_epoch' => (string)$row['sample_epoch'],
                'timezone' => $row['timezone'],
                'received_at_utc' => $row['received_at']
            ];
        }
    }

    $result->free();

    $status['latest_readings_by_device'] = $latestReadings;

    /* -----------------------------------------------------
       F. Latest 20 cycles and their received controllers
       ----------------------------------------------------- */

    $recentCycles = [];

    $result = $conn->query(
        "SELECT
            c.cycle_id,
            c.completed,
            c.created_at,
            COUNT(p.device_id) AS reading_count,
            GROUP_CONCAT(
                p.device_id
                ORDER BY p.device_id
                SEPARATOR ','
            ) AS devices_received
         FROM sensor_cycles8 AS c
         LEFT JOIN sensor_pending8 AS p
             ON p.cycle_id = c.cycle_id
         GROUP BY
            c.cycle_id,
            c.completed,
            c.created_at
         ORDER BY c.cycle_id DESC
         LIMIT 20"
    );

    while ($row = $result->fetch_assoc()) {

        $recentCycles[] = [
            'cycle_id' => (string)$row['cycle_id'],
            'completed' => (int)$row['completed'],
            'reading_count' => (int)$row['reading_count'],
            'devices_received' => $row['devices_received'] ?? '',
            'created_at_utc' => $row['created_at']
        ];
    }

    $result->free();

    $status['recent_cycles'] = $recentCycles;

    /* -----------------------------------------------------
       G. Latest 20 combined records in logs
       ----------------------------------------------------- */

    $recentLogs = [];

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

    while ($row = $result->fetch_assoc()) {

        $recentLogs[] = [
            'id' => (string)$row['id'],
            'value1' => $row['value1'],
            'value2' => $row['value2'],
            'value3' => $row['value3'],
            'date' => $row['Date'],
            'time' => $row['Time'],
            'timestamp_utc' => $row['TimeStamp'],
            'timezone' => $row['timezone'],
            'device_id' => $row['device_id']
        ];
    }

    $result->free();

    $status['latest_logs_records'] = $recentLogs;

    /* -----------------------------------------------------
       H. Server time
       ----------------------------------------------------- */

    $status['server_time_utc'] = gmdate('Y-m-d H:i:s');

    /* -----------------------------------------------------
       I. Final response
       ----------------------------------------------------- */

    statusJson(200, [
        'ok' => true,
        'message' => 'Datalogger8 status retrieved successfully',
        'status' => $status
    ]);

} catch (Throwable $e) {

    error_log(
        'datalogger8 status.php ERROR: ' .
        $e->getMessage()
    );

    statusJson(500, [
        'ok' => false,
        'message' => 'Unable to retrieve datalogger status. Check Render logs.'
    ]);
}
