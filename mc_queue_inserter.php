#! /usr/bin/php
<?php

            error_reporting(E_ALL);

            $job = $argv[1];

            $job_queue = msg_get_queue(9527);

            $msgtype = 1;
            $is_serialize = false;
            $is_block = false;

            if(msg_send($job_queue ,$msgtype, $job, $is_serialize, $is_block, $err)){
                echo "Job: $job is put in queue.\n";
            }else{
                echo "Something is wrong. Error: $err\n";
            }

            exit();
