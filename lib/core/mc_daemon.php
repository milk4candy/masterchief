<?php

$ds = DIRECTORY_SEPARATOR;

require(__DIR__.$ds."daemon.php");

abstract class mc_daemon extends daemon {

    /***********************
     * Define data members *
     ***********************/

    public $args = null;
    public $config = null;
    public $libs = null;
    public $stime = null;
    public $hash = null;
    public $job = null;
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
        $this->daemon_pid_dir = $this->proj_dir.$this->ds."pid".$this->ds.$this->classname;
        $this->worker_pid_dir = $this->proj_dir.$this->ds."pid".$this->ds.$this->classname.$this->ds."worker";
        $this->args = $this->prepare_args($cmd_args);
        $this->config = $this->prepare_config();
        $this->init_libs($this->proj_dir.$this->ds.'lib'.$this->ds.'module');
        $this->create_daemon_pid_dir();
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
            $args['config'] = $this->proj_dir.$this->ds.'config'.$this->ds.$this->classname.'.ini';
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
    public function init_libs($lib_dir = '.'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'module'){
        // Initial a empty array as a container for library objects
        $this->libs = array();

        // Read all requeired libraries defined in config file
        foreach($this->config['basic']['libraries'] as $lib_name){
            require("$lib_dir".$this->ds."$lib_name.php");
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
                $this->reload_config();
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
                    $msg_level = "WARN";
                    $this->worker_behavior_when_worker_terminate($terminate_msg, $msg_level);
                    exit(1);
                }else{
                    $this->daemon_behavior_when_daemon_terminate();
                    exit();
                }
        }
    }

    public function reload_config(){
        $this->libs['mc_log_mgr']->write_log("Configuration reloading...");
        $this->config = $this->prepare_config();
        foreach($this->libs as $lib_name => $lib){
            if(preg_match('/^mc_/', $lib_name)){
                $this->libs[$lib_name]->reload_config($this->config);
            }
        }
        $this->libs['mc_log_mgr']->write_log("Configuration reload complete.");
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

    public function custom_preparation(){ 
        parent::custom_preparation();
        $this->create_daemon_pid_file();
        $this->stime = time();
        $this->hash = $this->gen_proc_hash(); 
    }

    public function create_daemon_pid_dir(){
        try{
            $this->libs['mc_log_mgr']->create_dir($this->daemon_pid_dir, 0777);
        }catch(Exception $e){
            echo $e->getMessage();
            exit(1);
        }
    }

    public function create_daemon_pid_file(){
        if(file_put_contents("$this->daemon_pid_dir".$this->ds."$this->classname.pid", $this->pid, LOCK_EX)){
            $this->libs['mc_log_mgr']->write_log("PID file is created.");
        }else{
            $this->libs['mc_log_mgr']->write_log("Can't create PID file, daemon stop!");
            exit(1);
        }
    }

    public function daemon_behavior_when_worker_exit($exit_worker_pid){
        // Remove finished worker from exist worker record.
        if(isset($this->workers[$exit_worker_pid])){
            unset($this->workers[$exit_worker_pid]);
        }

        // Remove pid file of finished worker.
        if(file_exists($this->worker_pid_dir.$this->ds.$exit_worker_pid)){
            unlink($this->worker_pid_dir.$this->ds.$exit_worker_pid);
        }
    }

    public function daemon_behavior_when_worker_terminate($terminate_worker_pid){
        $this->daemon_behavior_when_worker_exit($terminate_worker_pid);
    }

    public function worker_behavior_when_worker_terminate($terminate_msg, $msg_level){
        $this->libs['mc_log_mgr']->write_log($terminate_msg, $msg_level);
        $this->report_worker_result_to_db("T", $terminate_msg);
    }

    public function daemon_behavior_when_daemon_terminate(){
        unlink("$this->daemon_pid_dir".$this->ds."$this->classname.pid");
        $this->libs['mc_log_mgr']->write_log($this->proc_type." ".$this->classname."(PID=".$this->pid.") stop!");
    }


    /*
     *  This method will check if the request job is allowed to execute on local machine or not. 
     *
     *  Return: array
     */
    public function authenticate_job(){
        $user = $this->job['payload']['user'];
        $passwd = $this->job['payload']['passwd'];
        $run_user = $this->job['payload']['run_user'];
        $cmd = $this->job['payload']['cmd'];
        $dir = $this->job['payload']['dir'];

        // Authenticate username and password -- make sure this pair username and password can login on local machine.(including LDAP user)
        exec($this->proj_dir.$this->ds."lib".$this->ds."module".$this->ds."auth.py $user $passwd >".$this->ds."dev".$this->ds."null 2>&1", $output, $pass_auth);
        if($pass_auth == 0){
            $this->job['status'] = true;
            $this->job['msg'] = 'Pass account authentication.';
            $this->job['msg_level'] = 'INFO';
        }else{
            $this->job['status'] = false;
            $this->job['msg'] = "Can't pass account authentication. Please make sure your username and password is correct.";
            $this->job['msg_level'] = "WARNING";
        }
    }

    public function clear_timeout_worker(){
        foreach($this->workers as $worker_pid => $info){
            if(time() - $info['start_time'] > $info['timeout']){
                if($info['terminate_times'] == 0){
                    $msg = "Worker(PID=$worker_pid) reach timeout limit. Sending termination signal to it...";
                    $this->libs['mc_log_mgr']->write_log($msg);
                    $this->kill_worker_by_pid($worker_pid, SIGTERM);
                    $this->workers[$worker_pid]['terminate_times'] += 1;
                }elseif($info['terminate_times'] > 0 and $info['terminate_times'] < 2){
                    $msg = "Timeout worker(PID=$worker_pid) is still alive. Sending termination signal to it again....";
                    $this->libs['mc_log_mgr']->write_log($msg);
                    $this->kill_worker_by_pid($worker_pid, SIGTERM);
                    $this->workers[$worker_pid]['terminate_times'] += 1;
                }elseif($info['terminate_times'] == 2){
                    $msg = "Timeout worker(PID=$worker_pid) is still alive. Sending final termination signal to it....";
                    $this->libs['mc_log_mgr']->write_log($msg);
                    $this->kill_worker_by_pid($worker_pid, SIGTERM);
                    $this->workers[$worker_pid]['terminate_times'] += 1;
                    $this->libs['mc_log_mgr']->write_log($msg);
                }else{
                    // do nothing
                }
            }
        }
        
    }

    public function kill_worker_by_pid($worker_pid, $signo){

        // Kill worker first
        //posix_kill($worker_pid, $signo);


        // Find PPID of job which is executing by worker.
        $pid_file = $this->worker_pid_dir.$this->ds.$worker_pid;
        if(file_exists($pid_file)){
            exec("cat $pid_file", $job_ppid_output, $job_ppid_get_exec_code);
        }
        if($job_ppid_get_exec_code == 0 and count($job_ppid_output) == 1){
            $job_ppid = $job_ppid_output[0];
            //$this->libs['mc_log_mgr']->write_log("Job's PPID is: $job_ppid"); // This is debug outout
        }

        // Find job PIDs by its PPID
        $job_pids = $this->get_pids_by_ppid($job_ppid);

        /* Debug output
        if(count($job_pids) > 0){
            $this->libs['mc_log_mgr']->write_log("There are ".count($job_pids)." job(s). First job's pid is: $job_pids[0]");
        }
         */

        // Add those job PIDs to a array.
        $pids_to_kill = $job_pids;

        // Those job process might have child process, so use them to find all child jobs and thier descendants.
        while(count($job_pids) > 0){
            $new_job_pids_array = array();
            foreach($job_pids as $job_pid){
                $new_job_pids_array[] = $this->get_pids_by_ppid($job_pid);
            }

            $new_pids_to_kill = array();
            foreach($new_job_pids_array as $new_job_pids){
                foreach($new_job_pids as $new_job_pid){
                    $new_pids_to_kill[] = $new_job_pid;
                }
            }
            
            $pids_to_kill = array_merge($pids_to_kill, $new_pids_to_kill);
            
            $job_pids = $new_pids_to_kill; 
        }

        //$this->libs['mc_log_mgr']->write_log("There are ".count($pids_to_kill)." job(s) to kill."); // This is debug output

        if(count($pids_to_kill) > 0){
            // Kill all job process and their descendants. 
            sort($pids_to_kill, SORT_NUMERIC);
            $this->libs['mc_log_mgr']->write_log("Killing all jobs and their desendant jobs triggered by worker(PID=$worker_pid)...");
            foreach($pids_to_kill as $pid_to_kill){
                $this->libs['mc_log_mgr']->write_log("Killing job(PID=$pid_to_kill)...");
                posix_kill($pid_to_kill, $signo);
            }
        }

    }

    public function get_pids_by_ppid($ppid){
        exec("ps --ppid $ppid -o pid --no-heading", $pids, $exec_code);
        if($exec_code == 0){
            foreach($pids as $key => $pid){
                $pids[$key] = trim($pid);
            }
            return $pids;
        }
        return array();
    }

    public function create_worker_pid_dir(){
        try{
            $this->libs['mc_log_mgr']->create_dir($this->worker_pid_dir, 0777);
        }catch(Exception $e){
            echo $e->getMessage();
            exit(1);
        }
    }

    public function gen_proc_hash(){
        return md5($this->config['basic']['host'].$this->stime.$this->pid);
    }

    public function register_worker_to_db($is_retry = false){
        $info = array('job' => array(), 'exec' => array());
        
        $info['job']['hash'] = $this->hash;
        $info['exec']['hash'] = $this->hash;
        $info['exec']['stime'] = date("Y-m-d H:i:s", $this->stime);
        $info['exec']['etime'] = null;
        $info['exec']['host'] = $this->config['basic']['host'];
        $info['exec']['pid'] = $this->pid;
        
        foreach($this->job['payload'] as $arg_name => $arg_val){
            if($arg_name == "timeout"){
                $info['job']["$arg_name"] = $arg_val ? $arg_val : $this->config['basic']['default_timeout'];
            }elseif($arg_name == "passwd"){
                $info['job']["$arg_name"] = base64_encode($arg_val);
            }else{
                $info['job']["$arg_name"] = $arg_val;
            }
        }

        $info['exec']['status'] = "R";
        $info['exec']['msg'] = NULL;

        try{
            if($is_retry){
                $this->libs['mc_db_mgr']->write_retry_info_at_start($info);
            }else{
                $this->libs['mc_db_mgr']->write_worker_info_at_start($info);
            }
        }catch(PDOException $e){
            $this->libs['mc_log_mgr']->write_log("Write DB error with message: ".$e->getMessage(), "WARN");
        }
    }

    public function report_worker_result_to_db($status, $msg, $is_retry = false){
        $info = array('job' => array(), 'exec' => array());
        
        $info['job']['hash'] = $this->hash;
        $info['exec']['hash'] = $this->hash;
        $info['exec']['stime'] = date("Y-m-d H:i:s", $this->stime);
        $info['exec']['etime'] = date("Y-m-d H:i:s");
        $info['exec']['host'] = $this->config['basic']['host'];
        $info['exec']['pid'] = $this->pid;
        
        foreach($this->job['payload'] as $arg_name => $arg_val){
            if($arg_name == "timeout"){
                $info['job']["$arg_name"] = $arg_val ? $arg_val : $this->config['basic']['default_timeout'];
            }elseif($arg_name == "passwd"){
                $info['job']["$arg_name"] = base64_encode($arg_val);
            }else{
                $info['job']["$arg_name"] = $arg_val;
            }
        }

        $info['exec']['status'] = $status;
        $info['exec']['msg'] = $msg;

        try{
            if($is_retry){
                $this->libs['mc_db_mgr']->write_retry_info_at_finish($info);
            }else{
                $this->libs['mc_db_mgr']->write_worker_info_at_finish($info);
            }
        }catch(PDOException $e){
            $this->libs['mc_log_mgr']->write_log("Write DB error with message: ".$e->getMessage(), "WARN");
        }
    }

    public function nap($last_time){
        declare(ticks=1){
            $now = time();
            while(time() - $now <= $last_time){
                $do_nothing = null;
            }
        }
    }

}

