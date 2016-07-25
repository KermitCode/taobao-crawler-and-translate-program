<?php

class AccessTokenAuthentication {
    function getTokens($grantType, $scopeUrl, $clientID, $clientSecret, $authUrl){
        try {
            $ch = curl_init();
            $paramArr = array (
                 'grant_type'    => $grantType,
                 'scope'         => $scopeUrl,
                 'client_id'     => $clientID,
                 'client_secret' => $clientSecret
            );
            $paramArr = http_build_query($paramArr);
            curl_setopt($ch, CURLOPT_URL, $authUrl);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $paramArr);
            curl_setopt ($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $strResponse = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            if($curlErrno){
                $curlError = curl_error($ch);
                throw new Exception($curlError);
            }
            curl_close($ch);
            $objResponse = json_decode($strResponse);
            if ($objResponse->error){
                throw new Exception($objResponse->error_description);
            }
            return $objResponse->access_token;
        } catch (Exception $e) {
            echo "Exception-".$e->getMessage();
        }
    }
}


Class HTTPTranslator {
    function curlRequest($url, $authHeader, $postData=''){
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
        if ($curlErrno) {
            $curlError = curl_error($ch);
            throw new Exception($curlError);
        }
        curl_close($ch);
        return $curlResponse;
    }

    function createReqXML($fromLanguage,$toLanguage,$contentType,$inputStrArr) {
        $requestXml = "<TranslateArrayRequest>".
            "<AppId/>".
            "<From>$fromLanguage</From>". 
            "<Options>" .
             "<Category xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              "<ContentType xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\">$contentType</ContentType>" .
              "<ReservedFlags xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              "<State xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              "<Uri xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
              "<User xmlns=\"http://schemas.datacontract.org/2004/07/Microsoft.MT.Web.Service.V2\" />" .
            "</Options>" .
            "<Texts>";
        foreach ($inputStrArr as $inputStr)
        $requestXml .=  "<string xmlns=\"http://schemas.microsoft.com/2003/10/Serialization/Arrays\">$inputStr</string>" ;
        $requestXml .= "</Texts>".
            "<To>$toLanguage</To>" .
          "</TranslateArrayRequest>";
        return $requestXml;
    }
}

try {
    $clientID     = "count1";
    $clientSecret = "secret1";
    $authUrl      = "https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/";
    $scopeUrl     = "http://api.microsofttranslator.com";
    $grantType    = "client_credentials";
    $authObj      = new AccessTokenAuthentication();
    $accessToken  = $authObj->getTokens($grantType, $scopeUrl, $clientID, $clientSecret, $authUrl);
    $authHeader = "Authorization: Bearer ". $accessToken;

    $fromLanguage = "zh-CHS";
    $toLanguage   = "en";
    $inputStrArr  = array("中国人民", "中国");
    $contentType  = 'text/plain';
    $translatorObj = new HTTPTranslator();
    $requestXml = $translatorObj->createReqXML($fromLanguage,$toLanguage,$contentType,$inputStrArr);
    $translateUrl = "http://api.microsofttranslator.com/v2/Http.svc/TranslateArray";
    $curlResponse = $translatorObj->curlRequest($translateUrl, $authHeader, $requestXml);
    $xmlObj = simplexml_load_string($curlResponse);
    $i=0;
    echo "<table border=2px>";
    echo "<tr>";
    echo "<td><b>From $fromLanguage</b></td><td><b>To $toLanguage</b></td>";
    echo "</tr>";
    foreach($xmlObj->TranslateArrayResponse as $translatedArrObj){
        echo "<tr><td>".$inputStrArr[$i]."</td><td>". $translatedArrObj->TranslatedText."</td></tr>";
        $i++;
    }
    echo "</table>";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . PHP_EOL;
}