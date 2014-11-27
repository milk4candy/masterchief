#! /usr/bin/php

<?php

require('daemond.php');

class cortana extends daemond{

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
     * It will listen a queue for incoming job request.
     * Onec it receive a job request, it will fork a child worker process to execute the job.
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
            //$this->libs['mc_log_mgr']->write_log('Father out!');
            exit();

        }else{
            // This part is child process of first fork, we will fork it again then make grand child process runs as a daemond.
            // But before that, we invoke posix_setsid() to detach all controlling terminals from child process.
            posix_setsid();

            // Even there are not much meaning, we still set pid attribute in cortana object in chlid process to the currect value.
            $this->pid = getmypid();

            // Second fork
            $grand_child_pid = pcntl_fork();

            //Deal with process form second fork
            if($grand_child_pid === -1){
                // Something goes wrong while forking, just die.
                exit(1); 
            }elseif($grand_child_pid){
                // Child process part, just exit to make grand child process be adopted by init.
                //$this->libs['mc_log_mgr']->write_log('Child out!');
                exit(); 
            }else{
                // Daemond part
                // We set pid attribute in cortana object in chlid process to the currect value.
                $this->pid = getmypid();

                // Disable output and set signal handler
                $this->set_daemond_env();

                $this->libs['mc_log_mgr']->write_log("Daemond start");

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

                                    // Check if the job is allowed to execute
                                    $job = $this->authenticate_job($job);
                                    if(!$job['status']){
                                        $this->libs['mc_log_mgr']->write_log($job['msg'].", worker(".$this->pid.") exits.");
                                        exit();
                                    }

                                    $this->libs['mc_log_mgr']->write_log("$worker_thread_title is starting.");
                                    $sleep = rand(3, 8);
                                    sleep($sleep);

                                    $log_msg ="Cortana will execute '".$job['payload']['cmd'].
                                              "' under directory '".$job['payload']['dir']."' ".
                                              "as user '".$job['payload']['run_user']."'";

                                    $this->libs['mc_log_mgr']->write_log($log_msg);

                                    $this->libs['mc_log_mgr']->write_log("$worker_thread_title is exiting.");
                                    exit();
                                }else{
                                    // Service daemond part
                                    $this->libs['mc_log_mgr']->write_log("Create a worker(PID=$worker_pid) for ".basename($job['payload']['cmd']));
                                    $this->workers[] = $worker_pid;
                                }
                            }else{
                                // do something when get_msg fail.
                            }
                        }
                    }
    
                    $this->clear_uncaptured_zombies();

                    usleep(200000);
                }
            }
        }
    }
}

$mc = new cortana($argv);
$mc->execute();
