<?php
// every record update triggers an set date on column updated_at
// added home button.
// added timestamp voor nieuwe records current timestamp
// added vertical view option for easier reading
// added horizontal view showing only first 4 columns, editing only in vertical view
// added recommendation system for records not recently updated based on updated_at column
// added print-friendly feature
// removed sensitive columns from print (encrypted columns are not shown in print)
// added batch printing of all records with DPIA
//v13 viewer

session_start();

// ==================== CONFIGURATION ====================
date_default_timezone_set('Europe/Amsterdam');

// Database configuration
$db_config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'database',
    'table' => 'avg_register',
    'users_table' => 'system_users',
    'changes_table' => 'system_changes',
    'dpia_table' => 'dpia_registrations'
];

// Define user roles and permissions
$user_roles = [
    'admin' => ['view', 'add', 'edit', 'delete', 'manage_users', 'view_changes', 'manage_dpia', 'export', 'print'],
    'editor' => ['view', 'add', 'edit', 'view_own_changes', 'manage_dpia', 'print'],
    'viewer' => ['view', 'print']
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

// Threshold for "recently updated" (in days) - based on updated_at column
$recent_update_threshold = 180; // 6 months

// Translation function (English only)
function t($key, $params = []) {
    $translations = [
        'site_name' => 'AVG REGISTER',
        'welcome' => 'GDPR Compliance Database',
        'login' => 'Login',
        'logout' => 'Logout',
        'save' => 'Save',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'view' => 'View',
        'add' => 'Add',
        'create' => 'Create',
        'update' => 'Update',
        'search' => 'Search',
        'clear' => 'Clear',
        'export' => 'Export',
        'back' => 'Back',
        'cancel' => 'Cancel',
        'actions' => 'Actions',
        'status' => 'Status',
        'dashboard' => 'Dashboard',
        'processing_activities' => 'Processing Activities',
        'data_breaches' => 'Data Breaches',
        'audit_trail' => 'Audit Trail',
        'reports' => 'Reports',
        'settings' => 'Settings',
        'language' => 'Language',
        'total_activities' => 'Total Records',
        'active_activities' => 'Active Records',
        'high_risk' => 'High Risk',
        'international_transfers' => 'International',
        'open_breaches' => 'Open Issues',
        'pending_dpias' => 'Pending Reviews',
        'view_all' => 'View All',
        'recent_activities' => 'Recent Activities',
        'add_activity' => 'Add New Record',
        'activity_name' => 'Record Name',
        'legal_basis' => 'Legal Basis',
        'purpose' => 'Purpose',
        'data_categories' => 'Data Categories',
        'data_subjects' => 'Data Subjects',
        'recipients' => 'Recipients',
        'retention_period' => 'Retention Period',
        'security_measures' => 'Security Measures',
        'risk_level' => 'Risk Level',
        'third_country_transfers' => 'Intl. Transfers',
        'dpi_required' => 'DPIA Required',
        'safeguards' => 'Safeguards',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'under_review' => 'Under Review',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'all' => 'All',
        'yes' => 'Yes',
        'no' => 'No',
        'success' => 'Success',
        'error' => 'Error',
        'warning' => 'Warning',
        'info' => 'Info',
        'confirm_delete' => 'Are you sure you want to delete this item?',
        'confirm_document_delete' => 'Are you sure you want to delete this document?',
        'enter_password' => 'Enter access password',
        'invalid_password' => 'Invalid password',
        'operation_completed' => 'Operation completed successfully',
        'item_deleted' => 'Item deleted successfully',
        'new' => 'New',
        'system' => 'System',
        'created' => 'Created',
        'updated' => 'Updated',
        'last_updated' => 'Last Updated',
        'select_legal_basis' => 'Select Legal Basis',
        'all_activities' => 'All activities',
        'recent_user_activity' => 'Recent User Activity',
        'system_audit_trail' => 'System Audit Trail',
        'view_full_log' => 'View Full Log',
        'timestamp' => 'Timestamp',
        'action' => 'Action',
        'entity_type' => 'Entity Type',
        'entity_id' => 'Entity ID',
        'details' => 'Details',
        'user_ip' => 'IP Address',
        'user_agent' => 'User Agent',
        'total_log_entries' => 'Total Log Entries',
        'unique_ip_addresses' => 'Unique IP Addresses',
        'unique_actions' => 'Unique Actions',
        'oldest_log' => 'Oldest Log',
        'filter_logs' => 'Filter Logs',
        'action_type' => 'Action Type',
        'date_from' => 'Date From',
        'date_to' => 'Date To',
        'filter' => 'Filter',
        'login_successful' => 'Login successful',
        'login_failed' => 'Failed login attempt',
        'database_error' => 'Database error',
        'no_audit_entries' => 'No audit log entries found',
        'changes' => 'Changes',
        'added_activity' => 'Added processing activity',
        'updated_activity' => 'Updated processing activity',
        'deleted_activity' => 'Deleted processing activity',
        'viewed_activity' => 'Viewed activity',
        'listed_processing_activities' => 'Listed processing activities',
        'searched_for' => 'Searched for',
        'found_results' => 'found',
        'exported_to' => 'Exported to',
        'with_filters' => 'with filters',
        'audit_logs_cleaned' => 'Audit logs cleaned up successfully.',
        'records_removed' => 'records removed',
        'error_cleaning_logs' => 'Error cleaning up audit logs.',
        'confirm_cleanup_logs' => 'Are you sure you want to cleanup audit logs older than {days} days?',
        'cleanup_old_logs' => 'Cleanup Old Logs',
        'list_view' => 'List View',
        'manage_users' => 'Manage Users',
        'user_management' => 'User Management',
        'add_new_user' => 'Add New User',
        'username' => 'Username',
        'password' => 'Password',
        'email' => 'Email',
        'full_name' => 'Full Name',
        'role' => 'Role',
        'is_active' => 'Active',
        'last_login' => 'Last Login',
        'user_actions' => 'User Actions',
        'edit_user' => 'Edit User',
        'delete_user' => 'Delete User',
        'confirm_user_delete' => 'Are you sure you want to delete this user?',
        'cannot_delete_self' => 'You cannot delete your own account!',
        'encrypted_indicator' => '[ENCRYPTED]',
        'home' => 'Home',
        'refresh' => 'Refresh',
        'review_mode' => 'Review Mode',
        'toggle_compact_view' => 'Toggle Compact View',
        'row_counter' => 'Showing {current} of {total} rows',
        'encrypted_columns' => 'Encrypted columns',
        'user' => 'User',
        'add_record_form' => 'Add New Record',
        'edit_record_form' => 'Edit Record',
        'record_id' => 'Record ID',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
        'timestamps' => 'Timestamps',
        'automatically_set' => 'Automatically set',
        'automatically_updated' => 'Automatically updated',
        'original_date_kept' => 'Original date kept',
        'view_changes' => 'View Changes',
        'recent_changes' => 'Recent Changes',
        'action_type' => 'Action Type',
        'table' => 'Table',
        'changed_fields' => 'Changed Fields',
        'old_data' => 'Old Data',
        'new_data' => 'New Data',
        'close' => 'Close',
        'admin' => 'Admin',
        'editor' => 'Editor',
        'viewer' => 'Viewer',
        'role_admin' => 'Administrator',
        'role_editor' => 'Editor',
        'role_viewer' => 'Viewer',
        'no_permission_view' => 'You don\'t have permission to view data.',
        'no_activities_found' => 'No processing activities found',
        'change_details' => 'Change Details',
        'of' => 'of',
        'existing_users' => 'Existing Users',
        'vertical_view' => 'Vertical View',
        'table_view' => 'Table View',
        'view_record' => 'View Record',
        'edit_record' => 'Edit Record',
        'previous_record' => 'Previous',
        'next_record' => 'Next',
        'record_navigation' => 'Record Navigation',
        'vertical_display' => 'Vertical Display',
        'record_details' => 'Record Details',
        'back_to_table' => 'Back to Table',
        'jump_to_record' => 'Jump to record',
        'record' => 'Record',
        'first_four_columns' => 'First 4 columns',
        'full_details' => 'Full Details',
        'showing_columns' => 'Showing columns',
        'only_in' => 'only in',
        'view_only_in' => 'View only in',
        'dpia_management' => 'DPIA Management',
        'dpia_registrations' => 'DPIA Registrations',
        'view_dpias' => 'View DPIAs',
        'register_dpia' => 'Register DPIA',
        'dpia_status' => 'DPIA Status',
        'has_dpia' => 'Has DPIA',
        'no_dpia' => 'No DPIA',
        'dpia_registered' => 'DPIA Registered',
        'dpia_registered_on' => 'DPIA Registered on',
        'dpia_registered_by' => 'DPIA Registered by',
        'register_record_dpia' => 'Register Record for DPIA',
        'dpia_already_registered' => 'DPIA already registered for this record',
        'dpia_registration_success' => 'DPIA registration successful',
        'remove_dpia' => 'Remove DPIA',
        'confirm_remove_dpia' => 'Are you sure you want to remove DPIA registration for this record?',
        'dpia_removed' => 'DPIA registration removed',
        'dpia_description' => 'Description',
        'necessity_proportionality' => 'Necessity and Proportionality',
        'mitigation_measures' => 'Mitigation Measures',
        'residual_risk' => 'Residual Risk After Mitigation',
        'overall_risk_level' => 'Overall Risk Level',
        'dpia_status_field' => 'Status',
        'dpia_open' => 'Open',
        'dpia_closed' => 'Closed',
        'dpia_pending' => 'Pending',
        'add_dpia' => 'Add DPIA',
        'edit_dpia' => 'Edit DPIA',
        'delete_dpia' => 'Delete DPIA',
        'view_dpia' => 'View DPIA',
        'dpia_details' => 'DPIA Details',
        'dpia_for_record' => 'DPIA for Record',
        'registered_date' => 'Registered Date',
        'last_updated' => 'Last Updated',
        'notes' => 'Notes',
        'no_dpias_found' => 'No DPIA registrations found',
        'all_dpias' => 'All DPIAs',
        'open_dpias' => 'Open DPIAs',
        'closed_dpias' => 'Closed DPIAs',
        'pending_dpias' => 'Pending DPIAs',
        'filter_by_status' => 'Filter by Status',
        'dpia_id' => 'DPIA ID',
        'back_to_dpias' => 'Back to DPIAs',
        'processing_activity_name' => 'Processing Activity',
        'organizational_measures' => 'Organizational Measures',
        'technical_measures' => 'Technical Measures',
        'records_to_update' => 'Records to Update',
        'update_recommendations' => 'Update Recommendations',
        'not_updated_recently' => 'Not recently updated',
        'days_ago' => 'days ago',
        'recommendation_threshold' => 'Recommendation Threshold',
        'update_required' => 'Update Required',
        'add_new' => 'Add New',
        'edit_existing' => 'Edit Existing',
        'recommend_to_edit' => 'Recommend to Edit',
        'recommendation_info' => 'Records not updated in the last {days} days',
        'add_button_menu' => 'Add/Edit Menu',
        'last_update' => 'Last Update',
        'never_updated' => 'Never updated',
        'recently_updated' => 'Recently updated',
        'update_status' => 'Update Status',
        'needs_update' => 'Needs Update',
        'up_to_date' => 'Up to Date',
        'updated_long_ago' => 'Updated long ago',
        'update_frequency' => 'Update Frequency',
        'update_statistics' => 'Update Statistics',
        'records_need_update' => 'records need update',
        'records_up_to_date' => 'records up to date',
        'average_update_age' => 'Average update age',
        'oldest_update' => 'Oldest update',
        'newest_update' => 'Newest update',
        'update_history' => 'Update History',
        'days_since_update' => 'Days since update',
        'update_priority' => 'Update Priority',
        'high_priority' => 'High Priority',
        'medium_priority' => 'Medium Priority',
        'low_priority' => 'Low Priority',
        'no_update_data' => 'No update data available',
        'update_reminder' => 'Update Reminder',
        'update_due' => 'Update due',
        'overdue_by' => 'Overdue by',
        'update_schedule' => 'Update Schedule',
        'update_monitoring' => 'Update Monitoring',
        'update_analytics' => 'Update Analytics',
        'update_tracking' => 'Update Tracking',
        'update_management' => 'Update Management',
        'update_notifications' => 'Update Notifications',
        'update_alerts' => 'Update Alerts',
        'update_warnings' => 'Update Warnings',
        'this_record_not_updated' => 'This record has not been updated for {days} days (threshold: {threshold} days)',
        'update_now' => 'Update Now',
        'auto_populated' => 'Auto-populated',
        'mitigation_measures_from_record' => 'Mitigation measures from record',
        'refresh_measures' => 'Refresh measures',
        
        // Print feature translations - UPDATED
        'print' => 'Print',
        'print_record' => 'Print Record',
        'print_full_details' => 'Full Details Print',
        'print_compact' => 'Compact Print',
        'compact_view' => 'Compact View',
        'compact_print_description' => 'Table-based compact view for printing',
        'full_print_description' => 'Full details with all columns',
        'confidential' => 'CONFIDENTIAL - CONFIDENTIAL - CONFIDENTIAL',
        'generated_on' => 'Generated on',
        'generated_by' => 'Generated by',
        'page' => 'Page',
        'print_summary' => 'Print Summary',
        'show_all_columns' => 'Show All Columns',
        'show_compact' => 'Show Compact',
        'print_compact_table' => 'Print Compact Table',
        'print_individual' => 'Print Individual Record',
        'print_all' => 'Print All Records',
        'print_options' => 'Print Options',
        'print_full' => 'Print Full Details',
        'dpia_focused_print' => 'DPIA Focused Print',
        'print_dpia_details' => 'Print DPIA Details',
        'dpia_print_description' => 'Includes DPIA risk assessment and mitigation details',
        'print_with_dpia_details' => 'Print with DPIA risk assessment details',
        'residual_risk_summary' => 'Residual Risk Summary',
        'risk_assessment' => 'Risk Assessment',
        
        // New translations for updated print functionality
        'avg_registersovereenkomstmetderdepartij' => 'Agreement with Third Party',
        'wijzijnverwerker' => 'We are Processor',
        'update_needed_column' => 'Update Needed',
        'columns_shown' => 'Columns shown',
        'field' => 'Field',
        'value' => 'Value',
        'click_record_print' => 'Click on a record\'s print button below',
        'printing_options' => 'Printing Options',
        'print_individual_record' => 'Print Individual Record',
        
        // Sensitive columns removed from print
        'sensitive_data_removed' => 'Sensitive data removed for printing',
        'sensitive_columns_excluded' => 'Sensitive columns excluded',
        'print_safe_version' => 'Print Safe Version',
        'excluded_columns' => 'Excluded Columns',
        'safe_for_printing' => 'Safe for printing',
        'showing_safe_data' => 'Showing safe data only',
        'sensitive_information' => 'Sensitive Information',
        'protected_for_privacy' => 'Protected for privacy',
        'add_new' => 'Add New',
        'records' => 'Records',
        'page' => 'Page',
        'print_all_records' => 'Print All Records',
        'secure_print' => 'Secure Print',
        
        // NEW: Batch printing translations
        'print_all_with_dpia' => 'Print All Records with DPIA Details',
        'print_all_with_dpia_description' => 'Secure print of all records including DPIA risk assessments',
        'generating_print' => 'Generating print documents...',
        'print_batch' => 'Print Batch',
        'include_all_records' => 'Include All Records',
        'include_only_with_dpia' => 'Include Only Records with DPIA',
        'print_selection' => 'Print Selection',
        'print_all_secure' => 'Print All Records Securely',
        'generating_multiple_records' => 'Generating {count} records for printing',
        'batch_print_progress' => 'Processing record {current} of {total}',
        'print_complete' => 'Print generation complete',
        'download_print_package' => 'Download Print Package',
        'view_print_preview' => 'View Print Preview',
        'start_print_job' => 'Start Print Job',
        'cancel_print_job' => 'Cancel Print Job',
        'print_job_status' => 'Print Job Status',
        'records_in_batch' => 'Records in batch',
        'estimated_pages' => 'Estimated pages',
        'print_quality' => 'Print Quality',
        'print_speed' => 'Print Speed',
        'duplex_printing' => 'Duplex Printing',
        'collate_documents' => 'Collate Documents',
        'print_range' => 'Print Range',
        'all_pages' => 'All Pages',
        'current_page' => 'Current Page',
        'custom_range' => 'Custom Range',
        'from_page' => 'From page',
        'to_page' => 'To page',
        'print_settings' => 'Print Settings',
        'margins' => 'Margins',
        'orientation' => 'Orientation',
        'portrait' => 'Portrait',
        'landscape' => 'Landscape',
        'paper_size' => 'Paper Size',
        'a4' => 'A4',
        'letter' => 'Letter',
        'legal' => 'Legal',
        'scale_to_fit' => 'Scale to fit',
        'headers_footers' => 'Headers & Footers',
        'print_header' => 'Print Header',
        'print_footer' => 'Print Footer',
        'page_numbers' => 'Page Numbers',
        'watermark' => 'Watermark',
        'draft' => 'Draft',
        'normal' => 'Normal',
        'high_quality' => 'High Quality',
        'print_preview' => 'Print Preview',
        'print_now' => 'Print Now',
        'save_as_pdf' => 'Save as PDF',
        'send_to_printer' => 'Send to Printer',
        'print_dialog' => 'Print Dialog',
        'print_queued' => 'Print Queued',
        'print_in_progress' => 'Print in Progress',
        'print_completed' => 'Print Completed',
        'print_failed' => 'Print Failed',
        'retry_print' => 'Retry Print',
        'cancel_printing' => 'Cancel Printing',
        'print_log' => 'Print Log',
        'last_printed' => 'Last Printed',
        'printed_by' => 'Printed By',
        'print_count' => 'Print Count',
        'paper_used' => 'Paper Used',
        'ink_level' => 'Ink Level',
        'printer_status' => 'Printer Status',
        'ready' => 'Ready',
        'busy' => 'Busy',
        'offline' => 'Offline',
        'error' => 'Error',
        'out_of_paper' => 'Out of Paper',
        'low_ink' => 'Low Ink',
        'jam' => 'Jam',
        'maintenance_required' => 'Maintenance Required',
        'batch_print_summary' => 'Batch Print Summary',
        'records_with_dpia' => 'Records with DPIA',
        'batch_report' => 'Batch Report'
    ];
    
    $translation = $translations[$key] ?? $key;
    
    // Replace parameters
    foreach ($params as $param => $value) {
        $translation = str_replace("{{$param}}", $value, $translation);
    }
    
    return $translation;
}

// Debug logging function
function debug_log($message, $data = null) {
    error_log(date('Y-m-d H:i:s') . " - " . $message);
    if ($data !== null) {
        error_log(print_r($data, true));
    }
}

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

// Function to remove sensitive columns for printing
function remove_sensitive_columns_for_print($record, $rot47_columns) {
    $safe_record = [];
    
    foreach ($record as $field => $value) {
        // Skip sensitive columns (encrypted columns)
        if (in_array($field, $rot47_columns)) {
            continue;
        }
        
        // Also skip other potentially sensitive fields
        $sensitive_patterns = ['email', 'telefoon', 'mobiel', 'contact', 'phone', 'tel', 'mobile'];
        $is_sensitive = false;
        foreach ($sensitive_patterns as $pattern) {
            if (stripos($field, $pattern) !== false) {
                $is_sensitive = true;
                break;
            }
        }
        
        if (!$is_sensitive) {
            $safe_record[$field] = $value;
        }
    }
    
    return $safe_record;
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

// Function to create DPIA table
function create_dpia_table($connection, $table_name) {
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        record_id INT NOT NULL,
        description TEXT,
        necessity_proportionality TEXT,
        mitigation_measures TEXT,
        residual_risk TEXT,
        overall_risk_level ENUM('low', 'medium', 'high') DEFAULT 'medium',
        status ENUM('open', 'closed', 'pending') DEFAULT 'pending',
        registered_by INT NOT NULL,
        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        notes TEXT,
        FOREIGN KEY (registered_by) REFERENCES system_users(id) ON DELETE CASCADE,
        FOREIGN KEY (record_id) REFERENCES avg_register(id) ON DELETE CASCADE,
        UNIQUE KEY unique_record_dpia (record_id)
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

// Function to check if record has DPIA
function has_dpia($connection, $record_id) {
    global $db_config;
    $sql = "SELECT COUNT(*) as count FROM {$db_config['dpia_table']} WHERE record_id = ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] > 0;
    }
    return false;
}

// Function to get DPIA info
function get_dpia_info($connection, $dpia_id) {
    global $db_config;
    $sql = "SELECT d.*, u.username, u.full_name, r.verwerkingsactiviteit, r.organisatorische_maatregelen, r.technische_maatregelen
            FROM {$db_config['dpia_table']} d 
            LEFT JOIN {$db_config['users_table']} u ON d.registered_by = u.id 
            LEFT JOIN {$db_config['table']} r ON d.record_id = r.id 
            WHERE d.id = ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $dpia_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return null;
}

// Function to get DPIA info by record ID
function get_dpia_info_by_record($connection, $record_id) {
    global $db_config;
    $sql = "SELECT d.*, u.username, u.full_name, r.verwerkingsactiviteit, r.organisatorische_maatregelen, r.technische_maatregelen
            FROM {$db_config['dpia_table']} d 
            LEFT JOIN {$db_config['users_table']} u ON d.registered_by = u.id 
            LEFT JOIN {$db_config['table']} r ON d.record_id = r.id 
            WHERE d.record_id = ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return null;
}

// Function to get all DPIA registrations
function get_all_dpias($connection, $status = null) {
    global $db_config;
    
    $sql = "SELECT d.*, u.username, u.full_name, r.verwerkingsactiviteit 
            FROM {$db_config['dpia_table']} d 
            LEFT JOIN {$db_config['users_table']} u ON d.registered_by = u.id 
            LEFT JOIN {$db_config['table']} r ON d.record_id = r.id";
    
    if ($status) {
        $sql .= " WHERE d.status = ?";
        $stmt = $connection->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $status);
            $stmt->execute();
            $result = $stmt->get_result();
            $dpias = [];
            while ($row = $result->fetch_assoc()) {
                $dpias[] = $row;
            }
            return $dpias;
        }
    } else {
        $result = $connection->query($sql);
        $dpias = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $dpias[] = $row;
            }
        }
        return $dpias;
    }
    return [];
}

// Function to register DPIA
function register_dpia($connection, $record_id, $data) {
    global $current_user, $db_config;
    
    // Check if already registered
    if (has_dpia($connection, $record_id)) {
        debug_log("DPIA already exists for record: $record_id");
        return false;
    }
    
    debug_log("Attempting to register DPIA for record: $record_id", $data);
    debug_log("Current user ID: " . $current_user['id']);
    
    $sql = "INSERT INTO {$db_config['dpia_table']} (record_id, description, necessity_proportionality, mitigation_measures, residual_risk, overall_risk_level, status, registered_by, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    debug_log("SQL query: $sql");
    
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("issssssis", 
            $record_id, 
            $data['description'],
            $data['necessity_proportionality'],
            $data['mitigation_measures'],
            $data['residual_risk'],
            $data['overall_risk_level'],
            $data['status'],
            $current_user['id'],
            $data['notes']
        );
        
        $result = $stmt->execute();
        
        if ($result) {
            debug_log("DPIA registration SUCCESS for record: $record_id, ID: " . $connection->insert_id);
            
            // Log the change
            $new_data = [
                'record_id' => $record_id,
                'description' => $data['description'],
                'status' => $data['status'],
                'overall_risk_level' => $data['overall_risk_level']
            ];
            
            log_change($connection, $db_config['dpia_table'], $connection->insert_id, 'INSERT', null, $new_data, 'record_id,description,status,overall_risk_level');
        } else {
            debug_log("DPIA registration FAILED for record: $record_id, Error: " . $stmt->error);
        }
        
        return $result;
    } else {
        debug_log("DPIA prepare failed for record: $record_id, Error: " . $connection->error);
        return false;
    }
}

// Function to update DPIA
function update_dpia($connection, $dpia_id, $data) {
    global $db_config;
    
    debug_log("Updating DPIA: $dpia_id", $data);
    
    // Get old data for logging
    $old_sql = "SELECT * FROM {$db_config['dpia_table']} WHERE id = ?";
    $old_stmt = $connection->prepare($old_sql);
    $old_stmt->bind_param("i", $dpia_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();
    $old_data = $old_result->fetch_assoc();
    
    $sql = "UPDATE {$db_config['dpia_table']} SET 
            description = ?,
            necessity_proportionality = ?,
            mitigation_measures = ?,
            residual_risk = ?,
            overall_risk_level = ?,
            status = ?,
            notes = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = ?";
    
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssssssi", 
            $data['description'],
            $data['necessity_proportionality'],
            $data['mitigation_measures'],
            $data['residual_risk'],
            $data['overall_risk_level'],
            $data['status'],
            $data['notes'],
            $dpia_id
        );
        
        $result = $stmt->execute();
        
        if ($result) {
            // Log the change
            $new_data = [
                'description' => $data['description'],
                'status' => $data['status'],
                'overall_risk_level' => $data['overall_risk_level']
            ];
            
            $changed_fields = get_changed_fields($old_data, $new_data);
            log_change($connection, $db_config['dpia_table'], $dpia_id, 'UPDATE', $old_data, $new_data, $changed_fields);
            
            debug_log("DPIA update SUCCESS for ID: $dpia_id");
        } else {
            debug_log("DPIA update FAILED for ID: $dpia_id, Error: " . $stmt->error);
        }
        
        return $result;
    }
    debug_log("DPIA prepare failed for update: " . $connection->error);
    return false;
}

// Function to remove DPIA
function remove_dpia($connection, $record_id) {
    global $db_config;
    
    debug_log("Removing DPIA for record: $record_id");
    
    // Get old data for logging
    $old_sql = "SELECT * FROM {$db_config['dpia_table']} WHERE record_id = ?";
    $old_stmt = $connection->prepare($old_sql);
    $old_stmt->bind_param("i", $record_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();
    $old_data = $old_result->fetch_assoc();
    
    $sql = "DELETE FROM {$db_config['dpia_table']} WHERE record_id = ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $record_id);
        $result = $stmt->execute();
        
        if ($result) {
            // Log the change
            log_change($connection, $db_config['dpia_table'], $record_id, 'DELETE', $old_data, null, 'all_fields');
            debug_log("DPIA removal SUCCESS for record: $record_id");
        } else {
            debug_log("DPIA removal FAILED for record: $record_id, Error: " . $stmt->error);
        }
        
        return $result;
    }
    return false;
}

// Function to delete DPIA by ID
function delete_dpia($connection, $dpia_id) {
    global $db_config;
    
    debug_log("Deleting DPIA ID: $dpia_id");
    
    // Get old data for logging
    $old_sql = "SELECT * FROM {$db_config['dpia_table']} WHERE id = ?";
    $old_stmt = $connection->prepare($old_sql);
    $old_stmt->bind_param("i", $dpia_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();
    $old_data = $old_result->fetch_assoc();
    
    $sql = "DELETE FROM {$db_config['dpia_table']} WHERE id = ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $dpia_id);
        $result = $stmt->execute();
        
        if ($result) {
            // Log the change
            log_change($connection, $db_config['dpia_table'], $dpia_id, 'DELETE', $old_data, null, 'all_fields');
            debug_log("DPIA delete SUCCESS for ID: $dpia_id");
        } else {
            debug_log("DPIA delete FAILED for ID: $dpia_id, Error: " . $stmt->error);
        }
        
        return $result;
    }
    debug_log("DPIA delete prepare failed: " . $connection->error);
    return false;
}

// Function to get record verwerkingsactiviteit by ID
function get_record_verwerkingsactiviteit($connection, $record_id) {
    global $db_config;
    $sql = "SELECT verwerkingsactiviteit FROM {$db_config['table']} WHERE id = ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['verwerkingsactiviteit'] ?? 'Unknown';
    }
    return 'Unknown';
}

// Function to get security measures from record
function get_security_measures_from_record($connection, $record_id) {
    global $db_config;
    $sql = "SELECT organisatorische_maatregelen, technische_maatregelen FROM {$db_config['table']} WHERE id = ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $measures = [];
        
        if (!empty($row['organisatorische_maatregelen'])) {
            $measures[] = "**" . t('organizational_measures') . ":**\n" . $row['organisatorische_maatregelen'];
        }
        
        if (!empty($row['technische_maatregelen'])) {
            $measures[] = "**" . t('technical_measures') . ":**\n" . $row['technische_maatregelen'];
        }
        
        return implode("\n\n", $measures);
    }
    return '';
}

// Function to get full record details WITHOUT sensitive columns for printing
function get_full_record_details_for_print($connection, $record_id, $rot47_columns) {
    global $db_config;
    
    $sql = "SELECT * FROM {$db_config['table']} WHERE id = ?";
    $stmt = $connection->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $record_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            // Remove sensitive columns for printing
            $safe_record = remove_sensitive_columns_for_print($row, $rot47_columns);
            
            // Calculate update status
            $update_status = [];
            if (!empty($row['updated_at'])) {
                $days_since = floor((time() - strtotime($row['updated_at'])) / (60 * 60 * 24));
                $update_status['days_since'] = $days_since;
                $update_status['needs_update'] = $days_since > 180 ? 'Yes' : 'No';
                $update_status['last_updated'] = $row['updated_at'];
            } else {
                $update_status['days_since'] = 'N/A';
                $update_status['needs_update'] = 'Unknown';
                $update_status['last_updated'] = 'Never';
            }
            
            // Get DPIA info
            $dpia_info = get_dpia_info_by_record($connection, $record_id);
            
            return [
                'record' => $safe_record,
                'update_status' => $update_status,
                'dpia_info' => $dpia_info,
                'has_dpia' => !empty($dpia_info),
                'excluded_columns' => $rot47_columns
            ];
        }
    }
    
    return null;
}

// NEW: Function to get all records with DPIA for batch printing
function get_all_records_with_dpia_for_print($connection, $rot47_columns, $only_with_dpia = false) {
    global $db_config;
    
    // Build SQL query
    if ($only_with_dpia) {
        // Only records that have DPIA
        $sql = "SELECT v.*, d.id as dpia_id, d.description as dpia_description, 
                       d.necessity_proportionality, d.mitigation_measures, 
                       d.residual_risk, d.overall_risk_level, d.status as dpia_status,
                       d.registered_at as dpia_registered_at, d.updated_at as dpia_updated_at,
                       d.notes as dpia_notes, u.username as dpia_registered_by_username,
                       u.full_name as dpia_registered_by_name
                FROM {$db_config['table']} v
                INNER JOIN {$db_config['dpia_table']} d ON v.id = d.record_id
                LEFT JOIN {$db_config['users_table']} u ON d.registered_by = u.id
                ORDER BY v.id";
    } else {
        // All records with LEFT JOIN for DPIA
        $sql = "SELECT v.*, d.id as dpia_id, d.description as dpia_description, 
                       d.necessity_proportionality, d.mitigation_measures, 
                       d.residual_risk, d.overall_risk_level, d.status as dpia_status,
                       d.registered_at as dpia_registered_at, d.updated_at as dpia_updated_at,
                       d.notes as dpia_notes, u.username as dpia_registered_by_username,
                       u.full_name as dpia_registered_by_name
                FROM {$db_config['table']} v
                LEFT JOIN {$db_config['dpia_table']} d ON v.id = d.record_id
                LEFT JOIN {$db_config['users_table']} u ON d.registered_by = u.id
                ORDER BY v.id";
    }
    
    $result = $connection->query($sql);
    $records = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Remove sensitive columns for printing
            $safe_record = remove_sensitive_columns_for_print($row, $rot47_columns);
            
            // Calculate update status
            $update_status = [];
            if (!empty($row['updated_at'])) {
                $days_since = floor((time() - strtotime($row['updated_at'])) / (60 * 60 * 24));
                $update_status['days_since'] = $days_since;
                $update_status['needs_update'] = $days_since > 180 ? 'Yes' : 'No';
                $update_status['last_updated'] = $row['updated_at'];
            } else {
                $update_status['days_since'] = 'N/A';
                $update_status['needs_update'] = 'Unknown';
                $update_status['last_updated'] = 'Never';
            }
            
            // Prepare DPIA info if exists
            $dpia_info = null;
            if (!empty($row['dpia_id'])) {
                $dpia_info = [
                    'id' => $row['dpia_id'],
                    'description' => $row['dpia_description'],
                    'necessity_proportionality' => $row['necessity_proportionality'],
                    'mitigation_measures' => $row['mitigation_measures'],
                    'residual_risk' => $row['residual_risk'],
                    'overall_risk_level' => $row['overall_risk_level'],
                    'status' => $row['dpia_status'],
                    'registered_at' => $row['dpia_registered_at'],
                    'updated_at' => $row['dpia_updated_at'],
                    'notes' => $row['dpia_notes'],
                    'registered_by_username' => $row['dpia_registered_by_username'],
                    'registered_by_name' => $row['dpia_registered_by_name']
                ];
            }
            
            $records[] = [
                'record' => $safe_record,
                'update_status' => $update_status,
                'dpia_info' => $dpia_info,
                'has_dpia' => !empty($row['dpia_id']),
                'excluded_columns' => $rot47_columns
            ];
        }
    }
    
    return $records;
}

// Function to get records not recently updated based on updated_at column
function get_records_not_recently_updated($connection) {
    global $db_config, $recent_update_threshold;
    
    $sql = "SELECT id, verwerkingsactiviteit, updated_at, 
            DATEDIFF(CURDATE(), DATE(updated_at)) as days_since_update,
            organisatorische_maatregelen, technische_maatregelen
            FROM {$db_config['table']} 
            WHERE updated_at IS NOT NULL 
            AND DATEDIFF(CURDATE(), DATE(updated_at)) > ?
            ORDER BY days_since_update DESC 
            LIMIT 10";
    
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $recent_update_threshold);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        return $records;
    }
    return [];
}

// Function to get count of records not recently updated
function get_count_records_not_recently_updated($connection) {
    global $db_config, $recent_update_threshold;
    
    $sql = "SELECT COUNT(*) as count 
            FROM {$db_config['table']} 
            WHERE updated_at IS NOT NULL 
            AND DATEDIFF(CURDATE(), DATE(updated_at)) > ?";
    
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $recent_update_threshold);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] ?? 0;
    }
    return 0;
}

// Function to get update statistics
function get_update_statistics($connection) {
    global $db_config, $recent_update_threshold;
    
    $stats = [
        'total_records' => 0,
        'needs_update' => 0,
        'up_to_date' => 0,
        'average_days_since_update' => 0,
        'oldest_update' => null,
        'newest_update' => null,
        'update_distribution' => []
    ];
    
    // Get total records count
    $sql = "SELECT COUNT(*) as count FROM {$db_config['table']}";
    $result = $connection->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total_records'] = $row['count'] ?? 0;
    }
    
    // Get records that need update
    $sql = "SELECT COUNT(*) as count 
            FROM {$db_config['table']} 
            WHERE updated_at IS NOT NULL 
            AND DATEDIFF(CURDATE(), DATE(updated_at)) > ?";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $recent_update_threshold);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['needs_update'] = $row['count'] ?? 0;
    }
    
    // Get records that are up to date
    $stats['up_to_date'] = $stats['total_records'] - $stats['needs_update'];
    
    // Get average days since update
    $sql = "SELECT AVG(DATEDIFF(CURDATE(), DATE(updated_at))) as avg_days 
            FROM {$db_config['table']} 
            WHERE updated_at IS NOT NULL";
    $result = $connection->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['average_days_since_update'] = round($row['avg_days'] ?? 0, 1);
    }
    
    // Get oldest and newest update
    $sql = "SELECT MIN(updated_at) as oldest, MAX(updated_at) as newest 
            FROM {$db_config['table']} 
            WHERE updated_at IS NOT NULL";
    $result = $connection->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['oldest_update'] = $row['oldest'] ?? null;
        $stats['newest_update'] = $row['newest'] ?? null;
    }
    
    // Get update distribution by timeframe
    $sql = "SELECT 
                CASE 
                    WHEN DATEDIFF(CURDATE(), DATE(updated_at)) <= 30 THEN '0-30 days'
                    WHEN DATEDIFF(CURDATE(), DATE(updated_at)) <= 90 THEN '31-90 days'
                    WHEN DATEDIFF(CURDATE(), DATE(updated_at)) <= 180 THEN '91-180 days'
                    WHEN DATEDIFF(CURDATE(), DATE(updated_at)) <= 365 THEN '181-365 days'
                    ELSE 'Over 1 year'
                END as timeframe,
                COUNT(*) as count
            FROM {$db_config['table']} 
            WHERE updated_at IS NOT NULL
            GROUP BY timeframe
            ORDER BY 
                CASE timeframe
                    WHEN '0-30 days' THEN 1
                    WHEN '31-90 days' THEN 2
                    WHEN '91-180 days' THEN 3
                    WHEN '181-365 days' THEN 4
                    ELSE 5
                END";
    
    $result = $connection->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $stats['update_distribution'][] = $row;
        }
    }
    
    return $stats;
}

// Function to get update priority for a record
function get_update_priority($days_since_update) {
    global $recent_update_threshold;
    
    if ($days_since_update > 365) {
        return 'high';
    } elseif ($days_since_update > $recent_update_threshold) {
        return 'medium';
    } elseif ($days_since_update > $recent_update_threshold / 2) {
        return 'low';
    } else {
        return 'none';
    }
}

// Function to get priority color
function get_priority_color($priority) {
    switch ($priority) {
        case 'high':
            return '#f44336'; // Red
        case 'medium':
            return '#ff9800'; // Orange
        case 'low':
            return '#ffc107'; // Yellow
        default:
            return '#4CAF50'; // Green
    }
}

// Function: Get compact table data for printing with custom columns
function get_compact_table_data_updated($connection, $records, $rot47_columns) {
    global $recent_update_threshold;
    
    $compact_data = [];
    
    foreach ($records as $record) {
        $row = [];
        
        // Key compact columns to show - UPDATED
        $compact_columns = [
            'id', 
            'verwerkingsactiviteit', 
            'avg_registersovereenkomstmetderdepartij', 
            'wijzijnverwerker'
        ];
        
        foreach ($compact_columns as $col_name) {
            if (isset($record[$col_name])) {
                $value = $record[$col_name];
                if (in_array($col_name, $rot47_columns) && $value !== null && $value !== '') {
                    $value = rot47_decrypt($value);
                }
                $row[$col_name] = $value;
            } else {
                $row[$col_name] = '';
            }
        }
        
        // Add DPIA status
        $has_dpia = has_dpia($connection, $record['id']);
        $row['dpia_status'] = $has_dpia ? 'Yes' : 'No';
        
        // Calculate days since update and update needed status
        $update_needed = 'No';
        $days_since = 'N/A';
        
        if (!empty($record['updated_at'])) {
            $days_since = floor((time() - strtotime($record['updated_at'])) / (60 * 60 * 24));
            $update_needed = $days_since > $recent_update_threshold ? 'Yes' : 'No';
        } elseif (!empty($record['created_at'])) {
            $days_since = floor((time() - strtotime($record['created_at'])) / (60 * 60 * 24));
            $update_needed = $days_since > $recent_update_threshold ? 'Yes' : 'No';
        }
        
        $row['days_since_update'] = $days_since;
        $row['update_needed'] = $update_needed;
        
        $compact_data[] = $row;
    }
    
    return $compact_data;
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
$view_mode = 'table';
$show_dpia_list = isset($_GET['view_dpias']) ? true : false;
$dpia_status_filter = isset($_GET['dpia_status']) ? $_GET['dpia_status'] : null;
$edit_dpia_id = isset($_GET['edit_dpia']) ? intval($_GET['edit_dpia']) : 0;
$add_dpia_record = isset($_GET['add_dpia']) ? intval($_GET['add_dpia']) : 0;
$view_dpia_id = isset($_GET['view_dpia']) ? intval($_GET['view_dpia']) : 0;
$dpias = [];
$all_dpias_count = 0;
$open_dpias_count = 0;
$closed_dpias_count = 0;
$pending_dpias_count = 0;
$records_to_update = [];
$count_records_to_update = 0;
$update_statistics = [];
$print_individual = isset($_GET['print_record']) ? intval($_GET['print_record']) : 0;
$print_compact = isset($_GET['print_compact']) ? true : false;
$print_full_details = isset($_GET['print_full']) ? true : false;
$print_all_with_dpia = isset($_GET['print_all_with_dpia']) ? true : false;
$only_with_dpia = isset($_GET['only_with_dpia']) ? true : false;
$watermark = true; // Always use watermark for printing
$full_record_details = null;
$batch_records = [];

// Check session for logged in user
if (isset($_SESSION['user_id'])) {
    $is_logged_in = true;
    $current_user = $_SESSION['user'];
}

// Create database connection for initial setup
try {
    $setup_connection = new mysqli(
        $db_config['host'],
        $db_config['username'],
        $db_config['password'],
        $db_config['database']
    );
    
    if ($setup_connection->connect_error) {
        throw new Exception("Connection failed: " . $setup_connection->connect_error);
    }
    
    // Create tables if they don't exist
    create_users_table($setup_connection, $db_config['users_table']);
    create_changes_table($setup_connection, $db_config['changes_table']);
    create_dpia_table($setup_connection, $db_config['dpia_table']);
    
    $setup_connection->close();
} catch (Exception $e) {
    // Don't throw error here, let it be handled in login
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
            create_dpia_table($connection, $db_config['dpia_table']);
            
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
                    $success = t('login_successful');
                    
                    header("Location: ?");
                    exit();
                } else {
                    $error = t('invalid_password');
                }
            } else {
                $error = t('invalid_password');
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
    elseif (isset($_POST['action']) && $_POST['action'] === 'show_dpias') {
        $show_dpia_list = true;
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
                            
                            $success = t('operation_completed');
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
                            
                            $success = t('operation_completed');
                        } else {
                            $error = "Error updating user: " . $connection->error;
                        }
                        break;
                        
                    case 'delete_user':
                        $user_id = $connection->real_escape_string($_POST['user_id']);
                        
                        if ($user_id == $current_user['id']) {
                            $error = t('cannot_delete_self');
                        } else {
                            $old_sql = "SELECT username, email, full_name, role FROM {$db_config['users_table']} WHERE id = '$user_id'";
                            $old_result = $connection->query($old_sql);
                            $old_data = $old_result->fetch_assoc();
                            
                            $sql = "DELETE FROM {$db_config['users_table']} WHERE id = '$user_id'";
                            if ($connection->query($sql)) {
                                log_change($connection, $db_config['users_table'], $user_id, 'DELETE', $old_data, null, 'username,email,full_name,role');
                                
                                $success = t('item_deleted');
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

// Handle DPIA operations
if ($is_logged_in && isset($_POST['dpia_action'])) {
    debug_log("DPIA action detected: " . $_POST['dpia_action']);
    
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
        
        switch ($_POST['dpia_action']) {
            case 'add_dpia':
                debug_log("Processing add_dpia action");
                
                if (!has_permission('manage_dpia')) {
                    $error = t('no_permission_view');
                    debug_log("User doesn't have permission to add DPIA");
                    break;
                }
                
                $record_id = intval($_POST['record_id']);
                debug_log("Record ID from form: $record_id");
                
                $data = [
                    'description' => trim($_POST['description'] ?? ''),
                    'necessity_proportionality' => trim($_POST['necessity_proportionality'] ?? ''),
                    'mitigation_measures' => trim($_POST['mitigation_measures'] ?? ''),
                    'residual_risk' => trim($_POST['residual_risk'] ?? ''),
                    'overall_risk_level' => $_POST['overall_risk_level'] ?? 'medium',
                    'status' => $_POST['status'] ?? 'pending',
                    'notes' => trim($_POST['notes'] ?? '')
                ];
                
                debug_log("DPIA form data received", $data);
                
                // Validate required fields
                $required_fields = ['description', 'necessity_proportionality', 'mitigation_measures', 'residual_risk'];
                $missing_fields = [];
                
                foreach ($required_fields as $field) {
                    if (empty($data[$field])) {
                        $missing_fields[] = $field;
                    }
                }
                
                if (!empty($missing_fields)) {
                    $error = "Please fill in all required fields: " . implode(', ', $missing_fields);
                    debug_log("Missing required fields: " . implode(', ', $missing_fields));
                    break;
                }
                
                if (register_dpia($connection, $record_id, $data)) {
                    $success = t('dpia_registration_success');
                    debug_log("DPIA registration successful, redirecting...");
                    
                    // Close connection and redirect
                    $connection->close();
                    header("Location: ?view_dpias=1");
                    exit();
                } else {
                    $error = t('dpia_already_registered') . " or database error";
                    debug_log("DPIA registration failed");
                }
                break;
                
            case 'edit_dpia':
                debug_log("Processing edit_dpia action");
                
                if (!has_permission('manage_dpia')) {
                    $error = t('no_permission_view');
                    debug_log("User doesn't have permission to edit DPIA");
                    break;
                }
                
                $dpia_id = intval($_POST['dpia_id']);
                debug_log("DPIA ID from form: $dpia_id");
                
                $data = [
                    'description' => trim($_POST['description'] ?? ''),
                    'necessity_proportionality' => trim($_POST['necessity_proportionality'] ?? ''),
                    'mitigation_measures' => trim($_POST['mitigation_measures'] ?? ''),
                    'residual_risk' => trim($_POST['residual_risk'] ?? ''),
                    'overall_risk_level' => $_POST['overall_risk_level'] ?? 'medium',
                    'status' => $_POST['status'] ?? 'pending',
                    'notes' => trim($_POST['notes'] ?? '')
                ];
                
                debug_log("DPIA edit data received", $data);
                
                // Validate required fields
                $required_fields = ['description', 'necessity_proportionality', 'mitigation_measures', 'residual_risk'];
                $missing_fields = [];
                
                foreach ($required_fields as $field) {
                    if (empty($data[$field])) {
                        $missing_fields[] = $field;
                    }
                }
                
                if (!empty($missing_fields)) {
                    $error = "Please fill in all required fields: " . implode(', ', $missing_fields);
                    debug_log("Missing required fields: " . implode(', ', $missing_fields));
                    break;
                }
                
                if (update_dpia($connection, $dpia_id, $data)) {
                    $success = t('operation_completed');
                    debug_log("DPIA update successful, redirecting...");
                    
                    // Close connection and redirect
                    $connection->close();
                    header("Location: ?view_dpia=" . $dpia_id);
                    exit();
                } else {
                    $error = "Error updating DPIA";
                    debug_log("DPIA update failed");
                }
                break;
                
            case 'remove_dpia':
                debug_log("Processing remove_dpia action");
                
                if (!has_permission('manage_dpia')) {
                    $error = t('no_permission_view');
                    break;
                }
                
                $record_id = intval($_POST['record_id']);
                debug_log("Removing DPIA for record ID: $record_id");
                
                if (remove_dpia($connection, $record_id)) {
                    $success = t('dpia_removed');
                    debug_log("DPIA removal successful, redirecting...");
                    
                    // Close connection and redirect
                    $connection->close();
                    header("Location: ?");
                    exit();
                } else {
                    $error = "Error removing DPIA";
                    debug_log("DPIA removal failed");
                }
                break;
                
            case 'delete_dpia':
                debug_log("Processing delete_dpia action");
                
                if (!has_permission('manage_dpia')) {
                    $error = t('no_permission_view');
                    break;
                }
                
                $dpia_id = intval($_POST['dpia_id']);
                debug_log("Deleting DPIA ID: $dpia_id");
                
                if (delete_dpia($connection, $dpia_id)) {
                    $success = t('item_deleted');
                    debug_log("DPIA delete successful, redirecting...");
                    
                    // Close connection and redirect
                    $connection->close();
                    header("Location: ?view_dpias=1");
                    exit();
                } else {
                    $error = "Error deleting DPIA";
                    debug_log("DPIA delete failed");
                }
                break;
        }
        
        $connection->close();
    } catch (Exception $e) {
        $error = $e->getMessage();
        debug_log("Exception in DPIA handling: " . $e->getMessage());
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
                            
                            $success = t('operation_completed');
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
                                
                                $success = t('operation_completed');
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
                            
                            $success = t('item_deleted');
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

// Get table data if connected and logged in
if ($is_logged_in && !$error) {
    try {
        // Create a new connection for data fetching
        $data_connection = new mysqli(
            $db_config['host'],
            $db_config['username'],
            $db_config['password'],
            $db_config['database']
        );
        
        if ($data_connection->connect_error) {
            throw new Exception("Connection failed: " . $data_connection->connect_error);
        }
        
        // Store this connection for later use in has_dpia() calls
        $global_connection = $data_connection;
        
        // Get columns first
        $columns = getTableColumns($data_connection, $db_config['table']);
        
        $column_names = [];
        foreach ($columns as $col) {
            $column_names[] = $col['Field'];
        }
        
        // Start building the SQL query - NO LIMIT!
        $sql = "SELECT * FROM {$db_config['table']}";
        
        // Check for custom sort request
        if (isset($_GET['sort'])) {
            $requested_sort_column = $_GET['sort'];
            
            if (validateColumnExists($data_connection, $db_config['table'], $requested_sort_column)) {
                $sort_column = $requested_sort_column;
                $sort_direction = isset($_GET['dir']) && $_GET['dir'] === 'desc' ? 'DESC' : 'ASC';
                $sql .= " ORDER BY `$sort_column` $sort_direction";
            } else {
                $error = "Invalid sort column: '$requested_sort_column'. Available columns: " . implode(', ', $column_names);
            }
        } else {
            // DEFAULT SORTING: first by verwerkingsactiviteit (or another column)
            // Check if verwerkingsactiviteit column exists
            $verwerkingsactiviteit_exists = validateColumnExists($data_connection, $db_config['table'], 'verwerkingsactiviteit');
            
            if ($verwerkingsactiviteit_exists) {
                // Sort by verwerkingsactiviteit ASC
                $sql .= " ORDER BY verwerkingsactiviteit ASC";
            } else {
                // Fallback to ID
                $sql .= " ORDER BY id ASC";
            }
        }
        
        // NO LIMIT ADDED HERE - SHOW ALL ROWS
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM {$db_config['table']}";
        $count_result = $data_connection->query($count_sql);
        if ($count_result) {
            $total_rows = $count_result->fetch_assoc()['total'];
        }
        
        try {
            $result = $data_connection->query($sql);
            if (!$result) {
                throw new Exception("Query failed: " . $data_connection->error . " | SQL: " . $sql);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            $result = null;
        }
        
        // Get all records for printing
        $all_records = [];
        if ($result) {
            $result->data_seek(0);
            while ($row = $result->fetch_assoc()) {
                $all_records[] = $row;
            }
            $result->data_seek(0); // Reset pointer
        }
        
        // Get records not recently updated for recommendations
        $records_to_update = get_records_not_recently_updated($data_connection);
        $count_records_to_update = get_count_records_not_recently_updated($data_connection);
        
        // Get update statistics
        $update_statistics = get_update_statistics($data_connection);
        
        // Handle edit request
        if (isset($_GET['edit'])) {
            if (has_permission('edit')) {
                $edit_id = $data_connection->real_escape_string($_GET['edit']);
                $edit_result = $data_connection->query("SELECT * FROM {$db_config['table']} WHERE id = '$edit_id' LIMIT 1");
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
        
        // Handle compact print request
        if ($print_compact) {
            if (has_permission('print')) {
                $compact_data = get_compact_table_data_updated($data_connection, $all_records, $rot47_columns);
            }
        }
        
        // Handle full details print request WITHOUT SENSITIVE COLUMNS
        if ($print_individual > 0 && $print_full_details) {
            if (has_permission('print')) {
                $full_record_details = get_full_record_details_for_print($data_connection, $print_individual, $rot47_columns);
            }
        }
        
        // Handle batch print all records with DPIA
        if ($print_all_with_dpia) {
            if (has_permission('print')) {
                $batch_records = get_all_records_with_dpia_for_print($data_connection, $rot47_columns, $only_with_dpia);
            }
        }
        
        // Get DPIA info for edit/view
        if ($edit_dpia_id > 0) {
            $dpia_info = get_dpia_info($data_connection, $edit_dpia_id);
        } elseif ($view_dpia_id > 0) {
            $dpia_info = get_dpia_info($data_connection, $view_dpia_id);
        }
        
        // Get record name and security measures for DPIA add form
        $record_name = '';
        $security_measures = '';
        if ($add_dpia_record > 0) {
            $record_name = get_record_verwerkingsactiviteit($data_connection, $add_dpia_record);
            $security_measures = get_security_measures_from_record($data_connection, $add_dpia_record);
        }
        
        // Get users list for management
        $users_list = [];
        if (has_permission('manage_users')) {
            $users_result = $data_connection->query("SELECT * FROM {$db_config['users_table']} ORDER BY username");
            if ($users_result) {
                while ($user = $users_result->fetch_assoc()) {
                    $users_list[] = $user;
                }
            }
        }
        
        // Get changes for review mode
        if ($show_changes || isset($_GET['view_changes'])) {
            if (has_permission('view_changes') || (has_permission('view_own_changes') && !has_permission('view_changes'))) {
                $changes_sql = "SELECT c.*, u.username, u.full_name 
                               FROM {$db_config['changes_table']} c 
                               LEFT JOIN {$db_config['users_table']} u ON c.changed_by = u.id";
                
                if (!has_permission('view_changes') && has_permission('view_own_changes')) {
                    $changes_sql .= " WHERE c.changed_by = {$current_user['id']}";
                }
                
                $changes_sql .= " ORDER BY c.changed_at DESC LIMIT 100";
                
                $changes_result = $data_connection->query($changes_sql);
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
        
        // Get DPIA list and counts if showing DPIA list
        if ($show_dpia_list) {
            $dpias = get_all_dpias($data_connection, $dpia_status_filter);
            
            // Get counts for filter buttons
            $all_dpias_count = count(get_all_dpias($data_connection, null));
            $open_dpias_count = count(get_all_dpias($data_connection, 'open'));
            $closed_dpias_count = count(get_all_dpias($data_connection, 'closed'));
            $pending_dpias_count = count(get_all_dpias($data_connection, 'pending'));
        }
        
        // Don't close the connection yet - keep it open for has_dpia() calls
        // The connection will be closed at the end of the script
        
    } catch (Exception $e) {
        $error = $e->getMessage();
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
    <title><?= t('site_name') ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Arial', sans-serif; background: #ffffff; color: #000000; line-height: 1.6; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        /* Login Styles */
        .login-container { 
            max-width: 400px; 
            margin: 100px auto; 
            background: #fff; 
            border: 1px solid #000; 
            padding: 2rem; 
        }
        
        .login-title { 
            text-align: center; 
            margin-bottom: 1.5rem; 
            font-size: 1.5rem; 
            color: #000; 
        }
        
        .login-form .form-group { margin-bottom: 1rem; }
        .login-form .form-label { display: block; margin-bottom: 0.5rem; color: #000; }
        .login-form .form-input { 
            width: 100%; 
            padding: 0.75rem; 
            border: 1px solid #000; 
            background: #fff; 
            color: #000; 
        }
        
        /* Main Application Styles */
        header { 
            background: #000; 
            color: #fff; 
            padding: 2rem; 
            margin-bottom: 2rem; 
            border: 1px solid #000; 
        }
        
        header h1 { 
            font-size: 2rem; 
            margin-bottom: 0.5rem; 
            font-weight: normal; 
        }
        
        header p { 
            opacity: 0.8; 
            font-size: 1rem; 
        }
        
        nav { 
            background: #f8f8f8; 
            padding: 1rem; 
            border: 1px solid #000; 
            margin-bottom: 2rem; 
        }
        
        .nav-links { 
            display: flex; 
            gap: 0.5rem; 
            flex-wrap: wrap; 
        }
        
        .nav-links a { 
            color: #000; 
            text-decoration: none; 
            padding: 0.75rem 1.5rem; 
            border: 1px solid #000; 
            background: #fff; 
            transition: all 0.2s; 
            font-size: 0.9rem; 
        }
        
        .nav-links a:hover, .nav-links a.active { 
            background: #000; 
            color: #fff; 
        }
        
        .nav-link-btn { 
            color: #000; 
            text-decoration: none; 
            padding: 0.75rem 1.5rem; 
            border: 1px solid #000; 
            background: #fff; 
            transition: all 0.2s; 
            font-size: 0.9rem; 
            cursor: pointer;
            font-family: inherit;
        }
        
        .nav-link-btn:hover { 
            background: #000; 
            color: #fff; 
        }
        
        .language-selector { 
            position: absolute; 
            top: 2rem; 
            right: 2rem; 
        }
        
        .language-selector select { 
            padding: 0.5rem; 
            border: 1px solid #000; 
            background: #fff; 
            color: #000; 
        }
        
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 1rem; 
            margin-bottom: 2rem; 
        }
        
        .stat-card { 
            background: #fff; 
            padding: 1.5rem; 
            border: 1px solid #000; 
            text-align: center; 
        }
        
        .stat-number { 
            font-size: 2rem; 
            font-weight: bold; 
            margin-bottom: 0.5rem; 
        }
        
        .stat-label { 
            color: #666; 
            font-size: 0.9rem; 
        }
        
        .card { 
            background: #fff; 
            padding: 1.5rem; 
            border: 1px solid #000; 
            margin-bottom: 1.5rem; 
        }
        
        .card-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 1.5rem; 
            flex-wrap: wrap; 
            gap: 1rem; 
        }
        
        .card-header h2 { 
            font-size: 1.5rem; 
            font-weight: normal; 
            margin: 0; 
        }
        
        .btn { 
            display: inline-block; 
            padding: 0.75rem 1.5rem; 
            background: #000; 
            color: #fff; 
            text-decoration: none; 
            border: 1px solid #000; 
            cursor: pointer; 
            transition: all 0.2s; 
            font-size: 0.9rem; 
        }
        
        .btn:hover { 
            background: #fff; 
            color: #000; 
        }
        
        .btn-outline { 
            background: #fff; 
            color: #000; 
        }
        
        .btn-outline:hover { 
            background: #000; 
            color: #fff; 
        }
        
        .btn-sm { 
            padding: 0.5rem 1rem; 
            font-size: 0.8rem; 
        }
        
        .form-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 1rem; 
        }
        
        .form-group { 
            margin-bottom: 1rem; 
        }
        
        label { 
            display: block; 
            margin-bottom: 0.5rem; 
            font-weight: bold; 
        }
        
        input, select, textarea { 
            width: 100%; 
            padding: 0.75rem; 
            border: 1px solid #000; 
            background: #fff; 
            color: #000; 
            font-size: 1rem; 
        }
        
        textarea {
            min-height: 100px;
        }
        
        input:focus, select:focus, textarea:focus { 
            outline: none; 
            border-color: #000; 
        }
        
        .table-responsive { 
            overflow-x: auto; 
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            background: #fff; 
        }
        
        th, td { 
            padding: 1rem; 
            text-align: left; 
            border-bottom: 1px solid #000; 
        }
        
        th { 
            background: #f8f8f8; 
            font-weight: bold; 
        }
        
        tr:hover { 
            background: #f8f8f8; 
        }
        
        .badge { 
            display: inline-block; 
            padding: 0.25rem 0.75rem; 
            border-radius: 3px; 
            font-size: 0.8rem; 
            font-weight: bold; 
        }
        
        .badge-success { 
            background: #4CAF50; 
            color: white; 
        }
        
        .badge-warning { 
            background: #ff9800; 
            color: white; 
        }
        
        .badge-danger { 
            background: #f44336; 
            color: white; 
        }
        
        .badge-info { 
            background: #2196F3; 
            color: white; 
        }
        
        .badge-secondary { 
            background: #9e9e9e; 
            color: white; 
        }
        
        .search-box { 
            background: #f8f8f8; 
            padding: 1rem; 
            border: 1px solid #000; 
            margin-bottom: 1.5rem; 
        }
        
        .search-form { 
            display: flex; 
            gap: 1rem; 
        }
        
        .search-form input { 
            flex: 1; 
        }
        
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: 2fr 1fr; 
            gap: 1.5rem; 
        }
        
        .alert { 
            padding: 1rem; 
            border: 1px solid #000; 
            margin-bottom: 1rem; 
        }
        
        .alert-success { 
            background: #f8f8f8; 
        }
        
        .alert-danger { 
            background: #fff; 
            border-color: #d00; 
        }
        
        .actions { 
            display: flex; 
            gap: 0.5rem; 
            flex-wrap: wrap; 
        }
        
        .document-list { 
            display: grid; 
            gap: 1rem; 
        }
        
        .document-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 1rem; 
            background: #f8f8f8; 
            border: 1px solid #000; 
        }
        
        .document-info { 
            flex: 1; 
        }
        
        .document-actions { 
            display: flex; 
            gap: 0.5rem; 
        }
        
        .risk-item { 
            padding: 1rem; 
            border: 1px solid #000; 
            margin-bottom: 1rem; 
        }
        
        /* Special styles for AVG Register */
        .row-counter { 
            background: #f8f8f8; 
            padding: 1rem; 
            border: 1px solid #000; 
            margin-bottom: 1rem; 
            text-align: center; 
            font-weight: bold; 
        }
        
        .encryption-info { 
            background: #000; 
            color: #fff; 
            padding: 0.5rem; 
            margin: 0.5rem 0; 
            text-align: center; 
            font-size: 0.9rem; 
        }
        
        .timestamp-info { 
            background: #f8f8f8; 
            padding: 1rem; 
            border: 1px solid #000; 
            margin: 1rem 0; 
            text-align: center; 
        }
        
        .encrypted-indicator { 
            color: #900; 
            font-weight: bold; 
            font-size: 0.8rem; 
        }
        
        .timestamp-field { 
            background: #f8f8f8 !important; 
            font-family: 'Courier New', monospace !important; 
        }
        
        .user-info { 
            display: flex; 
            align-items: center; 
            gap: 1rem; 
            margin-top: 1rem; 
        }
        
        .user-role { 
            padding: 0.25rem 0.75rem; 
            border: 1px solid #000; 
            font-size: 0.8rem; 
            font-weight: bold; 
        }
        
        .role-admin { 
            background: #900; 
            color: #fff; 
        }
        
        .role-editor { 
            background: #090; 
            color: #fff; 
        }
        
        .role-viewer { 
            background: #009; 
            color: #fff; 
        }
        
        .home-btn { 
            display: inline-flex; 
            align-items: center; 
            gap: 0.5rem; 
            text-decoration: none; 
        }
        
        .home-icon { 
            display: inline-block; 
            width: 16px; 
            height: 16px; 
            background-color: #fff; 
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z'/%3E%3C/svg%3E") no-repeat center; 
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z'/%3E%3C/svg%3E") no-repeat center; 
            mask-size: contain; 
            -webkit-mask-size: contain; 
        }
        
        .ipv4-format { 
            font-family: 'Courier New', monospace; 
            font-size: 0.9rem; 
        }
        
        .dpia-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-left: 0.5rem;
        }
        
        .dpia-open {
            background: #ff9800;
            color: white;
        }
        
        .dpia-closed {
            background: #4CAF50;
            color: white;
        }
        
        .dpia-pending {
            background: #2196F3;
            color: white;
        }
        
        .dpia-risk-low {
            background: #4CAF50;
            color: white;
        }
        
        .dpia-risk-medium {
            background: #ff9800;
            color: white;
        }
        
        .dpia-risk-high {
            background: #f44336;
            color: white;
        }
        
        .dpia-details {
            background: #f8f8f8;
            padding: 1rem;
            border: 1px solid #ddd;
            margin: 1rem 0;
            border-radius: 3px;
        }
        
        .dpia-field {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .dpia-field:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .dpia-field-label {
            font-weight: bold;
            color: #000;
            margin-bottom: 0.25rem;
            display: block;
        }
        
        .dpia-field-value {
            padding: 0.75rem;
            background: white;
            border: 1px solid #ddd;
            border-radius: 3px;
            word-wrap: break-word;
        }
        
        .dpia-filter {
            background: #f8f8f8;
            padding: 1rem;
            border: 1px solid #000;
            margin-bottom: 1rem;
        }
        
        .dpia-filter-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .dpia-filter-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #000;
            background: white;
            color: #000;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .dpia-filter-btn.active {
            background: #000;
            color: white;
        }
        
        .dpia-filter-btn:hover:not(.active) {
            background: #f0f0f0;
        }
        
        /* Add Button Menu Styles */
        .add-button-menu {
            position: relative;
            display: inline-block;
        }
        
        .add-button-menu .btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .add-button-menu-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 350px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            border: 1px solid #000;
            z-index: 1000;
            top: 100%;
            left: 0;
            margin-top: 5px;
        }
        
        .add-button-menu:hover .add-button-menu-content {
            display: block;
        }
        
        .add-menu-header {
            background: #000;
            color: white;
            padding: 1rem;
            font-weight: bold;
        }
        
        .add-menu-section {
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .add-menu-section:last-child {
            border-bottom: none;
        }
        
        .add-menu-section h4 {
            margin-bottom: 0.75rem;
            color: #000;
            font-size: 1rem;
        }
        
        .recommendation-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .recommendation-item {
            padding: 0.75rem;
            border: 1px solid #eee;
            margin-bottom: 0.5rem;
            border-radius: 3px;
            transition: all 0.2s;
        }
        
        .recommendation-item:hover {
            background: #f8f8f8;
            border-color: #000;
        }
        
        .recommendation-item.warning {
            border-left: 4px solid #ff9800;
        }
        
        .recommendation-item.danger {
            border-left: 4px solid #f44336;
        }
        
        .recommendation-title {
            font-weight: bold;
            color: #000;
            margin-bottom: 0.25rem;
        }
        
        .recommendation-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #666;
        }
        
        .menu-action-btn {
            display: block;
            width: 100%;
            text-align: left;
            padding: 0.75rem;
            border: 1px solid #eee;
            background: white;
            color: #000;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            text-decoration: none;
        }
        
        .menu-action-btn:hover {
            background: #000;
            color: white;
            border-color: #000;
        }
        
        .menu-action-btn:last-child {
            margin-bottom: 0;
        }
        
        .menu-action-btn.primary {
            background: #000;
            color: white;
            border-color: #000;
        }
        
        .menu-action-btn.primary:hover {
            background: #333;
        }
        
        .update-badge {
            background: #ff9800;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-left: 0.5rem;
        }
        
        .dropdown-icon {
            display: inline-block;
            width: 12px;
            height: 12px;
            background-color: white;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat center;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat center;
            mask-size: contain;
            -webkit-mask-size: contain;
        }
        
        /* Update Statistics Styles */
        .update-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .update-stat-card {
            background: #f8f8f8;
            padding: 1rem;
            border: 1px solid #ddd;
            border-radius: 3px;
            text-align: center;
        }
        
        .update-stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }
        
        .update-stat-label {
            color: #666;
            font-size: 0.8rem;
        }
        
        .update-distribution {
            margin-top: 1rem;
        }
        
        .distribution-bar {
            height: 20px;
            background: #eee;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }
        
        .distribution-fill {
            height: 100%;
            background: #4CAF50;
            transition: width 0.3s;
        }
        
        .distribution-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }
        
        .priority-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .priority-high {
            background-color: #f44336;
        }
        
        .priority-medium {
            background-color: #ff9800;
        }
        
        .priority-low {
            background-color: #ffc107;
        }
        
        .priority-none {
            background-color: #4CAF50;
        }
        
        /* NEW: Sensitive columns removed for print */
        .safe-print-indicator {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 0.5rem;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        
        .excluded-columns-list {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.5rem;
        }
        
        /* Batch Print Styles */
        .batch-print-options {
            background: #f8f8f8;
            padding: 1rem;
            border: 1px solid #000;
            margin-bottom: 1rem;
        }
        
        .batch-print-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .batch-stat {
            background: white;
            padding: 0.75rem;
            border: 1px solid #000;
            border-radius: 3px;
            min-width: 150px;
        }
        
        .batch-stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 0.25rem;
        }
        
        .batch-stat-label {
            text-align: center;
            font-size: 0.9rem;
            color: #666;
        }
        
        .batch-print-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .print-progress {
            margin: 1rem 0;
            background: #f8f8f8;
            border: 1px solid #000;
            padding: 1rem;
        }
        
        .progress-bar {
            height: 20px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: #4CAF50;
            transition: width 0.3s ease;
        }
        
        /* COMPACT PRINT STYLES - UPDATED */
        @media print {
            * {
                background: transparent !important;
                color: #000 !important;
                box-shadow: none !important;
                text-shadow: none !important;
            }
            
            body {
                font-family: "Arial", sans-serif;
                font-size: 9pt; /* Smaller font for compact printing */
                line-height: 1.3;
                color: #000;
                background: #fff;
                margin: 0;
                padding: 0.5cm;
            }
            
            /* Watermark - ALWAYS ON */
            body::before {
                content: "<?= t('confidential') ?>";
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-45deg);
                font-size: 40pt; /* Smaller watermark */
                color: rgba(0,0,0,0.08); /* Lighter watermark */
                z-index: -1;
                pointer-events: none;
                white-space: nowrap;
                opacity: 0.7;
            }
            
            /* Hide non-essential elements */
            header, nav, .nav-links, .add-button-menu, 
            .messages, .row-counter, .actions, 
            .card-header .btn, .btn, 
            .language-selector, .user-info,
            .home-btn, .add-button-menu,
            .dpia-badge, .badge,
            .timestamp-info, .encryption-info,
            .update-badge, .priority-indicator,
            .recommendation-list, .update-stats-grid,
            .distribution-bar, .add-menu-section,
            .update-distribution, .search-box,
            .dpia-filter, .dpia-filter-buttons,
            .alert, .modal, .form-grid,
            .update-stats-grid, .distribution-bar,
            .add-menu-section, .recommendation-list,
            .back-to-list-btn, .print-options,
            .dpia-filter, .card-header .btn,
            .actions, .update-badge,
            .batch-print-options, .batch-print-stats,
            .batch-print-controls, .print-progress {
                display: none !important;
            }
            
            /* Card styling for print */
            .card {
                border: none;
                margin: 0;
                padding: 0;
                page-break-inside: avoid;
                box-shadow: none;
            }
            
            .card-header {
                border-bottom: 1px solid #000;
                margin-bottom: 5px;
                padding-bottom: 5px;
                display: block !important;
            }
            
            .card-header h2 {
                font-size: 11pt;
                margin: 0 0 3px 0;
            }
            
            /* Print header - COMPACT */
            .print-header {
                text-align: center;
                margin-bottom: 10px;
                padding-bottom: 5px;
                border-bottom: 1px solid #000;
                page-break-after: avoid;
            }
            
            .print-header h1 {
                font-size: 12pt;
                margin: 0 0 3px 0;
                font-weight: bold;
            }
            
            .print-info {
                font-size: 7pt;
                color: #666;
                line-height: 1.2;
            }
            
            /* Control page breaks */
            .page-break {
                page-break-before: always;
            }
            
            /* EXTREMELY COMPACT TABLE STYLES FOR BULK PRINTING */
            .compact-print-table {
                width: 100% !important;
                border-collapse: collapse;
                font-size: 7pt !important; /* Very small font */
                page-break-inside: auto;
                margin: 5px 0;
            }
            
            .compact-print-table th,
            .compact-print-table td {
                border: 0.5pt solid #888 !important; /* Very thin borders */
                padding: 2px 3px !important; /* Minimal padding */
                text-align: left;
                vertical-align: top;
                line-height: 1.1;
            }
            
            .compact-print-table th {
                background: #f0f0f0 !important;
                font-weight: bold;
                white-space: nowrap;
            }
            
            .compact-print-table td {
                word-wrap: break-word;
                max-width: 150px; /* Limit column width */
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            /* Compact column widths */
            .compact-id { width: 4% !important; }
            .compact-activity { width: 30% !important; }
            .compact-agreement { width: 20% !important; }
            .compact-processor { width: 15% !important; }
            .compact-dpia { width: 10% !important; }
            .compact-update { width: 15% !important; }
            
            /* For full details print */
            .full-details-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 5px;
                font-size: 8pt;
            }
            
            .full-details-grid .form-group {
                margin-bottom: 5px;
                page-break-inside: avoid;
            }
            
            .full-details-grid label {
                font-weight: bold;
                font-size: 8pt;
                margin-bottom: 1px;
            }
            
            .full-details-grid .form-value {
                padding: 3px;
                border: 0.5pt solid #ddd;
                border-radius: 2px;
                min-height: 15px;
                font-size: 8pt;
                word-wrap: break-word;
            }
            
            /* Ensure links are visible in print */
            a {
                color: #000 !important;
                text-decoration: none !important;
            }
            
            /* Print footer - COMPACT */
            .print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 6pt;
                color: #666;
                border-top: 0.5pt solid #ccc;
                padding: 2px 0;
                background: #fff;
            }
            
            /* Summary info for compact print */
            .print-summary {
                font-size: 7pt;
                margin-bottom: 5px;
                color: #666;
                text-align: center;
                border-bottom: 0.5pt solid #ccc;
                padding-bottom: 3px;
            }
            
            /* Force black and white printing */
            * {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Avoid page breaks inside tables */
            table {
                page-break-inside: avoid;
            }
            
            /* Avoid page breaks after headers */
            h1, h2, h3 {
                page-break-after: avoid;
            }
            
            /* Footer on each page */
            @page {
                margin: 1cm;
                @bottom-center {
                    content: "<?= t('confidential') ?> - <?= t('page') ?> " counter(page);
                    font-size: 7pt;
                    color: #666;
                }
            }
            
            /* Safe print indicator */
            .safe-print-indicator {
                background: #d4edda !important;
                border: 0.5pt solid #c3e6cb !important;
                padding: 3px !important;
                font-size: 7pt !important;
                margin-bottom: 5px !important;
            }
            
            /* Batch print separator */
            .record-separator {
                page-break-before: always;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 2px solid #000;
            }
            
            .record-separator:first-child {
                page-break-before: avoid;
                border-top: none;
                margin-top: 0;
                padding-top: 0;
            }
        }
        
        /* Print button styles for normal view */
        .print-btn-compact {
            background: #4CAF50;
            color: white;
            border: 1px solid #4CAF50;
        }
        
        .print-btn-compact:hover {
            background: #45a049;
        }
        
        .print-btn-full {
            background: #2196F3;
            color: white;
            border: 1px solid #2196F3;
        }
        
        .print-btn-full:hover {
            background: #0b7dda;
        }
        
        /* UPDATED: Secure print button style */
        .print-btn-secure {
            background: #28a745;
            color: white;
            border: 1px solid #28a745;
        }
        
        .print-btn-secure:hover {
            background: #218838;
        }
        
        /* Batch print button style */
        .print-btn-batch {
            background: #6f42c1;
            color: white;
            border: 1px solid #6f42c1;
        }
        
        .print-btn-batch:hover {
            background: #5a32a3;
        }
        
        /* NEW: Modal styles for change details */
        .modal {
            animation: fadeIn 0.2s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal .card-header {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        
        /* Diff highlighting */
        .diff-added {
            background: #d4edda;
            color: #155724;
            padding: 0.125rem 0.25rem;
            border-radius: 2px;
        }
        
        .diff-removed {
            background: #f8d7da;
            color: #721c24;
            padding: 0.125rem 0.25rem;
            border-radius: 2px;
            text-decoration: line-through;
        }
        
        .diff-changed {
            background: #fff3cd;
            color: #856404;
            padding: 0.125rem 0.25rem;
            border-radius: 2px;
        }
        
        /* Field change cards */
        .field-change-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: box-shadow 0.2s;
        }
        
        .field-change-card:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        
        .field-change-card h5 {
            margin: 0 0 0.5rem 0;
            color: #333;
            font-size: 1rem;
        }
        
        .field-value {
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-break: break-word;
            padding: 0.75rem;
            background: #f9f9f9;
            border-radius: 3px;
            border: 1px solid #e0e0e0;
            margin-top: 0.25rem;
        }
        
        .field-value.old {
            border-left: 3px solid #f44336;
        }
        
        .field-value.new {
            border-left: 3px solid #4CAF50;
        }
        
        /* Scrollable sections */
        .scrollable-section {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 1rem;
            background: #fafafa;
        }
        
        @media (max-width: 768px) {
            .form-grid, .dashboard-grid { 
                grid-template-columns: 1fr; 
            }
            
            .nav-links { 
                flex-direction: column; 
            }
            
            .card-header { 
                flex-direction: column; 
                align-items: flex-start; 
            }
            
            .actions { 
                flex-direction: column; 
            }
            
            .language-selector { 
                position: static; 
                margin-bottom: 1rem; 
            }
            
            table { 
                font-size: 0.9rem; 
            }
            
            th, td { 
                padding: 0.5rem; 
            }
            
            .dpia-filter-buttons {
                flex-direction: column;
            }
            
            .dpia-filter-btn {
                text-align: center;
            }
            
            .add-button-menu-content {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 90%;
                max-width: 400px;
                max-height: 80vh;
                overflow-y: auto;
            }
            
            .update-stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .batch-print-stats {
                flex-direction: column;
            }
            
            .batch-stat {
                min-width: 100%;
            }
            
            /* Responsive adjustments for modal */
            .modal .card {
                width: 98% !important;
                max-height: 98vh !important;
            }
            
            .modal-grid {
                grid-template-columns: 1fr !important;
            }
            
            .modal .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body <?php if (($print_compact || ($print_individual > 0 && $print_full_details) || $print_all_with_dpia) && isset($_GET['auto_print'])): ?>onload="window.print();"<?php endif; ?>>
    <div class="container">
        <?php if (!$is_logged_in): ?>
            <!-- Login Form -->
            <div class="login-container">
                <div class="login-title"><?= t('site_name') ?> - <?= t('login') ?></div>
                
                <div class="messages">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                </div>
                
                <form method="POST" class="login-form">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group">
                        <label class="form-label"><?= t('username') ?>:</label>
                        <input type="text" name="username" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label"><?= t('password') ?>:</label>
                        <input type="password" name="password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn"><?= t('login') ?></button>
                    </div>
                </form>
                
                <div style="text-align: center; margin-top: 15px; font-size: 0.9rem; color: #666;">
                    Default admin: admin / admin123
                </div>
            </div>
            
        <?php else: ?>
            <!-- Main Application -->
            <?php if (!$print_individual && !$print_compact && !$print_all_with_dpia): ?>
                <header>
                    <div class="language-selector">
                        <span style="color: #fff;">English</span>
                    </div>
                    
                    <h1><?= t('site_name') ?></h1>
                    <p><?= t('welcome') ?></p>
                    
                    <div class="user-info">
                        <div>
                            <strong><?= htmlspecialchars($current_user['full_name']); ?></strong>
                            <div style="font-size: 0.9rem;"><?= htmlspecialchars($current_user['username']); ?></div>
                        </div>
                        <div class="user-role role-<?= htmlspecialchars($current_user['role']); ?>">
                            <?= strtoupper(htmlspecialchars($current_user['role'])); ?>
                        </div>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="btn btn-sm"><?= t('logout') ?></button>
                        </form>
                    </div>
                </header>
                
                <nav>
                    <div class="nav-links">
                        <a href="?" class="home-btn">
                            <span class="home-icon"></span> <?= t('home') ?>
                        </a>
                        <?php if (has_permission('view_changes') || has_permission('view_own_changes')): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="show_changes">
                                <button type="submit" class="nav-link-btn"><?= t('review_mode') ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if (has_permission('manage_dpia')): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="show_dpias">
                                <button type="submit" class="nav-link-btn"><?= t('dpia_management') ?></button>
                            </form>
                        <?php endif; ?>
                        
                        <!-- UPDATED: Multiple Clickable Print Buttons in Navigation -->
                        <?php if (has_permission('print')): ?>
                            <a href="?print_compact=1&auto_print=1" class="nav-link-btn print-btn-compact" 
                               title="<?= t('print_compact_table') ?>">
                                🖨️ <?= t('print_compact') ?>
                            </a>
                            <a href="?print_all_with_dpia=1&auto_print=1" class="nav-link-btn print-btn-batch" 
                               title="<?= t('print_all_with_dpia_description') ?>">
                                📚 <?= t('print_all_with_dpia') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </nav>

                <div class="messages">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= t('error') ?>: <?= htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                </div>
                
                <!-- Row Counter -->
                <?php if (has_permission('view') && !isset($_GET['add']) && !$edit_row && !$show_changes && !$show_user_form && !$show_dpia_list && !$add_dpia_record && !$edit_dpia_id && !$view_dpia_id): ?>
                    <div class="row-counter">
                        <?php 
                        $current = $result ? $result->num_rows : 0;
                        echo str_replace(['{current}', '{total}'], [$current, $total_rows], t('row_counter')); 
                        ?>
                        <?php if ($count_records_to_update > 0): ?>
                            <div style="margin-top: 0.5rem; color: #ff9800; font-weight: bold;">
                                ⚠️ <?= t('update_required') ?>: <?= $count_records_to_update ?> <?= t('records_need_update') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Changes View -->
            <?php if ($show_changes && !empty($changes)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><?= t('recent_changes') ?> (Last 100)</h2>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <div style="position: relative;">
                                <input type="text" id="changeSearch" placeholder="Search changes..." 
                                       style="padding: 0.5rem 1rem; border: 1px solid #000; min-width: 250px;">
                                <div id="searchClear" style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); cursor: pointer; display: none;">×</div>
                            </div>
                            <select id="actionFilter" style="padding: 0.5rem; border: 1px solid #000;">
                                <option value="">All Actions</option>
                                <option value="INSERT">Added</option>
                                <option value="UPDATE">Modified</option>
                                <option value="DELETE">Deleted</option>
                            </select>
                            <select id="tableFilter" style="padding: 0.5rem; border: 1px solid #000;">
                                <option value="">All Tables</option>
                                <option value="<?= $db_config['table'] ?>">Processing Activities</option>
                                <option value="<?= $db_config['users_table'] ?>">Users</option>
                                <option value="<?= $db_config['dpia_table'] ?>">DPIAs</option>
                            </select>
                            <a href="?" class="btn"><?= t('back') ?></a>
                        </div>
                    </div>
                    
                    <div style="padding: 0 1rem 1rem 1rem;">
                        <div id="changeStats" style="display: flex; gap: 1rem; font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">
                            <span>Total: <strong id="totalCount"><?= count($changes) ?></strong></span>
                            <span>Filtered: <strong id="filteredCount"><?= count($changes) ?></strong></span>
                        </div>
                    </div>
                    
                    <table id="changesTable">
                        <thead>
                            <tr>
                                <th><?= t('timestamp') ?></th>
                                <th><?= t('action_type') ?></th>
                                <th><?= t('table') ?></th>
                                <th><?= t('record_id') ?></th>
                                <th><?= t('user') ?></th>
                                <th><?= t('user_ip') ?> (IPv4)</th>
                                <th><?= t('details') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($changes as $change): ?>
                                <tr>
                                    <td><?= htmlspecialchars($change['changed_at']); ?></td>
                                    <td>
                                        <span class="badge badge-<?= 
                                            $change['action'] == 'INSERT' ? 'success' : 
                                            ($change['action'] == 'UPDATE' ? 'warning' : 'danger') 
                                        ?>">
                                            <?= get_action_name($change['action']); ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($change['table_name']); ?></td>
                                    <td><?= htmlspecialchars($change['record_id']); ?></td>
                                    <td><?= htmlspecialchars($change['username'] . ' (' . $change['full_name'] . ')'); ?></td>
                                    <td class="ipv4-format"><?= htmlspecialchars($change['user_ip']); ?></td>
                                    <td>
                                        <button onclick="showChangeDetails(<?= htmlspecialchars(str_replace("'", "\\'", json_encode($change))); ?>)" class="btn btn-sm"><?= t('view') ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <script>
                // Add filtering functionality
                document.addEventListener('DOMContentLoaded', function() {
                    const searchInput = document.getElementById('changeSearch');
                    const actionFilter = document.getElementById('actionFilter');
                    const tableFilter = document.getElementById('tableFilter');
                    const searchClear = document.getElementById('searchClear');
                    const totalCount = document.getElementById('totalCount');
                    const filteredCount = document.getElementById('filteredCount');
                    const tableRows = document.querySelectorAll('#changesTable tbody tr');
                    
                    function filterChanges() {
                        const searchTerm = searchInput.value.toLowerCase();
                        const actionValue = actionFilter.value;
                        const tableValue = tableFilter.value;
                        
                        let visibleCount = 0;
                        
                        tableRows.forEach(row => {
                            const rowText = row.textContent.toLowerCase();
                            const action = row.querySelector('td:nth-child(2)').textContent;
                            const table = row.querySelector('td:nth-child(3)').textContent;
                            
                            const matchesSearch = !searchTerm || rowText.includes(searchTerm);
                            const matchesAction = !actionValue || action.includes(actionValue);
                            const matchesTable = !tableValue || table.includes(tableValue);
                            
                            if (matchesSearch && matchesAction && matchesTable) {
                                row.style.display = '';
                                visibleCount++;
                            } else {
                                row.style.display = 'none';
                            }
                        });
                        
                        filteredCount.textContent = visibleCount;
                        searchClear.style.display = searchTerm ? 'block' : 'none';
                    }
                    
                    searchInput.addEventListener('input', filterChanges);
                    actionFilter.addEventListener('change', filterChanges);
                    tableFilter.addEventListener('change', filterChanges);
                    
                    searchClear.addEventListener('click', function() {
                        searchInput.value = '';
                        filterChanges();
                        searchInput.focus();
                    });
                    
                    // Initial filter
                    filterChanges();
                });
                
                function showChangeDetails(change) {
                    let details = `<div class="card" style="max-width: 100%;">
                        <div class="card-header">
                            <h3>Change Details - ${get_action_name(change.action)}</h3>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span class="badge badge-${change.action == 'INSERT' ? 'success' : (change.action == 'UPDATE' ? 'warning' : 'danger')}">
                                    ${change.action}
                                </span>
                                <span>Record ID: ${change.record_id}</span>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1rem;">
                            <div class="card" style="margin: 0;">
                                <div class="card-header" style="background: #f8f8f8;">
                                    <h4>General Information</h4>
                                </div>
                                <div style="padding: 1rem;">
                                    <div style="margin-bottom: 0.75rem;">
                                        <strong>Table:</strong> ${change.table_name}
                                    </div>
                                    <div style="margin-bottom: 0.75rem;">
                                        <strong>User:</strong> ${change.username} (${change.full_name})
                                    </div>
                                    <div style="margin-bottom: 0.75rem;">
                                        <strong>Timestamp:</strong> ${change.changed_at}
                                    </div>
                                    <div style="margin-bottom: 0.75rem;">
                                        <strong>User IP:</strong> <span class="ipv4-format">${change.user_ip}</span>
                                    </div>
                                    <div style="margin-bottom: 0.75rem;">
                                        <strong>User Agent:</strong> ${change.user_agent || 'Not recorded'}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card" style="margin: 0;">
                                <div class="card-header" style="background: #f8f8f8;">
                                    <h4>Change Summary</h4>
                                </div>
                                <div style="padding: 1rem;">
                                    <div style="margin-bottom: 0.75rem;">
                                        <strong>Action:</strong> 
                                        <span class="badge badge-${change.action == 'INSERT' ? 'success' : (change.action == 'UPDATE' ? 'warning' : 'danger')}">
                                            ${get_action_name(change.action)}
                                        </span>
                                    </div>`;
                    
                    if (change.changed_fields) {
                        const fields = change.changed_fields.split(', ');
                        details += `<div style="margin-bottom: 0.75rem;">
                                        <strong>Changed Fields:</strong> ${change.changed_fields}
                                        <div style="font-size: 0.9rem; color: #666; margin-top: 0.25rem;">
                                            (${fields.length} field${fields.length !== 1 ? 's' : ''} changed)
                                        </div>
                                    </div>`;
                    }
                    
                    details += `</div></div></div>`;
                    
                    if (change.old_data || change.new_data) {
                        details += `<div class="card" style="margin-bottom: 1rem;">
                            <div class="card-header" style="background: #f8f8f8;">
                                <h4>Data Comparison</h4>
                            </div>
                            <div style="padding: 1rem;">`;
                        
                        if (change.old_data && change.action !== 'INSERT') {
                            details += `<h5 style="margin-bottom: 0.5rem; color: #900;">Old Data (Before Change)</h5>
                                <div style="max-height: 300px; overflow-y: auto; margin-bottom: 1rem; border: 1px solid #ddd; padding: 0.5rem;">`;
                            for (let key in change.old_data) {
                                details += `<div style="margin-bottom: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f0f0f0;">
                                    <strong style="color: #666;">${key}:</strong> 
                                    <div style="color: #900; font-family: monospace; word-break: break-word;">${formatChangeValue(change.old_data[key])}</div>
                                </div>`;
                            }
                            details += `</div>`;
                        }
                        
                        if (change.new_data && change.action !== 'DELETE') {
                            details += `<h5 style="margin-bottom: 0.5rem; color: #090;">New Data (After Change)</h5>
                                <div style="max-height: 300px; overflow-y: auto; margin-bottom: 1rem; border: 1px solid #ddd; padding: 0.5rem;">`;
                            for (let key in change.new_data) {
                                details += `<div style="margin-bottom: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f0f0f0;">
                                    <strong style="color: #666;">${key}:</strong> 
                                    <div style="color: #090; font-family: monospace; word-break: break-word;">${formatChangeValue(change.new_data[key])}</div>
                                </div>`;
                            }
                            details += `</div>`;
                        }
                        
                        if (change.action === 'UPDATE' && change.old_data && change.new_data) {
                            details += `<h5 style="margin-bottom: 0.5rem; color: #006;">Field-by-Field Changes</h5>
                                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 0.5rem;">`;
                            
                            const allKeys = new Set([...Object.keys(change.old_data), ...Object.keys(change.new_data)]);
                            let changedCount = 0;
                            
                            for (let key of allKeys) {
                                const oldVal = change.old_data[key];
                                const newVal = change.new_data[key];
                                
                                if (oldVal !== newVal) {
                                    changedCount++;
                                    details += `<div style="margin-bottom: 1rem; padding: 0.75rem; background: #f9f9f9; border-radius: 3px; border-left: 4px solid #ff9800;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                            <strong style="color: #000; font-size: 1.1em;">${key}</strong>
                                            <span class="badge badge-warning">CHANGED</span>
                                        </div>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                            <div>
                                                <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.25rem;">Old Value:</div>
                                                <div style="color: #900; padding: 0.5rem; background: #fff; border: 1px solid #ffcdd2; border-radius: 3px; font-family: monospace; word-break: break-word;">
                                                    ${formatChangeValue(oldVal)}
                                                </div>
                                            </div>
                                            <div>
                                                <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.25rem;">New Value:</div>
                                                <div style="color: #090; padding: 0.5rem; background: #fff; border: 1px solid #c8e6c9; border-radius: 3px; font-family: monospace; word-break: break-word;">
                                                    ${formatChangeValue(newVal)}
                                                </div>
                                            </div>
                                        </div>`;
                                    
                                    // Show difference for text fields
                                    if (typeof oldVal === 'string' && typeof newVal === 'string' && 
                                        oldVal.length < 1000 && newVal.length < 1000) {
                                        const diff = getTextDiff(oldVal, newVal);
                                        if (diff) {
                                            details += `<div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed #ddd;">
                                                <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.25rem;">Text Difference:</div>
                                                <div style="font-size: 0.9rem; background: #f8f8f8; padding: 0.5rem; border-radius: 3px; font-family: monospace;">
                                                    ${diff}
                                                </div>
                                            </div>`;
                                        }
                                    }
                                    
                                    details += `</div>`;
                                }
                            }
                            
                            if (changedCount === 0) {
                                details += `<div style="text-align: center; color: #666; padding: 2rem;">
                                    No fields changed (only timestamp updates)
                                </div>`;
                            }
                            
                            details += `</div>`;
                        }
                        
                        details += `</div></div>`;
                    }
                    
                    details += `</div>`;
                    
                    // Create modal with larger size
                    const modal = document.createElement('div');
                    modal.className = 'modal';
                    modal.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background-color: rgba(0,0,0,0.8);
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        z-index: 1000;
                        overflow: auto;
                        padding: 1rem;
                    `;
                    
                    const modalContent = document.createElement('div');
                    modalContent.className = 'card';
                    modalContent.style.cssText = `
                        max-width: 1400px;
                        width: 95%;
                        max-height: 95vh;
                        overflow-y: auto;
                        background: white;
                    `;
                    
                    modalContent.innerHTML = `<div class="card-header" style="position: sticky; top: 0; background: white; z-index: 10; border-bottom: 2px solid #000;">
                            <h2 style="margin: 0; display: flex; align-items: center; gap: 1rem;">
                                <span style="font-size: 1.5rem;"><?= t('change_details') ?></span>
                                <span class="badge badge-${change.action == 'INSERT' ? 'success' : (change.action == 'UPDATE' ? 'warning' : 'danger')}" style="font-size: 1rem;">
                                    ${change.action}
                                </span>
                            </h2>
                            <div>
                                <button onclick="closeModal()" class="btn" style="font-size: 1.2rem; padding: 0.5rem 1.5rem;">× Close</button>
                            </div>
                        </div>
                        <div style="padding: 1.5rem;">
                            ${details}
                        </div>`;
                    
                    modal.appendChild(modalContent);
                    document.body.appendChild(modal);
                    
                    // Prevent body scroll when modal is open
                    document.body.style.overflow = 'hidden';
                    
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeModal();
                        }
                    });
                    
                    modal.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') {
                            closeModal();
                        }
                    });
                    
                    // Focus on modal for keyboard navigation
                    modalContent.focus();
                    
                    function closeModal() {
                        modal.remove();
                        document.body.style.overflow = '';
                    }
                    
                    window.closeModal = closeModal;
                }
                
                // Helper function to format change values
                function formatChangeValue(value) {
                    if (value === null || value === undefined) {
                        return '<span style="color: #999; font-style: italic;">NULL</span>';
                    }
                    
                    if (value === '') {
                        return '<span style="color: #999; font-style: italic;">(empty)</span>';
                    }
                    
                    // Truncate very long values
                    if (typeof value === 'string' && value.length > 500) {
                        return value.substring(0, 497) + '... (truncated, ' + value.length + ' chars total)';
                    }
                    
                    // Escape HTML but preserve line breaks
                    const escaped = String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                    
                    return escaped.replace(/\n/g, '<br>');
                }
                
                // Helper function to get action name
                function get_action_name(action) {
                    const actions = {
                        'INSERT': 'Added',
                        'UPDATE': 'Modified',
                        'DELETE': 'Deleted'
                    };
                    return actions[action] || action;
                }
                
                // Simple text diff function
                function getTextDiff(oldText, newText) {
                    if (!oldText || !newText) return '';
                    
                    const oldLines = oldText.split('\n');
                    const newLines = newText.split('\n');
                    let diff = '';
                    
                    const maxLines = Math.max(oldLines.length, newLines.length);
                    for (let i = 0; i < maxLines; i++) {
                        const oldLine = oldLines[i] || '';
                        const newLine = newLines[i] || '';
                        
                        if (oldLine !== newLine) {
                            if (oldLine && !newLine) {
                                diff += `<div style="color: #900; margin-bottom: 0.25rem;">
                                    <span style="background: #ffcdd2; padding: 0 0.25rem;">-</span> ${oldLine}
                                </div>`;
                            } else if (!oldLine && newLine) {
                                diff += `<div style="color: #090; margin-bottom: 0.25rem;">
                                    <span style="background: #c8e6c9; padding: 0 0.25rem;">+</span> ${newLine}
                                </div>`;
                            } else {
                                diff += `<div style="margin-bottom: 0.5rem;">
                                    <div style="color: #900;">
                                        <span style="background: #ffcdd2; padding: 0 0.25rem;">-</span> ${oldLine}
                                    </div>
                                    <div style="color: #090;">
                                        <span style="background: #c8e6c9; padding: 0 0.25rem;">+</span> ${newLine}
                                    </div>
                                </div>`;
                            }
                        }
                    }
                    
                    return diff || 'No text differences detected';
                }
                </script>
                
            <?php elseif ($show_dpia_list): ?>
                <!-- DPIA Management View -->
                <div class="card">
                    <div class="card-header">
                        <h2><?= t('dpia_management') ?></h2>
                        <a href="?" class="btn"><?= t('back') ?></a>
                    </div>
                    
                    <!-- DPIA Filter -->
                    <div class="dpia-filter">
                        <h3><?= t('filter_by_status') ?></h3>
                        <div class="dpia-filter-buttons">
                            <a href="?view_dpias=1" class="dpia-filter-btn <?= !$dpia_status_filter ? 'active' : '' ?>">
                                <?= t('all_dpias') ?> (<?= $all_dpias_count ?>)
                            </a>
                            <a href="?view_dpias=1&dpia_status=open" class="dpia-filter-btn <?= $dpia_status_filter == 'open' ? 'active' : '' ?>">
                                <?= t('open_dpias') ?> (<?= $open_dpias_count ?>)
                            </a>
                            <a href="?view_dpias=1&dpia_status=closed" class="dpia-filter-btn <?= $dpia_status_filter == 'closed' ? 'active' : '' ?>">
                                <?= t('closed_dpias') ?> (<?= $closed_dpias_count ?>)
                            </a>
                            <a href="?view_dpias=1&dpia_status=pending" class="dpia-filter-btn <?= $dpia_status_filter == 'pending' ? 'active' : '' ?>">
                                <?= t('pending_dpias') ?> (<?= $pending_dpias_count ?>)
                            </a>
                        </div>
                    </div>
                    
                    <?php if (!empty($dpias)): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?= t('dpia_id') ?></th>
                                        <th><?= t('record_id') ?></th>
                                        <th><?= t('processing_activity_name') ?></th>
                                        <th><?= t('description') ?></th>
                                        <th><?= t('overall_risk_level') ?></th>
                                        <th><?= t('dpia_status_field') ?></th>
                                        <th><?= t('registered_date') ?></th>
                                        <th><?= t('last_updated') ?></th>
                                        <th><?= t('actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dpias as $dpia): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($dpia['id']); ?></td>
                                            <td>#<?= htmlspecialchars($dpia['record_id']); ?></td>
                                            <td>
                                                <?php if (!empty($dpia['verwerkingsactiviteit'])): ?>
                                                    <?= htmlspecialchars($dpia['verwerkingsactiviteit']); ?>
                                                <?php else: ?>
                                                    <span style="color: #999; font-style: italic;">No activity name</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars(substr($dpia['description'], 0, 100)); ?>...</td>
                                            <td>
                                                <span class="dpia-badge dpia-risk-<?= htmlspecialchars($dpia['overall_risk_level']); ?>">
                                                    <?= htmlspecialchars(ucfirst($dpia['overall_risk_level'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="dpia-badge dpia-<?= htmlspecialchars($dpia['status']); ?>">
                                                    <?= htmlspecialchars(ucfirst($dpia['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($dpia['registered_at']); ?></td>
                                            <td><?= htmlspecialchars($dpia['updated_at']); ?></td>
                                            <td class="actions">
                                                <a href="?view_dpia=<?= htmlspecialchars($dpia['id']); ?>" class="btn btn-sm"><?= t('view_dpia') ?></a>
                                                <?php if (has_permission('manage_dpia')): ?>
                                                    <a href="?edit_dpia=<?= htmlspecialchars($dpia['id']); ?>" class="btn btn-sm btn-outline"><?= t('edit_dpia') ?></a>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="dpia_action" value="delete_dpia">
                                                        <input type="hidden" name="dpia_id" value="<?= htmlspecialchars($dpia['id']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('<?= t('confirm_delete') ?>')"><?= t('delete_dpia') ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info" style="text-align: center;">
                            <?= t('no_dpias_found') ?>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($view_dpia_id && $dpia_info): ?>
                <!-- View DPIA Details -->
                <div class="card">
                    <div class="card-header">
                        <h2><?= t('dpia_details') ?> #<?= htmlspecialchars($dpia_info['id']); ?></h2>
                        <div>
                            <a href="?view_dpias=1" class="btn"><?= t('back_to_dpias') ?></a>
                            <?php if (has_permission('manage_dpia')): ?>
                                <a href="?edit_dpia=<?= htmlspecialchars($dpia_info['id']); ?>" class="btn btn-outline"><?= t('edit_dpia') ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dpia-details">
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('dpia_for_record') ?>:</div>
                            <div class="dpia-field-value">
                                <?php if (!empty($dpia_info['verwerkingsactiviteit'])): ?>
                                    <strong><?= htmlspecialchars($dpia_info['verwerkingsactiviteit']); ?></strong>
                                    <br>
                                    <small>(Record #<?= htmlspecialchars($dpia_info['record_id']); ?>)</small>
                                <?php else: ?>
                                    Record #<?= htmlspecialchars($dpia_info['record_id']); ?>
                                <?php endif; ?>
                                <a href="?edit=<?= htmlspecialchars($dpia_info['record_id']); ?>" class="btn btn-sm" style="margin-left: 1rem;"><?= t('view_record') ?></a>
                            </div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('description') ?>:</div>
                            <div class="dpia-field-value"><?= nl2br(htmlspecialchars($dpia_info['description'])); ?></div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('necessity_proportionality') ?>:</div>
                            <div class="dpia-field-value"><?= nl2br(htmlspecialchars($dpia_info['necessity_proportionality'])); ?></div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('mitigation_measures') ?>:</div>
                            <div class="dpia-field-value"><?= nl2br(htmlspecialchars($dpia_info['mitigation_measures'])); ?></div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('residual_risk') ?>:</div>
                            <div class="dpia-field-value"><?= nl2br(htmlspecialchars($dpia_info['residual_risk'])); ?></div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('overall_risk_level') ?>:</div>
                            <div class="dpia-field-value">
                                <span class="dpia-badge dpia-risk-<?= htmlspecialchars($dpia_info['overall_risk_level']); ?>">
                                    <?= htmlspecialchars(ucfirst($dpia_info['overall_risk_level'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('dpia_status_field') ?>:</div>
                            <div class="dpia-field-value">
                                <span class="dpia-badge dpia-<?= htmlspecialchars($dpia_info['status']); ?>">
                                    <?= htmlspecialchars(ucfirst($dpia_info['status'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if (!empty($dpia_info['notes'])): ?>
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('notes') ?>:</div>
                            <div class="dpia-field-value"><?= nl2br(htmlspecialchars($dpia_info['notes'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('registered_date') ?>:</div>
                            <div class="dpia-field-value"><?= htmlspecialchars($dpia_info['registered_at']); ?></div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('last_updated') ?>:</div>
                            <div class="dpia-field-value"><?= htmlspecialchars($dpia_info['updated_at']); ?></div>
                        </div>
                        
                        <div class="dpia-field">
                            <div class="dpia-field-label"><?= t('dpia_registered_by') ?>:</div>
                            <div class="dpia-field-value">
                                <?= htmlspecialchars($dpia_info['full_name']); ?> (<?= htmlspecialchars($dpia_info['username']); ?>)
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($add_dpia_record || $edit_dpia_id): ?>
                <!-- Add/Edit DPIA Form -->
                <?php 
                $is_edit = $edit_dpia_id > 0;
                $record_id = $is_edit ? $dpia_info['record_id'] : $add_dpia_record;
                $verwerkingsactiviteit = $is_edit ? 
                    ($dpia_info['verwerkingsactiviteit'] ?? get_record_verwerkingsactiviteit($global_connection, $record_id)) : 
                    $record_name;
                
                // Get security measures for auto-population
                $auto_mitigation_measures = '';
                if (!$is_edit) {
                    $auto_mitigation_measures = $security_measures;
                } elseif ($is_edit && !empty($dpia_info['organisatorische_maatregelen']) && !empty($dpia_info['technische_maatregelen'])) {
                    $auto_mitigation_measures = get_security_measures_from_record($global_connection, $record_id);
                }
                ?>
                <div class="card">
                    <div class="card-header">
                        <h2><?= $is_edit ? t('edit_dpia') : t('add_dpia') ?></h2>
                        <a href="<?= $is_edit ? '?view_dpia=' . $edit_dpia_id : '?view_dpias=1' ?>" class="btn"><?= t('cancel') ?></a>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="dpia_action" value="<?= $is_edit ? 'edit_dpia' : 'add_dpia'; ?>">
                        <?php if ($is_edit): ?>
                            <input type="hidden" name="dpia_id" value="<?= htmlspecialchars($edit_dpia_id); ?>">
                        <?php else: ?>
                            <input type="hidden" name="record_id" value="<?= htmlspecialchars($record_id); ?>">
                        <?php endif; ?>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label"><?= t('processing_activity_name') ?>:</label>
                                <input type="text" value="<?= htmlspecialchars($verwerkingsactiviteit); ?>" class="form-input" disabled>
                                <small>
                                    (Record #<?= htmlspecialchars($record_id); ?>)
                                    <a href="?edit=<?= htmlspecialchars($record_id); ?>"><?= t('view_record') ?></a>
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label"><?= t('dpia_status_field') ?>:</label>
                                <select name="status" class="form-input" required>
                                    <option value="open" <?= ($is_edit && $dpia_info['status'] == 'open') ? 'selected' : '' ?>><?= t('dpia_open') ?></option>
                                    <option value="closed" <?= ($is_edit && $dpia_info['status'] == 'closed') ? 'selected' : '' ?>><?= t('dpia_closed') ?></option>
                                    <option value="pending" <?= (!$is_edit || ($is_edit && $dpia_info['status'] == 'pending')) ? 'selected' : '' ?>><?= t('dpia_pending') ?></option>
                                </select>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label"><?= t('description') ?>:</label>
                                <textarea name="description" class="form-input" required><?= $is_edit ? htmlspecialchars($dpia_info['description']) : '' ?></textarea>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label"><?= t('necessity_proportionality') ?>:</label>
                                <textarea name="necessity_proportionality" class="form-input" required><?= $is_edit ? htmlspecialchars($dpia_info['necessity_proportionality']) : '' ?></textarea>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label"><?= t('mitigation_measures') ?>:</label>
                                <textarea name="mitigation_measures" class="form-input" required id="mitigation_measures"><?= $is_edit ? htmlspecialchars($dpia_info['mitigation_measures']) : $auto_mitigation_measures ?></textarea>
                                <?php if (!$is_edit && !empty($auto_mitigation_measures)): ?>
                                    <div style="margin-top: 0.5rem; font-size: 0.9rem; color: #006;">
                                        <strong>✓ <?= t('auto_populated') ?>:</strong> 
                                        <?= t('mitigation_measures_from_record') ?>
                                        <button type="button" class="btn btn-sm" onclick="populateMitigationMeasures()" style="margin-left: 1rem;">
                                            <?= t('refresh_measures') ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label"><?= t('residual_risk') ?>:</label>
                                <textarea name="residual_risk" class="form-input" required><?= $is_edit ? htmlspecialchars($dpia_info['residual_risk']) : '' ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label"><?= t('overall_risk_level') ?>:</label>
                                <select name="overall_risk_level" class="form-input" required>
                                    <option value="low" <?= ($is_edit && $dpia_info['overall_risk_level'] == 'low') ? 'selected' : '' ?>><?= t('low') ?></option>
                                    <option value="medium" <?= (!$is_edit || ($is_edit && $dpia_info['overall_risk_level'] == 'medium')) ? 'selected' : '' ?>><?= t('medium') ?></option>
                                    <option value="high" <?= ($is_edit && $dpia_info['overall_risk_level'] == 'high') ? 'selected' : '' ?>><?= t('high') ?></option>
                                </select>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label"><?= t('notes') ?>:</label>
                                <textarea name="notes" class="form-input"><?= $is_edit ? htmlspecialchars($dpia_info['notes']) : '' ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn"><?= $is_edit ? t('update') : t('add_dpia') ?></button>
                            <a href="<?= $is_edit ? '?view_dpia=' . $edit_dpia_id : '?view_dpias=1' ?>" class="btn btn-outline"><?= t('cancel') ?></a>
                        </div>
                    </form>
                    
                    <script>
                    function populateMitigationMeasures() {
                        // This would typically make an AJAX call to get fresh data
                        // For now, we'll just show a message
                        alert('Refreshing security measures from the record...');
                        // In a real implementation, you would fetch the latest data from the server
                        // location.reload(); // Simple reload for now
                    }
                    </script>
                </div>
                
            <?php elseif ($print_compact && isset($compact_data)): ?>
                <!-- Updated Compact Table Print View for All Records -->
                <div class="card">
                    <div class="print-header">
                        <h1><?= t('site_name') ?> - <?= t('print_compact_table') ?></h1>
                        <div class="print-info">
                            <p><?= t('generated_on') ?>: <?= date('Y-m-d H:i:s'); ?></p>
                            <p><?= t('generated_by') ?>: <?= htmlspecialchars($current_user['full_name']); ?> (<?= htmlspecialchars($current_user['username']); ?>)</p>
                            <p><?= t('total_records') ?>: <?= count($compact_data); ?></p>
                            <p><?= t('columns_shown') ?>: ID, Activity, Agreement with Third Party, We are Processor, DPIA Status, Update Needed</p>
                        </div>
                    </div>
                    
                    <div class="print-summary">
                        <?= t('compact_print_description') ?> | <?= t('records') ?>: <?= count($compact_data); ?> | 
                        <?php 
                        $needs_update_count = 0;
                        foreach ($compact_data as $record) {
                            if ($record['update_needed'] == 'Yes') {
                                $needs_update_count++;
                            }
                        }
                        ?>
                        <?= t('records_need_update') ?>: <?= $needs_update_count; ?> | 
                        <?= t('generated_on') ?>: <?= date('Y-m-d H:i:s'); ?>
                    </div>
                    
                    <!-- Updated Compact Table -->
                    <table class="compact-print-table">
                        <thead>
                            <tr>
                                <th class="compact-id">ID</th>
                                <th class="compact-activity"><?= t('processing_activity_name') ?></th>
                                <th class="compact-agreement"><?= t('avg_registersovereenkomstmetderdepartij') ?></th>
                                <th class="compact-processor"><?= t('wijzijnverwerker') ?></th>
                                <th class="compact-dpia">DPIA</th>
                                <th class="compact-update"><?= t('update_needed_column') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($compact_data as $record): ?>
                                <tr>
                                    <td class="compact-id"><?= htmlspecialchars($record['id']); ?></td>
                                    <td class="compact-activity"><?= htmlspecialchars($record['verwerkingsactiviteit'] ?? ''); ?></td>
                                    <td class="compact-agreement"><?= htmlspecialchars($record['avg_registersovereenkomstmetderdepartij'] ?? ''); ?></td>
                                    <td class="compact-processor"><?= htmlspecialchars($record['wijzijnverwerker'] ?? ''); ?></td>
                                    <td class="compact-dpia"><?= htmlspecialchars($record['dpia_status']); ?></td>
                                    <td class="compact-update">
                                        <?php if ($record['update_needed'] == 'Yes'): ?>
                                            <span style="color: #f44336; font-weight: bold;">⚠️ <?= t('yes') ?></span>
                                            (<?= $record['days_since_update'] ?> <?= t('days_ago') ?>)
                                        <?php else: ?>
                                            <?= htmlspecialchars($record['update_needed']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="print-footer">
                        <?= t('site_name') ?> - <?= t('print_compact_table') ?> | <?= t('page') ?> <span class="page-number"></span> |
                        <?= t('confidential') ?> - <?= date('Y-m-d'); ?>
                    </div>
                </div>
                
                <script>
                // Auto-print after page loads
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(function() {
                        window.print();
                        // Go back after printing
                        setTimeout(function() {
                            window.history.back();
                        }, 1000);
                    }, 500);
                });
                </script>
                
            <?php elseif ($print_individual > 0 && isset($full_record_details) && $print_full_details): ?>
                <!-- Full Details Print View for Individual Record WITHOUT SENSITIVE COLUMNS -->
                <?php 
                $record = $full_record_details['record'];
                $update_status = $full_record_details['update_status'];
                $dpia_info = $full_record_details['dpia_info'];
                $has_dpia = $full_record_details['has_dpia'];
                $excluded_columns = $full_record_details['excluded_columns'];
                ?>
                <div class="card">
                    <div class="print-header">
                        <h1><?= t('site_name') ?> - <?= $has_dpia ? t('print_with_dpia_details') : t('batch_report') ?></h1>
                        <div class="print-info">
                            <p><?= t('generated_on') ?>: <?= date('Y-m-d H:i:s'); ?></p>
                            <p><?= t('generated_by') ?>: <?= htmlspecialchars($current_user['full_name']); ?> (<?= htmlspecialchars($current_user['username']); ?>)</p>
                            <p><?= t('record_id') ?>: <?= htmlspecialchars($record['id']); ?></p>
                            <p><?= t('processing_activity_name') ?>: <?= htmlspecialchars($record['verwerkingsactiviteit'] ?? 'N/A'); ?></p>
                            <div class="safe-print-indicator">
                                <?php if ($has_dpia): ?>
                                    ✅ <?= t('dpia_registered') ?> | 
                                <?php endif; ?>
                                ✅ <?= t('safe_for_printing') ?>: <?= t('sensitive_data_removed') ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security Information Banner -->
                    <div class="safe-print-indicator" style="margin-bottom: 10px;">
                        <strong>🛡️ <?= t('protected_for_privacy') ?>:</strong> <?= t('sensitive_information') ?> <?= t('sensitive_data_removed') ?>
                        <div class="excluded-columns_list">
                            <strong><?= t('excluded_columns') ?>:</strong> <?= count($excluded_columns) ?> <?= t('columns') ?>
                        </div>
                    </div>
                    
                    <!-- Update Status Summary -->
                    <div class="card" style="margin-bottom: 10px; border: 1px solid #000;">
                        <div class="card-header" style="background: #f8f8f8; padding: 5px;">
                            <h3 style="margin: 0; font-size: 10pt;"><?= t('update_status') ?></h3>
                        </div>
                        <div style="padding: 5px;">
                            <table style="width: 100%; font-size: 8pt;">
                                <tr>
                                    <td><strong><?= t('last_updated') ?>:</strong></td>
                                    <td><?= htmlspecialchars($update_status['last_updated']); ?></td>
                                    <td><strong><?= t('days_since_update') ?>:</strong></td>
                                    <td><?= htmlspecialchars($update_status['days_since']); ?></td>
                                    <td><strong><?= t('update_needed_column') ?>:</strong></td>
                                    <td>
                                        <?php if ($update_status['needs_update'] == 'Yes'): ?>
                                            <span style="color: #f44336; font-weight: bold;">⚠️ <?= t('yes') ?></span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($update_status['needs_update']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Main Record Details (SAFE VERSION - No sensitive columns) -->
                    <div class="card" style="margin-bottom: 10px; border: 1px solid #000;">
                        <div class="card-header" style="background: #f8f8f8; padding: 5px;">
                            <h3 style="margin: 0; font-size: 10pt;"><?= t('record_details') ?> (<?= t('safe_for_printing') ?>)</h3>
                        </div>
                        <div style="padding: 5px;">
                            <table class="compact-print-table">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;"><?= t('field') ?></th>
                                        <th style="width: 70%;"><?= t('value') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($record as $field => $value): ?>
                                        <?php 
                                        // Skip id field as it's already in header
                                        if ($field == 'id') continue;
                                        
                                        // Format value
                                        $display_value = $value;
                                        
                                        if (is_null($display_value) || $display_value === '') {
                                            $display_value = 'N/A';
                                        }
                                        
                                        // Truncate very long values
                                        if (strlen($display_value) > 200) {
                                            $display_value = substr($display_value, 0, 197) . '...';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($field); ?></strong>
                                            </td>
                                            <td>
                                                <?= nl2br(htmlspecialchars($display_value)); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="margin-top: 10px; padding: 5px; background: #f8f8f8; border: 0.5pt solid #ddd; font-size: 7pt; color: #666;">
                                ✅ <?= t('showing_safe_data') ?>: <?= count($record) - 1 ?> <?= t('columns') ?> (<?= count($excluded_columns) ?> <?= t('sensitive_columns_excluded') ?>)
                            </div>
                        </div>
                    </div>
                    
                    <!-- DPIA Information (if exists) - INCLUDES RESIDUAL RISK -->
                    <?php if ($has_dpia && $dpia_info): ?>
                    <div class="card" style="margin-bottom: 10px; border: 1px solid #000;">
                        <div class="card-header" style="background: #f8f8f8; padding: 5px;">
                            <h3 style="margin: 0; font-size: 10pt;"><?= t('dpia_details') ?> - <?= t('risk_assessment') ?></h3>
                        </div>
                        <div style="padding: 5px;">
                            <table class="compact-print-table">
                                <thead>
                                    <tr>
                                        <th style="width: 25%;"><?= t('field') ?></th>
                                        <th style="width: 75%;"><?= t('value') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong><?= t('dpia_status_field') ?>:</strong></td>
                                        <td><?= htmlspecialchars(ucfirst($dpia_info['status'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?= t('overall_risk_level') ?>:</strong></td>
                                        <td><?= htmlspecialchars(ucfirst($dpia_info['overall_risk_level'])); ?></td>
                                    </tr>
                                    <!-- Residual Risk Section -->
                                    <?php if (!empty($dpia_info['residual_risk'])): ?>
                                    <tr>
                                        <td><strong><?= t('residual_risk') ?>:</strong></td>
                                        <td><?= nl2br(htmlspecialchars($dpia_info['residual_risk'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <!-- Optional: Other DPIA fields if needed -->
                                    <?php if (!empty($dpia_info['mitigation_measures'])): ?>
                                    <tr>
                                        <td><strong><?= t('mitigation_measures') ?>:</strong></td>
                                        <td><?= nl2br(htmlspecialchars(substr($dpia_info['mitigation_measures'], 0, 1000))); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td><strong><?= t('registered_date') ?>:</strong></td>
                                        <td><?= htmlspecialchars($dpia_info['registered_at']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?= t('last_updated') ?>:</strong></td>
                                        <td><?= htmlspecialchars($dpia_info['updated_at']); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <div style="margin-top: 10px; padding: 5px; background: #f8f8f8; border: 0.5pt solid #ddd; font-size: 7pt; color: #666;">
                                📊 <?= t('dpia_registered') ?> | <?= t('dpia_registered_by') ?>: <?= htmlspecialchars($dpia_info['full_name'] ?? $dpia_info['username'] ?? 'Unknown'); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="print-footer">
                        <?= t('site_name') ?> - <?= $has_dpia ? t('print_with_dpia_details') : t('secure_print') ?> | 
                        <?= t('record_id') ?>: <?= htmlspecialchars($record['id']); ?> | 
                        <?php if ($has_dpia): ?>📊 DPIA: <?= htmlspecialchars(ucfirst($dpia_info['status'])); ?> | <?php endif; ?>
                        <?= t('safe_for_printing') ?> - <?= count($excluded_columns) ?> <?= t('sensitive_columns_excluded') ?> | 
                        <?= t('confidential') ?> - <?= date('Y-m-d'); ?>
                    </div>
                </div>
                
                <script>
                // Auto-print after page loads
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(function() {
                        window.print();
                        // Go back after printing
                        setTimeout(function() {
                            window.history.back();
                        }, 1000);
                    }, 500);
                });
                </script>
                
            <?php elseif ($print_all_with_dpia && !empty($batch_records)): ?>
                <!-- Batch Print All Records with DPIA Details - MATCHING INDIVIDUAL FORMAT -->
                <div class="card">
                    <div class="print-header">
                        <h1><?= t('site_name') ?> - <?= t('print_all_with_dpia') ?></h1>
                        <div class="print-info">
                            <p><?= t('generated_on') ?>: <?= date('Y-m-d H:i:s'); ?></p>
                            <p><?= t('generated_by') ?>: <?= htmlspecialchars($current_user['full_name']); ?> (<?= htmlspecialchars($current_user['username']); ?>)</p>
                            <p><?= t('total_records') ?>: <?= count($batch_records); ?></p>
                            <p><?= $only_with_dpia ? t('include_only_with_dpia') : t('include_all_records') ?></p>
                            <?php 
                            $records_with_dpia = 0;
                            foreach ($batch_records as $record) {
                                if ($record['has_dpia']) {
                                    $records_with_dpia++;
                                }
                            }
                            ?>
                            <p><?= t('records_with_dpia') ?>: <?= $records_with_dpia ?> (<?= round(($records_with_dpia / count($batch_records)) * 100, 1) ?>%)</p>
                            <div class="safe-print-indicator">
                                ✅ <?= t('safe_for_printing') ?>: <?= t('sensitive_data_removed') ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Batch Statistics Summary -->
                    <div class="print-summary" style="margin-bottom: 20px; padding: 10px; background: #f8f8f8; border: 1px solid #000;">
                        <h3 style="margin: 0 0 10px 0; font-size: 12pt;"><?= t('batch_print_summary') ?></h3>
                        <table style="width: 100%; font-size: 9pt;">
                            <tr>
                                <td><strong><?= t('total_records') ?>:</strong></td>
                                <td><?= count($batch_records); ?></td>
                                <td><strong><?= t('records_with_dpia') ?>:</strong></td>
                                <td><?= $records_with_dpia; ?> (<?= round(($records_with_dpia / count($batch_records)) * 100, 1) ?>%)</td>
                            </tr>
                            <tr>
                                <td><strong><?= t('generated_on') ?>:</strong></td>
                                <td><?= date('Y-m-d H:i:s'); ?></td>
                                <td><strong><?= t('generated_by') ?>:</strong></td>
                                <td><?= htmlspecialchars($current_user['full_name']); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" style="padding-top: 10px;">
                                    <div class="safe-print-indicator" style="text-align: center;">
                                        ✅ <?= t('safe_for_printing') ?>: <?= t('sensitive_data_removed') ?>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Process each record - MATCHING INDIVIDUAL REPORT FORMAT -->
                    <?php $record_counter = 0; ?>
                    <?php foreach ($batch_records as $record_data): ?>
                        <?php 
                        $record_counter++;
                        $record = $record_data['record'];
                        $update_status = $record_data['update_status'];
                        $dpia_info = $record_data['dpia_info'];
                        $has_dpia = $record_data['has_dpia'];
                        $excluded_columns = $record_data['excluded_columns'];
                        ?>
                        
                        <!-- Page break for each record (except first) -->
                        <?php if ($record_counter > 1): ?>
                            <div class="record-separator page-break"></div>
                        <?php endif; ?>
                        
                        <!-- Individual Record Print - SAME FORMAT AS SINGLE PRINT -->
                        <div class="card">
                            <div class="print-header">
                                <h1><?= t('site_name') ?> - <?= $has_dpia ? t('print_with_dpia_details') : t('batch_report') ?></h1>
                                <div class="print-info">
                                    <p><?= t('generated_on') ?>: <?= date('Y-m-d H:i:s'); ?></p>
                                    <p><?= t('generated_by') ?>: <?= htmlspecialchars($current_user['full_name']); ?> (<?= htmlspecialchars($current_user['username']); ?>)</p>
                                    <p><?= t('record_id') ?>: <?= htmlspecialchars($record['id']); ?></p>
                                    <p><?= t('processing_activity_name') ?>: <?= htmlspecialchars($record['verwerkingsactiviteit'] ?? 'N/A'); ?></p>
                                    <p><?= t('record') ?>: <?= $record_counter ?> <?= t('of') ?> <?= count($batch_records); ?></p>
                                    <div class="safe-print-indicator">
                                        <?php if ($has_dpia): ?>
                                            ✅ <?= t('dpia_registered') ?> | 
                                        <?php endif; ?>
                                        ✅ <?= t('safe_for_printing') ?>: <?= t('sensitive_data_removed') ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Security Information Banner -->
                            <div class="safe-print-indicator" style="margin-bottom: 10px;">
                                <strong>🛡️ <?= t('protected_for_privacy') ?>:</strong> <?= t('sensitive_information') ?> <?= t('sensitive_data_removed') ?>
                                <div class="excluded-columns_list">
                                    <strong><?= t('excluded_columns') ?>:</strong> <?= count($excluded_columns) ?> <?= t('columns') ?>
                                </div>
                            </div>
                            
                            <!-- Update Status Summary -->
                            <div class="card" style="margin-bottom: 10px; border: 1px solid #000;">
                                <div class="card-header" style="background: #f8f8f8; padding: 5px;">
                                    <h3 style="margin: 0; font-size: 10pt;"><?= t('update_status') ?></h3>
                                </div>
                                <div style="padding: 5px;">
                                    <table style="width: 100%; font-size: 8pt;">
                                        <tr>
                                            <td><strong><?= t('last_updated') ?>:</strong></td>
                                            <td><?= htmlspecialchars($update_status['last_updated']); ?></td>
                                            <td><strong><?= t('days_since_update') ?>:</strong></td>
                                            <td><?= htmlspecialchars($update_status['days_since']); ?></td>
                                            <td><strong><?= t('update_needed_column') ?>:</strong></td>
                                            <td>
                                                <?php if ($update_status['needs_update'] == 'Yes'): ?>
                                                    <span style="color: #f44336; font-weight: bold;">⚠️ <?= t('yes') ?></span>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($update_status['needs_update']); ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Main Record Details (SAFE VERSION - No sensitive columns) -->
                            <div class="card" style="margin-bottom: 10px; border: 1px solid #000;">
                                <div class="card-header" style="background: #f8f8f8; padding: 5px;">
                                    <h3 style="margin: 0; font-size: 10pt;"><?= t('record_details') ?> (<?= t('safe_for_printing') ?>)</h3>
                                </div>
                                <div style="padding: 5px;">
                                    <table class="compact-print-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 30%;"><?= t('field') ?></th>
                                                <th style="width: 70%;"><?= t('value') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($record as $field => $value): ?>
                                                <?php 
                                                // Skip id field as it's already in header
                                                if ($field == 'id') continue;
                                                
                                                // Format value
                                                $display_value = $value;
                                                
                                                if (is_null($display_value) || $display_value === '') {
                                                    $display_value = 'N/A';
                                                }
                                                
                                                // Truncate very long values
                                                if (strlen($display_value) > 200) {
                                                    $display_value = substr($display_value, 0, 197) . '...';
                                                }
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($field); ?></strong>
                                                    </td>
                                                    <td>
                                                        <?= nl2br(htmlspecialchars($display_value)); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div style="margin-top: 10px; padding: 5px; background: #f8f8f8; border: 0.5pt solid #ddd; font-size: 7pt; color: #666;">
                                        ✅ <?= t('showing_safe_data') ?>: <?= count($record) - 1 ?> <?= t('columns') ?> (<?= count($excluded_columns) ?> <?= t('sensitive_columns_excluded') ?>)
                                    </div>
                                </div>
                            </div>
                            
                            <!-- DPIA Information (if exists) - INCLUDES RESIDUAL RISK -->
                            <?php if ($has_dpia && $dpia_info): ?>
                            <div class="card" style="margin-bottom: 10px; border: 1px solid #000;">
                                <div class="card-header" style="background: #f8f8f8; padding: 5px;">
                                    <h3 style="margin: 0; font-size: 10pt;"><?= t('dpia_details') ?> - <?= t('risk_assessment') ?></h3>
                                </div>
                                <div style="padding: 5px;">
                                    <table class="compact-print-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 25%;"><?= t('field') ?></th>
                                                <th style="width: 75%;"><?= t('value') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><strong><?= t('dpia_status_field') ?>:</strong></td>
                                                <td><?= htmlspecialchars(ucfirst($dpia_info['status'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong><?= t('overall_risk_level') ?>:</strong></td>
                                                <td><?= htmlspecialchars(ucfirst($dpia_info['overall_risk_level'])); ?></td>
                                            </tr>
                                            <!-- Residual Risk Section -->
                                            <?php if (!empty($dpia_info['residual_risk'])): ?>
                                            <tr>
                                                <td><strong><?= t('residual_risk') ?>:</strong></td>
                                                <td><?= nl2br(htmlspecialchars($dpia_info['residual_risk'])); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <!-- Optional: Other DPIA fields if needed -->
                                            <?php if (!empty($dpia_info['mitigation_measures'])): ?>
                                            <tr>
                                                <td><strong><?= t('mitigation_measures') ?>:</strong></td>
                                                <td><?= nl2br(htmlspecialchars(substr($dpia_info['mitigation_measures'], 0, 1000))); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td><strong><?= t('registered_date') ?>:</strong></td>
                                                <td><?= htmlspecialchars($dpia_info['registered_at']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong><?= t('last_updated') ?>:</strong></td>
                                                <td><?= htmlspecialchars($dpia_info['updated_at']); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div style="margin-top: 10px; padding: 5px; background: #f8f8f8; border: 0.5pt solid #ddd; font-size: 7pt; color: #666;">
                                        📊 <?= t('dpia_registered') ?> | <?= t('dpia_registered_by') ?>: <?= htmlspecialchars($dpia_info['full_name'] ?? $dpia_info['username'] ?? 'Unknown'); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="print-footer">
                                <?= t('site_name') ?> - <?= $has_dpia ? t('print_with_dpia_details') : t('secure_print') ?> | 
                                <?= t('record_id') ?>: <?= htmlspecialchars($record['id']); ?> | 
                                <?php if ($has_dpia): ?>📊 DPIA: <?= htmlspecialchars(ucfirst($dpia_info['status'])); ?> | <?php endif; ?>
                                <?= t('record') ?>: <?= $record_counter ?> <?= t('of') ?> <?= count($batch_records); ?> | 
                                <?= t('safe_for_printing') ?> - <?= count($excluded_columns) ?> <?= t('sensitive_columns_excluded') ?> | 
                                <?= t('confidential') ?> - <?= date('Y-m-d'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Final Batch Summary -->
                    <div class="card page-break" style="margin-top: 20px;">
                        <div class="print-header">
                            <h1><?= t('site_name') ?> - <?= t('batch_print_summary') ?></h1>
                            <div class="print-info">
                                <p><?= t('generated_on') ?>: <?= date('Y-m-d H:i:s'); ?></p>
                                <p><?= t('generated_by') ?>: <?= htmlspecialchars($current_user['full_name']); ?> (<?= htmlspecialchars($current_user['username']); ?>)</p>
                                <p><?= t('total_records') ?>: <?= count($batch_records); ?></p>
                                <p><?= t('records_with_dpia') ?>: <?= $records_with_dpia; ?> (<?= round(($records_with_dpia / count($batch_records)) * 100, 1) ?>%)</p>
                                <div class="safe-print-indicator">
                                    ✅ <?= t('safe_for_printing') ?>: <?= t('sensitive_data_removed') ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="print-summary" style="padding: 15px; margin: 15px 0; background: #f8f8f8; border: 1px solid #000;">
                            <h3 style="margin: 0 0 10px 0; font-size: 12pt;"><?= t('batch_print_completed') ?></h3>
                            <table style="width: 100%; font-size: 9pt;">
                                <tr>
                                    <td><strong><?= t('total_records_printed') ?>:</strong></td>
                                    <td><?= count($batch_records); ?></td>
                                    <td><strong><?= t('pages_generated') ?>:</strong></td>
                                    <td><?= count($batch_records) + 1; ?> (<?= count($batch_records); ?> records + 1 summary)</td>
                                </tr>
                                <tr>
                                    <td><strong><?= t('records_with_dpia') ?>:</strong></td>
                                    <td><?= $records_with_dpia; ?> (<?= round(($records_with_dpia / count($batch_records)) * 100, 1) ?>%)</td>
                                    <td><strong><?= t('records_without_dpia') ?>:</strong></td>
                                    <td><?= count($batch_records) - $records_with_dpia; ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" style="padding-top: 10px;">
                                        <div class="safe-print-indicator" style="text-align: center;">
                                            ✅ <?= t('safe_for_printing') ?>: <?= t('sensitive_data_removed') ?> | 
                                            ✅ <?= count($excluded_columns) ?> <?= t('sensitive_columns_excluded') ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="print-footer">
                            <?= t('site_name') ?> - <?= t('batch_print_summary') ?> | 
                            <?= t('total_records') ?>: <?= count($batch_records); ?> | 
                            <?= t('records_with_dpia') ?>: <?= $records_with_dpia; ?> | 
                            <?= t('safe_for_printing') ?> | <?= t('confidential') ?> - <?= date('Y-m-d'); ?>
                        </div>
                    </div>
                </div>
                
                <script>
                // Auto-print after page loads
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(function() {
                        window.print();
                        // Go back after printing
                        setTimeout(function() {
                            window.history.back();
                        }, 1000);
                    }, 500);
                });
                </script>
                
            <?php else: ?>
            
                <!-- Add/Edit Record Form -->
                <?php if ((has_permission('add') && isset($_GET['add'])) || (has_permission('edit') && $edit_row)): ?>
                    <div class="card" id="add-form" style="display: block;">
                        <div class="card-header">
                            <h2><?= $edit_row ? t('edit_record_form') : t('add_record_form') ?></h2>
                            <?php if ($edit_row): ?>
                                <?php 
                                $record_id = $edit_row['id'];
                                $days_since_update = isset($edit_row['updated_at']) ? 
                                    floor((time() - strtotime($edit_row['updated_at'])) / (60 * 60 * 24)) : 0;
                                $priority = get_update_priority($days_since_update);
                                $priority_color = get_priority_color($priority);
                                ?>
                                <?php if ($days_since_update > $recent_update_threshold): ?>
                                    <div class="update-badge" style="margin-left: 1rem; background-color: <?= $priority_color ?>;">
                                        ⚠️ <?= t('update_recommended') ?> (<?= $days_since_update ?> <?= t('days_ago') ?>)
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="timestamp-info">
                            📝 <strong><?= t('timestamps') ?>:</strong>
                            <br>• <strong><?= t('created_at') ?>:</strong> <?= $edit_row ? t('original_date_kept') : t('automatically_set') . ' ' . date('Y-m-d H:i:s'); ?>
                            <br>• <strong><?= t('updated_at') ?>:</strong> <?= $edit_row ? t('automatically_updated') . ' ' . date('Y-m-d H:i:s') : t('automatically_set') . ' ' . date('Y-m-d H:i:s'); ?>
                        </div>
                        
                        <?php if ($edit_row && $days_since_update > $recent_update_threshold): ?>
                            <div class="alert alert-warning" style="background: #fff3cd; border-color: #ffc107;">
                                <strong>⚠️ <?= t('update_required') ?></strong><br>
                                <?= t('this_record_not_updated', ['days' => $days_since_update, 'threshold' => $recent_update_threshold]) ?>
                                <br>
                                <span style="font-size: 0.9rem;">
                                    <span class="priority-indicator" style="background-color: <?= $priority_color ?>"></span>
                                    <?= t('update_priority') ?>: 
                                    <?php if ($priority == 'high'): ?>
                                        <strong style="color: #f44336;"><?= t('high_priority') ?></strong>
                                    <?php elseif ($priority == 'medium'): ?>
                                        <strong style="color: #ff9800;"><?= t('medium_priority') ?></strong>
                                    <?php else: ?>
                                        <strong style="color: #ffc107;"><?= t('low_priority') ?></strong>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="<?= $edit_row ? 'edit' : 'add'; ?>">
                            <?php if ($edit_row): ?>
                                <input type="hidden" name="id" value="<?= htmlspecialchars($edit_row['id']); ?>">
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
                                            <label class="form-label">
                                                <?= htmlspecialchars($col_name); ?> 
                                                (<?= htmlspecialchars($col['Type']); ?>)
                                                <?php if ($is_encrypted): ?>
                                                    <span class="encrypted-indicator"><?= t('encrypted_indicator') ?></span>
                                                <?php endif; ?>
                                                <?php if ($is_timestamp): ?>
                                                    <span style="color: #006; font-weight: bold;">[auto]</span>
                                                <?php endif; ?>
                                            </label>
                                            <?php if ($col_name === 'created_at' || $col_name === 'updated_at'): ?>
                                                <?php 
                                                $timestamp_value = '';
                                                if ($edit_row && isset($edit_row[$col_name])) {
                                                    $timestamp_value = htmlspecialchars($edit_row[$col_name]);
                                                } else {
                                                    $timestamp_value = date('Y-m-d H:i:s');
                                                }
                                                ?>
                                                <input 
                                                    type="text" 
                                                    class="form-input timestamp-field" 
                                                    value="<?= $timestamp_value ?>"
                                                    disabled
                                                    style="background-color: #f5f5f5;"
                                                >
                                                <div style="font-size: 0.8rem; color: #666; margin-top: 0.25rem;">
                                                    <?php if ($col_name === 'created_at'): ?>
                                                        <?= t('automatically_set') ?>
                                                    <?php else: ?>
                                                        <?= t('automatically_updated') ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif (strpos($col['Type'], 'text') !== false || strpos($col['Type'], 'varchar') !== false && (int)str_replace(['varchar(', ')'], '', $col['Type']) > 100): ?>
                                                <textarea 
                                                    name="<?= htmlspecialchars($col_name); ?>" 
                                                    class="form-input"
                                                    <?php if ($is_encrypted): ?>placeholder="<?= t('encrypted_indicator') ?>"<?php endif; ?>
                                                ><?= $edit_row && isset($edit_row[$col_name]) ? htmlspecialchars($edit_row[$col_name]) : ''; ?></textarea>
                                            <?php else: ?>
                                                <input 
                                                    type="text" 
                                                    name="<?= htmlspecialchars($col_name); ?>" 
                                                    class="form-input" 
                                                    value="<?= $edit_row && isset($edit_row[$col_name]) ? htmlspecialchars($edit_row[$col_name]) : ''; ?>"
                                                    <?php if ($is_encrypted): ?>placeholder="<?= t('encrypted_indicator') ?>"<?php endif; ?>
                                                >
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn"><?= $edit_row ? t('update') : t('add'); ?> <?= t('record') ?></button>
                                <a href="?" class="btn btn-outline"><?= t('cancel') ?></a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                
                <!-- Data Display -->
                <?php if (has_permission('view')): ?>
                    <?php if ($result && $result->num_rows > 0): ?>
                        
                        <!-- TABLE VIEW - Only first 4 columns -->
                        <div class="card" id="data-container">
                            <div class="card-header">
                                <h2><?= t('processing_activities') ?> (<?= $result->num_rows ?>)</h2>
                                <div style="display: flex; gap: 0.5rem;">
                                    <?php if (has_permission('add')): ?>
                                        <a href="?add=1" class="btn btn-outline">
                                            <?= t('add_record_form') ?>
                                            <?php if ($count_records_to_update > 0): ?>
                                                <span class="update-badge"><?= $count_records_to_update ?></span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- UPDATED: Simple "Update Recommendations" button -->
                                    <?php if (!empty($records_to_update)): ?>
                                        <button class="btn btn-outline" onclick="showUpdateRecommendations()">
                                            ⚠️ <?= t('update_recommendations') ?> (<?= $count_records_to_update ?>)
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table id="data-table">
                                    <thead>
                                        <tr>
                                            <?php 
                                            // Show only first 4 columns in table view
                                            $visible_columns = array_slice($columns, 0, 4);
                                            foreach ($visible_columns as $col): 
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
                                                        <a href="<?= $sort_url; ?>" style="color: #000; text-decoration: none;">
                                                            <?= htmlspecialchars($col_name); ?>
                                                            <?php if ($is_timestamp): ?>
                                                                <span style="color: #006; font-weight: bold;">[auto]</span>
                                                            <?php endif; ?>
                                                            <?php if ($is_encrypted): ?>
                                                                <span class="encrypted-indicator"><?= t('encrypted_indicator') ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($sort_column === $col_name): ?>
                                                                <?= $sort_direction === 'ASC' ? '↑' : '↓'; ?>
                                                            <?php endif; ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($col_name); ?>
                                                        <?php if ($is_timestamp): ?>
                                                            <span style="color: #006; font-weight: bold;">[auto]</span>
                                                        <?php endif; ?>
                                                        <?php if ($is_encrypted): ?>
                                                            <span class="encrypted-indicator"><?= t('encrypted_indicator') ?></span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </th>
                                            <?php endforeach; ?>
                                            <th><?= t('dpia_status') ?></th>
                                            <th><?= t('actions') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $row_counter = 0;
                                        $result->data_seek(0);
                                        while ($row = $result->fetch_assoc()): 
                                            $row_counter++;
                                            // Use the global connection that's still open
                                            if (isset($global_connection) && $global_connection) {
                                                $has_dpia_record = has_dpia($global_connection, $row['id']);
                                                $dpia_record_info = $has_dpia_record ? get_dpia_info_by_record($global_connection, $row['id']) : null;
                                            } else {
                                                $has_dpia_record = false;
                                                $dpia_record_info = null;
                                            }
                                            
                                            // Check if this record needs updating based on updated_at column
                                            $needs_update = false;
                                            $days_since_update = 0;
                                            $priority = 'none';
                                            $priority_color = '#4CAF50';
                                            
                                            if (!empty($row['updated_at'])) {
                                                $days_since_update = floor((time() - strtotime($row['updated_at'])) / (60 * 60 * 24));
                                                $needs_update = $days_since_update > $recent_update_threshold;
                                                $priority = get_update_priority($days_since_update);
                                                $priority_color = get_priority_color($priority);
                                            }
                                        ?>
                                            <tr <?php if ($needs_update): ?>style="border-left: 4px solid <?= $priority_color ?>;"<?php endif; ?>>
                                                <?php 
                                                // Show only first 4 columns in table view
                                                $visible_columns = array_slice($columns, 0, 4);
                                                foreach ($visible_columns as $col): 
                                                    $col_name = $col['Field'];
                                                    $value = isset($row[$col_name]) ? $row[$col_name] : '';
                                                    $is_encrypted = in_array($col_name, $rot47_columns);
                                                    if ($is_encrypted && $value !== null && $value !== '') {
                                                        $value = rot47_decrypt($value);
                                                    }
                                                    
                                                    $is_timestamp = ($col_name === 'created_at' || $col_name === 'updated_at');
                                                    $is_created_at = ($col_name === 'created_at');
                                                    $is_updated_at = ($col_name === 'updated_at');
                                                ?>
                                                    <td title="<?= htmlspecialchars($value); ?>" 
                                                        <?php if ($is_timestamp): ?>class="timestamp-field"<?php endif; ?>
                                                        style="<?php if ($is_timestamp): ?>background-color: #f9f9f9; font-family: 'Courier New', monospace; color: #006;<?php endif; ?>">
                                                        <?php 
                                                        if (strlen($value) > 50 && !$is_timestamp) {
                                                            echo htmlspecialchars(substr($value, 0, 47)) . '...';
                                                        } else {
                                                            echo htmlspecialchars($value);
                                                        }
                                                        ?>
                                                        <?php if ($is_encrypted && $value !== ''): ?>
                                                            <span class="encrypted-indicator">✓</span>
                                                        <?php endif; ?>
                                                        <?php if ($is_created_at): ?>
                                                            <div style="font-size: 0.7rem; color: #666; margin-top: 0.25rem;">
                                                                <?= t('created') ?>
                                                            </div>
                                                        <?php elseif ($is_updated_at): ?>
                                                            <div style="font-size: 0.7rem; color: #666; margin-top: 0.25rem;">
                                                                <?= t('updated') ?>
                                                                <?php if ($needs_update): ?>
                                                                    <span style="color: <?= $priority_color ?>; font-weight: bold;">
                                                                        (<?= $days_since_update ?> <?= t('days_ago') ?>)
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td>
                                                    <?php if ($has_dpia_record && $dpia_record_info): ?>
                                                        <span class="dpia-badge dpia-<?= htmlspecialchars($dpia_record_info['status']); ?>">
                                                            <?= htmlspecialchars(ucfirst($dpia_record_info['status'])); ?>
                                                        </span>
                                                        <br>
                                                        <small>
                                                            <a href="?view_dpia=<?= htmlspecialchars($dpia_record_info['id']); ?>"><?= t('view_dpia') ?></a>
                                                            <?php if (has_permission('manage_dpia')): ?>
                                                                | <a href="?edit_dpia=<?= htmlspecialchars($dpia_record_info['id']); ?>"><?= t('edit_dpia') ?></a>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary"><?= t('no_dpia') ?></span>
                                                        <?php if (has_permission('manage_dpia')): ?>
                                                            <br>
                                                            <small>
                                                                <a href="?add_dpia=<?= htmlspecialchars($row['id']); ?>"><?= t('register_dpia') ?></a>
                                                            </small>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="actions">
                                                    <?php if ($needs_update): ?>
                                                        <span class="badge badge-warning" title="<?= t('not_updated_recently') ?>: <?= $days_since_update ?> <?= t('days_ago') ?>" style="background-color: <?= $priority_color ?>;">
                                                            <span class="priority-indicator" style="background-color: white; margin-right: 0.25rem;"></span>
                                                            <?php if ($priority == 'high'): ?>
                                                                ⚠️ <?= t('high_priority') ?>
                                                            <?php elseif ($priority == 'medium'): ?>
                                                                ⚠️ <?= t('medium_priority') ?>
                                                            <?php else: ?>
                                                                ⚠️ <?= t('low_priority') ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- UPDATED: SECURE PRINT BUTTON ONLY - NO OPTIONS FOR UNMASKED PRINTING -->
                                                    <?php if (has_permission('print')): ?>
                                                        <a href="?print_record=<?= htmlspecialchars($row['id']); ?>&print_full=1&auto_print=1" 
                                                           class="btn btn-sm print-btn-secure" 
                                                           title="<?= t('print_safe_version') ?>">
                                                            📄 <?= t('secure_print') ?>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (has_permission('edit')): ?>
                                                        <a href="?edit=<?= htmlspecialchars($row['id']); ?>" class="btn btn-sm btn-outline"><?= t('edit_record') ?></a>
                                                    <?php endif; ?>
                                                    <?php if (has_permission('delete')): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']); ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('<?= t('confirm_delete') ?>')"><?= t('delete') ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($has_dpia_record && has_permission('manage_dpia')): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="dpia_action" value="remove_dpia">
                                                            <input type="hidden" name="record_id" value="<?= htmlspecialchars($row['id']); ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('<?= t('confirm_remove_dpia') ?>')"><?= t('remove_dpia') ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="padding: 1rem; border-top: 1px solid #000; background: #f8f8f8; display: flex; justify-content: space-between; font-size: 0.9rem;">
                                <div><?= t('showing_columns') ?>: 4 <?= t('of') ?> <?= count($columns); ?> | <?= t('encrypted_columns') ?>: <?= count($rot47_columns); ?></div>
                                <div>
                                    <?php if ($count_records_to_update > 0): ?>
                                        <span style="color: #ff9800; font-weight: bold; margin-right: 1rem;">
                                            ⚠️ <?= $count_records_to_update ?> <?= t('records_need_update') ?>
                                        </span>
                                    <?php endif; ?>
                                    <?= t('user') ?>: <?= htmlspecialchars($current_user['username']); ?> (<?= htmlspecialchars($current_user['role']); ?>)
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($result && $result->num_rows === 0): ?>
                        <div class="alert alert-danger" style="text-align: center;">
                            <?= t('no_activities_found') ?>
                        </div>
                    <?php elseif ($error): ?>
                        <div class="alert alert-danger" style="text-align: center;">
                            <?= t('database_error') ?>: <?= htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-danger" style="text-align: center;">
                        <?= t('no_permission_view') ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-hide success messages
        setTimeout(function() {
            const successMessages = document.querySelectorAll('.alert-success');
            successMessages.forEach(function(msg) {
                msg.style.display = 'none';
            });
        }, 5000);
        
        // Scroll to form if editing/adding
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('edit') || urlParams.has('add')) {
                const addForm = document.getElementById('add-form');
                if (addForm) {
                    addForm.scrollIntoView({ behavior: 'smooth' });
                }
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const addMenus = document.querySelectorAll('.add-button-menu');
            addMenus.forEach(function(menu) {
                if (!menu.contains(event.target)) {
                    const content = menu.querySelector('.add-button-menu-content');
                    if (content) {
                        content.style.display = 'none';
                    }
                }
            });
        });
        
        // Toggle dropdown on mobile
        document.querySelectorAll('.add-button-menu .btn').forEach(function(button) {
            button.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    e.stopPropagation();
                    const content = this.parentElement.querySelector('.add-button-menu-content');
                    if (content) {
                        content.style.display = content.style.display === 'block' ? 'none' : 'block';
                    }
                }
            });
        });
        
        // Simple print function
        function simplePrint(url) {
            window.open(url, '_blank');
        }
        
        // Batch print progress simulation
        function simulateBatchPrint() {
            const progressBar = document.querySelector('.progress-fill');
            if (progressBar) {
                let progress = 0;
                const interval = setInterval(() => {
                    progress += 10;
                    progressBar.style.width = progress + '%';
                    
                    if (progress >= 100) {
                        clearInterval(interval);
                        window.print();
                    }
                }, 200);
            }
        }
        
        // Function to show update recommendations modal
        function showUpdateRecommendations() {
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.8);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 1000;
                overflow: auto;
                padding: 1rem;
            `;
            
            const modalContent = document.createElement('div');
            modalContent.className = 'card';
            modalContent.style.cssText = `
                max-width: 800px;
                width: 95%;
                max-height: 90vh;
                overflow-y: auto;
                background: white;
            `;
            
            let content = `
                <div class="card-header">
                    <h2><?= t('update_recommendations') ?></h2>
                    <button onclick="closeModal()" class="btn">× Close</button>
                </div>
                <div style="padding: 1.5rem;">
                    <div class="update-stats-grid" style="margin-bottom: 1.5rem;">
                        <div class="update-stat-card">
                            <div class="update-stat-number">${<?= $update_statistics['total_records'] ?? 0 ?>}</div>
                            <div class="update-stat-label"><?= t('total_records') ?></div>
                        </div>
                        <div class="update-stat-card" style="border-color: #ff9800;">
                            <div class="update-stat-number" style="color: #ff9800;">${<?= $update_statistics['needs_update'] ?? 0 ?>}</div>
                            <div class="update-stat-label"><?= t('needs_update') ?></div>
                        </div>
                        <div class="update-stat-card" style="border-color: #4CAF50;">
                            <div class="update-stat-number" style="color: #4CAF50;">${<?= $update_statistics['up_to_date'] ?? 0 ?>}</div>
                            <div class="update-stat-label"><?= t('up_to_date') ?></div>
                        </div>
                        <div class="update-stat-card">
                            <div class="update-stat-number">${<?= $update_statistics['average_days_since_update'] ?? 0 ?>}</div>
                            <div class="update-stat-label"><?= t('average_update_age') ?></div>
                        </div>
                    </div>
                    
                    <h3 style="margin-bottom: 1rem;"><?= t('records_to_update') ?></h3>
                    <div class="recommendation-list" style="max-height: 400px; overflow-y: auto;">
            `;
            
            <?php foreach ($records_to_update as $record): ?>
                <?php 
                $days_since = $record['days_since_update'];
                $priority = get_update_priority($days_since);
                $priority_color = get_priority_color($priority);
                $warning_class = $days_since > 365 ? 'danger' : 'warning';
                ?>
                content += `
                    <div class="recommendation-item <?= $warning_class ?>" style="border-left-color: <?= $priority_color ?>;">
                        <div class="recommendation-title">
                            <?= htmlspecialchars($record['verwerkingsactiviteit'] ?? 'Record #' . $record['id']); ?>
                        </div>
                        <div class="recommendation-meta">
                            <span>
                                <span class="priority-indicator" style="background-color: <?= $priority_color ?>"></span>
                                <?= t('last_update') ?>: <?= $days_since ?> <?= t('days_ago') ?>
                            </span>
                            <a href="?edit=<?= $record['id'] ?>" class="btn btn-sm"><?= t('edit') ?></a>
                        </div>
                    </div>
                `;
            <?php endforeach; ?>
            
            content += `
                    </div>
                </div>
            `;
            
            modalContent.innerHTML = content;
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
            
            // Prevent body scroll when modal is open
            document.body.style.overflow = 'hidden';
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // Close modal function
            window.closeModal = function() {
                modal.remove();
                document.body.style.overflow = '';
            };
        }
    </script>
</body>
</html>
