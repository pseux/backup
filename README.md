# Laravel package: backups

Dumps your MySQL/MariaDB or SQLite database, gzips it and uploads it to an S3 bucket. Can restore it again too. Also syncs the site's uploaded files in `storage/app` to the same bucket.

Requires PHP 8.1+ and Laravel 10 or newer, with `gzip` and `gunzip` available on the server, plus `mysqldump` and `mysql` for MySQL/MariaDB. SQLite needs nothing extra: the database is copied with `VACUUM INTO` through PDO, which gives a consistent snapshot even while the app is writing.

## Installation

	composer require pseux/backup

## Config

Nothing needs to go in the app's `.env`. Credentials, region and bucket are read from the standard AWS locations in the home directory of the user running the command, so one server-wide setup covers every site on the box.

`~/.aws/credentials` (or just run `aws configure`):

```ini
[default]
aws_access_key_id = AKIA...
aws_secret_access_key = ...
```

`~/.aws/config`:

```ini
[default]
region = eu-west-2
backup_bucket = my-server-backups
```

Both files should be `chmod 600`. On EC2 or ECS you can leave out the credentials file entirely and the instance role is used.

Notes:

- `backup_bucket` is a custom key; the `aws` CLI ignores it.
- `AWS_PROFILE` selects a different profile, and `AWS_BACKUP_BUCKET` overrides the bucket, which is handy in a cron line.
- If the app's own `s3` disk has a bucket configured (`AWS_BUCKET` in `.env`), that bucket and those credentials win, so a site can opt out of the server default.

Backups are stored in the bucket under `{APP_ENV}-{APP_NAME}/`, slugified (for example `production-my-app/`). Each backup is kept as a timestamped file, and the latest one is also copied to `current.sql.gz` (or `current.sqlite.gz` for SQLite). Synced files go in a `files/` folder alongside them.

## Commands

	php artisan backup          # dump the default database connection and upload it
	php artisan backup:import   # restore current.sql.gz into the default database connection
	php artisan backup:files    # sync storage/app to S3

`backup:import` takes an optional source environment, so you can pull a production backup into a local database:

	php artisan backup:import production

When run in production, `backup:import` asks for confirmation first. Pass `--force` to skip the prompt.

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

- `backup db` is now just `backup`, and `backup:import db` is `backup:import`. Update any schedules.
- The `.env` backup and restore have been removed.
- `~/.backupconfig` is no longer read. Move the key and secret to `~/.aws/credentials`, and the region and bucket to `~/.aws/config` as above.
