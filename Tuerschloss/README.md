# HmIP Türschloss

Schaltet Homematic-IP-Türschlossantriebe (HmIP-DLD) über die Connect API der Home Control Unit (HCU). Das Modul hat einen eigenen Zugang zur HCU (Benutzer „Symcon Türschloss“ in der App), fragt den Zustand der Schlösser ab und sendet Befehle mit der PIN des passenden Zugangsprofils.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Nutzungshinweis bestätigen | Muss gesetzt sein, sonst bleibt die Instanz inaktiv |
| Instanz aktiv | Schaltet Abfragen und Befehle ein/aus |
| Adresse der HCU | IP-Adresse (am zuverlässigsten) oder `hcu1-XXXX.local` |
| Aktivierungsschlüssel | Aus HCUweb → Entwicklermodus; nur zum Anmelden, gilt kurz und nur einmal |
| PIN | PIN des Zugangsprofils – gilt für alle Schlösser ohne eigene Zeile |
| Weitere PINs je Zugangsprofil | Liste *Zugangsprofil* (Name wie in der App) und *PIN* – für mehrere Schlösser in verschiedenen Profilen |
| Zustand abfragen alle … s | Standard 60 s, mindestens 15 s; 0 = nur nach Befehlen |
| „Öffnen“ erlauben | Aus: nur Verriegeln und Entriegeln, die Falle wird nie gezogen |
| Experte: Ports | Anmeldung 6969, Connect API 9001 (nur ändern, wenn nötig) |

Buttons: **Jetzt abfragen**, **Neu anmelden** (mit neuem Aktivierungsschlüssel), **Zertifikat neu lernen** (nur nach Tausch oder Zurücksetzen der HCU).

## Variablen je Schloss

| Variable | Typ | Darstellung |
| :------- | :-- | :---------- |
| *Name des Schlosses* | Integer, bedienbar | Aufzählung: 0 Verriegelt · 1 Entriegelt · 2 Öffnen |
| *Name* · Motor | Integer | Steht · Öffnet · Schließt · Blockiert |
| *Name* · Freigabe | String | z. B. „ja – Profil „Familie““, „… aber im Modul fehlt die PIN“, „nein – … hinzufügen“ |

Nach einem Befehl zeigt die Variable sofort den gewünschten Zustand; nach 6 Sekunden holt das Modul den echten Zustand von der HCU. Schlösser, die es in der HCU nicht mehr gibt, verlieren ihre Variablen.

## Freigabe und PIN

Die HCU schaltet nur, wenn der Benutzer „Symcon Türschloss“ und das Schloss im **selben Zugangsprofil** stehen und das Profil eine **PIN** hat. Das Modul liest die Zugangsprofile aus dem Systemzustand der HCU (`ACCESS_AUTHORIZATION_PROFILE` mit `clientIds` und `authorizationPinAssigned`) und wählt je Schloss die PIN seines Profils. Ist keine PIN eingetragen, wird gar nicht erst gesendet – stattdessen steht im Meldungsfenster, was fehlt.

| Fehlercode der HCU | Bedeutung |
| :----------------- | :-------- |
| `INVALID_REQUEST` | meist PIN fehlt oder Modul nicht im Profil |
| `INVALID_AUTHORIZATION_PIN` | PIN falsch |
| `CLIENT_ACCESS_DENIED` / `CLIENT_INVALID_AUTHORIZATION` | Modul nicht im Zugangsprofil des Schlosses |

## Sicherheit

- Die HCU hat ein selbst ausgestelltes Zertifikat: Beim ersten Kontakt merkt sich das Modul dessen SHA-256-Fingerabdruck und lehnt danach jede Verbindung mit einem anderen Zertifikat ab.
- Zugangs-Token und PIN erscheinen weder im Debug noch im Meldungsfenster.
- Je Anfrage eine kurze, verschlüsselte Verbindung; Anfragen laufen nacheinander (Semaphore).
- Ein Türschloss zu öffnen ist sicherheitsrelevant: Zugriff auf Symcon nur für vertrauenswürdige Personen; „Öffnen“ lässt sich abschalten.

## Status

| Code | Bedeutung |
| :--: | :-------- |
| 102 | Verbunden |
| 104 | Instanz ist deaktiviert |
| 201 | Nutzungshinweis nicht bestätigt |
| 202 | Adresse der HCU fehlt |
| 203 | Noch nicht angemeldet – Aktivierungsschlüssel eintragen |
| 204 | Anmeldung bei der HCU fehlgeschlagen |
| 205 | HCU nicht erreichbar |

## PHP-Befehle

```php
// Schloss per Name oder Gerätekennung (SGTIN)
DLD_Verriegeln($id, 'Haustür');
DLD_Entriegeln($id, 'Haustür');
DLD_Oeffnen($id, 'Haustür');            // entriegeln und Falle ziehen
DLD_SetzeZustand($id, 'Haustür', 0);    // 0 verriegeln, 1 entriegeln, 2 öffnen

// Zustand jetzt abfragen
DLD_Aktualisieren($id);

// Alle gefundenen Schlösser (JSON: id, name, kanal, zustand, motor, freigabe, profile)
echo DLD_Schlossliste($id);
```

Alle Befehle liefern `true` bei Erfolg, sonst `false` mit Warnung und Grund.
