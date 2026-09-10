<?php

namespace Pseux\Backup\Commands;

use Symfony\Component\Finder\Finder;
use Throwable;

class BackupFiles extends BaseBackup
{
	protected $signature = 'backup:files
		{--prune : Delete files from S3 that no longer exist locally}
		{--profile= : AWS profile to use instead of the default lookup}';

	protected $description = 'Sync storage/app to S3, uploading only new or changed files';

	public function handle(): int
	{
		try
		{
			$disk = $this->disk();
		}
		catch (Throwable $e)
		{
			$this->error($e->getMessage());
			return self::FAILURE;
		}

		$prefix = $this->remoteDir() . '/files';

		// Dotfiles (such as the .gitignore Laravel ships in storage/app) are
		// skipped by Finder, and backups/ is this package's own scratch dir.
		$files = Finder::create()->files()->in(storage_path('app'))->exclude('backups')->ignoreUnreadableDirs();

		if (!$files->hasResults())
		{
			$this->info('Nothing to sync: storage/app is empty.');
			return self::SUCCESS;
		}

		// -- One listing call per thousand objects, giving size and modified time for each
		try
		{
			$remote = [];
			foreach ($disk->getDriver()->listContents($prefix, true) as $item)
				if ($item->isFile())
					$remote[$item->path()] = [$item->fileSize(), $item->lastModified()];
		}
		catch (Throwable $e)
		{
			$this->error('Error listing remote files: ' . $e->getMessage());
			return self::FAILURE;
		}

		// -- Upload anything new, or whose size or modified time differs from the copy on S3
		$uploaded = $unchanged = $failed = $bytes = 0;
		$local = [];

		foreach ($files as $file)
		{
			$key = $prefix . '/' . str_replace('\\', '/', $file->getRelativePathname());
			$local[$key] = true;

			[$size, $modified] = $remote[$key] ?? [null, null];

			if ($size === $file->getSize() && $modified >= $file->getMTime())
			{
				$unchanged++;
				continue;
			}

			try
			{
				$stream = fopen($file->getPathname(), 'r');
				$disk->writeStream($key, $stream);
				fclose($stream);

				$uploaded++;
				$bytes += $file->getSize();
			}
			catch (Throwable $e)
			{
				$this->error('Failed to upload ' . $file->getRelativePathname() . ': ' . $e->getMessage());
				$failed++;
			}
		}

		// -- Optionally remove remote files that have gone locally
		$pruned = 0;

		if ($this->option('prune') && $stale = array_keys(array_diff_key($remote, $local)))
		{
			try
			{
				$disk->delete($stale);
				$pruned = count($stale);
			}
			catch (Throwable $e)
			{
				$this->error('Error pruning remote files: ' . $e->getMessage());
				$failed++;
			}
		}

		$summary = sprintf('Uploaded %d file%s (%s), %d unchanged', $uploaded, $uploaded === 1 ? '' : 's', $this->formatBytes($bytes), $unchanged);

		if ($pruned)
			$summary .= ', ' . $pruned . ' pruned';

		if ($failed)
		{
			$this->error($summary . ', ' . $failed . ' failed');
			return self::FAILURE;
		}

		$this->info($summary . ' in ' . $prefix . '/');
		return self::SUCCESS;
	}

	protected function formatBytes(float $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB'];
		$i = 0;

		while ($bytes >= 1024 && $i < 3)
		{
			$bytes /= 1024;
			$i++;
		}

		return round($bytes, 1) . ' ' . $units[$i];
	}
}
