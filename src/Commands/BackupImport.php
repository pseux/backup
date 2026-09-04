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

		$path = $this->remoteDir($this->argument('source')) . '/current.sql.gz';
		$gz = $this->storageDir() . '/import.sql.gz';
		$sql = $this->storageDir() . '/import.sql';

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
		$command = sprintf('mysql %s %s < %s',
			$this->mysqlArguments($db),
			escapeshellarg($db['database']),
			escapeshellarg($sql)
		);

		$ok = $this->shell('gunzip -f ' . escapeshellarg($gz)) && $this->shell($command, $this->mysqlEnv($db));

		@unlink($gz);
		@unlink($sql);

		if (!$ok)
			return self::FAILURE;

		$this->info('Backup loaded: ' . $path);
		return self::SUCCESS;
	}
}
