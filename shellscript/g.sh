read -p "please inputa directoy" dir
if [ "$dir" == "" -o ! -d "$dir" ];then
    echo "the $dir is not exist in yoursystem"
    exit 1
fi
#开始测试档案
filelist=$(ls $dir)
for filename in $filelist
do
    perm=''
    test -r "$dir/$filename" && perm="$perm readable"
    test -w "$dir/$filename" && perm="$perm writable"
    test -x "$dir/$filename" && perm="$perm executable"
    echo "the file $dir/$filename's permission is $perm"
done
