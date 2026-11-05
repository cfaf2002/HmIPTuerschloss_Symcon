<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DLDLogik.php';

/**
 * HmIP Türschloss – ein Türschlossantrieb (HmIP-DLD) je Instanz
 * Verriegeln, Entriegeln, Öffnen mit der PIN des Zugangsprofils dieses Schlosses. Zeigt Zustand, Motor und ob
 * der Zugang in der HCU freigegeben ist. Die Verbindung zur HCU hält die übergeordnete Instanz
 * „HmIP Türschloss HCU“ – ein Zugang für alle Schlösser.
 *
 * Bis Version 1.0 bediente eine Instanz alle Schlösser. Beim ersten Start nach dem Update zieht sie um:
 * Zugang → neue Instanz „HmIP Türschloss HCU“, erstes Schloss bleibt hier, jedes weitere bekommt eine eigene
 * Instanz. Die Variablen ziehen mit (gleiche Objekt-IDs, Verknüpfungen und Ereignisse bleiben).
 *
 * Copyright (c) 2026 Armin Frohwerk · MIT-Lizenz
 * SPDX-License-Identifier: MIT
 */
class HmIPTuerschloss extends IPSModuleStrict
{
    private const ST_AKTIV = 102;
    private const ST_KEIN_SCHLOSS = 201;
    private const ST_NICHT_GEFUNDEN = 202;
    private const ST_ZUGANG = 203;
    private const ST_PIN = 204;

    /** Variablen-Idents aus Version 1.0 (Z/M/F + Gerätekennung) → neue Idents */
    private const ALT_NEU = ['Z' => 'Zustand', 'M' => 'Motor', 'F' => 'Freigabe'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Schloss', '');
        $this->RegisterPropertyString('PIN', '');
        $this->RegisterPropertyBoolean('OeffnenErlauben', true);
        $this->RegisterPropertyBoolean('Kachel', true);
        $this->RegisterPropertyInteger('TileTheme', 0);

        // Nur für den Umzug aus Version 1.0 (nicht im Formular)
        foreach (['HinweisBestaetigt' => false, 'Aktiv' => true] as $Name => $Standard) {
            $this->RegisterPropertyBoolean($Name, $Standard);
        }
        foreach (['Host' => '', 'Aktivierungsschluessel' => '', 'PinsJeProfil' => '[]'] as $Name => $Standard) {
            $this->RegisterPropertyString($Name, $Standard);
        }
        foreach (['Intervall' => 60, 'PortAuth' => 6969, 'PortWs' => 9001] as $Name => $Standard) {
            $this->RegisterPropertyInteger($Name, $Standard);
        }
        foreach (['Token' => '', 'ClientId' => '', 'BenutzterSchluessel' => '', 'Fingerabdruecke' => '{}', 'Schloesser' => '[]', 'LetzteAbfrage' => ''] as $Name => $Standard) {
            $this->RegisterAttributeString($Name, $Standard);
        }

        $this->RegisterAttributeString('Daten', '{}');
        $this->RegisterAttributeString('KachelDaten', '');
        $this->RegisterAttributeInteger('KachelAbfrage', 0);
        $this->RegisterAttributeInteger('Seit', 0);
        $this->RegisterAttributeInteger('Abfrage', 0);
        $this->RegisterAttributeString('Meldung', '');
        $this->RegisterAttributeInteger('MeldungZeit', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if ($this->ReadAttributeString('Token') !== '') {
            $this->Umziehen();
            return;
        }

        $this->SetVisualizationType($this->ReadPropertyBoolean('Kachel') ? 1 : 0);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->VariablenAnlegen();

        if (trim($this->ReadPropertyString('Schloss')) === '') {
            $this->StatusSetzen(self::ST_KEIN_SCHLOSS);
            $this->KachelSenden();
            return;
        }
        $this->VomZugangHolen();
        $this->KachelSenden();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->UnregisterMessage(0, IPS_KERNELSTARTED);
                $this->ApplyChanges();
                return;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->VomZugangHolen();
                return;
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Zustand':
                $Fehler = $this->SchaltenRun((int) $Value);
                if ($Fehler !== '') {
                    echo $Fehler;
                }
                return;
            case 'Abfragen':
                echo $this->AktualisierenRun() ? 'Zustand abgefragt.' : 'Abfrage fehlgeschlagen – Status des Zugangs zur HCU prüfen.';
                $this->ReloadForm();
                return;
        }
        throw new Exception('Ungültiger Ident: ' . $Ident);
    }

    /** Zustand vom Zugang zur HCU */
    public function ReceiveData(string $JSONString): string
    {
        $Daten = json_decode($JSONString, true);
        if (is_array($Daten)) {
            $this->Auswerten((bool) ($Daten['Bereit'] ?? false), is_array($Daten['Schloesser'] ?? null) ? $Daten['Schloesser'] : []);
        }
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $Gewaehlt = $this->ReadPropertyString('Schloss');
        $Optionen = [['caption' => '– bitte wählen –', 'value' => '']];
        $Liste = $this->Anfrage(['Funktion' => 'Liste']);
        foreach ((array) ($Liste['Schloesser'] ?? []) as $s) {
            $Optionen[] = ['caption' => $s['name'], 'value' => $s['id']];
        }
        if ($Gewaehlt !== '' && !in_array($Gewaehlt, array_column($Optionen, 'value'), true)) {
            $Optionen[] = ['caption' => $Gewaehlt . ' (in der HCU nicht gefunden)', 'value' => $Gewaehlt];
        }
        $Schloss = $this->Daten();
        $Info = $Schloss === []
            ? ($Liste === [] ? 'Kein Zugang zur HCU verbunden – oben unter „Übergeordnete Instanz“ den „HmIP Türschloss HCU“ wählen.' : '')
            : 'Freigabe: ' . DLDLogik::freigabeText($Schloss['freigabe'], $Schloss['profil'], trim($this->ReadPropertyString('PIN')) !== '');
        $this->FormAnpassen($Form['elements'], $Optionen, $Info);
        $this->FormAnpassen($Form['actions'], $Optionen, $Info);
        return (string) json_encode($Form);
    }

    /** HTML der Kachel mit den aktuellen Daten (wird von der Visualisierung einmal geladen) */
    public function GetVisualizationTile(): string
    {
        $Html = (string) file_get_contents(__DIR__ . '/tile.html');
        // JSON_HEX_* verhindert, dass Werte wie "</script>" das Skript der Kachel beenden
        $Json = (string) json_encode($this->KachelDaten(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', $Json, $Html);
    }

    // ------------------------------------------------------------ Befehle (PHP)

    /** Zustand jetzt von der HCU holen. */
    public function Aktualisieren(): bool
    {
        return $this->AktualisierenRun();
    }

    /** Schloss verriegeln. */
    public function Verriegeln(): bool
    {
        return $this->Setzen(0);
    }

    /** Schloss entriegeln (Tür bleibt zu, Falle nicht gezogen). */
    public function Entriegeln(): bool
    {
        return $this->Setzen(1);
    }

    /** Schloss öffnen: entriegeln und Falle ziehen. */
    public function Oeffnen(): bool
    {
        return $this->Setzen(2);
    }

    /** Zustand setzen: 0 = verriegeln, 1 = entriegeln, 2 = öffnen. */
    public function SetzeZustand(int $Zustand): bool
    {
        return $this->Setzen($Zustand);
    }

    // ------------------------------------------------------------ intern

    private function Setzen(int $Zustand): bool
    {
        $Fehler = $this->SchaltenRun($Zustand);
        if ($Fehler !== '') {
            trigger_error($Fehler, E_USER_WARNING);
            return false;
        }
        return true;
    }

    /** Liefert '' bei Erfolg, sonst den Fehlertext. */
    private function SchaltenRun(int $Zustand): string
    {
        $Schloss = $this->Daten();
        $Name = (string) ($Schloss['name'] ?? IPS_GetName($this->InstanceID));
        $Fehler = $this->PruefenSchalten($Zustand, $Schloss, $Name);
        if ($Fehler !== '') {
            $this->Meldung($Fehler, true);
            return $Fehler;
        }
        $Pin = trim($this->ReadPropertyString('PIN'));
        if ($Pin === '') {
            // Ohne PIN lehnt die HCU jeden Schlossbefehl ab (INVALID_REQUEST) – gleich sagen, was fehlt
            $Text = $Schloss['freigabe'] === 'none'
                ? '„' . $Name . '“: Der Benutzer „Symcon Türschloss“ gehört zu keinem Zugangsprofil dieses Schlosses. In der Homematic-IP-App unter Zutrittsberechtigungen ein Profil mit dem Schloss öffnen, „Symcon Türschloss“ hinzufügen, eine PIN vergeben und sie hier eintragen.'
                : '„' . $Name . '“: Für Türschlösser verlangt die HCU die PIN des Zugangsprofils' . ($Schloss['profil'] !== '' ? ' „' . $Schloss['profil'] . '“' : '') . '. Bitte in der Instanz eintragen.';
            $this->Meldung($Text, true);
            return $Text;
        }
        $this->SendDebug('Schalten', $Name . ' → ' . DLDLogik::ZUSTAENDE[$Zustand], 0);
        $Antwort = $this->Anfrage(['Funktion' => 'Schalten', 'Schloss' => $Schloss['id'], 'Zustand' => $Zustand, 'Pin' => $Pin]);
        if (($Antwort['Ok'] ?? false) !== true) {
            $Text = (string) ($Antwort['Fehler'] ?? '') ?: '„' . $Name . '“: Der Zugang zur HCU antwortet nicht.';
            $this->Meldung($Text, true);
            return $Text;
        }
        // Gleich anzeigen, was gewünscht war; der echte Zustand kommt nach einigen Sekunden vom Zugang
        $this->Schreiben('Zustand', $Zustand);
        $this->KachelSenden();
        return '';
    }

    private function AktualisierenRun(): bool
    {
        return ($this->Anfrage(['Funktion' => 'Aktualisieren'])['Ok'] ?? false) === true;
    }

    /** Aktuelle Liste beim Zugang abholen (nach Übernehmen, Verbinden, Trennen) */
    private function VomZugangHolen(): void
    {
        if (trim($this->ReadPropertyString('Schloss')) === '') {
            return;
        }
        $Liste = $this->Anfrage(['Funktion' => 'Liste']);
        $this->Auswerten((bool) ($Liste['Bereit'] ?? false), (array) ($Liste['Schloesser'] ?? []));
    }

    private function Auswerten(bool $Bereit, array $Liste): void
    {
        $Id = trim($this->ReadPropertyString('Schloss'));
        if ($Id === '') {
            $this->StatusSetzen(self::ST_KEIN_SCHLOSS);
            $this->KachelSenden();
            return;
        }
        $Schloss = null;
        foreach ($Liste as $s) {
            if (is_array($s) && ($s['id'] ?? '') === $Id) {
                $Schloss = $s;
            }
        }
        if (!$Bereit) {
            $this->StatusSetzen(self::ST_ZUGANG);
            if ($Schloss === null) {
                $this->KachelSenden();
                return;
            }
        } elseif ($Schloss === null) {
            $this->DatenSetzen([]);
            $this->StatusSetzen(self::ST_NICHT_GEFUNDEN);
            $this->KachelSenden();
            return;
        }
        $Vorher = $this->Daten();
        $this->DatenSetzen($Schloss);
        if ($Bereit) {
            $this->WriteAttributeInteger('Abfrage', time());
        }
        if ($Schloss['zustand'] !== null) {
            if (($Vorher['zustand'] ?? null) !== $Schloss['zustand'] || $this->ReadAttributeInteger('Seit') === 0) {
                $this->WriteAttributeInteger('Seit', time());
            }
            $this->Schreiben('Zustand', $Schloss['zustand']);
        }
        if ($Schloss['motor'] !== null) {
            $this->Schreiben('Motor', $Schloss['motor']);
        }
        $PinDa = trim($this->ReadPropertyString('PIN')) !== '';
        $this->Schreiben('Freigabe', DLDLogik::freigabeText($Schloss['freigabe'], $Schloss['profil'], $PinDa));
        if ($Bereit) {
            $this->StatusSetzen($PinDa ? self::ST_AKTIV : self::ST_PIN);
        }
        $this->KachelSenden();
    }

    private function VariablenAnlegen(): void
    {
        $Optionen = [
            ['Value' => 0, 'Caption' => 'Verriegelt', 'IconActive' => true, 'IconValue' => 'lock', 'Color' => 0x34B36B],
            ['Value' => 1, 'Caption' => 'Entriegelt', 'IconActive' => true, 'IconValue' => 'lock-open', 'Color' => 0xE2A63B],
        ];
        if ($this->ReadPropertyBoolean('OeffnenErlauben')) {
            $Optionen[] = ['Value' => 2, 'Caption' => 'Öffnen', 'IconActive' => true, 'IconValue' => 'door-open', 'Color' => 0xE5484D];
        }
        $Motor = [];
        // Jede Option mit Symbol und Farbe – sonst zeigt die Visualisierung nur die Zahl
        foreach ([['Steht', 'circle-pause', 0x8796A5], ['Öffnet', 'arrow-right-from-bracket', 0xE2A63B], ['Schließt', 'arrow-right-to-bracket', 0x34B36B], ['Blockiert', 'triangle-exclamation', 0xE5484D]] as $Wert => [$Text, $Icon, $Farbe]) {
            $Motor[] = ['Value' => $Wert, 'Caption' => $Text, 'IconActive' => true, 'IconValue' => $Icon, 'ColorActive' => true, 'ColorValue' => $Farbe];
        }
        $this->MaintainVariable('Zustand', 'Zustand', VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON' => 'door-closed',
            'LAYOUT' => 1,
            'DISPLAY' => 2,
            'OPTIONS' => (string) json_encode($Optionen),
        ], 10, true);
        $this->EnableAction('Zustand');
        $this->MaintainVariable('Motor', 'Motor', VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'gear',
            'OPTIONS' => (string) json_encode($Motor),
        ], 20, true);
        $this->MaintainVariable('Freigabe', 'Freigabe', VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'key',
        ], 30, true);
    }

    /**
     * Umzug aus Version 1.0: Zugang an eine Instanz „HmIP Türschloss HCU“ übergeben, je Schloss eine Instanz,
     * Variablen mitnehmen. Läuft genau einmal (danach ist das Token hier leer).
     */
    private function Umziehen(): void
    {
        $Zugang = [];
        foreach (['Token', 'ClientId', 'BenutzterSchluessel', 'Schloesser'] as $Name) {
            $Zugang[$Name] = $this->ReadAttributeString($Name);
        }
        $Zugang['Fingerabdruecke'] = json_decode($this->ReadAttributeString('Fingerabdruecke'), true);
        $Alt = json_decode($Zugang['Schloesser'], true);
        $Alt = is_array($Alt) ? array_values($Alt) : [];
        $Zugang['Schloesser'] = $Alt;
        // Ab hier gilt der Umzug als erledigt – auch wenn unten etwas schiefgeht, nicht noch einmal
        foreach (['Token' => '', 'ClientId' => '', 'BenutzterSchluessel' => '', 'Fingerabdruecke' => '{}', 'Schloesser' => '[]', 'LetzteAbfrage' => ''] as $Name => $Leer) {
            $this->WriteAttributeString($Name, $Leer);
        }

        // Zugang: vorhandenen „HmIP Türschloss HCU“ mit derselben Adresse nutzen, sonst anlegen
        $Host = trim($this->ReadPropertyString('Host'));
        $Io = 0;
        foreach (IPS_GetInstanceListByModuleID(DLDLogik::GUID_HCU) as $Kandidat) {
            if (strcasecmp(trim((string) IPS_GetProperty($Kandidat, 'Host')), $Host) === 0) {
                $Io = $Kandidat;
                break;
            }
        }
        if ($Io === 0) {
            $Io = IPS_CreateInstance(DLDLogik::GUID_HCU);
            IPS_SetName($Io, 'HmIP Türschloss HCU');
            foreach (['HinweisBestaetigt', 'Aktiv', 'Host', 'Aktivierungsschluessel', 'Intervall', 'PortAuth', 'PortWs'] as $Name) {
                IPS_SetProperty($Io, $Name, IPS_GetProperty($this->InstanceID, $Name));
            }
            IPS_RequestAction($Io, 'Uebernehmen', (string) json_encode($Zugang, JSON_UNESCAPED_UNICODE));
            IPS_ApplyChanges($Io);
        }

        $Pins = json_decode($this->ReadPropertyString('PinsJeProfil'), true);
        $Pins = is_array($Pins) ? $Pins : [];
        $Allgemein = $this->ReadPropertyString('PIN');
        usort($Alt, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        $Angelegt = [];
        foreach ($Alt as $Nr => $s) {
            $Pin = DLDLogik::pinFuer((array) ($s['profile'] ?? []), $Pins, $Allgemein);
            if ($Nr === 0) {
                $Ziel = $this->InstanceID;
                $Name = IPS_GetName($Ziel);
                if (preg_match('/^HmIP T(ue|ü)rschloss/i', $Name)) {
                    IPS_SetName($Ziel, (string) $s['name']);
                }
            } else {
                $Ziel = IPS_CreateInstance(DLDLogik::GUID_SCHLOSS);
                IPS_SetParent($Ziel, IPS_GetParent($this->InstanceID));
                IPS_SetName($Ziel, (string) $s['name']);
                IPS_SetProperty($Ziel, 'Schloss', (string) $s['id']);
                IPS_SetProperty($Ziel, 'PIN', $Pin);
                IPS_SetProperty($Ziel, 'OeffnenErlauben', $this->ReadPropertyBoolean('OeffnenErlauben'));
                $Angelegt[] = '#' . $Ziel . ' ' . $s['name'];
            }
            $this->Verbinden($Ziel, $Io);
            // Variablen mitnehmen: gleiche Objekt-ID, neuer Ident
            foreach (self::ALT_NEU as $Art => $Neu) {
                $Var = @IPS_GetObjectIDByIdent(DLDLogik::ident($Art, (string) $s['id']), $this->InstanceID);
                if ($Var === false || $Var === 0) {
                    continue;
                }
                // Beim Anlegen hat die neue Instanz schon leere Variablen erzeugt – die alten haben Vorrang
                $Leer = @IPS_GetObjectIDByIdent($Neu, $Ziel);
                if ($Leer !== false && $Leer !== 0 && $Leer !== $Var) {
                    IPS_DeleteVariable($Leer);
                }
                if ($Ziel !== $this->InstanceID) {
                    IPS_SetParent($Var, $Ziel);
                }
                IPS_SetIdent($Var, $Neu);
                IPS_SetName($Var, $Neu);
            }
            if ($Ziel !== $this->InstanceID) {
                IPS_ApplyChanges($Ziel);
            }
        }
        // Variablen von Schlössern, die es nicht mehr gibt
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $Kind) {
            $Obj = IPS_GetObject($Kind);
            $Ident = (string) $Obj['ObjectIdent'];
            if ($Obj['ObjectType'] === OBJECTTYPE_VARIABLE && !in_array($Ident, self::ALT_NEU, true) && preg_match('/^[ZMF][A-Za-z0-9]{6,}$/', $Ident)) {
                IPS_DeleteVariable($Kind);
            }
        }

        // Diese Instanz: erstes Schloss; Zugangsdaten hier löschen (liegen jetzt im Zugang)
        IPS_SetProperty($this->InstanceID, 'Schloss', (string) ($Alt[0]['id'] ?? ''));
        if ($Alt !== []) {
            IPS_SetProperty($this->InstanceID, 'PIN', DLDLogik::pinFuer((array) ($Alt[0]['profile'] ?? []), $Pins, $Allgemein));
        }
        IPS_SetProperty($this->InstanceID, 'Aktivierungsschluessel', '');
        IPS_SetProperty($this->InstanceID, 'PinsJeProfil', '[]');
        $this->LogMessage('Umzug auf Version 2: Zugang zur HCU jetzt in #' . $Io . ($Angelegt !== [] ? '; neue Türschloss-Instanzen: ' . implode(', ', $Angelegt) : ''), KL_NOTIFY);
        IPS_ApplyChanges($this->InstanceID);
    }

    private function Verbinden(int $Instanz, int $Io): void
    {
        $Aktuell = IPS_GetInstance($Instanz)['ConnectionID'];
        if ($Aktuell === $Io) {
            return;
        }
        if ($Aktuell > 0) {
            IPS_DisconnectInstance($Instanz);
        }
        IPS_ConnectInstance($Instanz, $Io);
    }

    /** Anfrage an den Zugang zur HCU; [] wenn keiner verbunden ist oder er nicht antwortet */
    private function Anfrage(array $Daten): array
    {
        if (IPS_GetInstance($this->InstanceID)['ConnectionID'] === 0) {
            return [];
        }
        try {
            $Antwort = $this->SendDataToParent((string) json_encode(['DataID' => DLDLogik::DATEN_ZUR_HCU] + $Daten, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            $this->SendDebug('Zugang', $e->getMessage(), 0);
            return [];
        }
        $Ergebnis = json_decode($Antwort, true);
        return is_array($Ergebnis) ? $Ergebnis : [];
    }

    private function FormAnpassen(array &$Elemente, array $Optionen, string $Info): void
    {
        foreach ($Elemente as &$Element) {
            if (($Element['name'] ?? '') === 'Schloss') {
                $Element['options'] = $Optionen;
            }
            if (($Element['name'] ?? '') === 'Freigabeinfo') {
                $Element['caption'] = $Info;
                $Element['visible'] = $Info !== '';
            }
            if (isset($Element['items']) && is_array($Element['items'])) {
                $this->FormAnpassen($Element['items'], $Optionen, $Info);
            }
        }
    }

    private function Daten(): array
    {
        $Daten = json_decode($this->ReadAttributeString('Daten'), true);
        return is_array($Daten) && isset($Daten['id']) ? $Daten : [];
    }

    private function DatenSetzen(array $Schloss): void
    {
        $Neu = (string) json_encode($Schloss === [] ? new stdClass() : $Schloss, JSON_UNESCAPED_UNICODE);
        if ($Neu !== $this->ReadAttributeString('Daten')) {
            $this->WriteAttributeString('Daten', $Neu);
        }
    }

    private function StatusSetzen(int $Status): void
    {
        if ($this->GetStatus() !== $Status) {
            $this->SetStatus($Status);
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

    /** Ins Meldungsfenster (ohne PIN), auf Wunsch auch als Hinweis in der Kachel */
    private function Meldung(string $Text, bool $Kachel = false): void
    {
        $this->LogMessage($Text, KL_WARNING);
        $this->SendDebug('Meldung', $Text, 0);
        if ($Kachel) {
            $this->WriteAttributeString('Meldung', $Text);
            $this->WriteAttributeInteger('MeldungZeit', time());
            $this->KachelSenden();
        }
    }

    /** Gründe, warum gar nicht erst gesendet wird (ohne Zugriff auf die HCU) */
    private function PruefenSchalten(int $Zustand, array $Schloss, string $Name): string
    {
        if (!isset(DLDLogik::ZUSTAENDE[$Zustand])) {
            return 'Ungültiger Zustand ' . $Zustand . ' (0 = verriegeln, 1 = entriegeln, 2 = öffnen)';
        }
        if ($Zustand === 2 && !$this->ReadPropertyBoolean('OeffnenErlauben')) {
            return '„' . $Name . '“: Öffnen ist in den Einstellungen der Instanz ausgeschaltet.';
        }
        if ($Schloss === []) {
            return '„' . $Name . '“: Kein Türschloss gewählt oder kein Zugang zur HCU verbunden (Status ' . $this->GetStatus() . ').';
        }
        return '';
    }

    private function KachelDaten(): array
    {
        $Schloss = $this->Daten();
        $Status = $this->GetStatus();
        $Texte = [self::ST_AKTIV => 'Bereit', self::ST_KEIN_SCHLOSS => 'Kein Türschloss gewählt', self::ST_NICHT_GEFUNDEN => 'Schloss nicht gefunden',
            self::ST_ZUGANG => 'HCU nicht erreichbar', self::ST_PIN => 'PIN fehlt'];
        $PinDa = trim($this->ReadPropertyString('PIN')) !== '';
        return [
            'theme' => $this->ReadPropertyInteger('TileTheme'),
            'name' => IPS_GetName($this->InstanceID),
            'state' => $this->Wert('Zustand') ?? ($Schloss['zustand'] ?? null),
            'motor' => $this->Wert('Motor') ?? ($Schloss['motor'] ?? null),
            'release' => (string) ($Schloss['freigabe'] ?? ''),
            'releaseText' => $Schloss === [] ? '' : DLDLogik::freigabeText($Schloss['freigabe'], $Schloss['profil'], $PinDa),
            'profile' => (string) ($Schloss['profil'] ?? ''),
            'pin' => $PinDa,
            'allowOpen' => $this->ReadPropertyBoolean('OeffnenErlauben'),
            'ready' => in_array($Status, [self::ST_AKTIV, self::ST_PIN], true) && $Schloss !== [],
            'statusText' => $Texte[$Status] ?? 'Status ' . $Status,
            'since' => $this->ReadAttributeInteger('Seit'),
            'updated' => $this->ReadAttributeInteger('Abfrage'),
            'message' => $this->ReadAttributeString('Meldung'),
            'messageTime' => $this->ReadAttributeInteger('MeldungZeit'),
            'now' => time(),
        ];
    }

    /** Kacheldaten senden – nur wenn sich etwas geändert hat (Uhrzeiten zählen nicht) */
    private function KachelSenden(): void
    {
        if (!$this->ReadPropertyBoolean('Kachel')) {
            return;
        }
        $Daten = $this->KachelDaten();
        $Vergleich = $Daten;
        unset($Vergleich['now'], $Vergleich['updated']);
        $Pruefsumme = md5((string) json_encode($Vergleich));
        // Zeit der letzten Abfrage höchstens alle 5 Minuten nachreichen
        if ($Pruefsumme === $this->ReadAttributeString('KachelDaten') && $Daten['updated'] - $this->ReadAttributeInteger('KachelAbfrage') < 300) {
            return;
        }
        $this->WriteAttributeString('KachelDaten', $Pruefsumme);
        $this->WriteAttributeInteger('KachelAbfrage', (int) $Daten['updated']);
        $this->UpdateVisualizationValue((string) json_encode($Daten));
    }

    private function Wert(string $Ident): ?int
    {
        $Id = @$this->GetIDForIdent($Ident);
        return ($Id === false || $Id === 0) ? null : (int) GetValue($Id);
    }

}
