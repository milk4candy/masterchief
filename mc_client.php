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
            if(!in_array('-t', $args)){
                $this->socket_port = 9527;
            }else{
                $arg_key = array_search('-t', $args) + 1;
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
            if(!in_array('--run-user', $args)){
                echo 'Please use --run-user argument to provide run user'."\n";
                exit(1);
            }
            if(!in_array('--run-dir', $args)){
                echo 'Please use --run-dir argument to provide run directory'."\n";
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

            return implode(',', $args);
        }

        public function execute(){
            $socket_proto = getprotobyname('tcp');
            $socket = socket_create(AF_INET, SOCK_STREAM, $socket_proto);

            $connection = socket_connect($socket, $this->socket_host, $this->socket_port);
            if($connection){
                if(!socket_write($socket, $this->jobs, strlen($this->jobs))){
                    echo("Write failed\n");
                }else{
                    while($response = socket_read($socket, 2048)){
                        echo $response."\n";
                        break;
                    }
                }
            }
            socket_close($socket);
        }
    }

    $client = new mc_client($argv);
    $client->execute();
    exit();
