<?php

class mc_db_mgr extends mc_basic_tool{

    public $pdo = null;
    public $activate = true;
    public $db_config_is_all_set = false;
    public $driver = null;
    public $host = null;
    public $port = null;
    public $dbname = null;
    public $charset = 'utf8';
    public $dsn = null;
    public $username = null;
    public $password = null;

    public function __construct($config = array()){
        parent::__construct($config);
        $this->prepare_db_setting();
    }

    public function __destruct(){
        $this->close_db();        
    }

    private function prepare_db_setting(){
        $this->activate = $this->config['basic']['db'];
        
        if($this->activate){
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
        }
    }

    private function set_dsn(){
        if(!$this->port){
            $this->dsn = $this->driver.":host=".$this->host.";dbname=".$this->dbname.";charset=".$this->charset;
        }else{
            $this->dsn = $this->driver.":host=".$this->host.";port=".$this->port.";dbname=".$this->dbname.";charset=".$this->charset;
        }
    }

    private function connect_db(){
        $options = array(PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
        $this->pdo = new PDO($this->dsn, $this->username, $this->password, $options);
    }

    private function close_db(){
        $this->pdo = null;
    }

    public function write_worker_info_at_start($info){
        if($this->db_config_is_all_set and $this->activate){

            $this->set_dsn();

            $this->connect_db();

            $sql = "INSERT INTO job_info (hash, host, user, passwd, run_user, dir, cmd, sync, timeout, retry, category, sequence) ".
                   "VALUES (:hash, :host, :user, :passwd, :run_user, :dir, :cmd, :sync, :timeout, :retry, :cat, :seq)";

            $stmt = $this->pdo->prepare($sql);

            foreach($info['job'] as $field_name => $field_val){
                $stmt->bindValue(":$field_name", $field_val);
            }

            $stmt->execute();

            $sql = "INSERT INTO exec_info (hash, stime, etime, host, pid, status, msg) ".
                   "VALUES (:hash, :stime, :etime, :host, :pid, :status, :msg)";

            $stmt = $this->pdo->prepare($sql);

            foreach($info['exec'] as $field_name => $field_val){
                $stmt->bindValue(":$field_name", $field_val);
            }

            $stmt->execute();

            $this->close_db();
        }
    }

    public function write_worker_info_at_finish($info){
        if($this->db_config_is_all_set and $this->activate){

            $this->set_dsn();

            $this->connect_db();
            

            $sql = "UPDATE exec_info SET etime=:etime, status=:status, msg=:msg WHERE hash=:hash and pid=:pid";

            $stmt = $this->pdo->prepare($sql);

            $stmt->bindValue(":etime", $info["exec"]["etime"]);
            $stmt->bindValue(":status", $info["exec"]["status"]);
            $stmt->bindValue(":msg", $info["exec"]["msg"]);
            $stmt->bindValue(":hash", $info["exec"]["hash"]);
            $stmt->bindValue(":pid", $info["exec"]["pid"]);
            
            $stmt->execute();


            $this->close_db();
        }
    }

    public function get_retry_jobs(){

        if($this->db_config_is_all_set and $this->activate){

            $return_jobs = array();

            $this->set_dsn();

            $this->connect_db();

            // Get all retry jobs 
            $sql = "SELECT * FROM job_info WHERE retry > 0 AND hash IN (".
                       "SELECT hash FROM exec_info WHERE hash NOT IN (".
                           "SELECT hash FROM exec_info WHERE status LIKE '%S' OR status LIKE 'R' GROUP BY hash".
                       ") ".
                       "GROUP BY hash ".
                       "HAVING COUNT(hash) < 3".
                   ")";

            $stmt = $this->pdo->query($sql);
            
            $retry_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            for($i=0; $i<count($retry_jobs); $i++){
                foreach($retry_jobs[$i] as $field_name => $field_val){
                    $return_jobs[$i]['payload'][$field_name] = $field_val;
                    $return_jobs[$i]['status'] = true;
                }
            }

            $this->close_db();

            return $return_jobs;

        }
    }

    public function write_retry_info_at_start($info){
        if($this->db_config_is_all_set and $this->activate){

            $this->set_dsn();

            $this->connect_db();

            $sql = "INSERT INTO exec_info (hash, stime, etime, host, pid, status, msg) ".
                   "VALUES (:hash, :stime, :etime, :host, :pid, :status, :msg)";

            $stmt = $this->pdo->prepare($sql);

            foreach($info['exec'] as $field_name => $field_val){
                $stmt->bindValue(":$field_name", $field_val);
            }

            $stmt->execute();

            $this->close_db();
        }
    }

    public function write_retry_info_at_finish($info){
        $this->write_worker_info_at_finish($info);
    }

}
