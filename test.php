<?php

if(ord("\xff") == 0xff) {
    echo "tst";
}
return;

function MakePacket($property, $type, $value = "") {
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

var_dump(bin2hex(MakePacket(0x0c, 0x01, "\x23")));
return;

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_connect($sock, '10.0.125.96', 7777);

$buffer = "\x00\x00\x02\x03\x00\x00\x00\x00\x0d\x0010.0.89.246";

socket_send($sock, $buffer, 23,0);
$bytes = socket_recv($sock, $buffer, 1024, 0);
//$buffer2 = "\x00\x00\x02\x28\x00\x00\x0\x00\x00\x00";
//$buffer2 = "\x00\x00\x02\x33\x00\x00\x00\x00\x01\x002";
$buffer2 = "\x00\x00\x02\x28\x00\x00\x00\x00\x04\x00STOP";
//$buffer2 = "\x00\x00\x02\x33\x00\x00\x00\x00\x01\x00\x33";
            socket_send($sock, $buffer2, 14,0);

while(true) {
    $bytes = socket_recv($sock, $buffer, 1024, 0);
    $remaining = $bytes;
    
    $type = ord($buffer[2]);
        $cmd = unpack('n', $buffer, 3)[1];
        $len = unpack('n', $buffer, 8)[1];
        
        echo "Action: " . $type ." | CMD: " . $cmd . "\n";
        
        // refresh playback state
        if($type == 2 && $cmd == 40) {
            $buffer2 = "\x00\x00\x01\x28\x00\x00\x00\x00\x00\x00";
            socket_send($sock, $buffer2, 10,0);
        }
    

    var_dump(bin2hex($buffer));
}


/*

InputSource:
0 => NET
2 => BT
NONE => all others

*/

/*

// receive playback update
// playing
00000200330087bd000130
// paused
000002003300a7ff000132
// nothing
000002003300979c000131

// receive position update
000002003100d6260006313538303832


// mute (from server)
000002003f00f0ac00044d555445

// control stream

00 00 02 28 00 00 00 00 04 00 S T O P
00 00 02 28 00 00 00 00 04 00 P R E V
00 00 02 28 00 00 00 00 04 00 N E X T
00 00 02 28 00 00 00 00 05 00 P A U S E
00 00 02 28 00 00 00 00 06 00 R E S U M E


// set streaming volume

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_connect($sock, '192.168.8.234', 7777);

$buffer = "\x00\x00\x02\x03\x00\x00\x00\x00\x0d\x00192.168.8.182";

socket_send($sock, $buffer, 23,0);

socket_recv($sock, $buffer, 1024, 0);
echo(bin2hex($buffer));
echo "\n\n";


$buffer = "\x00\x00\x02\x40\x00\x00\x00\x00\x02\x00\x21\x34"; // volume in percentage of 70
socket_send($sock, $buffer, 12,0);
socket_close($sock);
return;
*/

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_connect($sock, '10.0.125.96', 50006);

// --   --   --   --   --   len  len  val
// ff   aa   00   0c   01   00   01   vv

// client: set volume
// only works on none streaming input channels.. 
// otherwise have to use port 7777 interface
// ff   aa   00   0c   01   00   01   31

// client: request volume
// ff   aa   00   0c   02   00   00

// server: update volume
//                                    vol  
// ff   aa   00   0c   01   00   02   2b   32
// ff   aa   00   0c   01   00   02   1a   32

// client: request power state
// ff   aa   00   06   02   00   00

// client: update power state
//                                    pwr
// ff   aa   00   06   01   00   01   00


// client: request input
// ff   aa   00   03   02   00   00

// client: set input


// client: request mute
// ff   aa   00   09   02   00   00

// client: set mute
//                                   mute
// ff   aa   00   09   01   00   01  00

// server: update mute
//                                   mute
// ff   aa   00   09   01   00   01  00

// server: updateinput
// last byte: play mode
// ff   aa   00   03   01   00   03   01   10   01 => ATV
// ff   aa   00   03   01   00   03   02   04   01 => SAT
// ff   aa   00   03   01   00   03   03   0e   01 => PS
// ff   aa   00   03   01   00   03   06   02   01 => TV
// ff   aa   00   03   01   00   03   07   06   01 => TV
// ff   aa   00   03   01   00   03   0b   06   01 => DVD
// ff   aa   00   03   01   00   03   0f   12   01 => AUX
// ff   aa   00   03   01   00   03   17   13   01 => NET
// ff   aa   00   03   01   00   03   15   14   01 => BT

// client: request ???
// ff   aa   00   0c   04   00   00


//$buffer = "\xff\xaa\x00\x06\x02\x00\x00";//\xff\xaa\x00\x0c\x02\x00\x00\xff\xaa\x00\x03\x02\x00\x00";
//$buffer = "\xff\xaa\x00\x03\x01\x00\x03\x02\x04\x01";//\xff\xaa\x00\x0c\x02\x00\x00\xff\xaa\x00\x03\x02\x00\x00";
//$buffer = "\xff\xaa\x00\x03\x02\x00\x00";//\xff\xaa\x00\x0c\x02\x00\x00\xff\xaa\x00\x03\x02\x00\x00";

//$buffer = "\xff\xaa\x00\x0c\x02\x00\x00\xff\xaa\x00\x04\x02\x00\x00\xff\xaa\x00\x06\x02\x00\x00";
//$buffer = "\xff\xaa\x00\x0c\x01\x00\x01\x16";

//socket_send($sock, $buffer, 10, 0);

while(true) {
    $bytes = socket_recv($sock, $buffer, 1024, 0);
    $remaining = $bytes;
    
    var_dump(bin2hex($buffer));
}

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_connect($sock, '192.168.8.234', 7777);


// register with ip

$buffer = "\x00\x00\x02\x03\x00\x00\x00\x00\x0d\x00192.168.8.182";

echo(bin2hex($buffer));
echo "\n\n";

socket_send($sock, $buffer, 23,0);

socket_recv($sock, $buffer, 1024, 0);
echo(bin2hex($buffer));
echo "\n\n";

// request model

$buffer = "\x00\x00\x02\xd0\x00\x00\x00\x00\x0a\x00READ_Model";
echo(bin2hex($buffer));
echo "\n\n";
socket_send($sock, $buffer, 20,0);

socket_recv($sock, $buffer, 1024, 0);
echo(bin2hex($buffer));
echo $buffer;


echo "\n----\n";
// request volume 40
// request device status 70
// request play window status 2d
// request MRA info?? 46
// request ?? 97
// request ?? 2a
$buffer = "\x00\x00\x01\x40\x00\x00\x00\x00\x00\x00";
$buffer .= "\x00\x00\x01\x97\x00\x00\x00\x00\x00\x00";
$buffer .= "\x00\x00\x01\x2a\x00\x00\x00\x00\x00\x00";
echo(bin2hex($buffer));
echo "\n\n";
socket_send($sock, $buffer, 30,0);

/*socket_recv($sock, $buffer, 1024, 0);
echo(bin2hex($buffer));
echo $buffer;
echo "\n\n";


// request ?? 97
$buffer = "\x00\x00\x01\x97\x00\x00\x00\x00\x00\x00";
echo(bin2hex($buffer));
echo "\n\n";
socket_send($sock, $buffer, 10,0);

socket_recv($sock, $buffer, 1024, 0);
echo(bin2hex($buffer));
echo $buffer;

echo "\n\n";

// request ?? 2a
$buffer = "\x00\x00\x01\x2a\x00\x00\x00\x00\x00\x00";
echo(bin2hex($buffer));
echo "\n\n";
socket_send($sock, $buffer, 10,0);

socket_recv($sock, $buffer, 1024, 0);
echo(bin2hex($buffer));
echo $buffer;

echo "\n\n";


*/
$buffer = "\x00\x00\x02\x29\x00\x00\x00\x00\x0a\x00GETUI:PLAY";
echo(bin2hex($buffer));
echo "\n\n";
socket_send($sock, $buffer, 20,0);


while(true) {
    $bytes = socket_recv($sock, $buffer, 1024, 0);
    $remaining = $bytes;
    
    do {
        $type = ord($buffer[2]);
        $cmd = unpack('n', $buffer, 3)[1];
        $len = unpack('n', $buffer, 8)[1];
        
        echo "Action: " . $type ." | CMD: " . $cmd . "\n";
        
        // refresh device status when notified
        if($type == 2 && $cmd == 112) {
            $buffer2 = "\x00\x00\x01\x70\x00\x00\x00\x00\x00\x00";
            socket_send($sock, $buffer2, 10,0);
        // received new device status info
        } else if($type == 1 && $cmd == 112) {
            if($len) {
                echo 'Device Status: ' . substr($buffer, 10, $len) . '\n';
            }
        } else if($type == 1 && $cmd == 64) {
            if($len) {
                echo 'Volume: ' . substr($buffer, 10, $len) . '\n';
            }
        } else if($type == 1 && $cmd == 42) {
            if($len) {
                echo 'test: ' . substr($buffer, 10, $len) . '\n';
            }
        } 
        
        $remaining -= 10 + $len;
        $buffer = substr($buffer, 10 + $len, $remaining);
    } while($remaining > 0);

    //echo(bin2hex($buffer));

    
    echo "\n\n";
};