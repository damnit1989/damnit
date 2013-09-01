<?PHP
# 命令
#
# 程序执行的时间
#1) time php timeTest.php
#
#2) strace -ttt -o xxx php timeTest
# less xxx
#
#3) ltrace -c php timeTest.php
#
#特定函数strtol的调用情况
#4) ltrace -e "strtol" php timeTest.php
#
$y = "1800";
$x = array();
for($j = 0;$j<2000;$j++)
{
    $x[] = "{$j}";
}
for($i=0;$i<3000;$i++)
{
   if(in_array($y,$x,true)){
        continue;
    }
}
