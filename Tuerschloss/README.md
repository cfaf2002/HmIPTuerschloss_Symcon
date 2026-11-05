# HmIP Türschloss

Ein Homematic-IP-Türschlossantrieb (HmIP-DLD) je Instanz. Die Verbindung zur HCU hält die übergeordnete Instanz [HmIP Türschloss HCU](../HCU/README.md); diese Instanz schickt Befehle mit der PIN des Zugangsprofils ihres Schlosses und zeigt Zustand, Motor und Freigabe. Am einfachsten über den [Konfigurator](../Konfigurator/README.md) anlegen.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Türschloss | Auswahl aus den Schlössern, die der Zugang in der HCU findet |
| PIN des Zugangsprofils | PIN des Profils, in dem dieses Schloss und der Benutzer „Symcon Türschloss“ stehen. Darunter steht die Freigabe aus der HCU |
| „Öffnen“ erlauben | Aus: nur Verriegeln und Entriegeln, die Falle wird nie gezogen |

| Eigene Kachel | Tür mit Schloss, Zustand und Tasten statt der Variablenliste (Standard: an) |
| Farbschema der Kachel | Symcon-Design (Farben der Visualisierung), Dunkel oder Hell |

Buttons: **Jetzt abfragen**, **Verriegeln**, **Entriegeln**.

## Kachel

- Tür mit Riegel und Ring um den Schließzylinder: grün verriegelt, gelb entriegelt, rot offen (die Tür steht dann einen Spalt auf). Läuft der Motor, dreht sich der Ring.
- Darunter der Zustand in Worten mit „seit …“, oben rechts die Freigabe (z. B. „Freigegeben · Treppenhaus“ oder „PIN fehlt“).
- Drei Tasten: Verriegeln, Entriegeln, Öffnen. **Öffnen braucht einen zweiten Tipp innerhalb von 3 Sekunden.** Nach dem Tippen dreht sich ein Kreis, bis die HCU den neuen Zustand meldet.
- Klappt ein Befehl nicht (PIN falsch, HCU weg), erscheint der Grund unten in der Kachel; ein blockierter Riegel wird rot hervorgehoben.
- Passt sich der Größe an: kleine Kacheln zeigen nur Zustand und Tasten.

## Variablen

| Variable | Typ | Darstellung |
| :------- | :-- | :---------- |
| Zustand | Integer, bedienbar | Aufzählung: 0 Verriegelt · 1 Entriegelt · 2 Öffnen |
| Motor | Integer | Steht · Öffnet · Schließt · Blockiert |
| Freigabe | String | z. B. „ja – Profil „Familie““, „… aber im Modul fehlt die PIN“, „nein – … hinzufügen“ |

Nach einem Befehl zeigt *Zustand* sofort den gewünschten Wert; nach 6 Sekunden kommt der echte Zustand von der HCU.

## Freigabe und PIN

Die HCU schaltet nur, wenn der Benutzer „Symcon Türschloss“ und das Schloss im **selben Zugangsprofil** stehen und das Profil eine **PIN** hat. Das Modul liest die Zugangsprofile aus dem Systemzustand der HCU (`ACCESS_AUTHORIZATION_PROFILE` mit `clientIds` und `authorizationPinAssigned`) und zeigt je Schloss, ob es freigegeben ist. Ist keine PIN eingetragen, wird gar nicht erst gesendet – stattdessen steht im Meldungsfenster, was fehlt.

| Fehlercode der HCU | Bedeutung |
| :----------------- | :-------- |
| `INVALID_REQUEST` | meist PIN fehlt oder Modul nicht im Profil |
| `INVALID_AUTHORIZATION_PIN` | PIN falsch |
| `CLIENT_ACCESS_DENIED` / `CLIENT_INVALID_AUTHORIZATION` | Modul nicht im Zugangsprofil des Schlosses |

## Sicherheit

- Die PIN liegt nur in dieser Instanz und geht nur mit dem Befehl an den Zugang; sie erscheint weder im Debug noch im Meldungsfenster.
- Ein Türschloss zu öffnen ist sicherheitsrelevant: Zugriff auf Symcon nur für vertrauenswürdige Personen; „Öffnen“ lässt sich je Schloss abschalten.

## Status

| Code | Bedeutung |
| :--: | :-------- |
| 102 | Bereit |
| 201 | Kein Türschloss gewählt |
| 202 | Türschloss in der HCU nicht gefunden |
| 203 | Zugang zur HCU nicht verbunden oder nicht bereit |
| 204 | PIN des Zugangsprofils fehlt |

## PHP-Befehle

```php
// $id = ID der Türschloss-Instanz
DLD_Verriegeln($id);
DLD_Entriegeln($id);
DLD_Oeffnen($id);            // entriegeln und Falle ziehen
DLD_SetzeZustand($id, 0);    // 0 verriegeln, 1 entriegeln, 2 öffnen

// Zustand jetzt abfragen (fragt über den Zugang alle Schlösser ab)
DLD_Aktualisieren($id);
```

Alle Befehle liefern `true` bei Erfolg, sonst `false` mit Warnung und Grund.
