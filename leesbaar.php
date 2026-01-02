<?php
// One-Page Database → Printable Exporter (SQLite + MySQL)
// No sessions, no external libs — pure PHP + PDO

$error = '';
$tables = [];
$connected = false;
$action = $_POST['action'] ?? '';

// Handle export FIRST (before any HTML output)
if ($action === 'export') {
    $db_type = $_POST['db_type'] ?? '';
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table'] ?? '');
    if (!$table || !in_array($db_type, ['sqlite', 'mysql'])) {
        die('Invalid request.');
    }

    try {
        if ($db_type === 'mysql') {
            $host = $_POST['host'] ?? 'localhost';
            $user = $_POST['user'] ?? '';
            $pass = $_POST['pass'] ?? '';
            $dbname = $_POST['dbname'] ?? '';
            if (!$user || !$dbname) die('Missing MySQL credentials.');
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $stmt = $pdo->query("DESCRIBE `$table`");
            $columns = array_column($stmt->fetchAll(), 'Field');
        } else {
            $db_file = $_POST['sqlite_file'] ?? 'database.db';
            if (!file_exists($db_file)) die('SQLite file not found.');
            $pdo = new PDO("sqlite:$db_file");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->query("PRAGMA table_info(`$table`)");
            $columns = array_column($stmt->fetchAll(), 'name');
        }

        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

        // Output printable HTML
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>";
        echo "<title>Export: " . htmlspecialchars($table) . "</title>";
        echo "<style>body{font-family:Arial,sans-serif;margin:20px;} h2{text-align:center;} ";
        echo "table{width:100%;border-collapse:collapse;} ";
        echo "th,td{border:1px solid #000;padding:8px;text-align:left;} ";
        echo "th{background:#f0f0f0;}</style></head><body>";
        echo "<h2>Table Export: " . htmlspecialchars($table) . "</h2>";
        echo "<table><thead><tr>";
        foreach ($columns as $col) {
            echo "<th>" . htmlspecialchars($col) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                if (is_string($val) && strlen($val) > 100) $val = substr($val, 0, 97) . '...';
                echo "<td>" . htmlspecialchars((string)$val) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "<p style='text-align:center;margin-top:20px;color:#555;'>💡 Use <strong>Ctrl+P</strong> (or Cmd+P) → Save as PDF</p>";
        echo "</body></html>";
        exit;
    } catch (Exception $e) {
        die("<h3>Export Error:</h3><pre>" . htmlspecialchars($e->getMessage()) . "</pre>");
    }
}

// Handle "Connect & List Tables"
if ($action === 'connect') {
    $db_type = $_POST['db_type'] ?? 'sqlite';
    
    try {
        if ($db_type === 'mysql') {
            $host = trim($_POST['host'] ?? 'localhost');
            $user = trim($_POST['user'] ?? '');
            $pass = $_POST['pass'] ?? '';
            $dbname = trim($_POST['dbname'] ?? '');
            
            if (!$user || !$dbname) {
                throw new Exception("MySQL: Username and Database Name are required.");
            }
            
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $db_file = trim($_POST['sqlite_file'] ?? 'database.db');
            
            if (!file_exists($db_file)) {
                throw new Exception("SQLite file not found: " . $db_file);
            }
            if (!is_readable($db_file)) {
                throw new Exception("SQLite file is not readable: " . $db_file);
            }
            
            $pdo = new PDO("sqlite:$db_file");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Verify it's a valid SQLite DB
            $pdo->query("SELECT sqlite_version()")->fetch();
            
            // List only user tables (exclude internal sqlite_*)
            $stmt = $pdo->query("
                SELECT name FROM sqlite_master 
                WHERE type = 'table' 
                  AND name NOT LIKE 'sqlite_%'
                ORDER BY name
            ");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $connected = true;
        // Preserve POST data for re-display
        $form_data = $_POST;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        $form_data = $_POST;
    }
} else {
    // Initial load: set defaults
    $form_data = [
        'db_type' => 'sqlite',
        'sqlite_file' => 'database.db',
        'host' => 'localhost',
        'user' => '',
        'pass' => '',
        'dbname' => ''
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database → PDF Exporter (SQLite + MySQL)</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; background: #fafafa; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .form-group { margin: 12px 0; }
        label { display: inline-block; width: 140px; font-weight: 600; }
        input, select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; width: 280px; }
        button { padding: 8px 16px; background: #1976d2; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1565c0; }
        fieldset { border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 6px; }
        legend { font-weight: bold; padding: 0 8px; color: #333; }
        .error { color: #c62828; background: #ffebee; padding: 10px; border-radius: 4px; margin: 15px 0; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Database → PDF Exporter</h2>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="connect">
            
            <div class="form-group">
                <label>Database Type:</label>
                <select name="db_type" id="db_type" onchange="toggleDB()">
                    <option value="sqlite" <?= ($form_data['db_type'] ?? 'sqlite') === 'sqlite' ? 'selected' : '' ?>>SQLite (File)</option>
                    <option value="mysql" <?= ($form_data['db_type'] ?? '') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                </select>
            </div>

            <!-- SQLite -->
            <fieldset id="sqlite_block" <?= ($form_data['db_type'] ?? 'sqlite') !== 'sqlite' ? 'class="hidden"' : '' ?>>
                <legend>SQLite Settings</legend>
                <div class="form-group">
                    <label>File Path:</label>
                    <input type="text" name="sqlite_file" value="<?= htmlspecialchars($form_data['sqlite_file'] ?? 'database.db') ?>" placeholder="e.g., data.db">
                </div>
            </fieldset>

            <!-- MySQL -->
            <fieldset id="mysql_block" <?= ($form_data['db_type'] ?? 'sqlite') !== 'mysql' ? 'class="hidden"' : '' ?>>
                <legend>MySQL Settings</legend>
                <div class="form-group">
                    <label>Host:</label>
                    <input type="text" name="host" value="<?= htmlspecialchars($form_data['host'] ?? 'localhost') ?>">
                </div>
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="user" value="<?= htmlspecialchars($form_data['user'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="pass" value="<?= htmlspecialchars($form_data['pass'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Database:</label>
                    <input type="text" name="dbname" value="<?= htmlspecialchars($form_data['dbname'] ?? '') ?>" required>
                </div>
            </fieldset>

            <button type="submit">Connect & List Tables</button>
        </form>

        <?php if ($connected && !empty($tables)): ?>
            <div class="card" style="margin-top: 20px;">
                <h3>Tables Found (<?= count($tables) ?>):</h3>
                <form method="post">
                    <input type="hidden" name="action" value="export">
                    <!-- Re-embed all connection fields -->
                    <?php foreach (['db_type', 'sqlite_file', 'host', 'user', 'pass', 'dbname'] as $key): ?>
                        <input type="hidden" name="<?= $key ?>" value="<?= htmlspecialchars($form_data[$key] ?? '') ?>">
                    <?php endforeach; ?>
                    <div class="form-group">
                        <label>Select Table:</label>
                        <select name="table" required>
                            <option value="">-- Choose a table --</option>
                            <?php foreach ($tables as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" style="margin-left:10px;">Export to Printable View</button>
                    </div>
                </form>
            </div>
        <?php elseif ($connected): ?>
            <p style="color:#666; margin-top:15px;">No user tables found in the database.</p>
        <?php endif; ?>
    </div>

    <script>
        function toggleDB() {
            const type = document.getElementById('db_type').value;
            const sqliteBlock = document.getElementById('sqlite_block');
            const mysqlBlock = document.getElementById('mysql_block');
            
            if (type === 'sqlite') {
                sqliteBlock.style.display = 'block';
                mysqlBlock.style.display = 'none';
            } else {
                sqliteBlock.style.display = 'none';
                mysqlBlock.style.display = 'block';
            }
        }
        // Initialize on load
        document.addEventListener('DOMContentLoaded', toggleDB);
    </script>
</body>
</html>