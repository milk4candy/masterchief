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

            $socket_proto = getprotobyname('tcp');
            $socket = socket_create(AF_INET, SOCK_STREAM, $socket_proto);
            socket_bind($socket, 'localhost', 9527);
            socket_listen($socket);

            $clients = array();

            // infinity while loop
            while(true){

                $read = array($socket);
                $read = array_merge($read, $clients);
                //system("echo total socket number:".count($read)." >> $script_path/socket");

                $num_changed_socket = socket_select($read, $write = null, $except = null, $tv_sec = 0); 

                //system("echo active socket number:".count($read)." >> $script_path/socket");

                if(!$num_changed_socket){

                }elseif($num_changed_socket > 0){
                    if(in_array($socket, $read)){
                        $client = socket_accept($socket);
                        if(!$client){
                        }else{
                            $clients[] = $client;
                                
                        }
                        continue;
                    }

                    foreach($clients as $key => $client){
                        if(in_array($client, $read)){
                            if($input = socket_read($client, 1024)){
                                $pid = pcntl_fork();

                                if($pid === -1){
                                }elseif(!$pid){

                                    $child_proc_name = "mc_child_".basename($input);
				    setthreadtitle($child_proc_name);
                                    $children = array();

                                    $job_pid = exec($input.' & echo $!;', $output_array, $return_code);

				    $output = '';
				    foreach($output_array as $line => $content){
					$output .= $content;
				    }

                                    if($return_code == 0){
                                        $msg = "$child_proc_name runs OK, output: $output,$job_pid";
                                    }else{ 
                                        $msg = "$child_proc_name runs fail, output: $output,$job_pid";
                                    }

                                    system("echo '$msg' >> $script_path/socket");
                                    socket_write($client ,$msg, strlen($msg));
                                    exit();

                                }else{
                                    $children[] = $pid;
                                }
                            }
                            unset($clients[$key]);
                            socket_close($client);
                        }
                    }
                }

                $finished_child = pcntl_waitpid(-1, $status, WNOHANG);
                while($finished_child > 0){
                    unset($children[array_search($finished_child, $children)]);
                    system("echo $finished_child >> $script_path/test.close");
                    $finished_child = pcntl_waitpid(-1, $status, WNOHANG);
                    usleep(100000);
                }
                sleep(1);
            }
       }
    } 

function signal_handler($signo){
    //global $script_path, $connection, $children;
    global $script_path, $socket, $clients, $children;

    switch($signo){
        case SIGUSR1:
            break;
        default:
            //socket_close($connection);
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
            foreach($clients as $client){
                socket_close($client);
            }
            socket_close($socket);
            exit();
    }
}
?>
