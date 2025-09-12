<?php
/**
 * Migration Runner for Schema Installation
 */

namespace ZZZSolutions\VectorSearch\Migrations;

class Runner {

    /**
     * Run database migration
     *
     * @return array ['ok' => bool, 'msg' => string, 'details' => array]
     */
    public static function run(): array {
        // Environment checks
        $env_check = self::checkEnvironment();
        if (!$env_check['ok']) {
            return $env_check;
        }

        // Load SQL schema
        $sql = self::loadSQL();
        if (!$sql) {
            return [
                'ok' => false,
                'msg' => 'Schema SQL file not found',
                'details' => ['Expected location: ' . self::getSQLFilePath()]
            ];
        }

        // Test connection first
        $conn_test = self::testConnection();
        if (!$conn_test['ok']) {
            return $conn_test;
        }

        // Execute migration
        $result = self::executePSQL($sql);

        // Update WordPress options on success
        if ($result['ok']) {
            update_option('aivesese_schema_installed', time());
            update_option('aivesese_schema_version', '2.0');
            update_option('aivesese_schema_install_method', 'migration-runner');
        }

        return $result;
    }

    /**
     * Check if migrations can run
     */
    public static function canRunMigrations(): array {
        return self::checkEnvironment();
    }

    /**
     * Get migration status information
     */
    public static function getStatus(): array {
        $env_check = self::checkEnvironment();
        $sql_exists = file_exists(self::getSQLFilePath());
        $conn_configured = !empty(self::getConnectionString());

        return [
            'can_run' => $env_check['ok'],
            'psql_available' => $env_check['psql_available'] ?? false,
            'connection_configured' => $conn_configured,
            'sql_file_exists' => $sql_exists,
            'sql_file_path' => self::getSQLFilePath(),
            'requirements' => [
                'psql_command' => $env_check['psql_available'] ?? false,
                'connection_string' => $conn_configured,
                'sql_file' => $sql_exists,
                'write_permissions' => is_writable(WP_CONTENT_DIR)
            ]
        ];
    }

    // ================================================
    // PRIVATE IMPLEMENTATION METHODS
    // ================================================

    /**
     * Check environment prerequisites
     */
    private static function checkEnvironment(): array {
        // Check if psql is available
        $psql_check = shell_exec('which psql 2>/dev/null');
        $psql_available = !empty(trim($psql_check));

        if (!$psql_available) {
            return [
                'ok' => false,
                'msg' => 'PostgreSQL client (psql) not available',
                'details' => [
                    'error' => 'psql command not found in PATH',
                    'solution' => 'Install PostgreSQL client tools on your server',
                    'ubuntu' => 'sudo apt-get install postgresql-client',
                    'centos' => 'sudo yum install postgresql',
                    'macos' => 'brew install postgresql'
                ],
                'psql_available' => false
            ];
        }

        // Check connection string
        $conn_string = self::getConnectionString();
        if (!$conn_string) {
            return [
                'ok' => false,
                'msg' => 'PostgreSQL connection string not configured',
                'details' => [
                    'error' => 'Missing aivesese_postgres_connection_string option',
                    'solution' => 'Configure PostgreSQL connection string in WordPress admin'
                ],
                'psql_available' => true
            ];
        }

        return [
            'ok' => true,
            'msg' => 'Environment ready for migrations',
            'psql_available' => true
        ];
    }

    /**
     * Load SQL schema file
     */
    private static function loadSQL(): ?string {
        $sql_file = self::getSQLFilePath();

        if (!file_exists($sql_file)) {
            return null;
        }

        $sql = file_get_contents($sql_file);

        // Validate SQL content
        if (empty($sql) || strpos($sql, 'CREATE') === false) {
            return null;
        }

        return $sql;
    }

    /**
     * Test PostgreSQL connection
     */
    private static function testConnection(): array {
        $conn_string = self::getConnectionString();

        $cmd = sprintf('psql "%s" -c "SELECT 1;" 2>/dev/null', escapeshellarg($conn_string));
        $desc = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];

        $proc = proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return [
                'ok' => false,
                'msg' => 'Failed to start psql process',
                'details' => ['error' => 'proc_open failed']
            ];
        }

        fclose($pipes[0]); // Close stdin
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($proc);

        if ($exit_code !== 0) {
            return [
                'ok' => false,
                'msg' => 'PostgreSQL connection failed',
                'details' => [
                    'exit_code' => $exit_code,
                    'stderr' => $stderr,
                    'stdout' => $stdout
                ]
            ];
        }

        return [
            'ok' => true,
            'msg' => 'PostgreSQL connection successful'
        ];
    }

    /**
     * Execute SQL via psql with proper error handling
     */
    private static function executePSQL(string $sql): array {
        $conn_string = self::getConnectionString();

        // Build psql command with options for better error reporting
        $cmd = sprintf(
            'psql "%s" -v "ON_ERROR_STOP=1" --single-transaction --quiet --no-align --tuples-only 2>&1',
            escapeshellarg($conn_string)
        );

        $desc = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];

        $proc = proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return [
                'ok' => false,
                'msg' => 'Failed to start psql process',
                'details' => ['error' => 'proc_open failed']
            ];
        }

        // Send SQL to stdin
        fwrite($pipes[0], $sql);
        fclose($pipes[0]);

        // Read outputs
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($proc);

        if ($exit_code !== 0) {
            return [
                'ok' => false,
                'msg' => 'Schema installation failed',
                'details' => [
                    'exit_code' => $exit_code,
                    'stderr' => self::parsePostgreSQLError($stderr),
                    'stdout' => $stdout,
                    'sql_length' => strlen($sql)
                ]
            ];
        }

        return [
            'ok' => true,
            'msg' => 'Schema installed successfully',
            'details' => [
                'stdout' => $stdout,
                'sql_executed' => strlen($sql) . ' characters',
                'execution_time' => 'completed'
            ]
        ];
    }

    /**
     * Parse PostgreSQL error messages for better user feedback
     */
    private static function parsePostgreSQLError(string $stderr): array {
        $lines = explode("\n", $stderr);
        $parsed = [
            'raw' => $stderr,
            'errors' => [],
            'warnings' => [],
            'suggestions' => []
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Common error patterns
            if (strpos($line, 'ERROR:') === 0) {
                $parsed['errors'][] = $line;

                // Add suggestions for common errors
                if (strpos($line, 'permission denied') !== false) {
                    $parsed['suggestions'][] = 'Check PostgreSQL user permissions';
                } elseif (strpos($line, 'already exists') !== false) {
                    $parsed['suggestions'][] = 'This might be safe to ignore - component already installed';
                } elseif (strpos($line, 'does not exist') !== false) {
                    $parsed['suggestions'][] = 'Missing dependency or extension';
                }
            } elseif (strpos($line, 'WARNING:') === 0 || strpos($line, 'NOTICE:') === 0) {
                $parsed['warnings'][] = $line;
            }
        }

        return $parsed;
    }

    /**
     * Get encrypted PostgreSQL connection string
     */
    private static function getConnectionString(): ?string {
        $encrypted = get_option('aivesese_postgres_connection_string');

        if (empty($encrypted)) {
            return null;
        }

        // Handle encrypted values
        if (is_string($encrypted) && strpos($encrypted, '{') === 0) {
            $encryption_manager = \AIVectorSearch_Encryption_Manager::instance();
            $decrypted = $encryption_manager->decrypt(json_decode($encrypted, true));
            return $decrypted ?: null;
        }

        // Return as-is for backwards compatibility
        return $encrypted;
    }

    /**
     * Get SQL file path with fallback locations
     */
    private static function getSQLFilePath(): string {
        $possible_paths = [
            AIVESESE_PLUGIN_PATH . 'supabase.sql',
            AIVESESE_PLUGIN_PATH . 'assets/sql/supabase.sql',
            AIVESESE_PLUGIN_PATH . 'sql/supabase.sql'
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Return the preferred path even if it doesn't exist
        return AIVESESE_PLUGIN_PATH . 'supabase.sql';
    }

    /**
     * Validate SQL content
     */
    private static function validateSQL(string $sql): array {
        $issues = [];

        // Basic validation
        if (strlen($sql) < 100) {
            $issues[] = 'SQL file appears to be too short';
        }

        // Check for required components
        $required_patterns = [
            'CREATE TABLE.*products' => 'Products table creation',
            'CREATE.*FUNCTION.*fts_search' => 'FTS search function',
            'CREATE.*FUNCTION.*semantic_search' => 'Semantic search function',
            'CREATE EXTENSION.*vector' => 'Vector extension'
        ];

        foreach ($required_patterns as $pattern => $description) {
            if (!preg_match('/' . $pattern . '/i', $sql)) {
                $issues[] = "Missing: {$description}";
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues
        ];
    }
}
