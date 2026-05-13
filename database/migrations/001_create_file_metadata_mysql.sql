CREATE TABLE IF NOT EXISTS uploaded_files (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    uploaded_by INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    ftp_path TEXT NOT NULL,
    mime_type VARCHAR(100),
    file_size BIGINT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uploaded_files_uploaded_by (uploaded_by),
    CONSTRAINT fk_uploaded_files_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(userId)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
