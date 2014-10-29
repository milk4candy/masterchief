#! /usr/bin/php
<?php

            error_reporting(E_ALL);

            $job = $argv[1];
            $len = strlen($job);
            echo "$job($len)\n";

            $socket_proto = getprotobyname('tcp');
            $socket = socket_create(AF_INET, SOCK_STREAM, $socket_proto);
            $connection = socket_connect($socket, 'localhost', 9527);

            if(!socket_write($socket, $job, $len)){
                echo("Write failed\n");
            }else{
                while($response = socket_read($socket, 1024)){
                    echo $response."\n";
                    break;
                }
            }

            //socket_close($connection);
            exit();
