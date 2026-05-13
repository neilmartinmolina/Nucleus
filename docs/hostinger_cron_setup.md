# Hostinger Cron Setup for Nucleus Monitoring

Nucleus monitoring is designed to run from one global queue. The dashboard reads the latest database state; cron is responsible for doing the network checks in the background.

## hPanel setup

1. Open Hostinger hPanel.
2. Go to **Websites** and choose the site running Nucleus.
3. Open **Advanced** > **Cron Jobs**.
4. Choose **Custom** cron job.
5. Add the monitoring queue command.
6. Set the interval to every 2 to 5 minutes.
7. Save the cron job.

Recommended monitoring command:

```bash
php /home/USERNAME/public_html/handlers/run_monitoring_queue.php batch=3
```

Replace `USERNAME` with the Hostinger account username and adjust the path if Nucleus is installed in a subdirectory.

## Recommended interval

Run the queue every 2 to 5 minutes.

Use `batch=3` to `batch=5` on shared hosting. Each project can try multiple public endpoints, and slow or unreachable sites can consume several seconds. Small batches reduce the chance of hitting Hostinger cron time limits while still letting the scheduler cycle through projects safely.

For larger installations, prefer running cron more often with a small batch instead of running a large batch less often.

## Cleanup cron

Add a second cron job for retention cleanup:

```bash
php /home/USERNAME/public_html/handlers/cleanup_monitoring_data.php
```

Run cleanup once per day.

Cleanup removes old `deployment_checks`, old finished `monitoring_runs`, and old resolved `monitoring_alerts` based on `monitoring_settings.retention_days`. It does not delete unresolved monitoring alerts.

## Migration compatibility note

Some Hostinger plans may use MariaDB/MySQL versions that do not support every `IF NOT EXISTS` form for `ALTER TABLE ... ADD COLUMN` or `CREATE INDEX`.

If a migration fails on an `IF NOT EXISTS` index or column statement:

1. Check whether the column or index already exists in phpMyAdmin.
2. If it already exists, skip that statement and continue with the remaining migration.
3. If it does not exist, run the equivalent `ALTER TABLE ... ADD COLUMN ...` or `CREATE INDEX ...` statement without `IF NOT EXISTS`.

Required monitoring indexes:

```sql
deployment_checks(project_id, checked_at)
project_status(project_id)
monitoring_alerts(project_id, is_resolved)
monitoring_runs(started_at)
```
