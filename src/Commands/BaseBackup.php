<?php

namespace Pseux\Backup\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class BaseBackup extends Command
{
	/**
	 * Make sure the s3 disk is configured, falling back to ~/.backupconfig
	 * when the app itself has no AWS credentials.
	 */
	protected function loadCredentials(): bool
	{
		if (empty(config('filesystems.disks.s3.key')))
		{
			$home = env('HOME');

			if ($home && is_file($home . '/.backupconfig'))
			{
				Dotenv::create(Env::getRepository(), $home, '.backupconfig')->load();

				config([
					'filesystems.disks.s3.key'    => env('AWS_ACCESS_KEY_ID'),
					'filesystems.disks.s3.secret' => env('AWS_SECRET_ACCESS_KEY'),
					'filesystems.disks.s3.region' => env('AWS_DEFAULT_REGION'),
					'filesystems.disks.s3.bucket' => env('AWS_BUCKET'),
				]);
			}
		}

		if (empty(config('filesystems.disks.s3.bucket')))
		{
			$this->error('No S3 configuration found. Set AWS_* in .env or in ~/.backupconfig.');
			return false;
		}

		return true;
	}

	/**
	 * The s3 disk, built to throw so failures carry their reason.
	 */
	protected function disk(): Filesystem
	{
		return Storage::build(array_merge(config('filesystems.disks.s3'), ['throw' => true]));
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
	protected function databaseConfig(): ?array
	{
		$name = config('database.default');
		$db = config('database.connections.' . $name);

		if (!in_array($db['driver'] ?? null, ['mysql', 'mariadb']))
		{
			$this->error('Unsupported database driver for connection "' . $name . '": only mysql and mariadb are supported.');
			return null;
		}

		return $db;
	}

	/**
	 * Connection arguments shared by mysql and mysqldump, shell-escaped.
	 * The password is deliberately left out: it is passed via MYSQL_PWD
	 * in shell() so it never appears on the command line.
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

	protected function mysqlEnv(array $db): array
	{
		return empty($db['password']) ? [] : ['MYSQL_PWD' => $db['password']];
	}
}
