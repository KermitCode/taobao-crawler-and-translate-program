<?php
error_reporting(E_ALL);
function GoogleTranslate($array,$to='ru'){
	if(!$array) return false;
	$char='';
	if(count($array)==1) $array[]='The end';
	foreach($array as $k=>$v){
		$char.="&text=".urlencode($v);
	}
	$google_translator_url = "http://translate.google.com/translate_a/t?&ie=UTF-8&oe=UTF-8&client=t&sl=zh_CN&tl={$to}&".$char;
	#$google_translator_url="http://translate.google.com/translate_a/t?&ie=UTF-8&oe=UTF-8&client=t&text=".$newtext."&sl=zh&tl={$to}";	
	$ch = curl_init();
	curl_setopt($ch,CURLOPT_URL,$google_translator_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER ,1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,20);
	curl_setopt($ch, CURLOPT_TIMEOUT,20);	
	$html=curl_exec($ch);
	if(curl_errno($ch)) $html = "";
	curl_close($ch);
    echo $html;
	if(!$html) return false;
	$k=preg_match_all('/\[\[\["(.*?)"\]\],,"zh-CN"/i',$html,$dst);
	if(!isset($dst[1]) || !$dst[1]) return false;
	return $dst[1]; 
}

/*$char='韦蓝琪男裤秋冬款加厚男士休闲裤男装修身直筒裤子男针织商务长裤,尺码,36(2.91尺=95CM【加厚】,35(2.82尺=92CM【加厚】,38(3.12尺=101CM【加厚】,40(3.24尺=107CM【加厚】,[买套餐或两件，享折上折！,34(2.73尺=89CM【加厚】,33(2.64尺=86CM【加厚】,29（2.28尺=74CM【加厚】,30(2.37尺=77CM【加厚】,31（2.46尺=80CM【加厚】,32（2.55尺=83CM【加厚】,28（2.19尺=71CM【加厚】,颜色,03黑色,02中灰色,韦蓝琪';*/
$char='中国,美国';
$array=explode(',',$char);
echo '<pre>';print_r($array);echo '</pre>';


$a=GoogleTranslate($array,'en');
echo '<pre>';print_r($a);echo '</pre>';

#echo $a;
#$a=explode(',',$a);