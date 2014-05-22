#author lmm
#date 2013-10-13
#content  file dump
#镜像备份远程文件

#!/bin/bash
remotedir=/home/backup/
basedir=/backup/weekly
host=127.0.0.1
id=dmtsai

rsync -av -e ssh $basedir ${$id}@${host}:${remotedir}

