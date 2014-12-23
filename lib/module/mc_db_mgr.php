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
    public $dsn = null;
    public $username = null;
    public $password = null;

    public function __construct($config = array()){
        parent::__construct($config);
        $this->prepare_db_setting();
    }

    private function prepare_db_setting(){
        // Check all msut set DB config exist or not. If missing anyone, turn off DB record functionality.
        $must_param = array('driver', 'host', 'dbname', 'username', 'password');
        foreach($must_param as $param_name){
            if(!isset($this->config['db'][$param_name])){
                $this->db_config_is_all_set = false;
            }
        }

        // Read all DB config
        foreach($this->config['db'] as $param_name => $param_val){
            $this->$param_name = $param_val;
        }
        
        $this->db_config_is_all_set = true;
    }

    private function set_dsn(){
        if($this->port){
            $this->dsn = $this->driver.":host=".$this->host.";dbname=".$this->dbname.";charset=".$this->charset;
        }else{
            $this->dsn = $this->driver.":host=".$this->host.";port=".$this->port.";dbname=".$this->dbname.";charset=".$this->charset;
        }
    }

    private function connect_db(){
        $options = array(PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
        $this->pdo = new PDO($this->dsn, $username, $password, $options);
    }

    private function close_db(){
        $this->pdo = null;
    }

    public function write_worker_info_at_start($info){
        if($this->db_config_is_all_set){

            $this->set_dsn();

            $this->connect_db();

            $sql = "INSERT INTO worker_result (hash, stime, host, pid, user, run_user, dir, cmd, sync, timeout, retry, status, msg, category, sequence) ".
                   "VALUES (:hash, :stime, :host, :pid, :user, :run_user, :dir, :cmd, :sync, :timeout, :retry, :status, :msg, :cat, :seq)";

            $stmt = $this->pdo->prepare($sql);

            foreach($info as $field_name => $field_val){
                $stmt->bindParam(":$field_name", $field_val);
            }

            $stmt->execute();

            $this->close_db();
        }
    }

}
