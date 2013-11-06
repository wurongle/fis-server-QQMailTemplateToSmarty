<?php
class QQMailTemplateToSmarty {

    public static function parse($content,$filePath,$treePath) {
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
        if(preg_match('/<%#include\((#?[a-zA-Z0-9-_#]+)\)%>/',$content)){
            $content = preg_replace_callback('/<%#include\((#?[a-zA-Z0-9-_#]+)\)%>/',function ($matchs) use (&$templateTree,$file) {
                $filePath = $matchs[1];
                if(substr($filePath,0,1) == '#'){
                    $filePath = pathinfo($file)['filename'].$filePath;
                }
                $str = explode('#',$filePath);
                $realpath = pathinfo($file)['dirname'].'/'.$str[0].'.html';
                $section = $str[1];
                if($section){
                    $_section = $templateTree[$realpath][$section];
                    return $_section;
                }else{
                    return file_get_contents($realpath);
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
                $str = preg_replace_callback('/(==|\|\||!=|&&)([^=!|&]*$)/',function($_matchs){
                    //print_r($_matchs);
                    return $_matchs[1].'"'.preg_replace('/"/',"\\\"",$_matchs[2]).'"';
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
        $content = preg_replace('/==%}/','==""%}',$content);
        $content = preg_replace('/!=%}/','!=""%}',$content);
        return $content;
    }

    private static function parseValue($content) {
        $content = preg_replace_callback('/{%(.*?)%}/',function ($matchs) {
            //print_r($matchs);
            $str = preg_replace_callback('/(".*?)\$([a-zA-Z0-9_.]+?)(.DATA)?\$(.*?")/',function ($_matchs) {
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
                    $rest = ($next ? $args[0] : self::wrapString($args[0])).'|'.$matchs[1];
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
}
