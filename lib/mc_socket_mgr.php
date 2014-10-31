<?php

class mc_socket_mgr extends mc_basic_tool{

    public $service_sockets = array();
    public $client_sockets = array();

    public function __construct($config = array()){
        parent::__construct($config);
    }

    public function build_service_sockets(){
        $socket_proto = getprotobyname('tcp');
        $socket = socket_create(AF_INET, SOCK_STREAM, $socket_proto);
        socket_bind($socket, $this->config['socket']['host'], $this->config['socket']['port']); 
        socket_listen($socket);
        socket_set_nonblock($socket);
        $this->service_sockets[] = $socket;
    }

    public function is_request_in(){
        $w = null;
        $e = null;
        $tv_sec = null;
        return socket_select($this->service_sockets, $w, $e, $tv_sec);
    }

    public function add_client_socket($client_socket){
        array_push($this->client_sockets, $client_socket);
    }

    public function del_client_socket($socket_key){
        socket_shutdown($this->client_sockets[$socket_key]);
        socket_close($this->client_sockets[$socket_key]);
        unset($this->client_sockets[$socket_key]);
    }

    public function is_active_client(){
        $w = null;
        $e = null;
        $tv_sec = null;
        return socket_select($this->client_sockets, $w, $e, $tv_sec);
    }

    public function reply_client($client_socket, $msg){
        socket_write($client_socket, $msg, strlen($msg));
    }

    public function close_client_socket($client_socket_key){
        socket_shutdown($this->client_sockets[$client_socket_key]);
        socket_close($this->client_sockets[$client_socket_key]);
        unset($this->client_sockets[$client_socket_key]);
    }


}
