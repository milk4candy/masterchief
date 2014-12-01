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
        $this->args = $this->prepare_args($cmd_args);
        $this->config = $this->prepare_config();
        $this->init_libs($this->proj_dir.'/lib/module');
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
     *  This method will check if the request job is allowed to execute on local machine or not. 
     *
     *  Return: array
     */
    public function authenticate_job($job){
        $user = $job['payload']['user'];
        $passwd = $job['payload']['passwd'];
        $run_user = $job['payload']['run_user'];
        $cmd = $job['payload']['cmd'];

        // Authenticate username and password -- make sure this pair username and password can login on local machine.(including LDAP user)
        exec($this->proj_dir."/lib/module/auth.py $user $passwd >/dev/null 2>&1", $output, $pass_auth);
        if($pass_auth == 0){
            if($user == $run_user){
                $job['msg'] = 'Pass account authentication.';
                $job['msg_level'] = 'INFO';
            }else{
                $sudo_check = $this->libs['sudo_checker']->do_check($user, $run_user, $cmd);
                $job['status'] = $sudo_check['status'];
                $job['msg'] = $sudo_check['msg'];
                $job['msg_level'] = $sudo_check['msg_level'];
            }
        }else{
            $job['status'] = false;
            $job['msg'] = "Can't pass account authentication. Please make sure your username and password is correct.";
            $job['msg_level'] = "WARNING";
        }
        return $job;
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

