<?php

class mc_db_mgr extends mc_basic_tool{

    public $pdo = null;
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

    public function init_parameters(){
        $must_param = array('driver', 'host', 'dbname', 'username', 'password');
        foreach($this->config['db'] as $param_name => $param_val){
            $this->$param_name = $param_val;
        }
    }

    public function get_DSN(){
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
