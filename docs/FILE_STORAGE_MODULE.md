# Nucleus File Storage Module

## Architecture

The Files dashboard tab is a small Google Drive-style storage area. The web server runs Apache/PHP, MySQL/MariaDB stores only metadata, and the FTP server stores actual file bytes.

Actual uploaded files are sent to FTP. The web server only uses PHP temporary files during upload and download requests.

## Environment

Required `.env` values:

```env
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
HANDLER_QUOTA_BYTES=1073741824
```

FTP credentials are read server-side only and are never sent to the browser.

## Role Quotas

- Admin and superadmin: `ADMIN_QUOTA_BYTES`, default 5GB.
- Handler and other roles: `HANDLER_QUOTA_BYTES`, default 1GB.

Quota is calculated from `drive_files.file_size`. Nucleus does not scan FTP folders for quota.

## FTP Folder Structure

Nucleus builds FTP paths from server-side IDs:

```text
/storage/users/{userId}/
/storage/users/{userId}/folders/{folderId}/
```

Raw folder names and client-provided paths are never used as FTP paths.

## Database Tables

Migration:

```text
database/migrations/002_create_drive_storage_mysql.sql
```

Tables:

- `drive_folders`
- `drive_files`

The schema uses the existing Nucleus user key: `users.userId`.

## Flow

Upload:

1. Validate authenticated session and CSRF token.
2. Validate file exists and size is below `UPLOAD_MAX_BYTES`.
3. Check role quota using metadata.
4. Generate a safe stored filename.
5. Upload the temp file to FTP.
6. Insert metadata into MySQL/MariaDB.
7. If metadata insert fails, delete the FTP file to avoid orphans.

Download:

1. Fetch metadata by file ID.
2. Check access: admins can manage all files, handlers manage their own.
3. Download from FTP to a temporary server file.
4. Stream to the browser.
5. Delete the temporary file.

Delete:

1. Fetch metadata and check access.
2. Delete from FTP first.
3. Delete database metadata only after FTP deletion succeeds.
4. Folders can only be deleted when empty.

Rename:

- File rename updates display metadata only.
- Folder rename updates metadata only. FTP directory names are ID-based, so no FTP rename is needed.

## Troubleshooting

FTP login failed:

- Check `FTP_HOST`, `FTP_PORT`, `FTP_USERNAME`, and `FTP_PASSWORD`.
- Verify the FTP user can log in from the web server.

Permission denied:

- Make sure the FTP user can create directories and upload/delete files under `FTP_ROOT`.

Quota exceeded:

- Check `ADMIN_QUOTA_BYTES` and `HANDLER_QUOTA_BYTES`.
- Quota is based on `drive_files.file_size`.

Passive mode issue:

- Keep `FTP_PASSIVE=true` for most VM/NAT setups.
- Open the passive port range on the FTP server firewall.

Wrong FTP IP:

- Use the Ubuntu VM IP reachable from the web server, not `localhost`.

Temp folder not writable:

- PHP must be able to write to `sys_get_temp_dir()`.
- Upload/download uses temporary files only during the request.
