network="192.168.1"
for sit in $(seq 1 100)
do
    ping -c 1 -w 1 ${network}.${sit} &> /dev/null && result=0\
        || result=1
    if [ "$result" == 0 ];then
        echo "server ${network}.${sit} is up"
    else
        echo "server ${network}.${sit}} is down"
    fi
done
