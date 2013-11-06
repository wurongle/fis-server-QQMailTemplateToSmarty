
fis.config.merge({
    modules : {
        parser : {
            //.less后缀的文件使用fis-parser-less插件编译
            less : 'less'
        }
    }
});

fis.config.merge({
    roadmap : {
        ext : {
            //.less后缀的文件转换为css文件后缀
            less : 'css'
        }
    }
});

fis.config.merge({
      deploy : {
          //使用fis release -d remote来使用这个配置
          remote: [{
              //如果配置了receiver，fis会把文件逐个post到接收端上
              receiver : 'http://www.example.com/path/to/receiver.php',
              //从产出的结果的static目录下找文件
              from : '/template',
              //保存到远端机器的/home/fis/www/static目录下
              //这个参数会跟随post请求一起发送
              to : '/home/fis/www/template',
              //某些后缀的文件不进行上传
              exclude : /.*\.(?:svn|cvs|tar|rar|psd).*/
          },{
              receiver : 'http://www.example.com/path/to/receiver.php',
              from : '/htdocs',
              to : '/home/fis/www/htdocs'
          }]
    }
});