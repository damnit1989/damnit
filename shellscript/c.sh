if [ "$1" == "hello" ];then
    echo "hello,how are you"
elif [ "$1" == "" ];then
    echo "you mush inpu param,ex> {$0 someword}"
else
    echo "the only paramer if 'hello',ex> {$0 hello}"
fi

if [ "$1" == "hello" ];then
    echo "hello how are you"
elif [ "$1" == "" ];then
    echo "you mushinput paramm ex> {$0 somewor}"
else
    echo "the only aram if 'hello' ex> {$0 hello}"
fi
