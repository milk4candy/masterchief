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
                $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);
                if($finished_worker_pid > 0){
                    if(pcntl_wifexited($status)){
                        $exit_msg = "ctn_worker_$finished_worker_pid exited normally with exit code:".pcntl_wexitstatus($status).". Reaping it...";
                        $exit_msg_level = "INFO";
                    }elseif(pcntl_wifstopped($status)){
                        $exit_msg = "ctn_worker_$finished_worker_pid is stopped by signal:".pcntl_wstopsig($status).". Reaping it...";
                        $exit_msg_level = "WARN";
                    }elseif(pcntl_wifsignaled($status)){
                        $exit_msg = "ctn_worker_$finished_worker_pid exited by signal:".pcntl_wtermsig($status).". Reaping it...";
                        $exit_msg_level = "WARN";
                    }
                    $this->libs['mc_log_mgr']->write_log($exit_msg, $exit_msg_level);

                    // Remove finished worker from exist worker record.
                    if(isset($this->workers[$finished_worker_pid])){
                        unset($this->workers[$finished_worker_pid]);
                    }

                    // Remove pid file of finished worker
                    if(file_exists($this->worker_pid_dir."/".$finished_worker_pid)){
                        unlink($this->worker_pid_dir."/".$finished_worker_pid);
                    }

                    // Write log
                    $this->libs['mc_log_mgr']->write_log("ctn_worker_$finished_worker_pid is reaped.");
                }
                break;
            default:
                // Kill all workers
                if(count($this->workers) > 0){
                    $this->libs['mc_log_mgr']->write_log('Killing all exist workers...');
                    foreach($this->workers as $worker_pid => $worker_info){
                        $this->libs['mc_log_mgr']->write_log("Killing ctn_worker_$worker_pid)");
                        $this->kill_worker_by_pid($worker_pid, $signo);
                    }
                }

                // Wait for all workers exit
                $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);
                $reaping_time = time();
                while(count($this->workers) > 0){
                    if($finished_worker_pid > 0){
                        if(pcntl_wifexited($status)){
                            $exit_msg = "ctn_worker_$finished_worker_pid terminated with exit code:".pcntl_wexitstatus($status).". Reaping it...";
                            $exit_msg_level = "INFO";
                        }elseif(pcntl_wifstopped($status)){
                            $exit_msg = "ctn_worker_$finished_worker_pid is stopped by signal:".pcntl_wstopsig($status).". Reaping it...";
                            $exit_msg_level = "WARN";
                        }elseif(pcntl_wifsignaled($status)){
                            $exit_msg = "ctn_worker_$finished_worker_pid terminated by signal:".pcntl_wtermsig($status).". Reaping it...";
                            $exit_msg_level = "WARN";
                        }
                        $this->libs['mc_log_mgr']->write_log($exit_msg, $exit_msg_level);

                        // Remove finished worker from exist worker record.
                        if(isset($this->workers[$finished_worker_pid])){
                            unset($this->workers[$finished_worker_pid]);
                        }

                        // Remove pid file of finished worker
                        if(file_exists($this->worker_pid_dir."/".$finished_worker_pid)){
                            unlink($this->worker_pid_dir."/".$finished_worker_pid);
                        }

                        // Write log
                        $this->libs['mc_log_mgr']->write_log("ctn_worker_$finished_worker_pid was reaped.");
                        usleep(10000);
                    }

                    if(count($this->workers) == 0){
                        $this->libs['mc_log_mgr']->write_log("All workers were reaped.");
                    }

                    $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);

                    if(time() - $reaping_time > 10){
                        break;
                    }
                }

                if($this->proc_type == "Worker"){
                    $terminate_msg = "ctn_worker_".$this->pid." terminated before finish.";
                    $this->libs['mc_log_mgr']->write_log($terminate_msg, "WARN");
                    exit(1);
                }else{
                    $this->libs['mc_log_mgr']->write_log($this->proc_type.'('.$this->pid.') stop!');
                }

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
        $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);
        while($finished_worker_pid > 0){
            if(pcntl_wifexited($status)){
                $exit_msg = "ctn_worker_$finished_worker_pid exited with exit code:".pcntl_wexitstatus($status).". Reaping it...";
                $exit_msg_level = "INFO";
            }elseif(pcntl_wifstopped($status)){
                $exit_msg = "ctn_worker_$finished_worker_pid is stopped by signal:".pcntl_wstopsig($status).". Reaping it...";
                $exit_msg_level = "WARN";
            }elseif(pcntl_wifsignaled($status)){
                $exit_msg = "ctn_worker_$finished_worker_pid exited by signal:".pcntl_wtermsig($status).". Reaping it...";
                $exit_msg_level = "WARN";
            }
            $this->libs['mc_log_mgr']->write_log($exit_msg, $exit_msg_level);

            // Remove finished worker from exist worker record.
            if(isset($this->worker[$finished_worker_pid])){
                unset($this->workers[$finished_worker_pid]);
            }

            if(file_exists($this->worker_pid_dir."/".$finished_worker_pid)){
                unlink($this->worker_pid_dir."/".$finished_worker_pid);
            }

            // Write log
            $this->libs['mc_log_mgr']->write_log("Finished ctn_worker_$finished_worker_pid is Reaped.");

            $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);
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

        $this->libs['mc_log_mgr']->write_log("Daemon start.");

        while(true){
            declare(ticks=1){
                if($this->libs['mc_queue_mgr']->is_msg_in_queue()){
                    $job = $this->libs['mc_queue_mgr']->get_msg();
                    if($job['status']){
                        $worker_pid = pcntl_fork();
                        if($worker_pid === -1){
                        }elseif(!$worker_pid){
                            // Worker part
                            
                            // Because we will create our timeout machanism, we disable php built-in timeout machanism.
                            set_time_limit(0);
                            
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

                            // Excuting Job

                            // Prepare variables
                            $user = $job['payload']['user'];
                            $passwd = $job['payload']['passwd'];
                            $cmd = $job['payload']['cmd'];
                            $dir = $job['payload']['dir'];
                            $run_user = $job['payload']['run_user'];
                            $timeout = isset($job['payload']['timeout']) ? $job['payload']['timeout'] : $this->config['basic']['timeout'];
                            $log = array();

                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is executing $cmd under directory '$dir' by user '$user' as user '$run_user'.");


                            // Execute command
                            $output = array();
                            if($run_user == $user){
                                if($run_user == 'root'){
                                    //exec("cd $dir && $cmd 2>&1", $output, $exec_code);
                                    exec("echo $$ > ".$this->worker_pid_dir."/".$this->pid." && cd $dir && $cmd 2>&1", $output, $exec_code);
                                }else{
                                    //exec("su -l $user -c 'cd $dir && $cmd' 2>&1", $output, $exec_code);
                                    exec("su -l $user -c 'echo $$ > ".$this->worker_pid_dir."/".$this->pid." && cd $dir && $cmd' 2>&1", $output, $exec_code);
                                }
                            }else{
                                $cmd = str_replace('"', '\"', $cmd);
                                $cmd = str_replace("'", "\'", $cmd);
                                if($run_user == 'root'){
                                    //exec("su -l $user -c 'cd $dir && echo $passwd|sudo -S $cmd' 2>&1", $output, $exec_code);
                                    exec("su -l $user -c 'echo $passwd|sudo -S bash -c \"echo \\$\\$ > ".$this->worker_pid_dir."/".$this->pid." && cd $dir && $cmd\"' 2>&1", $output, $exec_code);
                                }else{
                                    //exec("su -l $user -c 'cd $dir && echo $passwd|sudo -S -u $run_user $cmd' 2>&1", $output, $exec_code);
                                    exec("su -l $user -c 'echo $passwd|sudo -S -u $run_user bash -c \"echo \\$\\$ > ".$this->worker_pid_dir."/".$this->pid." && cd $dir && $cmd\"' 2>&1", $output, $exec_code);
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
                            $this->workers[$worker_pid] = array('start_time' => time(), 'timeout' => $job['payload']['timeout']);
                            $job_cmd = explode(' ', $job['payload']['cmd']);
                            $this->libs['mc_log_mgr']->write_log("Create a worker(ctn_worker_$worker_pid) for ".basename($job_cmd[0]));
                        }
                    }else{
                        // do something when get_msg fail.
                        $this->libs['mc_log_mgr']->write_log("Can't get message from queue. ".$job['msg'], $job['msg_level']);
                    }
                }
            }

            $this->clear_timeout_worker();

            usleep(200000);

            $this->clear_uncaptured_zombies();

        } /* End of while loop */

    } /* End of function */

} /* End of class */

/*
 *  Main Program
 */

$mc = new cortana($argv);
$mc->execute();
