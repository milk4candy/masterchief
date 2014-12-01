<?php
abstract class daemon {

    /***********************
     * Define data members *
     ***********************/

    public $classname;
    public $proj_dir;
    public $pid;



    /*************************
     * Define method members *
     *************************/

    /*
     * This method is constructor whic will execute when generate instance of this class.
     * Return: void
     */
    public function __construct(){
        // Error report setting
        error_reporting(E_ALL);

        // Timezone setting
        date_default_timezone_set("Asia/Taipei");
        
        $this->classname = get_class($this);
        $this->proj_dir = dirname(dirname(__DIR__));
        $this->pid = getmypid();
    } 

    /*
     * This method is destructor whic will execute when instance of this class is destroyed.
     * Return: void
     */
    public function __destruct(){
    }

    /*
     * This method will init all necessary setting before running daemon loop.
     * Return: void
     */
    public function set_daemon_env(){
        // Change directory to daemon location.
        chdir($this->proj_dir);        

        $this->disable_std_output();

        $this->set_signal_handler();

        $this->custom_preparation();

    }

    /*
     * This method will disable all standard output.
     * Return: void
     */
    public function disable_std_output(){
        // Because a daemon has no controlling terminal, it will raise some problems if daemon try to ouput something.
        // Hence, we disable all output
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen('/dev/null', 'wb');
        $STDERR = fopen('/dev/null', 'wb');
    }

    /*
     * This method will set signal handlers.
     * Return: void
     */
    public function set_signal_handler(){
        // Set signal handler
        pcntl_signal(SIGTERM, array($this,'signal_handler'));
        pcntl_signal(SIGHUP, array($this,'signal_handler'));
        pcntl_signal(SIGINT, array($this,'signal_handler'));
        pcntl_signal(SIGCHLD, array($this,'signal_handler'));
        pcntl_signal(SIGUSR1, array($this,'signal_handler'));
    }

    /*
     * This method will definds all necessary custom prepare that daemon need before running daemon loop.
     * Return: void
     */
    public function custom_preparation(){
    }

    /* This method will fork twice to make itself into a daemon.
     *      Something you should know about a daemon:
     *      The most important thing about daemon is it has to run forever. 
     *      In order to do that, the first thing is make it the child of init process. And we can do it by one fork and make the father exit.
     *      Also we do not want the daemon has a controlling terminal neither because we do not want someone accidently terminate a daemon using controlling terminal.
     *      To do that, we will invoke posix_setsid() in child process to make it a new session and process group leader which will detach it from any exist controlling terminal.
     *      But a session leader still has chance to regain a controlling terminal, so we have to invoke fork in child process again.
     *      Because grand child will not be a process group and session leader, it can not obtain a controlling terminal.
     *      Finally, we make child process exit making grand child process a orphan process which will be adopted by init process.
     *      And here is a basic daemon structure.
     *
     *  Return: void
     */
    public function daemonize(){
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
            exit();

        }else{
            // This part is child process of first fork, we will fork it again then make grand child process runs as a daemon.
            // But before that, we invoke posix_setsid() to detach all controlling terminals from child process.
            posix_setsid();

            // Even there are not much meaning, we still set pid attribute in mastercheif object in child process to correct value.
            $this->pid = getmypid();

            // Second fork
            $grand_child_pid = pcntl_fork();

            //Deal with process form second fork
            if($grand_child_pid === -1){
                // Something goes wrong while forking, just die.
                exit(1); 
            }elseif($grand_child_pid){
                // Child process part, just exit to make grand child process be adopted by init.
                exit(); 
            }else{

                // Grand child process part, also the Daemon part.
                // We set pid attribute in mastercheif object in grand child process to the correct value.
                $this->pid = getmypid();

                // Disable output and set signal handler
                $this->set_daemon_env();

                // Run daemon loop
                $this->daemon_loop();

            } /* End of second fork */
        } /* End of first fork */
    }

    /*
     *  This method is a start wrapper of daemon
     *
     *  Return: void
     */
    public function execute(){
        $this->daemonize();
    }

    /*
     * This method definds the behavior of signal handler.
     * Return: void
     */
    abstract public function signal_handler($signo);

    /*
     * This method definds the behavior of daemon.
     *
     * Return: void
     */
    abstract public function daemon_loop();

}

