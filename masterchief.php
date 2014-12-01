#! /usr/bin/php

<?php

require(__DIR__.'/lib/core/mc_daemon.php');

class masterchief extends mc_daemon{

    /***********************
     * Define data members *
     ***********************/



    /*************************
     * Define method members *
     *************************/

    /*
     * This method is constructor whic will execute when generate instance of this class.
     * Return: void
     */
    public function __construct($cmd_args){
        parent::__construct($cmd_args);
    } 

    /*
     * This method is destructor whic will execute when instance of this class destroy.
     * Return: void
     */
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
                    // After a worker is finished, close socket between service daemon and client.
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

    /*
     *  This method override inherited method to bulid a service socket before daemon loop 
     *  Return: void
     */
    public function custom_preparation(){
        // Build a service socket
        $this->libs['mc_socket_mgr']->build_service_socket();
    }

    /*
     *  This method is used to reap any exist zombie child process.
     *  Return: void
     */
    public function clear_uncaptured_zombies(){
        // Use pnctl_waitpid() to reap finished worker, also using WNOHANG option for nonblocking mode.
        // If error, return is -1. No child exit yet, return 0. Any child exit, return its PID.
        $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
        while($finished_worker_pid > 0){
            $this->libs['mc_log_mgr']->write_log("Found a finished worker(PID=$finished_worker_pid). Reaping it...");
            // After a worker is finished, close socket between service daemon and client.
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
     *  This method will organize the input socket message into a structure array.
     *  Return: void
     */
    public function parse_socket_input($input){
        $job = array();
        $status = true;
        $err = '';
        $payload = array();
        $input_array = explode(',', $input);
        if(!in_array('-h', $input_array)){
            $status = false;
            $err .= "missing -h argument.\n";
        }else{
            $payload['host'] = $input_array[array_search('-h', $input_array)+1];
        }
        if(!in_array('-u', $input_array)){
            $status = false;
            $err .= "missing -u argument.\n";
        }else{
            $payload['user'] = $input_array[array_search('-u', $input_array)+1];
        }
        if(!in_array('-p', $input_array)){
            $status = false;
            $err .= "missing -p argument.\n";
        }else{
            $payload['passwd'] = $input_array[array_search('-p', $input_array)+1];
        }
        if(!in_array('--dir', $input_array)){
            $status = false;
            $err .= "missing --dir argument.\n";
        }else{
            $payload['dir'] = $input_array[array_search('--dir', $input_array)+1];
        }
        if(!in_array('--run-user', $input_array)){
            $payload['run_user'] = $input_array[array_search('-u', $input_array)+1];
        }else{
            $payload['run_user'] = $input_array[array_search('--run-user', $input_array)+1];
        }
        if(!in_array('--cmd', $input_array)){
            $status = false;
            $err .= "missing --cmd argument.\n";
        }else{
            $payload['cmd'] = $input_array[array_search('--cmd', $input_array)+1];
        }
        if(!in_array('--sync', $input_array) and !in_array('--async', $input_array)){
            $payload['sync'] = false;
        }elseif(in_array('--sync', $input_array) and in_array('--async', $input_array)){
            $status = false;
            $err .= "--sync and --async can't coexist.'";
        }elseif(in_array('--sync', $input_array) and !in_array('--async', $input_array)){
            $payload['sync'] = true;
        }elseif(!in_array('--sync', $input_array) and in_array('--async', $input_array)){
            $payload['sync'] = false;
        }

        $job['status'] = $status;
        $job['payload'] = $payload;
        $job['msg'] = $err;
        $job['msg_level'] = $status ? "INFO" : "WARNING";

        return $job;
    }

    /*
     * This method will create an infinify loop to listen a certain TCP socket for incoming job request.
     * Onec it receive a job request, it will either runs the job or put job into a queue depends on type of request and reply the request client.
     * It will also write logs to local files and database.
     * Return: void
     */
    public function daemon_loop(){

        $this->libs['mc_log_mgr']->write_log("Daemon start.");

        while(true){
            /*
             *  In PHP, we can use "declare" key word to declare "ticks" key word to a certain interger number to apply ticks mechanism on a peice of code area.
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
             *  The reason we use ticks mechanism here is that we will use pnctl_signal() function to assign callback for signals in this daemon.
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

                                // parse input string to a organized job info array
                                $job = $this->parse_socket_input($input);
                                if(!$job['status']){
                                    $this->libs['mc_socket_mgr']->reply_client($client_socket, "Missing argument: ".$job['msg']);
                                    $this->libs['mc_log_mgr']->write_log("Missing argument: ".$job['msg'].", worker(".$this->pid.") exits.", $job['msg_level']);
                                    exit();
                                }

                                // If client sending validate data, fork a child process(a worker process) to deal it.
                                $worker_pid = pcntl_fork();

                                if($worker_pid === -1){
                                    $this->libs['mc_log_mgr']->write_log("Fail to create a worker", "ERROR");
                                }elseif(!$worker_pid){
                                    // Worker part
                                    $this->proc_type = 'Worker';
                                    $this->pid = getmypid();

                                    // A worker should not have any child worker and only should have one client socket.
                                    $this->workers = array();
                                    $this->libs['mc_socket_mgr']->client_sockets = array($client_socket);

                                    // Change the thread title
                                    $worker_thread_title = 'mc_worker_'.$this->pid;
                                    setthreadtitle($worker_thread_title);

                                    // Prepare variables
                                    $user = $job['payload']['user'];
                                    $passwd = $job['payload']['passwd'];
                                    $cmd = $job['payload']['cmd'];
                                    $dir = $job['payload']['dir'];
                                    $run_user = $job['payload']['run_user'];
                                    $log = array();

                                    $this->libs['mc_log_mgr']->write_log("$worker_thread_title is starting.");
                                    //$this->register_worker();

                                    // Check if the job is allowed to execute.
                                    $job = $this->authenticate_job($job);
                                    if(!$job['status']){
                                        $this->libs['mc_socket_mgr']->reply_client($client_socket, $job['msg']);
                                        $this->libs['mc_log_mgr']->write_log("$worker_thread_titlei : ".$job['msg'], $job['msg_level']);
                                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                                        exit();
                                    }

                                    // Excuting Job

                                    $this->libs['mc_log_mgr']->write_log("$worker_thread_title is executing $cmd under directory '$dir' by user '$user' as user $run_user");

                                    if($job['payload']['sync']){
                                        // Do the job now
                                        
                                        // Execute command
                                        $output = array();
                                        if($run_user == $user){
                                            if($run_user == 'root'){
                                                exec("cd $dir && $cmd 2>&1", $output, $exec_code);
                                            }else{
                                                exec("su -l $user -c 'cd $dir && $cmd' 2>&1", $output, $exec_code);
                                            }
                                        }else{
                                            if($run_user == 'root'){
                                                exec("su -l $user -c 'cd $dir && echo $passwd|sudo -S $cmd' 2>&1", $output, $exec_code);
                                            }else{
                                                exec("su -l $user -c 'cd $dir && echo $passwd|sudo -S -u $run_user $cmd' 2>&1", $output, $exec_code);
                                            }
                                        }
                                        if($exec_code == 0){
                                            // Job done.
                                            if(count($output) > 0){
                                                $output_msg = implode("\n", $output);
                                                $log['msg'] = "$worker_thread_title execute job successfully with following message:\n$output_msg";
                                                $log['level'] = "INFO";
                                            }else{
                                                $log['msg'] = "$worker_thread_title execute job successfully with no message.";
                                                $log['level'] = "INFO";
                                            }
                                        }else{
                                            // Job fail.
                                            if(count($output) > 0){
                                                $output_msg = implode("\n", $output);
                                                $log['msg'] = "$worker_thread_title execute job abnormally with following message:\n$output_msg";
                                                $log['level'] = "WARNING";
                                            }else{
                                                $log['msg'] = "$worker_thread_title execute job abnormally with no message.";
                                                $log['level'] = "WARNING";
                                            }
                                        }

                                    }else{
                                        // Put job in queue
                                        $this->libs['mc_log_mgr']->write_log("$worker_thread_title is putting job into queue.");
                                        $send_result = $this->libs['mc_queue_mgr']->send_msg($job);

                                        $log['msg']= "$worker_thread_title : ".$send_result['msg'];
                                        $log['level'] = $send_result['level'];
                                    }

                                    //Write log and reply client
                                    $this->libs['mc_socket_mgr']->reply_client($client_socket, $log['msg']);
                                    $this->libs['mc_log_mgr']->write_log($log['msg'], $log['level']);
                                    //$this->report_worker_result();
                                    $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                                    exit();

                                }else{
                                    // Service daemon part
                                        
                                    // Put new worker and its socket in to a mapping array for future management.
                                    $this->workers[$worker_pid] = $client_socket_key;
                                    $job_cmd = explode(' ', $job['payload']['cmd']);
                                    $this->libs['mc_log_mgr']->write_log("Create a worker(mc_worker_$worker_pid) for ".basename($job_cmd[0]));
                                }
                            }
                        }
                    }
                }
            } /* End of tick mecahism */

            // Basically, the callback function we set for SIGCHLD signal will reap every exited child worker process.
            // But here we scan again just in case we miss any SIGCHLD signal.
            $this->clear_uncaptured_zombies();

            // Let Daemon rest 0.2 seconds.
            usleep(200000);
        } /* End of while loop */

    } /* End of function */

} /* End of Class */

/*
 * Main Program
 */

$mc = new masterchief($argv);
$mc->execute();
