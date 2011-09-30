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

require_once("proxy.php");

/*
	デプロイサーバでGitのコマンドを叩いたりするクラス
*/
class Git extends Proxy
{
	//各種コマンド
	const CMD_SYNC = 'git daily pull';
	const CMD_RELEASE_SYNC = 'git daily release sync';
	const CMD_HOTFIX_SYNC = 'git daily hotfix sync';
	const CMD_RELEASE_OPEN = 'git daily release open';
	const CMD_RELEASE_CLOSE = 'git daily release close';
	const CMD_HOTFIX_OPEN = 'git daily hotfix open';
	const CMD_HOTFIX_CLOSE = 'git daily hotfix close';
	const CMD_LIST = 'git daily release list';
	const CMD_HOTFIX_LIST = 'git daily hotfix list';
	const CMD_BRANCH = 'git branch -a';
	const CMD_BRANCH_MOVE = 'git checkout ';
	const CMD_REV_PARSE = 'git rev-parse ';
	const CMD_FETCH = 'git fetch --all ';
	const BRANCH_MASTER = 'master';
	const BRANCH_DEVELOP = 'develop';

	//ハッシュリスト保存ファイル
	//const HASH_LIST_FIL = 'hash';
	const RELEASE_HASH_FILE = 'release_hash';
	const HOTFIX_HASH_FILE = 'hotfix_hash';
	const STATE_CLOSE = 2;
	const STATE_OPEN = 1;
	const STATE_RELEASE = 1;
	const STATE_HOTFIX = 2;

	//ハッシュと一時番号の対応表
	//private $hashList = array(); //temp用
	private $releaseHashList = array();
	private $hotfixHashList = array();
	//private $nextNum = 1;
	private $releaseNextNum = 1;
	private $hotfixNextNum = 1;
	private $isReleaseTestListEmpty = false;
	private $isHotfixTestListEmpty = false;
	private $deployPath;

	public function __construct($host, $path, $home)
	{
		parent::__construct($host, $path, $home);
		$this->deployPath = $path;
		$this->loadHashList();
		parent::loadReleaseTestList();
		parent::loadHotfixTestList();
	}

	//リリースオープンのために発行するコマンドを返す
	private function getReleaseOpenCommand()
	{
		return 'echo y | (cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_RELEASE_OPEN . ')';
	}

	//リリースクローズのために発行するコマンドを返す
	private function getReleaseCloseCommand()
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_RELEASE_CLOSE;
	}

	//hotfixオープンのために発行するコマンドを返す
	private function getHotfixOpenCommand()
	{
		return 'echo y | (cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_HOTFIX_OPEN . ')';
	}

	//hotfixクローズのために発行するコマンドを返す
	private function getHotfixCloseCommand()
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_HOTFIX_CLOSE;
	}
	//@syncで発行するコマンドを返す
	private function getSyncCommand()
	{
		if ($this->isHotfixOpened)
		{
			return $this->getSyncHotfixCommand();
		} else {
			return $this->getSyncReleaseCommand();
		}
		//return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_SYNC;
	}

	//release syncで発行するコマンド
	private function getSyncReleaseCommand()
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_RELEASE_SYNC;
	}

	//hotfix syncで発行するコマンド
	private function getSyncHotfixCommand()
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_HOTFIX_SYNC;
	}

	//テストリストを取得するコマンドを返す
	private function getReleaseTestListCommand()
	{
		if ($this->isHotfixOpened)
		{
			return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_HOTFIX_LIST;
		} else {
			return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_LIST;
		}
	}

	//hotfixテストリストを取得するコマンドを返す
	private function getHotfixTestListCommand()
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_HOTFIX_LIST;
	}
	//ブランチリストを取得するコマンドを返す
	private function getBranchListCommand()
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_BRANCH;
	}

	//ブランチを移動するコマンドを返す
	private function getCheckoutCommand($branch)
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_BRANCH_MOVE . ' ' . $branch;
	}

	//ブランチを移動するコマンドを返す
	private function getFetchCommand()
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_FETCH;
	}

	//git rev-parseのコマンドを返す
	private function getRevParseCommand($s)
	{
		return 'cd ' . $this->userCurrentDirectory . ' && ' . self::CMD_REV_PARSE . ' ' . $s;
	}

	/*
	　リリースオープンを行う
	  git-daily release openを実行
	  確認メッセージに応答するため、execute関数を使えない
	*/
	public function opReleaseOpen (&$irc, &$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		//今はまだリリースオープンプロセスでないことを明示する
		//リリースオープン中ならManagerクラスで弾かれるはず
		$this->isReleaseOpened = false;
		//hotfixオープン中はオープンさせない
		if ($this->isHotfixOpened)
		{
			parent::debugMessage('i am hotfix process so i cannot open release process');
			return $this->isReleaseOpened;
		}

		//sshでgit daily release openを発行する
		$lines = parent::execute($this->getCheckoutCommand(self::BRANCH_DEVELOP));
		$lines = parent::execute($this->getReleaseOpenCommand());

		//エラーが出ていたらそのまま表示
		if ($this->isErrorStream)
		{
			parent::debugMessage($lines);
		} else {
			for ($i=0; $i<count($lines);$i++)
			{
				//無駄な装飾情報を取り除く
				$lines[$i] = preg_replace("/\e\[[0-9;]+m/", "", $lines[$i]);
				//リリースオープン完了を受信
				if (preg_match("/release opened/", $lines[$i], $matches))
				{
					parent::debugMessage('release opened');
					$irc->changeTopic('release opened');
					$this->isReleaseOpened = true;
				}
			}
			if (!$this->isReleaseOpened)
			{
				//予期せぬエラー
				parent::debugMessage($lines);
			}
		}

		return $this->isReleaseOpened;

	}

	/*
	　hotfixオープンを行う
	*/
	public function opHotfixOpen (&$irc, &$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		//今はまだリリースオープンプロセスでないことを明示する
		//リリースオープン中ならManagerクラスで弾かれるはず
		$this->isHotfixOpened = false;
		$lines = parent::execute($this->getCheckoutCommand(self::BRANCH_MASTER));
		$lines = parent::execute($this->getHotfixOpenCommand());

		//エラーが出ていたらそのまま表示
		//hotfixもrelease opendで正常完了らしい
		if ($this->isErrorStream)
		{
			parent::debugMessage($lines);
		} else {
			for ($i=0; $i<count($lines);$i++)
			{
				//無駄な装飾情報を取り除く
				$lines[$i] = preg_replace("/\e\[[0-9;]+m/", "", $lines[$i]);
				//リリースオープン完了を受信
				//オープン時はrelease openedだったはず
				if (preg_match("/release opened/", $lines[$i], $matches))
				{
					parent::debugMessage('hotfix opened');
					$irc->changeTopic('hotfix opened');
					if ($this->isReleaseOpened)
					{
						parent::debugMessage('the operation to the release process is invalid until hotfix process is complete');
					}
					$this->isHotfixOpened = true;
				}
			}
			if (!$this->isHotfixOpened)
			{
				//予期せぬエラー
				parent::debugMessage($lines);
			}
		}

		return $this->isHotfixOpened;

	}
	/*
	  テスト完了通知を受信
	*/
	public function opOk(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;

		//hotfixとreleaseで別々にリストを保持する
		//引数なしのときはOK済みリストを表示する
		if ($this->isHotfixOpened)
		{
			if(!isset($data->messageex[1])){
				$this->preTestList($this->hotfixTestList, parent::TEST_OK);
				return;
			}else{
				$this->hotfixTestList = $this->approvalTest($data->messageex, $this->hotfixTestList, parent::TEST_OK);
			}
		} else {
			if(!isset($data->messageex[1])){
				$this->preTestList($this->releaseTestList, parent::TEST_OK);
				return;
			}else{
				$this->releaseTestList = $this->approvalTest($data->messageex, $this->releaseTestList, parent::TEST_OK);
			}
		}
		//テストリストを走査してテスト完了か調べる
		if ($this->isCompleteTestList())
		{
			parent::debugMessage(array('test is all complete!'));
			$this->showReleaseList();
		}
		//テストリストをファイルに保存する
		parent::saveReleaseTestList();
		parent::saveHotfixTestList();
	}

	//ok/ng list
	private function preTestList($testList, $sign)
	{
		$output = array('Test ' . $this->testStatus[$sign] . ' list:');
		foreach ($testList as $author => $list)
		{
			foreach ($list as $hashId => $status)
			{
				if ($status !== $sign) continue;
				array_push($output, sprintf("[%s] %s = %s", $this->searchHashList($hashId), $hashId, $author));
			}
		}
		if(count($output) > 1){
			array_push($output, '----');
			$this->debugMessage($output);
		}else{
			$this->debugMessage('nothing');
		}
	}

	/*
	  テストNG通知を受信
	*/
	public function opNg(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;

		//hotfixとreleaseで別々にリストを保持する
		if ($this->isHotfixOpened)
		{
			if(!isset($data->messageex[1])){
				$this->preTestList($this->hotfixTestList, parent::TEST_NG);
			}else{
				$this->hotfixTestList = $this->approvalTest($data->messageex, $this->hotfixTestList, parent::TEST_NG);
			}
		} else {
			if(!isset($data->messageex[1])){
				$this->preTestList($this->releaseTestList, parent::TEST_NG);
			}else{
				$this->releaseTestList = $this->approvalTest($data->messageex, $this->releaseTestList, parent::TEST_NG);
			}
		}

		//テストリストをファイルに保存する
		parent::saveReleaseTestList();
		parent::saveHotfixTestList();
	}

	//テスト結果(ok/ng)を記憶する
	private function approvalTest($messages, $testList, $sign)
	{
		//引数部分が空なら処理しない(その前で弾かれているはず)
		if (!isset($messages))
		{
			return;
		}

		//引数を１つずつ処理していく
		for ($i=1;$i<count($messages);$i++)
		{
			if ($messages[$i] == "")
			{
				continue;
			}
			//ユーザ名だった場合
			if (array_key_exists($messages[$i], $testList))
			{
				foreach ($testList[$messages[$i]] as $hash => $status)
				{
					$testList[$messages[$i]][$hash] = $sign;
				}
				parent::debugMessage(sprintf("acknowledged %s's all commit as %s\n", $messages[$i], $this->testStatus[$sign]));
			//正しいハッシュだった場合
			} elseif ($author = $this->getAuthorNameFromHash($messages[$i], $testList)) {
				$testList[$author][$messages[$i]] = $sign;
				parent::debugMessage(sprintf("acknowledged %s = %s as %s\n", $messages[$i], $author, $this->testStatus[$sign]));
			//一時番号だったら
			} elseif (preg_match("/\[([0-9]+)\]/", $messages[$i], $match)) {
				//番号からハッシュを引いて、ハッシュからAuthorを引く
				if (($hash = $this->getHash($match[1])) !== "")
				{
					if ($author = $this->getAuthorNameFromHash($hash, $testList))
					{
						$testList[$author][$hash] = $sign;
						parent::debugMessage(sprintf("acknowledged %s = %s as %s\n", $hash, $author, $this->testStatus[$sign]));
					} else {
						parent::debugMessage(sprintf("%s is valid number but this is not valid hash", $messages[$i]));
					}
				} else {
					parent::debugMessage(sprintf("%s is not valid number", $messages[$i]));
				}
			} else {
				//git rev-parseにかけて正しいハッシュが得られれば処理
				$reply = $this->execute($this->getRevParseCommand($messages[$i]));
				if (!$this->isErrorStream)
				{
					if (preg_match("/([0-9a-f]{40})/", $reply[0], $match) &&
						$author = $this->getAuthorNameFromHash($match[0], $testList))
					{
							$testList[$author][$match[0]] = $sign;
							parent::debugMessage(sprintf("acknowledged %s = %s as %s\n", $match[0], $author, $this->testStatus[$sign]));
					} else {
						parent::debugMessage(sprintf("%s is not valid hash or valid author name\n", $messages[$i]));
					}
				} else {
					parent::debugMessage(sprintf("%s is ambiguous hash or invalid author name\n", $messages[$i]));
				}
			}
		}
		return $testList;
	}

	/*
	  deployサーバ上でgit pullする
	  stagingへ出す
	  同期中に手動でリリースオープンされたことが発覚されたら、1以上のフラグを返す
	*/
	public function opSync(&$irc, &$data, &$manager=null)
	{
		$this->irc = $irc;
		$this->data = $data;
		$isCompleteSync = false;

		//deploy check
		if(!parent::checkDeployNow())
		{
			parent::debugMessage("synchronization aborted because being deployed. ");
			return;
		}

		//どのプロセスも実行状態でないときに、@syncが呼ばれたら、
		//手動でリリースオープンされた可能性をあるので
		//hotfix及びreleaseのどちらのsyncも行う
		if (!$this->isReleaseOpened && !$this->isHotfixOpened)
		{
			if ($manager == null) echo '仕様外の動作です:git.php378行'."\n";

			//release
			$return = $this->execute($this->getSyncReleaseCommand());
			if ($this->isErrorStream)
			{
				//エラーが返ってきた
				parent::debugMessage($return);
			}
			$releaseOpenFlag = $this->checkFetch($return);
			if ($releaseOpenFlag > 0)
			{
				switch ($releaseOpenFlag)
				{
					case self::STATE_OPEN:
						$this->isReleaseOpened = true;
						break;
					case self::STATE_CLOSE:
						$this->isReleaseOpened = false;
						break;
					default:
						echo '仕様外の動作です：git.php 414行目'."\n";
				}
			}

			//hotfix
			$return = $this->execute($this->getSyncHotfixCommand());
			if ($this->isErrorStream)
			{
				//エラーが返ってきた
				parent::debugMessage($return);
			}
			$hotfixOpenFlag = $this->checkFetch($return);
			$manager->updateStatus($releaseOpenFlag, $hotfixOpenFlag);
			if ($hotfixOpenFlag > 0)
			{
				switch ($hotfixOpenFlag)
				{
					case self::STATE_OPEN:
						$this->isHotfixOpened = true;
						break;
					case self::STATE_CLOSE:
						$this->isHotfixOpened = false;
						break;
					default:
						echo '仕様外の動作です：git.php 414行目'."\n";
				}
			}
			return;
		}

		//sshでgit pullを発行する
		$return = $this->execute($this->getSyncCommand());

		//エラーが返ってきた
		if ($this->isErrorStream)
		{
			parent::debugMessage($return);
			return;
		}
		//pullが正常完了していたらおｋとみなす
		for ($i=0;$i<count($return);$i++)
		{
			if (preg_match("/pull completed/", $return[$i]))
			{
				$isCompleteSync = true;
				break;
			}
		}
		//念のため手動でクローズされていないか確認する
		//botのStatusがClose中でないときに、オープンが検出されることはないはず。。
		//close中なら、上でチェックされているはず
		//checkFetchはクローズされたかどうかを出力からチェックする
		$deleteFlag = $this->checkFetch($return);
		if ($deleteFlag == self::STATE_CLOSE)
		{
			echo "手動でのクローズを検知しました\n";
			$closeStatus = $this->isHotfixOpened ? self::STATE_HOTFIX : self::STATE_RELEASE;
			if ($closeStatus == self::STATE_HOTFIX)
			{
				$this->isHotfixOpened = false;
			} elseif ($closeStatus == self::STATE_RELEASE) {
				$this->isReleaseOpened = false;
			} else {
				echo "規定外の動作:git.php ln:461\n";
				return;
			}
			return $closeStatus;
		}

		//pull completed
		if ($isCompleteSync)
		{
			parent::debugMessage('sync completed');
			$this->deployToStaging();
			$this->opList($irc, $data);
		} else {
			parent::debugMessage($return);
		}
		return 0;
	}

	//外部でリリースプロセスがオープンされていないかチェックする
	private function checkFetch($return)
	{
		for ($i=0;$i<count($return);$i++)
		{
			//hotfixでもreleaseと表示されるが、
			//もしかしたらhotfixになるかもしれない..
			if (preg_match("/start to tracking .+ branch/", $return[$i])){
				$openFlag = self::STATE_OPEN;
				parent::debugMessage('sync completed:start to tracking release branch');
				$this->opList($this->irc, $this->data);
				return $openFlag;
			//よく考えたらデプロイサーバ以外でリリースクローズなんてあり得ない
			} elseif (preg_match("/Closed old .+ branch/", $return[$i])){
				$openFlag = self::STATE_CLOSE;
				parent::debugMessage('sync completed:deleted release branch');
				return $openFlag;
			}
		}
		return 0;
	}

	/*
	  テストリストを表示
	*/
	public function opList(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		$isCommitList = false;
		$isTestComplete = true;
		$err_out = array();
		$testList = array();

		//hotfixとreleaseで別々にリストを保持する
		if ($this->isHotfixOpened)
		{
			$this->testList = $this->hotfixTestList;
			$this->isHotfixTestListEmpty = false;
		} else {
			$this->testList = $this->releaseTestList;
			$this->isReleaseTestListEmpty = false;
		}
		//sshでgit daily release listを発行する
		$reply = $this->execute($this->getReleaseTestListCommand());

		//エラーが返ってきた
		if ($this->isErrorStream)
		{
			parent::debugMessage($reply);
			return;
		}

		for ($i=0;$i<count($reply);$i++)
		{
			if (preg_match("/Commit list:/", $reply[$i]))
			{
				$isCommitList = true;
			} elseif ($isCommitList && empty($reply[$i])) {
				$isCommitList = false;
			} elseif ($isCommitList) {
				//CommitHashとAuthorNameを取り出す
				if (preg_match("/([0-9a-f]+) = ([a-z0-9.-]+)/", $reply[$i], $matches))
				{
					//テストリストに追加する
					//AutorNameごとに自分のテストリスト(CommitHashとテスト状況)を配列で保持
					if (!array_key_exists($matches[2], $this->testList))
					{
						//Author新規追加
						$testList[$matches[2]][$matches[1]] = parent::TEST_INCOMPLETE;
						$this->registHashList($matches[1]);
					} else {
						//既に名前とハッシュが関連づけされているなら再登録しない
						if (!array_key_exists($matches[1], $this->testList[$matches[2]]))
						{
							//コミット新規追加
							$testList[$matches[2]][$matches[1]] = parent::TEST_INCOMPLETE;
							$this->registHashList($matches[1]);
						} else {
							$testList[$matches[2]][$matches[1]] = $this->testList[$matches[2]][$matches[1]];
						}
					}
				} else {
					//予期せぬフォーマット
					array_push($err_out, 'format error: '.$reply[$i]);
				}
			}
		}
		$this->testList = $testList;

		//hotfixとreleaseで別々にリストを保持する
		if ($this->isHotfixOpened)
		{
			$this->hotfixTestList = $this->testList;
		} else {
			$this->releaseTestList = $this->testList;
		}
		//テストリストを表示する
		$output = array('Test list:');
		foreach ($this->testList as $author => $list)
		{
			foreach ($list as $hashId => $status)
			{
				if ($status === parent::TEST_OK) continue;
				array_push($output, sprintf("[%s] %s = %s", $this->searchHashList($hashId), $hashId, $author));
				$isTestComplete = false;
			}
		}
		if (empty($this->testList))
		{
			array_push($err_out, 'commit list is empty');
			if ($this->isHotfixOpened)
			{
				$this->isHotfixTestListEmpty = true;
			} else {
				$this->isReleaseTestListEmpty = true;
			}
		} elseif ($isTestComplete) {
			array_push($err_out, 'test is all complete');
		} else {
			array_push($output, 'Let\'s test!');
			//ハッシュリストをファイルに保存する
			$this->saveHashList();
		}
		$output = array_merge($output, $err_out);
		parent::debugMessage($output);

		/*デバッグ用
		echo "リリースリスト\n";
		var_dump($this->releaseTestList);
		echo "ホットフィックスリスト\n";
		var_dump($this->hotfixTestList);
		*/
	}

	/*
	  ハッシュリストにハッシュを一時番号を付けて登録する
	*/
	private function registHashList($hash)
	{
		if ($this->isHotfixOpened) 
		{
			$hashList = &$this->hotfixHashList;
			$nextNum = &$this->hotfixNextNum;
		} else {
			$hashList = &$this->releaseHashList;
			$nextNum = &$this->releaseNextNum;
		}
		$hashList = $this->isHotfixOpened ? $this->hotfixHashList : $this->releaseHashList;
		$nextNum = $this->isHotfixOpened ? $this->hotfixNextNum : $this->releaseNextNum;
		if ($this->searchHashList($hash)) return false;
		$hashList[$hash] = $nextNum;
		$nextNum++;
		return true;
	}

	/*
	  ハッシュリストにそのハッシュがあれば、その一時番号を返し、
	  なければ空を返す
	*/
	private function searchHashList($hash)
	{
		$hashList = $this->isHotfixOpened ? $this->hotfixHashList : $this->releaseHashList;
		if (!isset($hashList[$hash])) return "";

		return $hashList[$hash];
	}

	/*
	  ハッシュリストから該当する番号のハッシュを返する
	  なければfalseを返する
	*/
	private function getHash($targetNum)
	{
		$hashList = $this->isHotfixOpened ? $this->hotfixHashList : $this->releaseHashList;
		foreach ($hashList as $hash => $num)
		{
			if ($targetNum == $num)
			{
				return $hash;
			}
		}
		return false;
	}

	/*
	  テストリストを開発者単位で表示
	*/
	public function opListAuthor(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		$isAuthorList = false;
		$output = array('Test list:');
		$temp = array();

		//hotfixとreleaseで別々にリストを保持する
		if ($this->isHotfixOpened)
		{
			$this->testList = $this->hotfixTestList;
			//sshでgit daily hotfix listを発行する
			$reply = $this->execute($this->getHotfixTestListCommand());
		} else {
			$this->testList = $this->releaseTestList;
			//sshでgit daily release listを発行する
			$reply = $this->execute($this->getReleaseTestListCommand());
		}

		//エラーが返ってきた
		if ($this->isErrorStream)
		{
			parent::debugMessage($reply);
			return;
		}
		//Author list
		//Author list以降に出力されるリスト(コミットや変更・追加ファイルなど)がないことが前提
		for ($i=0;$i<count($reply);$i++)
		{
			if (preg_match("/Author list:/", $reply[$i]))
			{
				$isAuthorList = true;
			} elseif ($isAuthorList) {
				//40文字の16進数があったら無条件で抜き出す
				if (preg_match("/http:\/\/.+\/.*([0-9a-f]{40})/", $reply[$i], $match))
				{
					//テストが完了していたら表示しない
					$author = $this->getAuthorNameFromHash($match[1], $this->testList);
					if ($author !== "")
					{
						if ($this->testList[$author][$match[1]] === parent::TEST_OK) continue;
					}
					$head = "";
					if (($num = $this->searchHashList($match[1])) !== "")
					{
						$head = "[" . $num . "] ";
					}
					$reply[$i] = $head . trim($reply[$i]);
				} else {
					//ここはAuthor名の行とURL出力後の改行で呼び出される
					//当該Authorのコミットが全てテスト済みなら
					//出力にはAuthor名も含めて表示しない
					if (count($temp) > 1)
					{
						$output = array_merge($output, $temp);
						unset($temp);
						$temp = array();
					}
					if($reply[$i] !== "")
					{
						$reply[$i] = trim($reply[$i]);
					} else {
						//既にテストOKなAuthorなら出力しない
						$temp = array();
						continue;
					}
				}
				array_push($temp, $reply[$i]);
			}
		}
		//最後の行がコミットの表示で終わっているかもしれない
		if (count($temp) > 1)
		{
			$output = array_merge($output, $temp);
		}
		//出力がない,テストがすべて完了していたら
		if (count($output) == 1)
		{
			array_push($output, 'author list is empty or test is all complete');
		} else {
			array_push($output, 'Let\'s test!');
		}

		parent::debugMessage($output);
	}

	/*
	  リリースリストを表示
	*/
	public function showReleaseList()
	{
		$output = array();
		$isModifiedNow = false;
		$isAddedNow = false;
		$isFilesNow = false;

		//sshでgit daily release listを発行する
		$reply = $this->execute($this->getReleaseTestListCommand());

		//エラーが返ってきた
		if ($this->isErrorStream)
		{
			parent::debugMessage($reply);
			return;
		}

		for ($i=0;$i<count($reply);$i++)
		{
			if (preg_match("/Added files:/", $reply[$i]))
			{
				$isFilesNow = true;
				array_push($output, 'Added files:');
			} elseif (preg_match("/Modified files:/", $reply[$i])) {
				$isFilesNow = true;
				array_push($output, 'Modified files:');
			} elseif (preg_match("/Author list:/", $reply[$i])) {
				$isFilesNow = false;
			} elseif ($isFilesNow && !empty($reply[$i])) {
				array_push($output, trim($reply[$i]));
			}
		}
		if (count($output) > 1)
		{
			array_push($output, '-----');
		}
		parent::debugMessage($output);
	}
	/*
	  リリースクローズを行う
	*/
	public function opReleaseClose(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		//リリースオープン中であることを明示するためにtrue
		//リリースオープン中でないならManagerクラスで弾かれるはず
		$this->isReleaseOpened = true;

		//hotfixオープン中はリリースクローズさせない
		if ($this->isHotfixOpened)
		{
			parent::debugMessage('please complete hotfix process first');
			return $this->isReleaseOpened;
		}

		//テストリストを走査してテスト完了か調べる
		if (!$this->isCompleteTestList("release"))
		{
			parent::debugMessage('test is incomplete!');
			return $this->isReleaseOpened;
		}

		$releaseCloseSuccess = false;
		$reply = $this->execute($this->getReleaseCloseCommand());

		//エラー対応
		for ($i=0;$i<count($reply);$i++)
		{
			if (preg_match("/run release sync first/", $reply[$i]))
			{
				parent::debugMessage('please run @sync');
				return $this->isReleaseOpened;
			}
		}

		if ($this->isErrorStream)
		{
			parent::debugMessage($reply);
			return $this->isReleaseOpened;
		}

		for ($i=0;$i<count($reply);$i++)
		{
			//リリース完了通知を受けたらテストリストを空にする
			if (preg_match("/release closed/", $reply[$i]))
			{
				$this->releaseTestList = array();
				$this->nextNum = 1;
				$releaseCloseSuccess = true;
				unset($this->releaseHashList);
				$this->releaseHashList = array();
				$this->saveHashList();
				parent::debugMessage('release closed');
				$irc->changeTopic('release closed');
				$this->isReleaseOpened = false;
				parent::saveReleaseTestList();
				break;
			}
		}
		if (!$releaseCloseSuccess)
		{
			//予期せぬエラー
			parent::debugMessage($reply);
		}
		return $this->isReleaseOpened;
	}


	/*
	  hotfixクローズを行う
	  TODO::releaseClose extend
	*/
	public function opHotfixClose(&$irc,&$data)
	{
		$this->irc = $irc;
		$this->data = $data;
		//リリースオープン中であることを明示するためにtrue
		//リリースオープン中でないならManagerクラスで弾かれるはず
		$this->isHotfixOpened = true;

		//テストリストを走査してテスト完了か調べる
		if (!$this->isCompleteTestList())
		{
			parent::debugMessage('test is incomplete!');
			return $this->isHotfixOpened;
		}

		$releaseCloseSuccess = false;
		$reply = $this->execute($this->getHotfixCloseCommand());

		//エラー対応
		for ($i=0;$i<count($reply);$i++)
		{
			if (preg_match("/run release sync first/", $reply[$i]))
			{
				parent::debugMessage('please run @sync');
				return $this->isHotfixOpened;
			}
		}

		if ($this->isErrorStream)
		{
			parent::debugMessage($reply);
			return $this->isHotfixOpened;
		}

		for ($i=0;$i<count($reply);$i++)
		{
			//リリース完了通知を受けたらテストリストを空にする
			if (preg_match("/hotfix closed/", $reply[$i]))
			{
				$this->hotfixTestList = array();
				$this->nextNum = 1;
				$releaseCloseSuccess = true;
				if (!$this->isReleaseOpened)
				{
					unset($this->hotfixHashList);
					$this->hotfixHashList = array();
					$this->saveHashList();
				}
				parent::debugMessage('hotfix closed');
				$irc->changeTopic('hotfix closed');
				$this->isHotfixOpened = false;
				parent::saveHotfixTestList();
				break;
			}
		}
		if (!$releaseCloseSuccess)
		{
			//予期せぬエラー
			parent::debugMessage($reply);
		}
		return $this->isHotfixOpened;
	}

	/*
	  リリースマスターを変更する
	/
	public function opChangeMaster(&$irc,&$data){
		$this->irc = $irc;
		$this->data = $data;

	}
	*/

	//指定されたハッシュに対応するAutherNameを取得する
	private function getAuthorNameFromHash($hash, $arrArray)
	{
		while (list($author, $hashList) = each($arrArray))
		{
			if (array_key_exists($hash, $hashList))
			{
				return $author;
			}
		}
		return "";
	}

	/*指定されたAuthorNameの全てコミットに対するテストが完了しているかどうか
	private function isCompleteTestOfAuthor($targetAuthor){
		$isComplete = true;
		while(list($author, $hashList) = each($this->testList)){
			if($author !== $targetAuthor) continue;
			foreach ($hashList as $hash => $status){
				if($status !== parent::TEST_OK){
					$isComplete = false;
				}
			}
		}
		return $isComplete;
	}
	*/

	//テストリストのテストを完了したかどうか
	//modeはreleaseテストリストとhotfixテストリストどちらを見るかを設定する
	//明示的に設定するとreleaseリストを調べる
	//設定しないときは、hotfix中ならhotfixリストを調べる
	private function isCompleteTestList($mode = "")
	{
		//テストリストを取得済みでなおかつ、それが空(コミットがない)の場合はtrue
		//テストリストが未取得の場合はfalse
		if ($this->isHotfixOpened && $mode === "")
		{
			if ($this->isHotfixTestListEmpty) return true;
			//テストリストが未取得
			if (empty($this->hotfixTestList)) return false;
			$this->testList = $this->hotfixTestList;
		} else {
			if ($this->isReleaseTestListEmpty) return true;
			//テストリストが未取得
			if (empty($this->releaseTestList)) return false;
			$this->testList = $this->releaseTestList;
		}
		$isComplete = true;
		$hashList = array();
		foreach ($this->testList as $author =>  $hashList)
		{
			foreach ($hashList as $hash => $status)
			{
				if ($status !== parent::TEST_OK)
				{
					$isComplete = false;
					break 2;
				}
			}
		}
		return $isComplete;
	}

	//hashListをシリアライズする
	private function saveHashList()
	{
		$deploy = $this->deployPath;

		if ($this->isHotfixOpened)
		{
			$hashList = $this->hotfixHashList;
			$nextNum = $this->hotfixNextNum;
			$hashFile = self::HOTFIX_HASH_FILE;
		} else {
			$hashList = $this->releaseHashList;
			$nextNum = $this->releaseNextNum;
			$hashFile = self::RELEASE_HASH_FILE;
		}

		$path = dirname(__FILE__) . "/" . $hashFile . '_' . str_replace('/', '_', $deploy);
		return $this->saveHashXml($path, $hashList, $nextNum);
		//return file_put_contents($path, serialize($targetList));
	}

	//hashListをデシリアライズする
	private function loadHashList()
	{
		$deploy = $this->deployPath;

		if ($this->isHotfixOpened)
		{
			$hashList = $this->hotfixHashList;
			$nextNum = $this->hotfixNextNum;
			$hashFile = self::HOTFIX_HASH_FILE;
		} else {
			$hashList = $this->releaseHashList;
			$nextNum = $this->releaseNextNum;
			$hashFile = self::RELEASE_HASH_FILE;
		}

		$path = dirname(__FILE__) . "/". $hashFile . '_' . str_replace('/', '_', $deploy);
		if (!is_readable($path)) return false;
		return $this->loadHashXml($path, $hashList, $nextNum);

		//$array = unserialize(file_get_contents($path));
		/*
		if (is_array($array))
		{
			$this->hashList = $array;
			//復元処理
			$max_num = 0;
			foreach ($this->hashList as $hash => $num)
			{
				if ($max_num < $num) $max_num = $num;
			}
			$this->nextNum = $max_num + 1;
			return true;
		}
		*/
		//return false;
	}

	//リリースオープン中かどうか、
	//git branch -aを発行して調べます。
	public function checkReleaseOpen()
	{
		$this->isReleaseOpened = false;
		echo "リリースオープン中かどうか調べています\n";
		$return = $this->execute($this->getFetchCommand());
		$return = $this->execute($this->getBranchListCommand());
		for ($i=0;$i<count($return);$i++)
		{
			if (preg_match("/remotes\/.*\/release\//", $return[$i]))
			{
				echo "リリースオープン中です\n";
				$this->isReleaseOpened = true;
				break;
			}
		}
		//手動で開かれた場合
		return $this->isReleaseOpened;
	}

	//hotfixオープン中かどうか、
	//git branch -aを発行して調べます。
	public function checkHotfixOpen()
	{
		$this->isHotfixOpened = false;
		echo "ホットフィックスオープン中かどうか調べています\n";
		$return = $this->execute($this->getFetchCommand());
		$return = $this->execute($this->getBranchListCommand());
		for ($i=0;$i<count($return);$i++)
		{
			if (preg_match("/remotes\/.*\/hotfix\//", $return[$i]))
			{
				echo "ホットフィックスオープン中です\n";
				$this->isHotfixOpened = true;
				break;
			}
		}
		return $this->isHotfixOpened;
	}

	//hashリストをXML形式で保存する
	private function saveHashXml($filepath, $hashlist, $nextNum)
	{
		$dom = new DomDocument('1.0');
		$dom->encoding = "UTF-8";
		$dom->formatOutput = true;
		$hashList = $dom->appendChild($dom->createElement('HashList'));
		$hashList->setAttribute('NextNum', $nextNum);
		foreach ($hashlist as $hash => $num)
		{
			$indexDom = $hashList->appendChild($dom->createElement('Index'));
			$hashDom = $indexDom->appendChild($dom->createElement('Hash'));
			$hashDom->appendChild($dom->createTextNode($hash));
			$numDom = $indexDom->appendChild($dom->createElement('Number'));
			$numDom->appendChild($dom->createTextNode($num));
		}
		return file_put_contents($filepath, $dom->saveXml());
	}

	//XML形式のファイルからhashリストを取得する
	private function loadHashXml($filepath, &$nextNum)
	{
		$hashlist = array();

		if (!is_readable($filepath)) return false;
		$dom = new DomDocument();
		$doc->validateOnParse = true;
		$dom->load($filepath);
		$hashDom = $dom->getElementsByTagName('HashList');
		$nextNum = $hashDom->item(0)->getAttribute('NextNum');
		$indexList = $dom->getElementsByTagName('Index');
		foreach($indexList as $index)
		{
			$hashDom = $index->getElementsByTagName('Hash');
			$numDom = $index->getElementsByTagName('Number');
			$hashlist[$hashDom->item(0)->nodeValue] = $numDom->item(0)->nodeValue;
		}
		return $hashlist;
	}

}
