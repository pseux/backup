<?php

namespace Pseux\Backup\Commands;

use Illuminate\Http\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class Backup extends BaseBackup
{
	protected $signature = 'backup {type}';
	protected $description = 'Creating backups of files';

	// --

	public function __construct()
	{
		parent::__construct();
	}

	public function handle()
	{
		if (env('AWS_ACCESS_KEY_ID') === null)
			return $this->error('No AWS credentials found.');

		$type = $this->argument('type');

		switch ($type)
		{
			case 'db':
				return $this->runBackupDB();

			case 'env':
				return $this->runBackupEnv();

			default:
				$this->error('Invalid type: ' . $type);
				exit;
		}
	}

	private function runBackupEnv()
	{
		if (config('app.env') !== 'local')
			return $this->error('Only run on local. #security');

		$remote_dir = config('app.env') . '-' . config('app.name');
		$remote_dir = Str::slug($remote_dir);

		try
		{
			Storage::disk('s3')->putFileAs($remote_dir, new File(base_path('.env')), 'current.env');
		}
		catch (\Exception $e)
		{
			$this->error('Error creating backup: ' . $e->getMessage());
			exit;
		}

		$this->info('Backup successful: ' . $remote_dir);
	}

	private function runBackupDB()
	{
		$dir = $this->getStorageDir();

		$remote_dir = config('app.env') . '-' . config('app.name');
		$remote_dir = Str::slug($remote_dir);

		$filename = 'db-' . date('Ymd-His') . '-' . substr(md5(microtime()), 0, 5) . '.sql.gz';

		$password = config('database.connections.mysql.password');
		if ($password) $password = '-p\'' . $password . '\'';

		$command = sprintf('mysqldump --no-tablespaces %s -u \'%s\' %s | gzip > %s',
			config('database.connections.mysql.database'),
			config('database.connections.mysql.username'),
			$password,
			$dir . '/' . $filename
		);

		try
		{
			// -- Create backup
			exec($command);

			// -- Delete other backups
			$files = glob($dir . '/*');
			foreach ($files as $file)
				if (basename($file) != $filename)
					unlink($file);

			// -- Upload backup
			Storage::disk('s3')->putFileAs($remote_dir, new File($dir . '/' . $filename), $filename);
			Storage::disk('s3')->delete($remote_dir . '/current.sql.gz');
			Storage::disk('s3')->copy($remote_dir . '/' . $filename, $remote_dir . '/current.sql.gz');
		}
		catch (\Exception $e)
		{
			$this->error('Error creating backup: ' . $e->getMessage());
			exit;
		}

		$this->info('Backup successful: ' . $remote_dir);
	}

	private function getStorageDir()
	{
		$dir = storage_path('app/backups');

		if (!is_dir($dir))
			mkdir($dir, 0777, true);

		return $dir;
	}
}
