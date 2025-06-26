<?php

class MessagePackUnpacker
{
    private $data;
    private $offset = 0;

    public function unpack($data)
    {
        $this->data = $data;
        $this->offset = 0;
        return $this->doUnpack();
    }

    private function doUnpack()
    {
        $type = ord($this->data[$this->offset]);
        $this->offset++;

        if ($type >= 0x00 && $type <= 0x7f) return $type;
        if ($type >= 0x80 && $type <= 0x8f) return $this->unpackMap($type & 0x0f);
        if ($type >= 0x90 && $type <= 0x9f) return $this->unpackArray($type & 0x0f);
        if ($type >= 0xa0 && $type <= 0xbf) return $this->unpackString($type & 0x1f);
        if ($type >= 0xe0 && $type <= 0xff) return $type - 256;

        switch ($type) {
            case 0xc0: return null;
            case 0xc2: return false;
            case 0xc3: return true;
            case 0xca: return $this->unpackFloat();
            case 0xcb: return $this->unpackDouble();
            case 0xcc: return $this->unpackUint(1);
            case 0xcd: return $this->unpackUint(2);
            case 0xce: return $this->unpackUint(4);
            case 0xcf: return $this->unpackUint(8);
            case 0xd0: return $this->unpackInt(1);
            case 0xd1: return $this->unpackInt(2);
            case 0xd2: return $this->unpackInt(4);
            case 0xd3: return $this->unpackInt(8);
            case 0xd9: return $this->unpackString($this->unpackUint(1));
            case 0xda: return $this->unpackString($this->unpackUint(2));
            case 0xdb: return $this->unpackString($this->unpackUint(4));
            case 0xdc: return $this->unpackArray($this->unpackUint(2));
            case 0xdd: return $this->unpackArray($this->unpackUint(4));
            case 0xde: return $this->unpackMap($this->unpackUint(2));
            case 0xdf: return $this->unpackMap($this->unpackUint(4));
        }

        throw new Exception("Unsupported MessagePack type: " . dechex($type));
    }

    private function unpackUint($bytes)
    {
        $data = substr($this->data, $this->offset, $bytes);
        $this->offset += $bytes;
        $format = ['C', 'n', 'N', 'J'];
        return unpack($format[$bytes / 2], $data)[1];
    }

    private function unpackInt($bytes)
    {
        $data = substr($this->data, $this->offset, $bytes);
        $this->offset += $bytes;
        $format = ['c', 's', 'l', 'q'];
        $unpacked = unpack($format[$bytes / 2], $data);
        return $unpacked[1];
    }
    
    private function unpackFloat()
    {
        $val = unpack('f', substr($this->data, $this->offset, 4));
        $this->offset += 4;
        return $val[1];
    }
    
    private function unpackDouble()
    {
        $val = unpack('d', substr($this->data, $this->offset, 8));
        $this->offset += 8;
        return $val[1];
    }

    private function unpackString($len)
    {
        $str = substr($this->data, $this->offset, $len);
        $this->offset += $len;
        return $str;
    }

    private function unpackArray($len)
    {
        $arr = [];
        for ($i = 0; $i < $len; $i++) {
            $arr[] = $this->doUnpack();
        }
        return $arr;
    }

    private function unpackMap($len)
    {
        $map = [];
        for ($i = 0; $i < $len; $i++) {
            $key = $this->doUnpack();
            $value = $this->doUnpack();
            $map[$key] = $value;
        }
        return $map;
    }
}