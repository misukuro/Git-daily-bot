#!/usr/local/bin/php

<?php

/*
Copyright (c) 2011, Kentaro Kato
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

Redistributions of source code must retain the above copyright notice,
this list of conditions and the following disclaimer.
Redistributions in binary form must reproduce the above copyright notice,
this list of conditions and the following disclaimer in the documentation 
and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS 
AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, 
INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY 
AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. 
IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES 
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED 
AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, 
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, 
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/

require_once("Net/SmartIRC.php");
require_once("bot.php");
require_once("botini.php");

define('MODE_GIT', 'git');
define('MODE_SVN', 'svn');

//IRCとデプロイサーバのホストをセットする
if (!DEPLOY_HOST || !DEPLOY_PATH || !IRC_HOST || !IRC_CHANNEL || !GIT_WORKING_DIR || !IRC_PORT) usage();
$deployHost = DEPLOY_HOST;
$deployPath = DEPLOY_PATH;
$ircHost = IRC_HOST;
$channel = array("#". IRC_CHANNEL);
$home = GIT_WORKING_DIR;


if (!MODE)
{
	$mode = MODE_GIT;
} else {
	switch(MODE){
		case MODE_GIT:
			$mode = MODE_GIT;
			break;
		case MODE_SVN:
			$mode = MODE_SVN;
			break;
		default:
			$mode = MODE_GIT;
	}
}

$bot =  new Bot($deployHost, $deployPath, $mode, $home);
$irc = new Net_SmartIRC();

$irc->setUseSockets(TRUE);
$irc->setAutoRetry(TRUE);
$irc->setautoReconnect(TRUE);
$irc->setChannelSyncing(TRUE);

$irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^@', $bot, 'commandReceived');

$irc->connect($ircHost, IRC_PORT);
$irc->login("bot", "Git/svn IRC RELEASE BOT", 0, "bot", null);
$irc->join($channel);

$irc->listen();

function usage()
{
	die('usage: Please set your configuration file(botini.php)'."\n");
}
?>
