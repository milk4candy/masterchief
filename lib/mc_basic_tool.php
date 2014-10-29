<?php

class mc_basic_tool {

    /*************************
     ** Define data members **
     *************************/
    
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

}
