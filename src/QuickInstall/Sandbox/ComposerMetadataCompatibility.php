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

use RuntimeException;

/** Normalizes Composer metadata required by legacy phpBB dependencies. */
class ComposerMetadataCompatibility
{
	/**
	 * Restores the flat installed.json format expected by package-versions 1.x.
	 *
	 * Composer 2 uses installed.php itself, so retaining the legacy JSON shape is
	 * safe until Composer next regenerates the vendor metadata.
	 */
	public static function normalizePackageVersions(string $root): bool
	{
		$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
		$versionsPath = $root . 'vendor/ocramius/package-versions/src/PackageVersions/Versions.php';
		$installedPath = $root . 'vendor/composer/installed.json';
		if (!is_file($versionsPath) || !is_file($installedPath))
		{
			return false;
		}

		$versions = file_get_contents($versionsPath);
		if (!is_string($versions) || !preg_match('/const\s+VERSIONS\s*=\s*\[\s*\]\s*;/', $versions))
		{
			return false;
		}

		$installed = file_get_contents($installedPath);
		$data = json_decode((string) $installed, true);
		if (!is_array($data))
		{
			throw new RuntimeException("Invalid legacy Composer metadata: $installedPath");
		}
		if (!isset($data['packages']))
		{
			if (is_string($installed) && substr(ltrim($installed), 0, 1) === '[')
			{
				// Composer 1 metadata already uses the format expected by the fallback.
				return false;
			}

			throw new RuntimeException("Unsupported Composer metadata format: $installedPath");
		}
		if (!is_array($data['packages']))
		{
			throw new RuntimeException("Invalid Composer package metadata: $installedPath");
		}

		$json = json_encode($data['packages'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false)
		{
			throw new RuntimeException("Unable to encode legacy Composer metadata: $installedPath");
		}

		$contents = $json . "\n";
		if (file_put_contents($installedPath, $contents, LOCK_EX) !== strlen($contents))
		{
			throw new RuntimeException("Unable to normalize legacy Composer metadata: $installedPath");
		}

		return true;
	}
}
