<?php

declare(strict_types=1);

/**
 * Nachgebaute HCU für die Tests: Anmeldung (HTTPS) und Connect API (WebSocket über TLS) mit eigenem Zertifikat.
 * Verhält sich beim Türschloss wie die echte HCU: ohne PIN INVALID_REQUEST, falsche PIN INVALID_AUTHORIZATION_PIN.
 *
 * Aufruf: php tests/fake_hcu.php <PortAnmeldung> <PortWs> <Zertifikat.pem>
 * SPDX-License-Identifier: MIT
 */

[, $PortAuth, $PortWs, $Pem] = $argv;
$Kontext = static fn () => stream_context_create(['ssl' => ['local_cert' => $Pem, 'verify_peer' => false, 'allow_self_signed' => true]]);
$Auth = stream_socket_server("ssl://127.0.0.1:$PortAuth", $e1, $t1, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Kontext());
$Ws = stream_socket_server("ssl://127.0.0.1:$PortWs", $e2, $t2, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Kontext());
if (!$Auth || !$Ws) {
    fwrite(STDERR, "Server startet nicht: $t1 $t2\n");
    exit(1);
}
echo "bereit\n";

const SCHLOSS = '3014F711A0001DE5C9B0A1B2';
$Zustand = [
    'devices' => [
        SCHLOSS => ['id' => SCHLOSS, 'label' => 'Haustür', 'modelType' => 'HmIP-DLD', 'functionalChannels' => [
            '0' => ['functionalChannelType' => 'DEVICE_OPERATIONLOCK', 'index' => 0],
            '1' => ['functionalChannelType' => 'DOOR_LOCK_CHANNEL', 'index' => 1, 'lockState' => 'LOCKED', 'motorState' => 'STOPPED'],
            '2' => ['functionalChannelType' => 'ACCESS_AUTHORIZATION_CHANNEL', 'index' => 2, 'groups' => ['prof-familie']],
        ]],
        'HAUSTUER2' => ['id' => 'GARTENTOR1', 'label' => 'Gartentor', 'functionalChannels' => [
            '1' => ['functionalChannelType' => 'DOOR_LOCK_CHANNEL', 'index' => 1, 'lockState' => 'UNLOCKED', 'motorState' => 'STOPPED'],
            '2' => ['functionalChannelType' => 'ACCESS_AUTHORIZATION_CHANNEL', 'index' => 2, 'groups' => ['prof-garten']],
        ]],
        'SW1' => ['id' => 'SW1', 'label' => 'Lampe', 'functionalChannels' => ['1' => ['functionalChannelType' => 'SWITCH_CHANNEL', 'on' => false]]],
    ],
    'groups' => [
        'prof-familie' => ['id' => 'prof-familie', 'type' => 'ACCESS_AUTHORIZATION_PROFILE', 'label' => 'Familie', 'clientIds' => ['app-1', 'client-1'], 'authorizationPinAssigned' => true],
        'prof-garten' => ['id' => 'prof-garten', 'type' => 'ACCESS_AUTHORIZATION_PROFILE', 'label' => 'Garten', 'clientIds' => ['client-1'], 'authorizationPinAssigned' => true],
    ],
    // große Antwort wie bei einer echten HCU (mehrere Rahmen-Längen testen)
    'fuellung' => str_repeat('x', 200000),
];
$Pins = ['Familie' => '4711', 'Garten' => '0815'];

function lesenBis($s, string $Ende): string
{
    $d = '';
    while (!str_contains($d, $Ende) && !feof($s)) {
        $c = fread($s, 1);
        if ($c === false || $c === '') {
            break;
        }
        $d .= $c;
    }
    return $d;
}

function rahmen($s, string $Text, int $Op = 1): void
{
    $l = strlen($Text);
    $k = chr(0x80 | $Op) . ($l < 126 ? chr($l) : ($l < 65536 ? chr(126) . pack('n', $l) : chr(127) . pack('J', $l)));
    fwrite($s, $k . $Text);
}

function rahmenLesen($s): ?array
{
    $k = fread($s, 2);
    if ($k === false || strlen($k) < 2) {
        return null;
    }
    $op = ord($k[0]) & 0x0f;
    $l = ord($k[1]) & 0x7f;
    if ($l === 126) {
        $l = unpack('n', fread($s, 2))[1];
    } elseif ($l === 127) {
        $l = unpack('J', fread($s, 8))[1];
    }
    $m = fread($s, 4);
    $d = '';
    while (strlen($d) < $l) {
        $d .= fread($s, $l - strlen($d));
    }
    $d = substr($d ^ str_repeat($m, intdiv($l, 4) + 1), 0, $l);
    return [$op, $d];
}

$Ende = time() + 120;
while (time() < $Ende) {
    $r = [$Auth, $Ws];
    $w = $x = null;
    if (@stream_select($r, $w, $x, 1) < 1) {
        continue;
    }
    foreach ($r as $Server) {
        $c = @stream_socket_accept($Server, 5);
        if (!$c) {
            continue;
        }
        if ($Server === $Auth) {
            $Kopf = lesenBis($c, "\r\n\r\n");
            preg_match('/Content-Length:\s*(\d+)/i', $Kopf, $m);
            $Body = $m ? fread($c, (int) $m[1]) : '';
            $Daten = json_decode((string) $Body, true) ?? [];
            if (str_contains($Kopf, 'requestConnectApiAuthToken') && ($Daten['activationKey'] ?? '') === 'ABC' && str_contains($Kopf, 'VERSION: 12')) {
                $Antwort = '{"authToken":"TOKEN1"}';
                // in Stücken (chunked) wie manche Server
                fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\n\r\n"
                    . dechex(10) . "\r\n" . substr($Antwort, 0, 10) . "\r\n" . dechex(strlen($Antwort) - 10) . "\r\n" . substr($Antwort, 10) . "\r\n0\r\n\r\n");
            } elseif (str_contains($Kopf, 'confirmConnectApiAuthToken') && ($Daten['authToken'] ?? '') === 'TOKEN1') {
                fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 23\r\n\r\n{\"clientId\":\"client-1\"}");
            } else {
                fwrite($c, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n");
            }
            fclose($c);
            continue;
        }
        // WebSocket
        $Kopf = lesenBis($c, "\r\n\r\n");
        if (!preg_match('/^authtoken: TOKEN1\r?$/mi', $Kopf) || !preg_match('/^plugin-id: \S+/mi', $Kopf)) {
            fwrite($c, "HTTP/1.1 401 Unauthorized\r\n\r\n");
            fclose($c);
            continue;
        }
        preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $Kopf, $k);
        $Accept = base64_encode(sha1($k[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        fwrite($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $Accept\r\n\r\n");
        while (($f = rahmenLesen($c)) !== null) {
            [$op, $Text] = $f;
            if ($op === 8) {
                break;
            }
            $n = json_decode($Text, true);
            if (($n['type'] ?? '') !== 'HMIP_SYSTEM_REQUEST') {
                continue;
            }
            // vor der Antwort: Ping und eine Statusanfrage der HCU (muss der Client aushalten)
            rahmen($c, 'p', 9);
            rahmen($c, json_encode(['type' => 'PLUGIN_STATE_REQUEST', 'id' => 'ps1']));
            $Pfad = $n['body']['path'];
            $b = $n['body']['body'];
            if ($Pfad === '/hmip/home/getSystemState') {
                $Antwort = ['code' => 200, 'body' => $Zustand];
            } elseif ($Pfad === '/hmip/device/control/setLockState') {
                $Profil = $b['deviceId'] === SCHLOSS ? 'Familie' : 'Garten';
                if (!isset($b['authorizationPin'])) {
                    $Antwort = ['code' => 400, 'body' => ['errorCode' => 'INVALID_REQUEST']];
                } elseif ($b['authorizationPin'] !== $Pins[$Profil]) {
                    $Antwort = ['code' => 403, 'body' => ['errorCode' => 'INVALID_AUTHORIZATION_PIN']];
                } else {
                    $Antwort = ['code' => 200];
                    file_put_contents($Pem . '.letzter', json_encode($b));
                }
            } else {
                $Antwort = ['code' => 400, 'body' => ['errorCode' => 'INVALID_REQUEST']];
            }
            rahmen($c, json_encode(['type' => 'HMIP_SYSTEM_RESPONSE', 'id' => $n['id'], 'body' => $Antwort]));
        }
        fclose($c);
    }
}
