<?php

require('./config.php');

//API

function chatInGame($text)
{
    global $config;
    $pack = pack("CCN", $config['chanel'], 0, 0) . packString($text) . packOctet('');
    SendToProvider(createHeader(120, $pack));
    return true;
}

function packString($data)
{
    $data = iconv("UTF-8", "UTF-16LE//TRANSLIT//IGNORE", $data);
    return cuint(strlen($data)) . $data;
}

function cuint($data)
{
    if ($data < 64)
        return strrev(pack("C", $data));
    else if ($data < 16384)
        return strrev(pack("S", ($data | 0x8000)));
    else if ($data < 536870912)
        return strrev(pack("I", ($data | 0xC0000000)));
    return strrev(pack("c", -32) . pack("i", $data));
}

function packOctet($data)
{
    $data = pack("H*", (string) $data);
    return cuint(strlen($data)) . $data;
}

function SendToProvider($data)
{
    global $config;
    return SendToSocket($data, $config['ports']['provider']);
}

function SendToSocket($data, $port, $RecvAfterSend = false, $buf = null)
{
    global $config;
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($sock, $config['ip'], $port);
    if ($RecvAfterSend)
        socket_recv($sock, $tmp, 8192, 0);
    socket_send($sock, $data, strlen($data), 0);
    switch (3) {
        case 1:
            socket_recv($sock, $buf, 65536, 0);
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
                unpackCuint($buf, $tmp);
                $length = unpackCuint($buf, $tmp);
                while (strlen($buf) < $length) {
                    $buf .= socket_read($sock, 1024, PHP_BINARY_READ);
                }
            }
            break;
    }
    socket_close($sock);
    return $buf;
}

function unpackCuint($data, &$p)
{
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
}

function createHeader($opcode, $data)
{
    return cuint($opcode) . cuint(strlen($data)) . $data;
}

function SendToGamedBD($data)
{
    global $config;
    return SendToSocket($data, $config['ports']['gamedbd']);
}

function deleteHeader($data)
{
    $length = 0;
    unpackCuint($data, $length);
    unpackCuint($data, $length);
    $length += 8;
    $data = substr($data, $length);
    return $data;
}

function unpackLong($data)
{
    $set = unpack('N2', $data);
    return $set[1] << 32 | $set[2];
}

function unpackOctet($data, &$tmp)
{
    $p = 0;
    $size = unpackCuint($data, $p);
    $octet = bin2hex(substr($data, $p, $size));
    $tmp = $tmp + $p + $size;
    return $octet;
}

function unpackString($data, &$tmp)
{
    $size = (hexdec(bin2hex(substr($data, $tmp, 1))) >= 128) ? 2 : 1;
    $octetlen = (hexdec(bin2hex(substr($data, $tmp, $size))) >= 128) ? hexdec(bin2hex(substr($data, $tmp, $size))) - 32768 : hexdec(bin2hex(substr($data, $tmp, $size)));
    $pp = $tmp;
    $tmp += $size + $octetlen;
    return mb_convert_encoding(substr($data, $pp + $size, $octetlen), "UTF-8", "UTF-16LE");
}

function unmarshal(&$rb, $struct)
{
    $cycle = false;
    $data = array();
    foreach ($struct as $key => $val) {
        if (is_array($val)) {
            if ($cycle) {
                if ($cycle > 0) {
                    for ($i = 0; $i < $cycle; $i++) {
                        $data[$key][$i] = unmarshal($rb, $val);
                        if (!$data[$key][$i])
                            return false;
                    }
                }
                $cycle = false;
            } else {
                $data[$key] = unmarshal($rb, $val);
                if (!$data[$key])
                    return false;
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
                    $data[$key] = unpackLong(substr($rb, 0, 8));
                    $rb = substr($rb, 8);
                    break;
                case 'lint':
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
                    $cui = unpackCuint($rb, $tmp);
                    $rb = substr($rb, $tmp);
                    if ($cui > 0)
                        $cycle = $cui;
                    else
                        $cycle = -1;
                    break;
                case 'octets':
                    $data[$key] = unpackOctet($rb, $tmp);
                    $rb = substr($rb, $tmp);
                    break;
                case 'name':
                    $data[$key] = unpackString($rb, $tmp);
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
            if ($val != 'cuint' and is_null($data[$key]))
                return false;
        }
    }
    return $data;
}

function getRoleBase($role)
{
    $pack = pack("N*", -1, $role);
    $pack = createHeader(3013, $pack);
    $send = SendToGamedBD($pack);
    $data = deleteHeader($send);
    $user = unmarshal(
        $data,
        array(
            'version' => 'byte',
            'id' => 'int',
            'name' => 'name',
            'race' => 'int',
            'cls' => 'int',
            'gender' => 'byte',
            'custom_data' => 'octets',
            'config_data' => 'octets',
            'custom_stamp' => 'int',
            'status' => 'byte',
            'delete_time' => 'int',
            'create_time' => 'int',
            'lastlogin_time' => 'int',
            'forbidcount' => 'cuint',
            'forbid' => array(
                'type' => 'byte',
                'time' => 'int',
                'createtime' => 'int',
                'reason' => 'name',
            ),
            'help_states' => 'octets',
            'spouse' => 'int',
            'userid' => 'int',
            'cross_data' => 'octets',
            'reserved2' => 'byte',
            'reserved3' => 'byte',
            'reserved4' => 'byte',
        ),
    );


    $classeStrings = array(
        0        =>        "Guerreiro",
        1        =>        "Mago",
        2        =>        "Espiritualista",
        3        =>        "Feiticeira",
        4        =>        "Bárbaro",
        5        =>        "Mercenário",
        6        =>        "Arqueiro",
        7        =>        "Sacerdote",
        8        =>        "Arcano",
        9        =>        "Místico",
        10      =>      "Retalhador",
        11      =>      "Tormentador",
        12      =>      "Atirador",
        13      =>      "Paladino",
    );

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

    $clsInt = $user['cls'];



    if (array_key_exists($clsInt, $classeStrings)) {

        $clsString = $classeStrings[$clsInt];



        $user['cls_string'] = $clsString;
    } else {
    }

    return $user;
}
