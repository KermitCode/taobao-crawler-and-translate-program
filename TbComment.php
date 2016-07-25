<?php

/*
 *Author:Kermit
 *Time:2014-12-06
 *Note:抓取评论抓取页程序
 */

error_reporting(E_ALL ^ E_NOTICE);
@set_time_limit(100);
date_default_timezone_set('Europe/Moscow');
!headers_sent() && header("Content-type:text/html;charset=utf-8");
class TbComment{

	public $DSGItemRes;
	public $params=array();
	public $DSGSearchRes;
	public $pageSize=44;
	public $long_limit=10;			//筛选太长在此设置截取多少个。需要先排一下序
	public $DSGobj;
	private static $_curlObject = FALSE;

	public function __construct($proxy=false,$curlUseMulti=true){

		}

	public function doGetPinglun($id,$sid,$page=1){
		
		#参数组装
		$url='http://rate.taobao.com/feedRateList.htm?userNumId='.$sid.'&auctionNumId='.$id.'&siteID=4&currentPageNum='.$page.'&orderType=sort_weight&showContent=1';
		$reffer='http://item.taobao.com/item.htm?id='.$id;
		$data=$this->curlGetNoRedirect($url,$reffer);	

		#数据处理
		$data=trim($data);
		$data=ltrim($data,'(');
		$data=rtrim($data,')');
		$data=json_decode($data);
	
		#评论提取
		$DsgComment=new stdClass();
		$DsgComment->maxPage=$data->maxPage;
		$DsgComment->commentnum=20 * $data->maxPage;
		$DsgComment->currentPageNum=$page;
		$DsgComment->comment=array();
		$keydata='date';
		
		foreach($data->comments as $k=>$comobj){
			$DsgComment->comment[$k]=new stdClass();
			$DsgComment->comment[$k]->pl_user_img=$comobj->user->avatar;
			$DsgComment->comment[$k]->pl_user_name=$comobj->user->nick;
			$DsgComment->comment[$k]->pl_user_grade=$comobj->user->displayRatePic?'http://a.tbcdn.cn/sys/common/icon/rank_s/'.$comobj->user->displayRatePic:'';
			$DsgComment->comment[$k]->pl_time=str_replace(array('年','月','日'),array('-','-',''),$comobj->$keydata);
			$DsgComment->comment[$k]->pl_text=$comobj->content;
			if(isset($comobj->photos) && $comobj->photos){
				$DsgComment->comment[$k]->pl_img=array();
				foreach($comobj->photos as $sk=>$imgobj){
					$DsgComment->comment[$k]->pl_img[$sk]=array(
						'thumbimg'=>$imgobj->thumbnail,
						'image'=>$imgobj->url,
						);
					}
				}
		}
		
		#释放内存并返回
		$data=$url=$reffer=NULL;
		return $DsgComment;

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
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Connection: keep-alive","Keep-Alive: 30")); 
	  if($this->proxy!='') {
		  curl_setopt($ch, CURLOPT_PROXYTYPE,CURLPROXY_HTTP);
		  curl_setopt($ch, CURLOPT_PROXY,$this->proxy);
	  }
	  return $ch;
   }	

}
#接受参数
$params=isset($_POST)?$_POST:array();
if(!$params && isset($_GET)) $params=$_GET;
$params=array('product_id'=>'45810520314','userNumId'=>'1891825317');
if($params){
	$TbComment=new TbComment();
	$data=$TbComment->doGetPinglun($params['product_id'],$params['userNumId'],$params['page']);
	echo "<pre>";print_r($data);echo '</pre>';exit;
	echo serialize($data);
}else{
	echo '';
}