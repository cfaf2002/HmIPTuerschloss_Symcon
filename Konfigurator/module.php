<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DLDLogik.php';

/**
 * HmIP Türschloss Konfigurator
 * Listet die Türschlösser, die der Zugang zur HCU kennt, und legt je Schloss eine Instanz „HmIP Türschloss“ an.
 *
 * Copyright (c) 2026 Armin Frohwerk · MIT-Lizenz
 * SPDX-License-Identifier: MIT
 */
class HmIPTuerschlossKonfigurator extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    /** Der Konfigurator wertet die laufenden Zustände nicht aus. */
    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $Liste = $this->Liste();

        // Vorhandene Instanzen je Schloss (am selben Zugang)
        $Zugang = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $Vorhanden = [];
        foreach (IPS_GetInstanceListByModuleID(DLDLogik::GUID_SCHLOSS) as $Id) {
            if ($Zugang > 0 && IPS_GetInstance($Id)['ConnectionID'] === $Zugang) {
                $Vorhanden[(string) IPS_GetProperty($Id, 'Schloss')] = $Id;
            }
        }
        $Werte = [];
        foreach ((array) ($Liste['Schloesser'] ?? []) as $s) {
            $Werte[] = [
                'Name' => $s['name'],
                'Kennung' => $s['id'],
                'Freigabe' => DLDLogik::freigabeText($s['freigabe'], $s['profil'], true),
                'instanceID' => $Vorhanden[$s['id']] ?? 0,
                'create' => [
                    'moduleID' => DLDLogik::GUID_SCHLOSS,
                    'name' => $s['name'],
                    'configuration' => ['Schloss' => $s['id']],
                ],
            ];
            unset($Vorhanden[$s['id']]);
        }
        // Instanzen, deren Schloss die HCU nicht (mehr) kennt
        foreach ($Vorhanden as $Kennung => $Id) {
            $Werte[] = ['Name' => IPS_GetName($Id), 'Kennung' => $Kennung, 'Freigabe' => 'in der HCU nicht gefunden', 'instanceID' => $Id];
        }
        foreach ($Form['actions'] as &$Element) {
            if (($Element['name'] ?? '') === 'Schloesser') {
                $Element['values'] = $Werte;
            }
            if (($Element['name'] ?? '') === 'Hinweis') {
                $Element['caption'] = $Liste === []
                    ? 'Kein Zugang zur HCU verbunden – oben unter „Übergeordnete Instanz“ den „HmIP Türschloss HCU“ wählen.'
                    : (($Liste['Bereit'] ?? false) ? 'Die PIN des Zugangsprofils danach in der jeweiligen Türschloss-Instanz eintragen.' : 'Der Zugang zur HCU ist gerade nicht bereit – angezeigt wird der zuletzt bekannte Stand.');
            }
        }
        return (string) json_encode($Form);
    }

    private function Liste(): array
    {
        if (IPS_GetInstance($this->InstanceID)['ConnectionID'] === 0) {
            return [];
        }
        try {
            $Antwort = $this->SendDataToParent((string) json_encode(['DataID' => DLDLogik::DATEN_ZUR_HCU, 'Funktion' => 'Liste']));
        } catch (Throwable $e) {
            return [];
        }
        $Daten = json_decode($Antwort, true);
        return is_array($Daten) ? $Daten : [];
    }
}
