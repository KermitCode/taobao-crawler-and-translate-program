<?php

/*
 *Kermit:Write from 2014-10-9
 *Note:for spride taobao/tmall data
 */

class TbData extends BaseSprider{
	
	public $tid,$type,$extend_path;
	public $RuleXml,$itemParams,$DSGItemRes;
	public $referer='http://item.taobao.com/';
	public $proxy=false;						//'183.224.1.56:80'; //close set $proxy=false 
	private static $_curlObject = FALSE;
	public $fromCart=false;
	public $curlUseMulti=false;					//use multi curl for do faster and you can set false to use Single curl function
	public $logArr=array(),$st=0;
	public $postageArea='990000';				//data get from rustao config table:
	
	public function __construct($curlUseMulti=true,$proxy=false,$bug=false){
		$this->debug=$bug;		
		$this->curlUseMulti=$curlUseMulti;
		$this->extend_path=dirname(__FILE__).'/';
		if(!$this->st) $this->st=microtime(true);	
		if($proxy) $this->proxy=$proxy;
	}
	
	//GET DATA FUNCTION
	public function getData($params,$type='item.taobao.com'){
		error_reporting(E_ALL);
		$id=$params['id'];
		$start=microtime(true);
		if(!$id) return false;
		$this->tid=$id;
		$this->type=$type;
		$this->itemParams=$this->getDSGRules();
	
		//GET PAGE
		$ch=$this->getCurl($this->itemParams->url,$this->referer);
		$mr=$this->maxredirect;
		$open_basedir = ini_get('open_basedir');
		$safe_mode = ini_get('safe_mode');
	    if(($open_basedir == '') && (in_array($safe_mode, array('0', 'Off'))) || !$safe_mode){
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
		//deal data
		$data = new stdClass();
		$data->info = curl_getinfo($ch);	
		if($data->info['http_code'] >= 400){
			return 'http_code'.$data->info['http_code'];
		}else $data->data = @iconv('GBK', 'UTF-8//IGNORE', $ret);
		$ret=NULL;
		$data->url = $this->itemParams->url;	
		$data->isTmall = !(bool) $this->regexMatch('loaded_result_is_fake', $data->data);
		//change and start get form tmall
        if(($this->type == 'item.taobao.com') && $data->isTmall){
			$this->type='detail.tmall.com';
            $this->itemParams = $this->getDSGRules();
		}
	
        $this->DSGItemRes=new stdClass();
        $this->DSGItemRes->html = $data;
		$this->DSGItemRes->item=new stdClass();
		$this->DSGItemRes->item->fromDSG=true;
		$this->DSGItemRes->item->isTmall=$data->isTmall;
        
		if($data->isTmall) $this->parseTmall($data);
		else $this->parseTaobao($data);
		unset($this->DSGItemRes->html,$this->search_DSGRulesList,$this->itemParams);

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
		if($this->DSGItemRes->rate->diqu==''){
			$res=preg_match('/location:\'(.*)\',/u',$this->DSGItemRes->item->truePostage,$resstr);
			$this->DSGItemRes->rate->diqu=$res?$resstr[1]:'';
			}
	
		$this->costTime=round(microtime(true)-$start,3);
		if(!$this->DSGItemRes) return '抓取页面：'.$this->itemParams->url.' 未抓取到数据。';
		return $this->DSGItemRes;

	}
	

	public function regexReplaceCallback($ruleXPath,$callback, $subject, $limit = -1, $count = NULL) {
		$rule =$this->getDSGRule($ruleXPath);
		ini_set('pcre.backtrack_limit', 4*1024*1024);
		ini_set('pcre.recursion_limit', 1024*1024);
		$res = preg_replace_callback($rule,$callback,$subject,$limit,$count);
		return $res;
	}
	
	public function parseChineseNumber($n) {
		$resstr = array();
		if ($n > 0) {
			$zhnum = $n;$res = preg_match('/([\d\.]+)/i', $zhnum, $resstr);
			if ($res > 0) {
				if (($zhnum != $resstr[1]) || (strpos($resstr[0],'.')>0)){$resnum = round((float) $resstr[1] * 10000);}
				else{$resnum = $zhnum;}
		  	}else $resnum = 0;
		}else $resnum = 0;
		return $resnum;
	}
	
	//BASE LOAD RULE AND DATA
	public function getDSGRules($queryParams=array(),$xmlfile=true){
		$xmlfile=$xmlfile?'DSG_rulesList.xml':'DSG_searchList.xml';
		$xml=simplexml_load_string(file_get_contents($this->extend_path.$xmlfile),NULL,LIBXML_NOCDATA);
		$search=$xml->xpath("/parser_sections/parser_section[name='{$this->type}']");
		if(!isset($search[0]) && $this->debug) $this->showError(__FILE__,__FUNCTION__,__LINE__,"no {$this->type} tree in XML rule");
	    $this->RuleXml=$search[0];
		$result = new stdClass();
		$result->debug = $this->getDSGRule('debug') == 'true';
		$result->type = $this->getDSGRule('type');
		if($this->tid) $queryParams['id']=$this->tid;
		if($queryParams) $result->urlPreservedPart = http_build_query($queryParams);
		$result->url=$this->getDSGRule('base_url').$result->urlPreservedPart;
		return $result;
	}

	//GET DATA FROM XMLDATA
	public function getDSGRule($xpath){
		$xml = $this->RuleXml->xpath($xpath);
		if($xml){
			if (count($xml) > 1 || count($xml[0]) > 1) $res = $xml;
		 	else $res = trim((string) ($xml[0]));
			return $res;
		}else return FALSE;
	}
	
	//MAKE SINGLE CURL
	public function getCurl($path,$referer) {
		
	  if(gettype(self::$_curlObject) != 'resource') {
		$ch = curl_init($path);self::$_curlObject = $ch;
	  }else{  
		$ch = self::$_curlObject;@curl_setopt($ch,CURLOPT_URL,$path);
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
	  @$this->logArr['curllog'][]=$path;
	  return $ch;
   }	
	
	//REGEX MATCH TMALL
	
	public function regexMatch($ruleXPath,$subject,array &$matches = array(), $flags = 0, $offset = 0) {
		
		$rule = $this->getDSGRule($ruleXPath);
		ini_set('pcre.backtrack_limit', 4*1024*1024);
		ini_set('pcre.recursion_limit', 1024*1024);
		$res=preg_match($rule,$subject,$matches,$flags,$offset);
		if($res===false && $this->debug){$this->showError(__FILE__,__FUNCTION__,__LINE__,preg_last_error());}
		return $res;
 	 
	 }
	
	//CURL ONE EVERYTIME
	
    public function getHttpDocument($path,$urlPreserved = FALSE, $direct = FALSE,$referer='http://item.taobao.com/') {
		
		$ch = $this->getCurl($path,$referer);
		$ret=curl_exec($ch);
		$res = new stdClass();
		$res->info = curl_getinfo($ch);
		if ($res->info['http_code'] >= 400) $res->data = FALSE;
		else $res->data = @iconv('GBK', 'UTF-8//IGNORE', $ret);
		unset ($ret);
		return $res;
		
   }
   
   //CURL MULTI PAGES EVERYTIME FOR MORE FAST
   
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
		  do{
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
	
	//CLOSE CURL HANDLE
	
	 public function closeCurl() {
		if(gettype(self::$_curlObject) == 'resource') {
		 	curl_close(self::$_curlObject);
		}
	 }
	
	//JAVASCRIPT TO JSON CHAR
	public function JavaScriptToJSON($input, $asObject = FALSE) {
		if (($input == FALSE) || ($input == '')) return new stdClass();
		ini_set('pcre.backtrack_limit', 4*1024*1024);
		ini_set('pcre.recursion_limit', 1024*1024);
		if ($input{0} != '{') {
		  $res = preg_replace('/^.*?(?={)/s', '', $input);
		}else{
		  $res = $input;
		}
		$res = preg_replace('/[^}]*?$/s', '', $res);
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
	
	private function parseFilters($data) {
		$sections = array();
		$this->DSGSearchRes->intFilters = array();
		$block=array();
		$res = $this->regexMatch('parse_filter_values/block', $data, $block);
		if ($res > 0) {
		$res = $this->regexMatchAll('parse_filter_values/section', $block[1], $sections);
		  unset($block);
		if ($res > 0) {
		  foreach ($sections[1] as $section) {
			$this->DSGSearchRes->intFilters[] = new stdClass();
			$resstr=array();
			$res = $this->regexMatch('parse_filter_values/title', $section, $resstr);
			if ($res > 0) {
			  end($this->DSGSearchRes->intFilters)->title = $resstr[1];
			}
			else {
			  array_pop($this->DSGSearchRes->intFilters);
			  continue;
			}
			end($this->DSGSearchRes->intFilters)->values = array();
			$values_sections = array();
			$res = $this->regexMatchAll('parse_filter_values/values_section', $section, $values_sections);
			if ($res > 0) {
			  foreach ($values_sections[1] as $values_section) {
				end($this->DSGSearchRes->intFilters)->values[] = new stdClass();
				$res = $this->regexMatch('parse_filter_values/values_count', $values_section, $resstr);
				if ($res > 0) {
				  $resnum = $this->parseChineseNumber($resstr[1]);
				  end(end($this->DSGSearchRes->intFilters)->values)->values_count = $resnum;
				}else {
				  array_pop(end($this->DSGSearchRes->intFilters)->values);continue;
				}
				$res = $this->regexMatch('parse_filter_values/values_title', $values_section, $resstr);
				if ($res > 0) {
				  end(end($this->DSGSearchRes->intFilters)->values)->values_title = $resstr[1];
				}else {
				  array_pop(end($this->DSGSearchRes->intFilters)->values);continue;
				}
				$res = $this->regexMatch('parse_filter_values/values_props', $values_section, $resstr);
				if ($res > 0) {
				  end(end($this->DSGSearchRes->intFilters)->values)->values_props = urldecode($resstr[1]);
				}else {
				  array_pop(end($this->DSGSearchRes->intFilters)->values);continue;
				}
			  }
			  unset($values_section);
			  unset($values_sections);
			}
			else {
			  array_pop($this->DSGSearchRes->intFilters);continue;
			}
			if (!function_exists('compareByValueCount')) {
			  function compareByValueCount($a, $b) {
				$a_val = $a->values_count;
				$b_val = $b->values_count;
				if ($a_val > $b_val) {
				  return -1;
				}
				elseif ($a_val == $b_val) {
				  return 0;
				}
				else {
				  return 1;
				}
			  }
			}
			if ((isset(end($this->DSGSearchRes->intFilters)->values)) && (isset(end(end($this->DSGSearchRes->intFilters)->values)->values_props))) {
			  $props = explode(':', end(end($this->DSGSearchRes->intFilters)->values)->values_props);
			  if ((isset($props[0])) && (($props[0] == 30000) || ($props[0] == 403))) {
				array_pop($this->DSGSearchRes->intFilters);
				unset($props);
				continue;
			  }
			  unset($props);
			}
			if (isset(end($this->DSGSearchRes->intFilters)->values)) {
			  uasort(end($this->DSGSearchRes->intFilters)->values, "compareByValueCount");
			  end($this->DSGSearchRes->intFilters)->values = array_slice(end($this->DSGSearchRes->intFilters)->values, 0, $this->translator_long_list_count_limit);
			}
		  }
		  unset($sections);
		  unset($section);
		  unset($resstr);
		}
	  }
	  return;
  	}
	
	public function parseSuggestions($data) {
		$sections = array();$resstr = array();
		$this->DSGSearchRes->intSuggestions = array();
		$res = $this->regexMatchAll('parse_suggestions_values/section', $data, $sections);
		if ($res > 0) {
		  foreach ($sections[1] as $section) {
			$this->DSGSearchRes->intSuggestions[] = new stdClass();
			$res = $this->regexMatch('parse_suggestions_values/title', $section, $resstr);
			if ($res > 0) {
			  end($this->DSGSearchRes->intSuggestions)->title = $resstr[1];
			}
			else {
			  array_pop($this->DSGSearchRes->intSuggestions);
			  continue;
			}
			$res = $this->regexMatch('parse_suggestions_values/q', $section, $resstr);
			if ($res > 0) {
			  end($this->DSGSearchRes->intSuggestions)->q = @iconv('GBK', 'UTF-8//IGNORE', urldecode($resstr[1]));
			}
			else {
			  end($this->DSGSearchRes->intSuggestions)->q = '';
			}
			$res = $this->regexMatch('parse_suggestions_values/cid', $section, $resstr);
			if ($res > 0) {
			  end($this->DSGSearchRes->intSuggestions)->cid = $resstr[1];
			}
			else {
			  end($this->DSGSearchRes->intSuggestions)->cid = 0;
			}
		  }
		  unset($resstr);
		  unset($sections);
		  unset($section);
		}
		return;
  	}
	
	public function parseGroups($data) {
		$sections = array();$block=array();
		$res = $this->regexMatch('parse_group_values/block', $data, $block);		

		if ($res > 0) {
		  $res = $this->regexMatchAll('parse_group_values/section',$block[1],$sections);
		  unset($block);	  
		  if ($res > 0) {
		  	
			foreach ($sections[1] as $section) {
			  $this->DSGSearchRes->intGroups[] = new stdClass();
			  $resstr=array();
			  
			  $res = $this->regexMatch('parse_group_values/title', $section, $resstr);
			  if ($res > 0) end($this->DSGSearchRes->intGroups)->title = $resstr[1];
			  else {array_pop($this->DSGSearchRes->intGroups);continue;}
			  end($this->DSGSearchRes->intGroups)->values = array();
		  
			  $values_sections = array();
			  $res = $this->regexMatchAll('parse_group_values/values_section', $section, $values_sections);
			  if ($res > 0) {
				foreach ($values_sections[1] as $values_section) {
				  end($this->DSGSearchRes->intGroups)->values[] = new stdClass();
				  $res = $this->regexMatch('parse_group_values/values_count', $values_section, $resstr);
				  if ($res > 0) {
					$resnum = $this->parseChineseNumber($resstr[1]);
					end(end($this->DSGSearchRes->intGroups)->values)->values_count = $resnum;
				  }else {
					array_pop(end($this->DSGSearchRes->intGroups)->values);
					continue;
				  }
				  $res = $this->regexMatch('parse_group_values/values_title', $values_section, $resstr);
				  if ($res > 0) {
					end(end($this->DSGSearchRes->intGroups)->values)->values_title = $resstr[1];
				  }else {
					array_pop(end($this->DSGSearchRes->intGroups)->values);
					continue;
				  } 
				  $res = $this->regexMatch('parse_group_values/values_props', $values_section, $resstr);
				  if ($res > 0) {
					end(end($this->DSGSearchRes->intGroups)->values)->values_props = urldecode($resstr[1]);
				  }else {
					array_pop(end($this->DSGSearchRes->intGroups)->values);
					continue;
				  }

				}
				unset($values_section);
				unset($values_sections);
			  }
			  else {
				array_pop($this->DSGSearchRes->intGroups);
				continue;
			  }
			  if (!function_exists('compareByValueCount')) {
				function compareByValueCount($a, $b) {
				  $a_val = $a->values_count;
				  $b_val = $b->values_count;
				  if ($a_val > $b_val) {
					return -1;
				  }
				  elseif ($a_val == $b_val) {
					return 0;
				  }
				  else {
					return 1;
				  }
				}
			  }
			  if ((isset(end($this->DSGSearchRes->intGroups)->values)) && (isset(end(end($this->DSGSearchRes->intGroups)->values)->values_props))) {
				$props = explode(':', end(end($this->DSGSearchRes->intGroups)->values)->values_props);
				if ((isset($props[0])) && (($props[0] == 30000) || ($props[0] == 403))) {
				  array_pop($this->DSGSearchRes->intGroups);
				  unset($props);
				  continue;
				}
				unset($props);
			  }
			  if (isset(end($this->DSGSearchRes->intGroups)->values)) {
				uasort(end($this->DSGSearchRes->intGroups)->values, "compareByValueCount");
				end($this->DSGSearchRes->intGroups)->values = array_slice(end($this->DSGSearchRes->intGroups)->values, 0, $this->translator_long_list_count_limit);
			  }
			}
			unset($sections);
			unset($section);
			unset($resstr);
		  }
		}
		return;
  	}
	
	//PARSE CATEGORIES
	
	public function parseCategories($data) {
    
		$sections = array();$block=array();
		
		$res = $this->regexMatch('parse_categories_values/block',$data,$block);
		
		if($res>0){$res=$this->regexMatchAll('parse_categories_values/section',$block[1],$sections);
		
		$sections=explode('</li>',$sections[1][0]);		
		
		if ($res > 0) {
		 
			  foreach($sections as $section) {
				
				$this->DSGSearchRes->intCategories[] = new stdClass();
				$resstr=array();
				
				$res = $this->regexMatch('parse_categories_values/values_count',$section,$resstr);
				if($res>0){$resnum = $this->parseChineseNumber($resstr[1]);end($this->DSGSearchRes->intCategories)->count=$resnum;}
				else{array_pop($this->DSGSearchRes->intCategories);continue;}
	
				$res = $this->regexMatch('parse_categories_values/values_title',$section,$resstr);
				if($res>0){end($this->DSGSearchRes->intCategories)->title = $resstr[1];}
				else{array_pop($this->DSGSearchRes->intCategories);continue;}
							
				$res = $this->regexMatch('parse_categories_values/values_cid', $section, $resstr);
				if($res>0){end($this->DSGSearchRes->intCategories)->cid = $resstr[1];}
				else {end($this->DSGSearchRes->intCategories)->cid = 0;}
				
			  }
		  
			  unset($resstr,$sections,$section);
			 
			  if (!function_exists('compareByCount')) {
				function compareByCount($a, $b) {
				  $a_val = $a->count;
				  $b_val = $b->count;
				  if ($a_val > $b_val) {return -1;}
				  elseif ($a_val == $b_val) {return 0;}
				  else {return 1;}
				}
			  }
			  
			  if(isset($this->DSGSearchRes->intCategories)) uasort($this->DSGSearchRes->intCategories, "compareByCount");
	
			  if(isset($this->DSGSearchRes->intCategories)) $this->DSGSearchRes->intCategories = array_slice($this->DSGSearchRes->intCategories, 0,$this->translator_long_list_count_limit);
	
			}
		
	   	}
	  
   		return;
  
 	}
	
	public function parseMultiFilters($data) {
		$sections = array();
		$this->DSGSearchRes->intMultiFilters = array();
		$block=array();
		$res = $this->regexMatch('parse_multifilter_values/block', $data, $block);
		if ($res > 0) {
		$res = $this->regexMatchAll('parse_multifilter_values/section', $block[1], $sections);
		if ($res > 0) {
		  foreach ($sections[1] as $section) {
			$this->DSGSearchRes->intMultiFilters[] = new stdClass();
			$resstr=array();
			$res = $this->regexMatch('parse_multifilter_values/title', $section, $resstr);
			if ($res > 0) {
			  end($this->DSGSearchRes->intMultiFilters)->title = $resstr[1];
			}else {
			  array_pop($this->DSGSearchRes->intMultiFilters);
			  continue;
			}
			end($this->DSGSearchRes->intMultiFilters)->values = array();
			$values_sections = array();
			$res = $this->regexMatchAll('parse_multifilter_values/values_section', $section, $values_sections);
			if ($res > 0) {
			  foreach ($values_sections[1] as $values_section) {
				end($this->DSGSearchRes->intMultiFilters)->values[] = new stdClass();
				$res = $this->regexMatch('parse_multifilter_values/values_count', $values_section, $resstr);
				if ($res > 0) {
				  $resnum = $this->parseChineseNumber($resstr[1]);
				  end(end($this->DSGSearchRes->intMultiFilters)->values)->values_count = $resnum;
				}else {
				  array_pop(end($this->DSGSearchRes->intMultiFilters)->values);
				  continue;
				}
				$res = $this->regexMatch('parse_multifilter_values/values_title', $values_section, $resstr);
				if ($res > 0) {
				  end(end($this->DSGSearchRes->intMultiFilters)->values)->values_title = $resstr[1];
				}else {
				  array_pop(end($this->DSGSearchRes->intMultiFilters)->values);
				  continue;
				}
				$res = $this->regexMatch('parse_multifilter_values/values_props', $values_section, $resstr);
				if ($res > 0) {
				  end(end($this->DSGSearchRes->intMultiFilters)->values)->values_props = urldecode($resstr[1]);
				}else {
				  array_pop(end($this->DSGSearchRes->intMultiFilters)->values);
				  continue;
				}
			  }
			}
			else {array_pop($this->DSGSearchRes->intMultiFilters);continue;}
			
			if ((isset(end($this->DSGSearchRes->intMultiFilters)->values)) && (isset(end(end($this->DSGSearchRes->intMultiFilters)->values)->values_props))) {
			  $mprops = explode(';', end(end($this->DSGSearchRes->intMultiFilters)->values)->values_props);
			  if (isset($mprops[0])) {$props = explode(':', $mprops[0]);}
			  else {$props = explode(':', end(end($this->DSGSearchRes->intMultiFilters)->values)->values_props);}
			  if ((isset($props[0])) && (($props[0] == 30000) || ($props[0] == 403))) {
				array_pop($this->DSGSearchRes->intMultiFilters);continue;
			  }
			}
		  }
		}
	  }
		return;
 	}
	
	public function getUserPriceForArray($searchRes){
		
        if (isset($searchRes->items)) {
            
			foreach ($searchRes->items as $k => $item) {

                if ($item->price != 0){$item->promotion_percent = ceil((100 - ($item->promotion_price/$item->price)*100)/5)*5;}
				else $item->promotion_percent = 0;
                
                $item->price = (float) $item->price;
                $item->promotion_price = (float) $item->promotion_price;
                $item->post_fee = (float) $item->post_fee;
                $item->ems_fee = (float) $item->ems_fee;
                $item->express_fee = (float) $item->express_fee;
                $resUserPrice = self::getUserPrice(
                  array(
                    'price'       => $item->price,
                    'count'       => 1,
                    'deliveryFee' => $item->express_fee,
                    'postageId'   => false,
                    'sellerNick'  => false,
                  )
                );
                $item->userPrice = $resUserPrice->price;
                $resUserPrice = self::getUserPrice(
                  array(
                    'price'       => $item->promotion_price,
                    'count'       => 1,
                    'deliveryFee' => $item->express_fee,
                    'postageId'   => false,
                    'sellerNick'  => false,
                  )
                );
                $item->userPromotionPrice = $resUserPrice->price;
                $searchRes->items[$k] = $item;
            }
        }
    }
	
	public function parsePriceRanges($data) {
		
		$sections = $resstr = array();
		$this->DSGSearchRes->intPriceRanges = array();
		$res = $this->regexMatchAll('parse_price_ranges_values/section', $data, $sections);
		
		if ($res > 0){
		  foreach ($sections[1] as $section) {
			$this->DSGSearchRes->intPriceRanges[] = new stdClass();
			$res = $this->regexMatch('parse_price_ranges_values/start', $section, $resstr);
			if ($res > 0) {end($this->DSGSearchRes->intPriceRanges)->start = round((float) $resstr[1]);}
			else {array_pop($this->DSGSearchRes->intPriceRanges);continue;}
			
			$res = $this->regexMatch('parse_price_ranges_values/end', $section, $resstr);
			if ($res > 0){end($this->DSGSearchRes->intPriceRanges)->end = round((float) $resstr[1]);}
			else{array_pop($this->DSGSearchRes->intPriceRanges);continue;}
			
			$res = $this->regexMatch('parse_price_ranges_values/percent', $section, $resstr);
			if ($res>0){end($this->DSGSearchRes->intPriceRanges)->percent = $resstr[1];}
			else {array_pop($this->DSGSearchRes->intPriceRanges);continue;}	
		  }
		
		}
		
		return;
  	}
	
	protected function parsetmItem($matches) {
		
		$this->DSGSearchRes->tmitems[] = new StdClass();
		
		$resstr = array();
		
		$res = $this->regexMatch('parse_item_values/num_iid', $matches[0], $resstr);
		
		if ($res > 0) {end($this->DSGSearchRes->tmitems)->num_iid = $resstr[1];}
		else{array_pop($this->DSGSearchRes->tmitems);return '';}
		
		$res = $this->regexMatch('parse_item_values/price', $matches[0], $resstr);
		if ($res > 0) {$price = (float) $resstr[1];}
		else{array_pop($this->DSGSearchRes->tmitems);return '';}
		
		$res = $this->regexMatch('parse_item_values/price_original', $matches[0], $resstr); /// !!!
		if ($res > 0) {
		  if((float) $resstr[1] > 0) {$price_original = (float) $resstr[1];}
		  else{$price_original = $price;}
		}else $price_original = $price;

		end($this->DSGSearchRes->tmitems)->price = max($price, $price_original);
		end($this->DSGSearchRes->tmitems)->promotion_price = min($price, $price_original);
		
		$res = $this->regexMatch('parse_item_values/pic_url', $matches[0], $resstr);
		if ($res > 0){end($this->DSGSearchRes->tmitems)->pic_url = $resstr[1];}
		else {array_pop($this->DSGSearchRes->tmitems);return '';}
		
		$res = $this->regexMatch('parse_item_values/nick', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->tmitems)->nick = $resstr[1];}
		else {end($this->DSGSearchRes->tmitems)->nick = '';}
		
		$res = $this->regexMatch('parse_item_values/seller_rate', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->tmitems)->seller_rate = $resstr[1];}
		else {end($this->DSGSearchRes->tmitems)->seller_rate = 0;}
		
		$res = $this->regexMatch('parse_item_values/post_fee', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->tmitems)->post_fee = (float) $resstr[1];}
		else {end($this->DSGSearchRes->tmitems)->post_fee = 0;}
		
		end($this->DSGSearchRes->tmitems)->express_fee = end($this->DSGSearchRes->tmitems)->post_fee;
		end($this->DSGSearchRes->tmitems)->ems_fee = end($this->DSGSearchRes->tmitems)->post_fee;
		end($this->DSGSearchRes->tmitems)->postage_id = 0;
		
		$res = $this->regexMatch('parse_item_values/cid', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->tmitems)->cid = $resstr[1];}
		else {end($this->DSGSearchRes->tmitems)->cid = 0;}
		
		$res = $this->regexMatch('parse_item_values/tmall', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->tmitems)->tmall = TRUE;}
		else{end($this->DSGSearchRes->tmitems)->tmall = FALSE;}
		
		$res = $this->regexMatch('parse_item_values/html_title', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->tmitems)->html_title = trim($resstr[1]);}
		else{end($this->DSGSearchRes->tmitems)->html_title = '';}
	
		return '';
 	
	}
	
	protected function parseItem($matches) {
	
		$this->DSGSearchRes->items[] = new StdClass();
		
		$resstr = array();
		
		$res = $this->regexMatch('parse_item_values/num_iid', $matches[0], $resstr);
		
		if ($res > 0) {end($this->DSGSearchRes->items)->num_iid = $resstr[1];}
		else{array_pop($this->DSGSearchRes->items);return '';}
		
		$res = $this->regexMatch('parse_item_values/price', $matches[0], $resstr);
		if ($res > 0) {$price = (float) $resstr[1];}
		else{array_pop($this->DSGSearchRes->items);return '';}
		
		$res = $this->regexMatch('parse_item_values/price_original', $matches[0], $resstr); /// !!!
		if ($res > 0) {
		  if((float) $resstr[1] > 0) {$price_original = (float) $resstr[1];}
		  else{$price_original = $price;}
		}else $price_original = $price;

		end($this->DSGSearchRes->items)->price = max($price, $price_original);
		end($this->DSGSearchRes->items)->promotion_price = min($price, $price_original);
		
		$res = $this->regexMatch('parse_item_values/pic_url', $matches[0], $resstr);
		if ($res > 0){end($this->DSGSearchRes->items)->pic_url = $resstr[1];}
		else {array_pop($this->DSGSearchRes->items);return '';}
		
		$res = $this->regexMatch('parse_item_values/nick', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->items)->nick = $resstr[1];}
		else {end($this->DSGSearchRes->items)->nick = '';}
		
		$res = $this->regexMatch('parse_item_values/seller_rate', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->items)->seller_rate = $resstr[1];}
		else {end($this->DSGSearchRes->items)->seller_rate = 0;}
		
		$res = $this->regexMatch('parse_item_values/post_fee', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->items)->post_fee = (float) $resstr[1];}
		else {end($this->DSGSearchRes->items)->post_fee = 0;}
		
		end($this->DSGSearchRes->items)->express_fee = end($this->DSGSearchRes->items)->post_fee;
		end($this->DSGSearchRes->items)->ems_fee = end($this->DSGSearchRes->items)->post_fee;
		end($this->DSGSearchRes->items)->postage_id = 0;
		
		$res = $this->regexMatch('parse_item_values/cid', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->items)->cid = $resstr[1];}
		else {end($this->DSGSearchRes->items)->cid = 0;}
		
		$res = $this->regexMatch('parse_item_values/tmall', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->items)->tmall = TRUE;}
		else{end($this->DSGSearchRes->items)->tmall = FALSE;}
		
		$res = $this->regexMatch('parse_item_values/html_title', $matches[0], $resstr);
		if ($res > 0) {end($this->DSGSearchRes->items)->html_title = trim($resstr[1]);}
		else{end($this->DSGSearchRes->items)->html_title = '';}
	
		return '';
 	
	}

/*-------------------------------------------------up function is for search---------------------------------------------------------------------*/
		
	//DO PRASE TAOBAO DATA
	
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
        if ($res > 0) {
            $this->DSGItemRes->item->cid = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->cid = '0';
        }
        $res = $this->regexMatch('parse_item_values/num_iid', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->num_iid = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->num_iid = '0';
        }
        $res = $this->regexMatch('parse_item_values/seller_id', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->seller_id = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->seller_id = '0';
        }
        $res = $this->regexMatch('parse_item_values/nick', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->nick = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->nick = '';
        }
        $res = $this->regexMatch('parse_item_values/shop_id', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->shop_id = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->shop_id = '0';
        }
        $res = $this->regexMatch('parse_item_values/title', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->title = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->title = '';
        }
        $res = $this->regexMatch('parse_item_values/pic_url', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->pic_url = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->pic_url = '';
        }
        $res = $this->regexMatch('parse_item_values/price', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->price = (float) $resstr[1];
        } else {
            $this->DSGItemRes->item->price = 0;
        }
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
            if ($res > 0) {
                $this->DSGItemRes->item->num = $resstr[1];
            } else {
                $this->DSGItemRes->item->num = 0;
            }
            $res = $this->regexMatch('parse_item_values/block_sib/location', $sib_data->data, $resstr);
            if ($res > 0) {
                $this->DSGItemRes->item->location = $resstr[1];
            } else {
                $this->DSGItemRes->item->location = '';
            }
            $res = $this->regexMatch('parse_item_values/block_sib/post_fee', $sib_data->data, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {
                    $this->DSGItemRes->item->post_fee = (float) $resstr[1];
                } else {
                    $this->DSGItemRes->item->post_fee = 0;
                }
            } else {
                $this->DSGItemRes->item->post_fee = 0;
            }
            $res = $this->regexMatch('parse_item_values/block_sib/express_fee', $sib_data->data, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {
                    $this->DSGItemRes->item->express_fee = (float) $resstr[1];
                } else {
                    $this->DSGItemRes->item->express_fee = 0;
                }
            } else {
                $this->DSGItemRes->item->express_fee = 0;
            }
            $res = $this->regexMatch('parse_item_values/block_sib/ems_fee', $sib_data->data, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {
                    $this->DSGItemRes->item->ems_fee = (float) $resstr[1];
                } else {
                    $this->DSGItemRes->item->ems_fee = 0;
                }
            } else {
                $this->DSGItemRes->item->ems_fee = 0;
            }
            if ($this->DSGItemRes->item->express_fee == 0) {
                $this->DSGItemRes->item->express_fee = max(
                  array(
                    $this->DSGItemRes->item->post_fee,
                    $this->DSGItemRes->item->express_fee
                  )
                );
            }
            $res = $this->regexMatch('parse_item_values/block_sib/sku_promotions', $sib_data->data, $resstr);
            if ($res > 0) {
                $this->DSGItemRes->item->sku_promotions = $this->JavaScriptToJSON('{' . $resstr[1], true);
            }
            $res = $this->regexMatch('parse_item_values/block_sib/sku_data', $sib_data->data, $resstr);
            if ($res > 0) {
                $this->DSGItemRes->item->sku_data = $this->JavaScriptToJSON('{' . $resstr[1], true);
            }
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
                    $this->DSGItemRes->item->item_attributes[$i]->prop = html_entity_decode(
                      $resstr[1][$i],
                      ENT_COMPAT,
                      'UTF-8'
                    );
                    $this->DSGItemRes->item->item_attributes[$i]->val = html_entity_decode(
                      $resstr[2][$i],
                      ENT_COMPAT,
                      'UTF-8'
                    );
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
                // Обход блока
                foreach ($propresstr[1] as $propblock) {
                    // Свойство есть, создаём объект, который потом ниже добавим в массив, если всё хорошо
                    $propFinal = new stdClass();
                    $propblockresstr = array();
                    $propblockres = $this->regexMatch(
                      'parse_item_values/prop_imgs/prop/title',
                      $propblock,
                      $propblockresstr
                    );
                    if ($propblockres > 0) {
                        $propFinal->cid = $this->DSGItemRes->item->cid;
                        $propFinal->name_zh = $propblockresstr[1];
                        $propFinal->name = $propblockresstr[1];
//                $this->DSGItemRes->item->props->prop[$i]['title']=$propblockresstr[1];
                        unset($propblockresstr);
                    } else {
                        continue;
                    }
                    $propimgresstr = array();
                    $propimgres = $this->regexMatchAll(
                      'parse_item_values/prop_imgs/prop/prop_img/block',
                      $propblock,
                      $propimgresstr
                    );
                    if ($propimgres > 0) {
                        // Ага, есть значения - создаём для них массив в объекте
                        $propFinal->childs = array();
                        //$this->DSGItemRes->item->props->prop[$i]['prop_img']=array();
                        foreach ($propimgresstr[1] as $j => $propimgblock) {
                            $resstr0 = array();
                            $res0 = $this->regexMatch(
                              'parse_item_values/prop_imgs/prop/prop_img/properties',
                              $propimgblock,
                              $resstr0
                            );
                            if ($res0 > 0) {
                                // Ага, у значений есть нормальные параметры - создаём под них класс
                                $propFinal->childs[$j] = new stdClass();
//                 $this->DSGItemRes->item->props->prop[$i]['prop_img'][$j]=new stdClass();
                                //Получаем pid:vid
                                $pidvid = explode(':', $resstr0[1]);
                                if (!isset($pidvid[0]) || !isset($pidvid[1])) {
                                    // Нет pid-vid - пропускаем
                                    continue;
                                }
                                $propFinal->childs[$j]->vid = $pidvid[1];
                            } else {
                                continue;
                            }
                            $resstr0 = array();
                            $res0 = $this->regexMatch(
                              'parse_item_values/prop_imgs/prop/prop_img/title',
                              $propimgblock,
                              $resstr0
                            );
                            if ($res0 > 0) {
                                $propFinal->childs[$j]->name_zh = $resstr0[1];
                                $propFinal->childs[$j]->name = $resstr0[1];
                            } else {
                                $propFinal->childs[$j]->name_zh = '';
                                $propFinal->childs[$j]->name = '';
                            }
                            $resstr0 = array();
                            $res0 = $this->regexMatch(
                              'parse_item_values/prop_imgs/prop/prop_img/url',
                              $propimgblock,
                              $resstr0
                            );
                            if ($res0 > 0) {
                                $propFinal->childs[$j]->url = $resstr0[1];
                            } else {
                                $propFinal->childs[$j]->url = '';
                            }
                        }
                        unset($propimgblock);
                        unset($propimgresstr);
                    }
                    // Тут один стрёмный момент. Если всё хорошо или не очень - добавляем таки объект свойства в массив
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
// ===== apiItemDesc - получаем описание товара ========================================================================
            if (!$this->fromCart) {
                if (isset($skudatajson->apiItemDesc)) {
                    $this->DSGItemRes->item->desc = false;
                    $this->DSGItemRes->item->descUrl = (string) $skudatajson->apiItemDesc;
                    unset($dldata);
                }
            }	
//-----------------------------------------------------------------------------------------------------------------------------------//
            if ($this->curlUseMulti) { //parallel donload
                $urls = array();
                if (!$this->fromCart) {
                    if (isset($skudatajson->valReviewsApi))  $urls[] = (string) $skudatajson->valReviewsApi;
                    if (isset($skudatajson->apiItemInfo)) $urls[] = (string) $skudatajson->apiItemInfo;
				    if (isset($skudatajson->apiRelateMarket)) {
                        // Some hacks to get more items
                        $url = preg_replace('/appid=\d+/', 'appid=32', (string) $skudatajson->apiRelateMarket);
                        $url = preg_replace('/count=\d+/', 'count=4', $url);
                        $urls[] = $url;
                        //$dldata = DSGCurl::getHttpDocument($url);
                    }
                }
		
                $_truePostage='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
                $urls[] = $_truePostage;
                $downloads = $this->getHttpDocumentArray($urls);
				
                if (isset($skudatajson->valReviewsApi)) {
                    if (isset($downloads[(string) $skudatajson->valReviewsApi])) {
                        $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON(
                          preg_replace(
                            '/(:?,"babyRateJsonList"|,"rateListInfo").*(?=})/s',
                            '',
                            $downloads[(string) $skudatajson->valReviewsApi]->data
                          ),
                          true
                        );
                    }
                }
                if (isset($skudatajson->apiItemInfo)) {
                    if (isset($downloads[(string) $skudatajson->apiItemInfo])) {
                        $this->DSGItemRes->item->apiItemInfo = $this->JavaScriptToJSON(
                          $downloads[(string) $skudatajson->apiItemInfo]->data,
                          true
                        );
                    }
                }
                if (isset($url)) {
                    if (isset($downloads[$url])) {
                        $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON(
                          $downloads[$url]->data,
                          true
                        );
                    }
                }
                if (isset($_truePostage) && isset($downloads[$_truePostage])) {
                    $this->DSGItemRes->item->truePostage = $downloads[$_truePostage]->data;
                }

            } else {//not use multi curl
//-----------------------------------------------------------------------------------------------------------------------------------//
// ===== valReviewsApi - получаем РЕЙТИНГ ПРОДАВЦА ========================================================================
//http://rate.taobao.com/detail_rate.htm?userNumId=87110412&auctionNumId=15879813267&showContent=1&currentPage=1&ismore=0&siteID=7
                if (!$this->fromCart) {
                    if (isset($skudatajson->valReviewsApi)) {
                        $dldata = $this->getHttpDocument((string) $skudatajson->valReviewsApi);
                        $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON(
                          preg_replace('/(:?,"babyRateJsonList"|,"rateListInfo").*(?=})/s', '', $dldata->data),
                          true
                        );
                    }
                }
// ===== apiItemInfo - получаем СТАТИСТИКУ ПРОДАЖ ========================================================================
                if (!$this->fromCart) {
                    if (isset($skudatajson->apiItemInfo)) {
                        $dldata = $this->getHttpDocument((string) $skudatajson->apiItemInfo);
                        $this->DSGItemRes->item->apiItemInfo = $this->JavaScriptToJSON($dldata->data, true);
                    }
                }
// ===== apiRelateMarket - получаем рекомендованные товары ========================================================================
                if (!$this->fromCart) {
                    if (isset($skudatajson->apiRelateMarket)) {
                        // Some hacks to get more items
                        $url = preg_replace('/appid=\d+/', 'appid=32', (string) $skudatajson->apiRelateMarket);
                        $url = preg_replace('/count=\d+/', 'count=32', $url);
                        $dldata = $this->getHttpDocument($url);
                        $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON($dldata->data, true);
                    }
                }
//http://tui.taobao.com/recommend?appid=32&count=32&itemid=26733820522
                $url ='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
				$dldata = $this->getHttpDocument($url);
                $this->DSGItemRes->item->truePostage = $dldata->data;
            }
//--------------------------------------------end multi select---------------------------------------------------------------------------------------//
            //====== ДОСТАВКА ========================================
            if (isset($this->DSGItemRes->item->truePostage)){
                $res = $this->regexMatch('parse_item_values/block_sib/express_fee', $this->DSGItemRes->item->truePostage, $resstr);
                if ($res > 0) {
                    if (!isset($resstr[2])) {
                        $this->DSGItemRes->item->express_fee = (float) $resstr[1];
                    } else {
                        $this->DSGItemRes->item->express_fee = 0;
                    }
                } else {
                    $this->DSGItemRes->item->express_fee = 0;
                }
                $res = $this->regexMatch('parse_item_values/block_sib/ems_fee', $this->DSGItemRes->item->truePostage, $resstr);
                if ($res > 0) {
                    if (!isset($resstr[2])) {
                        $this->DSGItemRes->item->ems_fee = (float) $resstr[1];
                    } else {
                        $this->DSGItemRes->item->ems_fee = 0;
                    }
                } else {
                    $this->DSGItemRes->item->ems_fee = 0;
                }
                if ($this->DSGItemRes->item->express_fee == 0) {
                    $this->DSGItemRes->item->express_fee = max(
                      array(
                        $this->DSGItemRes->item->post_fee,
                        $this->DSGItemRes->item->express_fee
                      )
                    );
                }
            }
// ===== SKUs - формируем ========================================================================
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
                            if (isset($skuFromSIB->{$name})) {
                                end($this->DSGItemRes->item->skus->sku)->quantity = $skuFromSIB->{$name}->stock;
                            } else {
                                end($this->DSGItemRes->item->skus->sku)->quantity = $val->stock;
                            }
                        } else {
                            end($this->DSGItemRes->item->skus->sku)->quantity = $val->stock;
                        }
                        if (end($this->DSGItemRes->item->skus->sku)->quantity == 99) {
                            end($this->DSGItemRes->item->skus->sku)->quantity = 0;
                        }
                        end($this->DSGItemRes->item->skus->sku)->sku_id = $val->skuId;
                        if (isset($this->DSGItemRes->item->sku_promotions->{$name})) {
                            $promotions = $this->DSGItemRes->item->sku_promotions->{$name};

                            $PromotionPriceArray = Array();
                            foreach ($promotions as $promotion) {
                                if (isset($promotion->price)) {
                                    if ($promotion->price > 0) {
                                        $PromotionPriceArray[] = (float) $promotion->price;
                                    }
                                }
                            }
                            if (count($PromotionPriceArray) > 0) {
                                $promotion_price = min($PromotionPriceArray);
                            } else {
                                $promotion_price = 0;
                            }
                            if ($promotion_price > 0) {
                                end($this->DSGItemRes->item->skus->sku)->promotion_price = $promotion_price;
                            } else {
                                end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                            }
                        } elseif (isset($val->specPrice)) {
                            if ($val->specPrice == '') {
                                end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                            } else {
                                end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->specPrice;
                            }
                        } else {
                            end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                        }
                    }
                    unset($this->DSGItemRes->item->sku_promotions);
                    $PromotionPriceArray = Array();
                    foreach ($this->DSGItemRes->item->skus->sku as $sku) {
                        if (isset($sku->promotion_price)) {
                            if ($sku->promotion_price > 0) {
                                $PromotionPriceArray[] = $sku->promotion_price;
                            }
                        }
                    }
                    $this->DSGItemRes->item->promotion_price = min($PromotionPriceArray);
                    unset($PromotionPriceArray);
                    unset($sku);
                    unset($srcSkuArray);
                    unset($name);
                    unset($val);
                } else {
                    $tryToGetSku_promotions = true;
                }
            } else {
                $tryToGetSku_promotions = true;
            }
            if (isset($tryToGetSku_promotions) && $tryToGetSku_promotions) {
                if (isset($this->DSGItemRes->item->sku_promotions)) {
                    if (isset($this->DSGItemRes->item->sku_promotions->def)) {
                        if (is_array($this->DSGItemRes->item->sku_promotions->def)) {
                            $promotions = array();
                            foreach ($this->DSGItemRes->item->sku_promotions->def as $defPromotions) {
                                if (isset($defPromotions->price)) {
                                    $promotions[] = (float) $defPromotions->price;
                                }
                            }
                            if (count($promotions) > 0) {
                                $this->DSGItemRes->item->promotion_price = min($promotions);
                            }
                        }
                    }
                }
            }
            unset($skuFromSIB);
            unset($dldata);
//http://tui.taobao.com/recommend?appid=32&count=32&itemid=26733820522
// ==================================================================================
            unset($skudatajson);
        }
        $this->DSGItemRes->item->postage_id = '0';
        $this->closeCurl();
    }
	
/*================================================================================================================================================================*/

	public function parseTmall($data){
        $resstr = array();
        $res = $this->regexMatch('parse_item_values/user_rate_url', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->userRateUrl = $resstr[0];
        }
        $resstr = array();
        $res = $this->regexMatch('parse_item_values/tshop', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->tshop = $this->JavaScriptToJSON($resstr[1], true);
        }
        $resstr = array();
        $this->DSGItemRes->item->cid = $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->categoryId, 0);
        $this->DSGItemRes->item->num_iid = $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->itemId, 0);
        $this->DSGItemRes->item->seller_id = $this->getObjPropValDef(
          @$this->DSGItemRes->item->tshop->itemDO->userId,
          0
        );
        $this->DSGItemRes->item->nick = urldecode(
          (string) $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->sellerNickName, '')
        );
        $this->DSGItemRes->item->price = (float) $this->getObjPropValDef(
          @$this->DSGItemRes->item->tshop->itemDO->reservePrice,
          0
        );
        $this->DSGItemRes->item->num = $this->getObjPropValDef(@$this->DSGItemRes->item->tshop->itemDO->quantity, 0);

        $res = $this->regexMatch('parse_item_values/shop_id', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->shop_id = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->shop_id = '0';
        }
        $res = $this->regexMatch('parse_item_values/title', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->title = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->title = '';
        }
        $res = $this->regexMatch('parse_item_values/pic_url', $data->data, $resstr);
        if ($res > 0) {
            $this->DSGItemRes->item->pic_url = (string) $resstr[1];
        } else {
            $this->DSGItemRes->item->pic_url = '';
        }
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
                    $this->DSGItemRes->item->item_attributes[$i]->prop = html_entity_decode(
                      $resstr[1][$i],
                      ENT_COMPAT,
                      'UTF-8'
                    );
                    $this->DSGItemRes->item->item_attributes[$i]->val = html_entity_decode(
                      $resstr[2][$i],
                      ENT_COMPAT,
                      'UTF-8'
                    );
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
                // Обход блока
                foreach ($propresstr[1] as $propblock) {
                    // Свойство есть, создаём объект, который потом ниже добавим в массив, если всё хорошо
                    $propFinal = new stdClass();
                    $propblockresstr = array();
                    $propblockres = $this->regexMatch(
                      'parse_item_values/prop_imgs/prop/title',
                      $propblock,
                      $propblockresstr
                    );
                    if ($propblockres > 0) {
                        $propFinal->cid = $this->DSGItemRes->item->cid;
                        $propFinal->name_zh = $propblockresstr[1];
                        $propFinal->name = $propblockresstr[1];
                        unset($propblockresstr);
                    } else {
                        continue;
                    }
                    $propimgresstr = array();
                    $propimgres = $this->regexMatchAll(
                      'parse_item_values/prop_imgs/prop/prop_img/block',
                      $propblock,
                      $propimgresstr
                    );
                    if ($propimgres > 0) {
                        // Ага, есть значения - создаём для них массив в объекте
                        $propFinal->childs = array();
                        //$this->DSGItemRes->item->props->prop[$i]['prop_img']=array();
                        foreach ($propimgresstr[1] as $j => $propimgblock) {
                            $resstr0 = array();
                            $res0 = $this->regexMatch(
                              'parse_item_values/prop_imgs/prop/prop_img/properties',
                              $propimgblock,
                              $resstr0
                            );
                            if ($res0 > 0) {
                                // Ага, у значений есть нормальные параметры - создаём под них класс
                                $propFinal->childs[$j] = new stdClass();
                                //Получаем pid:vid
                                $pidvid = explode(':', $resstr0[1]);
                                if (!isset($pidvid[0]) || !isset($pidvid[1])) {
                                    // Нет pid-vid - пропускаем
                                    continue;
                                }
                                $propFinal->childs[$j]->vid = $pidvid[1];
                            } else {
                                continue;
                            }
                            $resstr0 = array();
                            $res0 = $this->regexMatch(
                              'parse_item_values/prop_imgs/prop/prop_img/title',
                              $propimgblock,
                              $resstr0
                            );
                            if ($res0 > 0) {
                                $propFinal->childs[$j]->name_zh = $resstr0[1];
                                $propFinal->childs[$j]->name = $resstr0[1];
                            } else {
                                $propFinal->childs[$j]->name_zh = '';
                                $propFinal->childs[$j]->name = '';
                            }
                            $resstr0 = array();
                            $res0 = $this->regexMatch(
                              'parse_item_values/prop_imgs/prop/prop_img/url',
                              $propimgblock,
                              $resstr0
                            );
                            if ($res0 > 0) {
                                $propFinal->childs[$j]->url = $resstr0[1];
                            } else {
                                $propFinal->childs[$j]->url = '';
                            }
                        }
                        unset($propimgblock);
                        unset($propimgresstr);
                    }
                    // Тут один стрёмный момент. Если всё хорошо или не очень - добавляем таки объект свойства в массив
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
		
        // ===== apiItemDesc - получаем описание товара ========================================================================
        if (!$this->fromCart) {
            // $this->DSGItemRes->item->descUrl=$this->DSGItemRes->item->tshop->api;
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
                      . $this->DSGItemRes->item->tshop->itemDO->itemId . '&sellerId='
                      . $this->DSGItemRes->item->tshop->itemDO->userId;
                    $urls[] = $_valReviewsApi;
                    //$dldata = DSGCurl::getHttpDocument($_valReviewsApi);
                }
                // Some hacks to get more items
                $_apiRelateMarket = 'http://aldcdn.tmall.com/recommend.htm?itemId=' . $this->DSGItemRes->item->num_iid . '&refer=&rn=32&appId=03054';
                $urls[] = $_apiRelateMarket;
                //$dldata = DSGCurl::getHttpDocument($url);
            }
            $_truePostage='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
            $urls[] = $_truePostage;
            $downloads = $this->getHttpDocumentArray($urls);
            if (isset($this->DSGItemRes->item->tshop->itemDO->itemId) && isset($this->DSGItemRes->item->tshop->itemDO->userId)) {
                if (isset($_valReviewsApi) && isset($downloads[$_valReviewsApi])) {
                    $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON(
                      $downloads[$_valReviewsApi]->data,
                      true
                    );
                }
            }
            if (isset($_apiRelateMarket) && isset($downloads[$_apiRelateMarket])) {
                $downloads[$_apiRelateMarket]->data = preg_replace(
                  '/,{"acurl".*/s',
                  '',
                  $downloads[$_apiRelateMarket]->data
                );
                $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON(
                  $downloads[$_apiRelateMarket]->data,
                  true
                );
            }
            if (isset($_truePostage) && isset($downloads[$_truePostage])) {
                $this->DSGItemRes->item->truePostage = $downloads[$_truePostage]->data;
            }

        } else {
            // ===== valReviewsApi - получаем РЕЙТИНГ ПРОДАВЦА ========================================================================
            //http://dsr.rate.tmall.com/list_dsr_info.htm?itemId=20008134785&sellerId=1714306514
            if (!$this->fromCart) {
                if (isset($this->DSGItemRes->item->tshop->itemDO->itemId) && isset($this->DSGItemRes->item->tshop->itemDO->userId)) {
                    $dldata = $this->getHttpDocument(
                      'http://dsr.rate.tmall.com/list_dsr_info.htm?itemId='
                      . $this->DSGItemRes->item->tshop->itemDO->itemId . '&sellerId='
                      . $this->DSGItemRes->item->tshop->itemDO->userId
                    );
                    $this->DSGItemRes->item->valReviewsApi = $this->JavaScriptToJSON($dldata->data, true);
                }
            }
            // ===== apiRelateMarket - получаем рекомендованные товары ========================================================================
            if (!$this->fromCart) {
                // Some hacks to get more items
                $url = 'http://aldcdn.tmall.com/recommend.htm?itemId=' . $this->DSGItemRes->item->num_iid . '&refer=&rn=32&appId=03054';
                $dldata = $this->getHttpDocument($url);
                $dldata->data = preg_replace('/,{"acurl".*/s', '', $dldata->data);
                $this->DSGItemRes->item->apiRelateMarket = $this->JavaScriptToJSON($dldata->data, true);
            }
            $url ='http://detailskip.taobao.com/json/postageFee.htm?itemId='.$this->DSGItemRes->item->num_iid.'&areaId='.$this->postageArea;
            $dldata = $this->getHttpDocument($url);
            $this->DSGItemRes->item->truePostage = $dldata->data;
        }
//============================================================
        //http://aldcdn.tmall.com/recommend.htm?itemId=20008134785&refer=&rn=32&appId=03054
        //http://tui.taobao.com/recommend?appid=32&count=32&itemid=26733820522
        if (isset($this->DSGItemRes->item->tshop->initApi)) {
            $initApiData = $this->getHttpDocument(
              $this->DSGItemRes->item->tshop->initApi,
              false,
              false,
              'http://detail.tmall.com/item.htm?id=' . $this->DSGItemRes->item->num_iid
            );
            $initApi = $this->JavaScriptToJSON($initApiData->data, true);
            unset($initApiData);
        } else {
            $initApi = new stdClass();
        }
        //====== ДОСТАВКА ========================================
        if (isset($this->DSGItemRes->item->truePostage)){
            $res = $this->regexMatch('parse_item_values/block_sib/express_fee', $this->DSGItemRes->item->truePostage, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {
                    $this->DSGItemRes->item->express_fee = (float) $resstr[1];
                } else {
                    $this->DSGItemRes->item->express_fee = 0;
                }
            } else {
                $this->DSGItemRes->item->express_fee = 0;
            }
            $res = $this->regexMatch('parse_item_values/block_sib/ems_fee', $this->DSGItemRes->item->truePostage, $resstr);
            if ($res > 0) {
                if (!isset($resstr[2])) {
                    $this->DSGItemRes->item->ems_fee = (float) $resstr[1];
                } else {
                    $this->DSGItemRes->item->ems_fee = 0;
                }
            } else {
                $this->DSGItemRes->item->ems_fee = 0;
            }
            if ($this->DSGItemRes->item->express_fee == 0) {
                $this->DSGItemRes->item->express_fee = max(
                  array(
                    $this->DSGItemRes->item->post_fee,
                    $this->DSGItemRes->item->express_fee
                  )
                );
            }
    }
        else {
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
        // ===== SKUs - формируем ========================================================================
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
                                if ($promotion->price > 0) {
                                    $PromotionPriceArray[] = (float) $promotion->price;
                                }
                            }
                        }
                        if (count($PromotionPriceArray) > 0) {
                            $promotion_price = min($PromotionPriceArray);
                        } else {
                            $promotion_price = 0;
                        }
                        if ($promotion_price > 0) {
                            end($this->DSGItemRes->item->skus->sku)->promotion_price = $promotion_price;
                        } else {
                            end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                        }
                    } elseif (isset($val->specPrice)) {
                        if ($val->specPrice == '') {
                            end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                        } else {
                            end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->specPrice;
                        }
                    } else {
                        end($this->DSGItemRes->item->skus->sku)->promotion_price = (float) $val->price;
                    }
                }
                unset($this->DSGItemRes->item->sku_promotions);
                $PromotionPriceArray = Array();
                foreach ($this->DSGItemRes->item->skus->sku as $sku) {
                    if (isset($sku->promotion_price)) {
                        if ($sku->promotion_price > 0) {
                            $PromotionPriceArray[] = $sku->promotion_price;
                        }
                    }

                }
                $this->DSGItemRes->item->promotion_price = min($PromotionPriceArray);
                unset($PromotionPriceArray);
                unset($sku);
                unset($srcSkuArray);
                unset($name);
                unset($val);
            } else {
                $tryToGetSku_promotions = true;
            }
        } else {
            $tryToGetSku_promotions = true;
        }
        if (isset($tryToGetSku_promotions) && $tryToGetSku_promotions && isset($initApi)) {
            if (isset($initApi->defaultModel->itemPriceResultDO->priceInfo->def->promotionList)) {
                if (is_array($initApi->defaultModel->itemPriceResultDO->priceInfo->def->promotionList)) {
                    $promotions = array();
                    foreach ($initApi->defaultModel->itemPriceResultDO->priceInfo->def->promotionList as $defPromotions) {
                        if (isset($defPromotions->price)) {
                            $promotions[] = (float) $defPromotions->price;
                        }
                    }
                    if (count($promotions) > 0) {
                        $this->DSGItemRes->item->promotion_price = min($promotions);
                    }
                }
            }
        }
        unset($skuFromSIB);
        unset($dldata);
        unset($this->DSGItemRes->item->tshop);
        $this->DSGItemRes->item->postage_id = '0';
        $this->closeCurl();
    }	

}