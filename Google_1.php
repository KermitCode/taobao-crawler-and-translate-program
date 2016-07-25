<?php

/*
 *Google翻译PHP接口
 */

$Google = new Google();
echo $Google->translate('中国', 'en');

class Google{

    public $out = "";
    public $google_translator_url = 'http://translate.google.com/translate_a/t';
    public $text = '';
    public $from = '';
    public $to = '';
    
    function setText($text){ $this->text = $text;}
    function translate($text, $to = 'ru'){
        $this->out  = "";
        $this->text = $text;
        $this->from = 'zh_CN';
        $this->to   = $to;        
        $gphtml = $this->postPage();	
        $arr = $this->decode($gphtml, 1);
        if(is_array($arr['sentences'])){
            foreach ($arr['sentences'] as $val){
                $this->out .= $val['trans'];
            }
        }
        return $this->out;
    }
    
    function postPage(){
        $sockfd=socket_create(AF_INET,SOCK_STREAM,getprotobyname("tcp"));
        $enctext=urlencode($this->text);
        $post_fields="sl={$this->from}&tl={$this->to}&client=json&ie=UTF-8&oe=UTF-8&text={$enctext}";
        $post_content_length=strlen($post_fields); 
        $post_org="POST /translate_a/t HTTP/1.1\r\nHost: translate.google.com\r\nConnection: Close\r\nAccept-Encoding: gzip,deflate,sdch\r\nContent-Length: ".$post_content_length."\r\n\r\n".$post_fields;
        $ip=gethostbyname("translate.google.com");
        socket_connect($sockfd,$ip,80);
        socket_send($sockfd,$post_org,strlen($post_org),0);
        $buffer="";
        $html="";
        while(!strstr($buffer,"\r\n\r\n")){
            $buf="";
            $n=socket_recv($sockfd,$buf,2048,0);
            if($n!=0 && $n!=-1)
            {
                $buffer.=$buf;
            }           
        }
        $header=substr($buffer,0,strpos($buffer,"\r\n\r\n"));
        //echo $post_org."\r\n".$buffer;exit;
        if(!strstr($header,"chunked")){
            $html=substr($buffer,strpos($buffer,"\r\n\r\n")+4);
            while(1){
                $nrecv=socket_recv($sockfd,$buf,1024,0);
                if($nrecv!=0 && $nrecv!=-1){
                    $html.=$buf;
                }else{
                    socket_close($sockfd);
                    return  $this->decodeUnicode($html);
                }
            }
        }
        $html="";
        $body=substr($buffer,strpos($buffer,"\r\n\r\n")+4);
        $buf="";
        $lastlen=0;
        $recvloop=TRUE;
        $bufferloop=TRUE;
        $nRemainLen=0;
        while($recvloop){
            while($bufferloop){
                if($lastlen!=0){
                    $body=substr($body,$lastlen);
                }
                $pos=strpos($body,"\r\n");
                $len=hexdec(substr($body,0,$pos));
                if($len!=0){
                    $body=substr($body,$pos+2);
                    if(strlen($body)>$len+2){
                        $html.=substr($body,0,$len);
                        $body=substr($body,$len+$pos+4);
                        $lastlen=0;
                    }else{
                        $lastlen=$len+2-strlen($body);
                        if($len<strlen($body))
                            $html.=substr($body,0,(strlen($body)-$len)-2);
                        else $html.=$body;
                        $bufferloop=FALSE;
                    }
                }else{
                    return  $this->decodeUnicode($html);
                }
            }
            $buf="";
            $nrecv=socket_recv($sockfd,$buf,1024,0);
            if($nrecv!=0 && $nrecv!=-1){
                $nRemainLen+=$nrecv;
            }
            if($nRemainLen>$lastlen) {
                $bufferloop=TRUE;
                $lastlen=$nrecv-($nRemainLen-$lastlen);
                $html.=substr($buf,0,$lastlen-2);
                $nRemainLen=0;
            }
            else $html.=$buf;
        }
    }
    function decode($json,$assoc = false){
        $match = '/".*?(?<!\\\\)"/';
        $string = preg_replace($match, '', $json);
        $string = preg_replace('/[,:{}\[\]0-9.\-+Eaeflnr-u \n\r\t]/', '', $string);
        if ($string != '') { return null;}
        $s2m = array();
        $m2s = array();
        preg_match_all($match, $json, $m);
        foreach ($m[0] as $s) {
            $hash = '"' . md5($s) . '"';
            $s2m[$s] = $hash;
            $m2s[$hash] = str_replace('$', '\$', $s);
        }
        $json = strtr($json, $s2m);
        $a = ($assoc) ? '' : '(object) ';
        $data = array(
            ':' => '=>', 
            '[' => 'array(', 
            '{' => "{$a}array(", 
            ']' => ')', 
            '}' => ')'
        );
        $json = strtr($json, $data);
        $json = preg_replace('~([\s\(,>])(-?)0~', '$1$2', $json);
        $json = strtr($json, $m2s);
        $function = @create_function('', "return {$json};");
        $return = ($function) ? $function() : null;
        unset($s2m); 
        unset($m2s); 
        unset($function);
        return $return;
    }
    function decodeUnicode($str) {
        return preg_replace_callback('/\\\\u([0-9a-f]{4})/i',
                create_function(
                    '$matches',
                    'return mb_convert_encoding(pack("H*", $matches[1]), "UTF-8", "UCS-2BE");'
                ),
                $str);
    }
}