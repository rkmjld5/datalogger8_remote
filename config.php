
<?php
/*
 * Data Logger Remote Configuration
 * Project: datalogger8_remote
 * Platform: Render
 * Database: TiDB Cloud
 * Database name: espdemo2
 *
 * No session is started.
 * No output is generated on successful initialization.
 * Database credentials must be configured in Render Environment.
 */

date_default_timezone_set('UTC');

/* Load optional local .env file.
   Render environment variables take precedence. */

$envFile = __DIR__ . '/.env';

if (is_file($envFile) && is_readable($envFile)) {
    $envLines = file(
        $envFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($envLines !== false) {
        foreach ($envLines as $envLine) {
            $envLine = trim($envLine);

            if (
                $envLine === '' ||
                str_starts_with($envLine, '#') ||
                !str_contains($envLine, '=')
            ) {
                continue;
            }

            [$envKey, $envValue] = explode('=', $envLine, 2);

            $envKey = trim($envKey);
            $envValue = trim($envValue);

            if (strlen($envValue) >= 2) {
                $first = $envValue[0];
                $last = $envValue[strlen($envValue) - 1];

                if (
                    ($first === '"' && $last === '"') ||
                    ($first === "'" && $last === "'")
                ) {
                    $envValue = substr($envValue, 1, -1);
                }
            }

            if (
                $envKey !== '' &&
                getenv($envKey) === false
            ) {
                putenv($envKey . '=' . $envValue);
                $_ENV[$envKey] = $envValue;
            }
        }
    }
}

/* Environment variable helper */

if (!function_exists('dataLoggerEnv')) {
    function dataLoggerEnv(
        string $key,
        string $default = ''
    ): string {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return trim((string)$value);
    }
}

/* MySQLi error reporting */

mysqli_report(
    MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
);

/* TiDB Cloud configuration */

$servername = dataLoggerEnv('DB_HOST');
$dbUsername = dataLoggerEnv('DB_USER');
$dbPassword = dataLoggerEnv('DB_PASSWORD');
$dbname = dataLoggerEnv('DB_NAME', 'espdemo2');
$dbport = (int)dataLoggerEnv('DB_PORT', '4000');

$dbSslCa = dataLoggerEnv(
    'DB_SSL_CA',
    '/etc/ssl/certs/ca-certificates.crt'
);

/* Validate configuration */

if (
    $servername === '' ||
    $dbUsername === '' ||
    $dbPassword === '' ||
    $dbname === '' ||
    $dbport < 1 ||
    $dbport > 65535
) {
    error_log(
        'datalogger8: Required database environment variables are missing.'
    );

    http_response_code(500);

    exit(
        'Database configuration error. ' .
        'Check Render Environment settings.'
    );
}

/* Connect to database */

try {
    $conn = mysqli_init();

    if (!$conn) {
        throw new RuntimeException(
            'Unable to initialize MySQL connection.'
        );
    }

    $conn->options(
        MYSQLI_OPT_CONNECT_TIMEOUT,
        15
    );

    $isRemoteDatabase = !in_array(
        strtolower($servername),
        ['localhost', '127.0.0.1', '::1'],
        true
    );

    if ($isRemoteDatabase) {
        $caFile = (
            $dbSslCa !== '' &&
            is_readable($dbSslCa)
        ) ? $dbSslCa : null;

        $conn->ssl_set(
            null,
            null,
            $caFile,
            null,
            null
        );

        $conn->real_connect(
            $servername,
            $dbUsername,
            $dbPassword,
            $dbname,
            $dbport,
            null,
            MYSQLI_CLIENT_SSL
        );
    } else {
        $conn->real_connect(
            $servername,
            $dbUsername,
            $dbPassword,
            $dbname,
            $dbport
        );
    }

    $conn->set_charset('utf8mb4');

} catch (Throwable $e) {
    error_log(
        'datalogger8 database connection failed: ' .
        $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Database connection failed. ' .
        'Check Render Environment settings, ' .
        'TiDB credentials and TLS configuration.'
    );
}

/* HTML escaping helper */

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/*
 * Do not start a session.
 * Do not output HTML here.
 * Do not add a closing PHP tag.
 */
