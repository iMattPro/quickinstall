<?php
/**
*
* @package quickinstall
* @copyright (c) 2007 phpBB Limited
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
 * Useful class for directory and file actions
 */
class qi_file
{
	public static $error = array();

	public static function make_file($file, $data)
	{
		$success = file_put_contents($file, $data);

		return (bool) $success;
	}

	public static function delete_file($file)
	{
		chmod($file, 0755);

		$success = @unlink($file);

		if (!$success)
		{
			self::$error[] = $file;
		}

		return $success;
	}

	public static function copy_file($src_file, $dst_file)
	{
		return copy($src_file, $dst_file);
	}

	public static function move_file($src_file, $dst_file)
	{
		self::copy_file($src_file, $dst_file);
		self::delete_file($src_file);
	}

	public static function copy_dir($src_dir, $dst_dir)
	{
		self::append_slash($src_dir);
		self::append_slash($dst_dir);

		if (!is_dir($dst_dir) && !mkdir($dst_dir) && !is_dir($dst_dir))
		{
			throw new RuntimeException('COPY_DIR_ERROR');
		}

		foreach (scandir($src_dir) as $file)
		{
			if (in_array($file, array('.', '..'), true))
			{
				continue;
			}

			$src_file = $src_dir . $file;
			$dst_file = $dst_dir . $file;

			if (is_file($src_file))
			{
				if (is_file($dst_file))
				{
					$ow = filemtime($src_file) - filemtime($dst_file);
				}
				else
				{
					$ow = 1;
				}

				if (($ow > 0) && copy($src_file, $dst_file))
				{
					touch($dst_file, filemtime($src_file));
				}
			}
			else if (is_dir($src_file))
			{
				self::copy_dir($src_file, $dst_file);
			}
		}
	}

	public static function delete_dir($dir, $empty = false)
	{
		self::append_slash($dir);

		if (!file_exists($dir) || !is_dir($dir) || !is_readable($dir))
		{
			return;
		}

		foreach (scandir($dir) as $file)
		{
			if (in_array($file, array('.', '..'), true))
			{
				continue;
			}

			if (is_dir($dir . $file))
			{
				self::delete_dir($dir . $file);
			}
			else
			{
				self::delete_file($dir . $file);
			}
		}

		if (!$empty)
		{
			@rmdir($dir);
		}
	}

	public static function delete_files($dir, $files_ary, $recursive = true)
	{
		self::append_slash($dir);

		foreach (scandir($dir) as $file)
		{
			if (in_array($file, array('.', '..'), true))
			{
				continue;
			}

			if ($recursive && is_dir($dir . $file))
			{
				self::delete_files($dir . $file, $files_ary, true);
			}

			if (in_array($file, $files_ary, true))
			{
				if (is_dir($dir . $file))
				{
					self::delete_dir($dir . $file);
				}
				else
				{
					self::delete_file($dir . $file);
				}
			}
		}
	}

	public static function append_slash(&$dir)
	{
		if ($dir[strlen($dir) - 1] !== '/')
		{
			$dir .= '/';
		}
	}

	/**
	 * Recursive make all files and directories world writable.
	 *
	 * @param string $dir
	 * @param bool   $root
	 */
	public static function make_writable($dir, $root = true)
	{
		self::grant_permissions($dir, 0666, $root);
	}

	public static function grant_permissions($dir, $add_perms, $root = true)
	{
		$old_perms = fileperms($dir);
		$new_perms = $old_perms | $add_perms;
		if ($new_perms != $old_perms)
		{
			chmod($dir, $new_perms);
		}

		if (is_dir($dir))
		{
			$file_arr = scandir($dir);
			$dir .= '/';

			foreach ($file_arr as $file)
			{
				if ($file === '.' || $file === '..')
				{
					continue;
				}

				//if ($root && $file == 'config.' . $phpEx)
				//{
				//	chmod($dir . $file, 0666);
				//	continue;
				//}

				$file = $dir . $file;
				self::grant_permissions($file, $add_perms, false);
			}
		}
	}
}
