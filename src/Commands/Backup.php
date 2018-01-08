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
	protected $signature = 'backup:run';

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

			// -- Optionally remove backup
			$retain_one = env('BACKUP_RETAIN', true);
			if (!$retain_one) unlink($dir . '/' . $filename);
		}
		catch (\Exception $e)
		{
			throw $e;
			// dump('Backup error: ' . $e->getMessage());
		}

		dump('Backup successful');
	}
}
