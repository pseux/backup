# Laravel package: backups

Dumps your MySQL/MariaDB database, gzips it and uploads it to an S3 bucket. Can restore it again too.

Requires PHP 8.1+ and Laravel 10 or newer, with `mysqldump`, `mysql`, `gzip` and `gunzip` available on the server.

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

Backups are stored in the bucket under `{APP_ENV}-{APP_NAME}/`, slugified (for example `production-my-app/`). Each backup is kept as a timestamped file, and the latest one is also copied to `current.sql.gz`.

## Commands

	php artisan backup          # dump the default database connection and upload it
	php artisan backup:import   # restore current.sql.gz into the default database connection

`backup:import` takes an optional source environment, so you can pull a production backup into a local database:

	php artisan backup:import production

When run in production, `backup:import` asks for confirmation first. Pass `--force` to skip the prompt.

## Scheduling backups

Laravel 11 and newer, in `routes/console.php`:

	use Illuminate\Support\Facades\Schedule;

	Schedule::command('backup')->daily();

Laravel 10, in the `schedule` method of `App\Console\Kernel`:

	$schedule->command('backup')->daily();

The scheduler runs as whichever user owns the cron entry, so the `~/.aws/` files need to be in that user's home directory.

## Upgrading from earlier versions

- `backup db` is now just `backup`, and `backup:import db` is `backup:import`. Update any schedules.
- The `.env` backup and restore have been removed.
- `~/.backupconfig` is no longer read. Move the key and secret to `~/.aws/credentials`, and the region and bucket to `~/.aws/config` as above.
