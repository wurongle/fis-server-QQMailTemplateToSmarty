<?php
class QQMailTemplateToSmarty {

    public static function parse($content,$filePath,$treePath) {
        $content = self::encodeContent($content); 
        $content = preg_replace('/<head>/i', '<head><meta charset = "utf-8">', $content);
        $content = preg_replace('/(<meta[ ].*?charset=["]?)(gb2312|gbk)(["]?.*?>)/i', '$1utf-8$3', $content);
        $templateTree = self::createTemplateTree($treePath); 
        $content = self::parseIncludeFile($content,$templateTree,$filePath);
        $content = self::removeSectionString($content);
        $content = self::parseTemplateStatement($content);
        $content = self::parseData($content);
        $content = self::parseValue($content);
        return $content;
    }

    private static function getFileList($d) {
        $fileList = array();
        $tree = function($directory) use (&$tree,&$fileList) { 
            $mydir=dir($directory);
            while($file=$mydir->read()){
                if((is_dir("$directory/$file")) AND ($file!=".") AND ($file!="..")){ 
                    $tree("$directory/$file"); 
                }else{ 
                    if(($file!=".") AND ($file!="..")){
                        array_push($fileList,"$directory/$file");
                    }
                } 
            }
            $mydir->close(); 
        };
        $tree($d);
        return $fileList;
    }

    private static function createTemplateTree($p) {
        $fileList = self::getFileList($p);
        $templateTree = array();
        $templateExtnames = array('','.html');

        $fileList = array_filter($fileList,function($item) use (&$templateExtnames){
            preg_match("/\.[a-zA-Z]+$/i",$item,$match);
            return array_search($match[0],$templateExtnames)>0;
        });
        foreach ($fileList as &$value) {
            $content = file_get_contents($value);
            $content = self::encodeContent($content); 
            $templateTree[$value] = self::parseSection($content);
        }
        return $templateTree;
    }

    private static function parseSection($content) {
        $arr=array();
        //<%#tMobile_head%>00<%#/tMobile_head%>
        preg_replace_callback('/<%#([a-zA-Z_]+)%>([\s\S]*?)(<%#\/\1%>)/',function ($matchs) use (&$arr) {
            $arr[$matchs[1]] = $matchs[2];
        },$content);
        return $arr;
    }

    private static function parseIncludeFile($content,$templateTree,$file) {
        //echo "$file";
        if(preg_match('/<%#include\((#?[a-zA-Z0-9-_#.\/]+)\)%>/',$content)){
            $content = preg_replace_callback('/<%#include\((#?[a-zA-Z0-9-_#.\/]+)\)%>/',function ($matchs) use (&$templateTree,$file) {
                $filePath = $matchs[1];
                if(substr($filePath,0,1) == '#'){
                    $filePath = pathinfo($file)['filename'].$filePath;
                }
                //print_r(pathinfo($file));
                $str = explode('#',$filePath);
                $realpath = self::relative_path($file,$str[0]).(preg_match('/\.html$/', $str[0])?'':'.html');
                //echo "$realpath";
                $section = $str[1];
                if($section){
                    $_section = $templateTree[$realpath][$section];
                    return $_section;
                }else{
                    return self::encodeContent(file_get_contents($realpath));
                }
            },$content);
            return self::parseIncludeFile($content,$templateTree,$file);
        }else{
            return $content;
        }
    }

    private static function parseTemplateStatement($content) {
        
        $content = preg_replace_callback('/<%(.*?)%>/',function ($matchs) {
            $sm = $matchs[1];
            if(preg_match('/^##(.*?)##/',$sm,$_matchs)){
                return self::wrapStatementWithnewTemplate('*'.$_matchs[1].'*');
            }elseif(preg_match('/^@(else if|if|elseif)[ ]?\((.*?)\)$/',$sm,$_matchs)){
                $str = $_matchs[2];
                $str = preg_replace('/=/','==',$str);
                $str = preg_replace('/!/','!=',$str);
                $str = preg_replace('/\|/','||',$str);
                $str = preg_replace('/&/','&&',$str);
                $str = self::parseFunction($str);
                $str = preg_replace_callback('/(==|\|\||!=|&&)([^=!\|&]*)($|&&|\|\|)/',function($_matchs){
                    return $_matchs[1].'"'.preg_replace('/"/',"\\\"",$_matchs[2]).'"'.$_matchs[3];
                },$str);
                $str = preg_replace('/[ ]/','',$_matchs[1]).' '.$str;
                return self::wrapStatementWithnewTemplate($str);
            }elseif (preg_match('/^@(endif\)?|else)$/',$sm,$_matchs)) {
                $str = preg_replace('/endif\)?/','/if',$_matchs[1]);
                return self::wrapStatementWithnewTemplate($str);
            }elseif(preg_match('/^@(.*?)$/',$sm,$_matchs)){
                return self::wrapStatementWithnewTemplate(self::parseFunction($_matchs[1]));
            }else{
                return $matchs[0];
            }

        },$content);
        $content = preg_replace('/(==|!=)(%}|\|\||&&)/','$1""$2',$content);
        $content = preg_replace('/<%##[\w\W]*?##%>/', '', $content);
        return $content;
    }

    private static function parseValue($content) {
        $content = preg_replace_callback('/{%(.*?)%}/',function ($matchs) {
            //print_r($matchs);
            $str = preg_replace_callback('/("[^|&]*?)\$([a-zA-Z0-9_.]+?)(.DATA)?\$(.*?")/',function ($_matchs) {
                return $_matchs[1]."`$".$_matchs[2]."`".$_matchs[4];
            },$matchs[1]);
            $str = preg_replace_callback('/\$([a-zA-Z0-9_.]+?)(.DATA)?\$/',function ($_matchs) {
                return "$".$_matchs[1];
            },$str);
            return '{%'.$str.'%}';
        },$content);
        $content = preg_replace_callback('/\$([a-zA-Z0-9_.]+?)(.DATA)?\$/',function ($matchs) {
            return "{%$".$matchs[1].'%}';
        },$content);
        return $content;
    }

    private static function parseFunction($content,$next){
        $hasFun = false;
        $content = preg_replace('/GetCurrentDate\(\)/','GetCurrentDate(2013)',$content);
        $content = preg_replace_callback('/([a-zA-Z0-9]+)\(([^()]+)\)/',function($matchs) use (&$hasFun,&$next){
            $args = explode(',',$matchs[2]);
            $hasFun = true;
            foreach ($args as $key => $value) {
                if($key == 0){
                    $rest = (($next && substr($args[0],0,3)=="##[") ? $args[0] : self::wrapString($args[0])).'|'.$matchs[1];
                }else{
                    $rest = $rest.':'.(preg_match('/^##\[/',$value) ? $value : self::wrapString($value) );
                }
            }
            return '##['.$rest.']##';
        },$content);
        if($hasFun){
            return self::parseFunction($content,true);
        }else{
            $content = preg_replace_callback('/(SetVar|AppendVar)\(([a-zA-Z0-9_]+),(.*)\)/',function($matchs){
                return '"'.$matchs[2].'"|'.$matchs[1].':"'.preg_replace('/"/','\\"',$matchs[3]).'"';
            },$content);
            $content = preg_replace('/##\[/','(',$content);
            $content = preg_replace('/\]##/',')',$content);
            return $content;
        }
    }

    private static function parseData($content){
        $content = self::parseDataItem($content,'');
        return $content;
    }

    private static function parseDataItem($content,$v){
        $hasFun = false;
        $content = preg_replace_callback('/<%([a-zA-Z0-9_]+)%>([\w\W]*?)<%\/\1%>/',function($matchs) use (&$hasFun,&$v) {
            $hasFun  = true;
            $cc = preg_replace_callback('/\$([a-zA-Z0-9_.]+?)(.DATA)?\$/',function($_matchs) use (&$_v){
                return '$###.'.$_matchs[1].'$';
            },$matchs[2]);
            $v = $v=="" ? '' : ($v.'.');
            $res = '{%foreach $'.$v.$matchs[1].' as $v%}'.$cc.'{%/foreach%}';
            $v = $matchs[1];
            return $res;
        },$content);
        if($hasFun){
            return self::parseDataItem($content,$v);
        }else{
            return preg_replace('/\$###/','$v',$content);
        }
    }

    private static function removeSectionString($content){
        $content = preg_replace_callback('/<%#([a-zA-Z_]+)%>([\s\S]*?)(<%#\/\1%>)/',function ($matchs) use (&$arr) {
            return '';
        },$content);
        return $content;
    }

    private static function wrapString($string) {
        return '"'.preg_replace('/"/','\\"',$string).'"';
    }

    private static function wrapStatementWithnewTemplate($content){
        return '{%'.$content.'%}';
    }

    private static function relative_path ($a, $b, $separator = '/'){
        $tmp_a = explode($separator,trim($a,$separator));
        $tmp_b = explode($separator,trim($b,$separator));

        //b是a的相对路径
        array_pop($tmp_a);
        foreach ($tmp_b as $value) {
            if($value == '..'){
                array_pop($tmp_a);
                array_shift($tmp_b);
            }else if($value == '.'){
                array_shift($tmp_b);
            }else{
                break;
            }
        }
        $relative_path = implode($separator, $tmp_a);
        $relative_path .= $separator.implode($separator, $tmp_b);
        return $relative_path;
    }
    private static function encodeContent($content){   
        if(!self::isUtf8($content)){
            $content = iconv("gbk","utf-8",$content); 
        }
        return $content;
    }
    private static function isUtf8($str){
        $c=0; $b=0;   
        $bits=0;   
        $len=strlen($str);   
        for($i=0; $i<$len; $i++){   
            $c=ord($str[$i]);   
            if($c > 128){   
                if(($c >= 254)) return false;   
                elseif($c >= 252) $bits=6;   
                elseif($c >= 248) $bits=5;   
                elseif($c >= 240) $bits=4;   
                elseif($c >= 224) $bits=3;   
                elseif($c >= 192) $bits=2;   
                else return false;   
                if(($i+$bits) > $len) return false;   
                while($bits > 1){   
                    $i++;   
                    $b=ord($str[$i]);   
                    if($b < 128 || $b > 191) return false;   
                    $bits--;   
                }   
            }   
        }   
        return true;   
    }
}
