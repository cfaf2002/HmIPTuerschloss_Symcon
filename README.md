# HmIP Türschloss für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.0 (Build 1)](https://img.shields.io/badge/Modul--Version-1.0_(Build_1)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/HmIPTuerschloss_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/HmIPTuerschloss_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
![Sprache: Deutsch](https://img.shields.io/badge/Sprache-Deutsch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Schnittstelle: HCU Connect API (lokal)](https://img.shields.io/badge/Schnittstelle-HCU_Connect_API_(lokal)-lightgrey.svg)](https://github.com/homematicip/connect-api)

Schaltet Homematic-IP-Türschlossantriebe (HmIP-DLD) über die Home Control Unit (HCU): **Verriegeln, Entriegeln, Öffnen** – mit der PIN des Zugangsprofils, auch bei mehreren Schlössern in verschiedenen Profilen. Zeigt Zustand, Motor und ob das Modul in der HCU freigegeben ist. Läuft lokal ohne Cloud und neben dem Symcon-Modul „HomeMatic HCU“.

> **Hinweis:** Das Modul nutzt die offizielle Connect API der HCU, ist aber kein Produkt der eQ-3 AG. Für die Connect API muss in HCUweb der Entwicklermodus eingeschaltet sein; laut eQ-3 lässt er sich danach nicht mehr ausschalten, und eQ-3 leistet dann keinen technischen Support.

## Inhalt

| Modul | Typ | Aufgabe |
| :---- | :-- | :------ |
| [HmIP Türschloss](Tuerschloss/README.md) | Gerät | Eigener Zugang zur HCU, alle Türschlösser mit Zustand, Motor, Freigabe und Bedienung |

## Warum ein eigenes Modul?

Die HCU schaltet ein Türschloss nur, wenn der anfragende Zugang **im selben Zugangsprofil** wie das Schloss steht und bei jedem Befehl die **PIN dieses Profils** mitschickt (`authorizationPin`). Fehlt die PIN, antwortet die HCU mit `INVALID_REQUEST`. Dieses Modul bringt genau das mit: eigener Zugang, PIN je Profil, verständliche Fehlermeldungen.

## Voraussetzungen

- IP-Symcon ab Version 8.1 (empfohlen 9.0)
- Homematic IP Home Control Unit (HCU1) im selben Netz, Entwicklermodus mit „Connect API WebSocket freigeben“
- In der Homematic-IP-App ein Zugangsprofil mit dem Schloss und einer PIN

## Installation

1. Im Objektbaum unter *Kern Instanzen → Modules* die URL `https://github.com/cfaf2002/HmIPTuerschloss_Symcon` hinzufügen.
2. Instanz **HmIP Türschloss** anlegen, Nutzungshinweis bestätigen.
3. In HCUweb einen **Aktivierungsschlüssel** erzeugen, zusammen mit der Adresse der HCU eintragen und **Übernehmen** – in der App erscheint der Benutzer **„Symcon Türschloss“**.
4. In der Homematic-IP-App unter **Zutrittsberechtigungen** das Profil mit dem Schloss öffnen (oder anlegen), **„Symcon Türschloss“ hinzufügen** und eine **PIN** vergeben.
5. Die PIN im Modul eintragen (bei mehreren Profilen je Profil eine Zeile) und **Übernehmen**. Unter „Gefundene Türschlösser“ steht bei jedem Schloss „ja – Profil …“.

## GUIDs

| Modul | Präfix | GUID |
| :---- | :----: | :--: |
| HmIP Türschloss | DLD | {C1931337-4B5F-4B19-9680-20B23BBF691A} |

## Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.0 | 1 | 02.11.2026 | Erste Version: eigener Zugang zur HCU (Connect API), alle Türschlösser mit Zustand, Motor und Freigabe; Verriegeln, Entriegeln, Öffnen mit PIN je Zugangsprofil; Zertifikat der HCU wird beim ersten Kontakt gemerkt; Tests gegen eine nachgebaute HCU |

## Lizenz

MIT – siehe [LICENSE](LICENSE).

## Autor

Armin Frohwerk
