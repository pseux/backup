<?php

namespace Pseux\Backup\Commands;

use Illuminate\Http\File;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class BackupImport extends Command
{
	protected $signature = 'backup:import {type} {source?}';
	protected $description = 'Import previous backups';

	// --

	public function __construct()
	{
		parent::__construct();
	}

	public function handle()
	{
		$type = $this->argument('type');
		$source = $this->argument('source');
		if ($source === null) $source = config('app.env');

		switch ($type)
		{
			case 'db':
				return $this->runImportDB($source);

			case 'env':
				return $this->runImportEnv($source);

			default:
				$this->error('Invalid type: ' . $type);
				exit;
		}
	}

	private function runImportEnv($source)
	{
		$remote_dir = $source . '-' . config('app.name');
		$remote_dir = Str::slug($remote_dir);

		try
		{
			$file = Storage::disk('s3')->get($remote_dir . '/current.env');
			Storage::createLocalDriver(['root' => base_path()])->put('.env', $file);
		}
		catch (\Exception $e)
		{
			$this->error('Remote backup not available.');
			exit;
		}

		$this->info('Backup loaded.');
	}

	private function runImportDB($source)
	{
		$remote_dir = $source . '-' . config('app.name');
		$remote_dir = Str::slug($remote_dir);

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
