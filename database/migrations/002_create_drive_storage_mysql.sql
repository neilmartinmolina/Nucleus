CREATE TABLE IF NOT EXISTS drive_folders (
    folder_id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    owner_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    ftp_path TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner_id (owner_id),
    INDEX idx_parent_id (parent_id),
    CONSTRAINT fk_drive_folders_parent
        FOREIGN KEY (parent_id) REFERENCES drive_folders(folder_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_drive_folders_owner
        FOREIGN KEY (owner_id) REFERENCES users(userId)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drive_files (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    folder_id INT NULL,
    owner_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    ftp_path TEXT NOT NULL,
    mime_type VARCHAR(100),
    extension VARCHAR(30),
    file_size BIGINT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner_id (owner_id),
    INDEX idx_folder_id (folder_id),
    INDEX idx_original_name (original_name),
    CONSTRAINT fk_drive_files_folder
        FOREIGN KEY (folder_id) REFERENCES drive_folders(folder_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_drive_files_owner
        FOREIGN KEY (owner_id) REFERENCES users(userId)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
