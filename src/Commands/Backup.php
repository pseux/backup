<?php

namespace Pseux\Backup\Commands;

use Illuminate\Http\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class Backup extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'backup:run {--import=}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Backup the database';

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		if ($this->option('import') !== null)
			return $this->runImport($this->option('import'));

		$this->runBackup();
	}

	private function runBackup()
	{
		$dir = storage_path('app/backups');
		if (!is_dir($dir)) mkdir($dir, 0777, true);

		$remote_dir = config('app.env') . '-' . config('app.name');
		$remote_dir = str_slug($remote_dir);

		$filename = 'db-' . date('Ymd-His') . '-' . substr(md5(microtime()), 0, 5) . '.sql.gz';

		$password = config('database.connections.mysql.password');
		if ($password) $password = '-p\'' . $password . '\'';

		$command = sprintf('mysqldump %s -u \'%s\' %s | gzip > %s',
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

		$this->info('Backup successful.');
	}

	private function runImport($source)
	{
		$remote_dir = $source . '-' . config('app.name');
		$remote_dir = str_slug($remote_dir);

		try
		{
			// Download file
			$file = Storage::disk('s3')->get($remote_dir . '/current.sql.gz');
			Storage::disk('local')->put('database.sql.gz', $file);

			// Unzip file
			$command = sprintf('gunzip -f %s',
				storage_path('app/database.sql.gz')
			);
			exec($command);

			// Import SQL file
			$password = config('database.connections.mysql.password');
			if ($password) $password = '-p\'' . $password . '\'';

			$command = sprintf('mysql -u \'%s\' %s %s < %s',
				config('database.connections.mysql.username'),
				$password,
				config('database.connections.mysql.database'),
				storage_path('app/database.sql')
			);

			exec($command);

			// Remove file
			Storage::disk('local')->delete('database.sql');
		}
		catch (\Exception $e)
		{
			$this->error('Remote backup not available');
			exit;
		}

		$this->info('Backup loaded.');
	}
}
