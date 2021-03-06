<?php
/*
 * Plugin Name: MentionMe for MyBB 1.6.x
 * Copyright 2013 WildcardSearch
 * http://www.wildcardsworld.com
 *
 * this script is a task used to build a cache of user mention HTML to conserve queries during normal forum operation
 */

 /*
  * task_mentiome_namecache()
  *
  * @param - $task an integer represented the MyBB task id
  */
function task_mentiome_namecache($task)
{
	global $db, $cache, $mybb, $lang;

	if(!$lang->mention)
	{
		$lang->load('mention');
	}

	// if the user has not set a valid amount of cache_days
	$cache_days = (int) $mybb->settings['mention_cache_time'];
	if((int) $cache_days <= 0)
	{
		// default to one week
		$cache_days = 7;
	}

	// find all users that have been active within the specified amount of days
	$timesearch = TIME_NOW - (60 * 60 * 24 * $cache_days);
	$query = $db->simple_select('users', 'uid, username, usergroup, displaygroup', "lastvisit > {$timesearch}");

	if($db->num_rows($query) > 0)
	{
		$user_data = array();

		// if there are any results then build an array of data used for @mentions
		while($user = $db->fetch_array($query))
		{
			$user_data[strtolower($user['username'])] = $user;
		}

		// store the resulting array in our cache
		$wildcard_plugins = $cache->read('wildcard_plugins');
		$wildcard_plugins['mentionme']['namecache'] = $user_data;
		$cache->update('wildcard_plugins', $wildcard_plugins);

		// get some stats
		$user_count = count($user_data);
		$total_cache_size = get_friendly_size(strlen(serialize($user_data)));

		$report = $lang->sprintf($lang->mention_task_success, $cache_days, $user_count, $total_cache_size);
	}
	else
	{
		$report = $lang->mention_task_fail;
	}

	// add an entry to the log
	add_task_log($task, $report);
}

?>
