<?php

require('mc_basic_tool.php')

class mc_socket_mgr extends mc_basic_tool{

    public $listening_socket;
    public $client_sockets = array();
    public $all_sockets = array()

    public function __construct($config = array()){
        parent::__construct($config);
    }

    public function build_listening_socket(){
        $socket_proto = getprotobyname('tcp');
        $socket = socket_create(AF_INET, SOCK_STREAM, $socket_proto);
        socket_bind($socket, $this->config['socket']['host'], $this->config['socket']['port']); 
        socket_listen($socket);
        socket_set_nonblock($socket);
        $this->listening_socket = $socket;
    }

    public function get_incoming_socket_num(){
        $this->all_sockets = array_merge(array($this->listening_socket), $this->client_sockets);
        return socket_select($this->all_sockets, null, null, 0);
    }

    public function is_listening_socket_exist(){
        return in_array($this->listening_socket, $this->all_sockets);
    }

    public function add_client_socket($client_socket){
        array_push($this->client_sockets, $client_socket);
    }

    public function del_client_socket($socket_key){
        socket_shutdown($this->client_sockets[$socket_key]);
        socket_close($this->client_sockets[$socket_key]);
        unset($this->client_sockets[$socket_key]);
    }


}
