#! /usr/bin/php

<?php

require('daemond.php');

class masterchief extends daemond{

    /***********************
     * Define data members *
     ***********************/

    public $script_path;
    public $args;
    public $config;
    public $libs;
    public $workers = array();
    public $proc_type = 'Daemond';
    public $pid;



    /*************************
     * Define method members *
     *************************/

    /*
     * This method is constructor whic will execute when generate instance of this class.
     * Return: void
     */
    public function __construct($cmd_args){
        parent::__construct($cmd_args, array('config_name' => 'main'));
    } 


    public function __destruct(){
        parent::__destruct();
    }

    /*
     * This method definds the behavior of signal handler.
     * Return: void
     */
    public function signal_handler($signo){
        switch($signo){
            case SIGUSR1:
                break;
            case SIGCHLD:
                /*
                 *  When child process exits, it will send a SIGCHLD signal to parent process.
                 *  In Unix, default behavior is to ignore such signal.
                 *  Here we capture this signal and reap exited child with pcntl_waitpid() to prevent zombie process.
                 */
                $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
                if($finished_worker_pid > 0){
                    $this->libs['mc_log_mgr']->write_log("Worker(PID=$finished_worker_pid) is sending exit signal. Reaping it...");
                    // After a worker is finished, close socket between service daemond and client.
                    $this->libs['mc_socket_mgr']->close_client_socket($this->workers[$finished_worker_pid]);

                    // Remove finished worker from exist worker record.
                    unset($this->workers[$finished_worker_pid]);

                    // Write log
                    $this->libs['mc_log_mgr']->write_log("Finished worker(PID=$finished_worker_pid) is Reaped.");
                }
                break;
            default:
                // Before exit, Kill all workers first.
                if(count($this->workers) > 0){
                    $this->libs['mc_log_mgr']->write_log('Killing all exist workers...');
                    foreach($this->workers as $worker){
                        posix_kill($worker, $signo);
                    }
                }

                // Wait for all workers exit
                $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
                $reaping_time = time();
                while(count($this->workers) > 0){
                    if($finished_worker_pid > 0){
                        $this->libs['mc_log_mgr']->write_log("Reaping worker(PID=$finished_worker_pid)...");
                        $this->libs['mc_socket_mgr']->close_client_socket($this->workers[$finished_worker_pid]);

                        // Remove finished worker from exist worker record.
                        unset($this->workers[$finished_worker_pid]);

                        // Write log
                        $this->libs['mc_log_mgr']->write_log("Worker(PID=$finished_worker_pid) was reaped.");
                        usleep(10000);
                    }

                    if(count($this->workers) == 0){
                        $this->libs['mc_log_mgr']->write_log("All workers were reaped.");
                    }

                    $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);

                    if(time() - $reaping_time > 10){
                        break;
                    }
                }

                $this->libs['mc_log_mgr']->write_log($this->proc_type.'('.$this->pid.') stop!');
                exit();
        }
    }

    public function clear_uncaptured_zombies(){
        // Use pnctl_waitpid() to reap finished worker, also using WNOHANG option for nonblocking mode.
        // If error, return is -1. No child exit yet, return 0. Any child exit, return its PID.
        $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
        while($finished_worker_pid > 0){
            $this->libs['mc_log_mgr']->write_log("Found a finished worker(PID=$finished_worker_pid). Reaping it...");
            // After a worker is finished, close socket between service daemond and client.
            $this->libs['mc_socket_mgr']->close_client_socket($this->workers[$finished_worker_pid]);

            // Remove finished worker from exist worker record.
            unset($this->workers[$finished_worker_pid]);

            // Write log
            $this->libs['mc_log_mgr']->write_log("Finished worker(PID=$finished_worker_pid) is Reaped.");

            $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
            usleep(100000);
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
                exit(); 
            }else{

                // Grand child process part, also the Daemond part.

                // Disable output and set signal handler
                $this->set_daemond_env();

                // Build a service socket
                $this->libs['mc_socket_mgr']->build_service_socket();

                $this->libs['mc_log_mgr']->write_log("Daemond start.");

                while(true){
                    /*
                     *  In PHP, we can use "declare" key word to declare ticks key word to a certain interger number to apply ticks mechanism on a peice of code area.
                     *  In code area with ticks mechanism, a tick event will be rasied when PHP runs certain number of PHP statements(the number here depends on the number assigned to ticks).
                     *  When a tick event happen, PHP will invoke every function which is registered by a register_tick_function() function(if there is any).
                     *  For example, like following snippet:
                     *      function do_tick(){
                     *          echo 'tick';
                     *      }
                     *      declare(ticks=1){
                     *          $sum = 1 + 2 + 3;
                     *          echo $sum;
                     *      }
                     *
                     *  The output will be like:
                     *      tick6ticktick
                     *  
                     *  There are three tick events because declare statement itself also count.
                     *
                     *  The reason we use ticks mechanism here is that we will use pnctl_signal() function to assign callback for signals in this daemond.
                     *  And pnctl_signal() is also using ticks mechanism to register the callback function.
                     *  Hence, in order to make the callback functions will actually be called when receive signals, we have to declare a ticks area here.
                     */
                    declare(ticks=1){
                        // Check if there is any active request
                        if($this->libs['mc_socket_mgr']->is_request_in()){
                            if($this->libs['mc_socket_mgr']->is_request_from_service()){
                                if($client_socket = socket_accept($this->libs['mc_socket_mgr']->service_socket)){
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

                            // Process client request.
                            foreach($this->libs['mc_socket_mgr']->client_sockets as $client_socket_key => $client_socket){
                                if($this->libs['mc_socket_mgr']->is_request_from_client($client_socket)){
                                    if($input = socket_read($client_socket, 2048)){
                                        // If client sending data, fork a child process(a worker process) to deal it.
                                        $worker_pid = pcntl_fork();
                                        if($worker_pid === -1){
                                        }elseif(!$worker_pid){
                                            // Worker part
                                            $this->proc_type = 'Worker';
                                            $this->pid = getmypid();

                                            // A worker should not have any child worker and only should have one client socket.
                                            $this->workers = array();
                                            $this->libs['mc_socket_mgr']->client_sockets = array($client_socket);

                                            $worker_thread_title = 'mc_worker_'.basename($input);
                                            setthreadtitle($worker_thread_title);
                                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is starting.");
                                            $sleep = rand(3, 8);
                                            sleep($sleep);

                                            $this->libs['mc_socket_mgr']->reply_client($client_socket, 'Job('.$this->pid.') is done!');

                                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");

                                            exit();
                                        }else{
                                            // Service daemond part
                                                
                                            // Put new worker and its socket in to a mapping array for future management.
                                            $this->workers[$worker_pid] = $client_socket_key;
                                            $this->libs['mc_log_mgr']->write_log("Create a worker(PID=$worker_pid) for ".basename($input));
                                        }
                                    }
                                }
                            }
                        }
                    } /* End of tick mecahism */

                    // Basically, the callback function we set for SIGCHLD signal will reap every exited child worker process.
                    // But here we scan again just in case we miss any SIGCHLD signal.
                    $this->clear_uncaptured_zombies();

                    // Let Daemond rest 0.2 seconds.
                    usleep(200000);

                } /* End of While loop */
            } /* End of second fork */
        } /* End of first fork */
    } /* End of function */
} /* End of Class */

/*
 * Main Program
 */

$mc = new masterchief($argv);
$mc->execute();
