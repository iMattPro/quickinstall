<?php
/**
*
* @package quickinstall
* @copyright (c) 2010 phpBB Limited
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

class qi_populate
{
	// Populate settings
	private $create_mod = false;
	private $create_admin = false;
	private $num_users = 0;
	private $num_new_group = 0;
	private $num_cats = 0;
	private $num_forums = 0;
	private $num_topics_min = 0;
	private $num_topics_max = 0;
	private $num_replies_min = 0;
	private $num_replies_max = 0;
	private $email_domain = '';

	// We can't have all posts posted in the same second.
	private $post_time = 0;
	private $start_time = 0;

	// How many of each type to send to the db each run
	// Might be better to add some memory checking later.
	private $user_chunks = 0;
	private $post_chunks = 0;
	private $topic_chunks = 0;

	/**
	 * $user_arr = array(
	 *   (int) $user_id => array(
	 *     'user_ud' => (int) $user_id,
	 *     'user_lastpost_time' => (int) time(),
	 *     'user_lastmark' => (int) time(),
	 *     'user_lastvisit' => (int) time(),
	 *     'user_posts' => (int) $num_posts,
	 *   ),
	 * );
	 */
	private $user_arr = array();

	/**
	 * $forum_arr = array(
	 *     'forum_id' => (int) $forum_id,
	 *     'parent_id' => (int) $cat_id,
	 *     'forum_posts' => (int) $forum_posts_cnt,
	 *     'forum_topics' => (int) $forum_topics_cnt,
	 *     'forum_topics_real' => (int) $forum_topics_real_cnt,
	 *     'forum_last_post_id' => (int) $forum_last_post_id,
	 *     'forum_last_poster_id' => (int) $forum_last_poster_id,
	 *     'forum_last_post_subject' => (string) $forum_last_post_subject,
	 *     'forum_last_post_time' => (int) $forum_last_post_time,
	 *     'forum_last_poster_name' => (string) $forum_last_poster_name,
	 * 	 ),
	 * );
	 */
	private $forum_arr = array();

	// The default forums. To copy permissions from.
	private $def_cat_id = 0;
	private $def_forum_id = 0;

	public function run()
	{
		global $quickinstall_path, $phpbb_root_path, $phpEx, $settings;

		// Need to include some files.
		if (!function_exists('gen_sort_selects'))
		{
			include($phpbb_root_path . 'includes/functions_content.' . $phpEx);
		}

		if (!class_exists('acp_forums'))
		{
			include($phpbb_root_path . 'includes/acp/acp_forums.' . $phpEx);
		}

		if (!function_exists('recalc_nested_sets'))
		{
			include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
		}

		if (!class_exists('acp_permissions'))
		{
			include($phpbb_root_path . 'includes/acp/acp_permissions.' . $phpEx);
		}

		if (!function_exists('validate_range'))
		{
			include($quickinstall_path . 'includes/functions_forum_create.' . $phpEx);
		}

		// Get the chunk sizes. Make sure they are integers and set to something.
		$this->post_chunks	= $settings->get_config('chunk_post', 0);
		$this->topic_chunks	= $settings->get_config('chunk_topic', 0);
		$this->user_chunks	= $settings->get_config('chunk_user', 0);

		// Initiate these. $settings->get_config('', ); //
		$this->create_admin		= $settings->get_config('create_admin', false);
		$this->create_mod		= $settings->get_config('create_mod', false);
		$this->num_users		= $settings->get_config('num_users', 0);
		$this->num_new_group	= $settings->get_config('num_new_group', 0);
		$this->num_cats			= $settings->get_config('num_cats', 0);
		$this->num_forums		= $settings->get_config('num_forums', 0);
		$this->num_topics_min	= $settings->get_config('num_topics_min', 0);
		$this->num_topics_max	= $settings->get_config('num_topics_max', 0);
		$this->num_replies_min	= $settings->get_config('num_replies_min', 0);
		$this->num_replies_max	= $settings->get_config('num_replies_max', 0);

		if (!$this->num_users && !$this->num_forums)
		{
			// Nothing to do.
			return;
		}

		// There is already one forum and one category created. Let's get their data.
		$this->get_default_forums();

		// There is already one category and one forum created.
		$this->num_forums	= ($this->num_forums) ? $this->num_forums - 1 : 0;
		$this->num_cats		= ($this->num_cats) ? $this->num_cats - 1 : 0;

		// Don't try to fool us.
		$this->num_replies_max	= max($this->num_replies_max, $this->num_replies_min);
		$this->num_topics_max	= max($this->num_topics_max, $this->num_topics_min);

		// Estimate the number of posts created.
		// Or in reality, calculate the highest possible number and convert to seconds in the past.
		// If one of them is zero this would not be so nice.
		$replies	= max($this->num_replies_max, 1);
		$topics		= max($this->num_topics_max, 1);
		$forums		= max($this->num_forums, 1);

		// Calculate the board's start time/date
		$this->start_time = max(0, time() - ($topics * $replies * $forums) - $this->num_users - 60);

		if ($this->num_users)
		{
			// If we have users we also need an e-mail domain.
			$this->email_domain = trim($settings->get_config('email_domain', ''));

			if (empty($this->email_domain))
			{
				trigger_error(qi::lang('NEED_EMAIL_DOMAIN'), E_USER_ERROR);
			}

			// Make sure the email domain starts with a @ and is lower case.
			$this->email_domain = ((strpos($this->email_domain, '@') === false) ? '@' : '') . $this->email_domain;
			$this->email_domain = strtolower($this->email_domain);

			// Populate the users array with some initial data.
			// I'm sure there was a reason to split this up to two functions.
			// Need to have a closer look at that later. The second one might need to move.
			$this->pop_user_arr();
		}

		// We need to create categories and forums first.
		if ($this->num_forums)
		{
			// Don't create any categories if no forums are to be created.
			$this->create_forums();
		}

		// And now those pesky posts.
		if ($this->num_replies_max || $this->num_topics_max)
		{
			// Give posts a timestamp starting 1 second after the last user reg date
			$this->post_time = $this->start_time + $this->num_users + 1;

			$this->fill_forums();
		}

		// And the users...
		if ($this->num_users)
		{
			$this->save_users();

			if ($this->create_mod || $this->create_admin)
			{
				$this->create_management();
			}
		}

		// If we populated any users, topics or replies, update initial start date
		$this->update_start_date();
	}

	/**
	 * Make the first two users an admin and a global moderator.
	 */
	private function create_management()
	{
		global $phpbb_root_path, $phpEx, $settings;

		// Don't do anything if there is not enough users.
		$users_needed = 0;
		$users_needed = ($this->create_mod) ? $users_needed + 1 : $users_needed;
		$users_needed = ($this->create_admin) ? $users_needed + 1 : $users_needed;

		if (count($this->user_arr) < $users_needed)
		{
			return;
		}

		// Get group id for admins and moderators.
		$user_groups = $this->get_groups();
		$admin_group = (int) array_search('ADMINISTRATORS', $user_groups, true);
		$mod_group = (int) array_search('GLOBAL_MODERATORS', $user_groups, true);

		// Load language file array
		$lang = [];
		if (file_exists("{$phpbb_root_path}language/" . $settings->get_config('default_lang') . "/common.$phpEx"))
		{
			include("{$phpbb_root_path}language/" . $settings->get_config('default_lang') . "/common.$phpEx");
		}
		else if (file_exists("{$phpbb_root_path}language/en/common.$phpEx"))
		{
			include("{$phpbb_root_path}language/en/common.$phpEx");
		}
		else
		{
			$lang['G_ADMINISTRATORS'] = $lang['G_GLOBAL_MODERATORS'] = '';
		}

		if (!empty($admin_group) && $this->create_admin)
		{
			reset($this->user_arr);
			$user = current($this->user_arr);
			if (!empty($user['user_id']))
			{
				group_user_add($admin_group, $user['user_id'], false, $lang['G_ADMINISTRATORS'], true, 0);
			}
		}

		if (!empty($mod_group) && $this->create_mod)
		{
			next($this->user_arr);

			$user = current($this->user_arr);
			if (!empty($user['user_id']))
			{
				group_user_add($mod_group, $user['user_id'], false,  $lang['G_GLOBAL_MODERATORS'], true, 1);
			}
		}

		unset($lang); // Free memory
	}

	/**
	 * Create topics and posts in them.
	 */
	private function fill_forums()
	{
		global $db;

		// Statistics
		$topic_cnt = $post_cnt = 0;

		// There is at least one topic with one post already created.
		$sql = 'SELECT t.topic_id AS t_topic_id, p.post_id FROM ' . TOPICS_TABLE . ' t, ' . POSTS_TABLE . ' p
			ORDER BY t.topic_id DESC, p.post_id DESC';
		$result		= $db->sql_query_limit($sql, 1);
		$row		= $db->sql_fetchrow($result);
		$topic_id	= (int) $row['t_topic_id'];
		$post_id	= (int) $row['post_id'];
		$db->sql_freeresult($result);

		// Put topics and posts in their arrays, so they can be sent to the database when the limit is reached.
		$sql_topics = $sql_posts = array();

		// Use the default user if no new users are being populated
		if (!$this->num_users && empty($this->user_arr))
		{
			$this->user_arr = $users = $this->get_user(1);
		}
		// If there are going to be newly registered users, we need to not use them when filling forums
		else if ($this->num_new_group && $this->num_new_group < $this->num_users)
		{
			$users = array_slice($this->user_arr, 0, -$this->num_new_group, true);
		}
		else
		{
			$users = $this->user_arr;
		}

		// Get the min and max for mt_rand.
		end($users);
		$mt_max	= (int) key($users);
		reset($users);
		$mt_min	= (int) key($users);

		unset($users);

		// Flags for BBCodes.
		$flags = 7;
		foreach ($this->forum_arr as &$forum)
		{
			// How many topics in this forum?
			$topics = ($this->num_topics_min == $this->num_topics_max) ? $this->num_topics_max : mt_rand($this->num_topics_min, $this->num_topics_max);

			for ($i = 0; $i < $topics; $i++)
			{
				// Increase this here, so we get the number for the topic title.
				$topic_cnt++;

				$topic_arr = array(
					'topic_id'		=> ++$topic_id,
					'forum_id'		=> (int) $forum['forum_id'],
					'topic_title'	=> qi::lang('TEST_TOPIC_TITLE', $topic_cnt),
				);

				if (qi::phpbb_branch('3.1'))
				{
					$topic_arr['topic_posts_approved'] = 1;

					$forum['forum_topics_approved']++;
					$forum['forum_posts_approved']++;
				}
				else
				{
					$topic_arr['topic_replies'] = 0;
					$topic_arr['topic_replies_real'] = 0;

					$forum['forum_topics']++;
					$forum['forum_topics_real']++;
					$forum['forum_posts']++;
				}

				$replies = ($this->num_replies_min == $this->num_replies_max) ? $this->num_replies_max : mt_rand($this->num_replies_min, $this->num_replies_max);
				// The first topic post also needs to be posted.
				$replies++;

				// Generate the posts.
				for ($j = 0; $j < $replies; $j++)
				{
					$post_cnt++;

					$poster_id	= mt_rand($mt_min, $mt_max);
					$poster_arr	= $this->user_arr[$poster_id];
					$post_time	= $this->post_time++;
					$post_text	= qi::lang('TEST_POST_START', $post_cnt) . "\n" . qi::lang('LOREM_IPSUM');
					$subject	= (($j > 0) ? 'Re: ' : '') . $topic_arr['topic_title'];

					$bbcode_uid = $bbcode_bitfield = '';
					generate_text_for_storage($post_text, $bbcode_uid, $bbcode_bitfield, $flags, TRUE, TRUE, TRUE);

					$sql_posts[] = array(
						'post_id'			=> ++$post_id,
						'topic_id'			=> $topic_id,
						'forum_id'			=> $forum['forum_id'],
						'poster_id'			=> $poster_arr['user_id'],
						'post_time'			=> $post_time,
						'post_username'		=> $poster_arr['username'],
						'post_subject'		=> $subject,
						'post_text'			=> $post_text,
						'post_checksum'		=> md5($post_text),
						'bbcode_bitfield'	=> $bbcode_bitfield,
						'bbcode_uid'		=> qi::phpbb_branch('3.2') ? gen_rand_string() : $bbcode_uid,
					);

					if (qi::phpbb_branch('3.1'))
					{
						$sql_posts[count($sql_posts) - 1]['post_visibility']	= ITEM_APPROVED;
					}

					if ($j === 0)
					{
						// Put some first post info to the topic array.
						$topic_arr['topic_first_post_id']	= $post_id;
						$topic_arr['topic_first_poster_name']	= $poster_arr['username'];
						$topic_arr['topic_time']		= $post_time;
						$topic_arr['topic_poster']		= $poster_arr['user_id'];

						if (qi::phpbb_branch('3.1'))
						{
							$topic_arr['topic_visibility']	= ITEM_APPROVED;
						}
					}
					else
					{
						if (qi::phpbb_branch('3.1'))
						{
							$topic_arr['topic_posts_approved']++;
							$forum['forum_posts_approved']++;
						}
						else
						{
							$topic_arr['topic_replies']++;
							$topic_arr['topic_replies_real']++;
							$forum['forum_posts']++;
						}
					}

					$forum['forum_last_post_id']		= $post_id;
					$forum['forum_last_poster_id']		= $poster_arr['user_id'];
					$forum['forum_last_post_subject']	= $subject;
					$forum['forum_last_post_time']		= $post_time;
					$forum['forum_last_poster_name']	= $poster_arr['username'];

					$this->user_arr[$poster_arr['user_id']]['user_posts']++;
					$this->user_arr[$poster_arr['user_id']]['user_lastpost_time'] = $post_time;
					$this->user_arr[$poster_arr['user_id']]['user_lastmark'] = $post_time;
					$this->user_arr[$poster_arr['user_id']]['user_lastvisit'] = $post_time;

					if (count($sql_posts) >= $this->post_chunks)
					{
						// Save the array to the posts table
						$db->sql_multi_insert(POSTS_TABLE, $sql_posts);
						unset($sql_posts);
						$sql_posts = array();
					}
				}

				$topic_arr['topic_last_post_id']		= $post_id;
				$topic_arr['topic_last_poster_id']		= $poster_arr['user_id'];
				$topic_arr['topic_last_poster_name']	= $poster_arr['username'];
				$topic_arr['topic_last_post_subject']	= $subject;
				$topic_arr['topic_last_post_time']		= $post_time;

				$sql_topics[] = $topic_arr;

				if (count($sql_topics) >= $this->topic_chunks)
				{
					// Save the array to the topics table
					$db->sql_multi_insert(TOPICS_TABLE, $sql_topics);
					unset($sql_topics);
					$sql_topics = array();
				}
			}

			$sql_ary = array(
				'forum_posts' . (qi::phpbb_branch('3.1') ? '_approved' : '' )	=> $forum['forum_posts' . (qi::phpbb_branch('3.1') ? '_approved' : '' )],
				'forum_topics' . (qi::phpbb_branch('3.1') ? '_approved' : '' )	=> $forum['forum_topics' . (qi::phpbb_branch('3.1') ? '_approved' : '' )],
				'forum_last_post_id'		=> $forum['forum_last_post_id'],
				'forum_last_poster_id'		=> $forum['forum_last_poster_id'],
				'forum_last_post_subject'	=> $forum['forum_last_post_subject'],
				'forum_last_post_time'		=> $forum['forum_last_post_time'],
				'forum_last_poster_name'	=> $forum['forum_last_poster_name'],
				'forum_last_poster_colour'	=> '',
			);

			if (!qi::phpbb_branch('3.1'))
			{
				$sql_ary['forum_topics_real'] = $forum['forum_topics_real'];
			}

			$sql = 'UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $sql_ary);
			$sql .= ' WHERE forum_id = ' . (int) $forum['forum_id'];
			$db->sql_query($sql);
		}

		unset($forum);

		if (count($sql_posts))
		{
			// Save the array to the posts table
			$db->sql_multi_insert(POSTS_TABLE, $sql_posts);
			unset($sql_posts);
		}

		if (count($sql_topics))
		{
			// Save the array to the topics table
			$db->sql_multi_insert(TOPICS_TABLE, $sql_topics);
			unset($sql_topics);
			$sql_topics = array();
		}

		// phpBB installs the forum with one topic and one post.
		qi_set_config('num_topics', $topic_cnt + 1);
		qi_set_config('num_posts', $post_cnt + 1);

		$this->update_sequence(TOPICS_TABLE . '_seq', $topic_cnt + 1);
		$this->update_sequence(POSTS_TABLE . '_seq', $post_cnt + 1);
	}

	/**
	 * Updates value of a sequence for postgresql.
	 */
	private function update_sequence($sequence_name, $value)
	{
		global $db, $settings;

		if ($settings->get_config('dbms') === 'postgres')
		{
			$result = $db->sql_query("select setval('$sequence_name', '$value')");
			$db->sql_freeresult($result);
		}
	}

	/**
	 * Create our forums and populate the forums array.
	 * I think we can use phpBB default functions for this.
	 * Hope nobody is trying to
	 */
	private function create_forums()
	{
		$acp_forums = new acp_forums();

		$parent_arr = array();

		// The first category to
		$parent_arr[] = $this->def_cat_id;

		for ($i = 0; $i < $this->num_cats; $i++)
		{
			// Create categories and fill an array with parent ids.
			$parent_arr[] = $this->_create_forums(FORUM_CAT, $i + 1, $acp_forums);
		}

		$parent_size = count($parent_arr);

		// If we have more than one cat, let's start with the second.
		$parent_cnt = ($parent_size > 1) ? 1 : 0;
		for ($i = 0; $i < $this->num_forums; $i++)
		{
			$this->_create_forums(FORUM_POST, $i + 1, $acp_forums, $parent_arr[$parent_cnt++]);

			if ($parent_cnt >= $parent_size)
			{
				$parent_cnt = 0;
			}
		}
	}

	/**
	 * The function actually creating the forums
	 */
	private function _create_forums($forum_type, $cnt, $acp_forums, $parent_id = 0)
	{
		global $auth;

		$forum_name = ($forum_type == FORUM_CAT) ? qi::lang('TEST_CAT_NAME', $cnt) : qi::lang('TEST_FORUM_NAME', $cnt);
		$forum_desc = ($forum_type == FORUM_CAT) ? qi::lang('TEST_FORUM_NAME', $cnt) : '';

		// Setting up the data to be used
		$forum_data = array(
			'parent_id'					=> $parent_id,
			'forum_type'				=> $forum_type,
			'forum_status'				=> ITEM_UNLOCKED,
			'forum_parents'				=> '',
			'forum_options'				=> 0,
			'forum_name'				=> utf8_normalize_nfc($forum_name),
			'forum_link'				=> '',
			'forum_link_track'			=> false,
			'forum_desc'				=> utf8_normalize_nfc($forum_desc),
			'forum_desc_uid'			=> '',
			'forum_desc_options'		=> 7,
			'forum_desc_bitfield'		=> '',
			'forum_rules'				=> '',
			'forum_rules_uid'			=> '',
			'forum_rules_options'		=> 7,
			'forum_rules_bitfield'		=> '',
			'forum_rules_link'			=> '',
			'forum_image'				=> '',
			'forum_style'				=> 0,
			'forum_password'			=> '',
			'forum_password_confirm'	=> '',
			'display_subforum_list'		=> true,
			'display_on_index'			=> true,
			'forum_topics_per_page'		=> 0,
			'enable_indexing'			=> true,
			'enable_icons'				=> false,
			'enable_prune'				=> false,
			'enable_post_review'		=> true,
			'enable_quick_reply'		=> false,
			'prune_days'				=> 7,
			'prune_viewed'				=> 7,
			'prune_freq'				=> 1,
			'prune_old_polls'			=> false,
			'prune_announce'			=> false,
			'prune_sticky'				=> false,
			'forum_password_unset'		=> false,
			'show_active'				=> 1,
		);

		// The description should not need this, but who knows what people will come up with.
		generate_text_for_storage($forum_data['forum_desc'], $forum_data['forum_desc_uid'], $forum_data['forum_desc_bitfield'], $forum_data['forum_desc_options'], false, false, false);

		// Create that thing.
		$errors = $acp_forums->update_forum_data($forum_data);

		if (count($errors))
		{
			trigger_error(implode('<br />', $errors));
		}

		// Copy the permissions from our default forums
		copy_forum_permissions($this->def_forum_id, $forum_data['forum_id']);
		$auth->acl_clear_prefetch();

		if ($forum_type == FORUM_POST)
		{
			// A normal forum. There is no link type forums installed with phpBB.
			$this->forum_arr[$forum_data['forum_id']] = array(
				'forum_id'					=> $forum_data['forum_id'],
				'parent_id'					=> $forum_data['parent_id'],
				'forum_topics_real'			=> 0,
				'forum_last_post_id'		=> 0,
				'forum_last_poster_id'		=> 0,
				'forum_last_post_subject'	=> '',
				'forum_last_post_time'		=> 0,
				'forum_last_poster_name'	=> '',
			);

			if (qi::phpbb_branch('3.1'))
			{
				$this->forum_arr[$forum_data['forum_id']]['forum_posts_approved']	= 0;
				$this->forum_arr[$forum_data['forum_id']]['forum_topics_approved']	= 0;
			}
			else
			{
				$this->forum_arr[$forum_data['forum_id']]['forum_posts']	= 0;
				$this->forum_arr[$forum_data['forum_id']]['forum_topics']	= 0;
			}
		}

		return $forum_data['forum_id'];
	}

	/**
	 * Creates users and puts them in the right groups.
	 * Also populates the users array.
	 */
	private function save_users()
	{
		global $db, $db_tools, $config, $settings;

		// Hash the password.
		if (qi::phpbb_branch('3.1'))
		{
			global $passwords_manager;
			$password = $passwords_manager->hash('123456');
		}
		else
		{
			$password = phpbb_hash('123456');
		}

		// Get the group id for registered users and newly registered.
		$user_groups = $this->get_groups();
		$registered_group = (int) array_search('REGISTERED', $user_groups, true);
		$newly_registered_group = (int) array_search('NEWLY_REGISTERED', $user_groups, true);

		$s_chunks = $this->num_users > $this->user_chunks;
		$chunk_cnt = 0;
		$sql_ary = array();

		if (!qi::phpbb_branch('3.1'))
		{
			$tz		= new DateTimeZone($settings->get_config('qi_tz', ''));
			$tz_ary	= $tz->getTransitions(time());
			$offset	= (float) $tz_ary[0]['offset'] / 3600;	// 3600 seconds = 1 hour.
			$qi_dst	= ($tz_ary[0]['isdst']) ? 1 : 0;
			unset($tz_ary, $tz);
		}

		foreach ($this->user_arr as $user)
		{
			$email = $user['username_clean'] . $this->email_domain;
			$sql_ary[] = array_merge([
				'user_id'				=> $user['user_id'],
				'username'				=> $user['username'],
				'username_clean'		=> $user['username_clean'],
				'user_lastpost_time'	=> $user['user_lastpost_time'],
				'user_lastmark'			=> $user['user_lastmark'],
				'user_lastvisit'		=> $user['user_lastvisit'],
				'user_posts'			=> $user['user_posts'],
				'user_password'			=> $password,
				'user_email'			=> $email,
				'group_id'				=> $registered_group,
				'user_type'				=> USER_NORMAL,
				'user_permissions'		=> '',
				'user_lang'				=> $settings->get_config('qi_lang'),
				'user_form_salt'		=> unique_id(),
				'user_style'			=> (int) $config['default_style'],
				'user_regdate'			=> $user['user_regdate'],
				'user_passchg'			=> $user['user_passchg'],
				'user_options'			=> 230271,
				'user_full_folder'		=> PRIVMSGS_NO_BOX,
				'user_dateformat'		=> 'M jS, ’y, H:i',
				'user_sig'				=> '',
			], !qi::phpbb_branch('4.0') ?
				['user_notify_type' => defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : 0] :
				[]
			);

			$count = count($sql_ary) - 1;
			if (qi::phpbb_branch('3.1'))
			{
				$sql_ary[$count]['user_timezone'] = $settings->get_config('qi_tz', '');
			}
			else
			{
				$sql_ary[$count]['user_timezone'] = $offset;
				$sql_ary[$count]['user_pass_convert'] = 0;
				$sql_ary[$count]['user_occ'] = '';
				$sql_ary[$count]['user_interests'] = '';
				$sql_ary[$count]['user_dst'] = $qi_dst;
			}

			if (!qi::phpbb_branch('3.3') || $db_tools->sql_column_exists(USERS_TABLE, 'user_email_hash'))
			{
				$sql_ary[$count]['user_email_hash'] = phpbb_email_hash($email);
			}

			$chunk_cnt++;
			if ($s_chunks && $chunk_cnt >= $this->user_chunks)
			{
				// throw the array to the users table
				$db->sql_multi_insert(USERS_TABLE, $sql_ary);
				unset($sql_ary);
				$sql_ary = array();
				$chunk_cnt = 0;
			}
		}
		// If there are any remaining users we need to throw them in to.
		if (!empty($sql_ary))
		{
			$db->sql_multi_insert(USERS_TABLE, $sql_ary);
		}
		unset($sql_ary);

		// Put them in groups.
		$chunk_cnt = $skip = 0;

		// Don't add the first users to the newly registered group if a moderator and/or an admin is needed.
		$skip = ($this->create_mod) ? $skip + 1 : $skip;
		$skip = ($this->create_admin) ? $skip + 1 : $skip;

		// First the registered group.
		foreach ($this->user_arr as $user)
		{
			$sql_ary[] = array(
				'user_id'		=> (int) $user['user_id'],
				'group_id'		=> $this->num_new_group && $user['user_posts'] < 1 && $skip < 1 ? $newly_registered_group : $registered_group,
				'group_leader'	=> 0, // No group leaders.
				'user_pending'	=> 0, // User is not pending.
			);

			$skip--;

			$chunk_cnt++;
			if ($s_chunks && $chunk_cnt >= $this->user_chunks)
			{
				// throw the array to the users table
				$db->sql_multi_insert(USER_GROUP_TABLE, $sql_ary);
				unset($sql_ary);
				$sql_ary = array();
				$chunk_cnt = 0;
			}
		}
		// If there are any remaining users we need to throw them in too.
		if (!empty($sql_ary))
		{
			$db->sql_multi_insert(USER_GROUP_TABLE, $sql_ary);
		}

		// Get the last user
		$user = end($this->user_arr);
		qi_set_config('newest_user_id', $user['user_id']);
		qi_set_config('newest_username', $user['username']);
		qi_set_config('newest_user_colour', '');

		// phpBB installs the forum with one user.
		qi_set_config('num_users', $this->num_users + 1);
	}

	/**
	 * Populates the category array and the forums array with the default forums.
	 * Needed for posts and topics.
	 */
	private function get_default_forums()
	{
		global $db;

		// We are the only ones messing with this database so far.
		// So the latest user_id + 1 should be the user id for the first test user.
		$sql = 'SELECT forum_id, parent_id, forum_type, forum_posts' . (qi::phpbb_branch('3.1') ? '_approved' : '' ) . ', forum_topics' . (qi::phpbb_branch('3.1') ? '_approved' : ', forum_topics_real' ) . ', forum_last_post_id, forum_last_poster_id, forum_last_post_subject, forum_last_post_time, forum_last_poster_name FROM ' . FORUMS_TABLE;
		$result = $db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			if ($row['forum_type'] == FORUM_CAT)
			{
				$this->def_cat_id = (int) $row['forum_id'];
			}
			else
			{
				// A normal forum. There is no link type forums installed with phpBB.
				$this->forum_arr[$row['forum_id']] = array(
					'forum_id'					=> $row['forum_id'],
					'parent_id'					=> $row['parent_id'],
					'forum_posts' . (qi::phpbb_branch('3.1') ? '_approved' : '' )	=> $row['forum_posts' . (qi::phpbb_branch('3.1') ? '_approved' : '' )],
					'forum_topics' . (qi::phpbb_branch('3.1') ? '_approved' : '' )	=> $row['forum_topics' . (qi::phpbb_branch('3.1') ? '_approved' : '' )],
					'forum_last_post_id'		=> $row['forum_last_post_id'],
					'forum_last_poster_id'		=> $row['forum_last_poster_id'],
					'forum_last_post_subject'	=> $row['forum_last_post_subject'],
					'forum_last_post_time'		=> $row['forum_last_post_time'],
					'forum_last_poster_name'	=> $row['forum_last_poster_name'],
				);

				if (!qi::phpbb_branch('3.1'))
				{
					$this->forum_arr[$row['forum_id']]['forum_topics_real'] = $row['forum_topics_real'];
				}

				$this->def_forum_id = (int) $row['forum_id'];
			}
		}
	}

	/**
	 * Populates the users array and fills with some default data.
	 * Needed for posts and topics.
	 */
	private function pop_user_arr()
	{
		global $db;

		// We are the only ones messing with this database so far.
		// So the last user_id + 1 should be the user id for the first user.
		$sql = 'SELECT user_id FROM ' . USERS_TABLE . '
			ORDER BY user_id DESC';
		$result = $db->sql_query_limit($sql, 1);
		$first_user_id = (int) $db->sql_fetchfield('user_id') + 1;
		$db->sql_freeresult($result);
		$last_user_id = $first_user_id + $this->num_users - 1;

		$reg_time = $this->start_time + 1;
		$cnt = 1;
		for ($i = $first_user_id; $i <= $last_user_id; $i++)
		{
			$this->user_arr[$i] = array(
				'user_id'			=> $i,
				'username'			=> 'tester_' . $cnt,
				'username_clean'	=> 'tester_' . $cnt,
				'user_lastpost_time'=> 0,
				'user_lastmark'		=> 0,
				'user_lastvisit'	=> 0,
				'user_posts'		=> 0,
				'user_regdate'		=> $reg_time,
				'user_passchg'		=> $reg_time,
			);

			$reg_time++;
			$cnt++;
		}
	}

	/**
	 * Get a user's basic info
	 *
	 * @param $id int User id
	 *
	 * @return array Row of user data
	 */
	private function get_user($id)
	{
		global $db;
		$user_row = array();
		$sql = 'SELECT user_id, username, user_posts, user_lastpost_time, user_lastmark, user_lastvisit 
			FROM ' . USERS_TABLE . '
			WHERE user_id = ' . (int) $id;
		$result = $db->sql_query_limit($sql, 1);
		while ($row = $db->sql_fetchrow($result))
		{
			$user_row[$row['user_id']] = $row;
		}
		$db->sql_freeresult($result);
		return $user_row;
	}

	/**
	 * Update the start date set during phpBB's installation
	 * to a time just before the forum was populated with stuff.
	 */
	private function update_start_date()
	{
		global $config, $db;

		if (!$this->num_replies_max && !$this->num_topics_max && !$this->num_users)
		{
			return;
		}

		$original_startdate = $config['board_startdate'];

		// update board's start date
		qi_set_config('board_startdate', $this->start_time);

		$db->sql_transaction('begin');

		// update the default first forum and category
		$sql = 'UPDATE ' . FORUMS_TABLE . '
			SET forum_last_post_time = ' . (int) $this->start_time . '
			WHERE forum_last_post_time = ' . (int) $original_startdate . '
				AND ' . $db->sql_in_set('forum_id', array(1, 2));
		$db->sql_query($sql);

		// update the default (welcome) first post
		$sql = 'UPDATE ' . POSTS_TABLE . '
			SET post_time = ' . (int) $this->start_time . '
			WHERE post_id = 1';
		$db->sql_query($sql);

		// update the default first topic
		$sql = 'UPDATE ' . TOPICS_TABLE . '
			SET topic_time =  ' . (int) $this->start_time . ', topic_last_post_time =  ' . (int) $this->start_time . '
			WHERE topic_id = 1';
		$db->sql_query($sql);

		// update the default admin and anonymous user
		$sql = 'UPDATE ' . USERS_TABLE . '
			SET user_regdate =  ' . (int) $this->start_time . '
			WHERE ' . $db->sql_in_set('user_id', array(1, 2));
		$db->sql_query($sql);

		$db->sql_transaction('commit');
	}

	/**
	 * Get an array of the user groups
	 * @return array group_id => group_name
	 */
	private function get_groups()
	{
		global $db;

		$groups = [];

		$sql = 'SELECT group_id, group_name FROM ' . GROUPS_TABLE;
		$result = $db->sql_query($sql);
		while ($row = $db->sql_fetchrow($result))
		{
			$groups[$row['group_id']] = $row['group_name'];
		}
		$db->sql_freeresult($result);

		return $groups;
	}
}
