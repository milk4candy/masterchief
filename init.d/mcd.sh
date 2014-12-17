#! /bin/bash

daemon_name="masterchief"

proj_dir=$(dirname $(cd $(dirname $0) && pwd))

pid_file="$proj_dir/pid/$daemon_name/$daemon_name.pid"


check_pid(){
    if test -f $pid_file
    then
        pid=$(cat $pid_file)
    else
        echo "Can't find PID file '$pis_file'"
        exit 1
    fi
}

if test $1 != "start"
then
    check_pid
fi

start_daemon(){
    echo "Starting $daemon_name deamon..."
    $proj_dir/$daemon_name.php
    if test $? -eq 0
    then
        sleep 3
        check_pid
        check=$(ps --pid $pid -o pid --no-heading)
        if test -n $check
        then
            echo "$daemon_name daemon(PID=$pid) is running now."
            return 0
        else
            echo "Startup command execution success. But can't find any running $daemon_name daemon."
            return 2
        fi
    else
        echo "Startup command execution fail."
        return 1
    fi
}

stop_daemon(){
    echo "Stopping $daemon_name daemon(PID=$pid)..."
    kill $pid
    sleep 3
    check=$(ps --pid $pid -o pid --no-heading)
    if test -z $check
    then
        rm -f $pid_file
        if test $? -ne 0
        then
            echo "$daemon_name daemon(PID=$pid) is stop now. But can't remove PID file '$pif_file'."
            return 2
        else
            echo "$daemon_name daemon(PID=$pid) is stop now."
            return 0
        fi
    else
        echo "$daemon_name daemon(PID=$pid) seems still alive"
        return 1
    fi
}


case $1 in
    start)
        start_daemon
        ;;
    stop)
        stop_daemon
        ;;
    restart)
        stop_daemon
        if test $? -eq 0
        then
            start_daemon
        fi
        ;;
    reload)
        ;;
    test)
        echo $pid
        ;;
esac

