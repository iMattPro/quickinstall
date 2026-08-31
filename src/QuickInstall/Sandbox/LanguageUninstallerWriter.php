<?php
/**
 *
 * QuickInstall sandbox language uninstaller writer
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license       GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace QuickInstall\Sandbox;

use RuntimeException;

/** Writes a phpBB-bootstrapped script that safely removes an installed language. */
class LanguageUninstallerWriter
{
	private Project $project;

	public function __construct(Project $project)
	{
		$this->project = $project;
	}

	public function write(string $board): string
	{
		$path = $this->project->runtimePath($board) . '/language-uninstall.php';
		$directory = dirname($path);
		if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))
		{
			throw new RuntimeException("Unable to create runtime directory: $directory");
		}

		if (file_put_contents($path, $this->script(), LOCK_EX) === false)
		{
			throw new RuntimeException("Unable to write language uninstaller: $path");
		}

		return $path;
	}

	private function script(): string
	{
		return <<<'PHP'
<?php
define('IN_PHPBB', true);
$phpbb_root_path = '/var/www/html/';
$phpEx = 'php';
require $phpbb_root_path . 'common.' . $phpEx;

$iso = $argv[1] ?? '';
if ($iso === '' || !preg_match('/^[a-z]{2,3}(?:_[a-z0-9]+)*$/', $iso))
{
	fwrite(STDERR, "Invalid language ISO.\n");
	exit(1);
}

$sql = 'SELECT * FROM ' . LANG_TABLE . " WHERE lang_iso = '" . $db->sql_escape($iso) . "'";
$result = $db->sql_query($sql);
$language = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if (!$language)
{
	exit(0);
}

if ($language['lang_iso'] === $config['default_lang'])
{
	fwrite(STDERR, "Cannot uninstall the default language.\n");
	exit(1);
}

$id = (int) $language['lang_id'];
$default = $db->sql_escape($config['default_lang']);
$db->sql_transaction('begin');
$db->sql_query('DELETE FROM ' . LANG_TABLE . ' WHERE lang_id = ' . $id);
$db->sql_query("UPDATE " . USERS_TABLE . " SET user_lang = '$default' WHERE user_lang = '" . $db->sql_escape($iso) . "'");
$db->sql_query('DELETE FROM ' . PROFILE_LANG_TABLE . ' WHERE lang_id = ' . $id);
$db->sql_query('DELETE FROM ' . PROFILE_FIELDS_LANG_TABLE . ' WHERE lang_id = ' . $id);
$db->sql_transaction('commit');
$cache->purge();
PHP;
	}
}
