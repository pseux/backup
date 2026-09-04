<?php

namespace Pseux\Backup\Commands;

use Illuminate\Support\Str;
use Throwable;

class Backup extends BaseBackup
{
	protected $signature = 'backup {type : What to back up: db or env}';
	protected $description = 'Back up the database or .env file to S3';

	public function handle(): int
	{
		$type = $this->argument('type');

		if (!in_array($type, ['db', 'env']))
		{
			$this->error('Invalid type: ' . $type . ' (expected db or env)');
			return self::INVALID;
		}

		if (!$this->loadCredentials())
			return self::FAILURE;

		return $type === 'db' ? $this->backupDatabase() : $this->backupEnv();
	}

	private function backupEnv(): int
	{
		if (config('app.env') !== 'local')
		{
			$this->error('The .env backup can only be run locally.');
			return self::FAILURE;
		}

		$remote = $this->remoteDir();

		try
		{
			$this->disk()->putFileAs($remote, base_path('.env'), 'current.env');
		}
		catch (Throwable $e)
		{
			$this->error('Error creating backup: ' . $e->getMessage());
			return self::FAILURE;
		}

		$this->info('Backup successful: ' . $remote . '/current.env');
		return self::SUCCESS;
	}

	private function backupDatabase(): int
	{
		$db = $this->databaseConfig();
		if ($db === null)
			return self::FAILURE;

		$dir = $this->storageDir();
		$remote = $this->remoteDir();

		$filename = 'db-' . date('Ymd-His') . '-' . Str::lower(Str::random(5)) . '.sql';
		$sql = $dir . '/' . $filename;
		$gz = $sql . '.gz';

		// -- Clear out previous local backups
		foreach (glob($dir . '/*') as $file)
			unlink($file);

		// -- Dump and compress
		$command = sprintf('mysqldump --no-tablespaces %s --result-file=%s %s',
			$this->mysqlArguments($db),
			escapeshellarg($sql),
			escapeshellarg($db['database'])
		);

		if (!$this->shell($command, $this->mysqlEnv($db)) || !$this->shell('gzip -f ' . escapeshellarg($sql)))
		{
			@unlink($sql);
			@unlink($gz);
			return self::FAILURE;
		}

		// -- Upload
		try
		{
			$disk = $this->disk();
			$disk->putFileAs($remote, $gz, basename($gz));
			$disk->delete($remote . '/current.sql.gz');
			$disk->copy($remote . '/' . basename($gz), $remote . '/current.sql.gz');
		}
		catch (Throwable $e)
		{
			$this->error('Error creating backup: ' . $e->getMessage());
			return self::FAILURE;
		}

		$this->info('Backup successful: ' . $remote . '/' . basename($gz));
		return self::SUCCESS;
	}
}
