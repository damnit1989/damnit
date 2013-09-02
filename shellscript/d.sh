#告知用户这支程序的用途，并且告知应该如何输入日志时间

echo "this program will try to calculate"
echo "how many days before your demobillzation"
read -p "please input your demobillzation date(YYYYMMDD ex>\
    >20090401):" date2
#测试一下，这个输入的内容是否正确利用正规表示发演奏
date_d=$(echo $date2 | grep '[0-9]\{8\}')

if [ "$date_d" == "" ];then
    echo "you input the wrong date format"
    exit 1
fi
#开始计算

declare -i dete_dem=`date --date="$date2" + %s`
declare -i date_now=`date +%s`
declare -i date_total_s=$(($date_dem-$date_now))
declare -i date_d=$(($date_toatl_s/60/60/24))
if [ "$date_total_s" -lt "0" ];then
    echo "you had been demobillzation before"
else
    declare -i date_h=$(($(($date_total_s-$date_d*60*60*24))/60/60))
    echo "you will demobille after $date_d days and $date_h  hours"
fi

