-- Monitoring reliability support for Nucleus.
-- Keeps monitoring read-only: checks only read public status endpoints and persist observations.

CREATE TABLE IF NOT EXISTS monitoring_alerts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    alert_type VARCHAR(80) NOT NULL,
    message TEXT NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
    is_resolved TINYINT(1) NOT NULL DEFAULT 0,
    triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    INDEX idx_monitoring_alerts_project_resolved (project_id, is_resolved),
    INDEX idx_monitoring_alerts_type_resolved (alert_type, is_resolved),
    INDEX idx_monitoring_alerts_triggered (triggered_at)
);

CREATE INDEX IF NOT EXISTS idx_deployment_checks_project_checked_status ON deployment_checks (project_id, checked_at, status);
CREATE INDEX IF NOT EXISTS idx_deployment_checks_project_remote_updated ON deployment_checks (project_id, remote_updated_at);
CREATE INDEX IF NOT EXISTS idx_project_status_checked_project ON project_status (checked_at, project_id);
