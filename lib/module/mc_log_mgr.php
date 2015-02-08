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
        $this->log = rtrim($log_dir, $this->ds).$this->ds.$log_name.'.log';
        $this->errlog = rtrim($log_dir, $this->ds).$this->ds.$errlog_name.'.log';
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

    public function logrotate($size=1, $rotate_num=5){
        if(file_exists($this->log) and is_file($this->log)){
            if(filesize($this->log)/102400 > $size){
                for($i=$rotate_num; $i>0; $i-- ){
                    if(file_exists($this->log.".$i") and is_file($this->log.".$i")){
                        if($i == $rotate_num){
                            exec("rm -f ".$this->log.".$i", $output, $exec_code);
                        }else{
                            exec("mv -f ".$this->log.".$i ".$this->log.".".($i+1), $output, $exec_code);
                        }
                    }
                }
                exec("mv -f ".$this->log." ".$this->log.".1", $output, $exec_code);
            }
        }

        if(file_exists($this->errlog) and is_file($this->errlog)){
            if(filesize($this->errlog)/102400 > $size){
                for($i=$rotate_num; $i>0; $i-- ){
                    if(file_exists($this->errlog.".$i") and is_file($this->errlog.".$i")){
                        if($i == $rotate_num){
                            exec("rm -f ".$this->errlog.".$i", $output, $exec_code);
                        }else{
                            exec("mv -f ".$this->errlog.".$i ".$this->errlog.".".($i+1), $output, $exec_code);
                        }
                    }
                }
                exec("mv -f ".$this->errlog." ".$this->errlog.".1", $output, $exec_code);
            }
        }
    }

}
