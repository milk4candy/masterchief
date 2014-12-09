<?php

require(__DIR__.'/daemon.php');

abstract class mc_daemon extends daemon {

    /***********************
     * Define data members *
     ***********************/

    public $args;
    public $config;
    public $libs;
    public $workers = array();
    public $proc_type = 'Daemon';



    /*************************
     * Define method members *
     *************************/

    /*
     * This method is constructor whic will execute when generate instance of this class.
     * Return: void
     */
    public function __construct($cmd_args){
        parent::__construct();
        umask(0);
        $this->worker_pid_dir = $this->proj_dir."/pid/".$this->classname."/worker";
        $this->args = $this->prepare_args($cmd_args);
        $this->config = $this->prepare_config();
        $this->init_libs($this->proj_dir.'/lib/module');
        $this->create_worker_pid_dir();
    } 

    /*
     * This method is destructor whic will execute when instance of this class is destroyed.
     * Return: void
     */
    public function __destruct(){
    }

    /*
     * This method will organize the incoming arguments to a array and return it.
     * Return: array
     */
    public function prepare_args($cmd_args){
        $args = array();

        if(!in_array('-c', $cmd_args)){
            $args['config'] = $this->proj_dir.'/config/'.$this->classname.'.ini';
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
    public function init_libs($lib_dir = './lib/module'){
        // Initial a empty array as a container for library objects
        $this->libs = array();

        // Read all requeired libraries defined in config file
        foreach($this->config['basic']['libraries'] as $lib_name){
            require("$lib_dir/$lib_name.php");
            $class = new ReflectionClass($lib_name);
            if(!$class->isAbstract()){
                if(preg_match('/^mc_/', $lib_name)){
                    $this->libs[$lib_name] = new $lib_name($this->config);
                }else{
                    $this->libs[$lib_name] = new $lib_name();
                }
            }
        }
    }

    /*
     * This method definds the behavior of signal handler.
     * Return: void
     */
    public function signal_handler($signo){

        switch($this->classname){
            case "masterchief":
                //$worker_prefix = 'mc_worker_';
                $worker_prefix = 'Worker(PID=';
                break;
            case "cortana":
                //$worker_prefix = 'ctn_worker_';
                $worker_prefix = 'Worker(PID=';
                break;
            default:
                //$worker_prefix = 'worker_';
                $worker_prefix = 'Worker(PID=';
        }
    
        switch($signo){
            case SIGUSR1:
                break;
            case SIGCHLD:
                /*
                 *  When child process exits, it will send a SIGCHLD signal to parent process.
                 *  In Unix, default behavior is to ignore such signal.
                 *  Here we capture this signal and reap exited child with pcntl_waitpid() to prevent zombie process.
                 */
                $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG + WUNTRACED);
                if($finished_worker_pid > 0){
                    if(pcntl_wifexited($status)){
                        $exit_msg = $worker_prefix."$finished_worker_pid) exited with exit code:".pcntl_wexitstatus($status).". Reaping it...";
                        $exit_msg_level="INFO";
                    }elseif(pcntl_wifstopped($status)){
                        $exit_msg = $worker_prefix."$finished_worker_pid) is stopped by singal:".pcntl_wstopsig($status).". Reaping it...";
                        $exit_msg_level="WARN";
                    }elseif(pcntl_wifsignaled($status)){
                        $exit_msg = $worker_prefix."$finished_worker_pid) exited by singal:".pcntl_wtermsig($status).". Reaping it...";
                        $exit_msg_level="WARN";
                    }
                    $this->libs['mc_log_mgr']->write_log($exit_msg, $exit_msg_level);

                    $this->daemon_behavior_when_worker_exit($finished_worker_pid);

                    // Write log
                    $this->libs['mc_log_mgr']->write_log($worker_prefix."$finished_worker_pid) was reaped.");
                }
                break;
            default:
                // Before exit, Kill all workers first.
                if(count($this->workers) > 0){
                    $this->libs['mc_log_mgr']->write_log('Daemon is about to stop. Killing all exist workers...');
                    foreach($this->workers as $worker_pid => $worker_info){
                        $this->libs['mc_log_mgr']->write_log("Killing $worker_prefix"."$worker_pid)...");
                        $this->kill_worker_by_pid($worker_pid, $signo);
                    }
                }

                // Wait for all workers exit
                $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);
                $reaping_time = time();
                while(count($this->workers) > 0){
                    if($finished_worker_pid > 0){
                        if(pcntl_wifexited($status)){
                            $exit_msg = $worker_prefix."$finished_worker_pid) termiated with exit code:".pcntl_wexitstatus($status).". Reaping it...";
                            $exit_msg_level="INFO";
                        }elseif(pcntl_wifstopped($status)){
                            $exit_msg = $worker_prefix."$finished_worker_pid) is stopped by singal:".pcntl_wstopsig($status).". Reaping it...";
                            $exit_msg_level="WARN";
                        }elseif(pcntl_wifsignaled($status)){
                            $exit_msg = $worker_prefix."$finished_worker_pid) terminated by singal:".pcntl_wtermsig($status).". Reaping it...";
                            $exit_msg_level="WARN";
                        }
                        $this->libs['mc_log_mgr']->write_log($exit_msg, $exit_msg_level);

                        $this->daemon_behavior_when_worker_terminate($finished_worker_pid);

                        // Write log
                        $this->libs['mc_log_mgr']->write_log($worker_prefix."$finished_worker_pid) was reaped.");
                    }

                    if(count($this->workers) == 0){
                        $this->libs['mc_log_mgr']->write_log("All workers were reaped.");
                        break;
                    }
                    
                    if(time() - $reaping_time > 10){
                        break;
                    }

                    usleep(10000);

                    $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);

                }

                if($this->proc_type == 'Worker'){
                    $terminate_msg = $worker_prefix.$this->pid.") terminated before finished.";
                    $this->worker_behavior_when_worker_terminate($terminate_msg);
                    exit(1);
                }else{
                    $this->daemon_behavior_when_daemon_terminate();
                    exit();
                }
        }
    }

    /*
     *  This method is used to reap any exist zombie child process.
     *  Return: void
     */
    public function clear_uncaptured_zombies(){

        switch($this->classname){
            case "masterchief":
                //$worker_prefix = 'mc_worker_';
                $worker_prefix = 'Worker(PID=';
                break;
            case "cortana":
                //$worker_prefix = 'ctn_worker_';
                $worker_prefix = 'Worker(PID=';
                break;
            default:
                //$worker_prefix = 'worker_';
                $worker_prefix = 'Worker(PID=';
        }
    
        // Use pnctl_waitpid() to reap finished worker, also using WNOHANG option for nonblocking mode.
        // If error, return is -1. No child exit yet, return 0. Any child exit, return its PID.
        $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);
        while($finished_worker_pid > 0){
            if(pcntl_wifexited($status)){
                $exit_msg = "Found ".$worker_prefix."$finished_worker_pid) exited with exit code:".pcntl_wexitstatus($status).". Reaping it...";
                $exit_msg_level="INFO";
            }elseif(pcntl_wifstopped($status)){
                $exit_msg = "Found ".$worker_prefix."$finished_worker_pid) is stopped by singal:".pcntl_wstopsig($status).". Reaping it...";
                $exit_msg_level="WARN";
            }elseif(pcntl_wifsignaled($status)){
                $exit_msg = "Found ".$worker_prefix."$finished_worker_pid) exited by singal:".pcntl_wtermsig($status).". Reaping it...";
                $exit_msg_level="WARN";
            }
            $this->libs['mc_log_mgr']->write_log($exit_msg, $exit_msg_level);

            $this->daemon_behavior_when_worker_exit($finished_worker_pid);
            
            $this->libs['mc_log_mgr']->write_log($worker_prefix."$finished_worker_pid) was reaped.");

            usleep(100000);

            $finished_worker_pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED);
        }
    }

    public function daemon_behavior_when_worker_exit($exit_worker_pid){
        // Remove finished worker from exist worker record.
        if(isset($this->workers[$exit_worker_pid])){
            unset($this->workers[$exit_worker_pid]);
        }

        // Remove pid file of finished worker.
        if(file_exists($this->worker_pid_dir."/".$exit_worker_pid)){
            unlink($this->worker_pid_dir."/".$exit_worker_pid);
        }
    }

    public function daemon_behavior_when_worker_terminate($terminate_worker_pid){
        $this->daemon_behavior_when_worker_exit($terminate_worker_pid);
    }

    public function worker_behavior_when_worker_terminate($terminate_msg){
        $this->libs['mc_log_mgr']->write_log($terminate_msg, "WARN");
    }

    public function daemon_behavior_when_daemon_terminate(){
        $this->libs['mc_log_mgr']->write_log($this->proc_type." ".$this->classname."(PID=".$this->pid.") stop!");
    }


    /*
     *  This method will check if the request job is allowed to execute on local machine or not. 
     *
     *  Return: array
     */
    public function authenticate_job($job){
        $user = $job['payload']['user'];
        $passwd = $job['payload']['passwd'];
        $run_user = $job['payload']['run_user'];
        $cmd = $job['payload']['cmd'];
        $dir = $job['payload']['dir'];

        // Authenticate username and password -- make sure this pair username and password can login on local machine.(including LDAP user)
        exec($this->proj_dir."/lib/module/auth.py $user $passwd >/dev/null 2>&1", $output, $pass_auth);
        if($pass_auth == 0){
            $job['msg'] = 'Pass account authentication.';
            $job['msg_level'] = 'INFO';
        }else{
            $job['status'] = false;
            $job['msg'] = "Can't pass account authentication. Please make sure your username and password is correct.";
            $job['msg_level'] = "WARNING";
        }
        return $job;
    }

    public function clear_timeout_worker(){
        foreach($this->workers as $worker_pid => $info){
            if(time() - $info['start_time'] > $info['timeout']){
                if($this->classname == "masterchief"){
                    $msg = "mc_worker_$worker_pid reach timeout limit. Terminate it ....";
                }elseif($this->classname == 'cortana'){
                    $msg = "ctn_worker_$worker_pid reach timeout limit. Terminate it ....";
                }
                $this->libs['mc_log_mgr']->write_log($msg);
                $this->kill_worker_by_pid($worker_pid, SIGTERM);
            }
        }
        
    }

    public function kill_worker_by_pid($worker_pid, $signo){
        $pid_file = $this->worker_pid_dir."/".$worker_pid;
        posix_kill($worker_pid, $signo);
        if(file_exists($pid_file)){
            exec("ps --ppid `cat $pid_file` -o pid --no-heading|xargs kill -9", $job_kill_output, $job_kill_exec_code);
        }
        return;
    }

    public function create_worker_pid_dir(){
        try{
            $this->libs['mc_log_mgr']->create_dir($this->worker_pid_dir, 0777);
        }catch(Exception $e){
            echo $e->getMessage();
            exit(1);
        }
    }

    /*
     *
     */
    public function register_worker(){
    }

    /*
     *
     */
    public function report_worker_result(){
    }

}

