<?php
session_start();

// Database configuration (same as your working version)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'test2');

header('Content-Type: application/json');

// Include the column aliases from your main config if it exists
$columnAliases = [];
if (file_exists('config.php')) {
    include_once 'config.php';
}

// Function to get column alias
function getColumnAlias($tableName, $columnName) {
    global $columnAliases;
    return isset($columnAliases[$tableName][$columnName]) 
        ? $columnAliases[$tableName][$columnName] 
        : $columnName;
}

try {
    $table = $_GET['table'] ?? '';
    
    if (empty($table)) {
        throw new Exception("Table name is required");
    }

    $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all columns from the table (using DESCRIBE like your working version)
    $stmt = $conn->query("DESCRIBE `$table`");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare response with both original and aliased names
    $response = [];
    foreach ($columns as $column) {
        $columnName = $column['Field'];
        $response[] = [
            'original' => $columnName,
            'alias' => getColumnAlias($table, $columnName)
        ];
    }
    
    echo json_encode($response);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}