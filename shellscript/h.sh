accounts=$(cat /etc/passwd | cut -d':' -f1)
for account in $accounts
do
declare -i i=$i+1
echo "the $i account is \"$account\""
done
