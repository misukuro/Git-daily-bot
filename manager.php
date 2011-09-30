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

require "git.php";

/*
	ユーザ管理やGit/svnクラスへ中継、
	リリースオープン中かどうかの管理を行うクラス
*/
class Manager
{
	private $isReleaseOpened = false;
	private $isHotfixOpened = false;
	private $proxy;
	private $deployPath;
	//IRCとGitのユーザ対応リスト
	private $userNameList = array();

	const USER_NAME_FILE = 'user';
	const MAX_BUFFER_SIZE = 8192;
	const MODE_GIT = "git";
	const MODE_SVN = "svn";
	const STATE_OPEN = "open";
	const STATE_CLOSE = "close";
	const STATE_RELEASE = 1;
	const STATE_HOTFIX = 2;

	private $releaseStatus = array(
		false => 'closed',
		true => 'opend'
	);

	public function __construct($host, $path, $mode, $home)
	{
		//ユーザ関連づけ情報を読み取る
		$this->readUserName();
		//リリースオープンプロセス中かどうか調べる
		if ($mode === self::MODE_GIT)
		{
			$this->proxy = new Git($host, $path, $home);
		} elseif  ($mode === self::MODE_SVN) {
			//svnクラス
			//$this->proxy = new Svn($host);
		}
		$this->isReleaseOpened = $this->proxy->checkReleaseOpen();
		$this->isHotfixOpened = $this->proxy->checkHotfixOpen();
		//if ($this->isReleaseOpened) echo "リリースオープン中です";
		//if ($this->isHotfixOpened) echo "Hotfixオープン中です";
		$this->deployPath = $path;
	}

	/*
	　リリースオープンを行う
	*/
	public function opReleaseOpen(&$irc, &$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		if ($this->isReleaseOpened){
			$this->debugMessage('i am already in the release process'."\n");
			return;
		}
		$irc->rawsend('starting release open...');

		$this->isReleaseOpened = $this->proxy->opReleaseOpen($irc, $data);
		//リリースオープンに成功したらデプロイとテストリストの表示
		if ($this->isReleaseOpened)
		{
			$this->proxy->deployToStaging();
			$this->opList($irc, $data);
		}
		return $this->isReleaseOpened;

	}

	/*
	　Hotfixオープンを行う
	*/
	public function opHotfixOpen(&$irc, &$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		if ($this->isHotfixOpened)
		{
			$this->debugMessage('i am already in the hotfix process'."\n");
			return;
		}
		$irc->rawsend('starting hotfix open...');

		$this->isHotfixOpened = $this->proxy->opHotfixOpen($irc, $data);
		//リリースオープンに成功したらデプロイとテストリストの表示
		if ($this->isHotfixOpened)
		{
			$this->proxy->deployToStaging();
			$this->opList($irc, $data);
		}
		return $this->isHotfixOpened;

	}
	//デバッグメッセージを表示/保存します
	//実は同じ関数がproxyクラスにもある
	private function debugMessage($s)
	{
		$this->irc->send($s);
	}

	/*
	  テスト完了通知を受信
	*/
	public function opOk(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;

		if (!$this->isReleaseOpened && !$this->isHotfixOpened)
		{
			$this->debugMessage('i am not in release process'."\n");
			return;
		}
		/*
		if (!isset($data->messageex[1]))
		{
			$output = array();
			array_push($output, 'usage:');
			array_push($output, '@ok hashid1 [hashid2 hashid3 ...]');
			array_push($output, '@ok [1] [[2] [3] ...]');
			array_push($output, '@ok username1 [username2 username3]');
			$this->debugMessage($output);
			return;
		}
		*/
		$this->proxy->opOk($irc, $data);
	}

	/*
	  テストNG通知を受信
	*/
	public function opNg(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		if (!$this->isReleaseOpened && !$this->isHotfixOpened)
		{
			$this->debugMessage('i am not in release process'."\n");
			return;
		}
		/*
		if (!isset($data->messageex[1]))
		{
			$output = array();
			array_push($output, 'usage:');
			array_push($output, '@ng hashid1 [hashid2 hashid3 ...]');
			array_push($output, '@ng [1] [[2] [3] ...]');
			array_push($output, '@ng username1 [username2 username3]');
			$tihs->debugMessage($output);
			return;
		}
		*/
		$this->proxy->opNg($irc, $data);
	}

	/*
	  deployサーバ上でgit daily sync (pull)する
	  stagingへ出す
	  gitクラスのopSyncメソッドは手動close検出時に、1以上の値を返す
	  open検出時は、updateStatusが呼ばれる
	*/
	public function opSync(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		$output = array();

		/*if (!$this->isReleaseOpened && !$this->isHotfixOpened)
		{
			$this->debugMessage("i am not in release process");
			return;
		}
		*/

		array_push($output, 'starting sync...');
		$irc->rawsend($output);

		$syncRelease = $this->proxy->opSync($irc, $data, $this);
		if ($syncRelease > 0)
		{
			switch ($syncRelease)
			{
				case self::STATE_RELEASE:
					$this->isReleaseOpened = false;
					$this->irc->changeTopic('release closed');
					$this->debugMessage('release closed');
					break;
				case self::STATE_HOTFIX:
					$this->isHotfixOpened = false;
					$this->irc->changeTopic('hotfix closed');
					$this->debugMessage('hotfix closed');
					break;
				default:
					echo "規定外な動作をしました：manager.php176行目\n";
					break;
			}
		} else {
			$this->debugMessage('completed sync');
		}
	}

	//手動リリースオープンされたらフラグを更新する
	public function updateStatus($release, $hotfix)
	{
		if ($release > 0) 
		{
			$this->isReleaseOpened = true;
			$this->irc->changeTopic('release opened');
			$this->debugMessage('release opend');
		}
		if ($hotfix > 0)
		{
			$this->isHotfixOpened = true;
			$this->irc->changeTopic('hotfix opened');
			$this->debugMessage('hotfix opend');
		}
	}

	/*
	  テストリストを表示
	*/
	public function opList(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		$output = array();

		if (!$this->isReleaseOpened && !$this->isHotfixOpened)
		{
			$this->debugMessage("i am not in release process");
			return;
		}
		array_push($output, 'getting list...');
		$irc->rawsend($output);

		$this->proxy->opList($irc, $data);
	}

	/*
	  テストリストを開発者単位で表示
	*/
	public function opListAuthor(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		$output = array();

		if (!$this->isReleaseOpened && !$this->isHotfixOpened)
		{
			$this->debugMessage("i am not in release process");
			return;
		}
		array_push($output, 'getting list...');
		$irc->rawsend($output);

		$this->proxy->opListAuthor($irc, $data);
	}

	/*
	  リリースクローズを行う
	*/
	public function opReleaseClose(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		//ユーザの名前対応を保存する
		$this->saveUserName();
		$output = array();

		if (!$this->isReleaseOpened)
		{
			$this->debugMessage("i am not in release process");
			return;
		}
		array_push($output, 'completing release process...');
		$irc->rawsend($output);
		$this->isReleaseOpened = $this->proxy->opReleaseClose($irc, $data);

	}

	/*
	  ホットフィックスクローズを行う
	*/
	public function opHotfixClose(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		//ユーザの名前対応を保存する
		$this->saveUserName();
		$output = array();

		if (!$this->isHotfixOpened)
		{
			$this->debugMessage("i am not in hotfix process");
			return;
		}
		array_push($output, 'completing hotfix process...');
		$irc->rawsend($output);
		$this->isHotfixOpened = $this->proxy->opHotfixClose($irc, $data);

	}
	/*
	  IRCのnicknameとユーザを結びつける
	*/
	public function opUser(&$irc, &$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		if  (!isset($data->messageex[1]) || isset($data->messageex[2]))
		{
			$this->debugMessage('usage: @user git-user');
			return;
		}
		if  ($this->addUser($data->nick, $data->messageex[1]))
		{
			$this->debugMessage(sprintf('set %s is %s', $data->nick, $data->messageex[1]));
		} else {
			foreach ($this->userNameList as $irc => $git)
			{
				if ($git === $data->messageex[1]){
					$registedUser = $irc;
					break;
				}
			}
			$this->debugMessage(sprintf('%s has already been registered by %s', $data->messageex[1], $registedUser));
		}
	}

	/*
	  process state 
	*/
	public function opState(&$irc, &$data)
	{
		$this->irc = $irc;
		$this->data = $data;

		//手動で何かやられていないか確認する
		$irc->rawsend('checking status...');
		$isReleaseOpenedNow = $this->proxy->checkReleaseOpen();
		$isHotfixOpenedNow = $this->proxy->checkHotfixOpen();
		if ($isHotfixOpenedNow != $this->isHotfixOpened)
		{
			$this->isHotfixOpened = $isHotfixOpenedNow;
			$irc->changeTopic('hotfix '. $this->releaseStatus[$this->isHotfixOpened]);
			//手動でオープンされていたら自動で同期する
			if ($this->isHotfixOpened)
			{
				$irc->rawsend('Because I detected that release process was opened manually, start the synchronization.');
				$this->opSync($irc, $data);
			}
		} elseif ($isReleaseOpenedNow != $this->isReleaseOpened){
			$this->isReleaseOpened = $isReleaseOpenedNow;
			$irc->changeTopic('release '. $this->releaseStatus[$this->isReleaseOpened]);
			//手動でオープンされていたら自動で同期する
			if ($this->isReleaseOpened)
			{
				$irc->rawsend('Because I detected that release process was opened manually, start the synchronization.');
				$this->opSync($irc, $data);
			}
		}

		$releaseState = sprintf("release process is %s", $this->isReleaseOpened ? self::STATE_OPEN : self::STATE_CLOSE);
		$hotfixState = $this->isHotfixOpened ? sprintf("Hotfix process is Open") : "";
		$output = array($releaseState, $hotfixState);
		$this->debugMessage($output);
	}

	//ユーザの対応付けをファイルに保存する
	private function saveUserName()
	{
		$deploy = $this->deployPath;
		$currentDirectory = dirname(__FILE__);
		$filepath = $currentDirectory .'/' .  self::USER_NAME_FILE . '_' . str_replace('/', '_', $deploy);
		$fp = fopen($filepath, "w");
		flock ($fp, LOCK_EX);
		while (list($irc, $git) = each($this->userNameList))
		{
			if (fwrite($fp, sprintf("%s = %s\n", $irc, $git)) == -1)
			{
				$this->debugMessage("user name file writing failed : $filepath");
				return;
			}
		}
		fclose($fp);
	}


	//ユーザの対応付けをファイルに読み込む
	private function readUserName()
	{
		$deploy = $this->deployPath;
		$currentDirectory = dirname(__FILE__);
		$filepath = $currentDirectory .'/' .  self::USER_NAME_FILE . '_' . str_replace('/', '_', $deploy);
		if (!file_exists($filepath)) return;
		$fp = fopen($filepath, "r");
		while ($line = fgets($fp, self::MAX_BUFFER_SIZE))
		{
			$array = explode(" = ", $line);
			$array[1] = rtrim($array[1], "\n");
			$this->addUser($array[0], $array[1]);
		}
		fclose($fp);
	}


	//IRCとGitのユーザ対応を記憶する
	private function addUser($nick, $name)
	{
		foreach ($this->userNameList as $irc => $git)
		{
			if ($git === $name) return false;
		}
		$this->userNameList[$nick] = $name;
		return true;
	}

}
