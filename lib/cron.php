#!/usr/bin/env php
<?php
$base = dirname(__FILE__);

class OC {
	public static $SERVERROOT = null;
};

include_once("$base/slackapi.php");
include_once("$base/exclusivelock.php");
include_once("$base/../../../config/config.php");

$db = new mysqli($CONFIG['dbhost'], $CONFIG['dbuser'], $CONFIG['dbpassword'],
		 $CONFIG['dbname']);

if ($db->connect_error) {
	die('Connect Error (' . $db->connect_errno . ') ' .
	    $db->connect_error);
}

function clearNotifications($user)
{
	global $db, $CONFIG;

	$user = $db->real_escape_string($user);

	$db->query("DELETE FROM " . $CONFIG['dbtableprefix'] .
		"preferences " . "WHERE appid='slacknotify' AND " .
		"configkey='notifications' AND userid='" . $user . "'");
}

function getAppValue($key, $default)
{
	global $db, $CONFIG;

	$key = $db->real_escape_string($key);

	$res = $db->query("SELECT * FROM " . $CONFIG['dbtableprefix'] .
		"appconfig " . "WHERE appid='slacknotify' AND configkey='" .
		$key . "'");

	if ($res === false or $res->num_rows != 1)
		return $default;

	$row = $res->fetch_array();

	return empty($row['configvalue']) ? $default : $row['configvalue'];
}

function getUserValue($user, $key)
{
	global $db, $CONFIG;

	$key = $db->real_escape_string($key);
	$user = $db->real_escape_string($user);

	$res = $db->query("SELECT * FROM " . $CONFIG['dbtableprefix'] .
		"preferences " . "WHERE appid='slacknotify' AND configkey='" .
		$key . "' AND userid='" . $user . "'");

	if ($res === false or $res->num_rows != 1)
		return null;

	$row = $res->fetch_array();

	return $row['configvalue'];
}

function slackSend($xoxp, $user, $msgs)
{
	$channel = getUserValue($user, 'channel');
	if (empty($xoxp) or empty($channel))
		return;

	$attachments = array();

	foreach ($msgs as $person) {
		foreach ($person as $target) {
			// Replace <foo|bar> with bar
			$pattern = '/<[^|]+\|([^>]+)>/i';
			$fallback = preg_replace($pattern, '$1', $target);

			// Remove occurences of *
			$pattern = '/(\*)/i';
			$fallback = preg_replace($pattern, '', $fallback);

			$attachments[] = array(
				'text' => $target,
				'color' => '#232323',
				'fallback' => $fallback,
				"mrkdwn_in" => array("text"),
			);
		}
	}

	$attachments = json_encode($attachments);

	$bot_name = getAppValue('slackBotName', 'Cyphre');
	$icon_url = getAppValue('slackIconUrl',
		'https://files.cyphre.com/themes/svy/core/img/favicon.png');

	$Slack = new \OCA\SlackNotify\SlackAPI($xoxp);
	$Slack->call('chat.postMessage', array(
		'icon_url' => $icon_url,
		'channel' => $channel,
		'username' => $bot_name,
		'parse' => 'none',
		'attachments' => $attachments,
	));
}

function getNotifications($user)
{
	$lock = new \OCA\SlackNotify\ExclusiveLock("/tmp/slacknotify");
	if (!$lock->lock())
                return array();

	$notif = getUserValue($user, 'notifications');
	clearNotifications($user);

	$lock->unlock();

	return $notif ? unserialize($notif) : array();
}


$res = $db->query("SELECT * FROM " . $CONFIG['dbtableprefix'] .
	"preferences " . "WHERE appid='slacknotify' AND configkey='xoxp'");

if ($res === false)
	exit();

while ($row = $res->fetch_array()) {
	$user = $row['userid'];
	$xoxp = $row['configvalue'];

	$notif = getNotifications($user);

	if (empty($notif))
		continue;

	$msgs = array();

	foreach ($notif as $vals) {
		$person = $vals['person'];
		$object = $vals['object'];
		$action = $vals['action'];

		// First time seeing this person
		if (empty($msgs[$person])) {
			$msgs[$person] = array(
				$action => "$person $action: $object",
			);
			continue;
		}

		// First time seeing this action for this person
		if (empty($msgs[$person][$action])) {
			$msgs[$person][$action] = "$person $action: $object";
			continue;
		}

		// We've seen this person and action, so just append object
		$msgs[$person][$action] .= ", $object";
	}

	slackSend($xoxp, $user, $msgs);
}
