<?php

function multiple_threads_request($nodes)
{
    $mh = curl_multi_init();
    $curl_array = array();
    foreach($nodes as $i => $url){
        $curl_array[$i] = curl_init($url);
        curl_setopt($curl_array[$i],CURLOPT_RETURNTRANSFER,true);
        curl_multi_add_handle($mh,$curl_array[$i]);
    }
    $running = NULL;
    do{
        usleep(10000);
        curl_multi_exec($mh,$running);
    }while($running > 0);
    $res = array();
    foreach($nodes as $i => $url){
        $res[$url] = curl_multi_getcontent($curl_array[$i]);
    }
    foreach($nodes as $i => $url){
        curl_multi_remove_handle($mh,$curl_array[$i]);
    }
    return $res;
}
$content = multiple_threads_request(array(
    'http://www.baidu.com',
    'www.sfcservice.com'
));
var_dump($content);
