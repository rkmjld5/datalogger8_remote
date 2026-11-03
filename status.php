<?php
declare(strict_types=1);

/*
 * Project: datalogger8_remote
 * File: status.php
 *
 * Checks:
 * 1. Database connection.
 * 2. Three ESP controllers.
 * 3. Pending sensor readings.
 * 4. Completed collection cycles.
 * 5. Recent combined records in logs.
 *
 * Database: espdemo2
 * Tables:
 *   logs
 *   sensor_pending8
 *   sensor_cycles8
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('UTC');


/* ---------------------------------------------------------
   1. JSON RESPONSE FUNCTION
   --------------------------------------------------------- */

function statusResponse(int $httpCode, array $data): void
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* ---------------------------------------------------------
   2. ACCEPT GET REQUESTS ONLY
   --------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET');

    statusResponse(405, [
        'ok' => false,
        'message' => 'GET requests only'
    ]);
}


/* ---------------------------------------------------------
   3. LOAD DATABASE CONFIGURATION
   --------------------------------------------------------- */

require_once __DIR__ . '/config.php';


/* ---------------------------------------------------------
   4. OPTIONAL API TOKEN AUTHENTICATION
   --------------------------------------------------------- */

/*
 * If ESP_API_TOKEN is configured, callers must provide
 * the same token using the X-API-Token header.
 *
 * This protects status information from public access.
 */

$expectedToken = trim((string)getenv('ESP_API_TOKEN'));

if ($expectedToken !== '') {

    $providedToken = (string)(
        $_SERVER['HTTP_X_API_TOKEN'] ?? ''
    );

    if (
        $providedToken === '' ||
        !hash_equals($expectedToken, $providedToken)
    ) {

        statusResponse(401, [
            'ok' => false,
            'message' => 'Invalid or missing API token'
        ]);
    }
}


/* ---------------------------------------------------------
   5. CHECK DATABASE CONNECTION
   --------------------------------------------------------- */

try {

    $databaseResult = $conn->query(
        'SELECT DATABASE() AS database_name'
    );

    $databaseRow = $databaseResult->fetch_assoc();

    $databaseName = $databaseRow['database_name'] ?? '';

    if ($databaseName !== 'espdemo2') {

        throw new RuntimeException(
            'Connected database is not espdemo2'
        );
    }


    /* -----------------------------------------------------
       6. CHECK NEW PROJECT TABLES
       ----------------------------------------------------- */

    $conn->query(
        'SELECT cycle_id, completed
         FROM sensor_cycles8
         LIMIT 1'
    );

    $conn->query(
        'SELECT cycle_id, device_id, sensor_value
         FROM sensor_pending8
         LIMIT 1'
    );


    /* -----------------------------------------------------
       7. COUNT PENDING READINGS
       ----------------------------------------------------- */

    $pendingResult = $conn->query(
        'SELECT COUNT(*) AS total
         FROM sensor_pending8'
    );

    $pendingRow = $pendingResult->fetch_assoc();

    $pendingTotal = (int)(
        $pendingRow['total'] ?? 0
    );


    /* -----------------------------------------------------
       8. COUNT COMPLETED CYCLES
       ----------------------------------------------------- */

    $completedResult = $conn->query(
        'SELECT COUNT(*) AS total
         FROM sensor_cycles8
         WHERE completed = 1'
    );

    $completedRow = $completedResult->fetch_assoc();

    $completedTotal = (int)(
        $completedRow['total'] ?? 0
    );


    /* -----------------------------------------------------
       9. COUNT INCOMPLETE CYCLES
       ----------------------------------------------------- */

    $incompleteResult = $conn->query(
        'SELECT COUNT(*) AS total
         FROM sensor_cycles8
         WHERE completed = 0'
    );

    $incompleteRow = $incompleteResult->fetch_assoc();

    $incompleteTotal = (int)(
        $incompleteRow['total'] ?? 0
    );


    /* -----------------------------------------------------
       10. GET RECENT CONTROLLER READINGS
       ----------------------------------------------------- */

    $controllerResult = $conn->query(
        'SELECT
            device_id,
            COUNT(*) AS pending_readings,
            MAX(received_at) AS last_received
         FROM sensor_pending8
         GROUP BY device_id
         ORDER BY device_id'
    );

    $controllerActivity = [];

    while ($row = $controllerResult->fetch_assoc()) {

        $controllerActivity[] = [
            'device_id' => $row['device_id'],
            'pending_readings' => (int)$row['pending_readings'],
            'last_received_utc' => $row['last_received']
        ];
    }


    /* -----------------------------------------------------
       11. COUNT COMBINED LOG RECORDS
       ----------------------------------------------------- */

    $logsResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM logs
         WHERE device_id = 'ESP0001,ESP0002,ESP0003'"
    );

    $logsRow = $logsResult->fetch_assoc();

    $combinedLogTotal = (int)(
        $logsRow['total'] ?? 0
    );


    /* -----------------------------------------------------
       12. GET LAST COMBINED LOG RECORD
       ----------------------------------------------------- */

    $lastLogResult = $conn->query(
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
         WHERE device_id = 'ESP0001,ESP0002,ESP0003'
         ORDER BY id DESC
         LIMIT 1"
    );

    $lastLog = $lastLogResult->fetch_assoc();

    if ($lastLog !== null) {

        $lastLog['id'] = (int)$lastLog['id'];
    }


    /* -----------------------------------------------------
       13. RETURN STATUS
       ----------------------------------------------------- */

    statusResponse(200, [
        'ok' => true,

        'project' => 'datalogger8_remote',

        'server_utc_time' => gmdate(
            'Y-m-d H:i:s'
        ),

        'database' => [
            'name' => $databaseName,
            'connection' => 'connected'
        ],

        'tables' => [
            'logs' => 'available',
            'sensor_pending8' => 'available',
            'sensor_cycles8' => 'available'
        ],

        'controllers' => [
            'ESP0001' => [
                'logs_column' => 'value1'
            ],
            'ESP0002' => [
                'logs_column' => 'value2'
            ],
            'ESP0003' => [
                'logs_column' => 'value3'
            ]
        ],

        'cycle_status' => [
            'pending_readings' => $pendingTotal,
            'completed_cycles' => $completedTotal,
            'incomplete_cycles' => $incompleteTotal
        ],

        'controller_activity' => $controllerActivity,

        'combined_logs' => [
            'total_records' => $combinedLogTotal,
            'last_record' => $lastLog
        ]
    ]);


} catch (Throwable $e) {

    error_log(
        'datalogger8 status.php failed: ' .
        $e->getMessage()
    );

    statusResponse(500, [
        'ok' => false,
        'project' => 'datalogger8_remote',
        'message' => 'Status check failed',
        'details' => $e->getMessage()
    ]);
}
?>
