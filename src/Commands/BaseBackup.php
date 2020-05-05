<?php

namespace Pseux\Backup\Commands;

use Illuminate\Console\Command;
use App;
use \Dotenv\Dotenv;

class BaseBackup extends Command
{
	public function __construct()
	{
		parent::__construct();

		if (empty(env('AWS_ACCESS_KEY_ID')))
		{
			if (!is_file(env('HOME') . '/.backupconfig'))
				return;

			if (!method_exists(Dotenv::class, 'createMutable')) // Dotenv 2 support
			{
				$dotenv = new Dotenv(env('HOME'), '.backupconfig');
				$dotenv->load();
			}
			else
			{
				$dotenv = Dotenv::createMutable(env('HOME'), '.backupconfig');
				$dotenv->load();
			}

			config(['filesystems.disks.s3.key'    => env('AWS_ACCESS_KEY_ID')]);
			config(['filesystems.disks.s3.secret' => env('AWS_SECRET_ACCESS_KEY')]);
			config(['filesystems.disks.s3.region' => env('AWS_DEFAULT_REGION')]);
			config(['filesystems.disks.s3.bucket' => env('AWS_BUCKET')]);
		}
	}
}
