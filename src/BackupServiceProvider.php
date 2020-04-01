<?php

namespace Pseux\Backup;

use Illuminate\Support\ServiceProvider;
use Pseux\Backup\Commands\Backup;
use Pseux\Backup\Commands\BackupImport;

class BackupServiceProvider extends ServiceProvider
{
	public function boot()
	{
		if ($this->app->runningInConsole())
		{
			$this->commands([
				Backup::class,
				BackupImport::class,
			]);
		}
	}

	public function register()
	{
	}
}
