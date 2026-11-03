
<?php
// status.php
// Status page for the NEW datalogger6_remote project.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

try {
    // Check database connection.
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection is not available.');
    }

    $conn->query("SELECT 1");

    // Check required tables.
    $requiredTables = [
        'logs',
        'sensor_pending',
        'sensor_cycles'
    ];

    $tableStatus = [];

    foreach ($requiredTables as $table) {
        $safeTable = $conn->real_escape_string($table);

        $result = $conn->query(
            "SHOW TABLES LIKE '$safeTable'"
        );

        $tableStatus[$table] = ($result && $result->num_rows > 0);
    }

    // Check API token configuration without displaying its value.
    $tokenConfigured =
        (getenv('ESP_API_TOKEN') !== false &&
         trim((string)getenv('ESP_API_TOKEN')) !== '');

    echo json_encode([
        'status' => 'success',
        'project' => 'datalogger6_remote',
        'database_connection' => 'OK',
        'database_name' => dataLoggerEnv('DB_NAME', 'espdemo2'),
        'required_tables' => $tableStatus,
        'api_token_configured' => $tokenConfigured,
        'server_time_utc' => gmdate('Y-m-d H:i:s'),
        'message' => 'Status check completed.'
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'project' => 'datalogger6_remote',
        'message' => 'Status check failed.',
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
