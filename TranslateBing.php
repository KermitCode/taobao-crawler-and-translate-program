<?php

/*
 *NOTE:对抓取回来的数据中的Group和multiFilter进行查询、翻译和入库保存：
 *    :对详情页中产品的属性/标题/进行查询、翻译和入库保存
 *SPECIAL：不使用DROPSHOP,采用BING
 *		 : 从CLASS BASESPRIDER EXTEND
 */
 
class TranslateBing extends BaseSprider{

  	public $globalObj;
	public $LID;
	public $CostTime=NULL;
	public $transUrl;
	public $accessToken=NULL;
	public $ForTrans_data=array();
	public $ForTrans_key=array();
	public $authUrl = 'https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/';
	public $BingUser=array(
		array('clientID'=>'count1','clientSecret'=>'secret1'),
		);
	

	public $index=0;
	public $Titlecache=86400;		//缓存标题的时间:置为0则不开启
	public $TitleKey=false;
	
	public function __construct($globalObj=Null,$language_id=6,$url='') {
		
		$this->globalObj=$globalObj;
		$this->LID=$language_id;
		$this->getTokens();
		return true;
	
	}
	
	#验证BING账号
	public function getTokens(){

		$randkey=rand(0,2);//array_rand($this->BingUser);
		$paramArr = array(
			'grant_type'=>'client_credentials',
			'scope'=>'http://api.microsofttranslator.com',
			'client_id'=>$this->BingUser[$randkey]['clientID'],
			'client_secret'=>$this->BingUser[$randkey]['clientSecret']
			);
		
		$ch=$this->getCurl($this->authUrl,'');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query($paramArr));		
		$strResponse = curl_exec($ch);
		$this->closeCurl();

		$objResponse = json_decode($strResponse);
		if(isset($objResponse->error) && $objResponse->error){
			exit($objResponse->error.':'.$objResponse->error_description);
		}
		$this->accessToken=$objResponse->access_token;
		return $this->accessToken;

	}

	#执行bin翻译数据.
	public function TransSingleText($text,$to='en'){

		$authHeader = "Authorization: Bearer ".$this->accessToken;
		$url = 'http://api.microsofttranslator.com/V2/Http.svc/Translate?text='.urlencode($text).'&to='.$to.'&contentType=text/plain';
		$strResponse = $this->curlRequest($url,$authHeader);
		$xmlObj = simplexml_load_string($strResponse);
		foreach((array)$xmlObj[0] as $val){$response = $val;}
		print_r($response);

	}
	
	private function curlRequest($url, $authHeader, $postData = '') {
		$ch = curl_init();
		// curl_setopt($ch,CURLOPT_PROXYTYPE,CURLPROXY_SOCKS5);
		// curl_setopt($ch, CURLOPT_PROXY, "72.247.48.10:80");
		// curl_setopt($ch, CURLOPT_PROXYUSERPWD, "root:11111111");
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array($authHeader, "Content-Type: text/xml"));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		// curl_setopt($curl,CURLOPT_PROXYTYPE,CURLPROXY_SOCKS5);//使用了SOCKS5代理
		// curl_setopt($curl, CURLOPT_PROXY, "192.11.222.124:8000");
		if ($postData) {
		  curl_setopt($ch, CURLOPT_POST, true);
		  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		}
	
		$curlResponse = curl_exec($ch);
		curl_close($ch);
		return $curlResponse;
		
    }

	public function transArray(){
		
		$authHeader = "Authorization: Bearer ".$this->accessToken;
		$url='http://api.microsofttranslator.com/V2/Http.svc/TranslateArray';
		$data='<TranslateArrayRequest><AppId></AppId><From>zh-CHS</From><Texts>
				<string xmlns="http://schemas.microsoft.com/2003/10/Serialization/Arrays">你叫什么名字</string>
				<string xmlns="http://schemas.microsoft.com/2003/10/Serialization/Arrays">你来自哪个国家</string>
				</Texts><To>en</To></TranslateArrayRequest>';
		return $this->curlRequest($url, $authHeader, $data);

	}
	
	
	public function trans(){
		
		$authHeader = "Authorization: Bearer ".$this->accessToken;
		$url='http://api.microsofttranslator.com/V2/Http.svc/TranslateArray';
		$data='<TranslateArrayRequest><AppId></AppId><From>zh-CHS</From><Texts>
		<string xmlns="http://schemas.microsoft.com/2003/10/Serialization/Arrays">你叫什么名字</string></Texts><To>en</To></TranslateArrayRequest>';
		return $this->curlRequest($url, $authHeader, $data);

	}
	

	public function checkLanuage(){
		
		$text='LOVE';
		$authHeader = "Authorization: Bearer ".$this->accessToken;
		$url = 'http://api.microsofttranslator.com/V2/Http.svc/Detect?text='.urlencode($text);
		$strResponse = $this->curlRequest($url,$authHeader);
		echo '<pre>';
		print_r($strResponse);
		echo '</pre>';
	
	}




























	
	public function doTransSearch($data){

		if(!$this->db) exit('db is NULL');		
		if(!$data || !count($data->item)) return $data;
		
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
			
		//第二步：构造sql查询数据库已有的中文翻译
		$rows=$this->searchDb($search_array);
		
		//第三步：遍历rows将有翻译数据代入，未有翻译的写入待翻译队列
		if($groupFlag){//将数据库中group的值代入并提取要翻译的数据入翻译队列
			foreach($data->intGroups as $k=>$obj){
				if(isset($rows[$obj->pid.':0']) && $rows[$obj->pid.':0']){
					$data->intGroups[$k]->ru=$rows[$obj->pid.':0'];
					unset($search_array[$obj->pid.':0']);
				}else{
					$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['attr'].'" >'.$obj->title.'</translation>');
					$this->ForTrans_key[$this->index]['pid']=$obj->pid;
					$this->ForTrans_key[$this->index]['vid']=0;
					$this->ForTrans_key[$this->index]['name']=$obj->title;//添加中文
					$data->intGroups[$k]->t_key=$this->index;
					$this->index++;
				}
				
				foreach($obj->values as $ks=>$objs){
					if(isset($rows[$objs->values_props])){ 
						$data->intGroups[$k]->values[$ks]->ru=$rows[$objs->values_props];
						unset($search_array[$objs->values_props]);
					}else{
						$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['attr'].'" >'.$objs->values_title.'</translation>');
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
					$data->intMultiFilters[$k]->ru=$rows[$obj->pid.':0'];
					unset($search_array[$obj->pid.':0']);
				}else{
					$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['prop'].'" >'.$obj->title.'</translation>');
					$this->ForTrans_key[$this->index]['pid']=$obj->pid;
					$this->ForTrans_key[$this->index]['vid']=0;
					$this->ForTrans_key[$this->index]['name']=$obj->title;
					$data->intMultiFilters[$k]->t_key=$this->index;
					$this->index++;
				}
				
				foreach($obj->values as $ks=>$objs){
					if(isset($rows[$objs->values_props])){ 
						$data->intMultiFilters[$k]->values[$ks]->ru=$rows[$objs->values_props];
						unset($search_array[$objs->values_props]);
					}else{
						$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['prop'].'" >'.$objs->values_title.'</translation>');
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
			
		//第四步：将未有翻译的执行翻译并将翻译的数据格式化
		$start=microtime(true);
		$this->formatTransData($this->sendRemote());
		$this->CostTime=round(microtime(true)-$start,3);
			
		//第五步：保存已翻译数据入库
		$this->saveData();
		
		//将所有翻译的数据还原回$data
		if($groupFlag){//还原group数据
			foreach($data->intGroups as $k=>$obj){
				if(isset($obj->t_key)) $data->intGroups[$k]->ru=$this->ForTrans_data[$obj->t_key][1];
				foreach($obj->values as $sk=>$sobj){
					if(isset($sobj->t_key)) $data->intGroups[$k]->values[$sk]->ru=$this->ForTrans_data[$sobj->t_key][1];
					}
			}
		}
		
		if($MultiFlag){//还原MultiFilter数据
			foreach($data->intMultiFilters as $k=>$obj){
				if(isset($obj->t_key)) $data->intMultiFilters[$k]->ru=$this->ForTrans_data[$obj->t_key][1];
				foreach($obj->values as $sk=>$sobj){
					if(isset($sobj->t_key)) $data->intMultiFilters[$k]->values[$sk]->ru=$this->ForTrans_data[$sobj->t_key][1];
					}
			}
		}

		//处理完成，释放内存
		$this->ForTrans_data=$this->ForTrans_key=array();
		return $data;
		
  	}
	
	//查询数据库中PID_VID已经翻译的值
	
	public function searchDb($search_array){
		
		$sql='0';
		foreach($search_array as $k=>$v){
			list($pid,$vid)=explode(':',$k);
			$sql.=" or (PID={$pid} AND VID={$vid})";
			}
		$sql='select PID,VID,TEXT from '.DB_PREFIX.'sku_propvals where LID='.$this->LID.' and ('.$sql.')';
		$query=$this->db->query($sql);
		$rows=array();
		if($query && $query->rows){
			foreach($query->rows as $k=>$row){	
				$rows[$row['PID'].':'.$row['VID']]=$row['TEXT'];
			}
		}
		//释放内存并返回数据
		$query=$sql=$search_array=NULL;
		return $rows;

	}
	
	//unserialize翻译后的数据
	
	public function formatTransData($ret){
		
		if(!$this->ForTrans_data) return;
		$this->ForTrans_data=unserialize($ret);
		foreach($this->ForTrans_data as $k=>$row){
			//也可使用正则来取数据
			//$res=preg_match('/title="(?:.*)" >(.*)<\/translation>/su',$this->ForTrans_data[$i][1],$pregdata);
			if(isset($row[1])) $this->ForTrans_data[$k][1]=strip_tags($row[1]);
			}
		
	}
		
	//保存数据入库
	
	public function saveData(){
	
		if(empty($this->ForTrans_key)) return ;
		$sql='insert ignore into '.DB_PREFIX.'sku_propvals(LID,PID,VID,ZH,TEXT) values';$sqladd='';
		
		foreach($this->ForTrans_key as $k=>$pidvid){
			if(isset($this->ForTrans_data[$k][1]) && $this->ForTrans_data[$k][1]){
				$t=addslashes($this->ForTrans_data[$k][1]);
				$c=addslashes($this->ForTrans_key[$k]['name']);
				$sqladd.="({$this->LID},{$pidvid['pid']},{$pidvid['vid']},'{$c}','{$t}'),";
			}
		}
		
		if($sqladd){
			$sqladd=rtrim($sqladd,',');
			$this->db->query($sql.$sqladd);
		}
	
		return;
		
	}
	
	//发送翻译请求
	
	public function sendRemote(){
		
		if(!$this->ForTrans_data) return;
		$post=urlencode(convert_uuencode(gzcompress(serialize($this->ForTrans_data),9)));	
        $l = strlen($post);
        #$ch = Translate::getCurl($this->transUrl);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, array('translationArray' => $post));
		$fHeader = fopen('php://temp', 'w+');
		$fBody = fopen('php://temp', 'w+');
		curl_setopt($ch, CURLOPT_WRITEHEADER, $fHeader);
		curl_setopt($ch, CURLOPT_FILE, $fBody);
		curl_exec($ch);
		if(curl_errno($ch)>0) $this->showError(__FILE__,__FUNCTION__,__LINE__,'curl error code:'.curl_errno($ch));
		fseek($fBody, 0);$fStat = fstat($fBody);$ret = false;
		if ($fStat['size'] > 0) $ret = fread($fBody, $fStat['size']);
		fseek($fHeader, 0);$fStat = fstat($fHeader);
		if ($fStat['size'] > 0) $headers = fread($fHeader, $fStat['size']);
		fclose($fHeader);fclose($fBody);unset($fStat);

		$res = new stdClass();
		$res->info = curl_getinfo($ch);
		if(($res->info['http_code'] >= 400)) {
			if(!isset($ret)) $this->showError(__FILE__,__FUNCTION__,__LINE__,'curl get data error');
		}

		if($ret) return $ret;
		else return false;
		
	}
	


//翻译淘宝产品详情页数据,传入数据对象，1提取title、item_attributes、props中中文，2，查询1中在数据库取未有翻译的,3将未翻译的执行翻译，4将翻译后的数组和对象合并，5返回最终数组

	public function doTransItem($data,$props_name=array()){

		//初始化防止意外
		if(!$this->db) exit('db is NULL');
		if(!$data->item->num_iid) return;
		$this->index && $this->index=0;
		$this->ForTrans_data && $this->ForTrans_data=array();
		$this->ForTrans_key && $this->ForTrans_key=array();
		
		//2014-10-27添加页面简介内容
		$data->item->desc=isset($props_name['item_get_response']['item']['desc'])?$this->clearHtml($props_name['item_get_response']['item']['desc']):'';

		//第一步:提取API中item_attributes以查询
		$start=microtime(true);
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
		
		//第二步:提取props以查询  ** #2014-10-29 取消props查库
		$propFlag=false;
		if(isset($data->item->props) && $data->item->props){
			$propFlag=true;
			/*foreach($data->item->props as $pid=>$pidArr){
				$search_array[$pid.':0']='';
				foreach($pidArr->childs as $k=>$vidObj){
					$search_array[$pid.':'.$vidObj->vid]='';
				}
			}*/
		}
		
		//第三步:构造sql查询数据库已有的中文翻译
		$rows=$search_array?$this->searchDb($search_array):array();
		
		//第四步:写入翻译队列.先比对props中值，余下的看是不是有API数据来决定是否有必要取翻译值
		$proid=$data->item->num_iid;
		if($data->item->title){
			#2014-10-28 标题缓存
			if($this->Titlecache){
				$mustTime=time()-$this->Titlecache;
				$sql='select * from '.DB_PREFIX."title_cache where proid={$proid}";			
				$query=$this->db->query($sql);	
				if($query->num_rows){$data->item->ru=$query->row['text'];}
				else{
					$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['text'].'" >'.$data->item->title.'</translation>');
					$data->item->t_key=$this->index;
					$this->TitleKey=$this->index;
					$this->index++;
				}
			}else{
				$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['text'].'" >'.$data->item->title.'</translation>');
				$data->item->t_key=$this->index;
				$this->index++;
				}
		}
		
		#2014-10-29 SKU属性值与获取的props_name不一样，每次都需要翻译
		if($propFlag){
			foreach($data->item->props as $pid=>$pidArr){	
				//PID值的翻译处理 不入库
				//if(isset($rows[$pid.':0']) && $rows[$pid.':0']) $data->item->props[$pid]->ru=$rows[$pid.':0'];
				//else{
				$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['prop'].'" >'.$pidArr->name_zh.'</translation>');
				$data->item->props[$pid]->t_key=$this->index;
				$this->index++;
				//}
				//VID值的翻译处理 不入库
				foreach($pidArr->childs as $sk=>$vidObj){
					//if(isset($rows[$pid.':'.$vidObj->vid]) && $rows[$pid.':'.$vidObj->vid]) $data->item->props[$pid]->childs[$sk]->ru=$rows[$pid.':'.$vidObj->vid];
					//else{
					$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['prop'].'" >'.$vidObj->name_zh.'</translation>');
					$data->item->props[$pid]->childs[$sk]->t_key=$this->index;
					$this->index++;
					//}	
				}
			}
		}
		if($ApiFlag){//API中item_attributes对比
			foreach($new_prop as $k=>$row){
				if(isset($rows[$row['pid'].':0']) && $rows[$row['pid'].':0']) $new_prop[$k]['pru']=$rows[$row['pid'].':0'];
				else{
					$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['prop'].'" >'.$row['pn'].'</translation>');
					$this->ForTrans_key[$this->index]['pid']=$row['pid'];
					$this->ForTrans_key[$this->index]['vid']=0;
					$this->ForTrans_key[$this->index]['name']=$row['pn'];
					$new_prop[$k]['t_pkey']=$this->index;
					$this->index++;
				}
				if(isset($rows[$row['pid'].':'.$row['vid']]) && $rows[$row['pid'].':'.$row['vid']]) $new_prop[$k]['vru']=$rows[$row['pid'].':'.$row['vid']];
				else{
					$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type['prop'].'" >'.$row['vn'].'</translation>');
					$this->ForTrans_key[$this->index]['pid']=$row['pid'];
					$this->ForTrans_key[$this->index]['vid']=$row['vid'];
					$this->ForTrans_key[$this->index]['name']=$row['vn'];
					$new_prop[$k]['t_vkey']=$this->index;
					$this->index++;
				}
			}	
		}
		
		#$this->showTest($this->ForTrans_data);		
		//第五步:调取翻译接口执行翻译
		$this->formatTransData($this->sendRemote());
	
		//第六步:保存除标题外的属性数据入库
		if($this->Titlecache && $this->TitleKey!==false){
			$rutext=addslashes($this->ForTrans_data[$this->TitleKey][1]);
			if(!preg_match("/[\x{4E00}-\x{9FA5}]{1}/u",$rutext)){
				$sql='insert into '.DB_PREFIX."title_cache(proid,ptime,text) values({$proid},".time().",'{$rutext}')";
				$this->db->query($sql);
			}
		}
	
		#保存属性
		$this->saveData();

		//第七步:处理翻译后的数据传回
		if(isset($data->item->t_key) && $this->ForTrans_data[$data->item->t_key][1]) $data->item->ru=$this->ForTrans_data[$data->item->t_key][1];
		
		if($propFlag){
			foreach($data->item->props as $pid=>$pidArr){
				if(isset($pidArr->t_key)) $data->item->props[$pid]->ru=$this->ForTrans_data[$pidArr->t_key][1];
				foreach($pidArr->childs as $sk=>$vidObj){
					if(isset($vidObj->t_key)) $data->item->props[$pid]->childs[$sk]->ru=$this->ForTrans_data[$vidObj->t_key][1];	
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
		
		/*$this->showTest($this->ForTrans_data,false);		
		$this->showTest($this->ForTrans_key,false);
		$this->showTest($data);*/
		
		//释放内存及返回数据			
		$item_arrributes_ru=$new_prop=$this->ForTrans_data=$this->ForTrans_key=NULL;
		$this->CostTime=round(microtime(true)-$start,3);

		return $data;
		
	}
	
	#2014-11-1 翻译搜索关键词
	public function doTransText($word){
		
		#查库记录
		if(!$word) return '';
		$rskey=$this->searchKey($word);
		if($rskey) return $rskey;

		#执行翻译
		$start=microtime(true);
		$this->ForTrans_data=array(0=>array('<translation from="ru" to="zh-CHS" type="parseQuery" >'.$word.'</translation>'));
		$this->formatTransData($this->sendRemote());
		$this->CostTime=round(microtime(true)-$start,3);
		
		#入库
		if(isset($this->ForTrans_data[0][1]) && $key=$this->ForTrans_data[0][1]) $this->saveKey($word,$key);
		return $this->ForTrans_data[0][1];
		
	}
	
	#查询库中关键词
	public function searchKey($key){
		
		if(!$key) return '';
		$sql='select * from '.DB_PREFIX."searchquery where query_ru='".$this->db->escape($key)."' limit 1";			
		$query=$this->db->query($sql);	
		if($query->num_rows) return $query->row['query_zh'];
		else return '';
			
	}
	
	#将关键词入库保存
	public function saveKey($key_ru,$key_zh){
		
		if(!$key_zh || !$key_ru) return;
		if (preg_match("/^[\x7f-\xff]+$/",$key_zh)){
			$sql='insert ignore into '.DB_PREFIX."searchquery(query_ru,query_zh) values('{$key_ru}','{$key_zh}')";	
			$this->db->query($sql);
		}
		return;
		
	}
	
	#保存关键词搜索记录以查看热门搜索关键词
	public function recordSearch($key){
		if(!$key) return;
		$sql='insert into '.DB_PREFIX."searchhot(query,time) values('{$key}','".time()."')";
		$this->db->query($sql);
		return;
	}
	
	#取出最近一周热门搜索关键词
	public function getSearchHot($type='week'){
		
		switch($type){
			case 'week':$startPos=time()-7*86400;break;
			case 'month':$startPos=time()-30*86400;break;
			case 'day':$startPos=time()-86400;break;
			default :$startPos=time()-7*86400;
			}
		
		$sql='select query,count(query) as qnum from '.DB_PREFIX."searchhot where time>{$startPos} group by query order by qnum desc";
		$query=$this->db->query($sql);
		if($query->num_rows) return $query->rows;
		else return array();
		
	}
	
	#2014-11-1翻译评论内容
	public function doTransPinglun($data){
	
		#执行翻译
		$start=microtime(true);
		
		$this->ForTrans_data=$this->ForTrans_key=array();
		$this->index=0;
		if(!$data) return array();
		foreach($data->comment as $k=>$plArr){
			$tr_key=array_rand($this->type);
			$this->ForTrans_data[$this->index]=array('<translation from="zh-CHS" to="ru" type="'.$this->type[$tr_key].'" >'.$plArr->pl_text.'</translation>');
			$data->comment[$k]->t_key=$this->index;
			$this->index++;
			}
		
		$this->formatTransData($this->sendRemote());
		#$this->showTest($this->ForTrans_data,false);
		#$this->showTest($data);
		
		foreach($data->comment as $k=>$plArr){
			if(isset($plArr->t_key) && isset($this->ForTrans_data[$plArr->t_key][1]))
				$data->comment[$k]->ru=$this->ForTrans_data[$plArr->t_key][1];
		}
		$this->CostTime=round(microtime(true)-$start,3);
		
		#注销释放内存
		$this->ForTrans_data=$this->ForTrans_key=array();
		return $data;
		
	}

	//help for show Error
  
  	public function showTest($data,$stop=true){echo '<pre>';print_r($data);echo'</pre>';if($stop) exit;}
		
	//help FOR ERROR
	
	public function showError($fi,$fu,$li,$message=''){
	
		$mess_arr=array('ErrorHappened'=>array(
			'FilePos'=>$fi,
			'Function'=>'function '.$fu.'(){...}',
			'LineNumber'=>$li.' line.'
			));	
		$message && $mess_arr['message']=$message;
		$this->showTest($mess_arr);
	
	}
	
	//ClearHTML
	
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