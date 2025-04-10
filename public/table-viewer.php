<?php
declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;

// Set UTF-8 encoding
header('Content-Type: text/html; charset=utf-8');

// Bootstrap the application
require_once __DIR__ . '/../examples/bootstrap.php';

// Override database configuration to use MySQL
$databaseConnection = 'mysql';

// Use global to avoid PHPStan error
global $capsule;

$capsule->addConnection(
    config("database.connections.{$databaseConnection}")
);

// Configuration
$defaultLimit  = 20;
$defaultClient = 'openai';

// Get parameters from query string with defaults
$limit         = isset($_GET['limit']) ? (int)$_GET['limit'] : $defaultLimit;
$client        = isset($_GET['client']) ? htmlspecialchars($_GET['client']) : $defaultClient;
$selectedTable = isset($_GET['table']) ? $_GET['table'] : '';

// Ensure limit is positive
$limit = max(1, $limit);

// Get all tables in the database
$tables = DB::select('
    SELECT TABLE_NAME 
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = ? 
    ORDER BY TABLE_NAME
', [config('database.connections.mysql.database')]);

$tableNames = array_map(function ($table) {
    return $table->TABLE_NAME;
}, $tables);

// Filter for API cache tables
$apiCacheTables = array_filter($tableNames, function ($table) {
    return str_starts_with($table, 'api_cache_');
});

// Get table sizes
$tableSizes = [];
foreach ($apiCacheTables as $table) {
    $size = DB::select('
        SELECT ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb 
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ', [config('database.connections.mysql.database'), $table])[0]->size_mb;
    $tableSizes[$table] = $size;
}

// Get cache manager instance
$cacheManager = app(ApiCacheManager::class);

// Get all configured clients
$clients = config('api-cache.apis', []);

try {
    // Create tables for the client if they don't exist
    createClientTables($client, false);

    // Get repository instance from container
    $repository = app(CacheRepository::class);

    // Function to format value based on column name
    function formatValue($columnName, $value, $isCompressed, $compression, $client)
    {
        // Handle null values
        if ($value === null) {
            return '<em>null</em>';
        }

        // For compressed tables, decompress the data first
        if ($isCompressed && in_array($columnName, ['request_headers', 'request_body', 'response_headers', 'response_body'])) {
            try {
                $value = $compression->decompress($client, $value);
            } catch (\Exception $e) {
                return '<em>Error decompressing: ' . htmlspecialchars($e->getMessage()) . '</em>';
            }
        }

        // For JSON fields, try to pretty print
        if (in_array($columnName, ['request_headers', 'request_body', 'response_headers', 'response_body'])) {
            try {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return '<pre class="json">' . htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                }
            } catch (\Exception $e) {
                // If JSON parsing fails, just return the raw value
                return '<pre class="json">' . htmlspecialchars((string)$value) . '</pre>';
            }
        }

        // For other fields, just return the value as is
        return htmlspecialchars((string)$value);
    }

    // Function to fetch and display table data
    function displayTable($tableName, $limit, $client)
    {
        try {
            // Check if table exists
            if (!DB::getSchemaBuilder()->hasTable($tableName)) {
                echo "<div class='error-message'>Table '$tableName' does not exist</div>";

                return;
            }

            // Get the rows
            $rows = DB::table($tableName)
                ->limit($limit)
                ->get();

            echo '<h2>' . htmlspecialchars($tableName) . '</h2>';

            if ($rows->isEmpty()) {
                echo '<p>No data found in table (table exists but is empty)</p>';
                // Show table structure for debugging
                $columns = DB::getSchemaBuilder()->getColumnListing($tableName);
                if (!empty($columns)) {
                    echo '<p>Table columns: ' . implode(', ', $columns) . '</p>';
                }

                return;
            }

            // Get compression service
            $compression  = app(CompressionService::class);
            $isCompressed = str_ends_with($tableName, '_compressed');

            // Display each row in a card-like format
            foreach ($rows as $row) {
                echo "<div class='data-card'>";
                foreach ((array)$row as $column => $value) {
                    echo "<div class='field'>";
                    echo "<div class='field-name'>" . htmlspecialchars($column) . ':</div>';
                    echo "<div class='field-value'>" . formatValue($column, $value, $isCompressed, $compression, $client) . '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            // Show row count
            echo '<p>Showing ' . $rows->count() . ' of ' . DB::table($tableName)->count() . ' total rows</p>';
        } catch (\Exception $e) {
            echo "<div class='error-message'>Error displaying table '$tableName': " . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    // HTML start with some basic styling
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>API Cache Table Viewer</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        .table-info {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        .table-info h2 {
            margin-top: 0;
            color: #333;
        }
        .table-list {
            list-style-type: none;
            padding: 0;
        }
        .table-list li {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .table-list li:last-child {
            border-bottom: none;
        }
        .table-size {
            float: right;
            color: #666;
        }
        .table-name {
            font-weight: bold;
        }
        .table-description {
            color: #666;
            font-size: 0.9em;
            margin-left: 20px;
        }
        h1, h2 {
            color: #333;
        }
        .data-card {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .field {
            margin-bottom: 15px;
        }
        .field-name {
            font-weight: bold;
            color: #666;
            margin-bottom: 5px;
        }
        .field-value {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
        pre.json {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .controls {
            margin-bottom: 20px;
            background: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .controls form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .form-group {
            margin-bottom: 10px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .submit-btn {
            grid-column: 1 / -1;
            padding: 10px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .submit-btn:hover {
            background-color: #45a049;
        }
        .error-message {
            color: red;
            padding: 10px;
            background-color: #fee;
            border: 1px solid #faa;
            margin: 10px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="table-info">
        <h2>API Cache Tables</h2>
        <ul class="table-list">
            <?php foreach ($apiCacheTables as $table): ?>
                <li>
                    <span class="table-name"><?php echo htmlspecialchars($table); ?></span>
                    <span class="table-size"><?php echo number_format((float)$tableSizes[$table], 2); ?> MB</span>
                    <?php
                    // Get client name from table name
                    $clientName = str_replace(['api_cache_', '_responses', '_compressed'], '', $table);
                if (isset($clients[$clientName])) {
                    echo '<div class="table-description">Client: ' . htmlspecialchars($clientName) . '</div>';
                }
                ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <h1>API Cache Table Viewer</h1>
    <form method="get">
        <label for="table">Select Table:</label>
        <select name="table" id="table">
            <?php foreach ($apiCacheTables as $table): ?>
                <option value="<?php echo htmlspecialchars($table); ?>" <?php echo $selectedTable === $table ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($table); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="limit">Limit:</label>
        <input type="number" name="limit" id="limit" value="<?php echo $limit; ?>" min="1" max="1000">
        <button type="submit">View</button>
    </form>

    <?php
    // Debug information
    echo "<div style='margin-bottom: 20px;'>";
    echo '<strong>Debug Info:</strong><br>';
    echo 'Database Config: ' . config('database.default') . '<br>';
    echo 'Database Name: ' . config('database.connections.' . config('database.default') . '.database') . '<br>';
    echo '</div>';

    // Get table names from repository
    $uncompressedTable = $repository->getTableName($client);
    config(["api-cache.apis.{$client}.compression_enabled" => true]);
    $compressedTable = $repository->getTableName($client);

    // Display selected table if one is chosen
    if ($selectedTable && in_array($selectedTable, $apiCacheTables)) {
        displayTable($selectedTable, $limit, $client);
    } else {
        echo '<p>Please select a table to view its contents.</p>';
    }
} catch (\Exception $e) {
    echo "<div class='error-message'>";
    echo 'Database Error: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}
?>

</body>
</html> 