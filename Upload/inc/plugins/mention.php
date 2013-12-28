<?php
/*
 * Plugin Name: MentionMe for MyBB 1.6.x
 * Copyright 2013 WildcardSearch
 * http://www.wildcardsworld.com
 *
 * this is the main plugin file
 */

// disallow direct access to this file for security reasons.
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

// checked by other plugin files
define("IN_MENTIONME", true);

// add hooks
mentionme_initialize();

/*
 * mention_run()
 *
 * use a regex to either match a double-quoted mention (@"user name") or just grab the @ symbol and everything after it that is qualifies as a word and is within name length
 *
 * @param - $message is the contents of the post
 */
$plugins->add_hook("parse_message", "mention_run");
function mention_run($message)
{
	global $mybb;

	// use function mention_filter_callback to repeatedly process mentions in the current post
	return preg_replace_callback('/@[\'|"|`]([^<]+?)[\'|"|`]|@([\w .]{' . (int) $mybb->settings['minnamelength'] . ',' . (int) $mybb->settings['maxnamelength'] . '})/', "mention_filter_callback", $message);
}

/*
 * mention_filter_callback()
 *
 * matches any mentions of existing user in the post
 *
 * advanced search routines rely on $mybb->settings['mention_advanced_matching'], if set to true mention will match user names with spaces in them without necessitating the use of double quotes.
 *
 * @param - $match is an array generated by preg_replace_callback()
 */
function mention_filter_callback($match)
{
	global $db, $mybb, $cache;
	static $name_cache;
	$name_parts = array();
	$shift_count = 0;

	$cache_changed = false;

	// cache names to reduce queries
	if(!isset($name_cache) || empty($name_cache))
	{
		$wildcard_plugins = $cache->read('wildcard_plugins');
		$name_cache = $wildcard_plugins['mentionme']['namecache'];
	}

	// if the user entered the mention in quotes then it will be returned in $match[1],
	// if not it will be returned in $match[2]
	array_shift($match);
	while(strlen(trim($match[0])) == 0 && !empty($match))
	{
		array_shift($match);
		++$shift_count;
	}

	// save the original name
	$orig_name = $match[0];
	$match[0] = trim(strtolower($match[0]));

	// if the name is already in the cache . . .
	if(isset($name_cache[$match[0]]))
	{
		$left_over = substr($orig_name, strlen($match[0]));
		return mention_build($name_cache[$match[0]]) . $left_over;
	}

	// if the array was shifted then no quotes were used
	if($shift_count)
	{
		// no padding necessary
		$shift_pad = 0;

		// split the string into an array of words
		$name_parts = explode(' ', $match[0]);

		// add the first part
		$username_lower = $name_parts[0];

		// if the name part we have is shorter than the minimum user name length (set in ACP) we need to loop through all the name parts and keep adding them until we at least reach the minimum length
		while(strlen($username_lower) < $mybb->settings['minnamelength'] && !empty($name_parts))
		{
			// discard the first part (we have it stored)
			array_shift($name_parts);
			if(strlen($name_parts[0]) == 0)
			{
				// no more parts?
				break;
			}

			// if there is another part add it
			$username_lower .= ' ' . $name_parts[0];
		}

		if(strlen($username_lower) < $mybb->settings['minnamelength'])
		{
			return $orig_name;
		}
	}
	else
	{
		// @ and two double quotes
		$shift_pad = 3;

		// grab the entire match
		$username_lower = $match[0];
	}

	// if the name is already in the cache . . .
	if(isset($name_cache[$username_lower]))
	{
		// . . . simply return it and save the query
		//  restore any surrounding characters from the original match
		return mention_build($name_cache[$username_lower]) . substr($orig_name, strlen($username_lower) + $shift_pad);
	}
	else
	{
		// lookup the user name
		$user = mention_try_name($username_lower);

		// if the user name exists . . .
		if($user['uid'] != 0)
		{
			$cache_changed = true;

			// preserve any surrounding chars
			$left_over = substr($orig_name, strlen($user['username']) + $shift_pad);
		}
		else
		{
			// if no match and advanced matching is enabled . . .
			if($mybb->settings['mention_advanced_matching'])
			{
				// we've already checked the first part, discard it
				array_shift($name_parts);

				// if there are more parts and quotes weren't used
				if(!empty($name_parts) && $shift_pad != 3 && strlen($name_parts[0]) > 0)
				{
					// start with the first part . . .
					$try_this = $username_lower;

					$all_good = false;

					// . . . loop through each part and try them in serial
					foreach($name_parts as $val)
					{
						// add the next part
						$try_this .= ' ' . $val;

						// check the cache for a match to save a query
						if(isset($name_cache[$try_this]))
						{
							// preserve any surrounding chars from the original match
							$left_over = substr($orig_name, strlen($try_this) + $shift_pad);
							return mention_build($name_cache[$try_this]) . $left_over;
						}

						// check the db
						$user = mention_try_name($try_this);

						// if there is a match . . .
						if((int) $user['uid'] > 0)
						{
							// cache the user name HTML
							$username_lower = strtolower($user['username']);

							// preserve any surrounding chars from the original match
							$left_over = substr($orig_name, strlen($user['username']) + $shift_pad);

							// and gtfo
							$all_good = true;
							$cache_changed = true;
							break;
						}
					}

					if(!$all_good)
					{
						// still no matches?
						return "@{$orig_name}";
					}
				}
				else
				{
					// nothing else to try
					return "@{$orig_name}";
				}
			}
			else
			{
				// no match found and advanced matching is disabled
				return "@{$orig_name}";
			}
		}

		// store the mention
		$name_cache[$username_lower] = $user;

		// if we had to query for this user's info then update the cache
		if($cache_changed)
		{
			$wildcard_plugins = $cache->read('wildcard_plugins');
			$wildcard_plugins['mentionme']['namecache'] = $name_cache;
			$cache->update('wildcard_plugins', $wildcard_plugins);
		}

		// and return the mention
		return mention_build($user) . $left_over;
	}
}

/*
 * mention_build()
 *
 * build  mention from user info
 *
 * @param - $user - (array)
 * 	an associative array of user info (as normally contained in $mybb->user)
 */
function mention_build($user)
{
	if(!is_array($user) || empty($user) || strlen($user['username']) == 0)
	{
		return false;
	}

	// set up the user name link so that it displays correctly for the display group of the user
	$username = format_name(htmlspecialchars_uni($user['username']), $user['usergroup'], $user['displaygroup']);
	$url = get_profile_link($user['uid']);

	// the HTML id property is used to store the uid of the mentioned user for MyAlerts (if installed)
	return <<<EOF
@<a id="mention_{$user['uid']}" href="{$url}">{$username}</a>
EOF;
}

/*
 * mention_try_name()
 *
 * searches the db for a user by name
 *
 * return an array containing user id, user name, user group and display group upon success
 * return false on failure
 *
 * @param - $username is a string containing the user name to try
 */
function mention_try_name($username = '')
{
	// create another name cache here to save queries if names with spaces are used more than once in the same post.
	static $name_list;

	if(!is_array($name_list))
	{
		$name_list = array();
	}

	$username = strtolower($username);

	if($username)
	{
		// if the name is in this cache (has been searched for before)
		if($name_list[$username])
		{
			// . . . just return the data and save the query
			return $name_list[$username];
		}

		global $db;

		// query the db
		$user_query = $db->simple_select("users", "uid, username, usergroup, displaygroup", "LOWER(username)='" . $db->escape_string($username) . "'", array('limit' => 1));

		// result?
		if($db->num_rows($user_query) === 1)
		{
			// cache the name
			$name_list[$username] = $db->fetch_array($user_query);

			// and return it
			return $name_list[$username];
		}
		else
		{
			// no matches
			return false;
		}
	}
	// no user name supplied
	return false;
}

/*
 * mention_mycode_add_codebuttons()
 *
 * add our code button's hover text language and insert our script (we don't
 * have to check settings because the hook will not be added if the setting for
 * adding a code button is set to no
 *
 * @param - $edit_lang - (array) an unindexed array of language variable names for the editor
 */
function mention_mycode_add_codebuttons($edit_lang)
{
	global $lang;

	if(!$lang->mention)
	{
		$lang->load('mention');
	}
	$lang->mentionme_codebutton = <<<EOF
<script type="text/javascript" src="jscripts/mention_codebutton.js"></script>

EOF;

	$edit_lang[] = 'editor_mention';
	return $edit_lang;
}

/*
 * mention_misc_start()
 *
 * currently only here to display the mention popup for the code button
 */
function mention_misc_start()
{
	global $mybb;

	if($mybb->input['action'] != 'mentionme')
	{
		// not our time
		return;
	}

	if($mybb->input['mode'] == 'popup')
	{
		// if we have any input
		if(trim($mybb->input['username']))
		{
			// just insert it with the 'safe' syntax, close the window and get out
			die
			(
<<<EOF
<script type="text/javascript">
<!--
	opener.clickableEditor.performInsert('@"{$mybb->input['username']}"');
	window.close();
// -->
</script>
EOF
			);
		}

		// show the popup
		global $templates, $lang, $headerinclude;
		if(!$lang->mention)
		{
			$lang->load('mention');
		}
		eval("\$page = \"" . $templates->get('mentionme_popup') . "\";");
		output_page($page);
		exit;
	}
}

/*
 * mentionme_initialize()
 *
 * add hooks and include functions only when appropriate
 */
function mentionme_initialize()
{
	global $mybb, $plugins;

	// load install routines and force enable script only if in ACP
	if(defined("IN_ADMINCP"))
	{
		switch($mybb->input['module'])
		{
			case 'config-plugins':
				require_once MYBB_ROOT . "inc/plugins/MentionMe/mention_install.php";
				$plugins->add_hook("admin_load", "mention_admin_load");
				break;
		}
		return;
	}

	// load the alerts functions only if MyAlerts and mention alerts are enabled
	if($mybb->settings['myalerts_enabled'] && $mybb->settings['myalerts_alert_mention'])
	{
		require_once MYBB_ROOT . 'inc/plugins/MentionMe/mention_alerts.php';
	}

	// only add the code button if the setting is on and we are viewing a page that use an editor
	if($mybb->settings['mention_add_codebutton'] && in_array(THIS_SCRIPT, array('newthread.php', 'newreply.php', 'editpost.php', 'private.php', 'usercp.php', 'modcp.php', 'calendar.php')))
	{
		switch(THIS_SCRIPT)
		{
			case 'usercp.php':
				switch($mybb->input['action'])
				{
					case 'editsig':
						break 2;
					default: return;
				}
			case 'private.php':
				switch($mybb->input['action'])
				{
					case 'send':
						break 2;
					default: return;
				}
			case 'modcp.php':
				switch($mybb->input['action'])
				{
					case 'edit_announcement':
					case 'new_announcement':
					case 'editprofile':
						break 2;
					default: return;
				}
			case 'calendar.php':
				switch($mybb->input['action'])
				{
					case 'addevent':
					case 'editevent':
						break 2;
					default: return;
				}
		}
		$plugins->add_hook("mycode_add_codebuttons", "mention_mycode_add_codebuttons");
	}
	// only add the misc hook if we are viewing the popup (or posting)
	elseif(THIS_SCRIPT == 'misc.php' && $mybb->input['action'] == 'mentionme')
	{
		$plugins->add_hook("misc_start", "mention_misc_start");
	}
	// only add the showthread hook if we are there and we are adding a postbit multi-mention button
	elseif(THIS_SCRIPT == 'showthread.php' && $mybb->settings['mention_add_postbit_button'])
	{
		$plugins->add_hook("showthread_start", "mention_showthread_start");
		$plugins->add_hook("postbit", "mention_postbit");
	}
	// only add the xmlhttp hook if required and we are adding a postbit multi-mention button
	elseif(THIS_SCRIPT == 'xmlhttp.php' && $mybb->settings['mention_add_postbit_button'])
	{
		$plugins->add_hook("xmlhttp", "mention_xmlhttp");
	}
}

/*
 * mention_postbit()
 *
 * build the multi-mention postbit button
 *
 * @param - $post - (array) passed from pluginSystem::run_hooks, an array of the post data
 */
function mention_postbit(&$post)
{
	global $theme, $lang;

	if(!$lang->mention)
	{
		$lang->load('mention');
	}

	// the multi-mention button
	$post['button_multi_mention'] = <<<EOF
<a href="javascript:MultiMention.multiMention({$post['pid']});" style="display: none;" id="multi_mention_link_{$post['pid']}"><img src="{$theme['imglangdir']}/postbit_multi_mention.gif" alt="{$lang->mention_title}" title="{$lang->mention_title}" id="multi_mention_{$post['pid']}" /></a>
<script type="text/javascript">
//<!--
	$('multi_mention_link_{$post['pid']}').style.display = '';
// -->
</script>
EOF;
}

/*
 * mention_xmlhttp()
 *
 * handles AJAX for MentionMe, currently only the multi-mention functionality
 */
function mention_xmlhttp()
{
	global $mybb, $db;

	if($mybb->input['action'] != 'get_multi_mentioned')
	{
		return;
	}

	// If the cookie does not exist, exit
	if(!array_key_exists("multi_mention", $mybb->cookies))
	{
		exit;
	}
	// Divide up the cookie using our delimiter
	$multi_mentioned = explode("|", $mybb->cookies['multi_mention']);

	// No values - exit
	if(!is_array($multi_mentioned))
	{
		exit;
	}

	// Loop through each post ID and sanitize it before querying
	foreach($multi_mentioned as $post)
	{
		$mentioned_posts[$post] = intval($post);
	}

	// Join the post IDs back together
	$mentioned_posts = implode(",", $mentioned_posts);

	// Fetch unviewable forums
	$unviewable_forums = get_unviewable_forums();
	if($unviewable_forums)
	{
		$unviewable_forums = "AND t.fid NOT IN ({$unviewable_forums})";
	}
	$message = '';

	// Are we loading all mentioned posts or only those not in the current thread?
	if(!$mybb->input['load_all'])
	{
		$from_tid = "p.tid != '".intval($mybb->input['tid'])."' AND ";
	}
	else
	{
		$from_tid = '';
	}

	// Query for any posts in the list which are not within the specified thread
	$mentioned = array();
	$query = $db->simple_select('posts', 'username', "{$from_tid}pid IN ({$mentioned_posts}) {$unviewable_forums}", array("order_by" => 'dateline'));
	while($mentioned_post = $db->fetch_array($query))
	{
		if(!is_moderator($mentioned_post['fid']) && $mentioned_post['visible'] == 0)
		{
			continue;
		}

		if($mentioned[$mentioned_post['username']] != true)
		{
			$message .= <<<EOF
@"{$mentioned_post['username']}" 
EOF;
			$mentioned[$mentioned_post['username']] = true;
		}
	}

	// Send our headers.
	header("Content-type: text/plain; charset={$charset}");
	echo $message;
	exit;
}

/*
 * mention_showthread_start()
 *
 * add the script, the Quick Reply notification <div> and the hidden input
 */
function mention_showthread_start()
{
	global $mention_script, $mention_quickreply, $mentioned_ids, $lang, $tid;

	if(!$lang->mention)
	{
		$lang->load('mention');
	}

	$mention_script = <<<EOF
<script type="text/javascript" src="jscripts/mention_thread.js"></script>

EOF;

	$mention_quickreply = <<<EOF
					<div class="editor_control_bar" style="width: 95%; padding: 4px; margin-top: 3px; display: none;" id="quickreply_multi_mention">
						<span class="smalltext">
							{$lang->mention_posts_selected} <a href="./newreply.php?tid={$tid}&amp;load_all_mentions=1" onclick="return MultiMention.loadMultiMentioned();">{$lang->mention_users_now}</a> {$lang->or} <a href="javascript:MultiMention.clearMultiMentioned();">{$lang->quickreply_multiquote_deselect}</a>.
						</span>
					</div>
EOF;

	$mentioned_ids = <<<EOF

	<input type="hidden" name="mentioned_ids" value="" id="mentioned_ids" />
EOF;
}

?>
