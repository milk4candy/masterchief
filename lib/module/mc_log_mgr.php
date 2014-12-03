<?php

class mc_log_mgr extends mc_basic_tool{

    public $log;
    public $errlog;

    public function __construct($config = array()){
        parent::__construct($config);
        $this->prepare_log_full_path();
    }

    public function prepare_log_full_path(){
        // Prepare log full path
        $log_dir = $this->config['log']['dir'];
        $log_name = $this->config['log']['log_name'];
        $errlog_name = $this->config['log']['errlog_name'];
        $this->log = rtrim($log_dir, '/').'/'.$log_name.'.log';
        $this->errlog = rtrim($log_dir, '/').'/'.$errlog_name.'.log';
    }

    public function write_log($msg, $level='INFO'){
        if($this->config['basic']['log']){
        // Prepare log message
            $msg = date('Y-m-d_H:i:s')." [$level] ".$msg;

            try{
                // Write message into log file
                if($level == 'INFO' or $level == 'NOTICE' or $level == 'DEBUG'){
                    // Make sure log directory exist
                    $this->create_dir(dirname($this->log));
                    file_put_contents($this->log, $msg."\n", FILE_APPEND|LOCK_EX);
                }else{
                    $this->create_dir(dirname($this->errlog));
                    file_put_contents($this->errlog, $msg."\n", FILE_APPEND|LOCK_EX);
                }

            }catch(Exception $e){
                echo $e->getMessage();
                exit(1);
            }
        }
    }

    public function logrotate($size=3, $rotate_num=4){
    }

}
