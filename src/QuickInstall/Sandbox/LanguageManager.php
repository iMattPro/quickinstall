<?php
/**
 *
 * QuickInstall CLI
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace QuickInstall\Sandbox;

use InvalidArgumentException;
use RuntimeException;

/** Discovers, copies, binds, lists, and removes phpBB language packs. */
class LanguageManager implements CustomisationManagerInterface
{
	private Project $project;

	public function __construct(Project $project)
	{
		$this->project = $project;
	}

	/** Mounts one 3.x or 4.x language pack using its validated ISO code. */
	public function mount(string $board, string $source, bool $copy = false, bool $allowExternal = false): array
	{
		$boardConfig = $this->project->board($board);
		$sourcePath = $this->resolvePath($source, $allowExternal);
		if (!is_dir($sourcePath))
		{
			throw new InvalidArgumentException("Language source is not a directory: $source");
		}

		$name = $this->languageName($sourcePath);
		$target = $this->project->boardPath($board) . '/language/' . $name;
		$languages = $boardConfig['languages'] ?? [];

		if (file_exists($target) || is_link($target))
		{
			if (!$copy && isset($languages[$name]) && ($languages[$name]['mode'] ?? '') === 'bind')
			{
				$languages[$name] = ['mode' => 'bind', 'source' => $sourcePath];
				$boardConfig['languages'] = $languages;
				$this->project->appendBoard($boardConfig);

				return ['name' => $name, 'source' => $sourcePath, 'target' => '/var/www/html/language/' . $name, 'mode' => 'bind'];
			}

			if (!$copy && (is_link($target) || !$this->isLanguagePath($target)))
			{
				$this->project->deleteTree($target);
			}
			else
			{
				throw new RuntimeException("Language target already exists: $target");
			}
		}

		if ($copy)
		{
			$this->project->copyTree($sourcePath, $target);
			$mode = 'copy';
			$languages[$name] = ['mode' => 'copy', 'source' => $target];
		}
		else
		{
			$mode = 'bind';
			$languages[$name] = ['mode' => 'bind', 'source' => $sourcePath];
			$target = '/var/www/html/language/' . $name;
		}

		$boardConfig['languages'] = $languages;
		$this->project->appendBoard($boardConfig);

		return ['name' => $name, 'source' => $sourcePath, 'target' => $target, 'mode' => $mode];
	}

	public function discover(string $source, bool $allowExternal = false): array
	{
		$sourcePath = $this->resolvePath($source, $allowExternal);
		if (!is_dir($sourcePath))
		{
			throw new InvalidArgumentException("Language search path is not a directory: $source");
		}

		$found = [];
		$this->discoverLanguages($sourcePath, $found);
		sort($found);

		return $found;
	}

	/** Removes copied files or registry state for a bind mount. */
	public function unmount(string $board, string $name): string
	{
		$boardConfig = $this->project->board($board);
		$this->assertLanguageName($name);
		$target = $this->project->boardPath($board) . '/language/' . $name;
		$languages = $boardConfig['languages'] ?? [];
		$isBind = isset($languages[$name]) && ($languages[$name]['mode'] ?? '') === 'bind';

		if (!isset($languages[$name]) && !file_exists($target) && !is_link($target))
		{
			throw new InvalidArgumentException("Language is not mounted: $name");
		}

		if (!$isBind)
		{
			$this->project->deleteTree($target);
		}

		unset($languages[$name]);
		$boardConfig['languages'] = $languages;
		$this->project->appendBoard($boardConfig);

		return $isBind ? '/var/www/html/language/' . $name : $target;
	}

	public function cleanupStaleTarget(string $board, string $name): void
	{
		$this->assertLanguageName($name);
		$this->project->deleteTree($this->project->boardPath($board) . '/language/' . $name);
	}

	public function list(string $board): array
	{
		$boardConfig = $this->project->board($board);
		$mounted = [];
		foreach (($boardConfig['languages'] ?? []) as $name => $language)
		{
			$mounted[] = [
				'name' => $name,
				'mode' => $language['mode'] ?? 'bind',
				'target' => '/var/www/html/language/' . $name,
				'source' => $language['source'] ?? '',
			];
		}

		usort($mounted, static function (array $left, array $right): int {
			return strcmp((string) $left['name'], (string) $right['name']);
		});

		return $mounted;
	}

	private function resolvePath(string $path, bool $allowExternal): string
	{
		return $this->project->resolveDropZonePath(
			$path,
			$this->project->customisationsPath(),
			$allowExternal,
			"Language path must be under customisations/. Use --allow-external only for trusted local paths."
		);
	}

	private function languageName(string $sourcePath): string
	{
		if (is_file($sourcePath . '/iso.txt'))
		{
			$name = basename($sourcePath);
			$this->assertLanguageName($name);

			return $name;
		}

		$composer = $sourcePath . '/composer.json';
		if (!is_file($composer))
		{
			throw new InvalidArgumentException("Language source must contain iso.txt or composer.json: $sourcePath");
		}

		$data = json_decode((string) file_get_contents($composer), true);
		$name = $data['extra']['language-iso'] ?? null;
		if (!is_string($name) || $name === '')
		{
			throw new InvalidArgumentException("Language composer.json must contain extra.language-iso: $composer");
		}

		$this->assertLanguageName($name);

		return $name;
	}

	private function isLanguagePath(string $path): bool
	{
		try
		{
			$this->languageName($path);
			return true;
		}
		catch (InvalidArgumentException $e)
		{
			return false;
		}
	}

	private function assertLanguageName(string $name): void
	{
		if (!preg_match('/^[a-z]{2,3}(?:_[a-z0-9]+)*$/', $name))
		{
			throw new InvalidArgumentException("Invalid language ISO: $name");
		}
	}

	private function discoverLanguages(string $path, array &$found): void
	{
		if ($this->isLanguagePath($path))
		{
			$found[] = realpath($path) ?: $path;
			return;
		}

		foreach (scandir($path) ?: [] as $item)
		{
			if ($item === '.' || $item === '..')
			{
				continue;
			}

			$child = $path . '/' . $item;
			if (is_dir($child) && !is_link($child))
			{
				$this->discoverLanguages($child, $found);
			}
		}
	}
}
