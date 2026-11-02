<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DLDLogik.php';
require_once __DIR__ . '/../libs/DLDVerbindung.php';

/**
 * HmIP Türschloss
 * Schaltet Homematic-IP-Türschlossantriebe (HmIP-DLD) über die Connect API der Home Control Unit (HCU):
 * Verriegeln, Entriegeln, Öffnen – mit der PIN des Zugangsprofils, auch für mehrere Schlösser mit
 * verschiedenen Profilen. Zeigt Zustand, Motor und ob das Modul in der HCU freigegeben ist.
 *
 * Läuft neben dem Symcon-Modul „HomeMatic HCU“ mit einem eigenen Zugang (eigener Benutzer in der App).
 *
 * Autor: Armin Frohwerk
 */
class HmIPTuerschloss extends IPSModuleStrict
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
        $this->RegisterPropertyString('PIN', '');
        $this->RegisterPropertyString('PinsJeProfil', '[]');
        $this->RegisterPropertyInteger('Intervall', 60);
        $this->RegisterPropertyBoolean('OeffnenErlauben', true);
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
            return;
        }

        // Neuer Aktivierungsschlüssel eingetragen (oder noch kein Zugang): jetzt bei der HCU anmelden
        $Schluessel = trim($this->ReadPropertyString('Aktivierungsschluessel'));
        if ($Schluessel !== '' && ($Schluessel !== $this->ReadAttributeString('BenutzterSchluessel') || $this->ReadAttributeString('Token') === '')) {
            if (!$this->Anmelden($Schluessel)) {
                return;
            }
        }
        if ($this->ReadAttributeString('Token') === '') {
            $this->SetStatus(self::ST_NICHT_ANGEMELDET);
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
        }
        // Schloss in der Visualisierung bedient
        if (str_starts_with($Ident, 'Z')) {
            foreach ($this->Schloesser() as $Schloss) {
                if (DLDLogik::ident('Z', $Schloss['id']) === $Ident) {
                    $Fehler = $this->SchaltenRun($Schloss, (int) $Value);
                    if ($Fehler !== '') {
                        echo $Fehler;
                    }
                    return;
                }
            }
        }
        throw new Exception('Ungültiger Ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $Zeilen = [];
        foreach ($this->Schloesser() as $s) {
            $Zeilen[] = [
                'Name' => $s['name'],
                'Zustand' => ['Verriegelt', 'Entriegelt', 'Offen'][$s['zustand'] ?? -1] ?? '–',
                'Freigabe' => DLDLogik::freigabeText($s['freigabe'], $s['profil'], $this->PinFuer($s) !== ''),
                'Kennung' => $s['id'],
            ];
        }
        $ClientId = $this->ReadAttributeString('ClientId');
        $Info = $this->ReadAttributeString('Token') === ''
            ? 'Noch nicht bei der HCU angemeldet.'
            : 'Angemeldet (Benutzer des Moduls in der App: „Symcon Türschloss“)' . ($this->ReadAttributeString('LetzteAbfrage') !== '' ? ' · letzte Abfrage ' . $this->ReadAttributeString('LetzteAbfrage') : '');
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

    // ------------------------------------------------------------ Befehle (PHP)

    /** Zustand aller Schlösser jetzt von der HCU holen. */
    public function Aktualisieren(): bool
    {
        return (bool) $this->AktualisierenRun();
    }

    /** Schloss verriegeln. $Schloss: Name oder Gerätekennung (SGTIN). */
    public function Verriegeln(string $Schloss): bool
    {
        return $this->Setzen($Schloss, 0);
    }

    /** Schloss entriegeln (Tür bleibt zu, Falle nicht gezogen). */
    public function Entriegeln(string $Schloss): bool
    {
        return $this->Setzen($Schloss, 1);
    }

    /** Schloss öffnen: entriegeln und Falle ziehen. */
    public function Oeffnen(string $Schloss): bool
    {
        return $this->Setzen($Schloss, 2);
    }

    /** Zustand setzen: 0 = verriegeln, 1 = entriegeln, 2 = öffnen. */
    public function SetzeZustand(string $Schloss, int $Zustand): bool
    {
        return $this->Setzen($Schloss, $Zustand);
    }

    /** Alle gefundenen Schlösser als JSON (Name, Kennung, Zustand, Motor, Freigabe, Profile). */
    public function Schlossliste(): string
    {
        return (string) json_encode($this->Schloesser(), JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------ intern

    private function Setzen(string $Name, int $Zustand): bool
    {
        $Schloss = $this->Finden($Name);
        if ($Schloss === null) {
            $this->Meldung('Kein Türschloss „' . $Name . '“ gefunden. Mit DLD_Schlossliste() sehen, welche es gibt.');
            return false;
        }
        $Fehler = $this->SchaltenRun($Schloss, $Zustand);
        if ($Fehler !== '') {
            trigger_error($Fehler, E_USER_WARNING);
            return false;
        }
        return true;
    }

    /** Liefert '' bei Erfolg, sonst den Fehlertext. */
    private function SchaltenRun(array $Schloss, int $Zustand): string
    {
        if (!isset(DLDLogik::ZUSTAENDE[$Zustand])) {
            return 'Ungültiger Zustand ' . $Zustand . ' (0 = verriegeln, 1 = entriegeln, 2 = öffnen)';
        }
        if ($Zustand === 2 && !$this->ReadPropertyBoolean('OeffnenErlauben')) {
            return '„' . $Schloss['name'] . '“: Öffnen ist in den Einstellungen des Moduls ausgeschaltet.';
        }
        if ($this->GetStatus() !== self::ST_AKTIV && $this->GetStatus() !== self::ST_ERREICHBAR) {
            return 'Das Modul ist nicht bereit (Status ' . $this->GetStatus() . ') – Einstellungen prüfen.';
        }
        $Pin = $this->PinFuer($Schloss);
        if ($Pin === '') {
            // Ohne PIN lehnt die HCU jeden Schlossbefehl ab (INVALID_REQUEST) – gleich sagen, was fehlt
            $Text = $Schloss['freigabe'] === 'none'
                ? '„' . $Schloss['name'] . '“: Das Modul gehört zu keinem Zugangsprofil dieses Schlosses. In der Homematic-IP-App unter Zutrittsberechtigungen ein Profil mit dem Schloss öffnen, den Benutzer „Symcon Türschloss“ hinzufügen, eine PIN vergeben und sie hier eintragen.'
                : '„' . $Schloss['name'] . '“: Für Türschlösser verlangt die HCU die PIN des Zugangsprofils' . ($Schloss['profil'] !== '' ? ' „' . $Schloss['profil'] . '“' : '') . '. Bitte im Modul eintragen.';
            $this->Meldung($Text);
            return $Text;
        }
        $this->SendDebug('Schalten', $Schloss['name'] . ' → ' . DLDLogik::ZUSTAENDE[$Zustand] . ' (Kanal ' . $Schloss['kanal'] . ')', 0);
        try {
            $this->MitVerbindung(function (DLDVerbindung $Hcu, string $Token) use ($Schloss, $Zustand, $Pin): void {
                $Hcu->anfrage($Token, '/hmip/device/control/setLockState', [
                    'deviceId' => $Schloss['id'],
                    'channelIndex' => $Schloss['kanal'],
                    'targetLockState' => DLDLogik::ZUSTAENDE[$Zustand],
                    'authorizationPin' => $Pin,
                ]);
            });
        } catch (DLDHcuFehler $e) {
            $Text = DLDLogik::fehlerText($e->Fehlercode, $Schloss['name']);
            $this->Meldung($Text);
            return $Text;
        } catch (Throwable $e) {
            $Text = '„' . $Schloss['name'] . '“: ' . $e->getMessage();
            $this->Meldung($Text);
            return $Text;
        }
        // Gleich anzeigen, was gewünscht war; der echte Zustand kommt mit der nächsten Abfrage
        $this->Schreiben(DLDLogik::ident('Z', $Schloss['id']), $Zustand);
        $this->SetTimerInterval('Nachladen', self::NACHLADEN_MS);
        return '';
    }

    /** Systemzustand holen, Schlösser und Variablen abgleichen. */
    private function AktualisierenRun(): bool
    {
        if ($this->ReadAttributeString('Token') === '' || !$this->ReadPropertyBoolean('Aktiv')) {
            return false;
        }
        try {
            $State = $this->MitVerbindung(fn (DLDVerbindung $Hcu, string $Token): array => $Hcu->anfrage($Token, '/hmip/home/getSystemState', [], 60));
        } catch (Throwable $e) {
            $this->SetStatus(str_contains($e->getMessage(), 'lehnt den Zugang ab') ? self::ST_ANMELDUNG : self::ST_ERREICHBAR);
            $this->SendDebug('Abfrage', $e->getMessage(), 0);
            return false;
        }
        $Liste = DLDLogik::schloesser($State, $this->ReadAttributeString('ClientId'));
        $this->WriteAttributeString('Schloesser', (string) json_encode($Liste, JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeString('LetzteAbfrage', date('d.m.Y H:i:s'));
        $this->VariablenAbgleichen($Liste);
        $this->SetStatus(self::ST_AKTIV);
        $this->SendDebug('Abfrage', count($Liste) . ' Türschloss/Türschlösser gefunden', 0);
        return true;
    }

    private function VariablenAbgleichen(array $Liste): void
    {
        $Oeffnen = $this->ReadPropertyBoolean('OeffnenErlauben');
        $Optionen = [
            ['Value' => 0, 'Caption' => 'Verriegelt', 'IconActive' => true, 'IconValue' => 'lock', 'Color' => 0x34B36B],
            ['Value' => 1, 'Caption' => 'Entriegelt', 'IconActive' => true, 'IconValue' => 'lock-open', 'Color' => 0xE2A63B],
        ];
        if ($Oeffnen) {
            $Optionen[] = ['Value' => 2, 'Caption' => 'Öffnen', 'IconActive' => true, 'IconValue' => 'door-open', 'Color' => 0xE5484D];
        }
        $Motor = [];
        foreach (['Steht', 'Öffnet', 'Schließt', 'Blockiert'] as $Wert => $Text) {
            $Motor[] = ['Value' => $Wert, 'Caption' => $Text, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => $Wert === 3, 'ColorValue' => $Wert === 3 ? 0xE5484D : -1];
        }
        $Behalten = [];
        $Position = 10;
        foreach ($Liste as $s) {
            $Z = DLDLogik::ident('Z', $s['id']);
            $M = DLDLogik::ident('M', $s['id']);
            $F = DLDLogik::ident('F', $s['id']);
            $Behalten[$Z] = $Behalten[$M] = $Behalten[$F] = true;
            $this->MaintainVariable($Z, $s['name'], VARIABLETYPE_INTEGER, [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'ICON' => 'door-closed',
                'LAYOUT' => 1,
                'DISPLAY' => 2,
                'OPTIONS' => (string) json_encode($Optionen),
            ], $Position, true);
            $this->EnableAction($Z);
            $this->MaintainVariable($M, $s['name'] . ' · Motor', VARIABLETYPE_INTEGER, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON' => 'gear',
                'OPTIONS' => (string) json_encode($Motor),
            ], $Position + 1, true);
            $this->MaintainVariable($F, $s['name'] . ' · Freigabe', VARIABLETYPE_STRING, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON' => 'key',
            ], $Position + 2, true);
            if ($s['zustand'] !== null) {
                $this->Schreiben($Z, $s['zustand']);
            }
            if ($s['motor'] !== null) {
                $this->Schreiben($M, $s['motor']);
            }
            $this->Schreiben($F, DLDLogik::freigabeText($s['freigabe'], $s['profil'], $this->PinFuer($s) !== ''));
            $Position += 10;
        }
        // Schlösser, die es in der HCU nicht mehr gibt: eigene Variablen entfernen
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $Kind) {
            $Obj = IPS_GetObject($Kind);
            $Ident = (string) $Obj['ObjectIdent'];
            if ($Obj['ObjectType'] === OBJECTTYPE_VARIABLE && preg_match('/^[ZMF][A-Za-z0-9]{6,}$/', $Ident) && !isset($Behalten[$Ident])) {
                $this->UnregisterVariable($Ident);
            }
        }
    }

    /** Variable nur bei Änderung schreiben */
    private function Schreiben(string $Ident, mixed $Wert): void
    {
        $Id = @$this->GetIDForIdent($Ident);
        if ($Id === false || $Id === 0) {
            return;
        }
        if (GetValue($Id) !== $Wert) {
            $this->SetValue($Ident, $Wert);
        }
    }

    private function PinFuer(array $Schloss): string
    {
        $Liste = json_decode($this->ReadPropertyString('PinsJeProfil'), true);
        return DLDLogik::pinFuer((array) ($Schloss['profile'] ?? []), is_array($Liste) ? $Liste : [], $this->ReadPropertyString('PIN'));
    }

    private function Finden(string $Name): ?array
    {
        $Suche = mb_strtolower(trim($Name));
        foreach ($this->Schloesser() as $s) {
            if (mb_strtolower($s['name']) === $Suche || mb_strtolower($s['id']) === $Suche) {
                return $s;
            }
        }
        return null;
    }

    private function Schloesser(): array
    {
        $Liste = json_decode($this->ReadAttributeString('Schloesser'), true);
        return is_array($Liste) ? $Liste : [];
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
