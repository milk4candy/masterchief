#! /usr/bin/php
<?php


    error_reporting(E_ALL);    
    date_default_timezone_set("Asia/Taipei");

    $script_path = dirname(__FILE__);

    // first fork
 
    $pid = pcntl_fork();

    if($pid === -1){
        die('Could not fork!');
    }elseif($pid){
        // grand father
        pcntl_wait($children_status);
    }else{
        //father
        posix_setsid();

        $pid = pcntl_fork();

        if($pid === -1){
            die();
        }elseif($pid){
            exit();
        }else{

            // child
            system("echo ".getmypid()." > $script_path/test.pid");

            $children = array();

            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            $STDIN = fopen('/dev/null', 'r');
            $STDOUT = fopen('/dev/null', 'wb');
            $STDERR = fopen('/dev/null', 'wb');

            declare(ticks = 1);
            pcntl_signal(SIGTERM, 'signal_handler');
            pcntl_signal(SIGHUP, 'signal_handler');
            pcntl_signal(SIGINT, 'signal_handler');
            pcntl_signal(SIGUSR1, 'signal_handler');

            $job_queue = msg_get_queue("9527");
            //$result_queue = msg_get_queue(9528);

            $msgtype_to_fetch = 1;
            $maxsize = 256;
            $is_serialize = false;
            $flags = MSG_IPC_NOWAIT;

            // infinity while loop
            while(true){

                while(count($children) >= 3){
                    $finished_child = pcntl_waitpid(-1, $status, WNOHANG);
                    if($finished_child > 0){
                        unset($children[array_search($finished_child, $children)]);
                        system("echo $finished_child >> $script_path/test.close");
                    }
                    usleep(100000);
                }

                $job_queue_status = msg_stat_queue($job_queue);
                if($job_queue_status['msg_qnum'] > 0){

                    if(msg_receive($job_queue, $msgtype_to_fetch ,$msgtype, $maxsize, $job, $is_serialize, $flags, $err)){

                        $pid = pcntl_fork();

                        if($pid === -1){
                            die();
                        }elseif(!$pid){

                            $children = array();
                            $uid = system('id -u peter');
                            $uid = (int)$uid;
                            if(posix_setuid($uid)){
                                system("echo ok >> $script_path/cu");
                            }else{
                                system("echo oops >> $script_path/cu");
                            }

                            system("touch $script_path/test/$job");

                            exit();

                        }else{
                            $children[] = $pid;
                        }
                    } else {
                        system("echo $err >> $script_path/queue_err");
                    }
                    
                }

                $finished_child = pcntl_waitpid(-1, $status, WNOHANG);
                while($finished_child > 0){
                    unset($children[array_search($finished_child, $children)]);
                    system("echo $finished_child >> $script_path/test.close");
                    $finished_child = pcntl_waitpid(-1, $status, WNOHANG);
                    usleep(100000);
                }
                usleep(500000);
            }
       }
    } 

function signal_handler($signo){
    global $script_path, $job_queue, $children;

    switch($signo){
        case SIGUSR1:
            break;
        default:

            while(count($children) > 0){
                $tmp = $children;
                foreach($tmp as $child){
                    posix_kill($child, $signo);
                    usleep(100000);
                    $finished_child = pcntl_waitpid(-1, $status, WNOHANG);
                    if($finished_child > 0){
                        unset($children[array_search($finished_child, $children)]);
                        system("echo $finished_child >> $script_path/test.close");
                    }
                }
            }   
            msg_remove_queue($job_queue);
            exit();
    }
}
?>
