#镜像备份远程文件

#!/bin/bash
remotedir=/home/backup/
basedir=/backup/weekly
host=127.0.0.1
id=dmtsai

rsync -av -e ssh $basedir ${$id}@${host}:${remotedir}

