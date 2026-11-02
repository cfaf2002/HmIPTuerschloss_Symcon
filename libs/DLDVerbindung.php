<?php

declare(strict_types=1);

/**
 * HmIP Türschloss – Verbindung zur Home Control Unit (Connect API), ohne Symcon-Abhängigkeit
 *
 * - Anmeldung: HTTPS auf Port 6969 (requestConnectApiAuthToken / confirmConnectApiAuthToken)
 * - Anfragen: WebSocket (TLS) auf Port 9001, je Anfrage eine kurze Verbindung:
 *   Kopfzeilen authtoken + plugin-id, PLUGIN_STATE_RESPONSE, HMIP_SYSTEM_REQUEST, Antwort mit derselben id
 *
 * Sicherheit: Die HCU hat ein selbst ausgestelltes Zertifikat. Beim ersten Kontakt wird sein SHA-256-Fingerabdruck
 * gemerkt („trust on first use“), danach wird jede Verbindung mit einem anderen Zertifikat abgelehnt.
 * Token und PIN erscheinen nie in Fehlermeldungen.
 *
 * Copyright (c) 2026 Armin Frohwerk · MIT-Lizenz
 * SPDX-License-Identifier: MIT
 */
final class DLDVerbindung
{
    public const PLUGIN_ID = 'de.cfaf2002.symcon.tuerschloss';
    private const MAX_NACHRICHT = 64 * 1024 * 1024;

    /** Fingerabdrücke je host:port (wird bei neuem Kontakt ergänzt; vom Aufrufer zu speichern) */
    public array $Fingerabdruecke = [];

    public function __construct(
        private readonly string $Host,
        private readonly int $PortAuth = 6969,
        private readonly int $PortWs = 9001,
        private readonly int $Zeitlimit = 15,
    ) {
    }

    // ------------------------------------------------------------ Anmeldung

    /**
     * Mit dem Aktivierungsschlüssel aus HCUweb einen Zugang erzeugen.
     *
     * @return array{token: string, clientId: string}
     */
    public function registrieren(string $Schluessel, string $Name): array
    {
        $Antwort = $this->postJson('/hmip/auth/requestConnectApiAuthToken', [
            'activationKey' => $Schluessel,
            'pluginId' => self::PLUGIN_ID,
            'friendlyName' => ['de' => $Name, 'en' => $Name],
        ]);
        $Token = (string) ($Antwort['authToken'] ?? '');
        if ($Token === '') {
            throw new RuntimeException('Die HCU hat keinen Zugang erzeugt (Aktivierungsschlüssel abgelaufen oder schon benutzt?)');
        }
        $Bestaetigt = $this->postJson('/hmip/auth/confirmConnectApiAuthToken', ['activationKey' => $Schluessel, 'authToken' => $Token]);
        $ClientId = (string) ($Bestaetigt['clientId'] ?? '');
        if ($ClientId === '') {
            throw new RuntimeException('Die HCU hat den Zugang nicht bestätigt');
        }
        return ['token' => $Token, 'clientId' => $ClientId];
    }

    private function postJson(string $Pfad, array $Daten): array
    {
        $Body = (string) json_encode($Daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $Sock = $this->oeffnen($this->PortAuth);
        try {
            $Anfrage = "POST $Pfad HTTP/1.1\r\nHost: {$this->Host}:{$this->PortAuth}\r\nContent-Type: application/json\r\nVERSION: 12\r\n"
                . 'Content-Length: ' . strlen($Body) . "\r\nConnection: close\r\n\r\n" . $Body;
            $this->schreiben($Sock, $Anfrage);
            $Roh = '';
            $Ende = microtime(true) + $this->Zeitlimit;
            while (!feof($Sock) && microtime(true) < $Ende) {
                $Teil = fread($Sock, 65536);
                if ($Teil === false) {
                    break;
                }
                $Roh .= $Teil;
                if (strlen($Roh) > 1024 * 1024) {
                    throw new RuntimeException('Antwort der HCU zu groß');
                }
            }
        } finally {
            fclose($Sock);
        }
        $Trenn = strpos($Roh, "\r\n\r\n");
        if ($Trenn === false || !preg_match('#^HTTP/1\.[01] (\d{3})#', $Roh, $m)) {
            throw new RuntimeException('Keine gültige Antwort der HCU');
        }
        $Kopf = substr($Roh, 0, $Trenn);
        $Inhalt = substr($Roh, $Trenn + 4);
        if (preg_match('/^Transfer-Encoding:\s*chunked/mi', $Kopf)) {
            $Inhalt = self::entchunken($Inhalt);
        }
        if ((int) $m[1] >= 400) {
            throw new RuntimeException('HCU antwortet mit HTTP ' . $m[1]);
        }
        $Json = json_decode($Inhalt, true);
        return is_array($Json) ? $Json : [];
    }

    private static function entchunken(string $Daten): string
    {
        $Aus = '';
        while ($Daten !== '') {
            $Zeile = strpos($Daten, "\r\n");
            if ($Zeile === false) {
                break;
            }
            $Laenge = hexdec(trim(explode(';', substr($Daten, 0, $Zeile))[0]));
            if ($Laenge <= 0) {
                break;
            }
            $Aus .= substr($Daten, $Zeile + 2, (int) $Laenge);
            $Daten = substr($Daten, $Zeile + 2 + (int) $Laenge + 2);
        }
        return $Aus;
    }

    // ------------------------------------------------------------ Anfragen über WebSocket

    /**
     * Eine Anfrage an die HCU (z. B. /hmip/home/getSystemState oder /hmip/device/control/setLockState).
     * Liefert den Inhalt der Antwort; bei Fehler DLDHcuFehler mit dem Code der HCU.
     */
    public function anfrage(string $Token, string $Pfad, array $Daten, int $Wartezeit = 20): array
    {
        $Sock = $this->oeffnen($this->PortWs);
        try {
            $this->handshake($Sock, $Token);
            $this->senden($Sock, ['id' => self::uuid(), 'pluginId' => self::PLUGIN_ID, 'type' => 'PLUGIN_STATE_RESPONSE',
                'body' => ['pluginReadinessStatus' => 'READY']]);
            $Id = self::uuid();
            $this->senden($Sock, ['id' => $Id, 'pluginId' => self::PLUGIN_ID, 'type' => 'HMIP_SYSTEM_REQUEST',
                'body' => ['path' => $Pfad, 'body' => (object) $Daten]]);
            $Ende = microtime(true) + $Wartezeit;
            while (microtime(true) < $Ende) {
                $Text = $this->empfangen($Sock, $Ende);
                if ($Text === null) {
                    throw new RuntimeException('Verbindung zur HCU getrennt');
                }
                $Nachricht = json_decode($Text, true);
                if (!is_array($Nachricht)) {
                    continue;
                }
                $Typ = $Nachricht['type'] ?? '';
                if ($Typ === 'PLUGIN_STATE_REQUEST') {
                    $this->senden($Sock, ['id' => (string) ($Nachricht['id'] ?? self::uuid()), 'pluginId' => self::PLUGIN_ID,
                        'type' => 'PLUGIN_STATE_RESPONSE', 'body' => ['pluginReadinessStatus' => 'READY']]);
                    continue;
                }
                if ($Typ !== 'HMIP_SYSTEM_RESPONSE' || ($Nachricht['id'] ?? '') !== $Id) {
                    continue;
                }
                $Body = is_array($Nachricht['body'] ?? null) ? $Nachricht['body'] : [];
                $Code = (int) ($Body['code'] ?? 0);
                if ($Code !== 200) {
                    $Fehler = is_array($Body['body'] ?? null) ? (string) ($Body['body']['errorCode'] ?? '') : '';
                    throw new DLDHcuFehler($Code, $Fehler);
                }
                $this->schliessen($Sock);
                return is_array($Body['body'] ?? null) ? $Body['body'] : [];
            }
            throw new RuntimeException('HCU antwortet nicht');
        } finally {
            if (is_resource($Sock)) {
                fclose($Sock);
            }
        }
    }

    /** @param resource $Sock */
    private function handshake($Sock, string $Token): void
    {
        $Schluessel = base64_encode(random_bytes(16));
        $this->schreiben($Sock, "GET / HTTP/1.1\r\nHost: {$this->Host}:{$this->PortWs}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $Schluessel\r\nSec-WebSocket-Version: 13\r\n"
            . 'authtoken: ' . $Token . "\r\nplugin-id: " . self::PLUGIN_ID . "\r\n\r\n");
        $Kopf = '';
        $Ende = microtime(true) + $this->Zeitlimit;
        while (!str_contains($Kopf, "\r\n\r\n")) {
            if (microtime(true) > $Ende || feof($Sock)) {
                throw new RuntimeException('HCU: keine Antwort auf den Verbindungsaufbau');
            }
            $Zeichen = fread($Sock, 1);
            if ($Zeichen === false || $Zeichen === '') {
                continue;
            }
            $Kopf .= $Zeichen;
            if (strlen($Kopf) > 16384) {
                throw new RuntimeException('HCU: ungültige Antwort');
            }
        }
        if (!preg_match('#^HTTP/1\.1 101#', $Kopf)) {
            preg_match('#^HTTP/1\.[01] (\d{3})#', $Kopf, $m);
            $Status = $m[1] ?? '?';
            throw new RuntimeException(in_array($Status, ['401', '403'], true)
                ? 'Die HCU lehnt den Zugang ab – bitte neu anmelden (neuen Aktivierungsschlüssel eintragen)'
                : 'HCU: Verbindungsaufbau abgelehnt (HTTP ' . $Status . ')');
        }
        $Erwartet = base64_encode(sha1($Schluessel . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $Kopf, $a) || $a[1] !== $Erwartet) {
            throw new RuntimeException('HCU: ungültige Bestätigung des Verbindungsaufbaus');
        }
    }

    /** @param resource $Sock */
    private function senden($Sock, array $Nachricht): void
    {
        $this->rahmen($Sock, 0x1, (string) json_encode($Nachricht, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param resource $Sock Rahmen vom Client: immer maskiert */
    private function rahmen($Sock, int $Opcode, string $Nutzdaten): void
    {
        $Laenge = strlen($Nutzdaten);
        $Kopf = chr(0x80 | $Opcode);
        if ($Laenge < 126) {
            $Kopf .= chr(0x80 | $Laenge);
        } elseif ($Laenge < 65536) {
            $Kopf .= chr(0x80 | 126) . pack('n', $Laenge);
        } else {
            $Kopf .= chr(0x80 | 127) . pack('J', $Laenge);
        }
        $Maske = random_bytes(4);
        $Maskiert = $Nutzdaten ^ str_repeat($Maske, intdiv($Laenge, 4) + 1);
        $this->schreiben($Sock, $Kopf . $Maske . substr($Maskiert, 0, $Laenge));
    }

    /**
     * Nächste Textnachricht (Fragmente zusammengesetzt); Ping wird beantwortet; null = Verbindung zu.
     *
     * @param resource $Sock
     */
    private function empfangen($Sock, float $Ende): ?string
    {
        $Text = '';
        while (true) {
            $Kopf = $this->lesen($Sock, 2, $Ende);
            if ($Kopf === null) {
                return null;
            }
            $Fin = (ord($Kopf[0]) & 0x80) !== 0;
            $Opcode = ord($Kopf[0]) & 0x0f;
            $Maskiert = (ord($Kopf[1]) & 0x80) !== 0;
            $Laenge = ord($Kopf[1]) & 0x7f;
            if ($Laenge === 126) {
                $Laenge = unpack('n', (string) $this->lesen($Sock, 2, $Ende))[1];
            } elseif ($Laenge === 127) {
                $Laenge = unpack('J', (string) $this->lesen($Sock, 8, $Ende))[1];
            }
            if ($Laenge > self::MAX_NACHRICHT || strlen($Text) + $Laenge > self::MAX_NACHRICHT) {
                throw new RuntimeException('Nachricht der HCU zu groß');
            }
            $Maske = $Maskiert ? (string) $this->lesen($Sock, 4, $Ende) : '';
            $Nutz = $Laenge > 0 ? $this->lesen($Sock, (int) $Laenge, $Ende) : '';
            if ($Nutz === null) {
                return null;
            }
            if ($Maskiert) {
                $Nutz = $Nutz ^ str_repeat($Maske, intdiv(strlen($Nutz), 4) + 1);
                $Nutz = substr($Nutz, 0, (int) $Laenge);
            }
            switch ($Opcode) {
                case 0x8: // Schließen
                    return null;
                case 0x9: // Ping → Pong
                    $this->rahmen($Sock, 0xA, $Nutz);
                    continue 2;
                case 0xA: // Pong
                    continue 2;
                case 0x1:
                case 0x0:
                    $Text .= $Nutz;
                    if ($Fin) {
                        return $Text;
                    }
                    continue 2;
                default:
                    continue 2;
            }
        }
    }

    /** @param resource $Sock */
    private function lesen($Sock, int $Anzahl, float $Ende): ?string
    {
        $Daten = '';
        while (strlen($Daten) < $Anzahl) {
            if (microtime(true) > $Ende) {
                throw new RuntimeException('HCU antwortet nicht');
            }
            if (feof($Sock)) {
                return null;
            }
            $Teil = fread($Sock, min(65536, $Anzahl - strlen($Daten)));
            if ($Teil === false) {
                return null;
            }
            $Daten .= $Teil;
        }
        return $Daten;
    }

    /** @param resource $Sock */
    private function schliessen($Sock): void
    {
        try {
            $this->rahmen($Sock, 0x8, pack('n', 1000));
        } catch (Throwable) {
            // Verbindung ohnehin zu
        }
    }

    // ------------------------------------------------------------ TLS mit gemerktem Zertifikat

    /** @return resource */
    private function oeffnen(int $Port)
    {
        $Kontext = stream_context_create(['ssl' => [
            // selbst ausgestelltes Zertifikat der HCU: Prüfung über den gemerkten Fingerabdruck (unten)
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $this->Host,
        ]]);
        $Ziel = 'ssl://' . $this->Host . ':' . $Port;
        $Sock = @stream_socket_client($Ziel, $Nr, $Text, $this->Zeitlimit, STREAM_CLIENT_CONNECT, $Kontext);
        if ($Sock === false) {
            throw new RuntimeException('HCU unter ' . $this->Host . ':' . $Port . ' nicht erreichbar (' . ($Text !== '' ? $Text : 'Fehler ' . $Nr) . ')');
        }
        stream_set_timeout($Sock, $this->Zeitlimit);
        $Zertifikat = stream_context_get_params($Sock)['options']['ssl']['peer_certificate'] ?? null;
        $Abdruck = $Zertifikat ? (string) openssl_x509_fingerprint($Zertifikat, 'sha256') : '';
        $Schluessel = $this->Host . ':' . $Port;
        $Bekannt = (string) ($this->Fingerabdruecke[$Schluessel] ?? '');
        if ($Abdruck === '') {
            fclose($Sock);
            throw new RuntimeException('HCU: kein Zertifikat erhalten');
        }
        if ($Bekannt === '') {
            $this->Fingerabdruecke[$Schluessel] = $Abdruck;
        } elseif (!hash_equals($Bekannt, $Abdruck)) {
            fclose($Sock);
            throw new RuntimeException('HCU: Zertifikat hat sich geändert – Verbindung abgelehnt. Wurde die HCU getauscht oder zurückgesetzt, im Modul „Zertifikat neu lernen“ wählen.');
        }
        return $Sock;
    }

    /** @param resource $Sock */
    private function schreiben($Sock, string $Daten): void
    {
        $Rest = $Daten;
        while ($Rest !== '') {
            $Geschrieben = fwrite($Sock, $Rest);
            if ($Geschrieben === false || $Geschrieben === 0) {
                throw new RuntimeException('Verbindung zur HCU unterbrochen');
            }
            $Rest = substr($Rest, $Geschrieben);
        }
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

/** Fehlerantwort der HCU mit ihrem Code (z. B. INVALID_REQUEST) */
final class DLDHcuFehler extends RuntimeException
{
    public function __construct(public readonly int $Status, public readonly string $Fehlercode)
    {
        parent::__construct('HCU meldet Fehler ' . $Status . ($Fehlercode !== '' ? ' (' . $Fehlercode . ')' : ''));
    }
}
