-- Optional performance indexes for AJAX dashboard tabs.
-- Safe to run after the normalized schema has been installed.

DELIMITER //

CREATE PROCEDURE nucleus_ensure_index(
    IN table_name_value VARCHAR(64),
    IN index_name_value VARCHAR(64),
    IN create_sql_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND INDEX_NAME = index_name_value
    ) THEN
        SET @nucleus_create_index_sql = create_sql_value;
        PREPARE nucleus_create_index_stmt FROM @nucleus_create_index_sql;
        EXECUTE nucleus_create_index_stmt;
        DEALLOCATE PREPARE nucleus_create_index_stmt;
    END IF;
END//

DELIMITER ;

CALL nucleus_ensure_index('activity_logs', 'idx_activity_logs_created_at', 'CREATE INDEX idx_activity_logs_created_at ON activity_logs (created_at)');
CALL nucleus_ensure_index('projects', 'idx_projects_last_updated_at', 'CREATE INDEX idx_projects_last_updated_at ON projects (last_updated_at)');
CALL nucleus_ensure_index('projects', 'idx_projects_updated_at', 'CREATE INDEX idx_projects_updated_at ON projects (updated_at)');
CALL nucleus_ensure_index('project_requests', 'idx_project_requests_status_created', 'CREATE INDEX idx_project_requests_status_created ON project_requests (status, created_at)');
CALL nucleus_ensure_index('subject_requests', 'idx_subject_requests_status_created', 'CREATE INDEX idx_subject_requests_status_created ON subject_requests (status, created_at)');
CALL nucleus_ensure_index('project_status', 'idx_project_status_checked_at', 'CREATE INDEX idx_project_status_checked_at ON project_status (checked_at)');

DROP PROCEDURE nucleus_ensure_index;
