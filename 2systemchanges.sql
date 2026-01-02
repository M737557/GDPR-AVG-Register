CREATE TABLE system_changes (
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
);