#! /usr/bin/php

<?php

$queue_id = 9527;

switch($argv[1]){
    case "status":
        if(msg_queue_exists($queue_id)){
            $status = msg_stat_queue(msg_get_queue($queue_id));
            print_r($status);
        }else{
            echo "There's no queue existed to display\n";
            exit(0);
        }
        break;
    case "destroy":
        if(msg_queue_exists($queue_id)){
            echo "Destroying queue...\n";
            if(msg_remove_queue(msg_get_queue($queue_id))){
                echo "Queue destoryed\n";
                exit(0);
            }else{
                echo "Something wrong. Can't destory queue.\n";
                exit(1);
            }
        }else{
            echo "There's no queue existed to destroy\n";
            exit(0);
        }
        break;
    default:
}

