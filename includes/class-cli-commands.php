<?php
/**
 * WP-CLI Schema Installation Command
 * File: includes/class-cli-commands.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class AIVectorSearch_CLI_Commands {

    private $encryption_manager;

    public function __construct() {
        $this->encryption_manager = AIVectorSearch_Encryption_Manager::instance();
        $this->register_commands();
    }

    private function register_commands() {
        WP_CLI::add_command('aivs install-schema', [$this, 'install_schema']);
        WP_CLI::add_command('aivs check-schema', [$this, 'check_schema']);
        WP_CLI::add_command('aivs test-connection', [$this, 'test_connection']);
        WP_CLI::add_command('aivs sync-products', [$this, 'sync_products']);
    }

    /**
     * Install or update the database schema via direct PostgreSQL connection
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force installation even if schema appears to be installed
     *
     * [--dry-run]
     * : Show what would be executed without actually running it
     *
     * ## EXAMPLES
     *
     *     wp aivs install-schema
     *     wp aivs install-schema --force
     *     wp aivs install-schema --dry-run
     *
     * @when after_wp_load
     */
    public function install_schema($args, $assoc_args) {
        $force = \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);
        $dry_run = \WP_CLI\Utils\get_flag_value($assoc_args, 'dry_run', false);

        WP_CLI::line('🗄️  AI Vector Search - Schema Installation');
        WP_CLI::line('=====================================');

        // Load Migration Runner
        require_once AIVESESE_PLUGIN_PATH . 'includes/migrations/class-runner.php';

        // Check if migrations can run
        $status = \ZZZSolutions\VectorSearch\Migrations\Runner::getStatus();

        if (!$status['can_run']) {
            WP_CLI::error('Migration environment not ready:');

            foreach ($status['requirements'] as $req => $met) {
                if (!$met) {
                    $req_name = ucwords(str_replace('_', ' ', $req));
                    WP_CLI::line("  ❌ {$req_name}");
                }
            }

            WP_CLI::line('');
            WP_CLI::line('Solutions:');
            if (!$status['requirements']['psql_command']) {
                WP_CLI::line('  • Install PostgreSQL client: apt-get install postgresql-client');
            }
            if (!$status['requirements']['connection_string']) {
                WP_CLI::line('  • Configure PostgreSQL connection string in WordPress admin');
            }
            if (!$status['requirements']['sql_file']) {
                WP_CLI::line('  • Ensure supabase.sql exists at: ' . $status['sql_file_path']);
            }

            return;
        }

        WP_CLI::success('✅ Migration environment ready!');

        if ($dry_run) {
            WP_CLI::line('🔍 DRY RUN - Migration would execute with these settings:');
            WP_CLI::line('   SQL File: ' . $status['sql_file_path']);
            WP_CLI::line('   Connection: [CONFIGURED]');
            WP_CLI::line('   psql Available: ' . ($status['requirements']['psql_command'] ? 'Yes' : 'No'));
            WP_CLI::line('');
            WP_CLI::warning('This was a dry run. No changes were made to the database.');
            return;
        }

        // Check if already installed
        if (!$force) {
            $installed_time = get_option('aivesese_schema_installed');
            if ($installed_time) {
                WP_CLI::line('✅ Schema already installed on ' . date('M j, Y', $installed_time));
                WP_CLI::line('   Use --force to reinstall or wp aivs check-schema for details.');
                return;
            }
        }

        // Run migration
        WP_CLI::line('⚡ Running database migration...');
        $result = \ZZZSolutions\VectorSearch\Migrations\Runner::run();

        if ($result['ok']) {
            WP_CLI::success('✅ ' . $result['msg']);

            if (!empty($result['details']['stdout'])) {
                WP_CLI::line('📋 Migration output:');
                $lines = explode("\n", $result['details']['stdout']);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        WP_CLI::line('   ' . $line);
                    }
                }
            }

            WP_CLI::line('');
            WP_CLI::line('🎉 Next Steps:');
            WP_CLI::line('   1. Run: wp aivs sync-products');
            WP_CLI::line('   2. Test search functionality on your site');
            WP_CLI::line('   3. Configure OpenAI key for semantic search (optional)');

        } else {
            WP_CLI::error('❌ Migration failed: ' . $result['msg']);

            if (!empty($result['details'])) {
                if (!empty($result['details']['stderr'])) {
                    WP_CLI::line('📋 Error details:');
                    if (is_array($result['details']['stderr'])) {
                        foreach ($result['details']['stderr']['errors'] ?? [] as $error) {
                            WP_CLI::line('   ' . $error);
                        }

                        if (!empty($result['details']['stderr']['suggestions'])) {
                            WP_CLI::line('💡 Suggestions:');
                            foreach ($result['details']['stderr']['suggestions'] as $suggestion) {
                                WP_CLI::line('   • ' . $suggestion);
                            }
                        }
                    } else {
                        WP_CLI::line('   ' . $result['details']['stderr']);
                    }
                }
            }
        }
    }

    /**
     * Check current schema status
     *
     * ## EXAMPLES
     *
     *     wp aivs check-schema
     *
     * @when after_wp_load
     */
    public function check_schema($args, $assoc_args) {
        WP_CLI::line('🔍 AI Vector Search - Schema Status Check');
        WP_CLI::line('========================================');

        // Load Migration Runner
        require_once AIVESESE_PLUGIN_PATH . 'includes/migrations/class-runner.php';

        $status = \ZZZSolutions\VectorSearch\Migrations\Runner::getStatus();

        // Display environment status
        WP_CLI::line('🔧 Migration Environment:');
        foreach ($status['requirements'] as $req => $met) {
            $icon = $met ? '✅' : '❌';
            $req_name = ucwords(str_replace('_', ' ', $req));
            WP_CLI::line("   {$icon} {$req_name}");
        }

        WP_CLI::line('');
        WP_CLI::line('📁 Files:');
        WP_CLI::line('   SQL File: ' . ($status['sql_file_exists'] ? '✅' : '❌') . ' ' . $status['sql_file_path']);

        if ($status['can_run']) {
            WP_CLI::success('✅ Migration environment ready!');

            // Test connection
            WP_CLI::line('🔗 Testing database connection...');
            $conn_test = \ZZZSolutions\VectorSearch\Migrations\Runner::canRunMigrations();

            if ($conn_test['ok']) {
                WP_CLI::success('✅ Database connection successful!');
            } else {
                WP_CLI::error('❌ Database connection failed: ' . $conn_test['msg']);
            }

        } else {
            WP_CLI::warning('⚠️  Migration environment not ready');
            WP_CLI::line('Run wp aivs install-schema for setup instructions.');
        }

        // Show installation history
        $installed_time = get_option('aivesese_schema_installed');
        $install_method = get_option('aivesese_schema_install_method');
        $schema_version = get_option('aivesese_schema_version');

        WP_CLI::line('');
        WP_CLI::line('📊 Installation History:');

        if ($installed_time) {
            WP_CLI::line('   ✅ Installed: ' . date('M j, Y \a\t g:i A', $installed_time));
            if ($install_method) {
                WP_CLI::line('   📋 Method: ' . $install_method);
            }
            if ($schema_version) {
                WP_CLI::line('   🏷️  Version: ' . $schema_version);
            }
        } else {
            WP_CLI::line('   ❌ No installation record found');
        }

        // Fallback to REST API check if available
        if (!$status['can_run']) {
            WP_CLI::line('');
            WP_CLI::line('🔄 Attempting REST API health check...');
            $this->check_schema_via_rest_api();
        }
    }

    /**
     * Test database connection
     *
     * ## EXAMPLES
     *
     *     wp aivs test-connection
     *
     * @when after_wp_load
     */
    public function test_connection($args, $assoc_args) {
        WP_CLI::line('🔗 Testing Database Connection');
        WP_CLI::line('=============================');

        // Load Migration Runner
        require_once AIVESESE_PLUGIN_PATH . 'includes/migrations/class-runner.php';

        $status = \ZZZSolutions\VectorSearch\Migrations\Runner::getStatus();

        if (!$status['requirements']['connection_string']) {
            WP_CLI::error('PostgreSQL connection string not configured in WordPress admin.');
            WP_CLI::line('Configure it at: WordPress Admin → Settings → AI Supabase');
            return;
        }

        if (!$status['requirements']['psql_command']) {
            WP_CLI::error('psql command not available.');
            WP_CLI::line('Install PostgreSQL client tools on your server.');
            return;
        }

        WP_CLI::line('⚡ Testing PostgreSQL connection...');

        // Use the Migration Runner's connection test
        $conn_test = \ZZZSolutions\VectorSearch\Migrations\Runner::canRunMigrations();

        if ($conn_test['ok']) {
            WP_CLI::success('✅ PostgreSQL connection successful!');

            WP_CLI::line('🎯 Connection verified using Migration Runner');
            WP_CLI::line('   Ready for: wp aivs install-schema');

        } else {
            WP_CLI::error('❌ Connection failed: ' . $conn_test['msg']);

            if (!empty($conn_test['details'])) {
                WP_CLI::line('📋 Details:');
                foreach ($conn_test['details'] as $key => $value) {
                    if (is_string($value) && !empty($value)) {
                        WP_CLI::line("   {$key}: {$value}");
                    }
                }
            }

            WP_CLI::line('');
            WP_CLI::line('🔧 Troubleshooting:');
            WP_CLI::line('   • Verify connection string in WordPress admin');
            WP_CLI::line('   • Check database server is accessible');
            WP_CLI::line('   • Ensure credentials are correct');
            WP_CLI::line('   • Test network connectivity to Supabase');
        }
    }

    /**
     * Sync WooCommerce products to Supabase
     *
     * ## OPTIONS
     *
     * [--batch-size=<size>]
     * : Number of products to sync at once
     * ---
     * default: 50
     * ---
     *
     * [--with-embeddings]
     * : Generate embeddings during sync (requires OpenAI API key)
     *
     * ## EXAMPLES
     *
     *     wp aivs sync-products
     *     wp aivs sync-products --batch-size=100 --with-embeddings
     *
     * @when after_wp_load
     */
    public function sync_products($args, $assoc_args) {
        if (!class_exists('WooCommerce')) {
            WP_CLI::error('WooCommerce is not active.');
            return;
        }

        $batch_size = \WP_CLI\Utils\get_flag_value($assoc_args, 'batch_size', 50);
        $with_embeddings = \WP_CLI\Utils\get_flag_value($assoc_args, 'with_embeddings', false);

        WP_CLI::line('📦 Syncing WooCommerce Products');
        WP_CLI::line('===============================');

        $product_sync = AIVectorSearch_Product_Sync::instance();

        // Get total count
        $total_products = wp_count_posts('product')->publish;
        WP_CLI::line("Found {$total_products} published products to sync.");

        if ($with_embeddings && !get_option('aivesese_openai')) {
            WP_CLI::warning('OpenAI API key not configured. Syncing without embeddings.');
            $with_embeddings = false;
        }

        // Sync in batches
        $offset = 0;
        $synced_count = 0;
        $progress = \WP_CLI\Utils\make_progress_bar('Syncing products', $total_products);

        while ($offset < $total_products) {
            $result = $product_sync->sync_products_batch($batch_size, $offset);

            if ($result['success']) {
                $synced_count += $result['synced'];
                $progress->tick($result['synced']);
            } else {
                WP_CLI::warning("Batch failed at offset {$offset}: " . $result['message']);
            }

            $offset += $batch_size;
        }

        $progress->finish();

        WP_CLI::success("✅ Synced {$synced_count}/{$total_products} products successfully!");

        if ($with_embeddings) {
            WP_CLI::line('🧠 Generating embeddings...');
            $embedding_result = $product_sync->generate_missing_embeddings();

            if ($embedding_result['success']) {
                WP_CLI::success("Generated embeddings for {$embedding_result['updated']} products.");
            } else {
                WP_CLI::warning('Embedding generation failed: ' . $embedding_result['message']);
            }
        }
    }

    // ================================================
    // PRIVATE HELPER METHODS
    // ================================================

    /**
     * Check if all prerequisites are met
     */
    private function check_prerequisites(): bool {
        // Check if psql is available
        $psql_version = shell_exec('psql --version 2>/dev/null');
        if (!$psql_version) {
            WP_CLI::error('psql (PostgreSQL client) is not installed or not in PATH.');
            WP_CLI::line('Please install PostgreSQL client tools:');
            WP_CLI::line('  Ubuntu/Debian: sudo apt-get install postgresql-client');
            WP_CLI::line('  CentOS/RHEL: sudo yum install postgresql');
            WP_CLI::line('  macOS: brew install postgresql');
            return false;
        }

        // Check if WooCommerce is active (for some commands)
        if (!class_exists('WooCommerce')) {
            WP_CLI::warning('WooCommerce is not active. Some features may not work.');
        }

        return true;
    }

    /**
     * Get encrypted connection string from WordPress options
     */
    private function get_connection_string(): ?string {
        $encrypted = get_option('aivesese_postgres_connection_string');

        if (empty($encrypted)) {
            return null;
        }

        // Decrypt if encrypted, otherwise return as-is (backwards compatibility)
        if (is_string($encrypted) && strpos($encrypted, '{') === 0) {
            $decrypted = $this->encryption_manager->decrypt_option($encrypted);
            return $decrypted ?: null;
        }

        return $encrypted;
    }

    /**
     * Test PostgreSQL connection
     */
    private function test_postgres_connection(string $conn_string): bool {
        $cmd = sprintf('psql "%s" -c "SELECT 1;" 2>/dev/null', escapeshellarg($conn_string));
        exec($cmd, $output, $exit_code);
        return $exit_code === 0;
    }

    /**
     * Execute SQL via psql
     */
    private function execute_sql_via_psql(string $conn_string, string $sql): array {
        // Create temporary file for SQL
        $temp_file = tempnam(sys_get_temp_dir(), 'aivs_schema_');
        file_put_contents($temp_file, $sql);

        // Execute via psql
        $cmd = sprintf(
            'psql "%s" -f "%s" -v "ON_ERROR_STOP=1" --single-transaction 2>&1',
            escapeshellarg($conn_string),
            escapeshellarg($temp_file)
        );

        exec($cmd, $output, $exit_code);

        // Clean up
        unlink($temp_file);

        return [
            'success' => $exit_code === 0,
            'output' => $output,
            'error' => $exit_code !== 0 ? implode("\n", $output) : null
        ];
    }

    /**
     * Get schema status via direct PostgreSQL queries
     */
    private function get_schema_status_via_psql(string $conn_string): array {
        $checks = [
            'tables' => [
                'products' => "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'products');"
            ],
            'functions' => [
                'fts_search' => "SELECT EXISTS (SELECT FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name = 'fts_search' AND routine_type = 'FUNCTION');",
                'semantic_search' => "SELECT EXISTS (SELECT FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name = 'semantic_search' AND routine_type = 'FUNCTION');",
                'sku_search' => "SELECT EXISTS (SELECT FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name = 'sku_search' AND routine_type = 'FUNCTION');",
                'similar_products' => "SELECT EXISTS (SELECT FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name = 'similar_products' AND routine_type = 'FUNCTION');",
                'get_recommendations' => "SELECT EXISTS (SELECT FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name = 'get_recommendations' AND routine_type = 'FUNCTION');",
                'store_health_check' => "SELECT EXISTS (SELECT FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name = 'store_health_check' AND routine_type = 'FUNCTION');",
                'upsert_product' => "SELECT EXISTS (SELECT FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name = 'upsert_product' AND routine_type = 'FUNCTION');"
            ],
            'triggers' => [
                'tsvector_update' => "SELECT EXISTS (SELECT FROM information_schema.triggers WHERE trigger_schema = 'public' AND trigger_name = 'tsvector_update');"
            ],
            'extensions' => [
                'pgcrypto' => "SELECT EXISTS (SELECT FROM pg_extension WHERE extname = 'pgcrypto');",
                'vector' => "SELECT EXISTS (SELECT FROM pg_extension WHERE extname = 'vector');",
                'pg_trgm' => "SELECT EXISTS (SELECT FROM pg_extension WHERE extname = 'pg_trgm');"
            ]
        ];

        $results = [
            'components' => [],
            'is_installed' => true
        ];

        foreach ($checks as $type => $queries) {
            $results['components'][$type] = [];

            foreach ($queries as $name => $query) {
                $cmd = sprintf('psql "%s" -t -c "%s" 2>/dev/null', escapeshellarg($conn_string), $query);
                exec($cmd, $output, $exit_code);

                $exists = ($exit_code === 0 && !empty($output) && trim($output[0]) === 't');
                $results['components'][$type][$name] = $exists;

                if (!$exists) {
                    $results['is_installed'] = false;
                }
            }
        }

        return $results;
    }

    /**
     * Get database info
     */
    private function get_database_info(string $conn_string): ?array {
        $cmd = sprintf('psql "%s" -t -c "SELECT version(), current_database();" 2>/dev/null', escapeshellarg($conn_string));
        exec($cmd, $output, $exit_code);

        if ($exit_code !== 0 || empty($output)) {
            return null;
        }

        $info = explode('|', $output[0]);

        return [
            'version' => trim($info[0] ?? ''),
            'database' => trim($info[1] ?? '')
        ];
    }

    /**
     * Get SQL file content
     */
    private function get_sql_content(): string {
        $sql_file = $this->get_sql_file_path();
        return file_exists($sql_file) ? file_get_contents($sql_file) : '';
    }

    /**
     * Get SQL file path
     */
    private function get_sql_file_path(): string {
        return AIVESESE_PLUGIN_PATH . 'supabase.sql';
    }

    /**
     * Fallback schema check via REST API
     */
    private function check_schema_via_rest_api(): void {
        WP_CLI::line('Using Supabase REST API for status check...');

        $supabase_client = AIVectorSearch_Supabase_Client::instance();
        $health = $supabase_client->get_store_health();

        if (!empty($health)) {
            WP_CLI::success('✅ REST API connection successful!');
            $data = $health[0];
            WP_CLI::line('📊 Store Health:');
            WP_CLI::line('   Total products: ' . ($data['total_products'] ?? 0));
            WP_CLI::line('   Published: ' . ($data['published_products'] ?? 0));
            WP_CLI::line('   With embeddings: ' . ($data['with_embeddings'] ?? 0));
        } else {
            WP_CLI::warning('⚠️  Could not retrieve status via REST API');
        }
    }

    /**
     * Get component icon for display
     */
    private function get_component_icon(string $type): string {
        $icons = [
            'tables' => '📋',
            'functions' => '⚡',
            'triggers' => '🔄',
            'extensions' => '🔧'
        ];
        return $icons[$type] ?? '📄';
    }
}

// Initialize WP-CLI commands
if (class_exists('WP_CLI')) {
    new AIVectorSearch_CLI_Commands();
}
