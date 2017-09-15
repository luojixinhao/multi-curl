<?PHP

namespace luojixinhao\mCurl;

/**
 * @author Jason
 * @date 2017-09-11
 * @version 1.3
 */
class multiCurl {

	protected $mh; //批处理cURL句柄
	protected $maxTry = 3;  //失败时最大尝试次数。小于等于0都不尝试。
	protected $maxConcur = 10; //并发数。小于等于1都代表并发为1。最大50000
	protected $handle = ''; //对返回内容处理。可取：json,php
	public $isStart = false; //是否已经开始采集
	protected $hasRun = false; //是否存在运行线程
	protected $urlPool = array(); //URL池子
	protected $urlHandlePool = array(); //URL线程池子
	protected $urlFailPool = array(); //失败的URL池子
	public $setopts = array(//公共CURL设置
		CURLOPT_AUTOREFERER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_HEADER => false,
		CURLINFO_HEADER_OUT => false,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_VERBOSE => false,
		CURLOPT_CONNECTTIMEOUT => 20,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_ENCODING => '',
		CURLOPT_REFERER => '',
		CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/28.0.1464.0 Safari/537.36',
	);
	public $infos = array(
		'stayNum' => 0, //待采集URL数
		'finishNum' => 0, //完成数
		'succNum' => 0, //成功数
		'failNum' => 0, //失败数
		'stillRunning' => 0, //正在运行数
		'queueNum' => 0, //队列剩余数
	);

	public function __construct($conf = array()) {
		$this->setConfig($conf);
	}

	/**
	 * 设置配置
	 * @param type $conf
	 */
	public function setConfig($conf = array()) {
		isset($conf['maxTry']) and $this->maxTry = intval($conf['maxTry']);
		isset($conf['maxConcur']) and $this->maxConcur = intval($conf['maxConcur']);
		isset($conf['handle']) and $this->handle = trim($conf['handle']);
		$this->maxConcur < 1 and $this->maxConcur = 1;
	}

	/**
	 * 获取运行结果信息
	 * @param type $item
	 */
	public function getInfos($item = null) {
		if ($item) {
			return isset($this->infos[$item]) ? $this->infos[$item] : null;
		} else {
			return $this->infos;
		}
	}

	/**
	 * 运行
	 * @param type $sundry
	 * 				默认null结果走回调
	 * 				设置为1~5结果可返回。建议采集数量多时不要使用,占内存。
	 * 				设置为函数，在函数中动态添加链接，函数返回true将一直循环等待，返回false采集完成后退出
	 */
	public function run($sundry = null) {
		$reArr = array();
		if ($this->isStart) {
			return $reArr;
		}
		$this->mh = curl_multi_init();
		$this->isStart = true;
		$isFunc = is_callable($sundry);
		$i = 0;
		$userLoop = 0;
		$status = null;
		do {
			$this->addHandle();

			$still_running = 0;
			$mrc = curl_multi_exec($this->mh, $still_running);
			$this->infos['stillRunning'] = $still_running;
			$this->hasRun = $still_running > 0 ? true : false;

			$select = curl_multi_select($this->mh);

			$queue = null;
			$curlInfo = curl_multi_info_read($this->mh, $queue);
			$this->infos['queueNum'] = intval($queue);
			$queue > 0 and $this->hasRun = true;

			if (isset($curlInfo['handle'])) {
				$this->hasRun = true;
				$i++;
				$ch = $curlInfo['handle'];
				$errorno = $curlInfo['result'];
				$getinfo = curl_getinfo($ch);
				$content = curl_multi_getcontent($ch);
				$error = curl_error($ch);
				$thisUrlHandle = isset($this->urlHandlePool[(int)$ch]) ? $this->urlHandlePool[(int)$ch] : array();
				$url = isset($thisUrlHandle['url']) ? $thisUrlHandle['url'] : (isset($getinfo['url']) ? $getinfo['url'] : '');
				$getheader = isset($thisUrlHandle['opt'][CURLOPT_HEADER]) ? $thisUrlHandle['opt'][CURLOPT_HEADER] : (isset($this->setopts[CURLOPT_HEADER]) ? $this->setopts[CURLOPT_HEADER] : false);
				if ($getheader) {
					$tmp = null;
					preg_match_all('#HTTP/.+(?=\r\n\r\n)#Usm', $content, $tmp);
					$header = isset($tmp[0]) ? $tmp[0] : '';
					$pos = 0;
					foreach ($header as $v) {
						$pos += strlen($v) + 4;
					}
					$content = substr($content, $pos);
				}
				if ('json' == $this->handle) {
					$content = json_decode($content, true);
				} elseif ('php' == $this->handle) {
					$content = unserialize($content);
				}
				$args = isset($thisUrlHandle['arg']) ? $thisUrlHandle['arg'] : null;

				$this->infos['finishNum'] ++;
				if ($errorno === CURLE_OK) {
					$this->infos['succNum'] ++;
					$callbackSuccess = isset($thisUrlHandle['success']) ? $thisUrlHandle['success'] : '';
					if (is_callable($callbackSuccess) || function_exists($callbackSuccess)) {
						$tmpArr = array(
							'url' => $url,
							'content' => $content,
							'args' => $args,
							'header' => isset($header) ? $header : '',
							'errorno' => $errorno,
							'error' => $error,
							//'getinfo' => $getinfo,
						);
						call_user_func_array($callbackSuccess, $tmpArr);
					}
				} else {
					$this->infos['failNum'] ++;
					isset($this->urlFailPool[$url]) ? $this->urlFailPool[$url] ++ : $this->urlFailPool[$url] = 1;
					if ($this->urlFailPool[$url] < $this->maxTry) {
						$this->add($url, $thisUrlHandle['opt'], $thisUrlHandle['arg'], $thisUrlHandle['success'], $thisUrlHandle['failure'], true);
					} else {
						unset($this->urlFailPool[$url]);
					}
					$callbackFailure = isset($thisUrlHandle['failure']) ? $thisUrlHandle['failure'] : '';
					if (is_callable($callbackFailure) || function_exists($callbackSuccess)) {
						$tmpArr = array(
							'url' => $url,
							'content' => $content,
							'args' => $args,
							'header' => isset($header) ? $header : '',
							'errorno' => $errorno,
							'error' => $error,
							//'getinfo' => $getinfo,
						);
						call_user_func_array($callbackFailure, $tmpArr);
					}
				}

				if (is_numeric($sundry)) {
					$sundry > 0 and $reArr[$i]['content'] = $content;
					$sundry > 0 && isset($header) and $reArr[$i]['header'] = $header;
					$sundry > 1 and $reArr[$i]['url'] = $url;
					$sundry > 2 and $reArr[$i]['error'] = $error;
					$sundry > 2 and $reArr[$i]['errorno'] = $errorno;
					$sundry > 3 and $reArr[$i]['getinfo'] = $getinfo;
					$sundry > 4 and $reArr[$i]['handle'] = $thisUrlHandle;
				}

				curl_multi_remove_handle($this->mh, $ch);
				curl_close($ch);
				unset($this->urlHandlePool[(int)$ch]);
			}

			if ($isFunc) {
				$status = call_user_func_array($sundry, array(&$this, $userLoop++));
				$status or $isFunc = false;
			}
			$this->infos['stayNum'] > 0 and $this->hasRun = true;
			if (!$this->hasRun && $userLoop > 1) {
				usleep(500000);
			}
		} while ($this->hasRun || $status);

		curl_multi_close($this->mh);
		return $reArr;
	}

	/**
	 * 添加处理句柄
	 * @return 添加的数量
	 */
	protected function addHandle() {
		$flag = 0;
		if (!is_resource($this->mh)) {
			return $flag;
		}
		if ($this->urlPool) {
			$deadLoop = 0;
			$stillRunning = 0;
			do {
				$deadLoop++;
				$urlItem = array_shift($this->urlPool);
				if (isset($urlItem['url'])) {
					$url = $urlItem['url'];
					$ch = curl_init($url);
					$opt = isset($urlItem['opt']) ? (array) $urlItem['opt'] : array();
					$options = $this->setopts;
					foreach ($opt as $key => $value) {
						$options[$key] = $value;
					}
					if (curl_setopt_array($ch, $options)) {
						$errcode = curl_multi_add_handle($this->mh, $ch);
						if (0 === $errcode) {
							$stillRunning++;
							$this->urlHandlePool[(int)$ch] = $urlItem;
						} else {

						}
					} else {

					}
				}
			} while ($deadLoop < 50000 && ($this->maxConcur - ($this->infos['stillRunning'] + $stillRunning)) > 0);
			$this->infos['stayNum'] = count($this->urlPool);
			$flag = $stillRunning;
		}

		return $flag;
	}

	/**
	 * 添加链接到池子
	 * @param type $url 链接地址
	 * @param type $opt CURL参数
	 * @param type $arg 透传参数
	 * @param type $callbackSuccess 成功时回调
	 * @param type $callbackFailure 失败时回调
	 * @param type $head 是否添加到队列开头
	 * @return type 池子链接数
	 */
	public function add($url, $opt = array(), $arg = array(), $callbackSuccess = null, $callbackFailure = null, $head = false) {
		$urlItem = array(
			'url' => $url,
			'opt' => $opt,
			'arg' => $arg,
			'success' => $callbackSuccess,
			'failure' => $callbackFailure,
		);
		$head ? array_push($this->urlPool, $urlItem) : array_unshift($this->urlPool, $urlItem);
		$this->infos['stayNum'] = count($this->urlPool);
		return $this->infos['stayNum'];
	}

}
