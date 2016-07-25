<?php

/*
 *Kermit:Write from 2014-12-04
 *Note:抓取淘宝rate相关数据，以及sellerID值。供异步加载rate以及评论使用.
 */

error_reporting(E_ALL);
date_default_timezone_set('PRC');

class TbRateData{
	
	public $maxredirect=20;
	public $proxy=false;#'xlei:2rhLKVyb@23.80.166.229:29842';	//'183.224.1.56:80'; //close set $proxy=false 
	public $extend_path;
	public $logArr=array('curlAtime'=>0);
	private static $_curlObject = FALSE;
	public $logIndex=0;
	public $st=0;
	public $referer='http://item.taobao.com/';
	public $tid;
	public $type;
	public $itemParams;
	public $curlUseMulti=true;
	public $autoRedirect;
	public $postageArea='990000';
	public $RuleXml;
	public $DSGItemRes;
	public $fromCart=false;
	public $debug=false;
	
	public function __construct($a=true,$m=false,$proxy=false){
		$this->extend_path=dirname(__FILE__).'/';
		$this->st=microtime(true);
		if($proxy) $this->proxy=$proxy;
		$this->autoRedirect=$a;
	}
	
	public function getData($params,$type='item.taobao.com'){
		if(!$id=$params['id']) return false;
		$this->tid=$id;
		$this->type=$type;
		$this->itemParams=$this->getDSGRules();
		$mr=$this->maxredirect;			
		$open_basedir=ini_get('open_basedir');
		$safe_mode=ini_get('safe_mode');
		$ch=$this->getCurl($this->itemParams->url,$this->referer);
	    if($this->autoRedirect && (($open_basedir=='') && (in_array($safe_mode, array('0', 'Off'))) || !$safe_mode)){
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
				  do{
					curl_setopt($rch,CURLOPT_URL,$newurl);
					$this->debug && $this->logArr[$this->logIndex]['ctime']=microtime(true);
					$header=curl_exec($rch);
					if(curl_errno($rch)) $code=0;
					else{
					  $code = curl_getinfo($rch, CURLINFO_HTTP_CODE);
					  if($code == 301 || $code == 302){
						preg_match('/Location:(.*?)[\n\r]/i',$header,$matches);
						$url = trim(array_pop($matches));
						if(preg_match('/http[s]*:\/\/.+?(?=\/)/i', $url)) {
						  $newurl = $url;
						}else{
						  preg_match('/http[s]*:\/\/.+?(?=\/)/i', $newurl, $m);
						  $newurl = trim(array_pop($m)).$url;
						}
					  }else $code = 0;
					}
					if($this->debug){
						$this->logArr[$this->logIndex]['ctime']=round(microtime(true)-$this->logArr[$this->logIndex]['ctime'],3);
						$this->logArr[$this->logIndex]['url']=$newurl;
						$this->logArr[$this->logIndex]['code']=$code;
						$this->logArr['curlAtime']+=$this->logArr[$this->logIndex]['ctime'];
						$this->logIndex++;
					}
				  }while($code && --$mr);
				  curl_setopt($ch,CURLOPT_URL,$newurl);
			}
	 	}
		$this->debug && $this->logArr[$this->logIndex]['ctime']=microtime(true);
		$ret=curl_exec($ch);
		if($this->debug){
			$this->logArr[$this->logIndex]['ctime']=round(microtime(true)-$this->logArr[$this->logIndex]['ctime'],3);
			$this->logArr[$this->logIndex]['url']=isset($newurl)?$newurl:$this->itemParams->url;
			$this->logIndex++;
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

		#商家页面地区可能不展示，此时从详情页中抓取配送数据
		if($this->DSGItemRes->rate->diqu==''){
			$res=preg_match('/location:\'(.*)\',/u',$this->DSGItemRes->item->truePostage,$resstr);
			$this->DSGItemRes->rate->diqu=$res?$resstr[1]:'';
		}
		unset($this->DSGItemRes->html,$this->DSGItemRes->item->truePostage);
		$this->debug && $this->logArr['allTime']=round((microtime(true)-$this->st),3);
		return $this->DSGItemRes;
	}
	
	public function getCurl($path,$referer=''){
		  $ch=curl_init();
		  curl_setopt($ch,CURLOPT_URL,$path);
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
   
	public function getDSGRules($queryParams=array(),$xmlfile=true){
		$xml=simplexml_load_string(file_get_contents($this->extend_path.'DSG_rateRule.xml'),NULL,LIBXML_NOCDATA);
		$search=$xml->xpath("/parser_sections/parser_section[name='{$this->type}']");
	    $this->RuleXml=$search[0];
		$result=new stdClass();
		$result->debug=$this->getDSGRule('debug')=='true';
		$result->type=$this->getDSGRule('type');
		$result->url=$this->getDSGRule('base_url').http_build_query(array('id'=>$this->tid));
		return $result;
	}

	public function getDSGRule($xpath){
		$xml = $this->RuleXml->xpath($xpath);
		if($xml){
			if (count($xml) > 1 || count($xml[0]) > 1) $res = $xml;
		 	else $res = trim((string) ($xml[0]));
			return $res;
		}else return FALSE;
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
        $res=$this->regexMatch('parse_item_values/user_rate_url', $data->data, $resstr);
        if($res>0) $this->DSGItemRes->item->userRateUrl = $resstr[0];
        
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
        }else{$this->DSGItemRes->item->cid = '0';}
        $res = $this->regexMatch('parse_item_values/num_iid', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->num_iid = (string) $resstr[1];
        }else{$this->DSGItemRes->item->num_iid = '0';}
        $res = $this->regexMatch('parse_item_values/seller_id', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->seller_id = (string) $resstr[1];
        }else{$this->DSGItemRes->item->seller_id = '0';}
        $res = $this->regexMatch('parse_item_values/nick', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->nick = (string) $resstr[1];
        }else{$this->DSGItemRes->item->nick = '';}
        $res = $this->regexMatch('parse_item_values/shop_id', $data->data, $resstr);
        if ($res > 0) {$this->DSGItemRes->item->shop_id = (string) $resstr[1];
        }else{$this->DSGItemRes->item->shop_id = '0';}
        $res = $this->regexMatch('parse_item_values/block_sib/_url', $data->data, $resstr);
        if($res <= 0) {
            $this->DSGItemRes->item->num = 0;
            $this->DSGItemRes->item->location = '';
            $this->DSGItemRes->item->post_fee = 0;
            $this->DSGItemRes->item->express_fee = 0;
            $this->DSGItemRes->item->ems_fee = 0;
            $this->DSGItemRes->item->sku_data = new stdClass();
        }else{
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
		$url ='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
		$dldata = $this->getHttpDocument($url);
		$this->DSGItemRes->item->truePostage = $dldata->data;
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
				$this->DSGItemRes->item->express_fee = max(array($this->DSGItemRes->item->post_fee,$this->DSGItemRes->item->express_fee));
			}
		}	
		unset($sib_data);
        }
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
        $this->DSGItemRes->item->location = '';
        $this->DSGItemRes->item->post_fee = 0;
        $this->DSGItemRes->item->express_fee = 0;
        $this->DSGItemRes->item->ems_fee = 0;
        unset($this->DSGItemRes->item->tshop);

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
		$url ='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
		$dldata = $this->getHttpDocument($url);
		$this->DSGItemRes->item->truePostage = $dldata->data;
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
        $this->DSGItemRes->item->post_fee = $postage;
        $this->DSGItemRes->item->express_fee = $postage;
        $this->DSGItemRes->item->ems_fee = $postage;
    	}
    }
	public function showTest($data,$stop=true){
		header("Content-type:text/html;charset=utf-8");
		echo '<pre>';print_r($data);echo'</pre>';
		if($stop) exit;
	}
}