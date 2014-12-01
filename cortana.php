#! /usr/bin/php

<?php

require(__DIR__.'/lib/core/mc_daemon.php');

class cortana extends mc_daemon{

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
     * This method is constructor whic will execute when instance of this class is destroyed.
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
                $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
                if($finished_worker_pid > 0){
                    $this->libs['mc_log_mgr']->write_log("Worker(PID=$finished_worker_pid) is sending exit signal. Reaping it...");

                    // Remove finished worker from exist worker record.
                    //unset($this->workers[$finished_worker_pid]);
                    unset($this->workers[array_search($finished_worker_pid, $this->workers)]);

                    // Write log
                    $this->libs['mc_log_mgr']->write_log("Finished worker(PID=$finished_worker_pid) is Reaped.");
                }
                break;
            default:
                // Kill all workers
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

                        // Remove finished worker from exist worker record.
                        //unset($this->workers[$finished_worker_pid]);
                        unset($this->workers[array_search($finished_worker_pid, $this->workers)]);

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
     *  This method is used to reap any exist zombie child process.
     *  Return: void
     */
    public function clear_uncaptured_zombies(){
        // Use pnctl_waitpid() to reap finished worker, also using WNOHANG option for nonblocking mode.
        // If error, return is -1. No child exit yet, return 0. Any child exit, return its PID.
        $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
        while($finished_worker_pid > 0){
            $this->libs['mc_log_mgr']->write_log("Found a finished worker(PID=$finished_worker_pid). Reaping it...");

            // Remove finished worker from exist worker record.
            if($finished_worker_key = array_search($finished_worker_pid, $this->worker)){
                //unset($this->workers[$finished_worker_key]);
                unset($this->workers[array_search($finished_worker_pid, $this->workers)]);
            }

            // Write log
            $this->libs['mc_log_mgr']->write_log("Finished worker(PID=$finished_worker_pid) is Reaped.");

            $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG);
            usleep(100000);
        }
    }

    /*
     * This method will run an infinity loop to listen a queue for incoming job request.
     * Onec it receive a job request, it will fork a child worker process to execute the job.
     * It will also write logs to local files and database.
     * Return: void
     */
    public function daemon_loop(){
        while(true){
            declare(ticks=1){
                if($this->libs['mc_queue_mgr']->is_msg_in_queue()){
                    $job = $this->libs['mc_queue_mgr']->get_msg();
                    if($job['status']){
                        $worker_pid = pcntl_fork();
                        if($worker_pid === -1){
                        }elseif(!$worker_pid){
                            // Worker part
                            $this->proc_type = 'Worker';
                            $this->pid = getmypid();

                            // A worker should not have any child worker and only should have one client socket.
                            $this->workers = array();

                            $worker_thread_title = 'ctn_worker_'.$this->pid;
                            setthreadtitle($worker_thread_title);

                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is starting.");

                            // Check if the job is allowed to execute
                            $job = $this->authenticate_job($job);
                            if(!$job['status']){
                                $this->libs['mc_log_mgr']->write_log("$worker_thread_title : ".$job['msg'], $job['msg_level']);
                                $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                                exit();
                            }

                            $sleep = rand(3, 8);
                            sleep($sleep);

                            // Excuting Job
                            $user = $job['payload']['user'];
                            $passwd = $job['payload']['passwd'];
                            $cmd = $job['payload']['cmd'];
                            $dir = $job['payload']['dir'];
                            $run_user = $job['payload']['run_user'];
                            $log = array();

                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is executing $cmd under directory '$dir' by user '$user' as user '$run_user'.");

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
                                if(count($output) > 0){
                                    $output_msg = implode("\n", $output);
                                    $log['msg'] = "$worker_thread_title executed the job sucessfully with following meaasge:\n$output_msg";
                                    $log['level'] = "INFO";
                                }else{
                                    $log['msg'] = "$worker_thread_title executed the job sucessfully with no meaasge.";
                                    $log['level'] = "INFO";
                                }
                            }else{
                                if(count($output) > 0){
                                    $output_msg = implode("\n", $output);
                                    $log['msg'] = "$worker_thread_title executed the job abnormally with following meaasge:\n$output_msg";
                                    $log['level'] = "WARNING";
                                }else{
                                    $log['msg'] = "$worker_thread_title executed the job abnormally with no meaasge.";
                                    $log['level'] = "WARNING";
                                }
                            }

                            $this->libs['mc_log_mgr']->write_log($log['msg'], $log['level']);
                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                            exit();
                        }else{
                            // Service daemon part
                            $this->workers[] = $worker_pid;
                            $job_cmd = explode(' ', $job['payload']['cmd']);
                            $this->libs['mc_log_mgr']->write_log("Create a worker(ctn_worker_$worker_pid) for ".basename($job_cmd[0]));
                        }
                    }else{
                        // do something when get_msg fail.
                        $this->libs['mc_log_mgr']->write_log("Can't get message from queue. ".$job['msg'], $job['msg_level']);
                    }
                }
            }

            $this->clear_uncaptured_zombies();

            usleep(200000);
        } /* End of while loop */

    } /* End of function */

} /* End of class */

$mc = new cortana($argv);
$mc->execute();
