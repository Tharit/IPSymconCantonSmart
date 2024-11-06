<?php

define('INPUT_ATV', 0x01);
define('INPUT_SAT', 0x02);
define('INPUT_PS', 0x03);
define('INPUT_TV', 0x06);
define('INPUT_CD', 0x07);
define('INPUT_DVD', 0x0b);
define('INPUT_AUX', 0x0f);
define('INPUT_NET', 0x17);
define('INPUT_BT', 0x15);

require_once(__DIR__ . '/../libs/ModuleUtilities.php');

function dashDefault($value) {
    if($value) return $value;
    return '-';
}

class CantonSmartSpeakerDevice extends IPSModule
{
    use ModuleUtilities;
    
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'); // IO Client Socket

        // profiles
        $this->RegisterProfileIntegerEx('CantonSmart.Input', 'Database', '', '', [
            [INPUT_ATV, 'ATV',  '', -1],
            [INPUT_SAT, 'SAT',  '', -1],
            [INPUT_PS, 'PS',  '', -1],
            [INPUT_TV, 'TV',  '', -1],
            [INPUT_CD, 'CD',  '', -1],
            [INPUT_DVD, 'DVD',  '', -1],
            [INPUT_AUX, 'AUX',  '', -1],
            [INPUT_NET, 'NET',  '', -1],
            [INPUT_BT, 'BT',  '', -1]
        ]);

        // variables
        $this->RegisterVariableBoolean("Connected", "Connected");
        $this->RegisterVariableInteger("Volume", "Volume", "~Intensity.100");
        $this->EnableAction("Volume");
        $this->RegisterVariableInteger("Input", "Input", "CantonSmart.Input");
        $this->EnableAction("Input");
        $this->RegisterVariableBoolean("PowerState", "PowerState", "~Switch");
        $this->EnableAction("PowerState");

        // messages
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        // clear state on startup
        $this->ResetState();

        // if this is not the initial creation there might already be a parent
        if($this->UpdateConnection() && $this->HasActiveParent()) {
            $this->Connect();
        }
    }

    /**
     * Configuration changes
     */
    public function ApplyChanges()
    {
        $this->SendDebug('Apply changes', 'Updating config', 0);

        $parentID = $this->GetConnectionID();

        parent::ApplyChanges();

        if (!IPS_GetProperty($parentID, 'Open')) {
            IPS_SetProperty($parentID, 'Open', true);
            @IPS_ApplyChanges($parentID);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
            case FM_CONNECT:
                $this->SendDebug('STARTED / CONNECT', 'resetting connection', 0);
                // if new parent and it is already active: connect immediately
                if($this->UpdateConnection() && $this->HasActiveParent()) {
                    $this->ApplyChanges();
                }
                break;
            case FM_DISCONNECT:
                $this->ResetState();
                $this->UpdateConnection();
                break;
            case IM_CHANGESTATUS:
                $skip = $this->MUGetBuffer('SkipData');

                // reset state
                $this->ResetState();

                $this->SendDebug('CHANGESTATUS', json_encode($Data), 0);

                // if parent became active: connect
                if ($Data[0] === IS_ACTIVE) {
                    $this->SetValue("Connected", true);
                    $this->Connect();
                } else {
                    $this->SetValue("Connected", false);
                }
                break;
            default:
                break;
        }
    }

    public function Connect() {
        // request input
        // request volume
        // request power state
        $data = $this->MakePacket(0x03, 0x02) . $this->MakePacket(0x0c, 0x02) . $this->MakePacket(0x06, 0x02);
        $this->SendDebug('Sending Data', bin2hex($data), 0);
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    private function MakePacket($property, $type, $value = "") {
        if($value) {
            $length = strlen($value);
        } else {
            $value = '';
            $length = 0;
        }
        $length = pack('n', $length);
        $property = chr($property);
        $type = chr($type);
        return "\xff\xaa\x00$property$type$length$value";
    }

    public function ReceiveDataDevice($data) {
        while(strlen($data) > 0) {
            // find start of packet
            while(strlen($data) >= 2) {
                if($data[0] != '{' && !(ord($data[0]) == 0xff && ord($data[1]) == 0xaa)) {
                    $data = substr($data, 1);
                } else break;
            }

            // JSON
            if($data[0] == '{') {
                $res = $this->parseJSON($data);
                if($res) {
                    $json = $res['json'];
                    $this->SendDebug('Processing JSON Packet', json_encode($json), 0);
                    if($json['Title'] == 'DeviceStatusUpdate') {
                        $json = $json['CONTENTS'];
                        
                        if($json['PlayStatus'] === 'PLAY') {
                            // validate input
                            $input = $this->GetValue('Input');
                            if(!($input == INPUT_BT || $input == INPUT_NET)) {
                                $this->SendDebug('Validating Input', 'Stream is playing, but not on streaming input.. validating', 0);
                                $data = $this->MakePacket(0x03, 0x02);
                                $this->SendDebug('Sending Data', bin2hex($data), 0);
                                CSCK_SendText($this->GetConnectionID(), $data);
                            }
                        }
                    }
                    $data = substr($data, $res['length']);
                }
            // binary
            } else if(strlen($data) >= 7 && ord($data[0]) == 0xff && ord($data[1]) == 0xaa) {
                // ff   aa   00   03   01   00   03
                $len = unpack('n', $data, 5)[1];
                $property = unpack('n', $data, 2)[1];
                $type = ord($data[4]);
                if(strlen($data) >= 7 + $len) {
                    $this->SendDebug('Processing Packet', bin2hex(substr($data, 0, 7 + $len)), 0);

                    // power
                    if($property == 0x06 && $type == 0x01) {
                        $this->SetValue('PowerState', ord($data[7]));
                    // input
                    } else if($property == 0x03 && $type == 0x01) {
                        $value = ord($data[7]);
                        $this->SetValue('Input', $value);
                    // volume
                    } else if($property = 0x0c && $type == 0x01) {
                        if($len == 2) {
                            $this->SetValue('Volume', ceil((ord($data[7]) / 70) * 100));
                        }
                    }
                    $data = substr($data, 7 + $len);
                }
            } else {
                break;
            }
        }

        return $data;
    }

    public function ReceiveData($data) {
        if($this->MUGetBuffer('SkipData')) {
            $this->SendDebug('Receive Data', 'Waiting to fix mode, skipping packet', 0);
            return;
        }

        // unpack & decode data
        $data = json_decode($data);
        $data = utf8_decode($data->Buffer);

        $data = $this->MUGetBuffer('Data') . $data;

        $data = $this->ReceiveDataDevice($data);
        
        $this->MUSetBuffer('Data', $data);
    }

    public function RequestAction($ident, $value)
    {
        if($ident === 'Input') {
            $forceDevice = true;
            switch($value) {
                // ff   aa   00   03   01   00   03   01   10   01 => ATV
                case INPUT_ATV: $input = "\x01\x10\x01"; break;
                // ff   aa   00   03   01   00   03   02   04   01 => SAT
                case INPUT_SAT: $input = "\x02\x04\x01"; break;
                // ff   aa   00   03   01   00   03   03   0e   01 => PS
                case INPUT_PS: $input = "\x03\x0e\x01"; break;
                // ff   aa   00   03   01   00   03   06   02   01 => TV
                case INPUT_TV: $input = "\x06\x02\x01"; break;
                // ff   aa   00   03   01   00   03   07   06   01 => CD
                case INPUT_CD: $input = "\x07\x06\x01"; break;
                // ff   aa   00   03   01   00   03   0b   06   01 => DVD
                case INPUT_DVD: $input = "\x0b\x06\x01"; break;
                // ff   aa   00   03   01   00   03   0f   12   01 => AUX
                case INPUT_AUX: $input = "\x0f\x12\x01"; break;
                // ff   aa   00   03   01   00   03   17   13   01 => NET
                case INPUT_NET: $input = "\x17\x13\x01"; break;
                // ff   aa   00   03   01   00   03   15   14   01 => BT
                case INPUT_BT: $input = "\x15\x14\x01"; break;
                default: return;
            }
            $data = $this->MakePacket(0x03, 0x01, $input);
        } else if($ident === 'Volume') {
            if($value < 0 || $value > 100) return;
            $data = $this->MakePacket(0x0c, 0x01, chr(round(($value/100)*70)));
        } else if($ident === 'PowerState') {
            $data = $this->MakePacket(0x06, 0x01, $value ? "\x01" : "\x00");
        } else return;

        $this->SendDebug('Sending Data', bin2hex($data), 0);
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    //------------------------------------------------------------------------------------
    // external methods
    //------------------------------------------------------------------------------------
    
    //------------------------------------------------------------------------------------
    // module internals
    //------------------------------------------------------------------------------------

    private function ResetState() {
        $this->MUSetBuffer('Data', '');
    }

    private function parseJSON($string) {
        $start = 0;
        $offset = 0;
        $json = null;
    
        do {
            $pos = strpos($string, '}', $offset);
            if($pos === false) break;
            $json = @json_decode(substr($string, $start, $pos + 1), true);
            $offset = $pos + 1;
        } while(!$json && $offset <= strlen($string));
    
        if(!$json) return null;

        return [
            "json" => $json,
            "length" => $offset
        ];
    }
}