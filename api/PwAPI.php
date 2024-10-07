<?php

require('./configs/config.php');

class Gamed
{

    public $cycle = false;
    public $online;
    public $protocol;
    public $pos = null;
    protected $config;

    public function __construct()
    {
        global $config;
        $this->config = $config;
        $this->cycle = false;
        $this->online = $this->serverOnline();
        $this->protocol = $this->config['protocolo' . $this->config['serverversion']];
    }

    public function serverOnline()
    {
        return (@fsockopen('127.0.0.1', $this->config['ports']['gamedbd'], $errCode, $errStr, 1) ? TRUE : FALSE);
    }


    public function deleteHeader($data)
    {
        $length = 0;
        $this->unpackCuint($data, $length);
        $this->unpackCuint($data, $length);
        $length += 8;
        $data = substr($data, $length);
        return $data;
    }
    public function createHeader($opcode, $data)
    {
        return $this->cuint($opcode) . $this->cuint(strlen($data)) . $data;
    }
    // public function packString($data)
    // {
    //     $data = iconv("UTF-8", "UTF-16LE//TRANSLIT", utf8_encode($data));
    //     return $this->cuint(strlen($data)) . $data;
    // }
    public function packString($data)
    {
        $data = iconv("UTF-8", "UTF-16LE", $data);
        return $this->cuint(strlen($data)) . $data;
    }
    public function packLongOctet($data)
    {
        return pack("n", strlen($data) + 32768) . $data;
    }
    public function packOctet($data)
    {
        $data = pack("H*", (string)$data);
        return $this->cuint(strlen($data)) . $data;
    }
    public function packInt($data)
    {
        return pack("N", $data);
    }
    public function packByte($data)
    {
        return pack("C", $data);
    }
    public function packFloat($data)
    {
        return strrev(pack("f", $data));
    }
    public function packShort($data)
    {
        return pack("n", $data);
    }
    public function packLong($data)
    {
        $left = 0xffffffff00000000;
        $right = 0x00000000ffffffff;
        $l = ($data & $left) >> 32;
        $r = $data & $right;
        return pack('NN', $l, $r);
    }
    public function hex2octet($tmp)
    {
        $t = 8 - strlen($tmp);
        for ($i = 0; $i < $t; $i++) {
            $tmp = '0' . $tmp;
        }
        return $tmp;
    }
    public function reverseOctet($str)
    {
        $octet = '';
        $length = strlen($str) / 2;
        for ($i = 0; $i < $length; $i++) {
            $tmp = substr($str, -2);
            $octet .= $tmp;
            $str = substr($str, 0, -2);
        }
        return $octet;
    }
    public function hex2int($value)
    {
        $value = str_split($value, 2);
        $value = $value[3] . $value[2] . $value[1] . $value[0];
        $value = hexdec($value);
        return $value;
    }
    public function getTime($str)
    {
        return hexdec($str);
    }
    public function getIp($str)
    {
        return long2ip(hexdec($str));
    }
    public function putIp($str)
    {
        $ip = ip2long($str);
        $ip = dechex($ip);
        $ip = hexdec($this->reverseOctet($ip));
        return $ip;
    }
    public function cuint($data)
    {
        if ($data < 64) return strrev(pack("C", $data));
        else if ($data < 16384) return strrev(pack("S", ($data | 0x8000)));
        else if ($data < 536870912) return strrev(pack("I", ($data | 0xC0000000)));
        return strrev(pack("c", -32) . pack("i", $data));
    }
    public function unpackLong($data)
    {
        //$data = pack("H*", $data);
        $set = unpack('N2', $data);
        return $set[1] << 32 | $set[2];
    }
    public function unpackOctet($data, &$tmp)
    {
        $p = 0;
        $size = $this->unpackCuint($data, $p);
        $octet = bin2hex(substr($data, $p, $size));
        $tmp = $tmp + $p + $size;
        return $octet;
    }
    public function unpackString($data, &$tmp)
    {
        $size = (hexdec(bin2hex(substr($data, $tmp, 1))) >= 128) ? 2 : 1;
        $octetlen = (hexdec(bin2hex(substr($data, $tmp, $size))) >= 128) ? hexdec(bin2hex(substr($data, $tmp, $size))) - 32768 : hexdec(bin2hex(substr($data, $tmp, $size)));
        $pp = $tmp;
        $tmp += $size + $octetlen;
        return mb_convert_encoding(substr($data, $pp + $size, $octetlen), "UTF-8", "UTF-16LE");
    }
    public function unpackCuint($data, &$p)
    {
        if ($this->config['version'] != '07') {
            $hex = hexdec(bin2hex(substr($data, $p, 1)));
            $min = 0;
            if ($hex < 0x80) {
                $size = 1;
            } else if ($hex < 0xC0) {
                $size = 2;
                $min = 0x8000;
            } else if ($hex < 0xE0) {
                $size = 4;
                $min = 0xC0000000;
            } else {
                $p++;
                $size = 4;
            }
            $data = (hexdec(bin2hex(substr($data, $p, $size))));
            $unpackCuint = $data - $min;
            $p += $size;
            return $unpackCuint;
        } else {
            $byte = unpack("Carray", substr($data, $p, 1));
            if ($byte['array'] < 0x80) {
                $p++;
            } else if ($byte['array'] < 0xC0) {
                $byte = unpack("Sarray", strrev(substr($data, $p, 2)));
                $byte['array'] -= 0x8000;
                $p += 2;
            } else if ($byte['array'] < 0xE0) {
                $byte = unpack("Iarray", strrev(substr($data, $p, 4)));
                $byte['array'] -= 0xC0000000;
                $p += 4;
            } else {
                $prom = strrev(substr($data, $p, 5));
                $byte = unpack("Iarray", strrev($prom));
                $p += 4;
            }
            return $byte['array'];
        }
    }
    public function SendToGamedBD($data)
    {
        return $this->SendToSocket($data, $this->config['ports']['gamedbd']);
    }
    public function SendToDelivery($data)
    {
        return $this->SendToSocket($data, $this->config['ports']['gdeliveryd'], true);
    }
    public function SendToProvider($data)
    {
        return $this->SendToSocket($data, $this->config['ports']['gacd']);
    }
    public function SendToSocket($data, $port, $RecvAfterSend = false, $buf = null)
    {
        global $config;

        if (@fsockopen('127.0.0.1', $port, $errCode, $errStr, 1)) {
            $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_connect($sock, '127.0.0.1', $port);
            if ($config['s_block'])
                socket_set_block($sock);
            if ($RecvAfterSend)
                socket_recv($sock, $tmp, 8192, 0);
            socket_send($sock, $data, strlen($data), 0);
            switch ($config['s_readtype']) {
                case 1:
                    socket_recv($sock, $buf, $config['maxbuffer'], 0);
                    break;
                case 2:
                    $buffer = socket_read($sock, 1024, PHP_BINARY_READ);
                    while (strlen($buffer) == 1024) {
                        $buf .= $buffer;
                        $buffer = socket_read($sock, 1024, PHP_BINARY_READ);
                    }
                    $buf .= $buffer;
                    break;
                case 3:
                    $tmp = 0;
                    $buf .= socket_read($sock, 1024, PHP_BINARY_READ);
                    if (strlen($buf) >= 8) {
                        $this->unpackCuint($buf, $tmp);
                        $length = $this->unpackCuint($buf, $tmp);
                        while (strlen($buf) < $length) {
                            $buf .= socket_read($sock, 1024, PHP_BINARY_READ);
                        }
                    }
                    break;
            }
            if ($config['s_block'])
                socket_set_nonblock($sock);
            socket_close($sock);
            return $buf;
        } else {
            return FALSE;
        }
    }

    public function unmarshal(&$rb, $struct)
    {
        $data = array();
        foreach ($struct as $key => $val) {
            if (is_array($val)) {
                if ($this->cycle) {
                    if ($this->cycle > 0) {
                        for ($i = 0; $i < $this->cycle; $i++) {
                            $data[$key][$i] = $this->unmarshal($rb, $val);
                            if (!$data[$key][$i]) return false;
                        }
                    }
                    $this->cycle = false;
                } else {
                    $data[$key] = $this->unmarshal($rb, $val);
                    if (!$data[$key]) return false;
                }
            } else {
                $tmp = 0;
                switch ($val) {
                    case 'int':
                        $un = unpack("N", substr($rb, 0, 4));
                        $rb = substr($rb, 4);
                        $data[$key] = $un[1];
                        break;
                    case 'int64':
                        $un = unpack("N", substr($rb, 0, 8));
                        $rb = substr($rb, 8);
                        $data[$key] = $un[1];
                        break;
                    case 'long':
                        $data[$key] = $this->unpackLong(substr($rb, 0, 8));
                        $rb = substr($rb, 8);
                        break;
                    case 'lint':
                        //$un = unpack("L", substr($rb,0,4));
                        $un = unpack("V", substr($rb, 0, 4));
                        $rb = substr($rb, 4);
                        $data[$key] = $un[1];
                        break;
                    case 'byte':
                        $un = unpack("C", substr($rb, 0, 1));
                        $rb = substr($rb, 1);
                        $data[$key] = $un[1];
                        break;
                    case 'cuint':
                        $cui = $this->unpackCuint($rb, $tmp);
                        $rb = substr($rb, $tmp);
                        if ($cui > 0) $this->cycle = $cui;
                        else $this->cycle = -1;
                        break;
                    case 'octets':
                        $data[$key] = $this->unpackOctet($rb, $tmp);
                        $rb = substr($rb, $tmp);
                        break;
                    case 'name':
                        $data[$key] = $this->unpackString($rb, $tmp);
                        $rb = substr($rb, $tmp);
                        break;
                    case 'short':
                        $un = unpack("n", substr($rb, 0, 2));
                        $rb = substr($rb, 2);
                        $data[$key] = $un[1];
                        break;
                    case 'lshort':
                        $un = unpack("v", substr($rb, 0, 2));
                        $rb = substr($rb, 2);
                        $data[$key] = $un[1];
                        break;
                    case 'float2':
                        $un = unpack("f", substr($rb, 0, 4));
                        $rb = substr($rb, 4);
                        $data[$key] = $un[1];
                        break;
                    case 'float':
                        $un = unpack("f", strrev(substr($rb, 0, 4)));
                        $rb = substr($rb, 4);
                        $data[$key] = $un[1];
                        break;
                }
                if ($val != 'cuint' and is_null($data[$key])) return false;
            }
        }
        return $data;
    }
    public function marshal($pack, $struct)
    {
        $this->cycle = false;
        $data = '';
        foreach ($struct as $key => $val) {
            if (substr($key, 0, 1) == "@") continue;
            if (is_array($val)) {
                if ($this->cycle) {
                    if ($this->cycle > 0) {
                        $count = $this->cycle;
                        for ($i = 0; $i < $count; $i++) {
                            $data .= $this->marshal($pack[$key][$i], $val);
                        }
                    }
                    $this->cycle = false;
                } else {
                    $data .= $this->marshal($pack[$key], $val);
                }
            } else {
                switch ($val) {
                    case 'int':
                        $data .= $this->packInt((int)$pack[$key]);
                        break;
                    case 'byte':
                        $data .= $this->packByte($pack[$key]);
                        break;
                    case 'cuint':
                        $arrkey = substr($key, 0, -5);
                        $cui = isset($pack[$arrkey]) ? count($pack[$arrkey]) : 0;
                        $this->cycle = ($cui > 0) ? $cui : -1;
                        $data .= $this->cuint($cui);
                        break;
                    case 'octets':
                        if ($pack[$key] === array()) $pack[$key] = '';
                        $data .= $this->packOctet($pack[$key]);
                        break;
                    case 'name':
                        if ($pack[$key] === array()) $pack[$key] = '';
                        $data .= $this->packString($pack[$key]);
                        break;
                    case 'short':
                        $data .= $this->packShort($pack[$key]);
                        break;
                    case 'float':
                        $data .= $this->packFloat($pack[$key]);
                        break;
                    case 'cat1':
                    case 'cat2':
                    case 'cat4':
                        $data .= $pack[$key];
                        break;
                }
            }
        }
        return $data;
    }
    public function MaxOnlineUserID($arr)
    {
        $max = $arr[0]['userid'];
        for ($i = 1; $i < count($arr); $i++) {
            if ($arr[$i]['userid'] > $max) {
                $max = $arr[$i]['userid'];
            }
        }
        return $max + 1;
    }
    public function getArrayValue($array = array(), $index = null)
    {
        return $array[$index];
    }
}
class API
{
    public $online;
    public $data = array();
    public $gamed;
    public $protocol;
    protected $config;
    public function __construct()
    {
        // Set some default values
        global $config;
        $this->config = $config;
        $this->gamed = new Gamed();
        $this->online = $this->serverOnline();
        $this->protocol = $this->config['protocolo' . $this->config['serverversion']];
    }
    /**
     * Returns the array of role data by structure
     * @params string $role
     * @return array
     */
    public function getRole($role)
    {
        if ($this->config['protocolo' . $this->config['serverversion']] == '136') {
            $user['base'] = $this->getRoleBase($role);
            $user['status'] = $this->getRoleStatus($role);
            $user['pocket'] = $this->getRoleInventory($role);
            //$user['pets'] = $this->getRolePetBadge($role);
            $user['equipment'] = $this->getRoleEquipment($role);
            $user['storehouse'] = $this->getRoleStoreHouse($role);
            $user['task'] = $this->getRoleTask($role);
        } else {
            $pack = pack("N*", -1, $role);
            $pack = $this->gamed->createHeader($this->protocol['code']['getRole'], $pack);
            $send = $this->gamed->SendToGamedBD($pack);
            $data = $this->gamed->deleteHeader($send);
            $user = $this->gamed->unmarshal($data, $this->protocol['role']);
            if (!is_array($user)) {
                $user['base'] = $this->getRoleBase($role);
                $user['status'] = $this->getRoleStatus($role);
                $user['pocket'] = $this->getRoleInventory($role);
                //$user['pets'] = $this->getRolePetBadge($role);
                $user['equipment'] = $this->getRoleEquipment($role);
                $user['storehouse'] = $this->getRoleStoreHouse($role);
                $user['task'] = $this->getRoleTask($role);
            }
        }
        return $user;
    }
    public function getRoleBase($role)
    {
        $pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader($this->protocol['code']['getRoleBase'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $user = $this->gamed->unmarshal($data, $this->protocol['role']['base']);
        return $user;
    }
    public function getRoleStatus($role)
    {
        $pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader($this->protocol['code']['getRoleStatus'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $user = $this->gamed->unmarshal($data, $this->protocol['role']['status']);
        return $user;
    }
    public function getRoleInventory($role)
    {
        $pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader($this->protocol['code']['getRoleInventory'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $user = $this->gamed->unmarshal($data, $this->protocol['role']['pocket']);
        return $user;
    }
    public function getRoleEquipment($role)
    {
        $pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader($this->protocol['code']['getRoleEquipment'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $user = $this->gamed->unmarshal($data, $this->protocol['role']['equipment']);
        return $user;
    }
    public function getRolePetBadge($role)
    {
        $pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader(3088, $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $user = $this->gamed->unmarshal($data, $this->protocol['role']['pocket']['petbadge']);
        return $user;
    }
    public function getRoleStorehouse($role)
    {
        $pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader($this->protocol['code']['getRoleStoreHouse'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $store = $this->gamed->unmarshal($data, $this->protocol['role']['storehouse']);
        return $store;
    }
    public function getRoleTask($role)
    {
        $pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader($this->protocol['code']['getRoleTask'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $user = $this->gamed->unmarshal($data, $this->protocol['role']['task']);
        return $user;
    }
    /**
     * Returns the array of role data by structure
     * @params string $role
     * @return array
     */
    public function getJdRole($role)
    {
        /*$pack = pack("N*", -1, $role);
        $pack = $this->gamed->createHeader(config('code.getRole'), $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $user = $this->gamed->unmarshal($data, config('role'));
        
        return $user;*/
    }
    /**
     * Returns the array of user roles by structure
     * @params string $user
     * @return array
     */
    public function getRoles($user)
    {
        $pack = pack("N*", -1, $user);
        $pack = $this->gamed->createHeader($this->protocol['code']['getUserRoles'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        $roles = $this->gamed->unmarshal($data, $this->protocol['user']['roles']);
        return $roles;
    }
    /**
     * Returns the array of user data by structure
     * @params int $id
     * @return array
     */
    public function getUser($id)
    {
        $pack = pack("N*", -1, $id, 1, 1);
        $data = $this->gamed->cuint($this->protocol['code']['getUser']) . $this->gamed->cuint(strlen($pack)) . $pack;
        $send = $this->gamed->SendToGamedBD($data);
        $strlarge = unpack("H", substr($send, 2, 1));
        if (substr($strlarge[1], 0, 1) == 8) {
            $tmp = 12;
        } else {
            $tmp = 11;
        }
        $send = substr($send, $tmp);
        $user = $this->gamed->unmarshal($send, $this->protocol['user']['info']);
        $user['login_ip'] = $this->gamed->getIp($this->gamed->reverseOctet(substr($user['login_record'], 8, 8)));
        $user['login_time'] = $this->gamed->getTime(substr($user['login_record'], 0, 8));
        return $user;
    }

    public function DBGetConsumeInfosArg($id)
    {

        $pack = pack('NCN', -1, 1, $id);
        $data = $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['getUserExtraInfo'], $pack));
        $retorno = $this->gamed->unmarshal($data, $this->protocol['getUserExtraInfo']);
        $ip[0] = $retorno['login_ip'] & 0xFF;
        $ip[1] = ($retorno['login_ip'] >> 8) & 0xFF;
        $ip[2] = ($retorno['login_ip'] >> 16) & 0xFF;
        $ip[3] = ($retorno['login_ip'] >> 24) & 0xFF;
        $retorno['login_ip'] = "{$ip[0]}.{$ip[1]}.{$ip[2]}.{$ip[3]}";
        return $retorno;
    }
    /**
     * Returns the array of user data by structure
     * @params string $user
     * @return array
     */
    public function getJdUser($id)
    {
        /*$pack = pack("N*", -1, $id);
        $data = $this->gamed->SendToGamedBD($this->gamed->createHeader(config('code.getUser'), $pack));
        $send = $this->gamed->SendToGamedBD($data);
        return $this->gamed->unmarshal($data, config('user.info'));*/
    }
    /**
     * Saves a data of character by structure
     * @params string $role
     * @params array $params
     * @return boolean
     */
    public function putRole($role, $params)
    {
        if (isset($params['equipment']['eqp']['id'])) {
            $tmp = $params['equipment']['eqp'];
            $params['equipment']['eqp'] = array();
            $params['equipment']['eqp'][] = $tmp;
        }
        if (isset($params['pocket']['inv']['id'])) {
            $tmp = $params['pocket']['inv'];
            $params['pocket']['inv'] = array();
            $params['pocket']['inv'][] = $tmp;
        }
        if (isset($params['storehouse']['store']['id'])) {
            $tmp = $params['storehouse']['store'];
            $params['storehouse']['store'] = array();
            $params['storehouse']['store'][] = $tmp;
        }
        if (isset($params['task']['task_inventory']['id'])) {
            $tmp = $params['task']['task_inventory'];
            $params['task']['task_inventory'] = array();
            $params['task']['task_inventory'][] = $tmp;
        }
        if (isset($params['storehouse']['dress']['id'])) {
            $tmp = $params['storehouse']['dress'];
            $params['storehouse']['dress'] = array();
            $params['storehouse']['dress'][] = $tmp;
        }
        if (isset($params['storehouse']['material']['id'])) {
            $tmp = $params['storehouse']['material'];
            $params['storehouse']['material'] = array();
            $params['storehouse']['material'][] = $tmp;
        }
        if ($this->config['protocolo' . $this->config['serverversion']] != '136') {
            $pack = pack("NNC*", -1, $role, 1) . $this->gamed->marshal($params, $this->protocol['role']);
            return $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['putRole'], $pack));
        } else {
            $pack = pack("NNC*", -1, $role) . $this->gamed->marshal($params["base"], $this->protocol['role']['base']);
            $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['putRoleBase'], $pack));
            $pack = pack("NNC*", -1, $role) . $this->gamed->marshal($params["status"], $this->protocol['role']['status']);
            $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['putRoleStatus'], $pack));
            $pack = pack("NNC*", -1, $role) . $this->gamed->marshal($params["pocket"], $this->protocol['role']['pocket']);
            $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['putRoleInventory'], $pack));
            $pack = pack("NNC*", -1, $role) . $this->gamed->marshal($params["equipment"], $this->protocol['role']['equipment']);
            $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['putRoleEquipment'], $pack));
            $pack = pack("NNC*", -1, $role) . $this->gamed->marshal($params["storehouse"], $this->protocol['role']['storehouse']);
            $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['putRoleStoreHouse'], $pack));
            $pack = pack("NNC*", -1, $role) . $this->gamed->marshal($params["task"], $this->protocol['role']['task']);
            return $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['putRoleTask'], $pack));
        }
    }
    /**
     * Saves a data of character by structure
     * @params string $role
     * @params array $params
     * @return boolean
     */
    public function putJdRole($role, $params)
    {
        /*$pack = pack("NNC*", -1, $role, 1).$this->gamed->marshal($params, config('role'));
        $this->gamed->SendToGamedBD($this->gamed->createHeader(config('code.putRole'), $pack));
        
        return true;*/
    }
    /**
     * Send mail to the game mail
     * @params string $receiver
     * @params string $title
     * @params string $context
     * @params array $item
     * @params string $money
     * @return boolean
     */
    public function renameRole($role, $oldname, $newname)
    {
        global $config;
        $pack = pack("N*", -1, $role) . $this->gamed->packString($oldname) . $this->gamed->packString($newname);

        return $this->gamed->SendToGamedBD($this->gamed->createHeader($this->config['code']['renameRole'], $pack));
    }
    public function sendGold($id, $quantidade)
    {
        echo "Chamando a função chat2player...";
        echo "Parâmetro \$role: $id";
        echo "Parâmetro \$Qtdade: $quantidade";
        $pack = pack('N*', $id, $quantidade);
        $data = $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['sendGold'], $pack));
        print_r($this->protocol['code']['sendGold']);
        return $data;
    }

    function chatInGame($mensagem)
    {
        global $config;

        $pack = pack("CCN", $config['chanel'], 0, 0) . $this->gamed->packString($mensagem) . $this->gamed->packOctet('');
        $this->gamed->SendToProvider($this->gamed->createHeader(120, $pack));
        return true;
    }

    function chatWhisper($char_id, $roleName, $mensagem, $chanel)
    {
        global $config;
        $pack = pack("CC", $chanel, 0);
        $pack .= $this->gamed->packString($config['gmNick']) . pack("N", $config['gmID']);
        $pack .= $this->gamed->packString($roleName) . pack("N", $char_id);
        $pack .= $this->gamed->packString($mensagem) . $this->gamed->packOctet('');
        $pack .= pack("N", 0);
        $this->gamed->SendToProvider($this->gamed->createHeader(96, $pack));
        return true;
    }


    public function sendMail($receiver, $title, $context, $item = array(), $money)
    {
        if ($item === array()) {
            $item = array(
                'id' => 0,
                'pos' => 0,
                'count' => 0,
                'max_count' => 0,
                'data' => '',
                'proctype' => 0,
                'expire_date' => 0,
                'guid1' => 0,
                'guid2' => 0,
                'mask' => 0
            );
        }

        $pack = pack("NNCN", 344, 1025, 3, $receiver) . $this->gamed->packString($title) . $this->gamed->packString($context);
        $pack .= $this->gamed->marshal($item, $this->protocol['role']['pocket']['inv']);
        $pack .= pack("N", $money);

        return $this->gamed->SendToDelivery($this->gamed->createHeader($this->protocol['code']['sendMail'], $pack));
    }
    /**
     * The ban of Account
     * @params string $role
     * @params miax $time
     * @params string $reason
     * @return boolean
     */
    public function forbidAcc($role, $time, $reason)
    {
        $pack = pack("N*", -1, 0, $role, $time) . $this->gamed->packString($reason);
        return $this->gamed->SendToDelivery($this->gamed->createHeader($this->protocol['code']['forbidAcc'], $pack));
    }
    /**
     * The ban of character
     * @params string $role
     * @params miax $time
     * @params string $reason
     * @return boolean
     */
    public function forbidRole($role, $time, $reason)
    {
        $pack = pack("N*", -1, 0, $role, $time) . $this->gamed->packString($reason);
        return $this->gamed->SendToDelivery($this->gamed->createHeader($this->protocol['code']['forbidRole'], $pack));
    }
    /**
     * The ban chat of account
     * @params string $role
     * @params miax $time
     * @params string $reason
     * @return boolean
     */
    public function muteAcc($role, $time, $reason)
    {
        $pack = pack("N*", -1, 0, $role, $time) . $this->gamed->packString($reason);
        return $this->gamed->SendToDelivery($this->gamed->createHeader($this->protocol['code']['muteAcc'], $pack));
    }
    /**
     * The ban chat of character
     * @params string $role
     * @params miax $time
     * @params string $reason
     * @return boolean
     */
    public function muteRole($role, $time, $reason)
    {
        $pack = pack("N*", -1, 0, $role, $time) . $this->gamed->packString($reason);
        return $this->gamed->SendToDelivery($this->gamed->createHeader($this->protocol['code']['muteRole'], $pack));
    }
    /**
     * Returns the ID of role
     * @params string $rolename
     * @return string
     */
    public function getRoleid($rolename)
    {
        $pack = pack("N", -1) . $this->gamed->packString($rolename) . pack("C", 1);
        $data = $this->gamed->deleteHeader($this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['getRoleid'], $pack)));
        $var = unpack("l", $data);
        if ($var[1] !== -1) {
            $var = unpack("N", $data);
        }
        return $var[1];
    }

    /**
     * Returns the array with online roles by structure
     * @return array
     */
    public function getOnlineList()
    {
        $online = array();
        if ($this->online) {
            $id = 0;
            $pack = pack('N*', -1, 1, $id) . $this->gamed->packString('1');
            $pack = $this->gamed->createHeader(352, $pack);
            $send = $this->gamed->SendToDelivery($pack);
            $data = $this->gamed->deleteHeader($send);
            $data = $this->gamed->unmarshal($data, $this->protocol['RoleList']);
            if (isset($data['users'])) {
                foreach ($data['users'] as $user) {
                    $online[] = $user;
                    //$id = $this->gamed->MaxOnlineUserID( $data['users'] );

                }
            }
        }
        return $online;
    }
    /**
     * Returns the array with friends by structure
     * @param string $role
     * @return array
     */
    public function getRoleFriends($role)
    {
        $tmp = 0;
        $pack = pack("N*", -1, $role);
        $data = $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['getRoleFriend'], $pack));
        $this->gamed->unpackCuint($data, $tmp);
        $this->gamed->unpackCuint($data, $tmp);
        $data = substr($data, $tmp + 5);
        return $this->gamed->unmarshal($data, $this->protocol['FriendList']);
    }
    public function addFaction($roleid, $name, $fid)
    {
        $pack = pack("N*", -1) . $this->gamed->packString($name) . pack("NN", $roleid, $fid);
        $pack = $this->gamed->createHeader($this->protocol['code']['AddFaction'], $pack);
        return $this->gamed->SendToGamedBD($pack);
    }
    public function delFaction($fid)
    {
        $pack = pack("N*", -1, $fid);
        $pack = $this->gamed->createHeader($this->protocol['code']['DelFaction'], $pack);
        return $this->gamed->SendToGamedBD($pack);
    }
    public function upgradeFaction($roleid, $fid, $level)
    {
        $pack = pack("N*", -1, $fid, $roleid, 0) . pack("C", $level);
        $pack = $this->gamed->createHeader($this->protocol['code']['FactionUpgrade'], $pack);
        return $this->gamed->SendToGamedBD($pack);
    }
    public function getFactionInfo($id)
    {
        $pack = pack("N*", -1, $id);
        $pack = $this->gamed->createHeader($this->protocol['code']['getFactionInfo'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        return $this->gamed->unmarshal($data, $this->protocol['FactionInfo']);
    }
    public function getFactionDetail($id)
    {
        $pack = pack("N*", -1, $id);
        $pack = $this->gamed->createHeader($this->protocol['code']['getFactionDetail'], $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        return $this->gamed->unmarshal($data, $this->protocol['FactionDetail']);
    }
    public function getFactionFortressDetail($id)
    {
        /*$pack = pack("N*", -1, $id);
        $pack = $this->gamed->createHeader(config('code.GFactionFortressDetail'), $pack);
        $send = $this->gamed->SendToGamedBD($pack);
        $data = $this->gamed->deleteHeader($send);
        return $this->gamed->unmarshal($data, config('FactionFortressDetail'));*/
    }
    public function getTerritories()
    {
        $pack = pack("N*", -1, 1);
        $data = $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['getTerritory'], $pack));
        $length = 0;
        $this->gamed->unpackCuint($data, $length);
        $this->gamed->unpackCuint($data, $length);
        $length += 6;
        $data = substr($data, $length);
        return $this->gamed->unmarshal($data, $this->protocol['GTerritoryDetail']);
    }
    public function getRaw($table, $handler = '', $key = '')
    {
        $pack = pack("N*", -1) . $this->gamed->packLongOctet($table) . $this->gamed->packOctet($handler) . $this->gamed->packOctet($key);
        $data = $this->gamed->deleteHeader($this->gamed->SendToGamedBD($this->gamed->createHeader(3055, $pack)));
        return $this->gamed->unmarshal($data, $this->protocol['RawRead']);
    }
    public function parseOctet($octet, $name)
    {
        $data = pack("H*", $octet);
        return $this->gamed->unmarshal($data, $this->protocol['octet'][$name]);
    }
    public function getUserFaction($id)
    {
        $tmp = 0;
        $pack = pack("N*", -1, 1, $id);
        $data = $this->gamed->SendToGamedBD($this->gamed->createHeader($this->protocol['code']['getUserFaction'], $pack));
        $this->gamed->unpackCuint($data, $tmp);
        $this->gamed->unpackCuint($data, $tmp);
        $data = substr($data, $tmp + 8);
        return $this->gamed->unmarshal($data, $this->protocol['getUserFaction']);
    }
    public function generateSkill($params = array())
    {
        $skills = substr($params['skills'], 8);
        $id = isset($params['id']) ? dechex($params['id']) : 1;
        $level = isset($params['level']) ? dechex($params['level']) : 1;
        $progress = isset($params['progress']) ? dechex($params['progress']) : 0;
        $skills .= $this->gamed->reverseOctet($this->gamed->hex2octet($id));
        $skills .= $this->gamed->reverseOctet($this->gamed->hex2octet($progress));
        $skills .= $this->gamed->reverseOctet($this->gamed->hex2octet($level));
        $count = dechex(strlen($skills) / 24);
        $skills = $this->gamed->reverseOctet($this->gamed->hex2octet($count)) . $skills;
        return $skills;
    }
    public function serverOnline()
    {
        return (@fsockopen('127.0.0.1', $this->config['ports']['gamedbd'], $errCode, $errStr, 1) ? TRUE : FALSE);
    }
}

function classes($classes)
{
    switch ($classes) {
        case 0:
            $tipo = 'Guerreiro';
            break;
        case 1:
            $tipo = 'Mago';
            break;
        case 2:
            $tipo = 'Espiritualista';
            break;
        case 3:
            $tipo = 'Feiticeira';
            break;
        case 4:
            $tipo = 'Bárbaro';
            break;
        case 5:
            $tipo = 'Mercenário';
            break;
        case 6:
            $tipo = 'Arqueiro';
            break;
        case 7:
            $tipo = 'Sacerdote';
            break;
        case 8:
            $tipo = 'Arcano';
            break;
        case 9:
            $tipo = 'Mistico';
            break;
        case 10:
            $tipo = 'Retalhador';
            break;
        case 11:
            $tipo = 'Tormentador';
            break;
        case 12:
            $tipo = 'Atiradora';
            break;
        case 13:
            $tipo = 'Paladino';
            break;
        default:
            $tipo = 'Desconhecido';
            break;
    }
    return $tipo;
}

// function cultivo($cultivo)
// {
//     switch (trim($cultivo)) {
//         case 0:
//             $cultivolvl  = 'Leal';
//             break;
//         case 1:
//             $cultivolvl  = 'Astuto';
//             break;
//         case 2:
//             $cultivolvl  = 'Harmonioso';
//             break;
//         case 3:
//             $cultivolvl  = 'L�cido';
//             break;
//         case 4:
//             $cultivolvl  = 'Enigm�tico';
//             break;
//         case 5:
//             $cultivolvl  = 'Amea�ador';
//             break;
//         case 6:
//             $cultivolvl  = 'Sinistro';
//             break;
//         case 7:
//             $cultivolvl  = 'Nirvana';
//             break;
//         case 8:
//             $cultivolvl  = 'Mahayana';
//             break;
//         case 20:
//             $cultivolvl = 'Nobre';
//             break;
//         case 21:
//             $cultivolvl = 'Iluminado';
//             break;
//         case 22:
//             $cultivolvl = 'God';
//             break;
//         case 30:
//             $cultivolvl = 'Diab�lico';
//             break;
//         case 31:
//             $cultivolvl = 'Infernal';
//             break;
//         case 32:
//             $cultivolvl = 'Evil';
//             break;
//         default:
//             $cultivolvl = 'Desconhecido';
//             break;
//     }
//     return $cultivolvl;
// }


function cores($text)
{
    $text = str_replace("{cor-1}",  '^ffffff', $text);
    $text = str_replace("{cor-2}",  '^808080', $text);
    $text = str_replace("{cor-3}",  '^33cc33', $text);
    $text = str_replace("{cor-4}",  '^ffff00', $text);
    $text = str_replace("{cor-5}",  '^0066ff', $text);
    $text = str_replace("{cor-6}",  '^ff00ff', $text);
    $text = str_replace("{cor-7}",  '^9933ff', $text);
    $text = str_replace("{cor-8}",  '^ff0000', $text);
    $text = str_replace("{cor-9}",  '^48D1CC', $text);
    $text = str_replace("{cor-10}",  '^1C1C1C', $text);
    return $text;
}

function clean($text)
{
    $text = str_replace("^ffffff",  '', $text);
    $text = str_replace("^808080",  '', $text);
    $text = str_replace("^33cc33",  '', $text);
    $text = str_replace("^ffff00",  '', $text);
    $text = str_replace("^0066ff",  '', $text);
    $text = str_replace("^ff00ff",  '', $text);
    $text = str_replace("^9933ff",  '', $text);
    $text = str_replace("^ff0000",  '', $text);
    $text = str_replace("^48D1CC",  '', $text);
    $text = str_replace("^1C1C1C",  '', $text);
    $text = str_replace("&",  '', $text);
    return $text;
}

function getEmoticon($liga)
{
    switch ($liga) {
        case 'Prata':
            return '<0><0:5>';
        case 'Ouro':
            return '<0><0:2>';
        case 'Diamante':
            return '<0><0:0>';
        case 'Mestre':
            return '<0><0:4>';
        default:
            return '<0><0:1>';
    }
}

function rankEmoticon($posicaoJogador)
{
    switch ($posicaoJogador) {
        case '1':
            return '<0><0:3>';
        case '2':
            return '<0><0:7>';
        case '3':
            return '<0><0:8>';
        default:
            return '<0><0:1>';
    }
}

function classesEmote($classes)
{
    switch ($classes) {
        case 0:
            $tipo = '<:guerreiro:1273401185570914344>';
            break;
        case 1:
            $tipo = '<:mago:1273401189299519488>';
            break;
        case 2:
            $tipo = '<:esp:1273401190800949320>';
            break;
        case 3:
            $tipo = '<:feiti:1273401197340004444>';
            break;
        case 4:
            $tipo = '<:barbaro:1273401198828851290>';
            break;
        case 5:
            $tipo = '<:merc:1273401200162771048>';
            break;
        case 6:
            $tipo = '<:arquer:1273401201442160806>';
            break;
        case 7:
            $tipo = '<:sacer:1273401203920863252>';
            break;
        case 8:
            $tipo = '<:arcano:1273401205371961404>';
            break;
        case 9:
            $tipo = '<:mistico:1273401207351672884>';
            break;
        case 10:
            $tipo = '<:retalha:1236820099826978846>';
            break;
        case 11:
            $tipo = '<:torment:1236820101236527104>';
            break;
        case 12:
            $tipo = '<:atira:1236820960946946058>';
            break;
        case 13:
            $tipo = '<:paladin:1236820105720238192>';
            break;
        default:
            $tipo = 'Desconhecido';
            break;
    }
    return $tipo;
}


function gravar_log($data)
{
    $logPath = "logs/sistema.txt";
    $mode = (!file_exists($logPath)) ? 'w' : 'a';
    $logfile = fopen($logPath, $mode);
    fwrite($logfile, "\r\n" . '' . date('d-m-y H:i:s') . ' | ' . $data);
    fclose($logfile);
}



$ArrCultivo = array(
    0 => "Leal",
    1 => "Astuto",
    2 => "Harmonioso",
    3 => "Lúcido",
    4 => "Enigmático",
    5 => "Ameaçador",
    6 => "Sinistro",
    7 => "Nirvana",
    8 => "Mahayana",
    20 => "Nobre",
    30 => "Diabólico",
    21 => "Iluminado",
    31 => "Infernal",
    32 => "Demoníaco",
    22 => "Imortal"
);

$cultString = "";

if (isset($user['status']['level2'])) {
    $cultInt = $user['status']['level2'];

    if (array_key_exists($cultInt, $ArrCultivo)) {
        $cultString = $ArrCultivo[$cultInt];
    }
}

$user['cult_string'] = $cultString;

return $user;
