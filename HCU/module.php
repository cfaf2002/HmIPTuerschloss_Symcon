<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DLDLogik.php';
require_once __DIR__ . '/../libs/DLDVerbindung.php';

/**
 * HmIP Türschloss HCU – Zugang zur Home Control Unit (I/O)
 * Ein Zugang (Benutzer „Symcon Türschloss“ in der App) für alle Türschlösser: meldet sich mit dem
 * Aktivierungsschlüssel an, fragt den Systemzustand ab (eine Abfrage für alle Schlösser) und verteilt ihn an
 * die Türschloss-Instanzen. Schaltbefehle der Türschlösser schickt er mit deren PIN an die HCU.
 *
 * Copyright (c) 2026 Armin Frohwerk · MIT-Lizenz
 * SPDX-License-Identifier: MIT
 */
class HmIPTuerschlossHCU extends IPSModuleStrict
{
    private const ST_AKTIV = 102;
    private const ST_INAKTIV = 104;
    private const ST_HINWEIS = 201;
    private const ST_ADRESSE = 202;
    private const ST_NICHT_ANGEMELDET = 203;
    private const ST_ANMELDUNG = 204;
    private const ST_ERREICHBAR = 205;

    /** Nach einem Befehl den neuen Zustand holen (Motor braucht einige Sekunden) */
    private const NACHLADEN_MS = 6000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('HinweisBestaetigt', false);
        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Aktivierungsschluessel', '');
        $this->RegisterPropertyInteger('Intervall', 60);
        $this->RegisterPropertyInteger('PortAuth', 6969);
        $this->RegisterPropertyInteger('PortWs', 9001);

        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeString('ClientId', '');
        $this->RegisterAttributeString('BenutzterSchluessel', '');
        $this->RegisterAttributeString('Fingerabdruecke', '{}');
        $this->RegisterAttributeString('Schloesser', '[]');
        $this->RegisterAttributeString('LetzteAbfrage', '');

        $this->RegisterTimer('Aktualisieren', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "Aktualisieren", true);');
        $this->RegisterTimer('Nachladen', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "Nachladen", true);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('Aktualisieren', 0);
        $this->SetTimerInterval('Nachladen', 0);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if (!$this->Pruefen()) {
            $this->AnKinder(false);
            return;
        }

        // Neuer Aktivierungsschlüssel eingetragen (oder noch kein Zugang): jetzt bei der HCU anmelden
        $Schluessel = trim($this->ReadPropertyString('Aktivierungsschluessel'));
        if ($Schluessel !== '' && ($Schluessel !== $this->ReadAttributeString('BenutzterSchluessel') || $this->ReadAttributeString('Token') === '')) {
            if (!$this->Anmelden($Schluessel)) {
                $this->AnKinder(false);
                return;
            }
        }
        if ($this->ReadAttributeString('Token') === '') {
            $this->SetStatus(self::ST_NICHT_ANGEMELDET);
            $this->AnKinder(false);
            return;
        }

        $this->AktualisierenRun();
        $Intervall = $this->ReadPropertyInteger('Intervall');
        if ($Intervall > 0) {
            $this->SetTimerInterval('Aktualisieren', max(15, $Intervall) * 1000);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->UnregisterMessage(0, IPS_KERNELSTARTED);
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Aktualisieren':
                $this->AktualisierenRun();
                return;
            case 'Nachladen':
                $this->SetTimerInterval('Nachladen', 0);
                $this->AktualisierenRun();
                return;
            case 'Abfragen':
                echo $this->AktualisierenRun() ? count($this->Schloesser()) . ' Türschloss/Türschlösser gefunden.' : 'Abfrage fehlgeschlagen – Status und Meldungen prüfen.';
                $this->ReloadForm();
                return;
            case 'NeuAnmelden':
                $Schluessel = trim($this->ReadPropertyString('Aktivierungsschluessel'));
                if ($Schluessel === '') {
                    echo 'Bitte zuerst in HCUweb einen neuen Aktivierungsschlüssel erzeugen, eintragen und „Übernehmen“ klicken.';
                    return;
                }
                if ($this->Anmelden($Schluessel)) {
                    $this->AktualisierenRun();
                    echo 'Bei der HCU angemeldet.';
                }
                $this->ReloadForm();
                return;
            case 'ZertifikatNeu':
                $this->WriteAttributeString('Fingerabdruecke', '{}');
                $this->AktualisierenRun();
                echo 'Das Zertifikat der HCU wird bei dieser Verbindung neu gemerkt.';
                $this->ReloadForm();
                return;
            case 'Uebernehmen':
                // Umzug aus Version 1.0: den bestehenden Zugang übernehmen (nur wenn hier noch keiner ist)
                $this->ZugangUebernehmen((string) $Value);
                return;
        }
        throw new Exception('Ungültiger Ident: ' . $Ident);
    }

    /** Anfragen der Türschlösser und des Konfigurators */
    public function ForwardData(string $JSONString): string
    {
        $Daten = json_decode($JSONString, true);
        if (!is_array($Daten)) {
            return '';
        }
        switch ($Daten['Funktion'] ?? '') {
            case 'Liste':
                return $this->Antwort(['Bereit' => $this->Bereit(), 'Schloesser' => $this->Schloesser()]);
            case 'Aktualisieren':
                $Ok = $this->AktualisierenRun();
                return $this->Antwort(['Ok' => $Ok]);
            case 'Schalten':
                $Fehler = $this->SchaltenRun((string) ($Daten['Schloss'] ?? ''), (int) ($Daten['Zustand'] ?? -1), (string) ($Daten['Pin'] ?? ''));
                return $this->Antwort(['Ok' => $Fehler === '', 'Fehler' => $Fehler]);
        }
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $Instanzen = [];
        foreach (IPS_GetInstanceListByModuleID(DLDLogik::GUID_SCHLOSS) as $Id) {
            if (IPS_GetInstance($Id)['ConnectionID'] === $this->InstanceID) {
                $Instanzen[(string) IPS_GetProperty($Id, 'Schloss')][] = '#' . $Id;
            }
        }
        $Zeilen = [];
        foreach ($this->Schloesser() as $s) {
            $Zeilen[] = [
                'Name' => $s['name'],
                'Zustand' => ['Verriegelt', 'Entriegelt', 'Offen'][$s['zustand'] ?? -1] ?? '–',
                'Freigabe' => DLDLogik::freigabeText($s['freigabe'], $s['profil'], true),
                'Instanz' => implode(', ', $Instanzen[$s['id']] ?? []) ?: '– (über den Konfigurator anlegen)',
            ];
        }
        $ClientId = $this->ReadAttributeString('ClientId');
        $Info = $this->ReadAttributeString('Token') === ''
            ? 'Noch nicht bei der HCU angemeldet.'
            : 'Angemeldet (Benutzer in der App: „Symcon Türschloss“)' . ($this->ReadAttributeString('LetzteAbfrage') !== '' ? ' · letzte Abfrage ' . $this->ReadAttributeString('LetzteAbfrage') : '');
        foreach ($Form['actions'] as &$Element) {
            if (($Element['name'] ?? '') === 'Schlossliste') {
                $Element['values'] = $Zeilen;
            }
            if (($Element['name'] ?? '') === 'Anmeldeinfo') {
                $Element['caption'] = $Info . ($ClientId !== '' ? ' · Kennung ' . substr($ClientId, 0, 8) . '…' : '');
            }
        }
        return (string) json_encode($Form);
    }

    // ------------------------------------------------------------ intern

    /** Liefert '' bei Erfolg, sonst den Fehlertext. PIN und Token erscheinen nirgends. */
    private function SchaltenRun(string $Id, int $Zustand, string $Pin): string
    {
        $Schloss = null;
        foreach ($this->Schloesser() as $s) {
            if ($s['id'] === $Id) {
                $Schloss = $s;
            }
        }
        if ($Schloss === null) {
            return 'Die HCU kennt kein Türschloss mit der Kennung „' . $Id . '“.';
        }
        if (!isset(DLDLogik::ZUSTAENDE[$Zustand])) {
            return 'Ungültiger Zustand ' . $Zustand . ' (0 = verriegeln, 1 = entriegeln, 2 = öffnen)';
        }
        // Auch nach einer gescheiterten Abfrage (HCU kurz weg) versuchen – der Befehl zeigt dann selbst, ob sie erreichbar ist
        if ($this->ReadAttributeString('Token') === '' || !in_array($this->GetStatus(), [self::ST_AKTIV, self::ST_ERREICHBAR], true)) {
            return 'Der Zugang zur HCU ist nicht bereit (Status ' . $this->GetStatus() . ').';
        }
        if (trim($Pin) === '') {
            return '„' . $Schloss['name'] . '“: Für Türschlösser verlangt die HCU die PIN des Zugangsprofils.';
        }
        $this->SendDebug('Schalten', $Schloss['name'] . ' → ' . DLDLogik::ZUSTAENDE[$Zustand] . ' (Kanal ' . $Schloss['kanal'] . ')', 0);
        try {
            $this->MitVerbindung(function (DLDVerbindung $Hcu, string $Token) use ($Schloss, $Zustand, $Pin): void {
                $Hcu->anfrage($Token, '/hmip/device/control/setLockState', [
                    'deviceId' => $Schloss['id'],
                    'channelIndex' => $Schloss['kanal'],
                    'targetLockState' => DLDLogik::ZUSTAENDE[$Zustand],
                    'authorizationPin' => trim($Pin),
                ]);
            });
        } catch (DLDHcuFehler $e) {
            return DLDLogik::fehlerText($e->Fehlercode, $Schloss['name']);
        } catch (Throwable $e) {
            return '„' . $Schloss['name'] . '“: ' . $e->getMessage();
        }
        $this->SetTimerInterval('Nachladen', self::NACHLADEN_MS);
        return '';
    }

    /** Systemzustand holen und an die Türschlösser verteilen. */
    private function AktualisierenRun(): bool
    {
        if ($this->ReadAttributeString('Token') === '' || !$this->ReadPropertyBoolean('Aktiv') || !$this->ReadPropertyBoolean('HinweisBestaetigt')) {
            return false;
        }
        try {
            $State = $this->MitVerbindung(fn (DLDVerbindung $Hcu, string $Token): array => $Hcu->anfrage($Token, '/hmip/home/getSystemState', [], 60));
        } catch (Throwable $e) {
            $this->SetStatus(str_contains($e->getMessage(), 'lehnt den Zugang ab') ? self::ST_ANMELDUNG : self::ST_ERREICHBAR);
            $this->SendDebug('Abfrage', $e->getMessage(), 0);
            $this->AnKinder(false);
            return false;
        }
        $Liste = DLDLogik::schloesser($State, $this->ReadAttributeString('ClientId'));
        $Neu = (string) json_encode($Liste, JSON_UNESCAPED_UNICODE);
        if ($Neu !== $this->ReadAttributeString('Schloesser')) {
            $this->WriteAttributeString('Schloesser', $Neu);
        }
        $this->WriteAttributeString('LetzteAbfrage', date('d.m.Y H:i:s'));
        if ($this->GetStatus() !== self::ST_AKTIV) {
            $this->SetStatus(self::ST_AKTIV);
        }
        $this->SendDebug('Abfrage', count($Liste) . ' Türschloss/Türschlösser gefunden', 0);
        $this->AnKinder(true);
        return true;
    }

    /** Zustand an alle verbundenen Türschlösser */
    private function AnKinder(bool $Bereit): void
    {
        $this->SendDataToChildren((string) json_encode([
            'DataID' => DLDLogik::DATEN_VON_HCU,
            'Bereit' => $Bereit,
            'Schloesser' => $this->Schloesser(),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function Antwort(array $Daten): string
    {
        return (string) json_encode($Daten, JSON_UNESCAPED_UNICODE);
    }

    private function Bereit(): bool
    {
        return $this->GetStatus() === self::ST_AKTIV && $this->ReadAttributeString('Token') !== '';
    }

    private function Schloesser(): array
    {
        $Liste = json_decode($this->ReadAttributeString('Schloesser'), true);
        return is_array($Liste) ? $Liste : [];
    }

    private function ZugangUebernehmen(string $Json): void
    {
        $Alt = json_decode($Json, true);
        if (!is_array($Alt) || $this->ReadAttributeString('Token') !== '' || (string) ($Alt['Token'] ?? '') === '') {
            return;
        }
        $this->WriteAttributeString('Token', (string) $Alt['Token']);
        $this->WriteAttributeString('ClientId', (string) ($Alt['ClientId'] ?? ''));
        $this->WriteAttributeString('BenutzterSchluessel', (string) ($Alt['BenutzterSchluessel'] ?? ''));
        $this->WriteAttributeString('Fingerabdruecke', (string) json_encode(is_array($Alt['Fingerabdruecke'] ?? null) ? $Alt['Fingerabdruecke'] : []));
        $this->WriteAttributeString('Schloesser', (string) json_encode(is_array($Alt['Schloesser'] ?? null) ? $Alt['Schloesser'] : [], JSON_UNESCAPED_UNICODE));
        $this->LogMessage('Zugang zur HCU aus der bisherigen Türschloss-Instanz übernommen', KL_NOTIFY);
    }

    private function Anmelden(string $Schluessel): bool
    {
        try {
            $Ergebnis = $this->MitVerbindung(
                fn (DLDVerbindung $Hcu): array => $Hcu->registrieren($Schluessel, 'Symcon Türschloss'),
                false
            );
        } catch (Throwable $e) {
            $this->SetStatus(self::ST_ANMELDUNG);
            $this->Meldung('Anmeldung bei der HCU fehlgeschlagen: ' . $e->getMessage() . ' – Aktivierungsschlüssel gilt nur kurz und nur einmal; ggf. neuen erzeugen.');
            return false;
        }
        $this->WriteAttributeString('Token', $Ergebnis['token']);
        $this->WriteAttributeString('ClientId', $Ergebnis['clientId']);
        $this->WriteAttributeString('BenutzterSchluessel', $Schluessel);
        $this->LogMessage('Bei der HCU angemeldet', KL_NOTIFY);
        return true;
    }

    /**
     * Verbindung zur HCU für eine Aktion, nacheinander (Semaphore), gemerkte Zertifikate werden gespeichert.
     */
    private function MitVerbindung(callable $Aktion, bool $MitToken = true): mixed
    {
        $Sperre = 'DLD_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($Sperre, 70000)) {
            throw new RuntimeException('HCU ist gerade beschäftigt – bitte gleich noch einmal versuchen');
        }
        try {
            $Hcu = new DLDVerbindung(trim($this->ReadPropertyString('Host')), $this->ReadPropertyInteger('PortAuth'), $this->ReadPropertyInteger('PortWs'));
            $Bekannt = json_decode($this->ReadAttributeString('Fingerabdruecke'), true);
            $Hcu->Fingerabdruecke = is_array($Bekannt) ? $Bekannt : [];
            try {
                return $MitToken ? $Aktion($Hcu, $this->ReadAttributeString('Token')) : $Aktion($Hcu);
            } finally {
                if ($Hcu->Fingerabdruecke !== $Bekannt) {
                    $this->WriteAttributeString('Fingerabdruecke', (string) json_encode($Hcu->Fingerabdruecke));
                }
            }
        } finally {
            IPS_SemaphoreLeave($Sperre);
        }
    }

    private function Pruefen(): bool
    {
        if (!$this->ReadPropertyBoolean('HinweisBestaetigt')) {
            $this->SetStatus(self::ST_HINWEIS);
            return false;
        }
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            $this->SetStatus(self::ST_INAKTIV);
            return false;
        }
        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(self::ST_ADRESSE);
            return false;
        }
        return true;
    }

    /** Ins Meldungsfenster (ohne PIN oder Token) */
    private function Meldung(string $Text): void
    {
        $this->LogMessage($Text, KL_WARNING);
        $this->SendDebug('Meldung', $Text, 0);
    }
}
