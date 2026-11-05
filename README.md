# HmIP Türschloss für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 2.0 (Build 2)](https://img.shields.io/badge/Modul--Version-2.0_(Build_2)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/HmIPTuerschloss_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/HmIPTuerschloss_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
![Sprache: Deutsch](https://img.shields.io/badge/Sprache-Deutsch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Schnittstelle: HCU Connect API (lokal)](https://img.shields.io/badge/Schnittstelle-HCU_Connect_API_(lokal)-lightgrey.svg)](https://github.com/homematicip/connect-api)

Schaltet Homematic-IP-Türschlossantriebe (HmIP-DLD) über die Home Control Unit (HCU): **Verriegeln, Entriegeln, Öffnen** – mit der PIN des Zugangsprofils, **je Türschloss eine Instanz** an einem gemeinsamen Zugang zur HCU. Zeigt Zustand, Motor und ob das Schloss für den Zugang freigegeben ist. Läuft lokal ohne Cloud und neben dem Symcon-Modul „HomeMatic HCU“.

> **Hinweis:** Das Modul nutzt die offizielle Connect API der HCU, ist aber kein Produkt der eQ-3 AG. Für die Connect API muss in HCUweb der Entwicklermodus eingeschaltet sein; laut eQ-3 lässt er sich danach nicht mehr ausschalten, und eQ-3 leistet dann keinen technischen Support.

## Inhalt

| Modul | Typ | Aufgabe |
| :---- | :-- | :------ |
| [HmIP Türschloss HCU](HCU/README.md) | I/O | Ein Zugang zur HCU für alle Schlösser (Anmeldung, Abfrage, Befehle) |
| [HmIP Türschloss Konfigurator](Konfigurator/README.md) | Konfigurator | Listet die Schlösser der HCU und legt je Schloss eine Instanz an |
| [HmIP Türschloss](Tuerschloss/README.md) | Gerät | Ein Türschloss: Zustand, Motor, Freigabe, Bedienung mit der PIN seines Zugangsprofils |

## Warum ein eigenes Modul?

Die HCU schaltet ein Türschloss nur, wenn der anfragende Zugang **im selben Zugangsprofil** wie das Schloss steht und bei jedem Befehl die **PIN dieses Profils** mitschickt (`authorizationPin`). Fehlt die PIN, antwortet die HCU mit `INVALID_REQUEST`. Dieses Modul bringt genau das mit: eigener Zugang, PIN je Schloss, verständliche Fehlermeldungen.

## Voraussetzungen

- IP-Symcon ab Version 8.1 (empfohlen 9.0)
- Homematic IP Home Control Unit (HCU1) im selben Netz, Entwicklermodus mit „Connect API WebSocket freigeben“
- In der Homematic-IP-App ein Zugangsprofil mit dem Schloss und einer PIN

## Installation

1. Im Objektbaum unter *Kern Instanzen → Modules* die URL `https://github.com/cfaf2002/HmIPTuerschloss_Symcon` hinzufügen.
2. Instanz **HmIP Türschloss Konfigurator** anlegen; als übergeordnete Instanz einen neuen **HmIP Türschloss HCU** anlegen lassen.
3. Im Zugang (HCU) den Nutzungshinweis bestätigen, in HCUweb einen **Aktivierungsschlüssel** erzeugen, zusammen mit der Adresse der HCU eintragen und **Übernehmen** – in der App erscheint der Benutzer **„Symcon Türschloss“**.
4. In der Homematic-IP-App unter **Zutrittsberechtigungen** das Profil mit dem Schloss öffnen (oder anlegen), **„Symcon Türschloss“ hinzufügen** und eine **PIN** vergeben.
5. Im Konfigurator je Schloss **Erstellen** klicken und in der neuen Instanz die **PIN** seines Zugangsprofils eintragen.

### Update von Version 1.0

Bis 1.0 bediente eine Instanz alle Schlösser. Nach dem Update zieht sie beim ersten Start von selbst um – ohne neuen Aktivierungsschlüssel:

- Der Zugang (Adresse, Anmeldung, gemerktes Zertifikat) kommt in eine neue Instanz **HmIP Türschloss HCU**.
- Die bisherige Instanz übernimmt das alphabetisch erste Schloss, jedes weitere bekommt eine eigene Instanz daneben – mit der PIN seines Profils aus der alten Liste.
- Die Variablen ziehen mit und behalten ihre Objekt-ID (Verknüpfungen, Ereignisse und Visualisierung bleiben); sie heißen jetzt *Zustand*, *Motor* und *Freigabe*.
- Skripte: Die Befehle brauchen keinen Schlossnamen mehr, sondern die ID der jeweiligen Türschloss-Instanz, z. B. `DLD_Oeffnen($idHaustuer)` statt `DLD_Oeffnen($id, 'Haustür')`. `DLD_Schlossliste` entfällt.

## GUIDs

| Modul | Präfix | GUID |
| :---- | :----: | :--: |
| HmIP Türschloss HCU | DLD | {1E261F5E-40A1-4744-A69E-661E33CA2496} |
| HmIP Türschloss Konfigurator | DLD | {1EBC9F8E-094F-4551-84D7-9CB801916F1F} |
| HmIP Türschloss | DLD | {C1931337-4B5F-4B19-9680-20B23BBF691A} |

| Datenfluss | GUID |
| :--------- | :--: |
| Türschloss/Konfigurator → HCU | {E78A3515-A873-4BEB-A2F9-8402AFCF495D} |
| HCU → Türschloss | {ED7D4F7F-B375-4BF9-8CA2-32B2EA73BBFC} |

## Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 2.0 | 2 | 05.11.2026 | Je Türschloss eine Instanz: neuer Zugang „HmIP Türschloss HCU“ (eine Abfrage für alle Schlösser) und Konfigurator; PIN je Instanz; automatischer Umzug aus 1.0 mit Übernahme des Zugangs und der Variablen (gleiche Objekt-IDs); Befehle ohne Schlossnamen |
| 1.0 | 1 | 02.11.2026 | Erste Version: eigener Zugang zur HCU (Connect API), alle Türschlösser mit Zustand, Motor und Freigabe; Verriegeln, Entriegeln, Öffnen mit PIN je Zugangsprofil; Zertifikat der HCU wird beim ersten Kontakt gemerkt; Tests gegen eine nachgebaute HCU |

## Lizenz

MIT – siehe [LICENSE](LICENSE).

## Autor

Armin Frohwerk
