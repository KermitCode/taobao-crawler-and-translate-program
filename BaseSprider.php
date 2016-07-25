<?php

/*
 *Author:Kermit
 *Time:2014-10-30
 *Note:数据抓取程序的类接口程序,因淘宝搜索页源代码全部改变
      :后期修改淘宝搜索页、产品详情页、翻译页的处理都调用此基类
 */
error_reporting(E_ALL);
!defined('DB_PREFIX') && define('DB_PREFIX','oc_');
abstract class BaseSprider{
	
	public $config=array();
	public $debug=true;				//开发测试模式
	private $proxy=false;
	private static $_curlObject = FALSE;
	private $curlUseMulti=true;
	public $logArr=array();
	public $st=0;
	private $index=0;
	public $maxredirect=20;
	public $costTime=NULL;
	public $db=NULL;
	public $redis=NULL;

	public function __construct($proxy=false,$curlUseMulti=true){	
		$this->curlUseMulti=$curlUseMulti;
		!$this->st && $this->st=microtime(true);
		$proxy && $this->proxy=$proxy;
	}
	
	public function __set($key,$value){
		$this->config[$key]=$value;
	}
	
	public function add($key,$data=''){
		if(is_array($key)){
			foreach($key as $k=>$v){
				if(is_array($v)) $this->add($v);
				else $this->$k=$v;
			}
		}else{
			$this->$k=$data;	
		}
	}
	
	public function showTest($data,$stop=true){
		header("Content-type:text/html;charset=utf-8");
		echo '<pre>';
		if($this->debug){
			$this->logArr['costTime']=round((microtime(true)-$this->st),3).'s';
			print_r($this->logArr);
		}
		print_r($data);
		echo'</pre>';
		if($stop) exit;
	}
	
	public function showError($fi,$fu,$li,$message=''){
		
		$mess_arr=array('ErrorHappened'=>array(
			'FilePos'=>$fi,
			'Function'=>'function '.$fu.'(){...}',
			'LineNumber'=>$li.' line.'
			));
		$message && $mess_arr['message']=$message;	
		$this->showTest($mess_arr);
		
	}
		
	//生成地址
	public function makeUrl($baseurl,$parmas){
		return $baseurl.http_build_query($parmas);
	}

	//生成curl
	public function getCurl($path,$referer){

	  if(gettype(self::$_curlObject) != 'resource') {		
		$ch = curl_init($path);
		self::$_curlObject = $ch;
	  }else{
		$ch = self::$_curlObject;
		@curl_setopt($ch,CURLOPT_URL,$path);
	  }
	  
	  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
	  curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
	  curl_setopt($ch, CURLOPT_ENCODING, '');
	  curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
	  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	  curl_setopt($ch, CURLOPT_TIMEOUT, 60);//120
	  curl_setopt($ch, CURLOPT_FORBID_REUSE, FALSE);
	  curl_setopt($ch, CURLOPT_NOPROGRESS, TRUE);
	  curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 3600);
	  curl_setopt($ch, CURLOPT_REFERER,$referer);
	  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Connection: keep-alive","Keep-Alive: 30")); 

	  if($this->proxy!='') {
		  curl_setopt($ch, CURLOPT_PROXYTYPE,CURLPROXY_HTTP);
		  curl_setopt($ch, CURLOPT_PROXY,$this->proxy);
	  }
	  
	  return $ch;
	
   }	

	//注销curl资源
	public function closeCurl() {
		
		if(gettype(self::$_curlObject) == 'resource') curl_close(self::$_curlObject);
		return true;
  	
	}
	
	//抓取单个页面
	public function curlGetNoRedirect($url,$referer){
		
		$ch=$this->getCurl($url,$referer);
		#curl_setopt($ch, CURLOPT_MAXREDIRS,20);
		#curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);
		$ret=curl_exec($ch);
		$curl_info=curl_getinfo($ch);		
		$this->debug && $this->logArr['curllog']['curl_'.$this->index][]=$url;
		if($curl_info['http_code']>=400) $ret='';
		else{
			$ret=@iconv('GBK','UTF-8//IGNORE',$ret);
			$this->debug &&$this->logArr['curllog']['curl_'.$this->index][]=$curl_info['total_time'];
		}
		$this->debug && $this->index++;
		return $ret;
		
	}
	
	 //将Unicode编码转换成可以浏览的utf-8编码	
	public function unicode_decode($name){

		 $pattern = '/([\w]+)|(\\\u([\w]{4}))/i';
		 preg_match_all($pattern,$name,$matches);
		 
		 if(!empty($matches)){
			  $name = '';
			  for($j = 0; $j<count($matches[0]);$j++){
			   
				   $str = $matches[0][$j];
				   if(strpos($str, '\\u') === 0){

					$code = base_convert(substr($str, 2, 2), 16, 10);
					$code2 = base_convert(substr($str, 4), 16, 10);
					$c = chr($code).chr($code2);
					$c = iconv('UCS-2', 'UTF-8', $c);
					$name .= $c;

				   }else $name .= $str;
			  }
		 }
		 return $name;
	
	}

   //同时抓取多页面
   public function getHttpDocumentArray($urls,$direct = FALSE,$referer='http://item.taobao.com/') {
	
	 	$multi = curl_multi_init();
	  
	  	$channels = array();
	  
	  	if(!isset($this->logArr['multicullog'][0])) $multi_index=0;
	  
	 	 else $multi_index++;

	 	 foreach ($urls as $url) {
		
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
			curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
			curl_setopt($ch, CURLOPT_ENCODING, ''); 
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_TIMEOUT, 120);
			curl_setopt($ch, CURLOPT_FORBID_REUSE, FALSE);
			curl_setopt($ch, CURLOPT_NOPROGRESS, TRUE);
			curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 3600);
			curl_setopt($ch, CURLOPT_REFERER, $referer);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
			
			if($this->proxy) curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
			
			curl_multi_add_handle($multi, $ch);
			
			$channels[$url] = $ch;
			
			$this->logArr['multicullog'][$multi_index][]=$url;
			
			$multi++;
		
	  	}//end for

		$active = null;
		
		do {
			
			$mrc = curl_multi_exec($multi, $active);
		
		}while ($active>0);
	
		while ($active && $mrc == CURLM_OK) {
		 
		  if (curl_multi_select($multi) == -1) {
			
			continue;
		  }

		  do {
			  
			$mrc = curl_multi_exec($multi, $active);
			
		  } while ($active>0);
		  
		}
	
		$results=array();
		
		foreach ($channels as $i=>$channel) {
		  
		  $results[$i]= new stdClass();
		  
		  $results[$i]->info=curl_getinfo($channel);
		  
		  $ret=curl_multi_getcontent($channel);
		 
		  curl_multi_remove_handle($multi, $channel);
		  
		  if ($direct || ($results[$i]->info['http_code'] >= 400)) {
			
			if (isset($ret)) {$results[$i]->data = $ret;
			
			}else{ $results[$i]->data = FALSE;}
		  
		  }else {
			
			$results[$i]->data = @iconv('GBK', 'UTF-8//IGNORE', $ret);
		  
		  }
		  
		  unset ($ret);
		
		}

		curl_multi_close($multi);
		
		return $results;
  
    }
	
	
	
	

}