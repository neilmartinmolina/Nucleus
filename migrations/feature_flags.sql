CREATE TABLE IF NOT EXISTS feature_flags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    feature_key VARCHAR(100) NOT NULL UNIQUE,
    feature_name VARCHAR(255) NOT NULL,
    feature_group VARCHAR(100) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    maintenance_message TEXT NULL,
    updated_by INT NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY (updated_by) REFERENCES users(userId) ON DELETE SET NULL,
    INDEX idx_feature_flags_group (feature_group),
    INDEX idx_feature_flags_enabled (is_enabled)
);

INSERT INTO feature_flags (feature_key, feature_name, feature_group, is_enabled)
VALUES
('dashboard', 'Dashboard', 'Core', 1),
('projects', 'Projects', 'Projects', 1),
('subjects', 'Subjects', 'Subjects', 1),
('subject_resources', 'Subject Resources', 'Subjects', 1),
('subject_posts', 'Subject Posts', 'Subjects', 1),
('tutorials', 'Tutorials', 'Learning', 1),
('alerts', 'Alerts', 'Monitoring', 1),
('requests', 'Requests', 'Workflow', 1),
('logs', 'Logs', 'Admin', 1),
('settings', 'Settings', 'Admin', 1)
ON DUPLICATE KEY UPDATE
feature_name = VALUES(feature_name),
feature_group = VALUES(feature_group);
