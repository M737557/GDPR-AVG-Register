<?php
session_start();
date_default_timezone_set('Europe/Amsterdam');

$db_config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'database_name',
    'users_table' => 'system_users'
];

$connection = null;
$error = '';
$success = '';
$is_logged_in = false;
$current_user = null;
$show_user_form = false;

if (isset($_SESSION['user_id'])) {
    $is_logged_in = true;
    $current_user = $_SESSION['user'];
}

// MFA Functions
function genSecret() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) {
        $secret .= $chars[rand(0, 31)];
    }
    return $secret;
}

function verifyTOTP($code, $secret) {
    if (strlen($code) != 6 || !is_numeric($code)) {
        return false;
    }
    if (empty($secret)) {
        return false;
    }
    
    $time = floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        $calc = calcTOTP($secret, $time + $i);
        if (hash_equals($calc, $code)) {
            return true;
        }
    }
    return false;
}

function calcTOTP($secret, $timestamp) {
    $key = base32_decode($secret);
    if (!$key) {
        return '000000';
    }
    
    $time = pack('N*', 0) . pack('N*', $timestamp);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0xF;
    $result = (ord($hash[$offset]) & 0x7F) << 24 |
              (ord($hash[$offset + 1]) & 0xFF) << 16 |
              (ord($hash[$offset + 2]) & 0xFF) << 8 |
              (ord($hash[$offset + 3]) & 0xFF);
    return str_pad($result % 1000000, 6, '0', STR_PAD_LEFT);
}

function base32_decode($secret) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
    
    for ($i = 0; $i < strlen($secret); $i++) {
        $value = strpos($chars, $secret[$i]);
        if ($value === false) {
            continue;
        }
        
        $buffer <<= 5;
        $buffer |= $value;
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $output .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
            $bitsLeft -= 8;
        }
    }
    return $output;
}

// Password Functions
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Database Functions - UPDATED with proper table creation
function create_users_table($connection, $table_name) {
    // First check if table exists and has the new columns
    $check_sql = "SHOW TABLES LIKE '$table_name'";
    $result = $connection->query($check_sql);
    
    if ($result && $result->num_rows > 0) {
        // Table exists, check for mfa columns
        $check_columns_sql = "SHOW COLUMNS FROM $table_name LIKE 'mfa_enabled'";
        $columns_result = $connection->query($check_columns_sql);
        
        if (!$columns_result || $columns_result->num_rows == 0) {
            // Add MFA columns if they don't exist
            $alter_sql = "ALTER TABLE $table_name 
                          ADD COLUMN mfa_enabled BOOLEAN DEFAULT FALSE,
                          ADD COLUMN mfa_secret VARCHAR(32) DEFAULT NULL";
            $connection->query($alter_sql);
        }
    } else {
        // Create new table with all columns
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            full_name VARCHAR(100),
            role ENUM('admin', 'editor', 'viewer') DEFAULT 'viewer',
            mfa_enabled BOOLEAN DEFAULT FALSE,
            mfa_secret VARCHAR(32),
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
                $insert_sql = "INSERT INTO $table_name (username, password, email, full_name, role, mfa_enabled) 
                              VALUES ('admin', '$default_password', 'admin@example.com', 'Administrator', 'admin', FALSE)";
                $connection->query($insert_sql);
            }
        }
    }
    return true;
}

// Handle Login with MFA
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
            
            $username = $connection->real_escape_string($_POST['username']);
            $password = $_POST['password'];
            
            $sql = "SELECT * FROM {$db_config['users_table']} WHERE username = '$username' AND is_active = TRUE LIMIT 1";
            $result = $connection->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                if (verify_password($password, $user['password'])) {
                    // Initialize MFA fields if they don't exist
                    if (!isset($user['mfa_enabled'])) {
                        $user['mfa_enabled'] = false;
                        $user['mfa_secret'] = null;
                    }
                    
                    // Check if MFA is enabled
                    if ($user['mfa_enabled'] && !empty($user['mfa_secret'])) {
                        // Store user in session for MFA verification
                        $_SESSION['temp_user'] = $user;
                        $_SESSION['mfa_required'] = true;
                        
                        header("Location: ?mfa=verify");
                        exit();
                    } else {
                        // No MFA required, log in directly
                        $update_sql = "UPDATE {$db_config['users_table']} SET last_login = NOW() WHERE id = {$user['id']}";
                        $connection->query($update_sql);
                        
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user'] = $user;
                        
                        $is_logged_in = true;
                        $current_user = $user;
                        $success = 'Login successful';
                        
                        header("Location: ?");
                        exit();
                    }
                } else {
                    $error = 'Invalid password';
                }
            } else {
                $error = 'Invalid username or password';
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    // Handle MFA Verification
    elseif (isset($_POST['action']) && $_POST['action'] === 'verify_mfa') {
        if (!isset($_SESSION['temp_user'])) {
            $error = 'Session expired';
            header("Location: ?");
            exit();
        }
        
        $code = $_POST['code'] ?? '';
        $user = $_SESSION['temp_user'];
        
        // Initialize MFA fields if they don't exist
        if (!isset($user['mfa_secret'])) {
            $user['mfa_secret'] = '';
        }
        
        if (!verifyTOTP($code, $user['mfa_secret'])) {
            $error = 'Invalid verification code';
        } else {
            // MFA verified, complete login
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
                
                $update_sql = "UPDATE {$db_config['users_table']} SET last_login = NOW() WHERE id = {$user['id']}";
                $connection->query($update_sql);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user'] = $user;
                unset($_SESSION['temp_user'], $_SESSION['mfa_required']);
                
                $is_logged_in = true;
                $current_user = $user;
                $success = 'Login successful';
                
                header("Location: ?");
                exit();
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'logout') {
        session_destroy();
        header("Location: ?");
        exit();
    }
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'show_user_form') {
        $show_user_form = true;
    }
    
    // Handle User Management Actions
    elseif ($is_logged_in && $current_user['role'] === 'admin') {
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
            
            // Ensure table has MFA columns
            create_users_table($connection, $db_config['users_table']);
            
            if (isset($_POST['user_action'])) {
                switch ($_POST['user_action']) {
                    case 'add_user':
                        $username = $connection->real_escape_string($_POST['username']);
                        $password = hash_password($_POST['password']);
                        $email = $connection->real_escape_string($_POST['email']);
                        $full_name = $connection->real_escape_string($_POST['full_name']);
                        $role = $connection->real_escape_string($_POST['role']);
                        $mfa_enabled = isset($_POST['mfa_enabled']) ? 1 : 0;
                        $mfa_secret = $mfa_enabled ? genSecret() : '';
                        
                        $sql = "INSERT INTO {$db_config['users_table']} (username, password, email, full_name, role, mfa_enabled, mfa_secret) 
                                VALUES ('$username', '$password', '$email', '$full_name', '$role', $mfa_enabled, '$mfa_secret')";
                        
                        if ($connection->query($sql)) {
                            $success = 'User added successfully';
                            if ($mfa_enabled) {
                                $success .= '. MFA enabled with secret: ' . $mfa_secret;
                            }
                            $show_user_form = false;
                        } else {
                            $error = "Error adding user: " . $connection->error;
                            $show_user_form = true;
                        }
                        break;
                        
                    case 'edit_user':
                        $user_id = intval($_POST['user_id']);
                        $email = $connection->real_escape_string($_POST['email']);
                        $full_name = $connection->real_escape_string($_POST['full_name']);
                        $role = $connection->real_escape_string($_POST['role']);
                        $is_active = isset($_POST['is_active']) ? 1 : 0;
                        
                        $sql = "UPDATE {$db_config['users_table']} SET 
                                email = '$email', 
                                full_name = '$full_name', 
                                role = '$role',
                                is_active = $is_active,
                                updated_at = CURRENT_TIMESTAMP
                                WHERE id = $user_id";
                        
                        if ($connection->query($sql)) {
                            $success = 'User updated successfully';
                        } else {
                            $error = "Error updating user: " . $connection->error;
                        }
                        break;
                        
                    case 'delete_user':
                        $user_id = intval($_POST['user_id']);
                        
                        if ($user_id == $current_user['id']) {
                            $error = 'You cannot delete your own account!';
                        } else {
                            $sql = "DELETE FROM {$db_config['users_table']} WHERE id = $user_id";
                            if ($connection->query($sql)) {
                                $success = 'User deleted successfully';
                            } else {
                                $error = "Error deleting user: " . $connection->error;
                            }
                        }
                        break;
                        
                    case 'reset_password':
                        $user_id = intval($_POST['user_id']);
                        $new_password = $_POST['new_password'];
                        $confirm_password = $_POST['confirm_password'];
                        
                        if ($new_password !== $confirm_password) {
                            $error = 'Passwords do not match';
                            break;
                        }
                        
                        if (strlen($new_password) < 6) {
                            $error = 'Password must be at least 6 characters long';
                            break;
                        }
                        
                        $hashed_password = hash_password($new_password);
                        $sql = "UPDATE {$db_config['users_table']} SET 
                                password = '$hashed_password',
                                updated_at = CURRENT_TIMESTAMP
                                WHERE id = $user_id";
                        
                        if ($connection->query($sql)) {
                            $success = 'Password reset successfully';
                        } else {
                            $error = "Error resetting password: " . $connection->error;
                        }
                        break;
                        
                    case 'toggle_mfa':
                        $user_id = intval($_POST['user_id']);
                        $action = $_POST['mfa_action'];
                        
                        if ($action === 'enable') {
                            $mfa_secret = genSecret();
                            $sql = "UPDATE {$db_config['users_table']} SET 
                                    mfa_enabled = TRUE, 
                                    mfa_secret = '$mfa_secret',
                                    updated_at = CURRENT_TIMESTAMP
                                    WHERE id = $user_id";
                            $success_msg = 'MFA enabled successfully. Secret: ' . $mfa_secret;
                        } elseif ($action === 'disable') {
                            $sql = "UPDATE {$db_config['users_table']} SET 
                                    mfa_enabled = FALSE, 
                                    mfa_secret = NULL,
                                    updated_at = CURRENT_TIMESTAMP
                                    WHERE id = $user_id";
                            $success_msg = 'MFA disabled successfully';
                        } elseif ($action === 'regenerate') {
                            $mfa_secret = genSecret();
                            $sql = "UPDATE {$db_config['users_table']} SET 
                                    mfa_secret = '$mfa_secret',
                                    updated_at = CURRENT_TIMESTAMP
                                    WHERE id = $user_id";
                            $success_msg = 'MFA secret regenerated: ' . $mfa_secret;
                        }
                        
                        if (isset($sql) && $connection->query($sql)) {
                            $success = $success_msg;
                        } else {
                            $error = "Error updating MFA: " . $connection->error;
                        }
                        break;
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Load users list if logged in as admin
if ($is_logged_in && !$connection) {
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
        
        // Ensure table has MFA columns
        create_users_table($connection, $db_config['users_table']);
        
        $users_list = [];
        if ($current_user['role'] === 'admin') {
            $users_result = $connection->query("SELECT * FROM {$db_config['users_table']} ORDER BY username");
            if ($users_result) {
                while ($user = $users_result->fetch_assoc()) {
                    // Ensure MFA fields exist
                    if (!isset($user['mfa_enabled'])) {
                        $user['mfa_enabled'] = false;
                    }
                    if (!isset($user['mfa_secret'])) {
                        $user['mfa_secret'] = null;
                    }
                    $users_list[] = $user;
                }
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($connection) {
    $connection->close();
}

// Show MFA Verification Page
if (isset($_GET['mfa']) && $_GET['mfa'] === 'verify' && isset($_SESSION['temp_user'])) {
    showMFAPage();
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #fff; color: #000; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .login-container { max-width: 400px; margin: 100px auto; padding: 2rem; border: 1px solid #000; }
        .login-title { text-align: center; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; }
        input, select { width: 100%; padding: 0.75rem; border: 1px solid #000; }
        .btn { padding: 0.75rem 1.5rem; background: #000; color: #fff; border: 1px solid #000; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #fff; color: #000; }
        .btn-outline { background: #fff; color: #000; }
        .btn-outline:hover { background: #000; color: #fff; }
        .btn-primary { background: #0066cc; border-color: #0066cc; }
        .btn-primary:hover { background: #0052a3; }
        .btn-success { background: #090; border-color: #090; }
        .btn-danger { background: #900; border-color: #900; }
        .nav { display: flex; gap: 1rem; margin: 20px 0; flex-wrap: wrap; }
        .alert { padding: 1rem; border: 1px solid #000; margin: 1rem 0; }
        .alert-danger { background: #fff0f0; border-color: #d00; }
        .alert-success { background: #f0fff0; border-color: #0d0; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { padding: 0.75rem; border: 1px solid #000; text-align: left; }
        th { background: #f0f0f0; }
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: #fff; padding: 2rem; max-width: 500px; width: 90%; border: 1px solid #000; }
        .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .user-info { background: #f8f8f8; padding: 1rem; border: 1px solid #000; margin: 1rem 0; }
        .role-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 3px; font-size: 0.8rem; font-weight: bold; }
        .role-admin { background: #ff4444; color: white; }
        .role-editor { background: #ffaa00; color: white; }
        .role-viewer { background: #4444ff; color: white; }
        .mfa-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 3px; font-size: 0.8rem; font-weight: bold; margin-left: 0.5rem; }
        .mfa-enabled { background: #090; color: white; }
        .mfa-disabled { background: #900; color: white; }
        .checkbox-label { display: flex; align-items: center; gap: 0.5rem; margin: 0.5rem 0; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$is_logged_in && !isset($_GET['mfa'])): ?>
            <div class="login-container">
                <div class="login-title">Login</div>
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Password:</label>
                        <input type="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn">Login</button>
                    </div>
                </form>
                <div style="text-align: center; margin-top: 15px; font-size: 0.9rem; color: #666;">
                    Default admin: admin / admin123
                </div>
            </div>
        <?php else: ?>
            <?php if ($is_logged_in): ?>
                <h1>User Management System</h1>
                
                <div class="user-info">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong>Logged in as: <?= htmlspecialchars($current_user['full_name']) ?></strong>
                            <div>Username: <?= htmlspecialchars($current_user['username']) ?></div>
                            <div>Role: <span class="role-badge role-<?= $current_user['role'] ?>"><?= ucfirst($current_user['role']) ?></span></div>
                            <div>MFA: <span class="mfa-badge <?= (isset($current_user['mfa_enabled']) && $current_user['mfa_enabled']) ? 'mfa-enabled' : 'mfa-disabled' ?>">
                                <?= (isset($current_user['mfa_enabled']) && $current_user['mfa_enabled']) ? 'ENABLED' : 'DISABLED' ?>
                            </span></div>
                        </div>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="btn btn-outline">Logout</button>
                        </form>
                    </div>
                </div>
                
                <div class="nav">
                    <a href="?" class="btn">Home</a>
                    <?php if ($current_user['role'] === 'admin'): ?>
                        <button onclick="showAddUserForm()" class="btn">Add New User</button>
                    <?php endif; ?>
                </div>
                
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                
                <?php if ($current_user['role'] === 'admin'): ?>
                    <h2>Existing Users</h2>
                    <?php if (!empty($users_list)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>MFA</th>
                                    <th>Active</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users_list as $user): ?>
                                    <tr>
                                        <td><?= $user['id'] ?></td>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                        <td><?= htmlspecialchars($user['full_name']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><span class="role-badge role-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span></td>
                                        <td>
                                            <span class="mfa-badge <?= (isset($user['mfa_enabled']) && $user['mfa_enabled']) ? 'mfa-enabled' : 'mfa-disabled' ?>">
                                                <?= (isset($user['mfa_enabled']) && $user['mfa_enabled']) ? '✓' : '✗' ?>
                                            </span>
                                        </td>
                                        <td><?= $user['is_active'] ? 'Yes' : 'No' ?></td>
                                        <td><?= htmlspecialchars($user['last_login']) ?></td>
                                        <td class="actions">
                                            <button onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)" class="btn btn-outline">Edit</button>
                                            <button onclick="resetPassword(<?= $user['id'] ?>)" class="btn btn-outline">Reset Password</button>
                                            <button onclick="manageMFA(<?= htmlspecialchars(json_encode($user)) ?>)" class="btn btn-outline">MFA</button>
                                            <?php if ($user['id'] != $current_user['id']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="user_action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this user?')">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No users found.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
    function showAddUserForm() {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content">
                <h2>Add New User</h2>
                <form method="POST">
                    <input type="hidden" name="user_action" value="add_user">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email">
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role">
                            <option value="admin">Admin</option>
                            <option value="editor">Editor</option>
                            <option value="viewer" selected>Viewer</option>
                        </select>
                    </div>
                    <div class="checkbox-label">
                        <input type="checkbox" name="mfa_enabled" id="mfa_enabled">
                        <label for="mfa_enabled">Enable MFA for this user</label>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn">Add User</button>
                        <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Cancel</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    function editUser(user) {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content">
                <h2>Edit User</h2>
                <form method="POST">
                    <input type="hidden" name="user_action" value="edit_user">
                    <input type="hidden" name="user_id" value="${user.id}">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" value="${user.username}" disabled>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="${user.email || ''}">
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" value="${user.full_name || ''}">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role">
                            <option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Admin</option>
                            <option value="editor" ${user.role === 'editor' ? 'selected' : ''}>Editor</option>
                            <option value="viewer" ${user.role === 'viewer' ? 'selected' : ''}>Viewer</option>
                        </select>
                    </div>
                    <div class="checkbox-label">
                        <input type="checkbox" name="is_active" id="is_active_${user.id}" ${user.is_active ? 'checked' : ''}>
                        <label for="is_active_${user.id}">Active</label>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn">Update</button>
                        <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Cancel</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    function resetPassword(userId) {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content">
                <h2>Reset Password</h2>
                <form method="POST">
                    <input type="hidden" name="user_action" value="reset_password">
                    <input type="hidden" name="user_id" value="${userId}">
                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="new_password" required minlength="6">
                        <small style="color: #666;">Minimum 6 characters</small>
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password *</label>
                        <input type="password" name="confirm_password" required minlength="6">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn">Reset Password</button>
                        <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Cancel</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    function manageMFA(user) {
        const mfaEnabled = user.mfa_enabled || false;
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content">
                <h2>Manage MFA for ${user.username}</h2>
                <div style="margin-bottom: 1rem;">
                    <strong>Current Status:</strong> 
                    <span class="mfa-badge ${mfaEnabled ? 'mfa-enabled' : 'mfa-disabled'}">
                        ${mfaEnabled ? 'ENABLED' : 'DISABLED'}
                    </span>
                </div>
                <form method="POST">
                    <input type="hidden" name="user_action" value="toggle_mfa">
                    <input type="hidden" name="user_id" value="${user.id}">
                    <div class="form-group">
                        <label>Action:</label>
                        <select name="mfa_action" required>
                            <option value="">Select Action</option>
                            <option value="enable">Enable MFA</option>
                            <option value="disable">Disable MFA</option>
                            <option value="regenerate">Regenerate Secret</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn">Execute</button>
                        <button type="button" class="btn btn-outline" onclick="this.closest('.modal').remove()">Cancel</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            e.target.remove();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.querySelector('.modal');
            if (modal) modal.remove();
        }
    });
    </script>
</body>
</html>

<?php
function showMFAPage() {
    $error = $_SESSION['error'] ?? '';
    unset($_SESSION['error']);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MFA Verification</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: Arial, sans-serif; background: #fff; color: #000; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
            .mfa-container { max-width: 400px; padding: 2rem; border: 1px solid #000; }
            .mfa-title { text-align: center; margin-bottom: 1.5rem; }
            .form-group { margin-bottom: 1rem; }
            label { display: block; margin-bottom: 0.5rem; }
            input { width: 100%; padding: 0.75rem; border: 1px solid #000; text-align: center; letter-spacing: 0.5rem; font-size: 1.5rem; }
            .btn { padding: 0.75rem 1.5rem; background: #000; color: #fff; border: 1px solid #000; cursor: pointer; width: 100%; }
            .btn:hover { background: #fff; color: #000; }
            .alert { padding: 1rem; border: 1px solid #000; margin: 1rem 0; }
            .alert-danger { background: #fff0f0; border-color: #d00; }
        </style>
    </head>
    <body>
        <div class="mfa-container">
            <div class="mfa-title">Two-Factor Authentication</div>
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <p style="text-align: center; margin-bottom: 1rem;">Please enter the 6-digit code from your authenticator app:</p>
            <form method="POST">
                <input type="hidden" name="action" value="verify_mfa">
                <div class="form-group">
                    <input type="text" name="code" maxlength="6" pattern="[0-9]{6}" required autofocus placeholder="123456">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn">Verify</button>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
}
?>
