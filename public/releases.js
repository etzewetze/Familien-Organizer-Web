export const VERSION = '0.2.0-allinkl';
export const releases = [
  { version: '0.2.0-allinkl', date: '2026-10-13', title: 'Sicherer Webzugang', changes: [
    'Die Ersteinrichtung benötigt jetzt zusätzlich einen geheimen Schlüssel aus der config.php.',
    'HTTPS, geschützte Sitzungscookies, private Bildauslieferung und ein Anmeldeschutz für alle Familiendaten sind aktiviert.',
    'Nach Softwareupdates werden alte Sitzungen beendet; nach erneuter Anmeldung wird dieser Änderungsverlauf angezeigt.',
  ] },
];
