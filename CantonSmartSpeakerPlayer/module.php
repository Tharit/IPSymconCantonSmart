<?php

require_once(__DIR__ . '/../libs/ModuleUtilities.php');

function dashDefault($value) {
    if($value) return $value;
    return '-';
}

class CantonSmartSpeakerPlayer extends IPSModule
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
                // reset state
                $this->ResetState();

                $this->SendDebug('CHANGESTATUS', json_encode($Data), 0);

                // if parent became active: connect
                if ($Data[0] === IS_ACTIVE) {
                    $this->Connect();
                    $this->SetValue("Connected", true);
                } else {
                    $this->SetValue("Connected", false);
                }
                break;
            default:
                break;
        }
    }

    public function Connect() {
        $buffer = "\x00\x00\x02\x03\x00\x00\x00\x00\x0d\x0010.0.0.11";
        CSCK_SendText($this->GetConnectionID(), $buffer);
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

    private function ReceiveDataStream($data) {
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
                    }
                // playback status
                } else if($cmd == 51 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    if(ord($data2) == 0x30) {
                        $this->SetValue('State', 'play');
                    } else if(ord($data2) == 0x31) {
                        $this->SetValue('State', 'stop');
                        $this->ClearMetadata();
                    } else if(ord($data2) == 0x32) {
                        $this->SetValue('State', 'pause');
                    }
                // playback position
                } else if($cmd == 49 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    $position = floor($data2 / 1000);
                    $this->SetValue('Position', $position);
                // volume
                } else if($cmd == 64 && $type == 2) {
                    $data2 = substr($data, 10, $len);
                    $this->SetValue('Volume', $data2);
                // input
                } else if($cmd == 70 && $type == 2) {
                    /*
                    $data2 = substr($data, 10, $len);
                    if($len > 0 && strpos($data2, 'SPEAKER_INACTIVE') === 0)) {
                        
                    }
                    */
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
        $data = $this->ReceiveDataStream($data);

        $this->MUSetBuffer('Data', $data);
    }

    public function RequestAction($ident, $value)
    {
        if($ident === 'Volume') {
            if($value < 0 || $value > 100) return;
            $data = "\x00\x00\x02\x40\x00\x00\x0\x00\x02\x00$value";
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
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x04\x00PLAY";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Pause() {
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x05\x00PAUSE";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Stop() {
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x04\x00STOP";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Next() {
        $data = "\x00\x00\x02\x28\x00\x00\x0\x00\x04\x00NEXT";
        CSCK_SendText($this->GetConnectionID(), $data);
    }

    public function Prev() {
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

        $this->ClearMetadata();
    }
}