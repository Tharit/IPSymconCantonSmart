<?php

require_once(__DIR__ . '/../libs/ModuleUtilities.php');

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
        $this->RegisterVariableBoolean("PowerState", "PowerState");

        // messages
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        // clear state on startup
        $this->ResetState();

        // if this is not the initial creation there might already be a parent
        if($this->UpdateConnection() && $this->HasActiveParent()) {
            $this->SendDebug('Module Create', 'Already connected', 0);
        }
    }

    /**
     * Configuration changes
     */
    public function ApplyChanges()
    {
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
            $length = pack('n', strlen($value));
        } else {
            $value = '';
            $length = '';
        }
        $property = chr($property);
        $type = chr($type);
        return "\xff\xaa\x00$property$type$length$value";
    }

    public function ReceiveData($data) {
        // unpack & decode data
        $data = json_decode($data);
        $data = utf8_decode($data->Buffer);

        $data = $this->MUGetBuffer('Data') . $data;

        // find start of packet
        do {
            if(ord($data[0]) != '{' && !(ord($data[0]) == 0xff && ord($data[1]) == 0xaa)) {
                $data = substr($data, 1);
            }
        } while(strlen($data) >= 2);

        // JSON
        if($data[0] == '{') {
            $json = @json_decode($data);
            if($json) {
                $this->SendDebug('Processing JSON Packet', $data, 0);
            }
            $data = '';
        // binary
        } else if(ord($data[0]) == 0xff && ord($data[1]) == 0xaa && strlen($data) >= 7) {
            // ff   aa   00   03   01   00   03
            $len = unpack('n', $data, 5)[1];
            $property = unpack('n', $data, 2)[1];
            $type = $data[4];
            if(strlen($data) >= 7 + $len) {
                $this->SendDebug('Processing Packet', bin2hex(substr($data, 0, 7 + $len), 0));

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
                // volume
                } else if($property = 0x0c) {
                    if($len == 2) {
                        $this->SetValue('Volume', ord($data[7]));
                    }
                }
                $data = substr($data, 7 + $len);
            }
        }

        $this->MUSetBuffer('Data', $data);
    }

    public function RequestAction($ident, $value)
    {
        if($ident === 'Volume') {
            // @TODO
        }

        $this->SendDebug('Action', $ident, 0);
    }

    //------------------------------------------------------------------------------------
    // external methods
    //------------------------------------------------------------------------------------
    public function SetVolume(int $volume) {
        // @TODO
    }

    public function Play() {
        // @TODO
    }

    public function Pause() {
        // @TODO
    }

    public function Stop() {
        // @TODO
    }

    public function Next() {
        // @TODO
    }

    public function Prev() {
        // @TODO
    }

    //------------------------------------------------------------------------------------
    // module internals
    //------------------------------------------------------------------------------------
    private function ResetState() {
        $this->SetValue("Artist", '-');
        $this->SetValue("Album", '-');
        $this->SetValue("Title", '-');
        $this->SetValue("Cover", "");
        $this->SetValue("Source", "-");
        $this->SetValue("Application", "-");
        $this->SetValue("Duration", 0);
        $this->SetValue('Source', '-');
        $this->SetValue('State', 'stop');
        $this->SetValue('Input', 'NET');
        $this->SetValue('PowerState', false);
    }
}