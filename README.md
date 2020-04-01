# Laravel package: backups

## Config

```
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
```

## Examples

	php artisan backup db
	php artisan backup:import env

## Scheduling backups

Add the following code to the `schedule` function in your `App\Console\Kernel.php` file:

	$schedule->command('backup db')->daily();
