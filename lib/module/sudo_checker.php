<?php

class sudo_checker{

    /*
     *  This function will accept three arguments to see if someone can run some command as someone else base on sudo privillege on local machine.
     *  It will get sudo rules array by invoking get_sudo_info() and get_sudo_rules()
     *  Then invoke check_cmd_privillege() to check if user can run input command as another centain user.
     *  A command must has a record in any 'allow' command rule array and has no record in all 'deny' command rule array for passing the check.
     *
     *  return: array
     */ 
    public function do_check($user, $as_user, $cmd){
        $pass_all_rules = false;
        $sudo_info = $this->get_sudo_info($user);
        if($sudo_info['status']){
            if(count($sudo_info['output']) > 0){
                $sudo_rules = $this->get_sudo_rules($sudo_info);
                foreach($sudo_rules as $rule){
                    if(in_array($as_user, $rule['user']) or in_array('ALL', $rule['user'])){
                        if($this->check_cmd_privilege($cmd, $rule['cmd']) == 'allow'){
                            $pass_all_rules = true;
                        }elseif($this->check_cmd_privilege($cmd, $rule['cmd']) == 'deny'){
                            $pass_all_rules = false;
                            break;
                        }
                    }
                }
                if($pass_all_rules){
                    return array('status' => $pass_all_rules, 'msg' => "", 'msg_level' => "INFO");
                }
                return array('status' => $pass_all_rules, 'msg' => "User $user is NOT allowed execute $cmd as user $as_user.", 'msg_level' => "WARNING");
            }
            return array('status' => $pass_all_rules, 'msg' => "User $user is not allowed to use sudo.", 'msg_level' => "WARNING");
        }
        return array('status' => $pass_all_rules, 'msg' => "Can't get sudo info.", 'msg_level' => "ERROR");
    }

    /*
     *  This function use shell command "sudo -l -U username|grep '('" to get sudo privillege text of user.
     *
     *  return: array
     */ 
    private function get_sudo_info($user){
        exec("sudo -l -U $user|grep '('", $output, $exec_code);
        if($exec_code == 0){
            return array('status' => true, 'output' => $output);
        }
        
        return array('status' => false, 'output' => array());
    }

    /*
     *  This function call parse_sudo_rule function to organize sudo privillege text into a rule array.
     *  For example, the sudo privillege text may look like:
     *      (linyinghsien, kaoheng) ALL, !/sbin/ifconfig
     *      (root) ALL
     *  The example above means there are two rules here for this user.
     *  Rule 1 is that user can run all commands except /sbin/ifconfig as user linyinghsien or kaoheng using sudo.
     *  Rule 2 is that user can run all command as root.
     *  This function go through each text rule and invoke parse_sudo_rule() to make each rule into an array.
     *  Then put all these rule arrays into a single array and return it.
     *  return: array
     */ 
    private function get_sudo_rules($info){
        $sudo_rules = array();
        if($info['status']){
            foreach($info['output'] as $line){
                $sudo_rules[] = $this->parse_sudo_rule($line);
            }
        }
        return $sudo_rules;
    }

    /*
     *  This function turns each text rule of sudo into a organized array then return it.
     *  Use previous example, say text rule like:
     *      (linyinghsien, kaoheng) ALL, !/sbin/ifconfig
     *  This function will parse it and make a rule array like:
     *      array('user' => 
     *                      array('linyinghsien', 'kaoheng'), 
     *            'cmd' => 
     *                      array('allow' => 
     *                                      array('ALL'), 
     *                            'deny' => 
     *                                      array('/sbin/ifconfig')
     *                           )
     *          )
     *
     *  return: array
     */
    private function parse_sudo_rule($rule){
        $rule_seg = explode(')', $rule);
        $users_str = trim($rule_seg[0]);
        $users_str = ltrim($users_str, '(');
        $users = explode(',', $users_str);
        foreach($users as $key => $user){
            $users[$key] = trim($user);
        }

        $cmds = array('allow' => array(), 'deny' => array());
        $cmds_str = trim($rule_seg[1]);
        $tmp_cmds = explode(',', $cmds_str);
        foreach($tmp_cmds as $key => $cmd){
            $tmp_cmd = trim($cmd);
            if($tmp_cmd[0] == '!'){
                $cmds['deny'][] = ltrim($tmp_cmd, '!');
            }else{
                $cmds['allow'][] = $tmp_cmd;
            }
        }

        return array('user' => $users, 'cmd' => $cmds);
        
    }

    /*
     *  This function will accept a input command and compare it with command part of a sudo rule array.
     *  Use previous example, say command rule array like:
     *      array('allow' => array('ALL')
     *            'deny'  => array('/sbin/ifconfig')
     *           )
     *  If input command is in 'allow' array or there is an 'ALL' element in it then return 'allow'.
     *  If input command is in 'deny' array or there is an 'ALL' element in it then return 'deny'.
     *  If not both conditions above then return 'neutral'.
     *
     *  return: string
     */
    private function check_cmd_privilege($cmd, $cmd_rule){

        $pass_this_rule = 'neutral';
        $cmd_seg = explode(' ', $cmd);
        $cmd_wo_arg = $cmd_seg[0];

        exec("readlink -f `which $cmd_wo_arg`", $output, $exec_code);
        $cmd_fullpath = $output[0];

        $deny_cmds = $cmd_rule['deny'];
        $allow_cmds = $cmd_rule['allow'];

        foreach($allow_cmds as $allow_cmd){
            if($allow_cmd == $cmd_fullpath or $allow_cmd == 'ALL'){
                $pass_this_rule = 'allow';
                break;
            }
        }

        foreach($deny_cmds as $deny_cmd){
            if($deny_cmd == $cmd_fullpath or $deny_cmd == 'ALL'){
                $pass_this_rule = 'deny';
                break;
            }
        }

        return $pass_this_rule;
    }

}
