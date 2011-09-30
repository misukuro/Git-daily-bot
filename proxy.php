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

require_once('botini.php');

/*
	Git,svnのための抽象クラス
	コマンドをexec関数に渡したり
	テストリストをシリアライズ/デシリアライズ、デプロイなどの処理を担当する。
	exec関数を実行するカレントディレクトリの設定も設定する。
*/
abstract class Proxy
{
	//SSHコネクション
	protected $remoteHost;
	protected $isErrorStream;
	protected $userCurrentDirectory;
	private $deployPath;

	//IRC接続情報
	protected $irc;
	protected $data;

	//基本的にリリースオープン中であるかどうかの管理は、Managerクラスで行う
	//ManagerクラスからProxy(git,svn)クラスへコマンドの中継が行われる時、
	//Managerクラスによってリリースプロセス中であることが保証される
	//(release-open要求以外の場合)
	//gitの場合はgitクラスにてcheckReleaseOpen()を実行すると、
	//この変数へ正しい状態が記録される
	protected $isReleaseOpened = false;
	protected $isHotfixOpened = false;

	protected $releaseTestList = array();
	protected $hotfixTestList = array();
	//作業用リスト(ok,ngなどのときに利用する)
	protected $testList = array();

	//テスト状況
	const TEST_INCOMPLETE = 'incomplete';
	const TEST_OK = true;
	const TEST_NG = false;

	const RELEASE_LIST_FILE = 'release_list';
	const HOTFIX_LIST_FILE = 'hotfix_list';

	protected $testStatus = array(
		self::TEST_INCOMPLETE => 'incomplete',
		self::TEST_OK => 'ok',
		self::TEST_NG => 'ng'
	);


	//SSHでデプロイサーバにつなげておく
	public function __construct($host, $path, $home)
	{
		//カレントディレクトリの設定
		$this->userCurrentDirectory = $home . "";
		$this->remoteHost = $host;
		$this->deployPath = $path;
	}

	//デプロイコマンドを発行します
	private function getDeployCommand()
	{
		$cmd = DEPLOY_TO_STAGING_CMD;
		return $cmd;
	}

	//既にデプロイ中かどうか調べるコマンドを発行します
	private function getChackDeployCommand(){
		$cmd = CHECK_DEPLOY_CMD;
		return $cmd;
	}

	/*
	　リリースオープンを行う
	*/
	public abstract function opReleaseOpen(&$irc, &$data);

	/*
	　hotfixオープンを行う
	*/
	public abstract function opHotfixOpen(&$irc, &$data);

	/*
	  テスト完了通知を受信
	*/
	public abstract function opOk(&$irc,&$data);

	/*
	  テストNG通知を受信
	*/
	public abstract function opNg(&$irc,&$data);

	/*
	  deployサーバ上でgit pullする
	*/
	public abstract function opSync(&$irc,&$data);

	/*
	  テストリストを表示
	*/
	public abstract function opList(&$irc,&$data);

	/*
	  テストリストを開発者単位で表示
	*/
	public abstract function opListAuthor(&$irc,&$data);

	/*
	  リリースクローズを行う
	*/
	public abstract function opReleaseClose(&$irc,&$data);

	/*
	  hotfixクローズを行う
	*/
	public abstract function opHotfixClose(&$irc,&$data);

	//コマンドを発行する
	protected function execute($cmd)
	{
		$cmd = 'ssh ' . $this->remoteHost . ' "' . $cmd . '"';
		//初期化
		$this->isErrorStream = false;
		unset ($this->return);
		//エラーも出力させる
		exec($cmd . ' 2>&1', $this->return);
		//一般的なエラーが出ていたらエラーフラグを立てておく
		for ($i=0;$i<count($this->return);$i++)
		{
			$this->return[$i] = preg_replace("/\e\[[0-9;]+m/", "", $this->return[$i]);
			if (preg_match("/git status is not clean/", $this->return[$i]))
			{
				$this->isErrorStream = true;
			} elseif (preg_match("/git-daily: fatal:/", $this->return[$i])) {
				$this->isErrorStream = true;
			} elseif (preg_match("/release process.*is not closed.*so cannot open release/", $this->return[$i])) {
				$this->isErrorStream = true;
			} elseif (preg_match("/ambiguous argument/", $this->return[$i])) {
				$this->isErrorStream = true;
			}
		}
		return $this->return;
	}

	/*
	  staging環境へデプロイする
	*/
	public function deployToStaging()
	{
		//staging環境へのデプロイ
		$this->irc->rawsend("starting deploy ..");

		$reply = $this->execute($this->getDeployCommand());
		//if reply has done 
		$output = array();
		$hasDone = false;
		for ($i=0;$i<count($reply);$i++)
		{
			if (preg_match("/\[.+\] done/", $reply[$i]))
			{
				$hasDone = true;
			}
			if ($hasDone)
			{
				array_push($output, $reply[$i]);
			}
		}
		//non done
		if (!$hasDone)
		{
			$output = $reply;
		}
		array_unshift($output, "deploy completed: ");
		$this->debugMessage($output);
		return $hasDone;
	}

	/*
	 deploy check
	*/
	protected function checkDeployNow()
	{
		//if already deploy
		$reply = $this->execute($this->getChackDeployCommand());
		if(count($reply) > 0){
			echo $reply;
			return false;
		}
		return true;
	}

	//デバッグメッセージを表示/保存します
	protected function debugMessage($s)
	{
		if (is_array($s))
		{
			$new_array = array();
			//内容を走査し、空の文字列が入っていたら送信しない
			for ($i=0;$i<count($s);$i++)
			{
				if (!empty($s[$i]))
				{
					array_push($new_array, $s[$i]);
				}
			}
			$s = $new_array;
		} else {
			if (empty($s)) return;
		}
		$this->irc->rawsend($s);
	}

	//releaseTestListをシリアライズする
	protected function saveReleaseTestList()
	{
		$deploy = $this->deployPath;

		$path = dirname(__FILE__) . "/" . self::RELEASE_LIST_FILE . '_' . str_replace('/', '_', $deploy);
		return $this->saveTestList($path, $this->releaseTestList);
		//return file_put_contents($path, serialize($this->releaseTestList));
	}

	//releaseTestListをデシリアライズする
	protected function loadReleaseTestList()
	{
		$deploy = $this->deployPath;

		$path = dirname(__FILE__) . "/" . self::RELEASE_LIST_FILE . '_' . str_replace('/', '_', $deploy);
		$array = $this->loadTestList($path);
		if (is_array($array))
		{
			$this->releaseTestList = $array;
			return true;
		}
		return false;
	}

	//hotfixTestListをシリアライズする
	protected function saveHotfixTestList()
	{
		$deploy = $this->deployPath;

		$path = dirname(__FILE__) . "/" . self::HOTFIX_LIST_FILE . '_' . str_replace('/', '_', $deploy);
		return $this->saveTestList($path, $this->hotfixTestList);
		//return file_put_contents($path, serialize($this->hotfixTestList));
	}

	//hotfixTestListをデシリアライズする
	protected function loadHotfixTestList()
	{
		$deploy = $this->deployPath;

		$path = dirname(__FILE__) . "/" . self::HOTFIX_LIST_FILE . '_' . str_replace('/', '_', $deploy);
		$array = $this->loadTestList($path);
		if (is_array($array))
		{
			$this->hotfixTestList = $array;
			return true;
		}
		return false;
	}

	//テストリストをXML形式で保存する
	private function saveTestList($filepath, $testlist)
	{
		$dom = new DomDocument('1.0');
		$dom->encoding = "UTF-8";
		$dom->formatOutput = true;
		$authorList = $dom->appendChild($dom->createElement('AuthorList'));
		foreach($testlist as $authorName => $tests)
		{
			$authorDom = $authorList->appendChild($dom->createElement('Author'));
			$authorDom->setAttribute('name', $authorName);
			foreach($tests as $hash => $status)
			{
				$testDom = $authorDom->appendChild($dom->createElement('Test'));
				$hashDom = $testDom->appendChild($dom->createElement('Hash'));
				$hashDom->appendChild($dom->createTextNode($hash));
				$statusDom = $testDom->appendChild($dom->createElement('Status'));
				$statusDom->appendChild($dom->createTextNode($status));
			}
		}
		echo $dom->saveXml();
		return file_put_contents($filepath, $dom->saveXml());
	}

	//XML形式のファイルからテストリストを取得する
	private function loadTestList($filepath)
	{
		$testlist = array();

		if (!is_readable($filepath)) return false;
		$dom = new DomDocument();
		$doc->validateOnParse = true;
		$dom->load($filepath);
		$authorList = $dom->getElementsByTagName('Author');
		foreach($authorList as $author)
		{
			$authorName = $author->getAttribute('name');
			$tests = $author->getElementsByTagName('Test');
			foreach($tests as $test)
			{
				$hashDom = $test->getElementsByTagName('Hash');
				$statusDom = $test->getElementsByTagName('Status');
				switch($statusDom->item(0)->nodeValue)
				{
					case self::TEST_INCOMPLETE:
						$status = self::TEST_INCOMPLETE;
						break;
					case "1":
						$status = self::TEST_OK;
						break;
					case "":
						$status = self::TEST_NG;
						break;
					default:
						echo "テストリストファイルのフォーマットがおかしい\n";
						$status = self::TEST_INCOMPLETE;
				}
				$testlist[$authorName][$hashDom->item(0)->nodeValue] = $status;
			}
		}
		return $testlist;
	}
}
