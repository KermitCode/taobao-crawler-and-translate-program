<?php

/*
 *Kermit:
 *Note:淘宝、天猫产品页数据抓取
 *Note:添加登录抓取淘宝
 */

#基本设置
error_reporting(E_ALL);
date_default_timezone_set('PRC');
ini_set('display_errors',1);

#数据抓取类
class TbData
{	
	public $extend_path;			#正则规则的文件目录
	public $autoRedirect=1;			#自动跳转
	public $maxredirect=20;			#最大跳转次数
	public $proxy=false;			#抓取代理
	public $debug=true;				#是否记录抓取日志
	public $logArr;					#抓取日志
	private static $_curlObject = FALSE;
	
	#淘宝登录账号密码
	private $taobao_username='kermit';
	private $taobao_password='*****';
	private $cookie_file='./cookie.txt';
	
	#其它数据
	public $referer='http://item.taobao.com/';
	public $tid;
	public $type='item.taobao.com';
	public $itemParams;
	public $curlUseMulti=true;
	public $postageArea='990000';
	public $RuleXml;
	public $DSGItemRes;
	public $fromCart=false;
	
######------1,基础类方法------#####################################

	#实例化抓取类
	public function __construct($proxy=false)
	{
		$this->extend_path=dirname(__FILE__).'/';
		if($proxy) $this->proxy=$proxy;
	}
	
	#测试展示	
	public function showTest($data,$stop=true)
	{
		header("Content-type:text/html;charset=utf-8");
		print_r('<pre>');print_r($data);print_r('</pre>');
		if($stop) exit;
	}

######------2,模拟登录------#####################################
	
	#模拟登录
	public function autoLogin($code='',$id='')
	{ 
		#获取登录框中的淘宝验证数据
		$text=trim(file_get_contents('https://login.taobao.com/member/login.jhtml'));
		$text=iconv('gbk','utf-8',trim($text));	
		
		#提取要发送的参数
		$rs=preg_match_all('/<input\s*type="hidden"[^>]*name="([^"]*)"[^>]*value="([^"]*)"[^>]*\/>/s',$text,$match);		
		$sdata=array();
		foreach($match[1] as $t=>$skey)
		{
			$sdata[$skey]=$match[2][$t];
		}
		
		#提取URL
		$rs=preg_match('/<form action="\/member([^"]*)"/s',$text,$match);
		$url='https://login.taobao.com/member'.$match[1];
		
		#加入淘宝登录账号
		$sdata['tid']='';
		$sdata['TPL_username']=$this->taobao_username;
		$sdata['TPL_password']=$this->taobao_password;
		$sdata['naviVer']='';
		$sdata['poy']=''; 
		$sdata['TPL_password_2']='';       
		$sdata['oslanguage']='';
		$sdata['sr']='';
		$sdata['osVer']='';
		if($code) $sdata['TPL_checkcode']=$code;

		#组装发送数据
		$char='';
		foreach($sdata as $k=>$v) $char.="{$k}={$v}&";
		$char=rtrim($char,'&');
		
		#执行页面登录、返回需要输入验证码
		$res=$this->vlogin($url,$char);
		
		#提取验证码图片、呈现出提交验证码的表单
		$rs=preg_match('/data-src="([^"]*)"/s',$res,$match);
		if($rs){
			echo "<html><body>
					<form action='http://127.0.0.1/newTbInterFace/TbData.php' method='get' >
						<input type='text' name='code'>&nbsp;
						<input type='hidden' name='id' value='{$id}'>&nbsp;
						<img src='{$match[1]}'>
						<input type='submit'>
					</form>
				  </body></html>";
			exit;
		}
		
		$this->getData(array('id'=>$id));
	}
	
	#执行登录
	public function vlogin($url,$data)
	{
        $curl = curl_init(); 
        curl_setopt($curl, CURLOPT_URL, $url); 
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_REFERER, 'https://login.taobao.com/member/login.jhtml');
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookie_file);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $tmpInfo = curl_exec($curl);
        if (curl_errno($curl))
		{
            echo 'Errno' . curl_error($curl);exit;
        }
		return $tmpInfo;
    }

######------3,页面抓取------#####################################	
	
	#执行页面数据抓取
	public function getData($params){

		#产品ID参数必须
		if(!$this->tid=$id=$params['id']) return false;
		$this->itemParams=$this->getDSGRules();
		
		#获取第一次抓取详情页的数据
		$ret=$this->getItemPageChar();

		#对抓取的数据进行分析、如存在登录框，则进行账与登录、手动输入验证码后跳转至抓取页面
		if(strpos($ret,'id="J_LoginBox"')!==false)
		{
			#进入登录界面并传递ID值
			$this->autoLogin('',$id);
		}

		#对最终页面进行抓取
		$data = new stdClass();
		$data->data=@iconv('GBK','UTF-8//IGNORE',$ret);
		$ret=NULL;
		$data->url=$this->itemParams->url;	
		$data->isTmall=!(bool)$this->regexMatch('loaded_result_is_fake',$data->data);
        if(($this->type == 'item.taobao.com') && $data->isTmall){
			$this->type='detail.tmall.com';
			$this->referer='http://detail.tmall.com';
            $this->itemParams=$this->getDSGRules();
		}
        $this->DSGItemRes=new stdClass();
        $this->DSGItemRes->html=$data;
		$this->DSGItemRes->item=new stdClass();
		$this->DSGItemRes->item->isTmall=$data->isTmall;     
		if($data->isTmall) $this->parseTmall($data);
		else $this->parseTaobao($data);
		
		#标题重新获取
		$rs=preg_match('/"title":"(.+?)"/s',$this->DSGItemRes->html->data,$match);
		$rs && $this->DSGItemRes->item->title=$match[1];
		
		$this->DSGItemRes->html=NULL;
        function orderSKUs($a, $b){
			$a_val = $a->url;$b_val = $b->url;
			if ($a_val > $b_val) return -1;
			elseif ($a_val == $b_val) return 0;
			else return 1;
        }
        if(isset($this->DSGItemRes->item->props)) {
		    foreach ($this->DSGItemRes->item->props as $prop) {
			    if (isset($prop->childs)) uasort($prop->childs, "orderSKUs");
            }
		}
		#商家页面地区可能不展示，此时从详情页中抓取配送数据
		if(!isset($this->DSGItemRes->rate)) $this->DSGItemRes->rate=new stdClass();
		if(!isset($this->DSGItemRes->rate->diqu) || $this->DSGItemRes->rate->diqu==''){
			$res=preg_match('/location:\'(.*)\',/u',$this->DSGItemRes->item->truePostage,$resstr);
			$this->DSGItemRes->rate->diqu=$res?$resstr[1]:'';
			}		
		return $this->DSGItemRes;
	}
	
	#获取详情页的数据
	public function getItemPageChar()
	{
		#读取系统配置			
		$open_basedir=ini_get('open_basedir');
		$safe_mode=ini_get('safe_mode');
	
		#获取curl资源
		$ch=$this->getCurl($this->itemParams->url,$this->referer);
		
		#加进COOKIES数据
		curl_setopt($ch,CURLOPT_COOKIEJAR, $this->cookie_file); // 存放Cookie信息的文件名称
		curl_setopt($ch,CURLOPT_COOKIEFILE,$this->cookie_file); // 读取上面所储存的Cookie信息

		#执行数据抓取
	    if($this->autoRedirect && (($open_basedir=='') && (in_array($safe_mode, array('0', 'Off'))) || !$safe_mode))
		{
			#自动跳转抓取
			$newurl=$this->itemParams->url;
			echo $newurl;exit;
			curl_setopt($ch, CURLOPT_MAXREDIRS,$this->maxredirect);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		}else{
        	curl_setopt($ch,CURLOPT_FOLLOWLOCATION,FALSE);
			$mr=$this->maxredirect;
			#手动跳转抓取
			if($mr>0)
			{
				  $newurl=curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
				  $originalUrl=$newurl;
				  $rch = curl_copy_handle($ch);
				  curl_setopt($rch, CURLOPT_HEADER, TRUE);
				  curl_setopt($rch, CURLOPT_NOBODY, TRUE);
				  do{
						curl_setopt($rch,CURLOPT_URL,$newurl);
						
						#抓取时间标记
						$this->debug && $this->logArr[$newurl]['ctime']=microtime(true);
						$header=curl_exec($rch);
						$this->debug && $this->logArr[$newurl]['ctime']=round(microtime(true)-$this->logArr[$newurl]['ctime'],3);
						if(curl_errno($rch)) $code=0;
						else
						{
							  $code = curl_getinfo($rch,CURLINFO_HTTP_CODE);
							  if($code == 301 || $code == 302)
							  {
									preg_match('/Location:(.*?)[\n\r]/i',$header,$matches);
									$url = trim(array_pop($matches));
									if(preg_match('/http[s]*:\/\/.+?(?=\/)/i', $url))
									{
										  $newurl = $url;
									}
									else
									{
										  preg_match('/http[s]*:\/\/.+?(?=\/)/i', $newurl, $m);
										  $newurl = trim(array_pop($m)).$url;
									}
							  }else $code = 0;
						}
						
						
				  }while($code && --$mr);
				  curl_setopt($ch,CURLOPT_URL,$newurl);
			}
	 	}

		#最终页面抓取
		$this->debug && $this->logArr[$newurl.'______curl']['ctime']=microtime(true);
		$ret=curl_exec($ch);
		$this->debug && $this->logArr[$newurl.'______curl']['ctime']=round(microtime(true)-$this->logArr[$newurl.'______curl']['ctime'],3);
		return $ret;
	}
	
	#获取CURL对象
	public function getCurl($path,$referer=''){
		  $ch=curl_init();
		  curl_setopt($ch, CURLOPT_URL,$path);
		  curl_setopt($ch, CURLOPT_REFERER,$referer);
		  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
		  curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
		  curl_setopt($ch, CURLOPT_ENCODING, '');
		  curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		  curl_setopt($ch, CURLOPT_FORBID_REUSE, FALSE);
		  curl_setopt($ch, CURLOPT_NOPROGRESS, TRUE);
		  curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT,3600);
		  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Connection: keep-alive","Keep-Alive: 30")); 
		  if($this->proxy!='') {
			  curl_setopt($ch, CURLOPT_PROXYTYPE,CURLPROXY_HTTP);
			  curl_setopt($ch, CURLOPT_PROXY,$this->proxy);
		  }
		  self::$_curlObject=$ch;
		  return $ch;
   }	
   
   	#加载解析XML正则文件
	public function getDSGRules($queryParams=array(),$xmlfile=true)
	{
		#加载XML文件
		$xml=simplexml_load_string(file_get_contents($this->extend_path.'DSG_rulesList.xml'),NULL,LIBXML_NOCDATA);
		
		#提取当前type的正则规则
		$search=$xml->xpath("/parser_sections/parser_section[name='{$this->type}']");
	    $this->RuleXml=$search[0];
		
		#当前抓取的参数设定
		$result=new stdClass();
		$result->debug=$this->getDSGRule('debug')=='true';
		$result->type=$this->getDSGRule('type');
		$result->url=$this->getDSGRule('base_url').http_build_query(array('id'=>$this->tid)).'#detail';
		return $result;
	}
	
	#提取正则对象中的值
	public function getDSGRule($xpath)
	{
		$xml=$this->RuleXml->xpath($xpath);
		if($xml)
		{
			if (count($xml) > 1 || count($xml[0]) > 1) $res = $xml;
		 	else $res = trim((string) ($xml[0]));
			return $res;
		}
		else return FALSE;
	}
	
	public function regexMatch($ruleXPath,$subject,array &$matches = array(), $flags = 0, $offset = 0) {
		$rule = $this->getDSGRule($ruleXPath);
		ini_set('pcre.backtrack_limit', 4*1024*1024);
		ini_set('pcre.recursion_limit', 1024*1024);
		$res=preg_match($rule,$subject,$matches,$flags,$offset);
		return $res;
	}
	
    public function getHttpDocument($path,$urlPreserved = FALSE, $direct = FALSE,$referer='http://item.taobao.com/') {
		$ch=$this->getCurl($path,$referer);
		$ret=curl_exec($ch);
		$res = new stdClass();
		$res->info = curl_getinfo($ch);
		if ($res->info['http_code'] >= 400) $res->data = FALSE;
		else $res->data = @iconv('GBK', 'UTF-8//IGNORE', $ret);
		$ret=NULL;
		return $res;
   }

   public function getHttpDocumentArray($urls,$direct = FALSE,$referer='http://item.taobao.com/') {
	 	$multi = curl_multi_init();
	  	$channels = array();
	  	 if(!isset($this->logArr['multicullog'][0])) $multi_index=0;
	 	 else $multi_index++;
		 $this->debug && $this->logArr['multicullog'][$multi_index]['ctime']=microtime(true);
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
			$this->debug && $this->logArr['multicullog'][$multi_index][]=$url;
			$multi++;
	  	}
		$active = null;
		do {
			$mrc = curl_multi_exec($multi, $active);
		}while ($active>0);
		while ($active && $mrc == CURLM_OK) {
		  if (curl_multi_select($multi) == -1) {
			continue;
		  }
		  do{
			$mrc = curl_multi_exec($multi, $active);
		  } while ($active>0); 
		}
		$results=array();
		$this->debug && $this->logArr['multicullog'][$multi_index]['ctime']=round((microtime(true)-$this->logArr['multicullog'][$multi_index]['ctime']),3);
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

	public function JavaScriptToJSON($input, $asObject = FALSE) {
		if (($input == FALSE) || ($input == '')) return new stdClass();
		ini_set('pcre.backtrack_limit', 4*1024*1024);
		ini_set('pcre.recursion_limit', 1024*1024);
		if ($input{0} != '{') {
		  $res = preg_replace('/^.*?(?={)/s', '', $input);
		}else{
		  $res = $input;
		}
		$res = preg_replace('/[^}]*?$/s','', $res);
		$res = preg_replace('/(?<=[\s,{])([a-z0-9\-_]+)\s*:(?!\d{2}:\d{2})/i', '"\1":', $res);
		$res = str_replace("'", '"', $res);
		$res = preg_replace('/("\s*:\s*)(?=[,}])/s', '\1""', $res);
		$cnt = preg_match_all('/(?::\s*")(.*?)(?:"\s*[,}])/', $res, $matches);
		if ($cnt > 0) {
		  foreach ($matches[1] as $src) {
			if (strpos($src, '"') === FALSE) continue;
			$res = str_replace($src, str_replace('"', '\"', $src), $res);
		  }
		}
		if (!$asObject) {
		  return $res;
		}else {
		  $resJSON = json_decode($res);
		  $err = json_last_error();
		  switch ($err) {
			case JSON_ERROR_NONE:
			  return $resJSON;
			  break;
			
			default:
			  return new stdClass();
			  break;
		  }
		}
	}
	
	public function regexMatchAll($ruleXPath,$subject,array &$matches = array(),$flags = 0, $offset = 0) {
		$rule = $this->getDSGRule($ruleXPath);
		ini_set('pcre.backtrack_limit', 4*1024*1024);
		ini_set('pcre.recursion_limit', 1024*1024);
		$res = preg_match_all($rule, $subject, $matches, $flags, $offset);
		if ($res===false && $this->debug) {$this->showError(__FILE__,__FUNCTION__,__LINE__,preg_last_error());}
		return $res;
  	}
	
	public function getObjPropValDef($stringPath, $defVal){
		if (isset($stringPath)) {
		  return $stringPath;
		}else {
		  return $defVal;
		}
  	}
	
	private function parseTaobao($data){
        $resstr = array();
        $res = $this->regexMatch('parse_item_values/user_rate_url', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->userRateUrl = $resstr[0];
        }
		#抓取商家页面
        $ch=$this->getCurl($this->DSGItemRes->item->userRateUrl,$this->referer);
        $mr=$this->maxredirect;
		$open_basedir = ini_get('open_basedir');
		$safe_mode = ini_get('safe_mode');
	    if(($open_basedir == '') && (in_array($safe_mode, array('0', 'Off'))||!$safe_mode)) {
			curl_setopt($ch, CURLOPT_MAXREDIRS,$mr);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION,$mr>0);
		}else{
        	curl_setopt($ch,CURLOPT_FOLLOWLOCATION,FALSE);
			if($mr>0){ 
				  $newurl=curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
				  $originalUrl=$newurl;
				  $rch = curl_copy_handle($ch);
				  curl_setopt($rch, CURLOPT_HEADER, TRUE);
				  curl_setopt($rch, CURLOPT_NOBODY, TRUE);
				  curl_setopt($rch, CURLOPT_FORBID_REUSE, FALSE);
				  curl_setopt($rch, CURLOPT_RETURNTRANSFER, TRUE);
				  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
				  do{
					curl_setopt($rch,CURLOPT_URL,$newurl);
					$mr!=$this->maxredirect && @$this->logArr['curllog'][]=$newurl;
					$header=curl_exec($rch);
					if(curl_errno($rch)) $code=0;
					else{ 
					  $code = curl_getinfo($rch, CURLINFO_HTTP_CODE);
					  if($code == 301 || $code == 302){
						preg_match('/Location:(.*?)[\n\r]/i',$header,$matches);
						$url = trim(array_pop($matches));
						if (preg_match('/http[s]*:\/\/.+?(?=\/)/i', $url)) {
						  $newurl = $url;
						}else{
						  preg_match('/http[s]*:\/\/.+?(?=\/)/i', $newurl, $m);
						  $newurl = trim(array_pop($m)) . $url;
						}
					  }else $code = 0;
					}
					
				  }while($code && --$mr);
				  curl_close($rch);
				  if(!$mr && $this->debug) $this->showError(__FILE__,__FUNCTION__,__LINE__,'redirects exceed '.$this->maxredirect.'. Result:'.E_USER_WARNING);
				  curl_setopt($ch, CURLOPT_URL, $newurl);
			}
	 	}

		$ret=curl_exec($ch);
		$data->rate=@iconv('GBK', 'UTF-8//IGNORE', $ret);
		$this->DSGItemRes->rate = new stdClass();
		$res = preg_match('/data-tnick\s*=\s*"(.+?)"/i', $data->rate, $resstr);
        if ($res > 0){$this->DSGItemRes->rate->nick = urldecode($resstr[1]);
        }else{$this->DSGItemRes->rate->nick ='';}
		
		#2014-11-1 kermit
		$res = preg_match('/\<div class="frame"\>\s*\<div class="list"\>卖家信用：(\d+?)\s*<a href="/s',$data->rate,$resstr);
        $this->DSGItemRes->rate->xinyong_fen=$res>0?urldecode($resstr[1]):'';
		$res = preg_match('/src="http:\/\/pics.taobaocdn.com\/newrank\/(.+?).gif"/u', $data->rate, $resstr);
        $this->DSGItemRes->rate->xinyong_star=$res>0?urldecode($resstr[1]):'';
		$res = preg_match('/\<li\>\s*所在地区\s*：\s*(.+?)\s*\<\/li\>/u', $data->rate, $resstr);
        $this->DSGItemRes->rate->diqu=$res>0?trim(str_replace('&nbsp;','',urldecode($resstr[1]))):'';
		$res = preg_match('/共\<span\>(\d*)\<\/span\>人/u',$data->rate,$resstr);
        $this->DSGItemRes->rate->point_number=$res>0?urldecode($resstr[1]):'';
		$res = preg_match('/宝贝与描述相符：\<\/span\>\s*\<em title="(\d*\.?\d*)分"/s',$data->rate,$resstr);
        $this->DSGItemRes->rate->point_miaosho=$res>0?urldecode($resstr[1]):'';
		$res = preg_match('/卖家的服务态度：\<\/span\>\s*\<em title="(\d*\.?\d*)分"/s',$data->rate,$resstr);
        $this->DSGItemRes->rate->point_fuwu=$res>0?urldecode($resstr[1]):'';
		$res = preg_match('/卖家发货的速度：\<\/span\>\s*\<em title="(\d*\.?\d*)分"/s',$data->rate,$resstr);
        $this->DSGItemRes->rate->point_fahuo=$res>0?urldecode($resstr[1]):'';
		$res = preg_match('/\<em style="color:gray;"\>好评率：(\d*\.\d*%)\<\/em\>/u',$data->rate,$resstr);
        $this->DSGItemRes->rate->haopinglv=$res>0?urldecode($resstr[1]):'';
		$res = preg_match('/id="\s*J_showShopStartDate\s*"\s*value="([\d\-]+?)"\s*\/>/u', $data->rate, $resstr);
		$this->DSGItemRes->rate->starttime=$res>0?urldecode($resstr[1]):'';
		$this->DSGItemRes->rate->shoptype='taobao';
        $res = preg_match_all('/dsr-item\s*"\s*\>(.+?)\s*\<\/li\>/u', $data->rate, $resstr);
		$this->DSGItemRes->rate->point=$res>0?urldecode($resstr[1]):'';

        $resstr = array();
        $res = $this->regexMatch('parse_item_values/cid', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->cid = (string) $resstr[1];
        } else {$this->DSGItemRes->item->cid = '0';}
        $res = $this->regexMatch('parse_item_values/num_iid', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->num_iid = (string) $resstr[1];
        } else {$this->DSGItemRes->item->num_iid = '0';}
        $res = $this->regexMatch('parse_item_values/seller_id', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->seller_id = (string) $resstr[1];
        } else {$this->DSGItemRes->item->seller_id = '0';}
        $res = $this->regexMatch('parse_item_values/nick', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->nick = (string) $resstr[1];
        } else {$this->DSGItemRes->item->nick = '';}
        $res = $this->regexMatch('parse_item_values/shop_id', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->shop_id = (string) $resstr[1];
        } else {$this->DSGItemRes->item->shop_id = '0';}
        $res = $this->regexMatch('parse_item_values/title', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->title = (string) $resstr[1];
        } else {$this->DSGItemRes->item->title = '';}
        $res = $this->regexMatch('parse_item_values/pic_url', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->pic_url = (string) $resstr[1];
        } else {$this->DSGItemRes->item->pic_url = '';}
        $res = $this->regexMatch('parse_item_values/price', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->price = (float) $resstr[1];
        } else {$this->DSGItemRes->item->price = 0;}
        $res = $this->regexMatch('parse_item_values/block_sib/_url', $data->data, $resstr);
        if ($res <= 0) {
            $this->DSGItemRes->item->num = 0;
            $this->DSGItemRes->item->location = '';
            $this->DSGItemRes->item->post_fee = 0;
            $this->DSGItemRes->item->express_fee = 0;
            $this->DSGItemRes->item->ems_fee = 0;
            $this->DSGItemRes->item->sku_data = new stdClass();
        }else {
            $sib_data = $this->getHttpDocument(
              $resstr[1] . '&ref=' . urldecode('http://item.taobao.com/item.htm?id=' . $this->DSGItemRes->item->num_iid),
			  false,false,'http://item.taobao.com/item.htm?id=' . $this->DSGItemRes->item->num_iid);
            $res = $this->regexMatch('parse_item_values/block_sib/num', $sib_data->data, $resstr);
            if ($res > 0) {$this->DSGItemRes->item->num = $resstr[1];
            } else {$this->DSGItemRes->item->num = 0;}
            $res = $this->regexMatch('parse_item_values/block_sib/location', $sib_data->data, $resstr);
            if ($res > 0) {$this->DSGItemRes->item->location = $resstr[1];
            } else {$this->DSGItemRes->item->location = '';}
            $res = $this->regexMatch('parse_item_values/block_sib/post_fee', $sib_data->data, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {$this->DSGItemRes->item->post_fee = (float) $resstr[1];
                }else {$this->DSGItemRes->item->post_fee = 0;
                }
            } else {$this->DSGItemRes->item->post_fee = 0;}
            $res = $this->regexMatch('parse_item_values/block_sib/express_fee', $sib_data->data, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {$this->DSGItemRes->item->express_fee = (float) $resstr[1];
                } else {$this->DSGItemRes->item->express_fee = 0;}
            } else {$this->DSGItemRes->item->express_fee = 0;}
            $res = $this->regexMatch('parse_item_values/block_sib/ems_fee', $sib_data->data, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {$this->DSGItemRes->item->ems_fee = (float) $resstr[1];
                } else {$this->DSGItemRes->item->ems_fee = 0;}
            } else {$this->DSGItemRes->item->ems_fee = 0;}
            if ($this->DSGItemRes->item->express_fee == 0) {
                $this->DSGItemRes->item->express_fee = max(
                  array(
                    $this->DSGItemRes->item->post_fee,
                    $this->DSGItemRes->item->express_fee
                  )
                );
            }
            $res = $this->regexMatch('parse_item_values/block_sib/sku_promotions', $sib_data->data, $resstr);
            if ($res > 0) {$this->DSGItemRes->item->sku_promotions = $this->JavaScriptToJSON('{' . $resstr[1], true);}
            $res = $this->regexMatch('parse_item_values/block_sib/sku_data', $sib_data->data, $resstr);
            if ($res > 0) {$this->DSGItemRes->item->sku_data = $this->JavaScriptToJSON('{' . $resstr[1], true);}
            unset($sib_data);
        }
        $this->DSGItemRes->item->item_imgs = new stdClass();
        $this->DSGItemRes->item->item_imgs->item_img = array();
        $res = $this->regexMatch('parse_item_values/item_imgs/block', $data->data, $resstr);
        if ($res > 0) {
            $item_imgs_data = $resstr[1];
            $res = $this->regexMatchAll('parse_item_values/item_imgs/url', $item_imgs_data, $resstr);
            unset($item_imgs_data);
            if ($res > 0) {
                foreach ($resstr[1] as $i => $item_img) {
                    $this->DSGItemRes->item->item_imgs->item_img[$i] = new stdClass();
                    $this->DSGItemRes->item->item_imgs->item_img[$i]->id = $i;
                    $this->DSGItemRes->item->item_imgs->item_img[$i]->position = $i;
                    $this->DSGItemRes->item->item_imgs->item_img[$i]->url = $item_img;
                }
                unset($item_img);
            }
        }
        $this->DSGItemRes->item->item_attributes = array();
        $res = $this->regexMatch('parse_item_values/item_attributes/block', $data->data, $resstr);
        if ($res > 0) {
            $attributes_data = $resstr[1];
            $res = $this->regexMatchAll('parse_item_values/item_attributes/propvallist', $attributes_data, $resstr);
            if ($res > 0) {
                foreach ($resstr[1] as $i => $item_attr) {
                    $this->DSGItemRes->item->item_attributes[$i] = new stdClass();
                    $this->DSGItemRes->item->item_attributes[$i]->prop = html_entity_decode($resstr[1][$i],ENT_COMPAT,'UTF-8');
                    $this->DSGItemRes->item->item_attributes[$i]->val = html_entity_decode($resstr[2][$i],ENT_COMPAT,'UTF-8');
                }
                unset($item_attr);
            }
            unset($attributes_data);
        }
        $this->DSGItemRes->item->props = array();
        $res=$this->regexMatch('parse_item_values/prop_imgs/block', $data->data, $resstr);
        if($res>0){
            $propresstr=array();
            $propres=$this->regexMatchAll('parse_item_values/prop_imgs/prop/block', $resstr[1], $propresstr);
            if ($propres>0){
                foreach ($propresstr[1] as $propblock) {
                    $propFinal = new stdClass();
                    $propblockresstr = array();
                    $propblockres = $this->regexMatch('parse_item_values/prop_imgs/prop/title',$propblock,$propblockresstr);
                    if ($propblockres > 0) {
                        $propFinal->cid = $this->DSGItemRes->item->cid;
                        $propFinal->name_zh = $propblockresstr[1];
                        $propFinal->name = $propblockresstr[1];
                        unset($propblockresstr);
                    } else {continue;}
                    $propimgresstr = array();
                    $propimgres = $this->regexMatchAll('parse_item_values/prop_imgs/prop/prop_img/block',$propblock,$propimgresstr);
                    if ($propimgres > 0) {
                        $propFinal->childs = array();
                        foreach ($propimgresstr[1] as $j => $propimgblock) {
                            $resstr0 = array();
                            $res0 = $this->regexMatch('parse_item_values/prop_imgs/prop/prop_img/properties',$propimgblock,$resstr0);
                            if ($res0 > 0) {
                                $propFinal->childs[$j] = new stdClass();
                                $pidvid = explode(':', $resstr0[1]);
                                if (!isset($pidvid[0]) || !isset($pidvid[1])){continue;}
                                $propFinal->childs[$j]->vid = $pidvid[1];
                            } else {continue;}
                            $resstr0 = array();
                            $res0 = $this->regexMatch('parse_item_values/prop_imgs/prop/prop_img/title',$propimgblock,$resstr0);
                            if ($res0 > 0) {
                                $propFinal->childs[$j]->name_zh = $resstr0[1];
                                $propFinal->childs[$j]->name = $resstr0[1];
                            } else {
                                $propFinal->childs[$j]->name_zh = '';
                                $propFinal->childs[$j]->name = '';
                            }
                            $resstr0 = array();
                            $res0 = $this->regexMatch('parse_item_values/prop_imgs/prop/prop_img/url',$propimgblock,$resstr0);
                            if ($res0 > 0) {$propFinal->childs[$j]->url = $resstr0[1];
                            }else {$propFinal->childs[$j]->url = '';}
                        }
                        unset($propimgblock);
                        unset($propimgresstr);
                    }
                    if (isset($pidvid)) {
                        $this->DSGItemRes->item->props[$pidvid[0]] = clone $propFinal;
                        unset($pidvid);
                        unset($propFinal);
                    }
                }
                unset($propblock);
                unset($propresstr);
            }
        }
        $res = $this->regexMatch('parse_item_values/skus/block', $data->data, $resstr);
        if ($res > 0) {
            $skudatajson = $this->JavaScriptToJSON($resstr[1], true);
            unset($data);
            if (!$this->fromCart) {
                if (isset($skudatajson->apiItemDesc)) {
                    $this->DSGItemRes->item->desc = false;
                    $this->DSGItemRes->item->descUrl = (string) $skudatajson->apiItemDesc;
                    unset($dldata);
                }
            }	
            if ($this->curlUseMulti) {
                $urls = array();
                if (!$this->fromCart) {
                    if (isset($skudatajson->valReviewsApi))  $urls[] = (string) $skudatajson->valReviewsApi;
                    if (isset($skudatajson->apiItemInfo)) $urls[] = (string) $skudatajson->apiItemInfo;
				    if (isset($skudatajson->apiRelateMarket)) {
                        $url = preg_replace('/appid=\d+/', 'appid=32', (string) $skudatajson->apiRelateMarket);
                        $url = preg_replace('/count=\d+/', 'count=4', $url);
                        $urls[] = $url;
                    }
                }
                $_truePostage='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
                $urls[] = $_truePostage;
                $downloads = $this->getHttpDocumentArray($urls);
                if (isset($skudatajson->valReviewsApi)) {
                    if (isset($downloads[(string) $skudatajson->valReviewsApi])) {
                        $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON(
                          preg_replace('/(:?,"babyRateJsonList"|,"rateListInfo").*(?=})/s','',$downloads[(string) $skudatajson->valReviewsApi]->data),true);
                    }
                }
                if (isset($skudatajson->apiItemInfo)) {
                    if (isset($downloads[(string) $skudatajson->apiItemInfo])) {
                        $this->DSGItemRes->item->apiItemInfo = $this->JavaScriptToJSON($downloads[(string) $skudatajson->apiItemInfo]->data,true);
                    }
                }
                if (isset($url)) {
                    if (isset($downloads[$url])) {
                        $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON($downloads[$url]->data,true);
                    }
                }
                if (isset($_truePostage) && isset($downloads[$_truePostage])) {
                    $this->DSGItemRes->item->truePostage = $downloads[$_truePostage]->data;
                }

            }else{
                if (!$this->fromCart) {
                    if (isset($skudatajson->valReviewsApi)) {
                        $dldata = $this->getHttpDocument((string) $skudatajson->valReviewsApi);
                        $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON(
                          preg_replace('/(:?,"babyRateJsonList"|,"rateListInfo").*(?=})/s', '', $dldata->data),true);
                    }
                }
                if (!$this->fromCart) {
                    if (isset($skudatajson->apiItemInfo)) {
                        $dldata = $this->getHttpDocument((string) $skudatajson->apiItemInfo);
                        $this->DSGItemRes->item->apiItemInfo = $this->JavaScriptToJSON($dldata->data, true);
                    }
                }
                if (!$this->fromCart) {
                    if (isset($skudatajson->apiRelateMarket)) {
                        $url = preg_replace('/appid=\d+/', 'appid=32', (string) $skudatajson->apiRelateMarket);
                        $url = preg_replace('/count=\d+/', 'count=32', $url);
                        $dldata = $this->getHttpDocument($url);
                        $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON($dldata->data, true);
                    }
                }
                $url ='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
				$dldata = $this->getHttpDocument($url);
                $this->DSGItemRes->item->truePostage = $dldata->data;
            }
            if (isset($this->DSGItemRes->item->truePostage)){
                $res = $this->regexMatch('parse_item_values/block_sib/express_fee', $this->DSGItemRes->item->truePostage, $resstr);
                if ($res > 0) {
                    if (!isset($resstr[2])) {$this->DSGItemRes->item->express_fee = (float) $resstr[1];
                    }else {$this->DSGItemRes->item->express_fee = 0;}
                }else{$this->DSGItemRes->item->express_fee = 0;}
                $res = $this->regexMatch('parse_item_values/block_sib/ems_fee', $this->DSGItemRes->item->truePostage, $resstr);
                if($res>0){
                    if(!isset($resstr[2])) {$this->DSGItemRes->item->ems_fee = (float) $resstr[1];
                    }else{$this->DSGItemRes->item->ems_fee = 0;}
                }else{$this->DSGItemRes->item->ems_fee = 0;}
                if ($this->DSGItemRes->item->express_fee == 0) {
                    $this->DSGItemRes->item->express_fee = max(
                      array(
                        $this->DSGItemRes->item->post_fee,
                        $this->DSGItemRes->item->express_fee
                      )
                    );
                }
            }
            if (isset($this->DSGItemRes->item->sku_data->sku)) {
                $skuFromSIB = $this->DSGItemRes->item->sku_data->sku;
                unset($this->DSGItemRes->item->sku_data);
            }
            $this->DSGItemRes->item->promotion_price = $this->DSGItemRes->item->price;
            if (isset($skudatajson->valItemInfo)) {
                if (isset($skudatajson->valItemInfo->skuMap)) {
                    $this->DSGItemRes->item->skus = new stdClass();
                    $srcSkuArray = (array) $skudatajson->valItemInfo->skuMap;
                    $this->DSGItemRes->item->skus->sku = array();
                    foreach ($srcSkuArray as $name => $val) {
                        $this->DSGItemRes->item->skus->sku[] = new stdClass();
                        end($this->DSGItemRes->item->skus->sku)->price = (float) $val->price;
                        end($this->DSGItemRes->item->skus->sku)->properties = trim($name, ';');
                        end($this->DSGItemRes->item->skus->sku)->properties_name = ''; // ???
                        if (isset($skuFromSIB)) {
                            if (isset($skuFromSIB->{$name})){end($this->DSGItemRes->item->skus->sku)->quantity = $skuFromSIB->{$name}->stock;
                            }else{end($this->DSGItemRes->item->skus->sku)->quantity = $val->stock;}
                        }else{end($this->DSGItemRes->item->skus->sku)->quantity = $val->stock;}
                        if (end($this->DSGItemRes->item->skus->sku)->quantity == 99){end($this->DSGItemRes->item->skus->sku)->quantity=0;}
                        end($this->DSGItemRes->item->skus->sku)->sku_id=$val->skuId;
                        if (isset($this->DSGItemRes->item->sku_promotions->{$name})) {
                            $promotions = $this->DSGItemRes->item->sku_promotions->{$name};
                            $PromotionPriceArray = Array();
                            foreach ($promotions as $promotion) {
                                if (isset($promotion->price)) {
                                    if ($promotion->price>0){$PromotionPriceArray[] = (float) $promotion->price;}
                                }
                            }
                            if (count($PromotionPriceArray) > 0) {$promotion_price = min($PromotionPriceArray);
                            } else {$promotion_price = 0;}
                            if ($promotion_price > 0) {end($this->DSGItemRes->item->skus->sku)->promotion_price = $promotion_price;
                            } else {end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;}
                        } elseif (isset($val->specPrice)) {
                            if ($val->specPrice == '') {end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                            } else {end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->specPrice;}
                        } else {end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;}
                    }
                    unset($this->DSGItemRes->item->sku_promotions);
                    $PromotionPriceArray = Array();
                    foreach ($this->DSGItemRes->item->skus->sku as $sku) {
                        if (isset($sku->promotion_price)) {
                            if ($sku->promotion_price > 0) {$PromotionPriceArray[] = $sku->promotion_price;}
                        }
                    }
                    $this->DSGItemRes->item->promotion_price = min($PromotionPriceArray);
                   $PromotionPriceArray=$sku=$srcSkuArray=$name=$val=NULL;
                } else {$tryToGetSku_promotions = true;}
            }else{
                $tryToGetSku_promotions = true;
            }
            if (isset($tryToGetSku_promotions) && $tryToGetSku_promotions) {
                if (isset($this->DSGItemRes->item->sku_promotions)) {
                    if (isset($this->DSGItemRes->item->sku_promotions->def)) {
                        if (is_array($this->DSGItemRes->item->sku_promotions->def)) {
                            $promotions = array();
                            foreach ($this->DSGItemRes->item->sku_promotions->def as $defPromotions) {
                                if (isset($defPromotions->price)) {$promotions[] = (float) $defPromotions->price;}
                            }
                            if (count($promotions) > 0) {$this->DSGItemRes->item->promotion_price = min($promotions);}
                        }
                    }
                }
            }
            unset($skuFromSIB);
            unset($dldata);
			//http://tui.taobao.com/recommend?appid=32&count=32&itemid=26733820522
            unset($skudatajson);
        }
        $this->DSGItemRes->item->postage_id = '0';
    }

	public function parseTmall($data){
        $resstr = array();
        $res = $this->regexMatch('parse_item_values/user_rate_url', $data->data, $resstr);
        if ($res > 0){$this->DSGItemRes->item->userRateUrl = $resstr[0];}
        $resstr = array();
        $res = $this->regexMatch('parse_item_values/tshop',$data->data,$resstr);
        if ($res > 0){$this->DSGItemRes->item->tshop=$this->JavaScriptToJSON($resstr[1],true);}
        $resstr = array();
        $this->DSGItemRes->item->cid = $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->categoryId,0);
        $this->DSGItemRes->item->num_iid = $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->itemId,0);
        $this->DSGItemRes->item->seller_id = $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->userId,0);
        $this->DSGItemRes->item->nick = urldecode((string) $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->sellerNickName,''));
        $this->DSGItemRes->item->price = (float) $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->reservePrice,0);
        $this->DSGItemRes->item->num = $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->quantity,0);
        $res = $this->regexMatch('parse_item_values/shop_id', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->shop_id = (string) $resstr[1];
        }else{$this->DSGItemRes->item->shop_id = '0';}
        $res = $this->regexMatch('parse_item_values/title', $data->data, $resstr);
        if($res>0){$this->DSGItemRes->item->title = (string) $resstr[1];
        }else{$this->DSGItemRes->item->title = '';}
        $res = $this->regexMatch('parse_item_values/pic_url', $data->data, $resstr);
        if($res>0){$this->DSGItemRes->item->pic_url = (string) $resstr[1];
        }else{$this->DSGItemRes->item->pic_url = '';}
        $this->DSGItemRes->item->location = '';
        $this->DSGItemRes->item->post_fee = 0;
        $this->DSGItemRes->item->express_fee = 0;
        $this->DSGItemRes->item->ems_fee = 0;
        $this->DSGItemRes->item->item_imgs = new stdClass();
        $this->DSGItemRes->item->item_imgs->item_img = array();
        $res = $this->regexMatch('parse_item_values/item_imgs/block', $data->data, $resstr);
        if ($res > 0) {
            $item_imgs_data = $resstr[1];
            $res = $this->regexMatchAll('parse_item_values/item_imgs/url', $item_imgs_data, $resstr);
            unset($item_imgs_data);
            if ($res > 0) {
                foreach ($resstr[1] as $i => $item_img) {
                    $this->DSGItemRes->item->item_imgs->item_img[$i] = new stdClass();
                    $this->DSGItemRes->item->item_imgs->item_img[$i]->id = $i;
                    $this->DSGItemRes->item->item_imgs->item_img[$i]->position = $i;
                    $this->DSGItemRes->item->item_imgs->item_img[$i]->url = $item_img;
                }
                unset($item_img);
            }
        }
        $this->DSGItemRes->item->item_attributes = array();
        $res = $this->regexMatch('parse_item_values/item_attributes/block', $data->data, $resstr);
        if ($res > 0) {
            $attributes_data = $resstr[1];
            $res = $this->regexMatchAll('parse_item_values/item_attributes/propvallist', $attributes_data, $resstr);
            if ($res > 0) {
                foreach ($resstr[1] as $i => $item_attr) {
                    $this->DSGItemRes->item->item_attributes[$i] = new stdClass();
                    $this->DSGItemRes->item->item_attributes[$i]->prop = html_entity_decode($resstr[1][$i],ENT_COMPAT,'UTF-8');
                    $this->DSGItemRes->item->item_attributes[$i]->val = html_entity_decode($resstr[2][$i],ENT_COMPAT,'UTF-8');
                }
                unset($item_attr);
            }
            unset($attributes_data);
        }
        $this->DSGItemRes->item->props = array();
        $res = $this->regexMatch('parse_item_values/prop_imgs/block', $data->data, $resstr);
        if ($res > 0) {
            $propresstr = array();
            $propres = $this->regexMatchAll('parse_item_values/prop_imgs/prop/block', $resstr[1], $propresstr);
            if ($propres > 0) {
                foreach ($propresstr[1] as $propblock) {
                    $propFinal = new stdClass();
                    $propblockresstr = array();
                    $propblockres = $this->regexMatch('parse_item_values/prop_imgs/prop/title',$propblock,$propblockresstr);
                    if ($propblockres > 0) {
                        $propFinal->cid = $this->DSGItemRes->item->cid;
                        $propFinal->name_zh = $propblockresstr[1];
                        $propFinal->name = $propblockresstr[1];
                        unset($propblockresstr);
                    } else {continue;}
                    $propimgresstr = array();
                    $propimgres = $this->regexMatchAll('parse_item_values/prop_imgs/prop/prop_img/block',$propblock,$propimgresstr);
                    if ($propimgres > 0) {
                        $propFinal->childs = array();
                        foreach ($propimgresstr[1] as $j => $propimgblock) {
                            $resstr0 = array();
                            $res0 = $this->regexMatch('parse_item_values/prop_imgs/prop/prop_img/properties',$propimgblock,$resstr0);
                            if($res0 > 0){
                                $propFinal->childs[$j] = new stdClass();
                                $pidvid = explode(':', $resstr0[1]);
                                if (!isset($pidvid[0]) || !isset($pidvid[1])) {continue;}
                                $propFinal->childs[$j]->vid = $pidvid[1];
                            }else{continue;}
                            $resstr0 = array();
                            $res0 = $this->regexMatch('parse_item_values/prop_imgs/prop/prop_img/title',$propimgblock,$resstr0);
                            if($res0 > 0) {
                                $propFinal->childs[$j]->name_zh = $resstr0[1];
                                $propFinal->childs[$j]->name = $resstr0[1];
                            }else {
                                $propFinal->childs[$j]->name_zh = '';
                                $propFinal->childs[$j]->name = '';
                            }
                            $resstr0 = array();
                            $res0 = $this->regexMatch('parse_item_values/prop_imgs/prop/prop_img/url',$propimgblock,$resstr0);
                            if ($res0 > 0) {$propFinal->childs[$j]->url = $resstr0[1];
                            } else {$propFinal->childs[$j]->url = '';}
                        }
                        unset($propimgblock);
                        unset($propimgresstr);
                    }
                    if (isset($pidvid)) {
                        $this->DSGItemRes->item->props[$pidvid[0]] = clone $propFinal;
                        unset($pidvid);
                        unset($propFinal);
                    }
                }
                unset($propblock);
                unset($propresstr);
            }
        }
		
		#kermit 2014-11-1 抓取天猫商店的信用等数据
		$res = preg_match('/id="dsr-ratelink" value="(.*)"\/\>/u',$data->data,$resstr);
        if($res){
			$url=$resstr[1];
			$rate_data=$this->getHttpDocument($url,$this->itemParams->url);
			$rate_data=$rate_data->data;
			$this->DSGItemRes->rate = new stdClass();
			$res = preg_match('/data-tnick\s*=\s*"(.+?)"/i', $rate_data, $resstr);
			if ($res > 0){$this->DSGItemRes->rate->nick = urldecode($resstr[1]);
			}else{$this->DSGItemRes->rate->nick ='';}
			#2014-11-1 kermit
			$res = preg_match('/\<div class="frame"\>\s*\<div class="list"\>卖家信用：(\d+?)\s*<a href="/s',$rate_data,$resstr);
			$this->DSGItemRes->rate->xinyong_fen=$res>0?urldecode($resstr[1]):'';
			$res = preg_match('/src="http:\/\/pics.taobaocdn.com\/newrank\/(.+?).gif"/u', $rate_data, $resstr);
			$this->DSGItemRes->rate->xinyong_star=$res>0?urldecode($resstr[1]):'';
			$res = preg_match('/\<li\>\s*所在地区\s*：\s*(.+?)\s*\<\/li\>/u', $rate_data, $resstr);
			$this->DSGItemRes->rate->diqu=$res>0?trim(str_replace('&nbsp;','',urldecode($resstr[1]))):'';
			$res = preg_match('/共\<span\>(\d*)\<\/span\>人/u',$rate_data,$resstr);
			$this->DSGItemRes->rate->point_number=$res>0?urldecode($resstr[1]):'';
			$res = preg_match('/宝贝与描述相符：\<\/span\>\s*\<em title="(\d*\.?\d*)分"/s',$rate_data,$resstr);
			$this->DSGItemRes->rate->point_miaosho=$res>0?urldecode($resstr[1]):'';
			$res = preg_match('/卖家的服务态度：\<\/span\>\s*\<em title="(\d*\.?\d*)分"/s',$rate_data,$resstr);
			$this->DSGItemRes->rate->point_fuwu=$res>0?urldecode($resstr[1]):'';
			$res = preg_match('/卖家发货的速度：\<\/span\>\s*\<em title="(\d*\.?\d*)分"/s',$rate_data,$resstr);
			$this->DSGItemRes->rate->point_fahuo=$res>0?urldecode($resstr[1]):'';
			$res = preg_match('/\<em style="color:gray;"\>好评率：(\d*\.\d*%)\<\/em\>/u',$rate_data,$resstr);
			$this->DSGItemRes->rate->haopinglv=$res>0?urldecode($resstr[1]):'';
			$res = preg_match('/id="\s*J_showShopStartDate\s*"\s*value="([\d\-]+?)"\s*\/>/u', $rate_data, $resstr);
			$this->DSGItemRes->rate->starttime=$res>0?urldecode($resstr[1]):'';
			$this->DSGItemRes->rate->shoptype='taobao';
			$res = preg_match_all('/dsr-item\s*"\s*\>(.+?)\s*\<\/li\>/u', $rate_data, $resstr);
			$this->DSGItemRes->rate->point=$res>0?urldecode($resstr[1]):'';
		}
        if (!$this->fromCart) {
            if (isset($this->DSGItemRes->item->tshop->api->descUrl)) {
                $this->DSGItemRes->item->desc = false; //$dldata->data;
                $this->DSGItemRes->item->descUrl = (string) $this->DSGItemRes->item->tshop->api->descUrl;
                unset ($dldata);
            }
        }
        if ($this->curlUseMulti) {
            $urls = array();
            if (!$this->fromCart) {
                if (isset($this->DSGItemRes->item->tshop->itemDO->itemId) && isset($this->DSGItemRes->item->tshop->itemDO->userId)) {
                    $_valReviewsApi = 'http://dsr.rate.tmall.com/list_dsr_info.htm?itemId='
                      . $this->DSGItemRes->item->tshop->itemDO->itemId.'&sellerId='.$this->DSGItemRes->item->tshop->itemDO->userId;
                    $urls[] = $_valReviewsApi;
                }
                $_apiRelateMarket = 'http://aldcdn.tmall.com/recommend.htm?itemId=' . $this->DSGItemRes->item->num_iid . '&refer=&rn=32&appId=03054';
                $urls[] = $_apiRelateMarket;
            }
            $_truePostage='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
            $urls[] = $_truePostage;
            $downloads = $this->getHttpDocumentArray($urls);
            if (isset($this->DSGItemRes->item->tshop->itemDO->itemId) && isset($this->DSGItemRes->item->tshop->itemDO->userId)) {
                if (isset($_valReviewsApi) && isset($downloads[$_valReviewsApi])) {
                    $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON($downloads[$_valReviewsApi]->data,true);
                }
            }
            if (isset($_apiRelateMarket) && isset($downloads[$_apiRelateMarket])) {
                $downloads[$_apiRelateMarket]->data = preg_replace('/,{"acurl".*/s','',$downloads[$_apiRelateMarket]->data);
                $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON($downloads[$_apiRelateMarket]->data,true);
            }
            if (isset($_truePostage) && isset($downloads[$_truePostage])) {
                $this->DSGItemRes->item->truePostage = $downloads[$_truePostage]->data;
            }
        } else {
            //http://dsr.rate.tmall.com/list_dsr_info.htm?itemId=20008134785&sellerId=1714306514
            if (!$this->fromCart) {
                if (isset($this->DSGItemRes->item->tshop->itemDO->itemId) && isset($this->DSGItemRes->item->tshop->itemDO->userId)) {
                    $dldata = $this->getHttpDocument(
                      'http://dsr.rate.tmall.com/list_dsr_info.htm?itemId='
                      . $this->DSGItemRes->item->tshop->itemDO->itemId . '&sellerId='. $this->DSGItemRes->item->tshop->itemDO->userId);
                    $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON($dldata->data, true);
                }
            }
            if (!$this->fromCart) {
                $url = 'http://aldcdn.tmall.com/recommend.htm?itemId=' . $this->DSGItemRes->item->num_iid . '&refer=&rn=32&appId=03054';
                $dldata = $this->getHttpDocument($url);
                $dldata->data = preg_replace('/,{"acurl".*/s', '', $dldata->data);
                $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON($dldata->data, true);
            }
            $url ='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
            $dldata = $this->getHttpDocument($url);
            $this->DSGItemRes->item->truePostage = $dldata->data;
        }
        //http://aldcdn.tmall.com/recommend.htm?itemId=20008134785&refer=&rn=32&appId=03054
        //http://tui.taobao.com/recommend?appid=32&count=32&itemid=26733820522
        if (isset($this->DSGItemRes->item->tshop->initApi)) {
            $initApiData =$this->getHttpDocument($this->DSGItemRes->item->tshop->initApi,false,false,'http://detail.tmall.com/item.htm?id='.$this->DSGItemRes->item->num_iid);
            $initApi=$this->JavaScriptToJSON($initApiData->data, true);
            unset($initApiData);
        }else {$initApi = new stdClass();}
        if(isset($this->DSGItemRes->item->truePostage)){
            $res=$this->regexMatch('parse_item_values/block_sib/express_fee', $this->DSGItemRes->item->truePostage, $resstr);
            if($res>0){
                if(!isset($resstr[2])){$this->DSGItemRes->item->express_fee = (float) $resstr[1];
                }else{$this->DSGItemRes->item->express_fee = 0;}
            }else {$this->DSGItemRes->item->express_fee = 0;}
            $res = $this->regexMatch('parse_item_values/block_sib/ems_fee', $this->DSGItemRes->item->truePostage, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {$this->DSGItemRes->item->ems_fee = (float) $resstr[1];
                }else {$this->DSGItemRes->item->ems_fee = 0;}
            } else {$this->DSGItemRes->item->ems_fee = 0;}
            if ($this->DSGItemRes->item->express_fee == 0) {
                $this->DSGItemRes->item->express_fee = max(
                  array(
                    $this->DSGItemRes->item->post_fee,
                    $this->DSGItemRes->item->express_fee
                  )
                );
            }
    	}else{
        $postage = 0;
        if (isset($initApi->defaultModel->deliveryDO->deliverySkuMap->default[0]->postage)) {
            $postage = $initApi->defaultModel->deliveryDO->deliverySkuMap->default[0]->postage;
            $res = preg_match('/([\d\.]+)/', $postage, $postages);
            if ($res > 0) {
                if (floatval($postages[1])) {
                    $postage = (float) $postages[1];
                } else {
                    $postage = 0;
                }
            } else {
                $postage = 0;
            }
        }
        unset($postages);
        $this->DSGItemRes->item->post_fee = $postage;
        $this->DSGItemRes->item->express_fee = $postage;
        $this->DSGItemRes->item->ems_fee = $postage;
    }
        //SKUs 
        $this->DSGItemRes->item->promotion_price = $this->DSGItemRes->item->price;
        if (isset($this->DSGItemRes->item->tshop->valItemInfo)) {
            if (isset($this->DSGItemRes->item->tshop->valItemInfo->skuMap)) {
                $this->DSGItemRes->item->skus = new stdClass();
                $srcSkuArray = (array) $this->DSGItemRes->item->tshop->valItemInfo->skuMap;
                $this->DSGItemRes->item->skus->sku = array();
                foreach ($srcSkuArray as $name => $val) {
                    $this->DSGItemRes->item->skus->sku[] = new stdClass();
                    end($this->DSGItemRes->item->skus->sku)->price = (float) $val->price;
                    end($this->DSGItemRes->item->skus->sku)->properties = trim($name, ';');
                    end($this->DSGItemRes->item->skus->sku)->properties_name = ''; // ???
                    end($this->DSGItemRes->item->skus->sku)->quantity = $val->stock;
                    if (end($this->DSGItemRes->item->skus->sku)->quantity == 99) {
                        end($this->DSGItemRes->item->skus->sku)->quantity = 0;
                    }
                    end($this->DSGItemRes->item->skus->sku)->sku_id = $val->skuId;
                    if (isset($initApi->defaultModel->itemPriceResultDO->priceInfo->{$val->skuId}->promotionList)) {
                        $promotions = $initApi->defaultModel->itemPriceResultDO->priceInfo->{$val->skuId}->promotionList;
                        $PromotionPriceArray = Array();
                        foreach ($promotions as $promotion) {
                            if (isset($promotion->price)) {
                                if ($promotion->price > 0) {$PromotionPriceArray[] = (float) $promotion->price;}
                            }
                        }
                        if (count($PromotionPriceArray) > 0) {$promotion_price = min($PromotionPriceArray);
                        } else {$promotion_price = 0;}
                        if ($promotion_price > 0) {end($this->DSGItemRes->item->skus->sku)->promotion_price = $promotion_price;
                        } else {end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;}
                    } elseif (isset($val->specPrice)) {
                        if($val->specPrice == ''){end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                        }else{end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->specPrice;}
                    }else{end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;}
                }
                unset($this->DSGItemRes->item->sku_promotions);
                $PromotionPriceArray = Array();
                foreach ($this->DSGItemRes->item->skus->sku as $sku) {
                    if (isset($sku->promotion_price)) {
                        if ($sku->promotion_price > 0) {$PromotionPriceArray[] = $sku->promotion_price;}
                    }
                }
                $this->DSGItemRes->item->promotion_price = min($PromotionPriceArray);
                $PromotionPriceArray=$sku=$srcSkuArray=$name=$val=NULL;
            }else{$tryToGetSku_promotions = true;}
        }else{$tryToGetSku_promotions = true;}
        if(isset($tryToGetSku_promotions) && $tryToGetSku_promotions && isset($initApi)) {
            if(isset($initApi->defaultModel->itemPriceResultDO->priceInfo->def->promotionList)) {
                if(is_array($initApi->defaultModel->itemPriceResultDO->priceInfo->def->promotionList)) {
                    $promotions = array();
                    foreach ($initApi->defaultModel->itemPriceResultDO->priceInfo->def->promotionList as $defPromotions) {
                        if (isset($defPromotions->price)){$promotions[] = (float) $defPromotions->price;}
                    }
                    if (count($promotions)>0){$this->DSGItemRes->item->promotion_price = min($promotions);}
                }
            }
        }
        unset($skuFromSIB);
        unset($dldata);
        unset($this->DSGItemRes->item->tshop);
        $this->DSGItemRes->item->postage_id = '0';
    }	
	
	

######################取prop值，下方为使用淘宝的API
	
	public function getProps($product_id){	
		$method='taobao.item.get';
		$appid='****';
		$appSecret='******';  
		$params=array(
			'app_key' 	=> $appid,
			'method' 	=> $method,
			'session' 	=> '',
			'format' 	=> 'json',
			'v'			=> '2.0',
			'partner_id'=> 'top-sdk-php-20131101',
			'timestamp' => date('Y-m-d H:i:s'),
			'sign_method'=> 'md5',
			'fields'=>'props_name,desc',
			'num_iid' => $product_id,
		);
		$params['sign']=$this->createSign($params,$appSecret);
		
		#执行调用
		$result=$this->makeRequest('http://gw.api.taobao.com/router/rest',$params);
		return $result;
	}
	
	#远程CURL
	 private function makeRequest($url,$params,$method ='GET') {
		$ch = curl_init();
		$opts = array(
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 60
		);
		if($method == 'POST') {
		  $opts[CURLOPT_URL] = $url;
		  $opts[CURLOPT_POST] = true;
		  $opts[CURLOPT_POSTFIELDS] = $params;
		} elseif ($method == 'GET') {
		  $opts[CURLOPT_URL]=$url.'?'.$this->createStrParam($params);
		}
		curl_setopt_array($ch,$opts);
		$result=curl_exec($ch);
		curl_close($ch);
		return json_decode($result,true);
	}
	
	#参数拼接
	private function createStrParam($params) {
   		$query = array();
		foreach ($params as $key => $val) {
		    if(($key!='' && $val != '') || $val === 0){
				$query[]=$key.'='.urlencode($val);
			}
		}
		return implode('&', $query);
    }
	
	#生成SIGN值
	private function createSign($params=array(),$appSecret){
		ksort($params);
		$sign = $appSecret;
		foreach ($params as $key => $val) {
		    if(($key != '' && $val != '') || $val === 0) {
				$sign .= $key . $val;
		    }
		}
		return strtoupper(md5($sign . $appSecret));
	}
	
	#处理DESC中的html
	public function clearHtml($content){
		if($content=='') return $content;
		$content = preg_replace("/<a.*?\/a>/i", "", $content);
		preg_match_all ("/<img[^>]*>/i", $content, $images);;
		$content = strip_tags($content);
		
		if (is_array($images) && count($images) > 0){
			$images = implode("\n", $images[0]) . "\n";
		}else{
			$images = '';
		}
		return $images;
	}
}

#如有验证码，进入到登录步骤
if(isset($_GET['code']))
{
	$code=$_GET['code'];
	$id=$_GET['id'];
	$TbData=new TbData();
	$TbData->autoLogin($code,$id);
}

#数据抓取
$id=isset($_POST['id'])?$_POST['id']:'';
if(!$id) $id=isset($_GET['id'])?$_GET['id']:'';
if($id){
	$TbData=new TbData();
	$data=$TbData->getData(array('id'=>$id));
	$props=$TbData->getProps($id);
	$data->item->props_name=$props['item_get_response']['item']['props_name'];
	$data->item->Apidesc=$props['item_get_response']['item']['desc'];
	$data->item->desc=$TbData->clearHtml($props['item_get_response']['item']['desc']);
	#$TbData->showTest($TbData->logArr,false);
	#$TbData->showTest($data);
}else $data='no id value!';
echo '<pre>';print_r($data);echo '</pre>';exit;
echo gzcompress(serialize($data));