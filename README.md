# Laravel package: backups

Backs up your MySQL/MariaDB database (and, locally, your `.env` file) to an S3 bucket, and restores them again.

Requires PHP 8.1+ and Laravel 10 or newer, with `mysqldump`, `mysql`, `gzip` and `gunzip` available on the server.

## Installation

	composer require pseux/backup

## Config

The package uses the app's `s3` filesystem disk, so the usual variables in `.env` are enough:

```
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
```

If the app has no AWS key configured, the same variables are read from `~/.backupconfig` in the home directory of the user running the command instead.

Backups are stored in the bucket under `{APP_ENV}-{APP_NAME}/`, slugified (for example `production-my-app/`). Each database backup is kept as a timestamped file, and the latest one is also copied to `current.sql.gz`.

## Commands

	php artisan backup db          # dump the default database connection and upload it
	php artisan backup env         # upload .env as current.env (local environment only)

	php artisan backup:import db   # restore current.sql.gz into the default database connection
	php artisan backup:import env  # overwrite .env with current.env

`backup:import` takes an optional source environment, so you can pull a production backup into a local database:

	php artisan backup:import db production

When run in production, `backup:import` asks for confirmation first. Pass `--force` to skip the prompt.

## Scheduling backups

Laravel 11 and newer, in `routes/console.php`:

	use Illuminate\Support\Facades\Schedule;

	Schedule::command('backup db')->daily();

Laravel 10, in the `schedule` method of `App\Console\Kernel`:

	$schedule->command('backup db')->daily();
