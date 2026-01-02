<?php
// ============================================
// DATABASE CONFIGURATION
// ============================================

// UPDATE THESE VALUES WITH YOUR DATABASE CREDENTIALS
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'onderwijs');
define('DB_TABLE', 'avg_register'); // UPDATE THIS to your table name


// Start session for messages
session_start();

// Initialize variables
$message = '';
$message_type = '';
$records = [];
$total_records = 0;

// Create database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8");
    
    // ============================================
    // HANDLE DELETE REQUESTS
    // ============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
        if (isset($_POST['delete_ids']) && is_array($_POST['delete_ids']) && !empty($_POST['delete_ids'])) {
            $ids_to_delete = array_filter(array_map('intval', $_POST['delete_ids']));
            
            if (!empty($ids_to_delete)) {
                $ids_string = implode(',', $ids_to_delete);
                $delete_sql = "DELETE FROM " . DB_TABLE . " WHERE id IN ($ids_string)";
                
                if ($conn->query($delete_sql)) {
                    $_SESSION['message'] = count($ids_to_delete) . " record(s) deleted successfully!";
                    $_SESSION['message_type'] = 'success';
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                } else {
                    $message = "Error deleting records: " . htmlspecialchars($conn->error);
                    $message_type = 'error';
                }
            }
        }
    }
    
    // Check for session messages
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $message_type = $_SESSION['message_type'];
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
    
    // ============================================
    // FETCH DATA FROM DATABASE
    // ============================================
    $sql = "SELECT id, verwerkingsactiviteit, doel_verwerking 
            FROM " . DB_TABLE . " 
            ORDER BY id DESC";
    
    $result = $conn->query($sql);
    
    if ($result) {
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $records[] = $row;
            }
            $total_records = $result->num_rows;
        }
        $result->free();
    } else {
        $message = "Error fetching data: " . htmlspecialchars($conn->error);
        $message_type = 'error';
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $message = "Error: " . htmlspecialchars($e->getMessage());
    $message_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verwerkingen Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* BLACK AND WHITE MONOCHROME THEME */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #fafafa;
            color: #222;
            line-height: 1.6;
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* HEADER - Black background, white text */
        .header {
            background-color: #000;
            color: #fff;
            padding: 28px 32px;
            margin-bottom: 24px;
            border: 1px solid #ddd;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 500;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header h1 i {
            color: #fff;
            font-size: 22px;
        }
        
        .header p {
            color: #ccc;
            font-size: 14px;
        }
        
        /* ALERT MESSAGES */
        .alert {
            padding: 14px 18px;
            margin-bottom: 20px;
            border-left: 3px solid;
            background-color: #fff;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            border-left-color: #000;
            background-color: #f9f9f9;
        }
        
        .alert-error {
            border-left-color: #666;
            background-color: #f5f5f5;
        }
        
        .alert-warning {
            border-left-color: #888;
            background-color: #f7f7f7;
        }
        
        /* TOOLBAR - Light gray background */
        .toolbar {
            background-color: #fff;
            padding: 18px 24px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .selection-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .select-all {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .selected-count {
            background-color: #f0f0f0;
            padding: 5px 12px;
            border-radius: 3px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #ddd;
        }
        
        /* BUTTONS - Black and white */
        .btn {
            padding: 9px 20px;
            border: 1px solid #000;
            background-color: #fff;
            color: #000;
            font-weight: 500;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.15s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn:hover {
            background-color: #000;
            color: #fff;
        }
        
        .btn:active {
            transform: translateY(1px);
        }
        
        .btn:disabled {
            border-color: #aaa;
            color: #aaa;
            cursor: not-allowed;
            background-color: #fff;
        }
        
        .btn:disabled:hover {
            background-color: #fff;
            color: #aaa;
        }
        
        .btn-secondary {
            border-color: #666;
            color: #666;
        }
        
        .btn-secondary:hover {
            background-color: #666;
            color: #fff;
        }
        
        /* TABLE - Clean black and white */
        .table-container {
            background-color: #fff;
            border: 1px solid #ddd;
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background-color: #f5f5f5;
            border-bottom: 2px solid #000;
        }
        
        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #000;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #ddd;
        }
        
        th.checkbox-col {
            width: 50px;
            text-align: center;
        }
        
        th.id-col {
            width: 70px;
        }
        
        td {
            padding: 16px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        td.checkbox-col {
            text-align: center;
        }
        
        td.id-col {
            font-family: 'SF Mono', 'Menlo', monospace;
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }
        
        .text-content {
            max-height: 80px;
            overflow-y: auto;
            padding-right: 10px;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .text-content::-webkit-scrollbar {
            width: 4px;
        }
        
        .text-content::-webkit-scrollbar-thumb {
            background-color: #aaa;
            border-radius: 2px;
        }
        
        tbody tr:hover {
            background-color: #f9f9f9;
        }
        
        tbody tr.selected {
            background-color: #f0f0f0;
        }
        
        /* CHECKBOXES - Black outline */
        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            border: 1px solid #000;
            border-radius: 2px;
        }
        
        input[type="checkbox"]:checked {
            background-color: #000;
            border-color: #000;
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #777;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #ccc;
        }
        
        .empty-state h3 {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 8px;
            color: #333;
        }
        
        .empty-state p {
            max-width: 400px;
            margin: 0 auto;
            color: #666;
        }
        
        /* MODAL - Black and white */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal {
            background-color: #fff;
            width: 400px;
            max-width: 90%;
            border: 1px solid #000;
        }
        
        .modal-header {
            padding: 20px;
            background-color: #000;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header h3 {
            font-size: 16px;
            font-weight: 500;
            flex: 1;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: #fff;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-modal:hover {
            color: #ccc;
        }
        
        .modal-body {
            padding: 24px;
            color: #333;
        }
        
        .modal-body p {
            margin-bottom: 12px;
            line-height: 1.5;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        /* FOOTER */
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 13px;
            border-top: 1px solid #ddd;
            background-color: #fafafa;
        }
        
        .stats {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #555;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            body {
                padding: 16px;
            }
            
            .header {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .toolbar {
                flex-direction: column;
                align-items: stretch;
                padding: 16px;
            }
            
            .selection-info {
                justify-content: space-between;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            th, td {
                padding: 12px 10px;
                font-size: 12px;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            table {
                min-width: 600px;
            }
            
            .stats {
                flex-direction: column;
                gap: 8px;
            }
        }
        
        @media (max-width: 480px) {
            .header h1 {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .modal {
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-database"></i>
                Verwerkingen Management
            </h1>
            <p>Manage processing activities and purposes</p>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php 
                    echo $message_type === 'success' ? 'check-circle' : 
                           ($message_type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'); 
                ?>"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Main Form -->
        <form method="POST" action="" id="mainForm">
            <input type="hidden" name="action" value="delete">
            
            <!-- Toolbar -->
            <div class="toolbar">
                <div class="selection-info">
                    <div class="select-all">
                        <input type="checkbox" id="selectAll">
                        <label for="selectAll">Select All</label>
                    </div>
                    <div class="selected-count" id="selectedCount">
                        <i class="fas fa-check"></i>
                        <span>0 selected</span>
                    </div>
                </div>
                <button type="button" id="deleteBtn" class="btn" disabled>
                    <i class="fas fa-trash-alt"></i>
                    Delete Selected
                </button>
            </div>
            
            <!-- Data Table -->
            <div class="table-container">
                <?php if (!empty($records)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th class="checkbox-col"></th>
                                <th class="id-col">ID</th>
                                <th>Verwerkingsactiviteit</th>
                                <th>Doel Verwerking</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td class="checkbox-col">
                                        <input type="checkbox" 
                                               name="delete_ids[]" 
                                               value="<?php echo (int)$record['id']; ?>" 
                                               class="row-checkbox"
                                               data-id="<?php echo (int)$record['id']; ?>">
                                    </td>
                                    <td class="id-col"><?php echo htmlspecialchars($record['id']); ?></td>
                                    <td>
                                        <div class="text-content">
                                            <?php echo nl2br(htmlspecialchars($record['verwerkingsactiviteit'] ?? '-')); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-content">
                                            <?php echo nl2br(htmlspecialchars($record['doel_verwerking'] ?? '-')); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-table"></i>
                        <h3>No Data Available</h3>
                        <p>The verwerkingen table is empty or no records were found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </form>
        
        <!-- Footer -->
        <div class="footer">
            <p>Verwerkingen Management System</p>
            <div class="stats">
                <div class="stat-item">
                    <i class="fas fa-table"></i>
                    <span><?php echo htmlspecialchars(DB_TABLE); ?></span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-list"></i>
                    <span><?php echo $total_records; ?> records</span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-clock"></i>
                    <span><?php echo date('H:i:s'); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Delete Confirmation</h3>
                <button type="button" class="close-modal" id="closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Delete <strong id="deleteCount">0</strong> selected record(s)?</p>
                <p>This action cannot be undone.</p>
                <p>All selected data will be permanently removed.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelDelete">
                    Cancel
                </button>
                <button type="button" class="btn" id="confirmDelete">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // DOM Elements
        const selectAllCheckbox = document.getElementById('selectAll');
        const deleteBtn = document.getElementById('deleteBtn');
        const selectedCount = document.getElementById('selectedCount');
        const deleteModal = document.getElementById('deleteModal');
        const deleteCountSpan = document.getElementById('deleteCount');
        const closeModalBtn = document.getElementById('closeModal');
        const cancelDeleteBtn = document.getElementById('cancelDelete');
        const confirmDeleteBtn = document.getElementById('confirmDelete');
        const mainForm = document.getElementById('mainForm');
        
        // Selection Management
        function initSelection() {
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            
            function updateSelection() {
                const selected = document.querySelectorAll('.row-checkbox:checked');
                const count = selected.length;
                
                // Update counter
                selectedCount.innerHTML = `
                    <i class="fas fa-check"></i>
                    <span>${count} selected</span>
                `;
                
                // Update delete button
                deleteBtn.disabled = count === 0;
                
                // Update row styling
                rowCheckboxes.forEach(cb => {
                    cb.closest('tr').classList.toggle('selected', cb.checked);
                });
                
                // Update select all state
                if (count === 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                } else if (count === rowCheckboxes.length) {
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                }
            }
            
            // Select All
            selectAllCheckbox.addEventListener('change', function() {
                rowCheckboxes.forEach(cb => cb.checked = this.checked);
                updateSelection();
            });
            
            // Individual checkboxes
            rowCheckboxes.forEach(cb => {
                cb.addEventListener('change', updateSelection);
            });
            
            // Delete button
            deleteBtn.addEventListener('click', function() {
                const selected = document.querySelectorAll('.row-checkbox:checked');
                deleteCountSpan.textContent = selected.length;
                deleteModal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            });
            
            updateSelection();
        }
        
        // Modal Controls
        function hideModal() {
            deleteModal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Event Listeners
        closeModalBtn.addEventListener('click', hideModal);
        cancelDeleteBtn.addEventListener('click', hideModal);
        
        confirmDeleteBtn.addEventListener('click', function() {
            hideModal();
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            deleteBtn.disabled = true;
            setTimeout(() => mainForm.submit(), 300);
        });
        
        // Close modal on outside click or Escape
        deleteModal.addEventListener('click', function(e) {
            if (e.target === deleteModal) hideModal();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && deleteModal.style.display === 'flex') {
                hideModal();
            }
        });
        
        // Auto-hide alerts
        function autoHideAlerts() {
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.3s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        }
        
        // Row animations
        function animateRows() {
            document.querySelectorAll('tbody tr').forEach((row, i) => {
                row.style.opacity = '0';
                setTimeout(() => {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '1';
                }, i * 30);
            });
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            initSelection();
            autoHideAlerts();
            animateRows();
            
            // Prevent form resubmission
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>