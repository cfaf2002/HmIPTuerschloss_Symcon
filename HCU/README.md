# HmIP Türschloss HCU

Zugang zur Homematic IP Home Control Unit (HCU) über die Connect API – einer für alle Türschlösser. Meldet sich mit dem Aktivierungsschlüssel an (Benutzer „Symcon Türschloss“ in der App), fragt den Systemzustand ab und verteilt ihn an die verbundenen Instanzen **HmIP Türschloss**. Deren Schaltbefehle schickt er mit der PIN der jeweiligen Instanz an die HCU.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Nutzungshinweis bestätigen | Muss gesetzt sein, sonst bleibt der Zugang inaktiv |
| Instanz aktiv | Schaltet Abfragen und Befehle ein/aus |
| Adresse der HCU | IP-Adresse (am zuverlässigsten) oder `hcu1-XXXX.local` |
| Aktivierungsschlüssel | Aus HCUweb → Entwicklermodus; nur zum Anmelden, gilt kurz und nur einmal |
| Zustand abfragen alle … s | Standard 60 s, mindestens 15 s; 0 = nur nach Befehlen. Eine Abfrage gilt für alle Schlösser |
| Experte: Ports | Anmeldung 6969, Connect API 9001 (nur ändern, wenn nötig) |

Unter den Einstellungen stehen die gefundenen Türschlösser mit Zustand, Freigabe für den Zugang und der zugehörigen Instanz in Symcon.
Buttons: **Jetzt abfragen**, **Neu anmelden** (mit neuem Aktivierungsschlüssel), **Zertifikat neu lernen** (nur nach Tausch oder Zurücksetzen der HCU).

Nach einem Schaltbefehl holt der Zugang nach 6 Sekunden den echten Zustand und gibt ihn an alle Türschlösser weiter.

## Sicherheit

- Die HCU hat ein selbst ausgestelltes Zertifikat: Beim ersten Kontakt merkt sich der Zugang dessen SHA-256-Fingerabdruck und lehnt danach jede Verbindung mit einem anderen Zertifikat ab.
- Zugangs-Token und PIN erscheinen weder im Debug noch im Meldungsfenster; das Token liegt nur in dieser Instanz.
- Je Anfrage eine kurze, verschlüsselte Verbindung; Anfragen laufen nacheinander (Semaphore).

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
