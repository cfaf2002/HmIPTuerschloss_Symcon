<?php

declare(strict_types=1);

/**
 * Tests für HmIP Türschloss: Logik (Schlösser, Freigabe, PIN je Profil, Fehlertexte) und Verbindung gegen eine
 * nachgebaute HCU (Anmeldung, WebSocket über TLS, große Antworten, Ping, PIN-Prüfung, gemerktes Zertifikat).
 *
 * Aufruf: php tests/run.php
 * SPDX-License-Identifier: MIT
 */

require __DIR__ . '/../libs/DLDLogik.php';
require __DIR__ . '/../libs/DLDVerbindung.php';

$Fehler = 0;
function pruefe(bool $Ok, string $Text): void
{
    global $Fehler;
    echo ($Ok ? '  ✓ ' : '  ✗ ') . $Text . PHP_EOL;
    if (!$Ok) {
        $Fehler++;
    }
}

echo "Logik\n";
$State = [
    'devices' => [
        'L1' => ['id' => 'L1', 'label' => 'Haustür', 'functionalChannels' => [
            '1' => ['functionalChannelType' => 'DOOR_LOCK_CHANNEL', 'index' => 1, 'lockState' => 'OPEN', 'motorState' => 'OPENING'],
            '2' => ['functionalChannelType' => 'ACCESS_AUTHORIZATION_CHANNEL', 'groups' => ['p1']],
        ]],
        'L2' => ['id' => 'L2', 'label' => 'Gartentor', 'functionalChannels' => [
            '1' => ['functionalChannelType' => 'DOOR_LOCK_CHANNEL', 'index' => 1, 'lockState' => 'LOCKED'],
            '2' => ['functionalChannelType' => 'ACCESS_AUTHORIZATION_CHANNEL', 'groups' => ['p2']],
        ]],
        'L3' => ['id' => 'L3', 'label' => 'Keller', 'functionalChannels' => [
            '1' => ['functionalChannelType' => 'DOOR_LOCK_CHANNEL', 'index' => 1, 'lockState' => 'LOCKED'],
            '2' => ['functionalChannelType' => 'ACCESS_AUTHORIZATION_CHANNEL', 'groups' => ['p3']],
        ]],
        'L4' => ['id' => 'L4', 'label' => 'Garage', 'functionalChannels' => [
            '1' => ['functionalChannelType' => 'DOOR_LOCK_CHANNEL', 'index' => 1],
            '2' => ['functionalChannelType' => 'ACCESS_AUTHORIZATION_CHANNEL', 'groups' => ['p4']],
        ]],
        'X' => ['id' => 'X', 'functionalChannels' => ['1' => ['functionalChannelType' => 'SWITCH_CHANNEL']]],
    ],
    'groups' => [
        'p1' => ['id' => 'p1', 'type' => 'ACCESS_AUTHORIZATION_PROFILE', 'label' => 'Familie', 'clientIds' => ['me'], 'authorizationPinAssigned' => true],
        'p2' => ['id' => 'p2', 'type' => 'ACCESS_AUTHORIZATION_PROFILE', 'label' => 'Garten', 'clientIds' => ['me'], 'authorizationPinAssigned' => true],
        'p3' => ['id' => 'p3', 'type' => 'ACCESS_AUTHORIZATION_PROFILE', 'label' => 'Gäste', 'clientIds' => ['other'], 'authorizationPinAssigned' => true],
        'p4' => ['id' => 'p4', 'type' => 'ACCESS_AUTHORIZATION_PROFILE', 'label' => 'Ohne PIN', 'clientIds' => ['me'], 'authorizationPinAssigned' => false],
    ],
];
$Liste = DLDLogik::schloesser($State, 'me');
$Nach = array_column($Liste, null, 'name');
pruefe(count($Liste) === 4, 'vier Türschlösser gefunden, die Lampe nicht');
pruefe($Nach['Haustür']['zustand'] === 2 && $Nach['Haustür']['motor'] === 1, 'Zustand OPEN → 2, Motor OPENING → 1');
pruefe($Nach['Haustür']['freigabe'] === 'ok' && $Nach['Haustür']['profile'] === ['Familie'], 'Freigabe über Profil „Familie“');
pruefe($Nach['Keller']['freigabe'] === 'none', 'fremdes Profil: keine Freigabe');
pruefe($Nach['Garage']['freigabe'] === 'nopin' && $Nach['Garage']['profil'] === 'Ohne PIN', 'Profil ohne PIN erkannt');
pruefe($Nach['Garage']['zustand'] === null, 'unbekannter Zustand bleibt leer');
$Pins = [['Profil' => 'Familie', 'PIN' => ' 1234 '], ['Profil' => 'garten', 'PIN' => '5678'], ['Profil' => '', 'PIN' => '9']];
pruefe(DLDLogik::pinFuer(['Familie'], $Pins, '0000') === '1234', 'PIN des Profils Familie');
pruefe(DLDLogik::pinFuer(['Garten'], $Pins, '0000') === '5678', 'PIN des Profils Garten (Groß-/Kleinschreibung egal)');
pruefe(DLDLogik::pinFuer(['Andere'], $Pins, ' 0000 ') === '0000', 'sonst die allgemeine PIN');
pruefe(DLDLogik::pinFuer([], [], '') === '', 'ohne alles leer');
pruefe(str_contains(DLDLogik::fehlerText('INVALID_REQUEST', 'Haustür'), 'PIN'), 'INVALID_REQUEST erklärt die PIN');
pruefe(str_contains(DLDLogik::fehlerText('INVALID_AUTHORIZATION_PIN', 'Haustür'), 'PIN falsch'), 'falsche PIN erklärt');
pruefe(str_contains(DLDLogik::freigabeText('ok', 'Familie', false), 'fehlt die PIN'), 'Freigabe-Text ohne PIN');
pruefe(DLDLogik::ident('Z', '3014-F711:A0') === 'Z3014F711A0', 'Ident nur aus Buchstaben und Ziffern');

echo "Verbindung (nachgebaute HCU)\n";
if (!extension_loaded('openssl')) {
    echo "  – übersprungen (openssl fehlt)\n";
} else {
    $Ordner = sys_get_temp_dir() . '/dld-test-' . getmypid();
    @mkdir($Ordner);
    $Zertifikat = static function (string $Datei): void {
        $Schluessel = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $Csr = openssl_csr_new(['commonName' => 'hcu1-test.local'], $Schluessel);
        $Cert = openssl_csr_sign($Csr, null, $Schluessel, 30);
        openssl_x509_export($Cert, $CertPem);
        openssl_pkey_export($Schluessel, $KeyPem);
        file_put_contents($Datei, $CertPem . $KeyPem);
    };
    $Starten = static function (string $Pem, int $Pa, int $Pw) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/fake_hcu.php', (string) $Pa, (string) $Pw, $Pem], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Rohre);
        $Zeile = fgets($Rohre[1]);
        if (trim((string) $Zeile) !== 'bereit') {
            echo stream_get_contents($Rohre[2]);
        }
        return $p;
    };
    $Pa = random_int(20000, 40000);
    $Pw = $Pa + 1;
    $Pem = "$Ordner/hcu.pem";
    $Zertifikat($Pem);
    $Server = $Starten($Pem, $Pa, $Pw);
    try {
        $Hcu = new DLDVerbindung('127.0.0.1', $Pa, $Pw, 10);
        try {
            $Hcu->registrieren('FALSCH', 'Test');
            pruefe(false, 'falscher Schlüssel wird abgelehnt');
        } catch (Throwable $e) {
            pruefe(str_contains($e->getMessage(), 'HTTP 403'), 'falscher Schlüssel wird abgelehnt');
        }
        $Zugang = $Hcu->registrieren('ABC', 'Test');
        pruefe($Zugang === ['token' => 'TOKEN1', 'clientId' => 'client-1'], 'Anmeldung liefert Token und clientId (auch bei chunked Antwort)');
        pruefe(count($Hcu->Fingerabdruecke) === 1, 'Zertifikat der Anmeldung gemerkt');

        $State = $Hcu->anfrage('TOKEN1', '/hmip/home/getSystemState', [], 20);
        pruefe(strlen($State['fuellung'] ?? '') === 200000, 'große Antwort (200 KB) trotz Ping und Statusanfrage gelesen');
        pruefe(count($Hcu->Fingerabdruecke) === 2, 'Zertifikat der Connect API gemerkt');
        $Liste = DLDLogik::schloesser($State, 'client-1');
        pruefe(array_column($Liste, 'name') === ['Gartentor', 'Haustür'], 'zwei Schlösser aus dem Systemzustand');

        $Schalten = static function (array $Body) use ($Hcu): string {
            try {
                $Hcu->anfrage('TOKEN1', '/hmip/device/control/setLockState', $Body, 10);
                return 'ok';
            } catch (DLDHcuFehler $e) {
                return $e->Fehlercode;
            }
        };
        $Haustuer = ['deviceId' => '3014F711A0001DE5C9B0A1B2', 'channelIndex' => 1, 'targetLockState' => 'OPEN'];
        pruefe($Schalten($Haustuer) === 'INVALID_REQUEST', 'ohne PIN: INVALID_REQUEST (wie die echte HCU)');
        pruefe($Schalten($Haustuer + ['authorizationPin' => '1111']) === 'INVALID_AUTHORIZATION_PIN', 'falsche PIN erkannt');
        $Pins = [['Profil' => 'Familie', 'PIN' => '4711'], ['Profil' => 'Garten', 'PIN' => '0815']];
        $Pin = DLDLogik::pinFuer(array_column($Liste, null, 'name')['Haustür']['profile'], $Pins, '');
        pruefe($Schalten($Haustuer + ['authorizationPin' => $Pin]) === 'ok', 'Haustür mit der PIN von „Familie“ geöffnet');
        $Letzter = json_decode((string) file_get_contents($Pem . '.letzter'), true);
        pruefe($Letzter === $Haustuer + ['authorizationPin' => '4711'], 'gesendet: deviceId, channelIndex, targetLockState, authorizationPin');
        $Garten = ['deviceId' => 'GARTENTOR1', 'channelIndex' => 1, 'targetLockState' => 'LOCKED',
            'authorizationPin' => DLDLogik::pinFuer(array_column($Liste, null, 'name')['Gartentor']['profile'], $Pins, '')];
        pruefe($Schalten($Garten) === 'ok', 'Gartentor mit der PIN von „Garten“ verriegelt');

        try {
            $Hcu->anfrage('FALSCH', '/hmip/home/getSystemState', [], 5);
            pruefe(false, 'falscher Token abgelehnt');
        } catch (Throwable $e) {
            pruefe(str_contains($e->getMessage(), 'lehnt den Zugang ab'), 'falscher Token: „lehnt den Zugang ab“');
        }
        pruefe(!str_contains(json_encode($Hcu->Fingerabdruecke) . ($e->getMessage()), 'TOKEN1'), 'Token erscheint nicht in Fehlermeldungen');
    } finally {
        proc_terminate($Server);
    }

    // Andere HCU (neues Zertifikat) unter derselben Adresse: wird abgelehnt
    $Pem2 = "$Ordner/andere.pem";
    $Zertifikat($Pem2);
    $Server2 = $Starten($Pem2, $Pa + 2, $Pw + 2);
    try {
        $Gemerkt = $Hcu->Fingerabdruecke;
        $Neu = new DLDVerbindung('127.0.0.1', $Pa + 2, $Pw + 2, 10);
        $Neu->Fingerabdruecke = ['127.0.0.1:' . ($Pw + 2) => $Gemerkt['127.0.0.1:' . $Pw]];
        try {
            $Neu->anfrage('TOKEN1', '/hmip/home/getSystemState', [], 5);
            pruefe(false, 'geändertes Zertifikat wird abgelehnt');
        } catch (Throwable $e) {
            pruefe(str_contains($e->getMessage(), 'Zertifikat hat sich geändert'), 'geändertes Zertifikat wird abgelehnt');
        }
    } finally {
        proc_terminate($Server2);
        array_map('unlink', glob("$Ordner/*") ?: []);
        @rmdir($Ordner);
    }
}

echo PHP_EOL . ($Fehler === 0 ? 'Alle Tests bestanden.' : "$Fehler Test(s) fehlgeschlagen.") . PHP_EOL;
exit($Fehler === 0 ? 0 : 1);
