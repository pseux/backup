<?php

namespace Pseux\Backup\Commands;

use Aws\Configuration\ConfigurationResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

abstract class BaseBackup extends Command
{
	/**
	 * The S3 disk used for backups.
	 *
	 * Starts from the app's own s3 disk config. Anything left empty there is
	 * resolved by the AWS SDK the standard way: credentials and region from
	 * AWS_* environment variables, ~/.aws/credentials and ~/.aws/config, or
	 * the instance role. The bucket falls back to `backup_bucket` in
	 * ~/.aws/config (or AWS_BACKUP_BUCKET), so one server-wide bucket can
	 * serve every site without touching each site's .env.
	 */
	protected function disk(): Filesystem
	{
		$config = config('filesystems.disks.s3') ?: [];

		if (empty($config['bucket']))
		{
			// The app has no S3 setup of its own, so take bucket and region
			// from ~/.aws/config. Laravel's stock .env ships a placeholder
			// region, which must not override the one alongside the bucket.
			unset($config['region']);
			$config['bucket'] = ConfigurationResolver::resolve('backup_bucket', null, 'string');
		}

		if (empty($config['bucket']))
			throw new RuntimeException('No backup bucket configured. Set backup_bucket in ~/.aws/config (or AWS_BUCKET in .env).');

		return Storage::build(['driver' => 's3', 'throw' => true] + $config);
	}

	protected function remoteDir(?string $env = null): string
	{
		return Str::slug(($env ?: config('app.env')) . '-' . config('app.name'));
	}

	protected function storageDir(): string
	{
		$dir = storage_path('app/backups');

		if (!is_dir($dir))
			mkdir($dir, 0755, true);

		return $dir;
	}

	/**
	 * Config for the default database connection. Only MySQL-compatible
	 * connections are supported, since the dump goes through mysqldump.
	 */
	protected function databaseConfig(): array
	{
		$name = config('database.default');
		$db = config('database.connections.' . $name);

		if (!in_array($db['driver'] ?? null, ['mysql', 'mariadb']))
			throw new RuntimeException('Unsupported database driver for connection "' . $name . '": only mysql and mariadb are supported.');

		return $db;
	}

	/**
	 * Connection arguments shared by mysql and mysqldump, shell-escaped.
	 * The password is deliberately left out: it is passed via MYSQL_PWD
	 * (see mysqlEnv) so it never appears on the command line.
	 */
	protected function mysqlArguments(array $db): string
	{
		$args = [];

		if (!empty($db['unix_socket']))
			$args[] = '--socket=' . $db['unix_socket'];
		else
		{
			$args[] = '--host=' . ($db['host'] ?? '127.0.0.1');
			$args[] = '--port=' . ($db['port'] ?? 3306);
		}

		$args[] = '--user=' . ($db['username'] ?? '');

		return implode(' ', array_map('escapeshellarg', $args));
	}

	protected function mysqlEnv(array $db): array
	{
		return empty($db['password']) ? [] : ['MYSQL_PWD' => $db['password']];
	}

	/**
	 * Run a shell command, printing its output if it fails.
	 */
	protected function shell(string $command, array $env = []): bool
	{
		$result = Process::env($env)->run($command);

		if ($result->failed())
		{
			$this->error('Command failed (exit code ' . $result->exitCode() . '):');
			foreach (explode("\n", trim($result->output() . "\n" . $result->errorOutput())) as $line)
				if ($line !== '')
					$this->line('  ' . $line);
		}

		return $result->successful();
	}
}
