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
        $this->RegisterVariableString("Source", "Source");
        $this->RegisterVariableString("Application", "Application");
        $this->RegisterVariableString("State", "State");
        $this->RegisterVariableString("Artist", "Artist");
        $this->RegisterVariableString("Album", "Album");
        $this->RegisterVariableString("Title", "Title");
        $this->RegisterVariableInteger("Position", "Position");
        $this->RegisterVariableInteger("Duration", "Duration");
        $this->RegisterVariableString("Cover", "Cover");
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

        $this->RegisterTimer('Reconfigure', 0, 'CantonSmart_Reconfigure($_IPS[\'TARGET\']);');
        $this->RegisterTimer('CancelSwitch', 0, 'CantonSmart_Reconfigure($_IPS[\'TARGET\']);');

        // if this is not the initial creation there might already be a parent
        if($this->UpdateConnection() && $this->HasActiveParent()) {
            $this->SendDebug('Module Create', 'Already connected', 0);
            $parentID = $this->GetConnectionID();
            $port = IPS_GetProperty($parentID, 'Port');
            $this->UpdateMode($port == 50006 ? 0 : 1);
            $this->Connect();
        } else {
            $this->UpdateMode(0);
        }
    }

    private function GetMode() {
        return $this->MUGetBuffer('mode');
    }

    public function CancelSwitch() {
        $this->SendDebug('CancelSwitch', 'Switching mode failed...', 0);
        $this->SetTimerInterval('CancelSwitch', 0);

        $this->SetValue("Connected", false);
    }
    
    public function Reconfigure() {
        $this->SendDebug('Reconfigure', 'Reconfiguring socket...', 0);
        $this->SetTimerInterval('Reconfigure', 0);

        $parentID = $this->GetConnectionID();
        $port = IPS_GetProperty($parentID, 'Port');

        $targetPort = $this->GetMode() == 0 ? 50006 : 7777;
        if($port == $targetPort) return false;

        $open = IPS_GetProperty($parentID, 'Open');

        if($open) {
            IPS_SetProperty($parentID, 'Open', false);
            IPS_ApplyChanges($parentID);
        }
        
        IPS_SetProperty($parentID, 'Port', $targetPort);
        if($open) {
            IPS_SetProperty($parentID, 'Open', true);
        }
        IPS_ApplyChanges($parentID);
    }
    
    /**
     * change mode
     */
    private function ValidateMode() {
        $mode = $this->GetMode();

        $parentID = $this->GetConnectionID();
        $port = IPS_GetProperty($parentID, 'Port');

        if(($mode == 0 && $port != 50006) || 
        ($mode == 1 && $port != 7777)) {
            $interval = $this->GetTimerInterval('Reconfigure');
            if($interval !== 1000) {
                $this-MUSetBuffer('SkipData', true);
                $this->SetTimerInterval('Reconfigure', 1000);
                $this->SendDebug('Mode change', 'Fixing mode...', 0);
                return false;
            }
        }
        return true;
    }

    /**
     * change mode
     */
    private function UpdateMode($newMode) {
        $mode = $this->MUGetBuffer('mode');
        $this->MUSetBuffer('mode', $newMode);

        if($mode == '') {
            $this->SendDebug('Mode init', 'Initializing mode to ' . $newMode, 0);
        } else {
            $this->SendDebug('Mode change', 'Changing mode from ' . $mode . ' to ' . $newMode, 0);
            $this->MUSetBuffer('SkipData', true);
        }

        $this->SetTimerInterval('Reconfigure', 1000);

        return true;
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

                if(!$this->ValidateMode()) {
                    $this->SetValue("Connected", false);
                    return;
                }

                // if parent became active: connect
                if ($Data[0] === IS_ACTIVE) {
                    $this->SetTimerInterval("CancelSwitch", 0);
                    $this->SetValue("Connected", true);
                    $this->Connect();
                } else if($skip) {
                    $this->SetTimerInterval("CancelSwitch", 1000);
                } else {
                    $this->SetValue("Connected", false);
                }
                break;
            default:
                break;
        }
    }

    public function Connect() {
        $mode = $this->GetMode();
        if($mode == 0) {
            // request input
            // request volume
            // request power state
            $data = $this->MakePacket(0x03, 0x02) . $this->MakePacket(0x0c, 0x02) . $this->MakePacket(0x06, 0x02);
            $this->SendDebug('Sending Data', bin2hex($data), 0);
            CSCK_SendText($this->GetConnectionID(), $data);
        } else {
            $buffer = "\x00\x00\x02\x03\x00\x00\x00\x00\x0d\x0010.0.0.11";
            CSCK_SendText($this->GetConnectionID(), $buffer);
        }
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

    private function UpdateMetaData($json) {
        $state = 'stop';
        if($json['PlayState'] == 0) $state = 'play';
        if($json['PlayState'] == 2) $state = 'pause';
        $this->SetValue('State', $state);
        
        $app = $source = 'N/A';
        if($json['Current Source'] == 1) {
            $app = $source = 'Airplay';
        } else if($json['Current Source'] == 4) {
            $app = $source = 'Spotify';
        } else if($json['Current Source'] == 24) {
            $source = 'Google Cast';
            $app = $json['CastContentApp'];
        }
        $this->SetValue('Source', $source);
        $this->SetValue('Application', $app);
        $this->SetValue('Position', 0);
        $this->SetValue('Album', dashDefault($json['Album']));
        $this->SetValue('Artist', dashDefault($json['Artist']));
        $this->SetValue('Title', dashDefault($json['TrackName']));

        $cover = $json['CoverArtUrl'];
        if($cover === 'coverart.jpg') {
            $parentID = $this->GetConnectionID();
            $cover = 'http://'. IPS_GetProperty($parentID, 'Host') . '/coverart.jpg?' . time();
        }

        $this->SetValue('Cover', $cover);
        $this->SetValue('Duration', ceil($json['TotalTime'] / 1000));
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
                        /**
                            nothing to do here.. we handle the streaming metadata on the other connection
                            streaming state sometimes "lags behind" anyway.. e.g. it might be "playing" still after switching to another input
                         */
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
                        if($this->GetMode() == 0 && ($value == INPUT_NET || $value == INPUT_BT)) {
                            $this->UpdateMode(1);
                            return '';
                        }
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

    public function ReceiveDataStream($data) {
        while(strlen($data) > 0) {
            // binary
            if(strlen($data) >= 10) {
                $type = ord($data[2]);
                $cmd = unpack('n', $data, 3)[1];
                $len = unpack('n', $data, 8)[1];

                $this->SendDebug('Received CMD', $cmd . "|" . $type, 0);

                // after login or on device status notification
                if(($cmd == 3 && $type == 2) || ($cmd == 112 && $type == 2)) {
                    $data2 = "\x00\x00\x01\x70\x00\x00\x00\x00\x00\x00";
                    CSCK_SendText($this->GetConnectionID(), $data2);
                // device status data
                } else if(($cmd == 42 && $type == 2) || ($cmd == 45 && $type === 2)) {
                    $data2 = substr($data, 10, $len);
                    $json = @json_decode($data2, true);
                    if($json && $json['Title'] == 'PlayView') {
                        $json = $json['Window CONTENTS'];

                        $this->SendDebug('Processing JSON Value', $data2, 0);
                        
                        $this->UpdateMetaData($json);
                        
                        // switch from BT input to NET input...
                        if($this->GetValue("Input") !== INPUT_NET) {
                            $this->SetValue("Input", INPUT_NET);
                        }
                    }
                // playback status
                } else if($cmd == 51 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    if(ord($data2) == 0x30) {
                        $this->SetValue('State', 'play');
                    } else if(ord($data2) == 0x31) {
                        $this->SetValue('State', 'stop');
                        $this->ClearMetadata();
                        if($this->GetValue("Input") === INPUT_BT) {
                            if($this->ValidateState()) {
                                return '';
                            }
                        }
                    } else if(ord($data2) == 0x32) {
                        $this->SetValue('State', 'pause');
                    }
                // playback position
                } else if($cmd == 49 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    $this->SetValue('Position', floor($data2 / 1000));
                // volume
                } else if($cmd == 64 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    $this->SetValue('Volume', $data2);
                // input
                } else if($cmd == 70 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    if(($len > 0 && strpos($data2, 'SPEAKER_INACTIVE') === 0) ||
                        ($len == 0 && $this->GetValue('State') == 'stop')
                     ) {
                        if($this->ValidateState()) {
                            return '';
                        }
                    }
                }

                $data = substr($data, 10 + $len);
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

        $mode = $this->GetMode();
        if($mode == 0) {
            $data = $this->ReceiveDataDevice($data);
        } else {
            $data = $this->ReceiveDataStream($data);
        }

        $this->MUSetBuffer('Data', $data);
    }

    public function RequestAction($ident, $value)
    {
        $mode = $this->GetMode();
        $forceDevice = false;

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

            // when switching to another input directly the InputSource is not changed to "NONE".. 
            if($mode == 1 && !($value == INPUT_NET || $value == INPUT_BT)) {
                $this->UpdateMode(0);
            }
        } else if($ident === 'Volume') {
            if($value < 0 || $value > 100) return;
            if($mode == 0) {
                $data = $this->MakePacket(0x0c, 0x01, chr(round(($value/100)*70)));
            } else {
                $data = "\x00\x00\x02\x40\x00\x00\x0\x00\x02\x00$value";
            }
        } else if($ident === 'PowerState') {
            $forceDevice = true;
            $data = $this->MakePacket(0x06, 0x01, $value ? "\x01" : "\x00");
        } else return;

        if($forceDevice && $mode != 0) {
            $this->SendDebug('Sending Extra Data', bin2hex($data), 0);
        
            $parentID = $this->GetConnectionID();
            $host = IPS_GetProperty($parentID, 'Host');
            $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_connect($sock, $host, 50006);
            socket_send($sock, $data, strlen($data), 0);
            socket_close($sock);
        } else {
            $this->SendDebug('Sending Data', bin2hex($data), 0);
            CSCK_SendText($this->GetConnectionID(), $data);
        }
        
    }

    private function ValidateState() {
        $this->SendDebug('Validating input', 'Checking...', 0);
        
        $input = false;
        $cnt = 0;
        while($cnt++ < 3) {
            $input = $this->FetchState();
            if($input !== false) break;
            IPS_Sleep(500);
        }

        if($input != false) {
            if($this->GetMode() === 1) {
                if(!($input == INPUT_NET || $input == INPUT_BT)) {
                    $this->UpdateMode(0);
                    return true;
                }
            } else {
                if($input == INPUT_NET || $input == INPUT_BT) {
                    $this->UpdateMode(1);
                    return true;
                }
            }
            if($input != $this->GetValue("Input")) {
                $this->SetValue("Input", $input);
            }
        }
        return false;
    }

    private function FetchState() {
        // check input is still correct
        $parentID = $this->GetConnectionID();
        $host = IPS_GetProperty($parentID, 'Host');
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 15, "usec" => 0));
        $res = socket_connect($sock, $host, 50006);
        if(!$res) {
            $this->SendDebug('Fetch input', 'Failed to connect', 0);
            return false;
        }

        IPS_Sleep(100);
        $data = $this->MakePacket(0x03, 0x02) . $this->MakePacket(0x06, 0x02);
        $res = socket_send($sock, $data, strlen($data), 0);
        if($res != strlen($data)) {
            $this->SendDebug('Fetch input', 'Failed to send (' . $res . ')', 0);
            return false;
        }
        
        $input = false;
        $power = false;

        $cnt = 0;
        $buffer = '';
        while($cnt < 5) {
            $bytes = socket_recv($sock, $frag, 1024, 0);
            if(!$bytes) {
                $this->SendDebug('Fetch input debug', 'Received nothing, waiting...', 0);
                IPS_Sleep(100);
                $cnt++;
                continue;
            }
            $buffer .= $frag;

            $this->SendDebug('Fetch input debug', bin2hex($buffer), 0);
        
            while(strlen($buffer) > 0 && $buffer[0] == '{') {
                $res = $this->parseJSON($buffer);
                if(!$res) {
                    $this->SendDebug('Fetch input debug', 'Detected incomplete json, waiting...', 0);
                    continue 2;
                }
                $this->SendDebug('Fetch input debug', 'Detected json, skipping...', 0);
                $length = $res['length'];
                if($buffer[$length] === "\n") $length++;
                $buffer = substr($buffer, $length);

                $this->SendDebug('Fetch input debug', bin2hex($buffer), 0);
            }

            while(strlen($buffer) >= 7) {
                $len = unpack('n', $buffer, 5)[1];
                $property = unpack('n', $buffer, 2)[1];
                $type = ord($buffer[4]);
                if(strlen($buffer) >= 7 + $len) {
                    if($property == 0x03) {
                        $input = ord($buffer[7]);
                    }
                    if($property == 0x06) {
                        $power = ord($buffer[7]);
                    }
                    if($input !== false && $power !== false) break 2;
                    
                    $buffer = substr($buffer, 7 + $len);
                }

                $this->SendDebug('Fetch input debug', bin2hex($buffer), 0);
            }
        }

        socket_close($sock);

        if($input !== false && $power !== false) {
            return $input;
        } else {
            $this->SendDebug('Fetch input', 'Failed to receive response', 0);
        }
        
        return false;
    }

    //------------------------------------------------------------------------------------
    // external methods
    //------------------------------------------------------------------------------------
    public function SetVolume(int $volume) {
        $this->RequestAction('Volume', $volume);
    }

    public function Play() {
        $mode = $this->GetMode();
        if($mode != 1) return;
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x04\x00PLAY";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Pause() {
        $mode = $this->GetMode();
        if($mode != 1) return;
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x05\x00PAUSE";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Stop() {
        $mode = $this->GetMode();
        if($mode != 1) return;
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x04\x00STOP";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Next() {
        $mode = $this->GetMode();
        if($mode != 1) return;
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x04\x00NEXT";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Prev() {
        $mode = $this->GetMode();
        if($mode != 1) return;
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x04\x00PREV";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    //------------------------------------------------------------------------------------
    // module internals
    //------------------------------------------------------------------------------------
    private function ClearMetadata() {
        $this->SetValue("Artist", '-');
        $this->SetValue("Album", '-');
        $this->SetValue("Title", '-');
        $this->SetValue("Cover", "");
        $this->SetValue("Source", "-");
        $this->SetValue("Application", "-");
        $this->SetValue("Duration", 0);
        $this->SetValue("Position", 0);
        $this->SetValue('State', 'stop');
    }

    private function ResetState() {
        $this->MUSetBuffer('Data', '');
        $this->MUSetBuffer('SkipData', false);

        $this->ClearMetadata();
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