#! /usr/bin/php

<?php

$ds = DIRECTORY_SEPARATOR;

require(__DIR__.$ds.'lib'.$ds.'core'.$ds.'mc_daemon.php');

class marine extends mc_daemon{

    /***********************
     * Define data members *
     ***********************/

    public $jobs = array();



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
                if(count($this->workers) < $this->config['basic']['maxworker']){
                    try{
                        $this->jobs = $this->libs['mc_db_mgr']->get_retry_jobs();
                        if(count($this->jobs) > 0){
                            $this->libs['mc_log_mgr']->write_log(count($this->jobs)." retry job(s) found.");
                        }
                    }catch(PDOException $e){
                        $this->libs['mc_log_mgr']->write_log("Something wrong when get retry job from DB.", "ERRO");
                        $this->jobs = array();
                        continue;
                    }
                    foreach($this->jobs as $job){
                        $this->job = $job;
                        $worker_pid = pcntl_fork();
                        if($worker_pid === -1){
                        }elseif(!$worker_pid){
                            // Worker part
                            
                            // Because we will create our timeout machanism, we disable php built-in timeout machanism.
                            set_time_limit(0);

                            $this->stime = time(); 
                            $this->pid = getmypid();
                            $this->hash = $this->job['payload']['hash'];
                            $this->proc_type = 'Worker';

                            $this->register_worker_to_db(true);

                            // A worker should not have any child worker and only should have one client socket.
                            $this->workers = array();

                            /*
                            $worker_thread_title = 'ctn_worker_'.$this->pid;
                            setthreadtitle($worker_thread_title);
                            */
                            $worker_thread_title = 'Worker(PID='.$this->pid.")";

                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is starting.");

                            $this->job['payload']['passwd'] = base64_decode($this->job['payload']['passwd']);

                            // Check if the job is allowed to execute
                            $this->authenticate_job();
                            if(!$this->job['status']){
                                $this->libs['mc_log_mgr']->write_log("$worker_thread_title : ".$this->job['msg'], $this->job['msg_level']);
                                $this->libs['mc_db_mgr']->report_worker_result_to_db("RF", $this->job['msg'], true);
                                $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                                exit();
                            }

                            // Excuting Job

                            // Prepare variables
                            $user = $this->job['payload']['user'];
                            $passwd = $this->job['payload']['passwd'];
                            $cmd = $this->job['payload']['cmd'];
                            $dir = $this->job['payload']['dir'];
                            $run_user = $this->job['payload']['run_user'];
                            $timeout = $this->job['payload']['timeout'] ? $this->job['payload']['timeout'] : $this->config['basic']['default_timeout'];
                            $log = array();

                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is executing '$cmd' under directory '$dir' by user '$user' as user '$run_user'.");


                            // Execute command
                            $output = array();
                            if($run_user == $user){
                                if($run_user == 'root'){
                                    //exec("cd $dir && $cmd 2>&1", $output, $exec_code);
                                    exec("echo $$ > ".$this->worker_pid_dir.$this->ds.$this->pid." && cd $dir && $cmd 2>&1", $output, $exec_code);
                                }else{
                                    //exec("su -l $user -c 'cd $dir && $cmd' 2>&1", $output, $exec_code);
                                    exec("su -l $user -c 'echo $$ > ".$this->worker_pid_dir.$this->ds.$this->pid." && cd $dir && $cmd' 2>&1", $output, $exec_code);
                                }
                            }else{
                                $cmd = str_replace('"', '\"', $cmd);
                                $cmd = str_replace("'", "\'", $cmd);
                                if($run_user == 'root'){
                                    //exec("su -l $user -c 'cd $dir && echo $passwd|sudo -S $cmd' 2>&1", $output, $exec_code);
                                    exec("su -l $user -c 'echo $passwd|sudo -S bash -c \"echo \\$\\$ > ".$this->worker_pid_dir.$this->ds.$this->pid." && cd $dir && $cmd\"' 2>&1", $output, $exec_code);
                                }else{
                                    //exec("su -l $user -c 'cd $dir && echo $passwd|sudo -S -u $run_user $cmd' 2>&1", $output, $exec_code);
                                    exec("su -l $user -c 'echo $passwd|sudo -S -u $run_user bash -c \"echo \\$\\$ > ".$this->worker_pid_dir.$this->ds.$this->pid." && cd $dir && $cmd\"' 2>&1", $output, $exec_code);
                                }
                            }
                            if($exec_code == 0){
                                if(count($output) > 0){
                                    $output_msg = implode("\n", $output);
                                    $log['msg'] = "$worker_thread_title executed the job sucessfully with following meaasge:\n$output_msg";
                                    $log['level'] = "INFO";
                                    $log['status'] = "RS";
                                }else{
                                    $log['msg'] = "$worker_thread_title executed the job sucessfully with no meaasge.";
                                    $log['level'] = "INFO";
                                    $log['status'] = "RS";
                                }
                            }else{
                                if(count($output) > 0){
                                    $output_msg = implode("\n", $output);
                                    $log['msg'] = "$worker_thread_title executed the job abnormally with following meaasge:\n$output_msg";
                                    $log['level'] = "WARN";
                                    $log['status'] = "RF";
                                }else{
                                    $log['msg'] = "$worker_thread_title executed the job abnormally with no meaasge.";
                                    $log['level'] = "WARN";
                                    $log['status'] = "RF";
                                }
                            }

                            $this->libs['mc_log_mgr']->write_log($log['msg'], $log['level']);
                            $this->report_worker_result_to_db($log['status'], $log['msg'], true);
                            $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                            exit();
                        }else{
                            // Service daemon part
                            $timeout = $this->job['payload']['timeout'] ? $this->job['payload']['timeout'] : $this->config['basic']['default_timeout'];
                            $this->workers[$worker_pid] = array('start_time' => time(), 'timeout' => $timeout, 'terminate_times' => 0);
                            $job_cmd = explode(' ', $this->job['payload']['cmd']);
                            $this->libs['mc_log_mgr']->write_log("Create a worker(PID=$worker_pid) for ".basename($job_cmd[0]));
                        } /* End of fork */
                    } /* End of foreach loop*/
                } /* End of max worker check */
            } /* End of declare */

            $this->clear_timeout_worker();

            $this->clear_uncaptured_zombies();

            $this->libs['mc_log_mgr']->logrotate();

            $this->nap($this->config['basic']['interval']);




        } /* End of while loop */

    } /* End of function */

} /* End of class */

/*
 *  Main Program
 */

$mc = new marine($argv);
$mc->execute();
