<?php
session_start();
require_once 'config.php';

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'test2');
define('BASE_DIR', 'D:/xamp/htdocs/tableEditor/');
define('SQL_FILE', BASE_DIR . 'test.sql');

// Function to get table alias
function getTableAlias($tableName) {
    global $tableAliases;
    return $tableAliases[$tableName] ?? $tableName;
}

// Function to get column alias
function getColumnAlias($tableName, $columnName) {
    global $columnAliases;
    return $columnAliases[$tableName][$columnName] ?? $columnName;
}

// Handle database creation and SQL execution
if (isset($_POST['execute_sql'])) {
    try {
        $conn = new PDO("mysql:host=".DB_HOST, DB_USER, DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $conn->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."`");
        $conn->exec("USE `".DB_NAME."`");
        
        if (file_exists(SQL_FILE)) {
            $sql = file_get_contents(SQL_FILE);
            $sql = preg_replace('/\/\*.*?\*\/|--.*?$/ms', '', $sql);
            
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    try {
                        $conn->exec($query);
                    } catch(PDOException $e) {
                        continue;
                    }
                }
            }
            
            $allTables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $tables = array_intersect($allTables, $visibleTables);
            
            $_SESSION['tables'] = $tables;
            $_SESSION['success'] = "SQL file executed successfully! Database '".DB_NAME."' is ready.";
            header("Location: index.php?page=tables");
            exit();
        } else {
            throw new PDOException("SQL file not found at: " . SQL_FILE);
        }
    } catch(PDOException $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
        header("Location: index.php");
        exit();
    }
}

// Handle export operations - UPDATED TO CHECK FOR DATA
if (isset($_GET['export'])) {
    try {
        $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $table = $_GET['table'];
        
        $stmt = $conn->query("DESCRIBE `$table`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.getTableAlias($table).'_'.date('Y-m-d').'.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Write headers
        $headers = array_map(function($col) use ($table) {
            return getColumnAlias($table, $col['Field']);
        }, $columns);
        fputcsv($output, $headers);
        
        // Check if table has data
        $countStmt = $conn->query("SELECT COUNT(*) FROM `$table`");
        $hasData = $countStmt->fetchColumn() > 0;
        
        if ($hasData) {
            // Export all data
            $stmt = $conn->query("SELECT * FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
        } else {
            // Export empty template
            $sampleRow = array_fill(0, count($columns), '');
            fputcsv($output, $sampleRow);
        }
        
        fclose($output);
        exit();
        
    } catch(Exception $e) {
        $_SESSION['error'] = "Export failed: " . $e->getMessage();
        header("Location: index.php?page=tables");
        exit();
    }
}

// Handle import operations
if (isset($_POST['import'])) {
    try {
        $table = $_POST['table'];
        $action = $_POST['action'] ?? 'new';
        
        if (empty($_FILES['import_file']['name'])) {
            throw new Exception("No file selected for import.");
        }
        
        $file = $_FILES['import_file']['tmp_name'];
        $fileType = pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION);
        
        if ($fileType !== 'csv') {
            throw new Exception("Only CSV files are supported for import.");
        }
        
        $fileContent = file_get_contents($file);
        $fileHandle = tmpfile();
        fwrite($fileHandle, $fileContent);
        fseek($fileHandle, 0);
        
        $headers = fgetcsv($fileHandle);
        if ($headers === FALSE) {
            throw new Exception("Empty or invalid CSV file.");
        }
        
        $rows = [];
        while (($row = fgetcsv($fileHandle)) !== FALSE) {
            if (!empty(array_filter($row))) {
                $rows[] = $row;
            }
        }
        fclose($fileHandle);
        
        $_SESSION['import_data'] = [
            'table' => $table,
            'action' => $action,
            'headers' => $headers,
            'rows' => $rows
        ];
        
        $_SESSION['success'] = "File uploaded successfully. Data is ready for import.";
        header("Location: index.php?page=tables&action=$action&table=".urlencode($table));
        exit();
        
    } catch(Exception $e) {
        $_SESSION['error'] = "Import failed: " . $e->getMessage();
        $action = $_POST['action'] ?? 'new';
        header("Location: index.php?page=tables&action=$action");
        exit();
    }
}

// Handle new record creation
if (isset($_POST['create_records'])) {
    try {
        $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $table = $_POST['table'];
        
        if (isset($_SESSION['import_data']) && $_SESSION['import_data']['table'] === $table) {
            $importData = $_SESSION['import_data'];
            unset($_SESSION['import_data']);
            
            $headers = $importData['headers'];
            $rows = $importData['rows'];
            
            $stmt = $conn->query("DESCRIBE `$table`");
            $tableColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $columnMap = [];
            foreach ($headers as $headerIndex => $header) {
                $header = trim($header);
                foreach ($tableColumns as $column) {
                    if (getColumnAlias($table, $column) === $header) {
                        $columnMap[$headerIndex] = $column;
                        break;
                    }
                }
            }
            
            if (empty($columnMap)) {
                throw new Exception("No matching columns found between CSV headers and table columns.");
            }
            
            $columnsStr = implode('`, `', array_values($columnMap));
            $placeholders = implode(',', array_fill(0, count($columnMap), '?'));
            $sql = "INSERT INTO `$table` (`$columnsStr`) VALUES ($placeholders)";
            $stmt = $conn->prepare($sql);
            
            $rowCount = 0;
            $errors = [];
            foreach ($rows as $rowIndex => $row) {
                try {
                    $paramIndex = 1;
                    foreach ($columnMap as $csvIndex => $column) {
                        $value = $row[$csvIndex] ?? '';
                        if ($value === '') {
                            $stmt->bindValue($paramIndex, null, PDO::PARAM_NULL);
                        } else {
                            $stmt->bindValue($paramIndex, (string)$value);
                        }
                        $paramIndex++;
                    }
                    $stmt->execute();
                    $rowCount++;
                } catch(PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        $errors[] = "Row " . ($rowIndex + 1) . ": " . $e->getMessage();
                    }
                    continue;
                }
            }
            
            if (!empty($errors)) {
                $_SESSION['warning'] = "Imported $rowCount records with " . count($errors) . " errors. " . 
                                      "First error: " . $errors[0];
            } else {
                $_SESSION['success'] = "Successfully created $rowCount new records in table '".getTableAlias($table)."'";
            }
            
            header("Location: index.php?page=tables&action=new&table=".urlencode($table));
            exit();
        } else {
            throw new Exception("No import data found to create records.");
        }
    } catch(Exception $e) {
        $_SESSION['error'] = "Record creation failed: " . $e->getMessage();
        header("Location: index.php?page=tables&action=new&table=".urlencode($_POST['table']));
        exit();
    }
}

// Handle update execution
if (isset($_POST['execute_update'])) {
    try {
        $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $table = $_POST['table'];
        $column = $_POST['column'];
        
        if (isset($_SESSION['import_data']) && $_SESSION['import_data']['table'] === $table) {
            $importData = $_SESSION['import_data'];
            unset($_SESSION['import_data']);
            
            $headers = $importData['headers'];
            $rows = $importData['rows'];
            
            $conditions = [];
            $params = [];
            
            $params[] = (string)($rows[0][0] ?? '');
            
            $conditionCounter = 1;
            while (isset($_POST['logical_op_'.$conditionCounter])) {
                $logicalOp = $_POST['logical_op_'.$conditionCounter];
                $secondColumn = $_POST['second_column_'.$conditionCounter];
                $value = $_POST['value_'.$conditionCounter];
                
                if (!empty($secondColumn) && !empty($value)) {
                    $conditions[] = "`$secondColumn` = ?";
                    $params[] = (string)$value;
                }
                
                $conditionCounter++;
            }
            
            $sql = "UPDATE `$table` SET `$column` = ?";
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            
            $affectedRows = $stmt->rowCount();
            $_SESSION['success'] = "Update executed successfully.";
            header("Location: index.php?page=tables&action=update&table=".urlencode($table));
            exit();
        } else {
            throw new Exception("No import data found to execute.");
        }
    } catch(Exception $e) {
        $_SESSION['error'] = "Execution failed: " . $e->getMessage();
        header("Location: index.php?page=tables&action=update&table=".urlencode($_POST['table']));
        exit();
    }
}

// Get current page and action
$page = $_GET['page'] ?? 'home';
$action = $_GET['action'] ?? null;
$selectedTable = $_GET['table'] ?? null;

// Get all tables for the dropdown
try {
    $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $allTables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_intersect($allTables, $visibleTables);
    $_SESSION['tables'] = $tables;
    
    $columns = [];
    if ($selectedTable) {
        $stmt = $conn->query("DESCRIBE `$selectedTable`");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch(PDOException $e) {
    $tables = $_SESSION['tables'] ?? [];
    $columns = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Wizard - SQL Executor</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <!-- Navigation Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <i class="fas fa-database"></i>
                <span>DB Wizard</span>
            </div>
            <nav>
                <a href="index.php?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <?php if (isset($_SESSION['tables'])): ?>
                <div class="nav-dropdown">
                    <a href="#" class="<?= $page === 'tables' ? 'active' : '' ?>">
                        <i class="fas fa-table"></i> Tables <i class="fas fa-chevron-down"></i>
                    </a>
                    <div class="nav-dropdown-content">
                        <a href="index.php?page=tables&action=new">
                            <i class="fas fa-plus"></i> New Entry
                        </a>
                        <a href="index.php?page=tables&action=update">
                            <i class="fas fa-edit"></i> Update Entry
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert error">
                    <?= $_SESSION['error'] ?>
                    <span class="close-btn" onclick="this.parentElement.remove()">&times;</span>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert success">
                    <?= $_SESSION['success'] ?>
                    <span class="close-btn" onclick="this.parentElement.remove()">&times;</span>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert warning">
                    <?= $_SESSION['warning'] ?>
                    <span class="close-btn" onclick="this.parentElement.remove()">&times;</span>
                </div>
                <?php unset($_SESSION['warning']); ?>
            <?php endif; ?>

            <!-- Home Page -->
            <?php if ($page === 'home'): ?>
            <div class="hero">
                <div class="hero-content">
                    <h1>SQL Database Executor</h1>
                    <p class="subtitle">Execute SQL files and manage your database tables with ease</p>
                    <form method="POST" class="execute-form">
                        <button type="submit" name="execute_sql" class="btn primary execute-btn">
                            <i class="fas fa-bolt"></i> Execute SQL File
                        </button>
                    </form>
                </div>
                <div class="hero-image">
                    <img src="https://cdn-icons-png.flaticon.com/512/2772/2772165.png" alt="Database Illustration">
                </div>
            </div>

            <!-- Tables Page -->
            <?php elseif ($page === 'tables' && isset($_SESSION['tables'])): ?>
            <div class="tables-container">
                <div class="tables-header">
                    <h2><i class="fas fa-table"></i> Database Tables</h2>
                    <p class="subtitle"><?= $action === 'new' ? 'Create new records' : ($action === 'update' ? 'Update existing records' : 'Select an action') ?></p>
                </div>
                
                <!-- Table Selector -->
                <div class="table-selector-container">
                    <div class="table-selector">
                        <div class="dropdown">
                            <button class="btn primary dropdown-toggle">
                                <i class="fas fa-list"></i> Select Table <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="dropdown-content">
                                <div class="search-container">
                                    <input type="text" id="table-search" placeholder="Search tables..." onkeyup="filterTables()">
                                    <i class="fas fa-search"></i>
                                </div>
                                <div class="tables-list" id="tables-list">
                                    <?php if (!empty($tables)): 
                                        foreach ($tables as $table): ?>
                                            <div class="table-item">
                                                <a href="#" onclick="selectTable('<?= htmlspecialchars($table) ?>')" class="table-link">
                                                    <i class="fas fa-table"></i> <?= htmlspecialchars(getTableAlias($table)) ?>
                                                </a>
                                            </div>
                                        <?php endforeach;
                                    else: ?>
                                        <span class="dropdown-info"><i class="fas fa-info-circle"></i> No tables found</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- New Table Section -->
                <?php if ($action === 'new' && $selectedTable): ?>
                <div class="action-section">
                    <div class="selected-table-info">
                        <h3><i class="fas fa-plus-circle"></i> New Records: <?= htmlspecialchars(getTableAlias($selectedTable)) ?></h3>
                        <p>Import data to create new records in this table</p>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn primary" onclick="exportTable('<?= htmlspecialchars($selectedTable) ?>')">
                            <i class="fas fa-download"></i> Export Template
                        </button>
                        
                        <div class="file-import-container">
                            <form id="new-import-form" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                                <input type="hidden" name="action" value="new">
                                <input type="file" id="new-browse-file" name="import_file" accept=".csv" style="display: none;" onchange="handleNewFileBrowse(this)">
                                <button type="button" class="btn secondary" onclick="document.getElementById('new-browse-file').click()">
                                    <i class="fas fa-file-import"></i> Select File
                                </button>
                                <span id="new-browse-file-name" class="file-name">No file selected</span>
                                <button type="submit" name="import" class="btn success" id="new-import-btn" disabled>
                                    <i class="fas fa-upload"></i> Import Data
                                </button>
                            </form>
                        </div>
                        
                        <?php if (isset($_SESSION['import_data']) && $_SESSION['import_data']['table'] === $selectedTable && $_SESSION['import_data']['action'] === 'new'): ?>
                        <form method="post" class="execute-import-form">
                            <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                            <button type="submit" name="create_records" class="btn primary">
                                <i class="fas fa-play"></i> Execute Import
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($action === 'new'): ?>
                <div class="empty-action-state">
                    <i class="fas fa-table"></i>
                    <p>Please select a table to create new records</p>
                </div>
                <?php endif; ?>
                
                <!-- Update Table Section -->
                <?php if ($action === 'update' && $selectedTable): ?>
                <div class="action-section">
                    <div class="selected-table-info">
                        <h3><i class="fas fa-edit"></i> Update Records: <?= htmlspecialchars(getTableAlias($selectedTable)) ?></h3>
                        <p>Build your update query</p>
                    </div>
                    
                    <div class="update-form-container">
                        <div class="action-buttons">
                            <button class="btn primary" onclick="exportTable('<?= htmlspecialchars($selectedTable) ?>')">
                                <i class="fas fa-download"></i> Export Data
                            </button>
                            
                            <div class="file-import-container">
                                <form id="update-import-form" method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="file" id="update-browse-file" name="import_file" accept=".csv" style="display: none;" onchange="handleUpdateFileBrowse(this)">
                                    <button type="button" class="btn secondary" onclick="document.getElementById('update-browse-file').click()">
                                        <i class="fas fa-file-import"></i> Import Data
                                    </button>
                                    <span id="update-browse-file-name" class="file-name">No file selected</span>
                                    <button type="submit" name="import" class="btn success" id="update-import-btn" disabled>
                                        <i class="fas fa-upload"></i> Upload File
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <?php if (isset($_SESSION['import_data']) && $_SESSION['import_data']['table'] === $selectedTable && $_SESSION['import_data']['action'] === 'update'): ?>
                        <form id="update-query-form" method="post">
                            <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                            
                            <div class="query-builder">
                                <div class="query-line">
                                    <span class="keyword">SELECT</span>
                                    <select name="table" class="form-control table-select" disabled>
                                        <option value="<?= htmlspecialchars($selectedTable) ?>"><?= htmlspecialchars(getTableAlias($selectedTable)) ?></option>
                                    </select>
                                </div>
                                
                                <div class="query-line">
                                    <span class="keyword">FROM</span>
                                    <select name="column" class="form-control column-select" required>
                                        <option value="">Select Column</option>
                                        <?php foreach ($columns as $column): ?>
                                            <option value="<?= htmlspecialchars($column) ?>"><?= htmlspecialchars(getColumnAlias($selectedTable, $column)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Initial condition row -->
                                <div class="condition-row" id="condition-row-1">
                                    <div class="query-line">
                                        <select name="logical_op_1" class="form-control logical-op-select">
                                            <option value="">-- Select --</option>
                                            <option value="AND">AND</option>
                                            <option value="OR">OR</option>
                                        </select>
                                        
                                        <select name="second_column_1" class="form-control column-select">
                                            <option value="">Select Column</option>
                                            <?php foreach ($columns as $column): ?>
                                                <option value="<?= htmlspecialchars($column) ?>"><?= htmlspecialchars(getColumnAlias($selectedTable, $column)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <input type="text" name="value_1" class="form-control value-input" placeholder="Value">
                                        
                                        <button type="button" class="btn icon-btn" onclick="addCondition()">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Container for additional conditions -->
                                <div id="additional-conditions"></div>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit" name="execute_update" class="btn success">
                                    <i class="fas fa-play"></i> Execute
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($action === 'update'): ?>
                <div class="empty-action-state">
                    <i class="fas fa-edit"></i>
                    <p>Please select a table to update records</p>
                </div>
                <?php endif; ?>
                
                <?php if (empty($tables)): ?>
                <div class="empty-state">
                    <i class="fas fa-database"></i>
                    <p>No tables found. Please execute SQL file first.</p>
                    <a href="index.php" class="btn primary">
                        <i class="fas fa-arrow-left"></i> Go Back
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
    let selectedTableName = '';
    let selectedFile = null;
    let updateSelectedFile = null;
    let newSelectedFile = null;
    let conditionCounter = 1;

    // Initialize dropdown system
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.dropdown-content').forEach(dropdown => {
            dropdown.classList.remove('show');
        });
        
        document.querySelectorAll('.dropdown-toggle').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const dropdownContent = this.nextElementSibling;
                const isCurrentlyOpen = dropdownContent.classList.contains('show');
                
                document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
                
                if (!isCurrentlyOpen) {
                    dropdownContent.classList.add('show');
                    document.getElementById('table-search').focus();
                }
            });
        });
        
        document.querySelectorAll('.dropdown-content').forEach(dropdown => {
            dropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
            }
        });
        
        document.querySelectorAll('.nav-dropdown > a').forEach(dropdown => {
            dropdown.addEventListener('click', function(e) {
                e.preventDefault();
                const content = this.nextElementSibling;
                content.style.display = content.style.display === 'block' ? 'none' : 'block';
            });
        });
    });

    function filterTables() {
        const input = document.getElementById('table-search');
        const filter = input.value.toUpperCase();
        const tablesList = document.getElementById('tables-list');
        const tables = tablesList.getElementsByClassName('table-item');
        
        for (let i = 0; i < tables.length; i++) {
            const tableLink = tables[i].getElementsByClassName('table-link')[0];
            const txtValue = tableLink.textContent || tableLink.innerText;
            if (txtValue.toUpperCase().indexOf(filter) > -1) {
                tables[i].style.display = "";
            } else {
                tables[i].style.display = "none";
            }
        }
    }

    function selectTable(tableName) {
        selectedTableName = tableName;
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action') || 'new';
        window.location.href = `index.php?page=tables&action=${action}&table=${encodeURIComponent(tableName)}`;
    }

    function exportTable(table = null) {
        const tableToExport = table || selectedTableName;
        if (tableToExport) {
            window.location.href = `index.php?export=1&table=${encodeURIComponent(tableToExport)}`;
        }
    }

    function handleNewFileBrowse(input) {
        if (input.files.length > 0) {
            newSelectedFile = input.files[0];
            document.getElementById('new-browse-file-name').textContent = newSelectedFile.name;
            document.getElementById('new-import-btn').disabled = false;
        }
    }

    function handleUpdateFileBrowse(input) {
        if (input.files.length > 0) {
            updateSelectedFile = input.files[0];
            document.getElementById('update-browse-file-name').textContent = updateSelectedFile.name;
            document.getElementById('update-import-btn').disabled = false;
        }
    }

    function addCondition() {
        conditionCounter++;
        const newCondition = document.createElement('div');
        newCondition.className = 'condition-row';
        newCondition.id = `condition-row-${conditionCounter}`;
        
        newCondition.innerHTML = `
            <div class="query-line">
                <select name="logical_op_${conditionCounter}" class="form-control logical-op-select">
                    <option value="">-- Select --</option>
                    <option value="AND">AND</option>
                    <option value="OR">OR</option>
                </select>
                
                <select name="second_column_${conditionCounter}" class="form-control column-select">
                    <option value="">Select Column</option>
                    <?php foreach ($columns as $column): ?>
                        <option value="<?= htmlspecialchars($column) ?>"><?= htmlspecialchars(getColumnAlias($selectedTable, $column)) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <input type="text" name="value_${conditionCounter}" class="form-control value-input" placeholder="Value">
                
                <button type="button" class="btn icon-btn" onclick="removeCondition(${conditionCounter})">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        `;
        
        document.getElementById('additional-conditions').appendChild(newCondition);
    }

    function removeCondition(id) {
        const conditionRow = document.getElementById(`condition-row-${id}`);
        if (conditionRow) {
            conditionRow.remove();
        }
    }
    </script>
</body>
</html>
