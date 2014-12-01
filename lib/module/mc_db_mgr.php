<?php

class mc_db_mgr extends mc_basic_tool{

    public $pdo = null;
    public $activate = true;
    public $db_config_is_all_set = false;
    public $driver = null;
    public $host = null;
    public $port = null;
    public $dbname = null;
    public $chartset = 'utf8';
    public $username = null;
    public $password = null;

    public function __construct($config = array()){
        parent::__construct($config);
    }

    public function prepare_db_setting(){
        // Check all msut set DB config exist or not. If missing anyone, turn off DB record functionality.
        $must_param = array('driver', 'host', 'dbname', 'username', 'password');
        foreach($must_param as $param_name){
            if(!isset($this->config['db'][$param_name])){
                $this->db_config_is_all_set = false;
                return;
            }
        }

        // Read all DB config
        foreach($this->config['db'] as $param_name => $param_val){
            $this->$param_name = $param_val;
        }
        
        $this->db_config_is_all_set = true;
        return;

    }

    public function get_DSN(){
        $dsn = false;
        if($this->db_config_is_all_set){
            if($this->port){
                $dsn = $this->driver.":host=".$this->host.";dbname=".$this->dbname.";charset=".$this->charset;
            }else{
                $dsn = $this->driver.":host=".$this->host.";port=".$this->port.";dbname=".$this->dbname.";charset=".$this->charset;
            }
        }   

        return $dsn;
    }

    public function connect_db(){
    }

    public function close_db(){
    }

    public function exec_sql($sql, $data){
    }

    public function get_sql_result($stmt){
    }

}
