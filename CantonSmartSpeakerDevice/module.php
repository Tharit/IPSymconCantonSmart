<?php

/*

@TODO: change input to int variable / predefined profile?
@TODO: open second socket connection to streaming service on 7777

*/
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
        $this->RegisterVariableString("Input", "Input");
        $this->EnableAction("Input");
        $this->RegisterVariableBoolean("PowerState", "PowerState");
        $this->EnableAction("PowerState");

        // messages
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        // clear state on startup
        $this->ResetState();

        $this->RegisterTimer('Reconfigure', 0, 'CantonSmart_Reconfigure($_IPS[\'TARGET\']);');

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
                // reset state
                $this->ResetState();

                $this->SendDebug('CHANGESTATUS', json_encode($Data), 0);

                if(!$this->ValidateMode()) {
                    $this->SetValue("Connected", false);
                    return;
                }

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
                $json = @json_decode($data, true);
                if($json) {
                    $this->SendDebug('Processing JSON Packet', $data, 0);
                    if($json['Title'] == 'DeviceStatusUpdate') {
                        $json = $json['CONTENTS'];

                        $powerState = $json['PowerStatus'] == 'ON';
                        $this->SetValue('PowerState', $powerState);
                        $this->SetValue('Volume', $json['Volume']);
                    }
                }
                $data = '';
            // binary
            } else if(strlen($data) >= 7 && ord($data[0]) == 0xff && ord($data[1]) == 0xaa) {
                // ff   aa   00   03   01   00   03
                $len = unpack('n', $data, 5)[1];
                $property = unpack('n', $data, 2)[1];
                $type = $data[4];
                if(strlen($data) >= 7 + $len) {
                    $this->SendDebug('Processing Packet', bin2hex(substr($data, 0, 7 + $len)), 0);

                    // power
                    if($property == 0x06) {
                        $this->SetValue('PowerState', ord($data[7]));
                    // input
                    } else if($property == 0x03) {
                        $value = ord($data[7]);
                        // ff   aa   00   03   01   00   03   01   10   01 => ATV
                        // ff   aa   00   03   01   00   03   02   04   01 => SAT
                        // ff   aa   00   03   01   00   03   03   0e   01 => PS
                        // ff   aa   00   03   01   00   03   06   02   01 => TV
                        // ff   aa   00   03   01   00   03   07   06   01 => CD
                        // ff   aa   00   03   01   00   03   0b   06   01 => DVD
                        // ff   aa   00   03   01   00   03   0f   12   01 => AUX
                        // ff   aa   00   03   01   00   03   17   13   01 => NET
                        // ff   aa   00   03   01   00   03   15   14   01 => BT
                        switch($value) {
                            case 0x01: $value = 'ATV'; break;
                            case 0x02: $value = 'SAT'; break;
                            case 0x03: $value = 'PS'; break;
                            case 0x06: $value = 'TV'; break;
                            case 0x07: $value = 'CD'; break;
                            case 0x0b: $value = 'DVD'; break;
                            case 0x0f: $value = 'AUX'; break;
                            default: 
                            case 0x17: $value = 'NET'; break;
                            case 0x15: $value = 'BT'; break;
                        }
                        $this->SetValue('Input', $value);
                        if($this->GetMode() == 0 && ($value == 'NET' || $value == 'BT')) {
                            $this->UpdateMode(1);
                            return '';
                        }
                    // volume
                    } else if($property = 0x0c) {
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

                // after login or on device status notification
                if(($cmd == 3 && $type == 2) || ($cmd == 112 && $type == 2)) {
                    $data2 = "\x00\x00\x01\x70\x00\x00\x00\x00\x00\x00";
                    CSCK_SendText($this->GetConnectionID(), $data2);
                // device status data
                } else if($cmd == 112 && $type == 1) {
                    $data2 = substr($data, 10, $len);
                    $json = @json_decode($data2, true);
                    if($json && $json['Title'] == 'DeviceStatusUpdate') {
                        $json = $json['CONTENTS'];
                        
                        if($json['InputSource'] == 'NONE') {
                            $this->UpdateMode(0);
                            return '';
                        }

                        $this->SendDebug('Processing JSON Value', $data2, 0);

                        $powerState = $json['PowerStatus'] == 'ON';
                        $this->SetValue('PowerState', $powerState);
                        $this->SetValue('Volume', $json['Volume']);

                        $state = 'stop';
                        if($json['PlayStatus'] == 'PLAY') $state = 'play';
                        if($json['PlayStatus'] == 'PAUSE') $state = 'pause';

                        $this->SetValue('State', $state);
                        $this->SetValue('Application', dashDefault(strpos($json['coverArtUrl'], 'scdn.co') == false ? '' : 'Spotify'));
                        $this->SetValue('Position', 0);
                        $this->SetValue('Album', dashDefault($json['Album']));
                        $this->SetValue('Artist', dashDefault($json['Artist']));
                        $this->SetValue('Title', dashDefault($json['TrackName']));
                        $this->SetValue('Cover', $json['coverArtUrl']);
                        $this->SetValue('Duration', ceil($json['DurationInMilliseconds'] / 1000));
                    }
                // playback status
                } else if($cmd == 51 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    if(ord($data2) == 0x30) {
                        $this->SetValue('State', 'play');
                    } else if(ord($data2) == 0x31) {
                        $this->SetValue('State', 'stop');
                    } else if(ord($data2) == 0x32) {
                        $this->SetValue('State', 'pause');
                    }
                // playback position
                } else if($cmd == 49 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    $this->SetValue('Position', floor($data2 / 1000));
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
                case 'ATV': $input = "\x01\x10\x01"; break;
                // ff   aa   00   03   01   00   03   02   04   01 => SAT
                case 'SAT': $input = "\x02\x04\x01"; break;
                // ff   aa   00   03   01   00   03   03   0e   01 => PS
                case 'PS': $input = "\x03\x0e\x01"; break;
                // ff   aa   00   03   01   00   03   06   02   01 => TV
                case 'TV': $input = "\x06\x02\x01"; break;
                // ff   aa   00   03   01   00   03   07   06   01 => CD
                case 'CD': $input = "\x07\x06\x01"; break;
                // ff   aa   00   03   01   00   03   0b   06   01 => DVD
                case 'DVD': $input = "\x0b\x06\x01"; break;
                // ff   aa   00   03   01   00   03   0f   12   01 => AUX
                case 'AUX': $input = "\x0f\x12\x01"; break;
                // ff   aa   00   03   01   00   03   17   13   01 => NET
                case 'NET': $input = "\x17\x13\x01"; break;
                // ff   aa   00   03   01   00   03   15   14   01 => BT
                case 'BT': $input = "\x15\x14\x01"; break;
                default: return;
            }
            $data = $this->MakePacket(0x03, 0x01, $input);

            // when switching to another input directly the InputSource is not changed to "NONE".. 
            if($mode == 1 && !($value == 'NET' || $value == 'BT')) {
                $this->UpdateMode(0);
            }
        } else if($ident === 'Volume') {
            if($mode == 0) {
                $data = $this->MakePacket(0x0c, 0x01, chr(round(($value/100)*70)));
            } else {
                $data = "\x00\x00\x02\x40\x00\x00\x0\x00\x02\x00$value";
            }
        } else if($ident === 'PowerState') {
            $forceDevice = true;
            $data = $this->MakePacket(0x06, 0x01, $value ? "\x01" : "\x00");
        }

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
    private function ResetState() {
        $this->MUSetBuffer('Data', '');
        $this->MUSetBuffer('SkipData', false);

        $this->SetValue("Artist", '-');
        $this->SetValue("Album", '-');
        $this->SetValue("Title", '-');
        $this->SetValue("Cover", "");
        $this->SetValue("Source", "-");
        $this->SetValue("Application", "-");
        $this->SetValue("Duration", 0);
        $this->SetValue('State', 'stop');
        $this->SetValue('Input', 'NET');
        $this->SetValue('PowerState', false);
    }
}