<?php

/*
 *NOTE:对抓取回来的数据中的Group和multiFilter进行查询、翻译和入库保存：
 *    :对详情页中产品的属性/标题/进行查询、翻译和入库保存
 *2014-11-14将存储改成Redis存储后进行修改
 *2014-11-18较大改动，新增几种翻译通道选择
 *			1：走dropshop翻译通道
 *			2：走Bing翻译通道
 *			3：走Google翻译通道
 *			0:随机走以上三通道之一
 *默认采用1通道，默认目标语言RU
 *2014-12-30 添加对VIP的翻译
 */
 
error_reporting(E_ALL);
class Translate{

  	public $transArr=array();		#翻译账号相关信息
	public $ForTrans_data=array();	#待翻译数据
	public $ForTrans_key=array();	#翻译数据对应KEY
	public $index=0;				#对应索引
	public $globalObj;				#公共对象
	public $to='ru';				#目标语言的代码			
	public $CostTime=NULL;			#用时
	public $type=NULL;				#使用的翻译通道
	static $_curlObject = FALSE;	#curl初始化对象
	public $recordTime=true;		#是否记录翻译用时

	#实例化对象传入globalObj
	public function __construct($globalObj){	
		$this->globalObj=$globalObj;
	}
	
	#判断是否有中文
	public function hchinese($str){
		#if(preg_match('/[^\x00-\x80]/',$str)){
		if(preg_match("/([\x81-\xfe][\x40-\xfe])/",$str,$match)){
			return true;
		}else{
			return false;	
		}
	}
	
	#测试展示数据
  	public function showTest($data,$stop=true){
		@header("Content-type:text/html;charset=utf-8");
		echo '<pre>';print_r($data);echo'</pre>';if($stop) exit;
		}
	
	#判断是否有中文字符
	public function hasChina($str){
		if(preg_match("/[\x{4e00}-\x{9fa5}]./u",$str)) return true;
		else return false; 	
	}
	
	#取翻译的URL和账号等调用数据
	public function init($type){
		if(!$type || !in_array($type,array(2,3))) $type=rand(2,3);
		$this->type=$type;
		if(!$this->globalObj) exit('globalObj is NULL');
		switch($this->type){
			#dropShop翻译
			case 1:$this->transArr['url']='http://proxy2.dropshop.pro/site/translateblock';
				   $this->transArr['type']=array(
							'text'=>'parseText',//标题
							'prop'=>'prepareProps',//属性
							'attr'=>'item_attributes',
							);
					break;
			#Bing翻译
			case 2:$this->transArr['url']='http://api.microsofttranslator.com/V2/Http.svc/TranslateArray';
				   $this->transArr['tokenurl']='https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/';
				   $this->transArr['user']=array(
						array('clientID'=>'count1','clientSecret'=>'secret1'),
						array('clientID'=>'count2','clientSecret'=>'secret2'),
						array('clientID'=>'count3','clientSecret'=>'secret3'),
						);
					$this->transArr['user']=$this->transArr['user'][rand(0,2)];//$this->transArr['user'][0];
					break;
			#google翻译
			case 3:$this->transArr['url']='http://translate.google.com/translate_a/t';
					break;
			
		};
		return true;
	}
	
	#添加要翻译的文本内容
	public function addDataText($text,$type=''){
		switch($this->type){
			case 1:
				$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="'.$this->to.'" type="'.$this->transArr['type'][$type].'" >'.$text.'</translation>');
				break;
			case 2:
				$this->ForTrans_data[$this->index]=array('<string xmlns="http://schemas.microsoft.com/2003/10/Serialization/Arrays">'.strip_tags($text).'</string>');
				break;
			case 3:
				$this->ForTrans_data[$this->index]=array($text);
				break;
		}
		return true;
	}

	#查询库中PID_VID已经翻译的值 2014-11-13 改从REDIS中查询
	public function searchDb($search_array){
		#$this->showTest($search_array,false);		
		$forSearchRedis=array_keys($search_array);
		$RedisData=$this->globalObj->Redis->hmget('PVID_'.$this->to,$forSearchRedis);
		#$this->showTest($RedisData,false);		
		$return_arr=array();
		foreach($RedisData as $k=>$v){
			if($v!=''){
				#不为空的值返回
				$return_arr[$k]=$v;
			}
		}
		$RedisData=$search_array=NULL;		
		return $return_arr;
	}
	
	#保存数据入Redis 2014-11-14保存数据入Redis
	public function saveData(){	
		if(empty($this->ForTrans_key)) return;
		$cArr=$rArr=array();
		$chinese_key='PVID_zh';$this_key='PVID_'.$this->to;
		foreach($this->ForTrans_key as $k=>$pidvid){
			if(isset($this->ForTrans_data[$k][1]) && $this->ForTrans_data[$k][1]){
				$t=addslashes($this->ForTrans_data[$k][1]);
				$c=addslashes($this->ForTrans_key[$k]['name']);
				$pvid=$this->ForTrans_key[$k]['pid'].':'.$this->ForTrans_key[$k]['vid'];
				if(strlen($c)) $cArr[$pvid]=$c;
				if(strlen($t)) $rArr[$pvid]=$t;
				$this->globalObj->Redis->ZADD('PVID_SET',time(),$pvid);
			}
		}
		$this->globalObj->Redis->hmset($chinese_key,$cArr);
		$this->globalObj->Redis->hmset($this_key,$rArr);
		$cArr=$rArr=$this->ForTrans_data=$this->ForTrans_key=NULL;
		return true;
	}

	#翻译搜索类目的淘宝数据.
	public function TransTbSearch($data,$type=1,$to='ru'){
		
		if($to) $this->to=$to;
		if(!$data || !count($data->item)) return $data;
		$this->init($type);
		
		//第一步：提取Group和MultiFilters中所有PID,VID数据
		$search_array=array();
		
		$groupFlag=false;//标记intGroups
		if(isset($data->intGroups) && $data->intGroups){
			$groupFlag=true;
			foreach($data->intGroups as $k=>$obj){
				//把PID值加入查表行列
				list($pid,$nouse)=explode(':',$obj->values[0]->values_props);
				if($pid=='-1'){
					unset($data->intGroups[$k]);
					continue;
				}
				$data->intGroups[$k]->pid=$pid;
				$search_array[$pid.':0']='';
				//把PID下面的vid加入查表行列
				foreach($obj->values as $ks=>$objs){
					$search_array[$objs->values_props]='';
				}
			}
		}
			
		$MultiFlag=false;//标记MultiFilters
		if(isset($data->intMultiFilters) && $data->intMultiFilters){
			$MultiFlag=true;
			foreach($data->intMultiFilters as $k=>$obj){
				//把PID值加入查表行列
				list($pid,$nouse)=explode(':',$obj->values[0]->values_props);
				if($pid=='-1'){
					unset($data->intMultiFilters[$k]);
					continue;
				}
				$data->intMultiFilters[$k]->pid=$pid;
				$search_array[$pid.':0']='';
				//把PID下面的vid加入查表行列
				foreach($obj->values as $ks=>$objs){
					$search_array[$objs->values_props]='';
				}
			}
		}	
		if(!$search_array) return $data;
		
		//第二步：对REDIS数据查询是否有翻译
		$rows=$this->searchDb($search_array);
		
		//第三步：遍历rows将有翻译数据代入，未有翻译的写入待翻译队列
		if($groupFlag){//将数据库中group的值代入并提取要翻译的数据入翻译队列
			foreach($data->intGroups as $k=>$obj){
				if(isset($rows[$obj->pid.':0']) && $rows[$obj->pid.':0']){
					$data->intGroups[$k]->trans=$rows[$obj->pid.':0'];
					unset($search_array[$obj->pid.':0']);
				}elseif(!$this->hchinese($obj->title)){#2014-12-19无中文不用翻译
					$data->intGroups[$k]->trans=$obj->title;
				}else{
					$this->addDataText($obj->title,'attr');
					$this->ForTrans_key[$this->index]['pid']=$obj->pid;
					$this->ForTrans_key[$this->index]['vid']=0;
					$this->ForTrans_key[$this->index]['name']=$obj->title;//添加中文
					$data->intGroups[$k]->t_key=$this->index;
					$this->index++;
				}
				
				foreach($obj->values as $ks=>$objs){
					if(isset($rows[$objs->values_props])){ 
						$data->intGroups[$k]->values[$ks]->trans=$rows[$objs->values_props];
						unset($search_array[$objs->values_props]);
					}elseif(!$this->hchinese($objs->values_title)){#2014-12-19无中文不用翻译
						$data->intGroups[$k]->values[$ks]->trans=$objs->values_title;
					}else{
						$this->addDataText($objs->values_title,'attr');
						list($this->ForTrans_key[$this->index]['pid'],$this->ForTrans_key[$this->index]['vid'])=explode(':',$objs->values_props);
						$this->ForTrans_key[$this->index]['name']=$objs->values_title;//添加中文
						$data->intGroups[$k]->values[$ks]->t_key=$this->index;
						$this->index++;	
					}
				}
			
			}
		}//end_group
		
		if($MultiFlag){//将数据库中MultiFlag的值代入并提取要翻译的数据入翻译队列
			foreach($data->intMultiFilters as $k=>$obj){
				if(isset($rows[$obj->pid.':0']) && $rows[$obj->pid.':0']){
					$data->intMultiFilters[$k]->trans=$rows[$obj->pid.':0'];
					unset($search_array[$obj->pid.':0']);
				}elseif(!$this->hchinese($obj->title)){#2014-12-19无中文不用翻译
					$data->intMultiFilters[$k]->trans=$obj->title;
				}else{
					$this->addDataText($obj->title,'prop');
					$this->ForTrans_key[$this->index]['pid']=$obj->pid;
					$this->ForTrans_key[$this->index]['vid']=0;
					$this->ForTrans_key[$this->index]['name']=$obj->title;
					$data->intMultiFilters[$k]->t_key=$this->index;
					$this->index++;
				}
				
				foreach($obj->values as $ks=>$objs){
					if(isset($rows[$objs->values_props])){ 
						$data->intMultiFilters[$k]->values[$ks]->trans=$rows[$objs->values_props];
						unset($search_array[$objs->values_props]);
					}elseif(!$this->hchinese($objs->values_title)){#2014-12-19无中文不用翻译
						$data->intMultiFilters[$k]->values[$ks]->trans=$objs->values_title;
					}else{
						$this->addDataText($objs->values_title,'prop');
						list($this->ForTrans_key[$this->index]['pid'],$this->ForTrans_key[$this->index]['vid'])=explode(':',$objs->values_props);
						$this->ForTrans_key[$this->index]['name']=$objs->values_title;
						$data->intMultiFilters[$k]->values[$ks]->t_key=$this->index;
						$this->index++;
					}
				}
			
			}
		}//end_MultiFlag

		if(!$this->ForTrans_data) return $data;
		$search_array=NULL;
	
		//第四步：将未有翻译的执行翻译并将翻译的数据格式化  #0代表是搜索数据翻译
		$this->DoTranslate(0); 
			
		//第五步：保存已翻译数据入库
		$this->saveData();
	
		//将所有翻译的数据还原回$data
		if($groupFlag){//还原group数据
			foreach($data->intGroups as $k=>$obj){
				if(isset($obj->t_key)) $data->intGroups[$k]->trans=$this->ForTrans_data[$obj->t_key][1];
				foreach($obj->values as $sk=>$sobj){
					if(isset($sobj->t_key)) $data->intGroups[$k]->values[$sk]->trans=$this->ForTrans_data[$sobj->t_key][1];
					}
			}
		}
		
		if($MultiFlag){//还原MultiFilter数据
			foreach($data->intMultiFilters as $k=>$obj){
				if(isset($obj->t_key)) $data->intMultiFilters[$k]->trans=$this->ForTrans_data[$obj->t_key][1];
				foreach($obj->values as $sk=>$sobj){
					if(isset($sobj->t_key)) $data->intMultiFilters[$k]->values[$sk]->trans=$this->ForTrans_data[$sobj->t_key][1];
					}
			}
		}

		//处理完成，释放内存
		$this->ForTrans_data=$this->ForTrans_key=array();
		return $data;
		
  	}
	
	#执行翻译 $t:1,Product 0,Search 2,Comment
	public function DoTranslate($t){		
		if(!count($this->ForTrans_data)) return true;
		$start=microtime(true);
		$this->{'DoTranslate_'.$this->type}();
		if($this->recordTime){
			$this->CostTime=round(microtime(true)-$start,3);
			$data=array(
				't'=>time(),
				'c'=>$this->CostTime,
				'ty'=>$this->type,
				'cl'=>$t,
				);
			$this->globalObj->Redis->lpush('TransTime',serialize($data));
		}
		return true;
	}
	
	#执行翻译 通道：DropShop
	public function DoTranslate_1(){
		$post=urlencode(convert_uuencode(gzcompress(serialize($this->ForTrans_data),9)));
        $ch=Translate::getCurl($this->transArr['url']);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, array('translationArray' => $post));
#$this->showTest($this->ForTrans_data,false);
		#2014-12-31 循环翻译
		for($i=0;$i<=10;$i++){
			$data=curl_exec($ch);
			if(curl_errno($ch)) continue;
			if(!$data) continue;
			#解析数据
			$this->ForTrans_data=unserialize($data);
			$hasChina=false;
			foreach($this->ForTrans_data as $k=>$row){
				if(isset($row[1])){
					if(trim($row[1])=='' || $this->hasChina($row[1])){
						$hasChina=true;
						break;
					}else $this->ForTrans_data[$k][1]=strip_tags($row[1]);
				}else $hasChina=false;
				if($hasChina) break;
			}
			if($hasChina) continue;
			else break;
		}
#echo $i;$this->showTest($this->ForTrans_data);		
		curl_close($ch);
		$data=$post=NULL;
		return true;
	}
	
	#执行翻译 通道：BING
	public function DoTranslate_2(){
		//2014-12-29 发现发送的字符串中的字符&和不可见的特殊字符会导致BING报错
		$authHeader = "Authorization: Bearer ". $this->getToken();
		$translateUrl = "http://api.microsofttranslator.com/v2/Http.svc/TranslateArray";
		$k='0';
		$fromLanguage = "zh-CHS";	
		$toLanguage   = $this->to;
		$contentType  = 'text/plain';
		$inputStrArr='';	
		foreach($this->ForTrans_data as $k=>$arr) $inputStrArr.=str_replace(array('',"\n","&"),'',trim(preg_replace('/&hellip;/i','',current($arr))));
		$requestXml = $this->createReqXML($fromLanguage,$toLanguage,$contentType,$inputStrArr);
		#$this->showTest($requestXml,false);
		#2014-12-31 循环翻译
		for($i=0;$i<=3;$i++){
			$curlResponse=$this->curlRequest($translateUrl,$authHeader,$requestXml);		
			if(!$curlResponse) continue;
			$xml=simplexml_load_string($curlResponse,NULL,LIBXML_NOCDATA);
			$haschina=false;
			foreach($this->ForTrans_data as $k=>$row){
				if(isset($xml->TranslateArrayResponse[$k]->TranslatedText))
					$text=(array)$xml->TranslateArrayResponse[$k]->TranslatedText;
					$russian=@current($text);
					#if(trim($russian)=='' || $this->hasChina($russian)){
					#	$haschina=true;
					#	break;
					#}else 
					$this->ForTrans_data[$k][1]=$russian;
				}
			#if($haschina) continue;
			#else break;
			break;
		}
		#echo $i;$this->showTest($this->ForTrans_data);
		return true;
	}
	
	#2014-12-26 BING 
	public function createReqXML($fromLanguage,$toLanguage,$contentType,$inputStrArr) {
        $requestXml = "<TranslateArrayRequest>".
            "<AppId/>".
            "<From>$fromLanguage</From>". 
            "<Options>" .
             "<Category xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              "<ContentType xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\">$contentType</ContentType>" .
              "<ReservedFlags xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              "<State xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              #"<Uri xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              "<User xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
            "</Options>" .
            "<Texts>";
        $requestXml .=  $inputStrArr;
        $requestXml .= "</Texts>".
            "<To>$toLanguage</To>" .
          "</TranslateArrayRequest>";
        return $requestXml;
    }
	
	#2014-12-26 BIng DO TRANS
	public function curlRequest($url, $authHeader, $postData=''){
        $ch = curl_init();
        curl_setopt ($ch, CURLOPT_URL, $url);
        curl_setopt ($ch, CURLOPT_HTTPHEADER, array($authHeader,"Content-Type: text/xml"));
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        if($postData) {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        $curlResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        if ($curlErrno) return false;
        curl_close($ch);
        return $curlResponse;
    }

	
	
	#2014-12-19下午 写google翻译程序
	public function GoogleTranslate2($array,$to='ru'){
		if(!$array) return false;
		$char='';
		if(count($array)==1) $array[]='The end';
		foreach($array as $k=>$v){$char.="&text=".urlencode($v);}		
		$google_translator_url = "http://translate.google.com/translate_a/t?&ie=UTF-8&oe=UTF-8&client=t&sl=zh_CN&tl={$to}&".$char;
#$this->showTest($google_translator_url,false);		
		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,$google_translator_url);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER ,1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,20);
		curl_setopt($ch, CURLOPT_TIMEOUT,20);
		curl_setopt($ch, CURLOPT_MAXREDIRS,10);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);
		
		#2014-12-31 循环翻译
		for($i=0;$i<=10;$i++){
			$html=curl_exec($ch);
			if(curl_errno($ch)) continue;
			$k=preg_match_all('/\[\[\["(.*?)"\]\],,"zh-CN"/i',$html,$dst);
			if($k===false) continue;
			#如有中文重新翻译
			$hasChina=false;
			foreach($dst[1] as $k=>$v){
				if(trim($v)=='' || $this->hasChina($v)) $hasChina=true;
				}
			if($hasChina) continue;
			else break;
		}
$this->showTest($html);
		curl_close($ch);
		return $dst[1]; 
	}
	
	#执行翻译 通道Google
	public function DoTranslate_3(){
		#2014-12-19 下午开发翻译
		$trans_arr=array();	
#$this->showTest($this->ForTrans_data);			
		foreach($this->ForTrans_data as $k=>$arr) $trans_arr[]=current($arr);
		$dsg=$this->GoogleTranslate2($trans_arr,$this->to);
$this->showTest($dsg,false);	
		 foreach($this->ForTrans_data as $k=>$arr){
			@$this->ForTrans_data[$k][1]=$dsg[$k];
		}
$this->showTest($this->ForTrans_data);		
		return true;
	}
	
	#Bing通道验证token
	public function getToken(){	
		$paramArr = array(
			'grant_type'=>'client_credentials',
			'scope'=>'http://api.microsofttranslator.com',
			'client_id'=>$this->transArr['user']['clientID'],
			'client_secret'=>$this->transArr['user']['clientSecret']);		
		$ch=Translate::getCurl($this->transArr['tokenurl']);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query($paramArr));
		$objResponse=curl_exec($ch);
		$objResponse=json_decode($objResponse);
		if(isset($objResponse->error) && $objResponse->error){
			$objResponse=curl_exec($ch);
			$objResponse=json_decode($objResponse);
		}
		if(isset($objResponse->error) && $objResponse->error){
			$objResponse=curl_exec($ch);
			$objResponse=json_decode($objResponse);
		}
		return $objResponse->access_token;
	}
	
	#curl初始化	
	public static function getCurl($path){	
	    if(!self::$_curlObject){
			$ch=curl_init($path);
	    }else{
			$ch=self::$_curlObject;
			@curl_setopt($ch,CURLOPT_URL,$path);
			return $ch;
	    } 

		curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0');
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);
		curl_setopt($ch, CURLOPT_ENCODING, ''); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, false);
		curl_setopt($ch, CURLOPT_NOPROGRESS, true);
		curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 3600);
		$header = array();
		$header[] = "Connection: keep-alive";
		$header[] = "Keep-Alive: 30";
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		self::$_curlObject = $ch;
		return $ch;
	}

//翻译淘宝产品详情页数据,传入数据对象，1提取title、item_attributes、props中中文，2，查询1中在数据库取未有翻译的,3将未翻译的执行翻译，4将翻译后的数组和对象合并，5返回最终数组
	public function TransTbData($data,$props_name=array(),$type=1,$to='ru'){

		#基本过滤及初始化翻译
		if(!$this->globalObj) exit('globalObj is NULL');
		if(!$data) return 'null data';
		$this->init($type);
		$this->index && $this->index=0;
		$this->ForTrans_data && $this->ForTrans_data=array();
		$this->ForTrans_key && $this->ForTrans_key=array();
		$this->to=$to;

		#第1步:提取API中item_attributes以查询
		$new_prop=$search_array=array();$ApiFlag=false;
		if(isset($props_name['item_get_response']['item']['props_name']) && $props_name['item_get_response']['item']['props_name']){
			$props_name=explode(';',$props_name['item_get_response']['item']['props_name']);
			$ApiFlag=true;
			foreach($props_name as $k=>$props){
				$prop=explode(':',$props);
				$new_prop[]=array(
					'pid'=>$prop[0],
					'vid'=>$prop[1],
					'pn'=>$prop[2],
					'vn'=>$prop[3],
					);
				$search_array[$prop[0].':0']=$search_array[$prop[0].':'.$prop[1]]='';
			}
		}
	
		#第2步:提取props的pid/vid查询REDIS数据
		$propFlag=false;
		if(isset($data->item->props) && $data->item->props) $propFlag=true;
		$rows=$search_array?$this->searchDb($search_array):array();

		#第3步:将标题写入翻译队列.
		if($data->item->title){
			$this->addDataText($data->item->title,'text');
			$data->item->t_key=$this->index;
			$this->index++;
		}
		
		#第4步：将SKU展示的属性值加入翻译队列
		if($propFlag){
			foreach($data->item->props as $pid=>$pidArr){	
				if($this->hchinese($pidArr->name)){#2014-12-19 无中文不用翻译
					$this->addDataText($pidArr->name,'prop');
					$data->item->props[$pid]->t_key=$this->index;
					$this->index++;
				}else{
					$data->item->props[$pid]->trans=$pidArr->name;	
				}
				foreach($pidArr->childs as $sk=>$vidObj){
					if($this->hchinese($vidObj->name)){#2014-12-19 无中文不用翻译
						$this->addDataText($vidObj->name,'prop');
						$data->item->props[$pid]->childs[$sk]->t_key=$this->index;
						$this->index++;
					}else{
						$data->item->props[$pid]->childs[$sk]->trans=$vidObj->name;	
					}
				}
			}
		}

		#第5步：将API中item_attributes属性对比查询REDIS得出的数据决定哪些需要翻译
		if($ApiFlag){	
			foreach($new_prop as $k=>$row){
				if(isset($rows[$row['pid'].':0']) && $rows[$row['pid'].':0']) $new_prop[$k]['pru']=$rows[$row['pid'].':0'];
				elseif(!$this->hchinese($row['pn'])){#2014-12-19 无中文不用翻译
					$new_prop[$k]['pru']=$row['pn'];
				}else{
					$this->addDataText($row['pn'],'prop');
					$this->ForTrans_key[$this->index]['pid']=$row['pid'];
					$this->ForTrans_key[$this->index]['vid']=0;
					$this->ForTrans_key[$this->index]['name']=$row['pn'];
					$new_prop[$k]['t_pkey']=$this->index;
					$this->index++;
				}
				if(isset($rows[$row['pid'].':'.$row['vid']]) && $rows[$row['pid'].':'.$row['vid']]) $new_prop[$k]['vru']=$rows[$row['pid'].':'.$row['vid']];
				elseif(!$this->hchinese($row['vn'])){#2014-12-19 无中文不用翻译
					$new_prop[$k]['vru']=$row['vn'];
				}else{
					$this->addDataText($row['vn'],'prop');
					$this->ForTrans_key[$this->index]['pid']=$row['pid'];
					$this->ForTrans_key[$this->index]['vid']=$row['vid'];
					$this->ForTrans_key[$this->index]['name']=$row['vn'];
					$new_prop[$k]['t_vkey']=$this->index;
					$this->index++;
				}
			}	
		}

	
		#第6步:调取翻译接口执行翻译，1表示翻译产品，以记录翻译用时时间
		$this->DoTranslate('1');	

		#第7步:保存除标题外的属性数据入PVID存储redis
		$this->saveData();

		#第8步:处理翻译后的数据
		if(isset($data->item->t_key) && $this->ForTrans_data[$data->item->t_key][1]) $data->item->trans=$this->ForTrans_data[$data->item->t_key][1];
#$this->showTest($this->ForTrans_data,false);		
		if($propFlag){
			foreach($data->item->props as $pid=>$pidArr){
				if(isset($pidArr->t_key)) $data->item->props[$pid]->trans=$this->ForTrans_data[$pidArr->t_key][1];
				foreach($pidArr->childs as $sk=>$vidObj){
					if(isset($vidObj->t_key)) $data->item->props[$pid]->childs[$sk]->trans=$this->ForTrans_data[$vidObj->t_key][1];	
				}
			}
		}
		if($ApiFlag){//API属性值如果存在替换原来的item_arrributes并归类属性值
			$item_arrributes_ru=array();$index=0;
			foreach($new_prop as $k=>$row){
				$pru=isset($row['t_pkey'])?$this->ForTrans_data[$row['t_pkey']][1]:$row['pru'];
				$vru=isset($row['t_vkey'])?$this->ForTrans_data[$row['t_vkey']][1]:$row['vru'];
				//将new_prop中相同PID值归类，并生成与data中item_arrributes相同的数据格式传回以便前端调用
				if(isset($item_arrributes_ru[$row['pid']])){
					$item_arrributes_ru[$row['pid']]->val.=' '.$vru;	
				}else{
					$item_arrributes_ru[$row['pid']]=new stdClass();
					$item_arrributes_ru[$row['pid']]->prop=$pru;
					$item_arrributes_ru[$row['pid']]->val=$vru;
				}
			}
			$data->item->item_attributes=$item_arrributes_ru;
		}

		#第9步：释放内存并返回数据			
		$item_arrributes_ru=$new_prop=$this->ForTrans_data=$this->ForTrans_key=NULL;
#$this->showTest($data);
		return $data;
		
	}
	
//------------------------------------------------------------------------------------------------	

	#翻译评论内容
	public function doTransPinglun($data,$type,$to='ru'){
		
		#基本初始化
		if(!$data) return array();
		$this->ForTrans_data=$this->ForTrans_key=array();
		$this->index=0;
		$this->init($type);
		$this->to=$to;
		
		#写入翻译队列
		foreach($data->comment as $k=>$plArr){
			$this->addDataText($plArr->pl_text,'text');
			$data->comment[$k]->t_key=$this->index;
			$this->index++;
			}

		#执行翻译并记录用时,2表示翻译评论
		$this->DoTranslate('2');
		#$this->showTest($this->ForTrans_data);
		
		#数据还原
		foreach($data->comment as $k=>$plArr){
			if(isset($plArr->t_key) && isset($this->ForTrans_data[$plArr->t_key][1]))
				$data->comment[$k]->trans=$this->ForTrans_data[$plArr->t_key][1];
		}

		#注销释放内存并返回
		$this->ForTrans_data=$this->ForTrans_key=array();
		return $data;
		
	}

	#辅助函数：ClearHTML
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