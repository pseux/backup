<?php

namespace Pseux\Backup\Commands;

use Aws\Configuration\ConfigurationResolver;
use Aws\Credentials\CredentialProvider;
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
	 * Credentials come from, in order: the profile named by --profile or
	 * AWS_PROFILE; the app's own s3 disk if it has a key and secret; a
	 * profile called `backup` if one exists in ~/.aws; and finally the AWS
	 * SDK's usual chain (environment, default profile, instance role).
	 *
	 * The bucket is AWS_BACKUP_BUCKET if set, so backups can go somewhere
	 * other than the bucket the app uses for its own storage. Otherwise it
	 * is the app's bucket, falling back to `backup_bucket` in the chosen
	 * profile's section of ~/.aws/config, so one server-wide bucket can
	 * serve every site without touching each site's .env.
	 */
	protected function disk(): Filesystem
	{
		$config = config('filesystems.disks.s3') ?: [];
		$profile = $this->profile(!empty($config['key']) && !empty($config['secret']));

		if ($profile)
		{
			unset($config['key'], $config['secret'], $config['token']);
			$config['profile'] = $profile;
		}

		if ($override = getenv('AWS_BACKUP_BUCKET'))
			$config['bucket'] = $override;

		if (empty($config['bucket']))
		{
			// The app has no S3 setup of its own, so take bucket and region
			// from ~/.aws/config. Laravel's stock .env ships a placeholder
			// region, which must not override the one alongside the bucket.
			// The SDK only reads the default profile's region on its own, so
			// the chosen profile's region has to be looked up here.
			$config['bucket'] = ConfigurationResolver::ini('backup_bucket', 'string', $profile);
			$config['region'] = ConfigurationResolver::env('region') ?? ConfigurationResolver::ini('region', 'string', $profile);
		}

		if (empty($config['bucket']))
			throw new RuntimeException('No backup bucket configured. Set backup_bucket in ~/.aws/config (or AWS_BUCKET in .env).');

		return Storage::build(['driver' => 's3', 'throw' => true] + $config);
	}

	/**
	 * The AWS profile to use, or null to leave it to the SDK.
	 *
	 * An explicit choice (--profile, or AWS_PROFILE in the environment or
	 * the app's .env) always wins. Otherwise the app's own credentials are
	 * respected if it has any, then a `backup` profile is used if one
	 * exists, so a server can keep a dedicated backup key without every
	 * site having to name it.
	 */
	protected function profile(bool $appHasCredentials): ?string
	{
		if ($this->hasOption('profile') && $this->option('profile'))
			return $this->option('profile');

		if ($profile = getenv('AWS_PROFILE'))
			return $profile;

		if ($appHasCredentials)
			return null;

		return $this->profileExists('backup') ? 'backup' : null;
	}

	/**
	 * Whether a profile is defined in ~/.aws/credentials or ~/.aws/config,
	 * honouring the SDK's environment variables for relocating either file.
	 */
	protected function profileExists(string $name): bool
	{
		$home = CredentialProvider::getHomeDir();
		$files = [
			[getenv('AWS_SHARED_CREDENTIALS_FILE') ?: $home . '/.aws/credentials', [$name]],
			[getenv('AWS_CONFIG_FILE') ?: $home . '/.aws/config', ['profile ' . $name, $name]],
		];

		foreach ($files as [$file, $sections])
		{
			if (!is_readable($file) || !($data = @\Aws\parse_ini_file($file, true, INI_SCANNER_RAW)))
				continue;

			foreach ($sections as $section)
				if (isset($data[$section]))
					return true;
		}

		return false;
	}

	/**
	 * Name of a database dump for the given extension. The timestamp comes
	 * first so a plain sort of a folder listing puts the newest last.
	 */
	protected function dumpFilename(string $extension): string
	{
		return 'db-' . date('Ymd-His') . '-' . Str::lower(Str::random(5)) . '.' . $extension . '.gz';
	}

	/**
	 * Whether a remote path is a database dump with the given extension.
	 */
	protected function isDump(string $path, string $extension): bool
	{
		return (bool) preg_match('/\/db-\d{8}-\d{6}-[a-z0-9]+\.' . preg_quote($extension, '/') . '\.gz$/', $path);
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
	 * Config for the default database connection. MySQL-compatible
	 * connections are dumped with mysqldump; SQLite databases are copied
	 * as a file.
	 */
	protected function databaseConfig(): array
	{
		$name = config('database.default');
		$db = config('database.connections.' . $name);

		if (!in_array($db['driver'] ?? null, ['mysql', 'mariadb', 'sqlite']))
			throw new RuntimeException('Unsupported database driver for connection "' . $name . '": only mysql, mariadb and sqlite are supported.');

		if ($this->isSqlite($db) && in_array($db['database'] ?? '', ['', ':memory:']))
			throw new RuntimeException('Only file-based SQLite databases can be backed up.');

		return $db;
	}

	protected function isSqlite(array $db): bool
	{
		return $db['driver'] === 'sqlite';
	}

	/**
	 * Extension of the dump file: a plain SQL dump for MySQL, or a copy of
	 * the database file itself for SQLite.
	 */
	protected function dumpExtension(array $db): string
	{
		return $this->isSqlite($db) ? 'sqlite' : 'sql';
	}

	/**
	 * Absolute path to the SQLite database file, resolved relative to the
	 * app root the same way Laravel's connector does.
	 */
	protected function sqlitePath(array $db): string
	{
		$path = $db['database'];

		return realpath($path) ?: (str_starts_with($path, '/') ? $path : base_path($path));
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

	/**
	 * Options for mysqldump.
	 *
	 * MySQL's mysqldump embeds SET @@GLOBAL.GTID_PURGED when GTIDs are on,
	 * which makes the dump refuse to import into any server that already has
	 * GTIDs, including the one it came from. MariaDB's mysqldump has no such
	 * option, so only pass it where it is supported.
	 */
	protected function mysqldumpOptions(): string
	{
		$options = ['--no-tablespaces', '--single-transaction'];

		if (str_contains(Process::run('mysqldump --help')->output(), 'set-gtid-purged'))
			$options[] = '--set-gtid-purged=OFF';

		return implode(' ', $options);
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
