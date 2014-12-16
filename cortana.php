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
     * This method will run an infinity loop to listen a queue for incoming job request.
     * Onec it receive a job request, it will fork a child worker process to execute the job.
     * It will also write logs to local files and database.
     * Return: void
     */
    public function daemon_loop(){

        $this->libs['mc_log_mgr']->write_log("Daemon start.");

        while(true){
            declare(ticks=1){
                if(count($this->workers) < $this->config['basic']['maxworker'])
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

                                /*
                                $worker_thread_title = 'ctn_worker_'.$this->pid;
                                setthreadtitle($worker_thread_title);
                                */
                                $worker_thread_title = 'Worker(PID='.$this->pid.")";

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
                                $timeout = $job['payload']['timeout'] ? $job['payload']['timeout'] : $this->config['basic']['default_timeout'];
                                $log = array();

                                $this->libs['mc_log_mgr']->write_log("$worker_thread_title is executing '$cmd' under directory '$dir' by user '$user' as user '$run_user'.");


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
                                        $log['level'] = "WARN";
                                    }else{
                                        $log['msg'] = "$worker_thread_title executed the job abnormally with no meaasge.";
                                        $log['level'] = "WARN";
                                    }
                                }

                                $this->libs['mc_log_mgr']->write_log($log['msg'], $log['level']);
                                $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                                exit();
                            }else{
                                // Service daemon part
                                $timeout = $job['payload']['timeout'] ? $job['payload']['timeout'] : $this->config['basic']['default_timeout'];
                                $this->workers[$worker_pid] = array('start_time' => time(), 'timeout' => $timeout, 'terminate_times' => 0);
                                $job_cmd = explode(' ', $job['payload']['cmd']);
                                $this->libs['mc_log_mgr']->write_log("Create a worker(PID=$worker_pid) for ".basename($job_cmd[0]));
                            }
                        }else{
                            // do something when get_msg fail.
                            $this->libs['mc_log_mgr']->write_log("Can't get message from queue. ".$job['msg'], $job['msg_level']);
                        }
                    }
                } /* End of max worker check */
            } /* End of declare */

            $this->clear_timeout_worker();

            $this->clear_uncaptured_zombies();

            $this->libs['mc_log_mgr']->logrotate();

            usleep(200000);

        } /* End of while loop */

    } /* End of function */

} /* End of class */

/*
 *  Main Program
 */

$mc = new cortana($argv);
$mc->execute();
