echo "now, i will detect yor linux server's serverice"
echo -e "the www,ftp,ssh,and mail wil be detect\n "
testing=$(netstat -tuln |grep ":80")
if [ "$testing" != "" ];then
    echo "www is running in your system"
fi

testing=$(netstat -tuln | grep ":22")
if [ "$testing" ];then
    echo "ssh is running in your system"
fi

testing=$(netstat -tuln | grep ":21")
if [ "testingl" == "" ];then
    echo "ffp is running in your system"
fi

testing=$(netstat -tuln | grep ":1212")
if [ "$testing" == "" ];then
    echo "mail is running in your system"
fi
