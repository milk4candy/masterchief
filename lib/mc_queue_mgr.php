<?php

class mc_queue_mgr {
    
    public $config;

    public function __construct($config = array()){
        $this->config = $config;
    }

    public function execute(){
        print_r($this->config);
    }

}
