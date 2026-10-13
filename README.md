# Familien Organisierer – ALL-INKL Edition

Separate PHP/MariaDB-Umsetzung für klassisches ALL-INKL-Webhosting. Die Browser-Oberfläche stammt aus `etzewetze/Familien-Organizer`; der Serverteil wurde für PHP und MariaDB neu begonnen. Der ursprüngliche Node.js-/Proxmox-Betrieb bleibt im Ausgangsrepository erhalten.

## Installation auf ALL-INKL Premium

1. Im KAS eine MariaDB-Datenbank anlegen und Host, Datenbankname, Benutzer und Passwort notieren.
2. `config.example.php` als `config.php` in den Projektordner kopieren. Zugangsdaten eintragen und für `setup_key` einen eigenen Zufallsschlüssel mit mindestens 32 Zeichen festlegen (zum Beispiel lokal mit `openssl rand -hex 32` erzeugen). `config.php` niemals in GitHub hochladen.
3. Den Inhalt des Ordners `public/` in das Web-Verzeichnis der Subdomain laden. Die Subdomain sollte im KAS direkt auf diesen Ordner zeigen. Alternativ kann das Repository-Verzeichnis als DocumentRoot dienen; die Root-`.htaccess` leitet dann auf `public/` um und sperrt Konfiguration, Datenordner und Git-Metadaten.
4. Neben `public/` einen Ordner `data/` anlegen. PHP muss dort Schreibrechte für seine Bildordner haben. Die Datenbanktabellen werden beim ersten API-Aufruf automatisch angelegt.
5. HTTPS für die Subdomain aktivieren und anschließend die Adresse im Browser öffnen. Bei der Ersteinrichtung den `setup_key` und danach Familienname, Mitglieder und Familienpasswort eingeben.

Die Installationsansicht braucht PHP 8.1 oder neuer mit PDO-MySQL, JSON und Sessions. Für die Wetterabfrage muss PHP ausgehende HTTPS-Verbindungen zu Open-Meteo öffnen dürfen (`allow_url_fopen` oder ein entsprechender Provider-Schalter). `config.php`, Datenbankzugänge und der Ordner `data/` gehören außerhalb des öffentlichen Web-Verzeichnisses. Datenbank und `data/` regelmäßig gemeinsam sichern.

Beispiel für `config.php`:

```php
<?php
return [
    'dsn' => 'mysql:host=IHRE_DB_HOST;dbname=IHRE_DB;charset=utf8mb4',
    'username' => 'IHR_DB_BENUTZER',
    'password' => 'IHR_DB_PASSWORT',
    'setup_key' => 'HIER_EINEN_EIGENEN_ZUFALLSSCHLUESSEL_EINTRAGEN',
];
```

## Datenschutz und Zugriffsschutz

Vor der Anmeldung liefert die API nur den Anmeldestatus und ob die Ersteinrichtung bereits abgeschlossen wurde. Kalender, Namen, Einstellungen, Bilder und Exporte verlangen eine gültige Anmeldung. Auch Bilder werden über geschützte API-Routen ausgeliefert und nicht aus einem öffentlichen Ordner gelesen. Die Einrichtung verlangt zusätzlich den geheimen `setup_key`, damit niemand die noch nicht eingerichtete Familienzentrale zuerst beanspruchen kann. Entferne den Schlüssel nach der Ersteinrichtung aus `config.php`.

Die API verwendet HTTPS-geschützte, HttpOnly- und SameSite-Sitzungscookies, schützt vor Session-Fixation und beendet Sitzungen nach einer Passwortänderung auf allen Geräten. Antworten werden nicht zwischengespeichert. Die `.htaccess`-Dateien erzwingen HTTPS, deaktivieren Verzeichnislisten und setzen Sicherheitsheader. Vor dem produktiven Betrieb muss die Subdomain mit gültigem HTTPS erreichbar sein.

## Stand der Portierung

Die vorhandene Oberfläche und die responsive Kalenderansicht sind übernommen. Die PHP-API enthält Einrichtung, Anmeldung, Datenzustand, allgemeine Datensatzverwaltung, Aufgabenabschluss/-zuweisung, Punkte und Belohnungen, Einstellungen, Datenexport, Wetterorte/-vorhersage und lokale Bild-Uploads.

Die Web-Oberfläche deckt damit noch nicht alle Funktionen der ursprünglichen Server-Version ab: Google-Kalender-OAuth und -Sync, Immich und externe Bilderlisten, Rezept-Link-Import sowie automatische Updates sind nicht portiert. Der automatische Einkaufslisten-Abgleich ist ebenfalls noch nicht implementiert. Für Softwareupdates werden die Dateien manuell per SFTP/FTP eingespielt; die MariaDB-Daten bleiben dabei erhalten.

## Lizenz

MIT – siehe `LICENSE`.
