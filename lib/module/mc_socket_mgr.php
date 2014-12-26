<?php

class mc_socket_mgr extends mc_basic_tool{

    public $service_socket = null;
    public $client_sockets = array();
    public $detected_sockets = null;

    public function __construct($config = array()){
        parent::__construct($config);
    }

    public function __destruct(){
       $this->close_service_socket(); 
       $this->close_client_sockets(); 
    }

    public function build_service_socket(){
        $socket_proto = getprotobyname('tcp');
        $socket = socket_create(AF_INET, SOCK_STREAM, $socket_proto);
        socket_bind($socket, $this->config['socket']['host'], $this->config['socket']['port']); 
        socket_listen($socket);
        socket_set_nonblock($socket);
        $this->service_socket = $socket;
    }

    public function close_client_socket($client_socket_key){
        socket_close($this->client_sockets[$client_socket_key]);
        unset($this->client_sockets[$client_socket_key]);
    }

    public function close_service_socket(){
        if($this->service_socket != null){
            socket_close($this->service_socket);
            $this->service_socket = null;
        }
    }

    public function close_client_sockets(){
        foreach($this->client_sockets as $client_socket_key => $client_socket){
            socket_close($client_socket);
            unset($this->client_sockets[$client_socket_key]);
        }
    }

    public function is_request_in(){
        $this->detected_sockets = array_merge(array($this->service_socket), $this->client_sockets);
        $w = null;
        $e = null;
        $tv_sec = 0;
        return socket_select($this->detected_sockets, $w, $e, $tv_sec);
    }

    public function is_request_from_service(){
        return in_array($this->service_socket, $this->detected_sockets);
    }

    public function is_request_from_client($client_socket){
        return in_array($client_socket, $this->detected_sockets);
    }

    public function add_client_socket($client_socket){
        array_push($this->client_sockets, $client_socket);
    }

    /*
    public function del_client_socket($socket_key){
        socket_shutdown($this->client_sockets[$socket_key]);
        socket_close($this->client_sockets[$socket_key]);
        unset($this->client_sockets[$socket_key]);
    }
     */

    public function reply_client($client_socket, $msg){
        socket_write($client_socket, $msg, strlen($msg));
    }

    public function reload_config($config){
        parent::reload_config($config);
        $this->bulid_service_socket();
    }

}
