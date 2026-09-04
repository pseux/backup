<?php

namespace Pseux\Backup\Commands;

use Illuminate\Console\ConfirmableTrait;
use Throwable;

class BackupImport extends BaseBackup
{
	use ConfirmableTrait;

	protected $signature = 'backup:import
		{source? : Environment the backup was taken from (defaults to the current one)}
		{--force : Run without confirmation when in production}';

	protected $description = 'Restore the latest database backup from S3';

	public function handle(): int
	{
		if (!$this->confirmToProceed())
			return self::FAILURE;

		try
		{
			$db = $this->databaseConfig();
			$disk = $this->disk();
		}
		catch (Throwable $e)
		{
			$this->error($e->getMessage());
			return self::FAILURE;
		}

		$extension = $this->dumpExtension($db);
		$path = $this->remoteDir($this->argument('source')) . '/current.' . $extension . '.gz';
		$dump = $this->storageDir() . '/import.' . $extension;
		$gz = $dump . '.gz';

		// -- Download
		try
		{
			$stream = $disk->readStream($path);
		}
		catch (Throwable $e)
		{
			$this->error('Remote backup not available: ' . $e->getMessage());
			return self::FAILURE;
		}

		file_put_contents($gz, $stream);
		fclose($stream);

		// -- Unzip and import
		$ok = $this->shell('gunzip -f ' . escapeshellarg($gz))
			&& ($this->isSqlite($db) ? $this->restoreSqlite($db, $dump) : $this->mysqlImport($db, $dump));

		@unlink($gz);
		@unlink($dump);

		if (!$ok)
			return self::FAILURE;

		$this->info('Backup loaded: ' . $path);
		return self::SUCCESS;
	}

	protected function mysqlImport(array $db, string $from): bool
	{
		$command = sprintf('mysql %s %s < %s',
			$this->mysqlArguments($db),
			escapeshellarg($db['database']),
			escapeshellarg($from)
		);

		return $this->shell($command, $this->mysqlEnv($db));
	}

	/**
	 * Swap the restored file in for the current database. Any WAL or
	 * journal left over from the old database would corrupt the new one,
	 * so those go too.
	 */
	protected function restoreSqlite(array $db, string $from): bool
	{
		$target = $this->sqlitePath($db);
		$dir = dirname($target);

		if (!is_dir($dir))
			mkdir($dir, 0755, true);

		foreach (['-wal', '-shm', '-journal'] as $suffix)
			@unlink($target . $suffix);

		if (!rename($from, $target))
		{
			$this->error('Could not replace ' . $target);
			return false;
		}

		return true;
	}
}
