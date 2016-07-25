<?php

/*
 *Author:Kermit
 *Time:2014-12-06
 *Note:抓取淘宝搜索页程序
 */

error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors',1);
date_default_timezone_set('PRC');
@set_time_limit(100);
!headers_sent() && header("Content-type:text/html;charset=utf-8");

class TbSearch
{	
	public $baseUrl='http://s.taobao.com/search?';
	public $referer='http://www.taobao.com';
	public $params=array();
	public $long_limit=10;
	public $proxy;
	public $logArr;
	public $debug=true;
	public $DSGobj;
	public $DSGSearchRes;
	private static $_curlObject = FALSE;
	
	//生成地址
	public function makeUrl($baseurl,$parmas)
    {
		return $baseurl.http_build_query($parmas);
	}

	//抓取淘宝搜索页面
	public function getData($params)
    {
		if(!$params) return false;
		$this->params=$params;
		$this->DSGSearchRes=new stdClass();
		$this->DSGSearchRes->url=$this->makeUrl($this->baseUrl,$params);
	
		#获取页面
		$this->DSGobj=$this->curlGetNoRedirect($this->DSGSearchRes->url,$this->referer);

		$pre_rs=preg_match('/g_page_config\s*=\s*(.*);\s*g_srp_loadCss/s',$this->DSGobj,$matches);
		if(!$pre_rs) return false;
		$this->DSGobj=$matches[1];
		$this->DSGobj=json_decode($this->DSGobj);
		$matches=NULL;
		if(!$this->DSGobj) return false;
		
		#取total_results 
		$this->DSGSearchRes->total_results=$this->DSGobj->mainInfo->traceInfo->traceData->totalHits;
		
        #取主图片列表项目
		$this->getItem();	
		
        #取intGroups项目
		$this->getintGroups();
		
        #取intMultiFilters项目
		$this->getintMultiFilters();
		
		$this->DSGobj=NULL;
		return $this->DSGSearchRes;
	}

	#curl
	public function getCurl($path,$referer)
    {
			if(gettype(self::$_curlObject)!='resource')
			{		
				$ch = curl_init($path);
				self::$_curlObject = $ch;
			}
			else
			{
				$ch = self::$_curlObject;
				@curl_setopt($ch,CURLOPT_URL,$path);
			}
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
			curl_setopt($ch, CURLINFO_HEADER_OUT, TRUE);
			curl_setopt($ch, CURLOPT_ENCODING, '');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
			//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			//curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 信任任何证书  
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 1); // 检查证书中是否设置域名  

			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_FORBID_REUSE, FALSE);
			curl_setopt($ch, CURLOPT_NOPROGRESS, TRUE);
			curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 86400);
			curl_setopt($ch, CURLOPT_REFERER,"https://www.taobao.com/");
			$header = array("Referer: http://www.taobao.com/","Connection: keep-alive","Keep-Alive: 30"); 
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header); 
			if($this->proxy!='')
			{
				curl_setopt($ch, CURLOPT_PROXYTYPE,CURLPROXY_HTTP);
				curl_setopt($ch, CURLOPT_PROXY,$this->proxy);
			}
			return $ch;
    }

	#注销curl资源
	public function closeCurl()
    {
		if(gettype(self::$_curlObject) == 'resource') curl_close(self::$_curlObject);
		return true;
	}
	
	//抓取单个页面
	public function curlGetNoRedirect($url,$referer)
    {
		$ch=$this->getCurl($url, $referer);
		$mr = 20;
        $newurl=$url;
		$open_basedir=ini_get('open_basedir');
		$safe_mode=ini_get('safe_mode');

        if($open_basedir=='' && (in_array($safe_mode, array('0', 'Off')) || !$safe_mode))
        {
            curl_setopt($ch, CURLOPT_MAXREDIRS, $mr);
            curl_setopt($ch, CURLOPT_HEADER, true);          
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		}
        else
        {
            curl_setopt($ch,CURLOPT_FOLLOWLOCATION,FALSE);
            $newurl=curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $originalUrl=$newurl;
            $rch = curl_copy_handle($ch);
            curl_setopt($rch, CURLOPT_HEADER, TRUE);
            curl_setopt($rch, CURLOPT_NOBODY, TRUE);
            do
            {
				curl_setopt($rch,CURLOPT_URL,$newurl);
				$header=curl_exec($rch);
				if(curl_errno($rch)) $code=0;
				else
				{
					$code = curl_getinfo($rch, CURLINFO_HTTP_CODE);
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
							preg_match('/http[s]*:\/\/.+?(?=\/)/i',$newurl,$m);
							$newurl = trim(array_pop($m)).$url;
						}
					}else $code = 0;
				}
            }while($code && --$mr);
            curl_setopt($ch,CURLOPT_URL,$newurl);
		}

		$ret=curl_exec($ch);
		$curl_info=curl_getinfo($ch);
		if($curl_info['http_code']>=400) $ret='';
		else
        {
			$this->debug &&$this->logArr['curllog'][$url]=$curl_info['total_time'];
		}
		return $ret;
	}
	
	#提取主项目列表
	public function getItem()
    {
		$this->DSGSearchRes->item = $this->DSGobj->mods->itemlist->data->auctions;
		#2015-5-27 数据压缩
		foreach($this->DSGSearchRes->item as $k=>$obj)
        {
			$this->DSGSearchRes->item[$k]->isTmall=$this->DSGSearchRes->item[$k]->shopcard->isTmall;
			$this->DSGSearchRes->item[$k]->view_sales=trim(str_replace('人付款','',$this->DSGSearchRes->item[$k]->view_sales));
			unset($this->DSGSearchRes->item[$k]->icon,
				$this->DSGSearchRes->item[$k]->i2iTags,
				$this->DSGSearchRes->item[$k]->raw_title,
				$this->DSGSearchRes->item[$k]->detail_url,
				$this->DSGSearchRes->item[$k]->comment_url,
				$this->DSGSearchRes->item[$k]->shopLink,
				$this->DSGSearchRes->item[$k]->pid,
				$this->DSGSearchRes->item[$k]->shopcard,
				$this->DSGSearchRes->item[$k]->title,
				$this->DSGSearchRes->item[$k]->nick,
				$this->DSGSearchRes->item[$k]->item_loc
				);
		}
		return;
	}
	
	#取intGroups项目
	public function getintGroups()
    {
		$this->DSGSearchRes->intGroups=array();
		$index=0;$count='count';
		if(!isset($this->DSGobj->mods->nav->data->common)) return;
		foreach($this->DSGobj->mods->nav->data->common as $k=>$itemobj)
        {
			if($itemobj->sub[0]->key!='ppath') continue;
			#PID标题和值
			$this->DSGSearchRes->intGroups[$index]=new stdClass();
			$this->DSGSearchRes->intGroups[$index]->title=$itemobj->text;
			$this->DSGSearchRes->intGroups[$index]->values=array();
			$sindex=0;
			foreach($itemobj->sub as $sk=>$sobj)
            {
				#VID标题和值
				$this->DSGSearchRes->intGroups[$index]->values[$sindex]=new stdClass();
				$this->DSGSearchRes->intGroups[$index]->values[$sindex]->values_title=$sobj->text;
				$this->DSGSearchRes->intGroups[$index]->values[$sindex]->values_props=$sobj->value;
				$this->DSGSearchRes->intGroups[$index]->values[$sindex]->count_num=$sobj->{$count};
				$sindex++;
				#还需要排序再截取
				if($this->long_limit && $sindex>=$this->long_limit) break;
			}
			$index++;
		}
	}
	
	#取intMultiFilters项目
	public function getintMultiFilters()
    {
		$this->DSGSearchRes->intMultiFilters=array();
		$index=0;$count='count';
		if(!isset($this->DSGobj->mods->nav->data->adv)) return;
		foreach($this->DSGobj->mods->nav->data->adv as $k=>$itemobj)
        {
			if($itemobj->sub[0]->key!='ppath') continue;
			#PID标题和值
			$this->DSGSearchRes->intMultiFilters[$index]=new stdClass();
			$this->DSGSearchRes->intMultiFilters[$index]->title=$itemobj->text;
			$this->DSGSearchRes->intMultiFilters[$index]->values=array();
			$sindex=0;
			
			foreach($itemobj->sub as $sk=>$sobj)
            {
				#VID标题和值
				$this->DSGSearchRes->intMultiFilters[$index]->values[$sindex]=new stdClass();
				$this->DSGSearchRes->intMultiFilters[$index]->values[$sindex]->values_title=$sobj->text;
				$this->DSGSearchRes->intMultiFilters[$index]->values[$sindex]->values_props=$sobj->value;
				$this->DSGSearchRes->intMultiFilters[$index]->values[$sindex]->count_num=$sobj->{$count};
				$sindex++;
				
				#还需要排序再截取
				if($this->long_limit && $sindex>=$this->long_limit) break;	
			}	 
			$index++;
		}
	}
}

#接受产品ID
@$params=$_POST;
if(!$params && isset($_GET)) $params=$_GET;
if($params)
{
	$TbSearch=new TbSearch();
	for($i=1;$i<5;$i++)
    {	
		$data=$TbSearch->getData($params);
		if($data) break;
	}
}
else
{
	$data=array('error'=>'no params!');
}
echo "<pre>";print_r($data);echo "</pre>";exit;

//可使用数据序列化然后压缩输出。
$data=serialize($data);
echo $data;