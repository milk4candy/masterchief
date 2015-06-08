#! /usr/bin/php
<?php
    error_reporting(E_ALL);

    class mc_client{
        public $jobs = null;
        public $socket_host = null;
        public $socket_port = 9527;

        public function __construct($args){
            $this->script_path = dirname(__FILE__);
            $this->jobs = $this->parse_args($args);
        }

        public function parse_args($args){
            array_shift($args);

            if(!in_array('-h', $args)){
                echo 'You must provide validate Job host.'."\n";
                exit(1);
            }else{
                $arg_key = array_search('-h', $args) + 1;
                $this->socket_host = $args[$arg_key];
            }
            if(!in_array('--port', $args)){
                $this->socket_port = 9527;
            }else{
                $arg_key = array_search('--port', $args) + 1;
                $this->socket_port = (int)$args[$arg_key];
            }
            if(!in_array('-u', $args)){
                echo 'Please use -u argument to provide username'."\n";
                exit(1);
            }
            if(!in_array('-p', $args)){
                echo 'Please use -p argument to provide password'."\n";
                exit(1);
            }
            if(!in_array('--dir', $args)){
                echo 'Please use --dir argument to provide run directory'."\n";
                exit(1);
            }
            if(!in_array('--cmd', $args)){
                echo 'Please use --cmd argument to provide command to run'."\n";
                exit(1);
            }
            if(!in_array('--sync', $args) and !in_array('async', $args)){
                array_push($args, '--async');
            }elseif(in_array('--sync', $args) and in_array('--async', $args)){
                echo "Argument --sync and --async can't coexist. Please choose one."."\n";
                exit(1);
            }

            return implode('%,%', $args);
        }

        public function execute(){
            $socket_proto = getprotobyname('tcp');
            $socket = socket_create(AF_INET, SOCK_STREAM, $socket_proto);
            $is_ok = true;

            $connection = socket_connect($socket, $this->socket_host, $this->socket_port);
            if($connection){
                if(!socket_write($socket, $this->jobs, strlen($this->jobs))){
                    echo("Write failed\n");
                    exit(1);
                }else{
                    $i = 0;
                    while($response = socket_read($socket, 1024)){
                        if($i == 0){
                            if(!preg_match("/^\[INFO\]/", $response)){
                                $is_ok = false;
                            }
                        }
                        echo $response."\n";
                        $i ++;
                    }
                }
            }
            socket_close($socket);

            if($is_ok){
                exit();
            }else{
                exit(1);
            }
        }
    }

    $client = new mc_client($argv);
    $client->execute();
