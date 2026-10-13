# Familien Organisierer – ALL-INKL Edition

Separate PHP/MariaDB-Umsetzung für klassisches ALL-INKL-Webhosting. Die Browser-Oberfläche stammt aus `etzewetze/Familien-Organizer`; der Serverteil wurde für PHP und MariaDB neu begonnen. Der ursprüngliche Node.js-/Proxmox-Betrieb bleibt im Ausgangsrepository erhalten.

## Installation auf ALL-INKL Premium

1. Im KAS eine MariaDB-Datenbank anlegen und Host, Datenbankname, Benutzer und Passwort notieren.
2. `config.example.php` als `config.php` in den Projektordner kopieren und Zugangsdaten eintragen. `config.php` liegt bewusst eine Ebene oberhalb des Web-Verzeichnisses und darf nicht öffentlich abrufbar sein.
3. Den Inhalt des Ordners `public/` in das Web-Verzeichnis der Subdomain laden. Alternativ die Subdomain im KAS direkt auf diesen Ordner zeigen lassen.
4. Neben `public/` einen Ordner `data/` anlegen. PHP muss dort Schreibrechte für seine Bildordner haben. Die Datenbanktabellen werden beim ersten API-Aufruf automatisch angelegt.
5. HTTPS für die Subdomain aktivieren und anschließend die Adresse im Browser öffnen. Beim ersten Aufruf werden Familienname, Mitglieder und Familienpasswort eingerichtet.

Die Installationsansicht braucht PHP 8.1 oder neuer mit PDO-MySQL, JSON und Sessions. Für die Wetterabfrage muss PHP ausgehende HTTPS-Verbindungen zu Open-Meteo öffnen dürfen (`allow_url_fopen` oder ein entsprechender Provider-Schalter). Bilder, Datenbankzugänge und Familiendaten gehören nicht ins öffentliche Web-Verzeichnis. Datenbank und `data/` regelmäßig gemeinsam sichern.

Beispiel für `config.php`:

```php
<?php
return [
    'dsn' => 'mysql:host=IHRE_DB_HOST;dbname=IHRE_DB;charset=utf8mb4',
    'username' => 'IHR_DB_BENUTZER',
    'password' => 'IHR_DB_PASSWORT',
    'version' => '0.1.0-allinkl',
];
```

## Stand der Portierung

Die vorhandene Oberfläche und die responsive Kalenderansicht sind übernommen. Die PHP-API enthält Einrichtung, Anmeldung, Datenzustand, allgemeine Datensatzverwaltung, Aufgabenabschluss/-zuweisung, Punkte und Belohnungen, Einstellungen, Datenexport, Wetterorte/-vorhersage und lokale Bild-Uploads.

Noch nicht portiert sind Google-Kalender-OAuth und -Sync, Immich und externe Bilderlisten, Rezept-Link-Import, automatische Updates und der automatische Einkaufslisten-Abgleich. Für Softwareupdates werden die Dateien manuell per SFTP/FTP eingespielt; die MariaDB-Daten bleiben dabei erhalten.

## Lizenz

MIT – siehe `LICENSE`.
