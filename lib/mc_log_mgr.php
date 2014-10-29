<?php

require('mc_basic_tool.php');

class mc_log_mgr extends mc_basic_tool{

    public function __construct($config = array()){
        parent::__construct($config);
    }

    public function write_log($msg, $level = 'INFO'){

        // Prepare log full path
        $log_dir = $this->config['log']['dir'];
        $log_name = $this->config['log']['log_name'];
        $errlog_name = $this->config['log']['errlog_name'];
        $log = rtrim($log_dir, '/').'/'.$log_name.'.log';
        $errlog = rtrim($log_dir, '/').'/'.$errlog_name.'.log';

        // Prepare log message
        $msg = date('Y-m-d_H:i:s')." [$level] ".$msg;

        try{
            // Make sure log directory exist
            $this->create_dir($log_dir);

            // Write message into log file
            if($level == 'INFO' or $level == 'NOTICE' or $level == 'DEBUG'){
                file_put_contents($log, $msg."\n", FILE_APPEND|LOCK_EX);
            }else{
                file_put_contents($errlog, $msg."\n", FILE_APPEND|LOCK_EX);
            }

        }catch(Exception $e){
            echo $e->getMessage();
        }

    }

    public function logrotate($size=3, $rotate_num=4){
    }

}
