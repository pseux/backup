<?php

namespace Pseux\Backup\Commands;

use Illuminate\Support\Str;
use PDO;
use Throwable;

class Backup extends BaseBackup
{
	protected $signature = 'backup';
	protected $description = 'Dump the database and upload it to S3';

	public function handle(): int
	{
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

		$dir = $this->storageDir();
		$remote = $this->remoteDir();

		$filename = 'db-' . date('Ymd-His') . '-' . Str::lower(Str::random(5)) . '.' . $this->dumpExtension($db);
		$dump = $dir . '/' . $filename;
		$gz = $dump . '.gz';

		// -- Clear out previous local backups
		foreach (glob($dir . '/*') as $file)
			unlink($file);

		// -- Dump and compress
		$dumped = $this->isSqlite($db) ? $this->copySqlite($db, $dump) : $this->mysqldump($db, $dump);

		if (!$dumped || !$this->shell('gzip -f ' . escapeshellarg($dump)))
		{
			@unlink($dump);
			@unlink($gz);
			return self::FAILURE;
		}

		// -- Upload
		try
		{
			$disk->putFileAs($remote, $gz, basename($gz));
			$disk->delete($remote . '/current.sql.gz');
			$disk->copy($remote . '/' . basename($gz), $remote . '/current.sql.gz');
		}
		catch (Throwable $e)
		{
			$this->error('Error uploading backup: ' . $e->getMessage());
			return self::FAILURE;
		}

		$this->info('Backup successful: ' . $remote . '/' . basename($gz));
		return self::SUCCESS;
	}

	protected function mysqldump(array $db, string $to): bool
	{
		$command = sprintf('mysqldump %s %s --result-file=%s %s',
			$this->mysqldumpOptions(),
			$this->mysqlArguments($db),
			escapeshellarg($to),
			escapeshellarg($db['database'])
		);

		return $this->shell($command, $this->mysqlEnv($db));
	}

	/**
	 * Copy the SQLite database with VACUUM INTO rather than copying the
	 * file, so the result is consistent even while the app is writing and
	 * includes anything still sitting in the WAL.
	 */
	protected function copySqlite(array $db, string $to): bool
	{
		$path = $this->sqlitePath($db);

		if (!is_file($path))
		{
			$this->error('SQLite database not found: ' . $path);
			return false;
		}

		try
		{
			$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
			$pdo->exec('VACUUM INTO ' . $pdo->quote($to));
			return true;
		}
		catch (Throwable $e)
		{
			$this->error('SQLite copy failed: ' . $e->getMessage());
			return false;
		}
	}
}
