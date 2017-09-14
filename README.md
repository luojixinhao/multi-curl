关于
-----

利用curl_multi_*系列函数+回调达到多线程采集。

需求
----
PHP 5.3 +

安装
----
composer require luojixinhao/multi-curl:*

联系
--------
Email: lx_2010@qq.com<br>


## 示例

```php
<?php

use luojixinhao\mCurl;

$mc = new multiCurl();
$mc->add('http://proxyip.9window.com/api/getproxyiplist/10');
$mc->add('http://im.qq.com/album/');
$re = $mc->run(4); //不使用回调，直接返回结果
print_r($re);
```

```php
<?php

use luojixinhao\mCurl;

$conf = array(
	'maxTry' => 2, //失败尝试次数
	'maxConcur' => 5, //最大并发数
);
$mc = new multiCurl($conf);
$mc->add('http://proxyip.9window.com/api/getproxyiplist/10', array(
	CURLOPT_USERAGENT => 'test',
), array(
	'arg1' => 'testArg'
), function($url, $content, $args, $header, $errorno, $error){
	//成功时的回调
	print_r(func_get_args());
}, function($url, $content, $args, $header, $errorno, $error){
	//失败时的回调
	print_r(func_get_args());
});
$mc->run();
```

```php
<?php

use luojixinhao\mCurl;

$mc = new multiCurl();
//先运行再动态添加
$mc->run(function() use ($mc) {
	$mc->add('http://proxyip.9window.com/api/getproxyiplist/10', null, null,
		function($url, $content, $args, $header, $errorno, $error) {
		//成功时的回调
		print_r(func_get_args());
	}, function($url, $content, $args, $header, $errorno, $error) {
		//失败时的回调
		print_r(func_get_args());
	});
});
```

```php
<?php

use luojixinhao\mCurl;

$mc = new multiCurl();
//不停采集某个地址，当采集大于10次后停止
$mc->run(function($mc) {
	$mc->add('http://im.qq.com/album/', null, null,
		function($url, $content, $args, $header, $errorno, $error) {
		//成功时的回调
		print_r(func_get_args());
	}, function($url, $content, $args, $header, $errorno, $error) {
		//失败时的回调
		print_r(func_get_args());
	});

	if ($mc->getInfos('finishNum') >= 10) {
		return false;
	}
	return true;
});
```