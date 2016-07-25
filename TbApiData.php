<?php

#2014-12-03
#等同于TbData.PHP 将产品数据通过API接口获取和TbData.php取得的结构一样的数据
#将数据统一返回由日本服务器进行翻译

error_reporting(E_ALL ^ E_NOTICE);
set_time_limit(100);
date_default_timezone_set('Europe/Moscow');
!headers_sent() && header("Content-type:text/html;charset=utf-8");

class TbApiData{
	
	private $appId='1111111';
	private $appSecret='11111111111111111111111111111111';
	private $id;
	private $start;
	public $cost;
	
	#实例化类，可入参产品ID
	public function __construct($product_id=0) {
		$this->id = $product_id;
		$this->start=microtime(true);
	}
	
	#返回格式化数据函数
	public function formatData($data,$promotion){
		
		#基础数据
		$dsgData=new stdClass();
		$dsgData->item=new stdClass();
		$dsgData->item->num_iid=$data['item_get_response']['item']['num_iid'];
		$dsgData->item->cid=$data['item_get_response']['item']['cid'];
		$dsgData->item->nick=$data['item_get_response']['item']['nick'];
		$dsgData->item->title=$data['item_get_response']['item']['title'];
		$dsgData->item->pic_url=$data['item_get_response']['item']['pic_url'];
		$dsgData->item->post_fee=$data['item_get_response']['item']['post_fee'];
		$dsgData->item->ems_fee=$data['item_get_response']['item']['ems_fee'];
		
		$dsgData->item->price=$data['item_get_response']['item']['price'];
		$dsgData->item->num=$data['item_get_response']['item']['num'];
		$dsgData->item->location=@implode('.',array_reverse($data['item_get_response']['item']['location']));
		$dsgData->item->item_imgs->item_img=array();
		
		#图片数据转化为对象
		if(isset($data['item_get_response']['item']['item_imgs']['item_img']) && $data['item_get_response']['item']['item_imgs']['item_img']){
			foreach($data['item_get_response']['item']['item_imgs']['item_img'] as $k=>$row){
				$dsgData->item->item_imgs->item_img[$k]=(object)$row;		
			}
		}
		
		#要替换的props数据整理
		$property_new=array();
		if(isset($data['item_get_response']['item']['property_alias']) && $data['item_get_response']['item']['property_alias']){
			$property_alias=explode(';',$data['item_get_response']['item']['property_alias']);
			foreach($property_alias as $k=>$value){
				list($pid,$vid,$vname)=explode(':',$value);
				$property_new[$pid.':'.$vid]=$vname;
			}
		}
		
		#预处理属性图片
		$property_img=array();
		if(isset($data['item_get_response']['item']['prop_imgs']['prop_img']) && $data['item_get_response']['item']['prop_imgs']['prop_img']){
			foreach($data['item_get_response']['item']['prop_imgs']['prop_img'] as $k=>$row){
				$property_img[$row['properties']]=$row['url'];
			}
		}
	
		#取item_attributes  返回props_name由翻译里执行
		/*$dsgData->item->item_attributes=array();
		if($data['item_get_response']['item']['props_name']){
			$props=explode(';',$data['item_get_response']['item']['props_name']);
			$prop_arr=array();
			foreach($props as $k=>$value){
				list($pid,$vid,$pname,$vname)=explode(':',$value);
				$key=array_search($pname,$prop_arr);
				#是否要替换
				$new_key=$pid.':'.$vid;
				if(isset($property_new[$new_key])) $vname=$property_new[$new_key];
				if($key!==false){
					$dsgData->item->item_attributes[$key]->val.=' '.$vname;
				}else{
					$dsgData->item->item_attributes[$k]=new stdClass();
					$dsgData->item->item_attributes[$k]->prop=$pname;
					$dsgData->item->item_attributes[$k]->val=$vname;
					$prop_arr[$k]=$pname;
				}
				
			}
		}*/
		
		#价格预拼接
		$sku_price=array();
		$dsgData->item->promotion_price='';  
		if(isset($promotion['ump_promotion_get_response']['promotions']['promotion_in_item']['promotion_in_item'][0])){
			$dsgData->item->promotion_price=$promotion['ump_promotion_get_response']['promotions']['promotion_in_item']['promotion_in_item'][0]['item_promo_price'];
			foreach($promotion['ump_promotion_get_response']['promotions']['promotion_in_item']['promotion_in_item'][0]['sku_id_list']['string'] as $k=>$skuid){
				$skuid=(string)$skuid;
				$sku_price[$skuid]=$promotion['ump_promotion_get_response']['promotions']['promotion_in_item']['promotion_in_item'][0]['sku_price_list']['price'][$k];
			}
		}
		
		#props拼接  #同时添加进SKU值
		$dsgData->item->props=array();
		
		$dsgData->item->skus->sku=array();
		if(isset($data['item_get_response']['item']['skus']) && $data['item_get_response']['item']['skus']){
			foreach($data['item_get_response']['item']['skus']['sku'] as $k=>$row){
				$prop_arr=explode(';',$row['properties_name']);
				#SKU入数据
				$sku_add=new stdClass();
				$sku_add->price=$row['price'];
				$sku_add->properties=$row['properties'];
				$sku_add->properties_name= '';
				$sku_add->quantity=$row['quantity'];
				$sku_add->sku_id =$row['sku_id'];
				$skukey=(string)$row['sku_id'];
				@$sku_add->promotion_price=$sku_price[$skukey];
				$dsgData->item->skus->sku[]=$sku_add;
				
				#PROPS入数据
				foreach($prop_arr as $sk=>$prop){
					list($pid,$vid,$pname,$vname)=explode(':',$prop);
					if(!isset($dsgData->item->props[$pid])){
						$dsgData->item->props[$pid]=new stdClass();
						$dsgData->item->props[$pid]->cid=$data['item_get_response']['item']['cid'];
						#$dsgData->item->props[$pid]->name_zh=
						$dsgData->item->props[$pid]->name=$pname;
						$dsgData->item->props[$pid]->childs=array();
					}
			
					#添加[childs]
					if(!isset($dsgData->item->props[$pid]->childs[$vid])){
						$childObj=new stdClass();
						$childObj->vid=$vid;
						#是否要替换
						$new_key=$pid.':'.$vid;
						if(isset($property_new[$new_key])) $vname=$property_new[$new_key];
						#$childObj->name_zh=
						$childObj->name=$vname;
						$childObj->url=isset($property_img[$new_key])?$property_img[$new_key]:'';
						$dsgData->item->props[$pid]->childs[$vid]=$childObj;
					}		
				}		
			}
		}
		
		#添加props_name以供翻译使用
		$dsgData->item->props_name=$data['item_get_response']['item']['props_name'];
		$dsgData->item->Apidesc=$data['item_get_response']['item']['desc'];
		$dsgData->item->desc=$this->clearHtml($data['item_get_response']['item']['desc']);
		$dsgData->item->Apidesc=NULL;
		
		$this->cost=microtime(true)-$this->start;
		return $dsgData;
	}

	#总调用接口
	public function MethodProduct($product_id){
		if(!$product_id) exit('no id');
		$this->id=$product_id;
		
		#第一步：调产品信息
		$result=$this->MethodItem($product_id);
		#第二步：调产品prop
		$promotion=$this->MethodProp($product_id);
		
		#测试展示API抓取数据
		#print_r($result);print_r($promotion);exit;		
		
		#第三步：组合产品结构
		$data=$this->formatData($result,$promotion);
		
		#第四步：取回产品RATE和sellerID值
		$RateData=$this->getRate();
		#print_r($RateData);exit;	
	
		#将rate值组合进Data
		$data->item->isTmall=$RateData->item->isTmall;
		$data->item->seller_id=$RateData->item->seller_id;
		if(!isset($data->item->cid) || $data->item->cid) $data->item->cid=$RateData->item->cid;
		if(!isset($data->item->shop_id) || $data->item->shop_id) $data->item->shop_id=$RateData->item->shop_id;
		if(!isset($data->item->post_fee) || $data->item->post_fee) $data->item->post_fee=$RateData->item->post_fee;
		if(!isset($data->item->express_fee) || $data->item->express_fee) $data->item->express_fee=$RateData->item->express_fee;
		if(!isset($data->item->ems_fee) || $data->item->ems_fee) $data->item->ems_fee=$RateData->item->ems_fee;
		if(!isset($data->item->num) || $data->item->num) $data->item->num=$RateData->item->num;
		$data->rate=$RateData->rate;
		
		$RateData=NULL;
		return $data;
		
	}
	
	#取产品详细信息接口
	public function MethodItem($product_id=0){
		!$product_id && $product_id=$this->id;
		if(!$product_id) return false;
		$paramArr=array(
			  'fields'=>'cid,seller_cids,props,input_pids,input_str,pic_url,num,location,price,item_img,prop_img,score,num_iid,title,nick,desc,sku,props_name,property_alias,item_weight,change_prop,sub_title,sold_quantity,shop_type,post_fee,ems_fee',
			  'num_iid' => $product_id
		);
		return $this->api('taobao.item.get',$paramArr);
	}
	
	#提取产品prop详细信息
	public function MethodProp($product_id=0){
		!$product_id && $product_id=$this->id;
		if(!$product_id) return false;
		$paramArrs=array(
			'item_id'=>$product_id
		);
		return $this->api('taobao.ump.promotion.get',$paramArrs);
	}
	
	#底层调取淘宝接口
	public function api($method,$params=array()){
		#数据拼接
		$params=array_merge(array(
			'app_key' 	=> $this->appId,
			'method' 	=> $method,
			'session' 	=> '',
			'format' 	=> 'json',
			'v'			=> '2.0',
			'partner_id'=> 'top-sdk-php-20131101',
			'timestamp' => date('Y-m-d H:i:s'),
			'sign_method'=> 'md5'
		),$params);
		$params['sign']=$this->createSign($params,$this->appSecret);
		#执行调用
		$result = $this->makeRequest('http://gw.api.taobao.com/router/rest',$params);
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
	 
	#生成SIGN值
	private static function createSign($params=array(),$appSecret){
		ksort($params);
		$sign = $appSecret;
		foreach ($params as $key => $val) {
		    if(($key != '' && $val != '') || $val === 0) {
				$sign .= $key . $val;
		    }
		}
		return strtoupper(md5($sign . $appSecret));
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
	
	#抓取产品rate和sellerID值
	public function getRate(){
		require('TbRateData.php');
		$TbRateData=new TbRateData();
		return $TbRateData->getData(array('id'=>$this->id));
	}
	
}
	
#接受产品ID
$id=isset($_POST['product_id'])?$_POST['product_id']:'';
if(!$id) $id=isset($_GET['product_id'])?$_GET['product_id']:'';
if($id){
	$TbApiData=new TbApiData();
	$data=$TbApiData->MethodProduct($id);
	#echo '<pre>';print_r($data);echo '</pre>';exit;
	echo gzcompress(serialize($data),9);
}else{
	echo gzcompress(serialize(array('error'=>'no id value!')));
}