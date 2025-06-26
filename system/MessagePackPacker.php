<?php

class MessagePackPacker
{
    public function pack($data)
    {
        if (is_null($data)) {
            return "\xc0";
        }
        if (is_bool($data)) {
            return $data ? "\xc3" : "\xc2";
        }
        if (is_int($data)) {
            return $this->packInt($data);
        }
        if (is_float($data)) {
            return "\xcb" . pack('d', $data);
        }
        if (is_string($data)) {
            return $this->packString($data);
        }
        if (is_array($data)) {
            if (array_keys($data) === range(0, count($data) - 1)) {
                return $this->packArray($data);
            } else {
                return $this->packMap($data);
            }
        }
        throw new Exception("Unsupported data type for MessagePack");
    }

    private function packInt($int)
    {
        if ($int >= 0) {
            if ($int < 128) return pack('C', $int);
            if ($int < 256) return "\xcc" . pack('C', $int);
            if ($int < 65536) return "\xcd" . pack('n', $int);
            if ($int < 4294967296) return "\xce" . pack('N', $int);
            return "\xcf" . pack('J', $int);
        } else {
            if ($int >= -32) return pack('c', $int);
            if ($int >= -128) return "\xd0" . pack('c', $int);
            if ($int >= -32768) return "\xd1" . pack('s', $int);
            if ($int >= -2147483648) return "\xd2" . pack('l', $int);
            return "\xd3" . pack('q', $int);
        }
    }

    private function packString($str)
    {
        $len = strlen($str);
        if ($len < 32) return pack('C', 0xa0 | $len) . $str;
        if ($len < 256) return "\xd9" . pack('C', $len) . $str;
        if ($len < 65536) return "\xda" . pack('n', $len) . $str;
        return "\xdb" . pack('N', $len) . $str;
    }

    private function packArray($arr)
    {
        $len = count($arr);
        $bin = '';
        if ($len < 16) $bin .= pack('C', 0x90 | $len);
        elseif ($len < 65536) $bin .= "\xdc" . pack('n', $len);
        else $bin .= "\xdd" . pack('N', $len);

        foreach ($arr as $item) {
            $bin .= $this->pack($item);
        }
        return $bin;
    }

    private function packMap($map)
    {
        $len = count($map);
        $bin = '';
        if ($len < 16) $bin .= pack('C', 0x80 | $len);
        elseif ($len < 65536) $bin .= "\xde" . pack('n', $len);
        else $bin .= "\xdf" . pack('N', $len);

        foreach ($map as $key => $value) {
            $bin .= $this->pack($key);
            $bin .= $this->pack($value);
        }
        return $bin;
    }
}