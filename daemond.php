<?php
abstract class daemond {

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
    public function __construct($cmd_args, $default_args = array()){
        // Error report setting
        error_reporting(E_ALL);

        // Timezone setting
        date_default_timezone_set("Asia/Taipei");

        $this->script_path = dirname(__FILE__);
        $this->args = $this->prepare_args($cmd_args, $default_args);
        $this->config = $this->prepare_config();
        $this->init_libs();
        $this->pid = getmypid();
    } 

    /*
     * This method is destructor whic will execute when instance of this class is dead.
     * Return: void
     */
    public function __destruct(){
    }

    /*
     * This method will organize the incoming arguments to a array and return it.
     * Return: array
     */
    public function prepare_args($cmd_args, $default_args){
        $args = array();

        if(!in_array('-c', $cmd_args)){
            $args['config'] = $this->script_path.'/config/'.$default_args['config_name'].'.ini';
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
    public function init_libs($lib_dir='./lib'){
        require("./lib/mc_basic_tool.php");

        // Initial a empty array as a container for library objects
        $this->libs = array();

        // Read all requeired libraries defined in config file
        foreach($this->config['basic']['libraries'] as $lib_name){
            require("$lib_dir/$lib_name.php");
            $this->libs[$lib_name] = new $lib_name($this->config);
        }
    }

    /*
     * This method will disable all standard output and set signal handlers.
     * Return: void
     */
    public function set_daemond_env(){
        // Because a daemond has no controlling terminal, it will raise some problems if daemond try to ouput something.
        // Hence, we disable all output
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen('/dev/null', 'wb');
        $STDERR = fopen('/dev/null', 'wb');

        // Set signal handler
        pcntl_signal(SIGTERM, array($this,'signal_handler'));
        pcntl_signal(SIGHUP, array($this,'signal_handler'));
        pcntl_signal(SIGINT, array($this,'signal_handler'));
        pcntl_signal(SIGCHLD, array($this,'signal_handler'));
        pcntl_signal(SIGUSR1, array($this,'signal_handler'));
    }

    /*
     * This method definds the behavior of signal handler.
     * Return: void
     */
    abstract public function signal_handler($signo);

    /*
     * This method definds the behavior of daemond.
     * Return: void
     */
    abstract public function execute();
}

