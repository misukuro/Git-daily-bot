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
require_once("manager.php");

/*
	IRCとチャットを送受信するクラス
	managerクラスにコマンドの処理を中継する
	help表示
	IRCへのチャット送信はこのクラスのsendとrawsend関数が呼ばれるので、
	この関数だけを別のIMに対応させれば良いはず
*/
class Bot
{
	//IRC
	private $irc;
	private $data;

	/* コマンド */
	const op_release_open = "release-open";
	const op_release_close = "release-close";
	const op_ok = "ok";
	const op_ng = "ng";
	const op_sync = "sync";
	//const op_merge = "merge";
	const op_list = "list";
	const op_list_author = "list-author";
	const op_help = "help";
	const op_change_master = "change-master";
	const op_user = "user";
	const op_hotfix_open = "hotfix-open";
	const op_hotfix_close = "hotfix-close";
	const op_state = "state";

	//コマンドの説明(ヘルプで使う)
	private	$op_list = array(
		/*
		self::op_user           => array(
			'desc'  => 'show or register user (@user or @user svn [user])',
		),
		*/
		self::op_release_open   => array(
			'desc'  => 'open release process',
		),
		self::op_release_close  => array(
			'desc'  => 'close release process ',
		),
		self::op_hotfix_open   => array(
			'desc'  => 'open hotfix process',
		),
		self::op_hotfix_close  => array(
			'desc'  => 'close hotfix process ',
		),
		/*
		self::op_release_master => array(
			'desc'  => 'show release masters',
		),
		*/
		/*
		self::op_change_master  => array(
			'desc'  => 'change release master (very dangerous?)',
		),
		*/
		/*
		self::op_merge          => array(
			'desc'  => 'merge (and deploy to stg servers) specified revision (@merge [revision(s)])',
		),
		*/
		self::op_list           => array(
			'desc'  => 'show current test list (available only after @release-open, and only for release master)',
		),
		self::op_list_author    => array(
			'desc'  => 'show current test list grouped by author',
		),
		self::op_ok             => array(
			'desc'  => 'tell specified revision(s) as ok (@ok HashID or @ok user or @ok [number])',
		),
		self::op_ng             => array(
			'desc'  => 'tell specified revision(s) as ng (@ng HashID or @ok user or @ok [number])',
		),
		self::op_help           => array(
			'desc'  => 'show this message',
		),
		self::op_user			=> array(
			'desc'  => 'associate your nickname on IRC and Git/svn',
		),
		self::op_state			=> array(
			'desc'  => 'show current release state',
		),
	);

	//コマンドマネージャ
	private $manager;

	public function __construct($host, $path, $mode, $home)
	{
		$this->manager = new Manager($host, $path, $mode, $home);
	}

	//@で始まるチャットを受信したときの処理
	public function commandReceived (&$irc, &$data)
	{
		$this->irc = $irc;
		$this->data = $data;

		//チャットメッセージ本文以降を見る
		$first_command = mb_substr($data->rawmessageex[3], 2);
		if (isset($data->rawmessageex[4]))
		{
			$second_command = $data->rawmessageex[4];
		}

		switch ($first_command)
		{
			case self::op_release_open:
				$this->manager->opReleaseOpen($this, $data);
				break;
			case self::op_release_close:
				$this->manager->opReleaseClose($this, $data);
				break;
			case self::op_hotfix_open:
				$this->manager->opHotfixOpen($this, $data);
				break;
			case self::op_hotfix_close:
				$this->manager->opHotfixClose($this, $data);
				break;
			case self::op_ok:
				$this->manager->opOk($this, $data);
				break;
			case self::op_ng:
				$this->manager->opNg($this, $data);
				break;
			case self::op_sync:
				$this->manager->opSync($this, $data);
				break;
			/*case self::op_merge:
				$this->manager->opMerge($this, $data);
				break;*/
			case self::op_list:
				$this->manager->opList($this, $data);
				break;
			case self::op_list_author:
				$this->manager->opListAuthor($this, $data);
				break;
			case self::op_help:
				$this->showHelp($irc, $data);
				break;
			/*case self::op_change_master:
				$this->manager->opChangeMaster($this, $data);
				break;
				*/
			case self::op_user:
				$this->manager->opUser($this, $data);
				break;
			case self::op_state:
				$this->manager->opState($this, $data);
				break;
			default:
				$this->unKnownCommands($irc, $data);
		}
	}

	private function unKnownCommands (&$irc, &$data)
	{
		$irc->message(SMARTIRC_TYPE_NOTICE, $data->channel, "unknown command. see '@help' > ".$data->nick);
	}

	//IRCにメッセージを送信します
	public function send($message)
	{
		$this->irc->message(SMARTIRC_TYPE_NOTICE, $this->data->channel, $message);
	}

	//IRCに同期でメッセージを送信します(短時間で数回呼び出すとBANされる)
	public function rawsend($message)
	{
		$this->irc->message(SMARTIRC_TYPE_NOTICE, $this->data->channel, $message, SMARTIRC_CRITICAL);
	}

	//change IRC channel topic
	public function changeTopic($message){
		$this->irc->setTopic($this->data->channel, $message);
	}

	//ヘルプメッセージを発信します
	private function showHelp(&$irc, &$data)
	{
		$output = array();
		foreach ($this->op_list as $op => $array)
		{
			$help = sprintf("%s : %s\n", $op, $array['desc']);
			array_push($output, $help);
		}
		$irc->message(SMARTIRC_TYPE_NOTICE, $data->channel, $output);
	}
}
