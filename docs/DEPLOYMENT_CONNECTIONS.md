# Nucleus 3-Server Demo Connections

Nucleus can run on a web server while connecting to a remote MySQL/MariaDB database server and a remote FTP file server. Actual uploaded files should live on the FTP server; MySQL/MariaDB stores metadata only.

## Web Server `.env`

Copy `.env.example` to `.env` on the Apache/PHP web server and adjust the IPs and credentials:

```env
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=192.168.1.20
DB_PORT=3306
DB_DATABASE=nucleus_demo
DB_USERNAME=nucleus_user
DB_PASSWORD=change_this_password
DB_CHARSET=utf8mb4

FILE_STORAGE_DRIVER=ftp
FTP_HOST=192.168.1.30
FTP_PORT=21
FTP_USERNAME=ftpuser
FTP_PASSWORD=change_this_password
FTP_ROOT=/storage
FTP_PASSIVE=true
FTP_TIMEOUT=30

UPLOAD_MAX_BYTES=104857600
ADMIN_QUOTA_BYTES=5368709120
```

Do not commit `.env`. It is already ignored by Git.

## MySQL/MariaDB Server

The database server must allow TCP connections from the web server:

- MySQL/MariaDB listens on the configured IP or `0.0.0.0`.
- Firewall allows inbound `DB_PORT`, usually `3306`, from the web server IP.
- The configured database and user exist.
- The user is granted access from the web server host, not only `localhost`.

Run the metadata migration in `database/migrations/001_create_file_metadata_mysql.sql` if you want the standalone `uploaded_files` table. The existing Nucleus resource upload flow uses the existing `resource_files` metadata table.

## FTP Server

The Ubuntu FTP server must allow the web server to connect:

- `vsftpd` is running and listening on `FTP_PORT`, usually `21`.
- Firewall allows the FTP control port and the passive port range.
- `FTP_ROOT` exists and the FTP user can create folders, upload, download, and delete files there.
- Keep `FTP_PASSIVE=true` for most NAT or VM networking setups.

## Test Connections

From the project root on the web server:

```bash
php tools/test_db_connection.php
php tools/test_ftp_connection.php
```

The scripts print host, database/root, and success or a clear error. They never print passwords.

Administrators can also use the existing diagnostics endpoint at `handlers/test_connections.php`; it reports whether the database and FTP server are reachable without exposing credentials.

## PHP Extensions

Enable these PHP extensions on the web server:

- `pdo_mysql`
- `ftp`

If you later switch the database layer to `mysqli`, enable `mysqli` instead of or in addition to `pdo_mysql`. The current Nucleus connection helper uses PDO MySQL.

## File Storage Rule

For the 3-server demo, set `FILE_STORAGE_DRIVER=ftp`. Uploaded file bytes are sent to the Ubuntu FTP server. The web server project folder should only contain application code, logs, locks, and temporary PHP upload files during a request.
