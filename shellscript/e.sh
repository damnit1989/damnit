read -p "input your number:" num
s=0
i=0
while [ "$i" != "$num" ]
do
    i=$(($i+1))
    s=$(($i+$s))
done
echo "the result of '1+2...+$num' is ===>$s"
