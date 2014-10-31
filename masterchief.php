#! /usr/bin/php

<?php

// Error report setting
error_reporting(E_ALL);

// Timezone setting
date_default_timezone_set("Asia/Taipei");

require("./lib/mc_basic_tool.php");

class masterchief {

    /***********************
     * Define data members *
     ***********************/

    public $script_path;
    public $args;
    public $config;
    public $libs;
    public $workers;



    /*************************
     * Define method members *
     *************************/

    /*
     * This method is constructor whic will execute when generate instance of this class.
     * Return: void
     */
    public function __construct($cmd_arg){
        $this->script_path = dirname(__FILE__);
        $this->args = $this->prepare_args($cmd_arg);
        $this->config = $this->prepare_config();
        $this->init_libs();
    } 

    /*
     * This method will organize the incoming arguments to a array and return it.
     * Return: array
     */
    public function prepare_args($cmd_args){
        $args = array();

        if(!in_array('-c', $cmd_args)){
            $args['config'] = $this->script_path.'/config/main.ini';
        }else{
            $arg_key = array_search('-c', $cmd_args);
            $config_file = $cmd_args[$arg_key + 1];
            if(is_file($config_file)){
                $args['config'] = $config_file;
            }else{
                echo "$config_file is not a file or doesn't exist.\n";
                exit(1);
            }
        }

        return $args;
    }

    /*
     * This method will parse configration file to a array and return it.
     * Return: array
     */
    public function prepare_config(){
        return parse_ini_file($this->args['config'], true);
    }

    /*
     * This method will load all needed php files and generate all needed objects then put them in a data member called libs.
     * Return: void
     */
    public function init_libs($lib_dir='./lib'){
        // Initial a empty array as a container for library objects
        $this->libs = array();

        // Read all requeired libraries defined in config file
        foreach($this->config['basic']['libraries'] as $lib_name){
            require("$lib_dir/$lib_name.php");
            $this->libs[$lib_name] = new $lib_name($this->config);
        }
    }

    /*
     * This method will disable all standard output and set signal handlers.
     * Return: void
     */
    public function set_daemond_env(){
        // Because a daemond has no controlling terminal, it will raise some problems if daemond try to ouput something.
        // Hence, we disable all output
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen('/dev/null', 'wb');
        $STDERR = fopen('/dev/null', 'wb');

        // Set signal handler
        declare(ticks=1);
        pcntl_signal(SIGTERM, array('masterchief','signal_handler'));
        pcntl_signal(SIGHUP, array('masterchief','signal_handler'));
        pcntl_signal(SIGINT, array('masterchief','signal_handler'));
        pcntl_signal(SIGUSR1, array('masterchief','signal_handler'));
    }

    /*
     * This method definds the behavior of signal handler.
     * Return: void
     */
    public function signal_handler($signo){
        switch($signo){
            case SIGUSR1:
                break;
            default:
                $this->libs['mc_log_mgr']->write_log('Daemond stop!');
                exit();
        }
    }

    /*
     * This method will fork twice to make itself into a daemond.
     *      Something you should know about a daemond:
     *      The most important thing about daemond is it has to run forever. 
     *      In order to do that, the first thing is make it the child of init process. And we can do it by one fork and make the father exit.
     *      Also we do not want the daemond has a controlling terminal neither because we do not want someone accidently terminate a daemond using controlling terminal.
     *      To do that, we will invoke posix_setsid() in child process to make it a new session and process group leader which will detach it from any exist controlling terminal.
     *      But a session leader still has chance to regain a controlling terminal, so we have to invoke fork in child process again.
     *      Because grand child will not be a process group and session leader, it can not obtain a controlling terminal.
     *      Finally, we make child process exit making grand child process a orphan process which will be adopted by init process.
     *      And here is a basic daemond structure.
     * It will listen a certain TCP socket for incoming job request.
     * Onec it receive a job request, it will either runs the job or put job into a queue depends on type of request and reply the request client.
     * It will also write logs to local files and database.
     * Return: void
     */
    public function execute(){
        // First fork
        $child_pid = pcntl_fork();

        // Deal with process from first fork
        if($child_pid === -1){
            // If pid is -1, it means fork doesn't work so just shut the program down.
            die('Could not fork!');

        }elseif($child_pid){
            // Only father process will receive the PID of child process. 
            // So this part defines what should father process(very beginning process) do after fork.
            // What father process should do here is waiting child to end then kill itself.
            pcntl_wait($children_status); 
            $this->libs['mc_log_mgr']->write_log('Father out!');
            exit();

        }else{
            // This part is child process of first fork, we will fork it again then make grand child process runs as a daemond.
            // But before that, we invoke posix_setsid() to detach all controlling terminals from child process.
            posix_setsid();

            // Second fork
            $grand_child_pid = pcntl_fork();

            //Deal with process form second fork
            if($grand_child_pid === -1){
                // Something goes wrong while forking, just die.
                exit(1); 
            }elseif($grand_child_pid){
                // Child process part, just exit to make grand child process be adopted by init.
                $this->libs['mc_log_mgr']->write_log('Child out!');
                exit(); 
            }else{
                // Disable output and set signal handler
                $this->set_daemond_env();

                // Build a service socket
                $this->libs['mc_socket_mgr']->build_service_sockets();

                while(true){
                    // Check if there is any active service request
                    if($this->libs['mc_socket_mgr']->is_request_in()){
                        if($client_socket = socket_accept($this->libs['mc_socket_mgr']->service_sockets[0])){
                            // Check if current client connection number exceed the maximun limit or not
                            if(count($this->libs['mc_socket_mgr']->client_sockets) < $this->config['socket']['maxconn']){
                                // If not, store the client socket then go back to listen
                                $this->libs['mc_socket_mgr']->add_client_socket($client_socket);
                                //continue;
                            }else{
                                // Too many socket connections, reject new socket!
                                $reject_msg = 'Server is busy, please try later.';
                                $this->libs['mc_socket_mgr']->reply_client($client_socket, $reject_msg);
                                socket_close($client_socket);
                            }
                        }
                    }


                    if($this->libs['mc_socket_mgr']->is_active_client()){
                        // Process client request.
                        foreach($this->libs['mc_socket_mgr']->client_sockets as $client_socket_key => $client_socket){
                            if($input = socket_read($client_socket, 2048)){
                                // If client sending data, fork a child process(a worker process) to deal it.
                                $worker_pid = pcntl_fork();
                                if($worker_pid === -1){
                                }elseif(!$worker_pid){
                                    // Worker part
                                    $worker_thread_title = 'mc_worker_'.basename($input);
                                    setthreadtitle($worker_thread_title);
                                    $this->libs['mc_log_mgr']->write_log("$worker_thread_title is starting.");
                                    $sleep = rand(3, 8);
                                    sleep($sleep);

                                    $this->libs['mc_socket_mgr']->reply_client($client_socket, 'Job is done!');

                                    // Job done, close socket between worker and client.
                                    $this->libs['mc_socket_mgr']->close_client_socket($client_socket_key);
                                    $this->libs['mc_log_mgr']->write_log("$worker_thread_title is closed.");
                                    exit();
                                }else{
                                    // Service daemond part
                                    $this->workers[$worker_pid] = $client_socket_key;
                                }
                            }
                        }
                    }

                    // Use pnctl_waitpid() to reap finished worker, also using WNOHANG option for nonblocking mode.
                    // If error, return is -1. No child exit yet, return 0. Any child exit, return its PID.
                    $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
                    while($finished_worker_pid > 0){
                        // After a worker is finished, close socket between service daemond and client.
                        $this->libs['mc_socket_mgr']->close_client_socket($this->workers[$finished_worker_pid]);

                        // Remove finished worker from exist worker record.
                        unset($this->workers[$finished_worker_pid]);

                        // Write log
                        $this->libs['mc_log_mgr']->write_log("Worker(PID=$finished_worker_pid) is closed.");

                        $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
                        usleep(100000);
                    }
                    sleep(1);
                }
            }
        }
    }
}

$mc = new masterchief($argv);
$mc->execute();
