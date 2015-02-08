<?php

abstract class mc_basic_tool {

    /*************************
     ** Define data members **
     *************************/
    
    public $ds = DIRECTORY_SEPARATOR;
    public $config;


    /***************************
     ** Define method members **
     ***************************/

    public function __construct($config){
        $this->config = $config;
    }

    public function create_dir($path, $mode = 0750){
        if(!(file_exists($path) and is_dir($path))){
            $is_created = mkdir($path, $mode, true);
            if(!$is_created){
                throw new Exception("Can't create directory '$path'.");
            }
        }
    }

    public function reload_config($config){
        $this->__construct($config);
    }

}
