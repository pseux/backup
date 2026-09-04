<?php

namespace Pseux\Backup\Commands;

use Illuminate\Support\Str;
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
}
