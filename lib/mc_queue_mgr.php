<?php

class mc_queue_mgr extends mc_basic_tool{
    
    public $queue_id;
    public $queue = null;
    public $is_serializeid = true;

    public function __construct($config = array()){
        parent::__construct($config);
        $this->queue_id = $this->config['queue']['id'];
    }

    public function build_a_queue(){
        if(msg_queue_exists($this->queue_id)){
            $this->queue = msg_get_queue($this->queue_id);
        }
    }

    public function is_queue_exist(){
        return msg_queue_exists($this->queue_id);
    }

    public function is_msg_in_queue(){
        if($this->queue != null){
            $queue_status = msg_stat_queue($this->queue);
            if($queue_status['msg_qnum'] > 0){
                return true;
            }else{
                return false;
            }
        }
        return false;
    }

    public function get_msg(){
        $type_filter = -3;
        $max_size = 256;
        $unserialized = $this->is_serialized;
        $flags = MSG_IPC_NOWAIT;

        if(msg_receive($this->queue, $type_filter, $msg_type, $max_size, $msg, $unserialized, $flags, $err)){
            //return array('status'=>true, 'payload'=>array('type' => $msg_type, 'msg' => $msg));
            return array('status'=>true, 'payload'=>$msg['payload']);
        }
        
        return array('status'=>false, 'payload'=>array('err' => $err));
    }

    public function send_msg($msg, $msg_type=1){
        $is_serialized = $this->is_serialized;
        $is_block = false;
        
        if($this->is_queue_exist()){
            $queue = msg_get_queue($this->queue_id);
            return msg_send($queue, $msg_type, $msg, $is_serialze, $is_block, $err);
        }
    }
}
