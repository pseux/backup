<?php

namespace Pseux\Backup;

use Illuminate\Support\ServiceProvider;
use Pseux\Backup\Commands\Backup;

class BackupServiceProvider extends ServiceProvider
{
	/**
	 * Bootstrap the application services.
	 *
	 * @return void
	 */
	public function boot()
	{
		if ($this->app->runningInConsole())
		{
			$this->commands([
				Backup::class,
			]);
		}
	}

	/**
	 * Register the application services.
	 *
	 * @return void
	 */
	public function register()
	{
		//
	}
}
