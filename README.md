# Laravel package: backups

Dumps your MySQL/MariaDB or SQLite database, gzips it and uploads it to an S3 bucket. Can restore it again too. Also syncs the site's uploaded files in `storage/app` to the same bucket.

Requires PHP 8.1+ and Laravel 10 or newer, with `gzip` and `gunzip` available on the server, plus `mysqldump` and `mysql` for MySQL/MariaDB. SQLite needs nothing extra: the database is copied with `VACUUM INTO` through PDO, which gives a consistent snapshot even while the app is writing.

## Installation

	composer require pseux/backup

## Config

Nothing needs to go in the app's `.env`. Credentials, region and bucket are read from the standard AWS locations in the home directory of the user running the command, so one server-wide setup covers every site on the box.

`~/.aws/credentials`:

```ini
[backup]
aws_access_key_id = AKIA...
aws_secret_access_key = ...
```

`~/.aws/config`:

```ini
[profile backup]
region = eu-west-2
bucket = my-server-backups
```

Both files should be `chmod 600`. A `backup` profile is used when one exists; otherwise the `default` profile, so a setup that only has `[default]` works too. On EC2 or ECS you can leave out the credentials file entirely and the instance role is used.

`bucket` is a custom key that the `aws` CLI ignores. `backup_bucket`, the name used by earlier versions, is still read as a fallback.

### Per-site overrides

A site can override any of that with a `backups` disk in `config/filesystems.php`. Keys you set win; keys you leave out are filled in from the profile as above. So a site on a shared server that keeps its own bucket only needs:

```php
'backups' => [
    'bucket' => 'my-app-backups',
],
```

A site on a server with no `~/.aws/config` at all can carry the whole thing, with only the credentials file on the box:

```php
'backups' => [
    'profile' => 'my-app-backup',
    'region' => 'eu-west-2',
    'bucket' => 'my-app-backups',
],
```

Set `key` and `secret` instead of `profile` to use credentials from the app's own `.env`, or give a different `driver` (`local`, `sftp`, or `s3` with an `endpoint` for R2 or MinIO) to back up somewhere other than S3. With a non-S3 driver the array is used as it is.

`--profile=name` on any command, or `AWS_PROFILE` in the environment, picks a profile ahead of the disk and the lookup above. That's how a restore uses a key that can read when the server's own key can't.

Backups are stored under `{APP_ENV}-{APP_NAME}/`, slugified (for example `production-my-app/`). Each dump is a timestamped file such as `db-20260910-020000-k3x9q.sql.gz` (`.sqlite.gz` for SQLite). Synced files go in a `files/` folder alongside them.

Old dumps are never deleted by the package. Add a lifecycle rule to the bucket to expire them after however long you want to keep them.

### Permissions

A nightly backup only ever uploads, so the key in `~/.aws/credentials` can be write-only. If it leaks, nothing can be downloaded or deleted with it. This IAM policy is enough for `backup` and `backup:files`:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::my-server-backups"
    },
    {
      "Effect": "Allow",
      "Action": "s3:PutObject",
      "Resource": "arn:aws:s3:::my-server-backups/*"
    }
  ]
}
```

`backup:import` also needs `s3:GetObject`, and `backup:files --prune` needs `s3:DeleteObject`. Either grant those to the same key for sites where that's acceptable, or keep a second profile with read access somewhere safer and pass it with `--profile` when restoring.

## Commands

	php artisan backup          # dump the default database connection and upload it
	php artisan backup:import   # restore the newest dump into the default database connection
	php artisan backup:files    # sync storage/app to S3

Every command takes `--profile=name` to use a specific AWS profile.

`backup:import` finds the newest dump in the site's folder. It takes an optional source environment, so you can pull a production backup into a local database:

	php artisan backup:import production

When run in production, `backup:import` asks for confirmation first. Pass `--force` to skip the prompt. If the server's backup key is write-only, restore with a profile that can read the bucket:

	php artisan backup:import production --profile=restore

The backup has to match the local driver: a MySQL dump can't be restored into SQLite or the other way round. For SQLite, the restore replaces the database file outright, so anything written since the backup is lost.

`backup:files` mirrors `storage/app` (minus dotfiles and the package's own `backups/` folder) to `files/` in the site's S3 folder. It lists the remote folder once, then uploads only files that are new, or whose size or modified time differs, so a nightly run with few changes costs almost nothing. Sites with nothing in `storage/app` are skipped.

Files deleted locally are left in S3, so an accidental delete on the server isn't repeated in the backup. To remove them, run with `--prune`:

	php artisan backup:files --prune

## Scheduling backups

Laravel 11 and newer, in `routes/console.php`:

	use Illuminate\Support\Facades\Schedule;

	Schedule::command('backup')->daily();
	Schedule::command('backup:files')->daily();

Laravel 10, in the `schedule` method of `App\Console\Kernel`:

	$schedule->command('backup')->daily();
	$schedule->command('backup:files')->daily();

The scheduler runs as whichever user owns the cron entry, so the `~/.aws/` files need to be in that user's home directory.

## Upgrading from earlier versions

- 2.2: `current.sql.gz` is no longer written; `backup:import` picks the newest timestamped dump instead. Any `current.*` files already in the bucket can be deleted. Retention is now the bucket's job, so add a lifecycle rule if you didn't have one.
- 2.2: a `backup` profile in `~/.aws` is preferred over `default` when present. Nothing changes if you only have `[default]`.
- 2.2: the config key is now `bucket`; `backup_bucket` still works.
- 2.2: the app's `s3` disk (`AWS_BUCKET` and friends in `.env`) is no longer consulted. A site that relied on it should define a `backups` disk instead, see above. `AWS_BACKUP_BUCKET` from 2.1.1 is gone for the same reason.
- 2.0: `backup db` is now just `backup`, and `backup:import db` is `backup:import`. Update any schedules.
- 2.0: the `.env` backup and restore have been removed.
