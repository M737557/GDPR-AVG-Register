<?php
// every record update triggers an set date on column updated_at
// added home button.
// added timestamp voor nieuwe records current timestamp

session_start();

// Database configuration
$db_config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => '',
    'table' => 'avg_register',
    'users_table' => 'system_users',
    'changes_table' => 'system_changes'
];

// Define user roles and permissions
$user_roles = [
    'admin' => ['view', 'add', 'edit', 'delete', 'manage_users', 'view_changes'],
    'editor' => ['view', 'add', 'edit', 'view_own_changes'],
    'viewer' => ['view']
];

// Define which columns should use ROT47 encryption
$rot47_columns = [
    'naam_verwerkingsverantwoordelijke',
    'contact_verwerkingsverantwoordelijke',
    'naam_gezamenlijke_verwerkingsverantwoordelijke',
    'contact_gezamenlijke_verwerkingsverantwoordelijke',
    'naam_vertegenwoordiger',
    'contact_vertegenwoordiger',
    'naam_fg',
    'contact_fg'
];

// ROT47 functions
function rot47_encrypt($string) {
    if ($string === null || $string === '') {
        return $string;
    }
    $result = '';
    for ($i = 0, $len = strlen($string); $i < $len; $i++) {
        $j = ord($string[$i]);
        if ($j >= 33 && $j <= 126) {
            $result .= chr(33 + (($j + 14) % 94));
        } else {
            $result .= $string[$i];
        }
    }
    return $result;
}

function rot47_decrypt($string) {
    return rot47_encrypt($string);
}

// Password hashing
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Check if user has permission
function has_permission($permission) {
    global $current_user, $user_roles;
    
    if (!$current_user) return false;
    
    $role = $current_user['role'];
    return in_array($permission, $user_roles[$role]);
}

// Function to get IPv4 address
function get_ipv4_address() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if ($ip === '::1') {
        return '127.0.0.1';
    }
    
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if (strpos($ip, '::ffff:') === 0) {
            return substr($ip, 7);
        }
        
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return '127.0.0.1';
        }
        
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                foreach ($ips as $client_ip) {
                    $client_ip = trim($client_ip);
                    if (filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $client_ip;
                    }
                }
            }
        }
    }
    
    return $ip;
}

// Initialize variables
$connection = null;
$error = '';
$success = '';
$result = null;
$columns = [];
$edit_row = null;
$sort_column = '';
$sort_direction = 'ASC';
$is_logged_in = false;
$current_user = null;
$changes = [];
$show_changes = false;
$total_rows = 0;
$show_user_form = false;

// Check session for logged in user
if (isset($_SESSION['user_id'])) {
    $is_logged_in = true;
    $current_user = $_SESSION['user'];
}

// Function to create users table
function create_users_table($connection, $table_name) {
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        full_name VARCHAR(100),
        role ENUM('admin', 'editor', 'viewer') DEFAULT 'viewer',
        is_active BOOLEAN DEFAULT TRUE,
        last_login DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if ($connection->query($sql)) {
        $check_sql = "SELECT COUNT(*) as count FROM $table_name";
        $result = $connection->query($check_sql);
        $row = $result->fetch_assoc();
        
        if ($row['count'] == 0) {
            $default_password = hash_password('admin123');
            $insert_sql = "INSERT INTO $table_name (username, password, email, full_name, role) 
                          VALUES ('admin', '$default_password', 'admin@example.com', 'Administrator', 'admin')";
            $connection->query($insert_sql);
        }
        return true;
    }
    return false;
}

// Function to create changes table
function create_changes_table($connection, $table_name) {
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(100) NOT NULL,
        record_id INT NOT NULL,
        action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
        old_data JSON,
        new_data JSON,
        changed_fields TEXT,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        changed_by INT NOT NULL,
        user_ip VARCHAR(45),
        user_agent VARCHAR(255),
        FOREIGN KEY (changed_by) REFERENCES system_users(id) ON DELETE CASCADE
    )";
    
    return $connection->query($sql);
}

// Function to log changes
function log_change($connection, $table_name, $record_id, $action, $old_data = null, $new_data = null, $changed_fields = null) {
    global $current_user;
    
    $table = $GLOBALS['db_config']['changes_table'];
    
    $old_data_json = $old_data ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null;
    $new_data_json = $new_data ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null;
    
    $user_ip = get_ipv4_address();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $sql = "INSERT INTO $table (table_name, record_id, action, old_data, new_data, changed_fields, changed_by, user_ip, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sissssiss", 
            $table_name, 
            $record_id, 
            $action, 
            $old_data_json, 
            $new_data_json, 
            $changed_fields,
            $current_user['id'],
            $user_ip,
            $user_agent
        );
        return $stmt->execute();
    }
    return false;
}

// Function to get changed fields between two arrays
function get_changed_fields($old_data, $new_data) {
    $changed = [];
    $all_keys = array_unique(array_merge(array_keys($old_data), array_keys($new_data)));
    
    foreach ($all_keys as $key) {
        $old_value = $old_data[$key] ?? null;
        $new_value = $new_data[$key] ?? null;
        
        if ($old_value !== $new_value) {
            $changed[] = $key;
        }
    }
    
    return implode(', ', $changed);
}

// Function to get readable action name
function get_action_name($action) {
    $actions = [
        'INSERT' => 'Added',
        'UPDATE' => 'Modified',
        'DELETE' => 'Deleted'
    ];
    return $actions[$action] ?? $action;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        try {
            $connection = new mysqli(
                $db_config['host'],
                $db_config['username'],
                $db_config['password'],
                $db_config['database']
            );
            
            if ($connection->connect_error) {
                throw new Exception("Connection failed: " . $connection->connect_error);
            }
            
            create_users_table($connection, $db_config['users_table']);
            create_changes_table($connection, $db_config['changes_table']);
            
            $username = $connection->real_escape_string($_POST['username']);
            $password = $_POST['password'];
            
            $sql = "SELECT * FROM {$db_config['users_table']} WHERE username = '$username' AND is_active = TRUE LIMIT 1";
            $result = $connection->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                if (verify_password($password, $user['password'])) {
                    $update_sql = "UPDATE {$db_config['users_table']} SET last_login = NOW() WHERE id = {$user['id']}";
                    $connection->query($update_sql);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user'] = $user;
                    
                    $is_logged_in = true;
                    $current_user = $user;
                    $success = "Login successful!";
                    
                    header("Location: ?");
                    exit();
                } else {
                    $error = "Invalid username or password";
                }
            } else {
                $error = "Invalid username or password";
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'logout') {
        session_destroy();
        header("Location: ?");
        exit();
    }
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'show_changes') {
        $show_changes = true;
    }
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'show_user_form') {
        $show_user_form = true;
    }
    
    elseif ($is_logged_in && has_permission('manage_users')) {
        try {
            $connection = new mysqli(
                $db_config['host'],
                $db_config['username'],
                $db_config['password'],
                $db_config['database']
            );
            
            if ($connection->connect_error) {
                throw new Exception("Connection failed: " . $connection->connect_error);
            }
            
            if (isset($_POST['user_action'])) {
                switch ($_POST['user_action']) {
                    case 'add_user':
                        $username = $connection->real_escape_string($_POST['username']);
                        $password = hash_password($_POST['password']);
                        $email = $connection->real_escape_string($_POST['email']);
                        $full_name = $connection->real_escape_string($_POST['full_name']);
                        $role = $connection->real_escape_string($_POST['role']);
                        
                        $sql = "INSERT INTO {$db_config['users_table']} (username, password, email, full_name, role) 
                                VALUES ('$username', '$password', '$email', '$full_name', '$role')";
                        
                        if ($connection->query($sql)) {
                            $user_id = $connection->insert_id;
                            log_change($connection, $db_config['users_table'], $user_id, 'INSERT', null, [
                                'username' => $username,
                                'email' => $email,
                                'full_name' => $full_name,
                                'role' => $role
                            ], 'username,email,full_name,role');
                            
                            $success = "User added successfully!";
                            $show_user_form = false;
                        } else {
                            $error = "Error adding user: " . $connection->error;
                            $show_user_form = true;
                        }
                        break;
                        
                    case 'edit_user':
                        $user_id = $connection->real_escape_string($_POST['user_id']);
                        $email = $connection->real_escape_string($_POST['email']);
                        $full_name = $connection->real_escape_string($_POST['full_name']);
                        $role = $connection->real_escape_string($_POST['role']);
                        $is_active = isset($_POST['is_active']) ? 1 : 0;
                        
                        $old_sql = "SELECT email, full_name, role, is_active FROM {$db_config['users_table']} WHERE id = '$user_id'";
                        $old_result = $connection->query($old_sql);
                        $old_data = $old_result->fetch_assoc();
                        
                        $sql = "UPDATE {$db_config['users_table']} SET 
                                email = '$email', 
                                full_name = '$full_name', 
                                role = '$role',
                                is_active = $is_active,
                                updated_at = CURRENT_TIMESTAMP
                                WHERE id = '$user_id'";
                        
                        if ($connection->query($sql)) {
                            $new_data = [
                                'email' => $email,
                                'full_name' => $full_name,
                                'role' => $role,
                                'is_active' => $is_active
                            ];
                            $changed_fields = get_changed_fields($old_data, $new_data);
                            
                            log_change($connection, $db_config['users_table'], $user_id, 'UPDATE', $old_data, $new_data, $changed_fields);
                            
                            $success = "User updated successfully!";
                        } else {
                            $error = "Error updating user: " . $connection->error;
                        }
                        break;
                        
                    case 'delete_user':
                        $user_id = $connection->real_escape_string($_POST['user_id']);
                        
                        if ($user_id == $current_user['id']) {
                            $error = "You cannot delete your own account!";
                        } else {
                            $old_sql = "SELECT username, email, full_name, role FROM {$db_config['users_table']} WHERE id = '$user_id'";
                            $old_result = $connection->query($old_sql);
                            $old_data = $old_result->fetch_assoc();
                            
                            $sql = "DELETE FROM {$db_config['users_table']} WHERE id = '$user_id'";
                            if ($connection->query($sql)) {
                                log_change($connection, $db_config['users_table'], $user_id, 'DELETE', $old_data, null, 'username,email,full_name,role');
                                
                                $success = "User deleted successfully!";
                            } else {
                                $error = "Error deleting user: " . $connection->error;
                            }
                        }
                        break;
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle main database operations (only if logged in)
if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && in_array($_POST['action'], ['add', 'edit', 'delete'])) {
        try {
            $connection = new mysqli(
                $db_config['host'],
                $db_config['username'],
                $db_config['password'],
                $db_config['database']
            );
            
            if ($connection->connect_error) {
                throw new Exception("Connection failed: " . $connection->connect_error);
            }
            
            switch ($_POST['action']) {
                case 'add':
                    if (!has_permission('add')) {
                        $error = "You don't have permission to add records";
                        break;
                    }
                    
                    $columns = getTableColumns($connection, $db_config['table']);
                    $values = [];
                    $new_data = [];
                    
                    foreach ($columns as $col) {
                        $col_name = $col['Field'];
                        if ($col_name === 'id' || strpos($col['Extra'], 'auto_increment') !== false) {
                            continue;
                        }
                        
                        // Skip created_at - it will be set automatically by MySQL's DEFAULT CURRENT_TIMESTAMP
                        if ($col_name === 'created_at') {
                            continue;
                        }
                        
                        // Skip updated_at - it will be set automatically by MySQL's DEFAULT CURRENT_TIMESTAMP
                        if ($col_name === 'updated_at') {
                            continue;
                        }
                        
                        if (isset($_POST[$col_name])) {
                            $value = trim($_POST[$col_name]);
                            if (in_array($col_name, $rot47_columns) && $value !== '') {
                                $value = rot47_encrypt($value);
                            }
                            $value = $connection->real_escape_string($value);
                            $values[$col_name] = "'$value'";
                            $new_data[$col_name] = $value;
                        } else {
                            $values[$col_name] = "NULL";
                            $new_data[$col_name] = null;
                        }
                    }
                    
                    if (!empty($values)) {
                        $columns_str = implode(', ', array_keys($values));
                        $values_str = implode(', ', array_values($values));
                        
                        $sql = "INSERT INTO {$db_config['table']} ($columns_str) VALUES ($values_str)";
                        if ($connection->query($sql)) {
                            $record_id = $connection->insert_id;
                            
                            // Add current timestamps to new_data for logging
                            $new_data['created_at'] = date('Y-m-d H:i:s');
                            $new_data['updated_at'] = date('Y-m-d H:i:s');
                            
                            log_change($connection, $db_config['table'], $record_id, 'INSERT', null, $new_data, $columns_str);
                            
                            $success = "Record added successfully!";
                        } else {
                            $error = "Error adding record: " . $connection->error;
                        }
                    }
                    break;
                    
                case 'edit':
                    if (!has_permission('edit')) {
                        $error = "You don't have permission to edit records";
                        break;
                    }
                    
                    if (isset($_POST['id'])) {
                        $edit_id = $connection->real_escape_string($_POST['id']);
                        $columns = getTableColumns($connection, $db_config['table']);
                        $updates = [];
                        $new_data = [];
                        
                        $old_sql = "SELECT * FROM {$db_config['table']} WHERE id = '$edit_id' LIMIT 1";
                        $old_result = $connection->query($old_sql);
                        $old_data = $old_result->fetch_assoc();
                        
                        foreach ($columns as $col) {
                            $col_name = $col['Field'];
                            if ($col_name === 'id' || strpos($col['Extra'], 'auto_increment') !== false) {
                                continue;
                            }
                            
                            // Skip created_at - it should not be updated
                            if ($col_name === 'created_at') {
                                continue;
                            }
                            
                            // Skip updated_at - it will be updated automatically by MySQL's ON UPDATE CURRENT_TIMESTAMP
                            if ($col_name === 'updated_at') {
                                continue;
                            }
                            
                            if (isset($_POST[$col_name])) {
                                $value = trim($_POST[$col_name]);
                                $log_value = $value;
                                
                                if (in_array($col_name, $rot47_columns) && $value !== '') {
                                    $value = rot47_encrypt($value);
                                }
                                $value = $connection->real_escape_string($value);
                                $updates[] = "$col_name = '$value'";
                                $new_data[$col_name] = $log_value;
                            }
                        }
                        
                        // Add updated_at column to be updated (MySQL will handle this automatically)
                        // We add it to the update query for clarity, but it's actually redundant
                        $updates[] = "updated_at = CURRENT_TIMESTAMP";
                        
                        if (!empty($updates)) {
                            $updates_str = implode(', ', $updates);
                            $sql = "UPDATE {$db_config['table']} SET $updates_str WHERE id = '$edit_id'";
                            if ($connection->query($sql)) {
                                // Add current timestamp to new_data for logging (created_at stays the same)
                                $new_data['updated_at'] = date('Y-m-d H:i:s');
                                
                                $changed_fields = get_changed_fields($old_data, $new_data);
                                $changed_fields .= ($changed_fields ? ', ' : '') . 'updated_at';
                                
                                log_change($connection, $db_config['table'], $edit_id, 'UPDATE', $old_data, $new_data, $changed_fields);
                                
                                $success = "Record updated successfully!";
                            } else {
                                $error = "Error updating record: " . $connection->error;
                            }
                        }
                    }
                    break;
                    
                case 'delete':
                    if (!has_permission('delete')) {
                        $error = "You don't have permission to delete records";
                        break;
                    }
                    
                    if (isset($_POST['id'])) {
                        $delete_id = $connection->real_escape_string($_POST['id']);
                        
                        $old_sql = "SELECT * FROM {$db_config['table']} WHERE id = '$delete_id'";
                        $old_result = $connection->query($old_sql);
                        $old_data = $old_result->fetch_assoc();
                        
                        $sql = "DELETE FROM {$db_config['table']} WHERE id = '$delete_id'";
                        if ($connection->query($sql)) {
                            log_change($connection, $db_config['table'], $delete_id, 'DELETE', $old_data, null, 'all_fields');
                            
                            $success = "Record deleted successfully!";
                        } else {
                            $error = "Error deleting record: " . $connection->error;
                        }
                    }
                    break;
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Function to get table columns
function getTableColumns($connection, $table) {
    $columns = [];
    $result = $connection->query("SHOW COLUMNS FROM $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row;
        }
    }
    return $columns;
}

// Function to validate column exists before using in ORDER BY
function validateColumnExists($connection, $table, $column) {
    $columns = getTableColumns($connection, $table);
    foreach ($columns as $col) {
        if ($col['Field'] === $column) {
            return true;
        }
    }
    return false;
}

// Fetch data if connected and logged in
if ($is_logged_in && !$connection && !$error) {
    try {
        $connection = new mysqli(
            $db_config['host'],
            $db_config['username'],
            $db_config['password'],
            $db_config['database']
        );
        
        if ($connection->connect_error) {
            throw new Exception("Connection failed: " . $connection->connect_error);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get table data if connected and logged in
if ($is_logged_in && $connection && !$error) {
    // Get columns first
    $columns = getTableColumns($connection, $db_config['table']);
    
    $column_names = [];
    foreach ($columns as $col) {
        $column_names[] = $col['Field'];
    }
    
    // Start building the SQL query - NO LIMIT!
    $sql = "SELECT * FROM {$db_config['table']}";
    
    // Check for custom sort request
    if (isset($_GET['sort'])) {
        $requested_sort_column = $_GET['sort'];
        
        if (validateColumnExists($connection, $db_config['table'], $requested_sort_column)) {
            $sort_column = $requested_sort_column;
            $sort_direction = isset($_GET['dir']) && $_GET['dir'] === 'desc' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY `$sort_column` $sort_direction";
        } else {
            $error = "Invalid sort column: '$requested_sort_column'. Available columns: " . implode(', ', $column_names);
        }
    } else {
        // DEFAULT SORTING: first by hoofdcategorie, then by subcategorie
        // Check if these columns exist
        $hoofdcategorie_exists = validateColumnExists($connection, $db_config['table'], 'hoofdcategorie');
        $subcategorie_exists = validateColumnExists($connection, $db_config['table'], 'subcategorie');
        
        if ($hoofdcategorie_exists && $subcategorie_exists) {
            // Sort by hoofdcategorie ASC, then subcategorie ASC
            $sql .= " ORDER BY hoofdcategorie ASC, subcategorie ASC";
        } elseif ($hoofdcategorie_exists) {
            // Only hoofdcategorie exists
            $sql .= " ORDER BY hoofdcategorie ASC";
        } else {
            // Neither exists, fallback to ID
            $sql .= " ORDER BY id ASC";
        }
    }
    
    // NO LIMIT ADDED HERE - SHOW ALL ROWS
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM {$db_config['table']}";
    $count_result = $connection->query($count_sql);
    if ($count_result) {
        $total_rows = $count_result->fetch_assoc()['total'];
    }
    
    try {
        $result = $connection->query($sql);
        if (!$result) {
            throw new Exception("Query failed: " . $connection->error . " | SQL: " . $sql);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        $result = null;
    }
    
    // Handle edit request
    if (isset($_GET['edit'])) {
        if (has_permission('edit')) {
            $edit_id = $connection->real_escape_string($_GET['edit']);
            $edit_result = $connection->query("SELECT * FROM {$db_config['table']} WHERE id = '$edit_id' LIMIT 1");
            if ($edit_result && $edit_result->num_rows > 0) {
                $edit_row = $edit_result->fetch_assoc();
                foreach ($rot47_columns as $col) {
                    if (isset($edit_row[$col]) && $edit_row[$col] !== null) {
                        $edit_row[$col] = rot47_decrypt($edit_row[$col]);
                    }
                }
            }
        } else {
            $error = "You don't have permission to edit records";
        }
    }
    
    // Get users list for management
    $users_list = [];
    if (has_permission('manage_users')) {
        $users_result = $connection->query("SELECT * FROM {$db_config['users_table']} ORDER BY username");
        if ($users_result) {
            while ($user = $users_result->fetch_assoc()) {
                $users_list[] = $user;
            }
        }
    }
    
    // Get changes for review mode - THIS HAS LIMIT 100, but that's OK for changes
    if ($show_changes || isset($_GET['view_changes'])) {
        if (has_permission('view_changes') || (has_permission('view_own_changes') && !has_permission('view_changes'))) {
            $changes_sql = "SELECT c.*, u.username, u.full_name 
                           FROM {$db_config['changes_table']} c 
                           LEFT JOIN {$db_config['users_table']} u ON c.changed_by = u.id";
            
            if (!has_permission('view_changes') && has_permission('view_own_changes')) {
                $changes_sql .= " WHERE c.changed_by = {$current_user['id']}";
            }
            
            $changes_sql .= " ORDER BY c.changed_at DESC LIMIT 100";
            
            $changes_result = $connection->query($changes_sql);
            if ($changes_result) {
                while ($change = $changes_result->fetch_assoc()) {
                    if ($change['old_data']) {
                        $change['old_data'] = json_decode($change['old_data'], true);
                    }
                    if ($change['new_data']) {
                        $change['new_data'] = json_decode($change['new_data'], true);
                    }
                    $changes[] = $change;
                }
            }
            $show_changes = true;
        }
    }
}

if ($connection) {
    $connection->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AVG REGISTER</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: monospace;
        }
        
        body {
            background-color: #fff;
            color: #000;
            font-size: 12px;
            line-height: 1.4;
            padding: 10px;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
        }
        
        .header {
            background-color: #fff;
            padding: 10px;
            border: 1px solid #ddd;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .title {
            font-size: 14px;
            font-weight: bold;
            color: #000;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .home-btn {
            background-color: #fff;
            color: #000;
            border: 1px solid #ddd;
            padding: 4px 12px;
            cursor: pointer;
            font-size: 11px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        
        .home-btn:hover {
            background-color: #eee;
            border-color: #000;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-role {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            color: #fff;
        }
        
        .role-admin { background-color: #900; }
        .role-editor { background-color: #090; }
        .role-viewer { background-color: #009; }
        
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            background-color: #fff;
            border: 1px solid #ddd;
            padding: 20px;
        }
        
        .login-title {
            text-align: center;
            margin-bottom: 20px;
            font-size: 16px;
            color: #000;
        }
        
        .login-form .form-group {
            margin-bottom: 15px;
        }
        
        .login-form .form-label {
            display: block;
            margin-bottom: 5px;
            color: #000;
        }
        
        .login-form .form-input {
            width: 100%;
            padding: 8px;
            background-color: #fff;
            border: 1px solid #ddd;
            color: #000;
        }
        
        .login-form .btn {
            width: 100%;
            padding: 10px;
            background-color: #fff;
            border: 1px solid #ddd;
            color: #000;
            cursor: pointer;
        }
        
        .login-form .btn:hover {
            background-color: #eee;
        }
        
        .messages {
            margin-bottom: 10px;
        }
        
        .error {
            background-color: #fff;
            border: 1px solid #f00;
            color: #f00;
            padding: 8px;
            margin-bottom: 5px;
        }
        
        .success {
            background-color: #fff;
            border: 1px solid #0f0;
            color: #090;
            padding: 8px;
            margin-bottom: 5px;
        }
        
        .toolbar {
            background-color: #fff;
            padding: 8px;
            border: 1px solid #ddd;
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .btn {
            background-color: #fff;
            color: #000;
            border: 1px solid #ddd;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 11px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background-color: #eee;
            border-color: #000;
        }
        
        .data-container {
            background-color: #fff;
            border: 1px solid #ddd;
            overflow-x: auto;
            overflow-y: auto;
            max-height: 80vh;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        .table th {
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 10;
            color: #000;
        }
        
        .table td {
            border: 1px solid #ddd;
            padding: 4px;
            vertical-align: top;
            word-break: break-all;
            color: #000;
        }
        
        .table tr:hover {
            background-color: #f5f5f5;
        }
        
        .actions {
            white-space: nowrap;
        }
        
        .form-container {
            background-color: #fff;
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .form-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            color: #000;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-size: 11px;
            margin-bottom: 3px;
            color: #333;
        }
        
        .form-input {
            background-color: #fff;
            border: 1px solid #ddd;
            color: #000;
            padding: 4px;
            font-size: 11px;
        }
        
        .form-textarea {
            background-color: #fff;
            border: 1px solid #ddd;
            color: #000;
            padding: 4px;
            font-size: 11px;
            min-height: 60px;
            resize: vertical;
        }
        
        .form-actions {
            margin-top: 10px;
            display: flex;
            gap: 5px;
        }
        
        .compact-view {
            font-size: 10px;
        }
        
        .compact-view .table td {
            padding: 2px 3px;
        }
        
        .toggle-view {
            margin-left: auto;
        }
        
        .status-bar {
            background-color: #fff;
            border: 1px solid #ddd;
            padding: 5px;
            margin-top: 10px;
            font-size: 10px;
            color: #666;
            display: flex;
            justify-content: space-between;
        }
        
        .encryption-info {
            background-color: #fff;
            border: 1px solid #000;
            padding: 5px;
            margin-top: 5px;
            font-size: 10px;
            color: #000;
            text-align: center;
            font-weight: bold;
        }
        
        .users-management {
            margin-top: 20px;
        }
        
        .user-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .users-table {
            margin-top: 20px;
        }
        
        .changes-container {
            margin-top: 20px;
        }
        
        .change-action {
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            color: #fff;
        }
        
        .action-INSERT { background-color: #060; }
        .action-UPDATE { background-color: #660; }
        .action-DELETE { background-color: #600; }
        
        .change-details {
            margin-top: 5px;
            padding: 5px;
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            font-size: 11px;
            color: #000;
        }
        
        .change-field {
            margin: 3px 0;
        }
        
        .old-value {
            color: #900;
            text-decoration: line-through;
        }
        
        .new-value {
            color: #090;
        }
        
        .change-arrow {
            color: #666;
            margin: 0 5px;
        }
        
        .diff-view {
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background-color: #fff;
            border: 1px solid #ddd;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            color: #000;
        }
        
        .diff-added {
            background-color: #e6ffe6;
            color: #006600;
        }
        
        .diff-removed {
            background-color: #ffe6e6;
            color: #660000;
            text-decoration: line-through;
        }
        
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255,255,255,0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        /* Nieuwe classes voor versleutelde indicatoren */
        .encrypted-indicator {
            color: #000 !important;
            font-weight: bold;
        }
        
        .encrypted-badge {
            color: #000;
            font-weight: bold;
            font-size: 10px;
        }
        
        .encrypted-check {
            color: #000;
            font-weight: bold;
            font-size: 9px;
        }
        
        .timestamp-info {
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            padding: 5px;
            margin: 5px 0;
            font-size: 10px;
            color: #666;
            text-align: center;
            border-radius: 3px;
        }
        
        .timestamp-info strong {
            color: #000;
        }
        
        @media (max-width: 768px) {
            .form-grid, .user-form-grid {
                grid-template-columns: 1fr;
            }
            
            .toolbar {
                flex-direction: column;
            }
            
            .header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .title {
                width: 100%;
                justify-content: space-between;
            }
            
            .user-info {
                width: 100%;
                justify-content: space-between;
            }
        }
        
        .row-counter {
            background-color: #fff;
            border: 1px solid #ddd;
            padding: 5px;
            margin-bottom: 10px;
            font-size: 12px;
            color: #000;
            text-align: center;
            font-weight: bold;
        }
        
        .ipv4-format {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            color: #006;
        }
        
        .user-management-section {
            margin-bottom: 20px;
        }
        
        .user-form-toggle {
            margin-left: auto;
        }
        
        .sort-info {
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            padding: 5px;
            margin-bottom: 10px;
            font-size: 11px;
            color: #666;
            text-align: center;
        }
        
        .home-icon {
            display: inline-block;
            width: 12px;
            height: 12px;
            background-color: #000;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z'/%3E%3C/svg%3E") no-repeat center;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z'/%3E%3C/svg%3E") no-repeat center;
            mask-size: contain;
            -webkit-mask-size: contain;
        }
        
        /* Styling voor created_at en updated_at velden */
        .creation-time {
            background-color: #f0f8ff;
            border: 1px solid #ddd;
            padding: 5px;
            margin: 5px 0;
            font-size: 10px;
            color: #006;
            text-align: center;
            border-radius: 3px;
        }
        
        .creation-time strong {
            color: #000;
        }
        
        .timestamp-field {
            background-color: #f9f9f9 !important;
            font-family: 'Courier New', monospace !important;
            color: #006 !important;
        }
        
        .timestamp-label {
            color: #006 !important;
            font-weight: bold !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$is_logged_in): ?>
            <!-- Login Form -->
            <div class="login-container">
                <div class="login-title">AVG REGISTER - Login</div>
                
                <div class="messages">
                    <?php if ($error): ?>
                        <div class="error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                </div>
                
                <form method="POST" class="login-form">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group">
                        <label class="form-label">Username:</label>
                        <input type="text" name="username" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Password:</label>
                        <input type="password" name="password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn">Login</button>
                    </div>
                </form>
                
                <div style="text-align: center; margin-top: 15px; font-size: 10px; color: #666;">
                    Default admin: admin / admin123
                </div>
            </div>
            
        <?php else: ?>
            <!-- Main Application -->
            <div class="header">
                <div class="title">
                    <a href="?" class="home-btn">
                        <span class="home-icon"></span>
                        Home
                    </a>
                    <span>- AVG REGISTER -</span>
                </div>
                <div class="user-info">
                    <div>
                        <strong><?php echo htmlspecialchars($current_user['full_name']); ?></strong>
                        <div style="font-size: 10px;"><?php echo htmlspecialchars($current_user['username']); ?></div>
                    </div>
                    <div class="user-role role-<?php echo htmlspecialchars($current_user['role']); ?>">
                        <?php echo strtoupper(htmlspecialchars($current_user['role'])); ?>
                    </div>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn">Logout</button>
                    </form>
                </div>
            </div>
            
            <div class="messages">
                <?php if ($error): ?>
                    <div class="error">Error: <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
            </div>
            
            
            
            <!-- Changes View -->
            <?php if ($show_changes && !empty($changes)): ?>
                <div class="changes-container">
                    <div class="form-container">
                        <div class="form-title" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Recent Changes (Last 100)</span>
                            <a href="?" class="btn">Back to Data</a>
                        </div>
                        
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Action</th>
                                    <th>Table</th>
                                    <th>Record ID</th>
                                    <th>User</th>
                                    <th>IP Address (IPv4)</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($changes as $change): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($change['changed_at']); ?></td>
                                        <td>
                                            <span class="change-action action-<?php echo htmlspecialchars($change['action']); ?>">
                                                <?php echo get_action_name($change['action']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($change['table_name']); ?></td>
                                        <td><?php echo htmlspecialchars($change['record_id']); ?></td>
                                        <td><?php echo htmlspecialchars($change['username'] . ' (' . $change['full_name'] . ')'); ?></td>
                                        <td class="ipv4-format"><?php echo htmlspecialchars($change['user_ip']); ?></td>
                                        <td>
                                            <button onclick="showChangeDetails(<?php echo htmlspecialchars(json_encode($change)); ?>)" class="btn">View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <script>
                function showChangeDetails(change) {
                    let details = `<div class="change-details">
                        <div><strong>Action:</strong> <span class="change-action action-${change.action}">${change.action}</span></div>
                        <div><strong>Table:</strong> ${change.table_name}</div>
                        <div><strong>Record ID:</strong> ${change.record_id}</div>
                        <div><strong>Changed by:</strong> ${change.username} (${change.full_name})</div>
                        <div><strong>Time:</strong> ${change.changed_at}</div>
                        <div><strong>IP (IPv4):</strong> <span class="ipv4-format">${change.user_ip}</span></div>
                        <div><strong>User Agent:</strong> ${change.user_agent || 'Not recorded'}</div>`;
                    
                    if (change.changed_fields) {
                        details += `<div><strong>Changed fields:</strong> ${change.changed_fields}</div>`;
                    }
                    
                    if (change.old_data || change.new_data) {
                        details += `<div class="diff-view">`;
                        
                        if (change.old_data && change.action !== 'INSERT') {
                            details += `<div><strong>Old Data:</strong></div>`;
                            for (let key in change.old_data) {
                                details += `<div class="change-field">
                                    <span class="old-value">${key}: ${change.old_data[key]}</span>
                                </div>`;
                            }
                        }
                        
                        if (change.new_data && change.action !== 'DELETE') {
                            details += `<div><strong>New Data:</strong></div>`;
                            for (let key in change.new_data) {
                                details += `<div class="change-field">
                                    <span class="new-value">${key}: ${change.new_data[key]}</span>
                                </div>`;
                            }
                        }
                        
                        if (change.action === 'UPDATE' && change.old_data && change.new_data) {
                            details += `<div><strong>Changes:</strong></div>`;
                            for (let key in change.old_data) {
                                if (change.new_data[key] !== undefined && change.old_data[key] !== change.new_data[key]) {
                                    details += `<div class="change-field">
                                        <span class="old-value">${key}: ${change.old_data[key]}</span>
                                        <span class="change-arrow">→</span>
                                        <span class="new-value">${change.new_data[key]}</span>
                                    </div>`;
                                }
                            }
                        }
                        
                        details += `</div>`;
                    }
                    
                    details += `</div>`;
                    
                    const modal = document.createElement('div');
                    modal.className = 'modal';
                    modal.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background-color: rgba(255,255,255,0.9);
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        z-index: 1000;
                    `;
                    
                    const modalContent = document.createElement('div');
                    modalContent.className = 'form-container';
                    modalContent.style.cssText = `
                        max-width: 800px;
                        width: 90%;
                        max-height: 90%;
                        overflow-y: auto;
                    `;
                    
                    modalContent.innerHTML = `<div class="form-title">Change Details</div>${details}`;
                    
                    const closeBtn = document.createElement('button');
                    closeBtn.className = 'btn';
                    closeBtn.textContent = 'Close';
                    closeBtn.style.marginTop = '10px';
                    closeBtn.onclick = function() { modal.remove(); };
                    modalContent.appendChild(closeBtn);
                    
                    modal.appendChild(modalContent);
                    document.body.appendChild(modal);
                    
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            modal.remove();
                        }
                    });
                    
                    modal.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') {
                            modal.remove();
                        }
                    });
                }
                </script>
                
            <?php else: ?>
            
                <!-- Row Counter -->
                <div class="row-counter">
                     <?php echo $result ? $result->num_rows : 0; ?> van <?php echo $total_rows; ?> rijen in database
                    <?php if (isset($column_names)): ?>
                        
                    <?php endif; ?>
                </div>
                
             
                
                <!-- Toolbar -->
                <div class="toolbar">
                    <a href="?" class="btn">Refresh</a>
                    
                    <?php if (has_permission('view_changes') || has_permission('view_own_changes')): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="show_changes">
                            <button type="submit" class="btn">Review modus</button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if (has_permission('manage_users')): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="show_user_form">
                            <button type="submit" class="btn">Add New User</button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if (has_permission('add')): ?>
                        <a href="?add=1" class="btn">Add New Record</a>
                    <?php endif; ?>
                    
                    <div class="toggle-view">
                        <button onclick="toggleCompactView()" class="btn">Toggle Compact View</button>
                    </div>
                </div>
                
                
                
                <!-- Add/Edit Record Form -->
                <?php if ((has_permission('add') && isset($_GET['add'])) || (has_permission('edit') && $edit_row)): ?>
                    <div class="form-container" id="add-form" style="display: block;">
                        <div class="form-title">
                            <?php echo $edit_row ? 'Edit Record' : 'Add New Record'; ?>
                        </div>
                        
                        <div class="creation-time">
                            📝 <strong>Timestamps:</strong>
                            <br>• <strong>created_at:</strong> <?php echo $edit_row ? 'Behouden oorspronkelijke datum' : 'Automatisch ingesteld op ' . date('Y-m-d H:i:s'); ?>
                            <br>• <strong>updated_at:</strong> <?php echo $edit_row ? 'Automatisch bijgewerkt naar ' . date('Y-m-d H:i:s') : 'Automatisch ingesteld op ' . date('Y-m-d H:i:s'); ?>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="<?php echo $edit_row ? 'edit' : 'add'; ?>">
                            <?php if ($edit_row): ?>
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_row['id']); ?>">
                            <?php endif; ?>
                            
                            <div class="form-grid">
                                <?php if ($columns): ?>
                                    <?php foreach ($columns as $col): ?>
                                        <?php 
                                        $col_name = $col['Field'];
                                        if ($col_name === 'id' || strpos($col['Extra'], 'auto_increment') !== false) {
                                            continue;
                                        }
                                        
                                        $is_encrypted = in_array($col_name, $rot47_columns);
                                        $is_timestamp = ($col_name === 'created_at' || $col_name === 'updated_at');
                                        ?>
                                        <div class="form-group">
                                            <label class="form-label <?php if ($is_timestamp): ?>timestamp-label<?php endif; ?>">
                                                <?php echo htmlspecialchars($col_name); ?> 
                                                (<?php echo htmlspecialchars($col['Type']); ?>)
                                                <?php if ($is_encrypted): ?>
                                                    <span class="encrypted-indicator">versleuteld</span>
                                                <?php endif; ?>
                                                <?php if ($is_timestamp): ?>
                                                    <span style="color: #006; font-weight: bold;">[auto]</span>
                                                <?php endif; ?>
                                            </label>
                                            <?php if ($col_name === 'created_at' || $col_name === 'updated_at'): ?>
                                                <input 
                                                    type="text" 
                                                    class="form-input timestamp-field" 
                                                    value="<?php 
                                                        if ($edit_row && $edit_row[$col_name]) {
                                                            echo htmlspecialchars($edit_row[$col_name]);
                                                        } else {
                                                            echo date('Y-m-d H:i:s');
                                                        }
                                                    ?>"
                                                    disabled
                                                    style="background-color: #f5f5f5;"
                                                >
                                                <div style="font-size: 9px; color: #666; margin-top: 2px;">
                                                    <?php if ($col_name === 'created_at'): ?>
                                                        Automatisch ingesteld bij aanmaak
                                                    <?php else: ?>
                                                        Automatisch bijgewerkt bij wijziging
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif (strpos($col['Type'], 'text') !== false || strpos($col['Type'], 'varchar') !== false && (int)str_replace(['varchar(', ')'], '', $col['Type']) > 100): ?>
                                                <textarea 
                                                    name="<?php echo htmlspecialchars($col_name); ?>" 
                                                    class="form-textarea"
                                                    <?php if ($is_encrypted): ?>placeholder="Versleutelde inhoud"<?php endif; ?>
                                                ><?php echo $edit_row ? htmlspecialchars($edit_row[$col_name]) : ''; ?></textarea>
                                            <?php else: ?>
                                                <input 
                                                    type="text" 
                                                    name="<?php echo htmlspecialchars($col_name); ?>" 
                                                    class="form-input" 
                                                    value="<?php echo $edit_row ? htmlspecialchars($edit_row[$col_name]) : ''; ?>"
                                                    <?php if ($is_encrypted): ?>placeholder="Versleutelde inhoud"<?php endif; ?>
                                                >
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn"><?php echo $edit_row ? 'Update' : 'Add'; ?> Record</button>
                                <a href="?" class="btn">Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="form-container" id="add-form" style="display: none;">
                        <!-- Form will be shown when "Add New Record" is clicked -->
                    </div>
                <?php endif; ?>
                
               
                
                <!-- Data Table -->
                <?php if (has_permission('view')): ?>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <div class="data-container" id="data-container">
                            <table class="table" id="data-table">
                                <thead>
                                    <tr>
                                        <?php foreach ($columns as $col): ?>
                                            <?php 
                                            $col_name = $col['Field'];
                                            $is_valid_column = true;
                                            
                                            $sort_url = "?";
                                            if ($is_valid_column) {
                                                $sort_url = "?sort=" . urlencode($col_name) . "&dir=";
                                                $sort_url .= ($sort_column === $col_name && $sort_direction === 'ASC') ? 'desc' : 'asc';
                                            }
                                            $is_encrypted = in_array($col_name, $rot47_columns);
                                            $is_timestamp = ($col_name === 'created_at' || $col_name === 'updated_at');
                                            ?>
                                            <th>
                                                <?php if ($is_valid_column): ?>
                                                    <a href="<?php echo $sort_url; ?>" style="color: #000; text-decoration: none;">
                                                        <?php echo htmlspecialchars($col_name); ?>
                                                        <?php if ($is_timestamp): ?>
                                                            <span style="color: #006; font-weight: bold;">[auto]</span>
                                                        <?php endif; ?>
                                                        <?php if ($is_encrypted): ?>
                                                            <span class="encrypted-badge">[E]</span>
                                                        <?php endif; ?>
                                                        <?php if ($sort_column === $col_name): ?>
                                                            <?php echo $sort_direction === 'ASC' ? '↑' : '↓'; ?>
                                                        <?php endif; ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($col_name); ?>
                                                    <?php if ($is_timestamp): ?>
                                                        <span style="color: #006; font-weight: bold;">[auto]</span>
                                                    <?php endif; ?>
                                                    <?php if ($is_encrypted): ?>
                                                        <span class="encrypted-badge">[E]</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </th>
                                        <?php endforeach; ?>
                                        <?php if (has_permission('edit') || has_permission('delete')): ?>
                                            <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $row_counter = 0;
                                    while ($row = $result->fetch_assoc()): 
                                        $row_counter++;
                                    ?>
                                        <tr>
                                            <?php foreach ($columns as $col): ?>
                                                <?php 
                                                $col_name = $col['Field'];
                                                $value = $row[$col_name];
                                                $is_encrypted = in_array($col_name, $rot47_columns);
                                                if ($is_encrypted && $value !== null) {
                                                    $value = rot47_decrypt($value);
                                                }
                                                
                                                $is_timestamp = ($col_name === 'created_at' || $col_name === 'updated_at');
                                                $is_created_at = ($col_name === 'created_at');
                                                $is_updated_at = ($col_name === 'updated_at');
                                                ?>
                                                <td title="<?php echo htmlspecialchars($value); ?>" 
                                                    <?php if ($is_timestamp): ?>class="timestamp-field"<?php endif; ?>
                                                    <?php if ($is_timestamp): ?>style="background-color: #f9f9f9; font-family: 'Courier New', monospace; color: #006;"<?php endif; ?>>
                                                    <?php 
                                                    if (strlen($value) > 50 && !$is_timestamp) {
                                                        echo htmlspecialchars(substr($value, 0, 47)) . '...';
                                                    } else {
                                                        echo htmlspecialchars($value);
                                                    }
                                                    ?>
                                                    <?php if ($is_encrypted && $value !== ''): ?>
                                                        <span class="encrypted-check">✓</span>
                                                    <?php endif; ?>
                                                    <?php if ($is_created_at): ?>
                                                        <div style="font-size: 8px; color: #666; margin-top: 2px;">
                                                            Aangemaakt
                                                        </div>
                                                    <?php elseif ($is_updated_at): ?>
                                                        <div style="font-size: 8px; color: #666; margin-top: 2px;">
                                                            Laatste wijziging
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <?php if (has_permission('edit') || has_permission('delete')): ?>
                                                <td class="actions">
                                                    <?php if (has_permission('edit')): ?>
                                                        <a href="?edit=<?php echo htmlspecialchars($row['id']); ?>" class="btn">Edit</a>
                                                    <?php endif; ?>
                                                    <?php if (has_permission('delete')): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($row['id']); ?>">
                                                            <button type="submit" class="btn" onclick="return confirm('Delete this record?')">Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="status-bar">
                            <div>Showing ALL <?php echo $row_counter; ?> records (Total in DB: <?php echo $total_rows; ?>)</div>
                            <div>Encrypted columns: <?php echo count($rot47_columns); ?> of <?php echo count($columns); ?></div>
                            <div>User: <?php echo htmlspecialchars($current_user['username']); ?> (<?php echo htmlspecialchars($current_user['role']); ?>)</div>
                        </div>
                        
                    <?php elseif ($result && $result->num_rows === 0): ?>
                        <div class="error" style="text-align: center;">
                            No records found in the table. Database appears to be empty.
                        </div>
                    <?php elseif ($error): ?>
                        <div class="error" style="text-align: center;">
                            Cannot display data due to connection error: <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="error" style="text-align: center;">
                        You don't have permission to view data.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
	
	
	<!-- Add New User Form (now under toolbar) -->
                <?php if ($show_user_form && has_permission('manage_users')): ?>
                    <div class="form-container user-management-section" id="add-user-form">
                        <div class="form-title">
                            Add New User
                            <button type="button" class="btn" onclick="hideUserForm()" style="float: right; margin-top: -5px;">Close</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="user_action" value="add_user">
                            
                            <div class="user-form-grid">
                                <div class="form-group">
                                    <label class="form-label">Username *</label>
                                    <input type="text" name="username" class="form-input" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Password *</label>
                                    <input type="password" name="password" class="form-input" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-input">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-input">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-input">
                                        <option value="admin">Admin</option>
                                        <option value="editor">Editor</option>
                                        <option value="viewer" selected>Viewer</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn">Add User</button>
                                <button type="button" class="btn" onclick="hideUserForm()">Cancel</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
				
				
				
				
				
				
				
				 <!-- Users List Table (if users exist and user has permission) -->
                <?php if (has_permission('manage_users') && !empty($users_list)): ?>
                    <div class="form-container users-management">
                        <div class="form-title">User Management - Existing Users</div>
                        
                        <div class="users-table">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Active</th>
                                        <th>Last Login</th>
                                        <th>Updated At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users_list as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><span class="user-role role-<?php echo htmlspecialchars($user['role']); ?>">
                                                <?php echo strtoupper(htmlspecialchars($user['role'])); ?>
                                            </span></td>
                                            <td><?php echo $user['is_active'] ? 'Yes' : 'No'; ?></td>
                                            <td><?php echo htmlspecialchars($user['last_login']); ?></td>
                                            <td><?php echo htmlspecialchars($user['updated_at']); ?></td>
                                            <td class="actions">
                                                <button onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="btn">Edit</button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                                    <button type="submit" class="btn" onclick="return confirm('Delete user <?php echo htmlspecialchars($user['username']); ?>?')">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
    
    <script>
        function toggleCompactView() {
            const container = document.getElementById('data-container');
            const table = document.getElementById('data-table');
            
            if (container && table) {
                if (container.classList.contains('compact-view')) {
                    container.classList.remove('compact-view');
                    table.classList.remove('compact-view');
                } else {
                    container.classList.add('compact-view');
                    table.classList.add('compact-view');
                }
            }
        }
        
        function hideUserForm() {
            const userForm = document.getElementById('add-user-form');
            if (userForm) {
                userForm.style.display = 'none';
            }
            // You can also redirect to clear the form state
            window.location.href = '?';
        }
        
        setTimeout(function() {
            const successMessages = document.querySelectorAll('.success');
            successMessages.forEach(function(msg) {
                msg.style.display = 'none';
            });
        }, 5000);
        
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('edit') || urlParams.has('add')) {
                const addForm = document.getElementById('add-form');
                if (addForm) {
                    addForm.scrollIntoView({ behavior: 'smooth' });
                }
            }
            
            if (document.getElementById('data-table')) {
                const rows = document.getElementById('data-table').getElementsByTagName('tr');
                if (rows.length > 50) {
                    window.scrollTo(0, 0);
                }
            }
        });
        
        function editUser(user) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="user_action" value="edit_user">
                <input type="hidden" name="user_id" value="${user.id}">
                
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" value="${user.username}" class="form-input" disabled>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" value="${user.email || ''}" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" value="${user.full_name || ''}" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-input">
                        <option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Admin</option>
                        <option value="editor" ${user.role === 'editor' ? 'selected' : ''}>Editor</option>
                        <option value="viewer" ${user.role === 'viewer' ? 'selected' : ''}>Viewer</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" ${user.is_active ? 'checked' : ''}>
                        Active
                    </label>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Updated At</label>
                    <input type="text" value="${user.updated_at || ''}" class="form-input" disabled style="background-color: #f5f5f5;">
                    <div style="font-size: 9px; color: #666; margin-top: 2px;">Automatisch bijgewerkt bij wijziging</div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn">Update User</button>
                    <button type="button" class="btn" onclick="this.closest('.modal').remove()">Cancel</button>
                </div>
            `;
            
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(255,255,255,0.9);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 1000;
            `;
            
            const modalContent = document.createElement('div');
            modalContent.className = 'form-container';
            modalContent.style.cssText = `
                max-width: 500px;
                width: 90%;
                max-height: 90%;
                overflow-y: auto;
            `;
            
            modalContent.innerHTML = '<div class="form-title">Edit User</div>';
            modalContent.appendChild(form);
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
            
            modal.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    modal.remove();
                }
            });
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
    </script>
</body>
</html>

