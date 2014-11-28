<?php

class mc_queue_mgr extends mc_basic_tool{
    
    public $queue_id;
    public $queue = null;
    public $is_serialize = true;

    public function __construct($config = array()){
        parent::__construct($config);
        $this->queue_id = (int) $this->config['queue']['id'];
        $this->build_a_queue();
    }

    public function build_a_queue(){
        $this->queue = msg_get_queue($this->queue_id);
    }

    public function is_queue_exist(){
        return $this->queue;
    }

    public function is_msg_in_queue(){
        if($this->is_queue_exist()){
            $queue_status = msg_stat_queue($this->queue);
            if($queue_status['msg_qnum'] > 0){
                return true;
            }else{
                return false;
            }
        }
        return false;
    }

    public function get_msg_num(){
        if($this->is_queue_exist()){
            $status = msg_stat_queue($this->queue);
            return $status['msg_qnum'];
        }
        return false;
    }

    public function get_msg(){
        $type_filter = -3;
        $max_size = 4096;
        $unserialized = $this->is_serialize;
        $flags = MSG_IPC_NOWAIT;

        if($this->is_queue_exist()){
            if(msg_receive(msg_get_queue($this->queue_id), $type_filter, $msg_type, $max_size, $msg, $unserialized, $flags, $err)){
                return array('status'=>true, 'payload'=>$msg['payload'], 'msg'=> 'Get meaasge successfully', 'msg_level' => 'INFO');
            }
            return array('status'=>false, 'msg' => "Can't get message from queue. Error code is: ".$err, 'msg_level' => "ERROR");
        }
        
        return array('status'=>false, 'msg' => "Can't get message from queue. There is no queue exists.", 'msg_level' => "ERROR");
    }

    public function send_msg($msg, $msg_type=1){
        $payload = array();
        $is_serialize = $this->is_serialize;
        $is_block = false;
        
        if($this->is_queue_exist()){
            if($this->get_msg_num() < $this->config['queue']['maxqueue']){
                $status = msg_send($this->queue, $msg_type, $msg, $is_serialize, $is_block, $err);
                $payload['status'] = $status;
                if($status){
                    $payload['msg'] = 'Job has been put in queue.';
                    $payload['level'] = 'INFO';
                }else{
                    $payload['msg'] = 'Something wrong when sending Job.';
                    $payload['level'] = 'ERROR';
                }
                return $payload;
            }
            $payload['status'] = false;
            $payload['msg'] = 'Queue is full right now('.$this->get_msg_num().') Please try later.';
            $payload['level'] = 'WARNING';
            return $payload;
        }
        $payload['status'] = false;
        $payload['msg'] = 'There is no queue exists.';
        $payload['level'] = 'ERROR';

        return $payload;
    }
}
