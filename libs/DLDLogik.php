<?php

declare(strict_types=1);

/**
 * HmIP Türschloss – Logik ohne Symcon-Abhängigkeit (damit testbar)
 *
 * - Türschlösser (HmIP-DLD) im Systemzustand der HCU finden
 * - Freigabe über die Zugangsprofile (ACCESS_AUTHORIZATION_PROFILE) prüfen
 * - passende PIN je Zugangsprofil wählen
 * - Fehlercodes der HCU verständlich machen
 * - GUIDs der Module und des Datenflusses
 *
 * Copyright (c) 2026 Armin Frohwerk · MIT-Lizenz
 * SPDX-License-Identifier: MIT
 */
final class DLDLogik
{
    /** Module und Datenfluss (Zugang zur HCU ↔ Türschloss/Konfigurator) */
    public const GUID_HCU = '{1E261F5E-40A1-4744-A69E-661E33CA2496}';
    public const GUID_SCHLOSS = '{C1931337-4B5F-4B19-9680-20B23BBF691A}';
    public const GUID_KONFIGURATOR = '{1EBC9F8E-094F-4551-84D7-9CB801916F1F}';
    public const DATEN_ZUR_HCU = '{E78A3515-A873-4BEB-A2F9-8402AFCF495D}';
    public const DATEN_VON_HCU = '{ED7D4F7F-B375-4BF9-8CA2-32B2EA73BBFC}';

    /** Zustände des Schlosses: Wert der Variable ↔ lockState der HCU */
    public const ZUSTAENDE = [0 => 'LOCKED', 1 => 'UNLOCKED', 2 => 'OPEN'];

    /** Motor: Wert der Variable ↔ motorState der HCU */
    public const MOTOR = [0 => 'STOPPED', 1 => 'OPENING', 2 => 'CLOSING', 3 => 'JAMMED'];

    /** lockState → 0/1/2, unbekannt → null */
    public static function zustandWert(mixed $LockState): ?int
    {
        $Wert = array_search($LockState, self::ZUSTAENDE, true);
        return $Wert === false ? null : (int) $Wert;
    }

    /** motorState → 0…3, unbekannt → null */
    public static function motorWert(mixed $MotorState): ?int
    {
        $Wert = array_search($MotorState, self::MOTOR, true);
        return $Wert === false ? null : (int) $Wert;
    }

    /**
     * Alle Türschlösser im Systemzustand mit Zustand und Freigabe.
     *
     * @return array<int, array{id: string, name: string, kanal: int, zustand: ?int, motor: ?int, freigabe: string, profile: list<string>, profil: string}>
     */
    public static function schloesser(array $State, string $ClientId): array
    {
        $Liste = [];
        foreach (($State['devices'] ?? []) as $Geraet) {
            if (!is_array($Geraet) || !isset($Geraet['id'])) {
                continue;
            }
            $Kanal = null;
            foreach (($Geraet['functionalChannels'] ?? []) as $Index => $Ch) {
                if (is_array($Ch) && ($Ch['functionalChannelType'] ?? '') === 'DOOR_LOCK_CHANNEL') {
                    $Kanal = ['index' => (int) ($Ch['index'] ?? $Index), 'daten' => $Ch];
                    break;
                }
            }
            if ($Kanal === null) {
                continue;
            }
            $Zugang = self::zugang($State, $Geraet, $ClientId);
            $Liste[] = [
                'id' => (string) $Geraet['id'],
                'name' => (string) (($Geraet['label'] ?? '') !== '' ? $Geraet['label'] : ($Geraet['modelType'] ?? $Geraet['id'])),
                'kanal' => $Kanal['index'],
                'zustand' => self::zustandWert($Kanal['daten']['lockState'] ?? null),
                'motor' => self::motorWert($Kanal['daten']['motorState'] ?? null),
                'freigabe' => $Zugang['status'],
                'profile' => $Zugang['profile'],
                'profil' => $Zugang['profile'][0] ?? ($Zugang['profil'] ?? ''),
            ];
        }
        usort($Liste, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $Liste;
    }

    /**
     * Darf dieser Zugang (clientId) das Schloss schalten?
     * Die HCU verlangt: Zugang und Schloss im selben Zugangsprofil, und das Profil hat eine PIN.
     *
     * @return array{status: string, profile: list<string>, profil?: string}
     *   status: ok (im Profil mit PIN) · nopin (im Profil, Profil ohne PIN) · none (in keinem Profil) · unknown
     */
    public static function zugang(array $State, array $Geraet, string $ClientId): array
    {
        $Profile = array_filter(
            $State['groups'] ?? [],
            static fn ($g): bool => is_array($g) && ($g['type'] ?? '') === 'ACCESS_AUTHORIZATION_PROFILE'
        );
        if ($Profile === []) {
            return ['status' => 'unknown', 'profile' => []];
        }
        $UeberKanaele = [];
        foreach (($Geraet['functionalChannels'] ?? []) as $Ch) {
            if (is_array($Ch) && ($Ch['functionalChannelType'] ?? '') === 'ACCESS_AUTHORIZATION_CHANNEL') {
                foreach ((array) ($Ch['groups'] ?? []) as $Gid) {
                    $UeberKanaele[(string) $Gid] = true;
                }
            }
        }
        $Meine = [];
        foreach ($Profile as $g) {
            $Gehoert = isset($UeberKanaele[(string) ($g['id'] ?? '')]);
            foreach ((array) ($g['channels'] ?? []) as $c) {
                if (is_array($c) && ($c['deviceId'] ?? '') === ($Geraet['id'] ?? null)) {
                    $Gehoert = true;
                }
            }
            $Clients = array_map('strval', (array) ($g['clientIds'] ?? []));
            if ($Gehoert && $ClientId !== '' && in_array($ClientId, $Clients, true)) {
                $Meine[] = $g;
            }
        }
        if ($Meine === []) {
            return ['status' => 'none', 'profile' => []];
        }
        $MitPin = array_values(array_filter($Meine, static fn (array $g): bool => ($g['authorizationPinAssigned'] ?? false) === true));
        if ($MitPin !== []) {
            return ['status' => 'ok', 'profile' => array_map(static fn (array $g): string => (string) ($g['label'] ?? $g['id']), $MitPin)];
        }
        return ['status' => 'nopin', 'profile' => [], 'profil' => (string) ($Meine[0]['label'] ?? $Meine[0]['id'])];
    }

    /**
     * PIN für ein Schloss: die des passenden Zugangsprofils aus der Liste, sonst die allgemeine.
     *
     * @param list<string> $Profile Profile des Schlosses mit Freigabe
     * @param array<int, array{Profil?: string, PIN?: string}> $PinListe Eintragungen aus dem Formular
     */
    public static function pinFuer(array $Profile, array $PinListe, string $Allgemein): string
    {
        $Pins = [];
        foreach ($PinListe as $Zeile) {
            $Name = mb_strtolower(trim((string) ($Zeile['Profil'] ?? '')));
            $Pin = trim((string) ($Zeile['PIN'] ?? ''));
            if ($Name !== '' && $Pin !== '') {
                $Pins[$Name] = $Pin;
            }
        }
        foreach ($Profile as $Profil) {
            $Pin = $Pins[mb_strtolower(trim($Profil))] ?? '';
            if ($Pin !== '') {
                return $Pin;
            }
        }
        return trim($Allgemein);
    }

    /** Text für die Variable „Freigabe“ */
    public static function freigabeText(string $Status, string $Profil, bool $PinVorhanden): string
    {
        switch ($Status) {
            case 'ok':
                return $PinVorhanden ? 'ja – Profil „' . $Profil . '“' : 'ja – Profil „' . $Profil . '“, aber im Modul fehlt die PIN';
            case 'nopin':
                return 'Profil „' . $Profil . '“ hat keine PIN – in der App eine PIN vergeben und hier eintragen';
            case 'none':
                return 'nein – in der Homematic-IP-App den Benutzer dieses Moduls einem Zugangsprofil mit dem Schloss hinzufügen';
            default:
                return 'keine Angabe der HCU';
        }
    }

    /** Verständlicher Text zu einem Fehlercode der HCU beim Schalten des Schlosses */
    public static function fehlerText(string $Code, string $Name): string
    {
        switch ($Code) {
            case 'INVALID_REQUEST':
                return '„' . $Name . '“: Die HCU lehnt den Befehl ab (INVALID_REQUEST). Meist fehlt die PIN des Zugangsprofils oder das Modul gehört nicht zum Profil – siehe Variable „Freigabe“.';
            case 'INVALID_AUTHORIZATION_PIN':
                return '„' . $Name . '“: PIN falsch (INVALID_AUTHORIZATION_PIN). Die PIN des Zugangsprofils im Modul eintragen.';
            case 'CLIENT_ACCESS_DENIED':
            case 'CLIENT_INVALID_AUTHORIZATION':
            case 'INVALID_AUTHORIZATION':
                return '„' . $Name . '“: Das Modul ist für dieses Schloss nicht freigegeben (' . $Code . '). In der Homematic-IP-App unter Zutrittsberechtigungen den Benutzer des Moduls dem Profil mit dem Schloss hinzufügen.';
            case 'FEATURE_NOT_SUPPORTED':
                return '„' . $Name . '“: Das Gerät unterstützt diesen Befehl nicht (FEATURE_NOT_SUPPORTED).';
            case 'UNKNOWN_DEVICE':
            case 'UNKNOWN_CHANNEL':
                return '„' . $Name . '“: Die HCU kennt das Gerät bzw. den Kanal nicht (' . $Code . ').';
            case 'LIVE_OTAU_IN_PROGRESS':
            case 'DATA_TRANSFER_IN_PROGRESS':
                return '„' . $Name . '“: Das Gerät wird gerade aktualisiert – später erneut versuchen (' . $Code . ').';
            default:
                return '„' . $Name . '“: Die HCU meldet einen Fehler' . ($Code !== '' ? ' (' . $Code . ')' : '') . '.';
        }
    }

    /** Ident einer Variable aus der Gerätekennung (SGTIN), nur Buchstaben und Ziffern */
    public static function ident(string $Art, string $GeraetId): string
    {
        return $Art . preg_replace('/[^A-Za-z0-9]/', '', $GeraetId);
    }
}
