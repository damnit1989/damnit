#!/bin/bash
groupadd  mygroup
for username in  test1 test2 test3 test4
do
    useradd -g mygroup $username
    echo "password"|passwd --stdin $username
done
