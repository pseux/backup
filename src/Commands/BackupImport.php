<?php

namespace Pseux\Backup\Commands;

use Illuminate\Console\ConfirmableTrait;
use Throwable;

class BackupImport extends BaseBackup
{
	use ConfirmableTrait;

	protected $signature = 'backup:import
		{type : What to import: db or env}
		{source? : Environment the backup was taken from (defaults to the current one)}
		{--force : Run without confirmation when in production}';

	protected $description = 'Restore the database or .env file from S3';

	public function handle(): int
	{
		$type = $this->argument('type');

		if (!in_array($type, ['db', 'env']))
		{
			$this->error('Invalid type: ' . $type . ' (expected db or env)');
			return self::INVALID;
		}

		if (!$this->confirmToProceed() || !$this->loadCredentials())
			return self::FAILURE;

		$remote = $this->remoteDir($this->argument('source'));

		return $type === 'db' ? $this->importDatabase($remote) : $this->importEnv($remote);
	}

	private function importEnv(string $remote): int
	{
		$path = $remote . '/current.env';

		try
		{
			$contents = $this->disk()->get($path);
		}
		catch (Throwable $e)
		{
			$this->error('Remote backup not available: ' . $e->getMessage());
			return self::FAILURE;
		}

		file_put_contents(base_path('.env'), $contents);

		$this->info('Backup loaded: ' . $path);
		return self::SUCCESS;
	}

	private function importDatabase(string $remote): int
	{
		$db = $this->databaseConfig();
		if ($db === null)
			return self::FAILURE;

		$path = $remote . '/current.sql.gz';
		$gz = $this->storageDir() . '/import.sql.gz';
		$sql = $this->storageDir() . '/import.sql';

		// -- Download
		try
		{
			$stream = $this->disk()->readStream($path);
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
