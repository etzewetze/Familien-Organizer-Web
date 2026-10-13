<?php
declare(strict_types=1);

// Shared-hosting API entry point. The browser UI is served as static files;
// all persistent state lives in MariaDB, never in the public web directory.
const APP_KINDS = ['members', 'events', 'birthdays', 'tasks', 'recipes', 'meals', 'lists', 'items', 'notes', 'rewards', 'pointAwards'];
const APP_SETTINGS = [
    'familyName' => 'Unsere Familie', 'timezone' => 'Europe/Berlin', 'photoInterval' => 15, 'photoFit' => 'contain',
    'remoteManifestUrl' => '', 'immichUrl' => '', 'immichAlbumId' => '',
    'allColor' => '#6366f1', 'backgroundColor' => '#f5f6fb', 'headerColor' => '#ffffff',
    'surfaceColor' => '#ffffff', 'navColor' => '#172237', 'accentColor' => '#4f46e5', 'textColor' => '#20283d',
    'backgroundImage' => '', 'calendarHourSize' => 64, 'calendarAutoWidth' => true, 'calendarCompactHours' => true,
];
const APP_RELEASES = [['version'=>'0.1.0-allinkl','date'=>'2026-10-13','title'=>'ALL-INKL-Webhosting-Variante','changes'=>[
    'Eigenständige PHP/MariaDB-API für Shared Hosting; die Oberfläche läuft weiterhin als statische Web-App.',
    'Einrichtung, Anmeldung, Kalender- und Aufgabendaten werden auf dem Webspace gespeichert.',
    'Wetteransicht ist an Open-Meteo angebunden; automatische Softwareupdates und Google-Kalendersynchronisierung sind in dieser Variante noch nicht enthalten.'
]]];

function respond(mixed $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(string $message, int $status = 400): never { respond(['error' => $message], $status); }
function inputJson(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 1048576) fail('Anfrage ist zu groß.', 413);
    $data = json_decode($raw, true);
    if (!is_array($data)) fail('Ungültige JSON-Anfrage.');
    return $data;
}
function pdo(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    $configPath = dirname(__DIR__) . '/config.php';
    if (!is_file($configPath)) fail('Bitte zuerst config.php mit den ALL-INKL-Datenbankzugangsdaten anlegen.', 503);
    $config = require $configPath;
    try {
        $db = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE IF NOT EXISTS fo_meta (meta_key VARCHAR(64) PRIMARY KEY, meta_value LONGTEXT NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS fo_records (kind VARCHAR(24) NOT NULL, record_id VARCHAR(100) NOT NULL, record_json LONGTEXT NOT NULL, revision INT NOT NULL, PRIMARY KEY(kind,record_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS fo_completions (task_id VARCHAR(100) NOT NULL, day_key VARCHAR(10) NOT NULL, member_id VARCHAR(100) NOT NULL, points INT NOT NULL, title VARCHAR(160) NOT NULL, completed_at VARCHAR(40) NOT NULL, PRIMARY KEY(task_id,day_key)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS fo_redemptions (id VARCHAR(100) PRIMARY KEY, member_id VARCHAR(100) NOT NULL, reward_id VARCHAR(100) NOT NULL, title VARCHAR(160) NOT NULL, cost INT NOT NULL, redeemed_at VARCHAR(40) NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS fo_login_attempts (ip_hash CHAR(64) PRIMARY KEY, attempts INT NOT NULL, window_started INT NOT NULL)");
        $db->exec("INSERT IGNORE INTO fo_meta(meta_key,meta_value) VALUES ('revision','0'),('weather','{\"locations\":[],\"primaryId\":\"\"}')");
    } catch (Throwable $e) { error_log('Organizer database error: ' . $e->getMessage()); fail('Datenbankverbindung fehlgeschlagen. Zugangsdaten in config.php prüfen.', 503); }
    return $db;
}
function meta(string $key, mixed $fallback = null): mixed {
    $q = pdo()->prepare('SELECT meta_value FROM fo_meta WHERE meta_key=?'); $q->execute([$key]); $v = $q->fetchColumn();
    return $v === false ? $fallback : json_decode((string)$v, true);
}
function setMeta(string $key, mixed $value): void {
    $q = pdo()->prepare('INSERT INTO fo_meta(meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');
    $q->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
function bump(): int { $n = (int)meta('revision', 0) + 1; setMeta('revision', $n); return $n; }
function allRecords(string $kind): array {
    $q = pdo()->prepare('SELECT record_id,record_json,revision FROM fo_records WHERE kind=? ORDER BY record_id'); $q->execute([$kind]);
    return array_map(static function ($r) { return [...json_decode($r['record_json'], true), 'id' => $r['record_id'], '_rev' => (int)$r['revision']]; }, $q->fetchAll());
}
function record(string $kind, string $id): ?array {
    $q = pdo()->prepare('SELECT record_json,revision FROM fo_records WHERE kind=? AND record_id=?'); $q->execute([$kind,$id]); $r=$q->fetch();
    return $r ? [...json_decode($r['record_json'],true),'id'=>$id,'_rev'=>(int)$r['revision']] : null;
}
function putRecord(string $kind, string $id, array $data): array {
    unset($data['id'],$data['_rev']); $rev=bump();
    $q=pdo()->prepare('INSERT INTO fo_records(kind,record_id,record_json,revision) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE record_json=VALUES(record_json),revision=VALUES(revision)');
    $q->execute([$kind,$id,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rev]);
    return [...$data,'id'=>$id,'_rev'=>$rev];
}
function newId(): string { return bin2hex(random_bytes(16)); }
function uploadImage(string $area, int $maxBytes=5242880): array {
    $stream=fopen('php://input','rb');$bytes=$stream?stream_get_contents($stream,$maxBytes+1):false;if($stream)fclose($stream);if ($bytes===false||strlen($bytes)>$maxBytes) fail('Das Bild ist zu groß.',413);
    $sig=substr($bytes,0,12); $ext='';
    if (str_starts_with($sig,"\xFF\xD8\xFF")) $ext='jpg';
    elseif (str_starts_with($sig,"\x89PNG\r\n\x1a\n")) $ext='png';
    elseif (substr($sig,0,6)==='GIF87a'||substr($sig,0,6)==='GIF89a') $ext='gif';
    elseif (substr($sig,0,4)==='RIFF'&&substr($sig,8,4)==='WEBP') $ext='webp';
    if ($ext==='') fail('Bitte ein JPEG-, PNG-, GIF- oder WebP-Bild auswählen.',415);
    $hex=bin2hex(random_bytes(16));$uuid=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);$name=$uuid.'.'.$ext;$dir=dirname(__DIR__).'/data/'.$area;
    if (!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) fail('Bildspeicher konnte nicht angelegt werden.',500);
    if (file_put_contents($dir.'/'.$name,$bytes,LOCK_EX)===false) fail('Bild konnte nicht gespeichert werden.',500);
    return ['name'=>$name,'url'=>'/api/photos/file?file='.rawurlencode($name)];
}
function sendImage(string $area,string $name): never {
    if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\.(jpg|png|gif|webp)$/',$name)) fail('Bild nicht gefunden.',404);
    $file=dirname(__DIR__).'/data/'.$area.'/'.$name;if(!is_file($file))fail('Bild nicht gefunden.',404);
    $mime=['jpg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'][pathinfo($name,PATHINFO_EXTENSION)];
    header('Content-Type: '.$mime);header('Cache-Control: private, max-age=3600');readfile($file);exit;
}
function remoteJson(string $url): array {
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>12,'header'=>"Accept: application/json\r\nUser-Agent: FamilienOrganisierer/All-INKL\r\n"]]);
    $raw=@file_get_contents($url,false,$ctx);if($raw===false||strlen($raw)>2097152)fail('Der Wetterdienst ist gerade nicht erreichbar.',503);
    $data=json_decode($raw,true);if(!is_array($data)||!empty($data['error']))fail('Der Wetterdienst hat keine gültigen Daten geliefert.',503);return $data;
}
function weatherPlace(array $p): array {
    $lat=(float)($p['latitude']??NAN);$lon=(float)($p['longitude']??NAN);
    if(!is_finite($lat)||!is_finite($lon)||$lat < -90||$lat>90||$lon < -180||$lon>180)fail('Ungültige Ortskoordinaten.');
    $id=substr(hash('sha256',number_format($lat,5,'.','').','.number_format($lon,5,'.','')),0,20);
    return ['id'=>$id,'name'=>requiredText($p,'name',100),'region'=>substr((string)($p['region']??''),0,100),'country'=>substr((string)($p['country']??''),0,100),'latitude'=>$lat,'longitude'=>$lon,'timezone'=>substr((string)($p['timezone']??'UTC'),0,80)];
}
function weatherRows(array $source,array $fields,int $max): array {
    $rows=[];$times=$source['time']??[];foreach(array_slice($times,0,$max) as $i=>$time){if(!is_numeric($time))continue;$row=['time'=>(int)$time];foreach($fields as $field)$row[$field]=isset($source[$field][$i])&&is_numeric($source[$field][$i])?(float)$source[$field][$i]:null;$rows[]=$row;}return $rows;
}
function weatherForecast(array $place): array {
    $currentFields=['temperature_2m','relative_humidity_2m','apparent_temperature','is_day','precipitation','weather_code','cloud_cover','pressure_msl','wind_speed_10m','wind_direction_10m','wind_gusts_10m'];
    $hourFields=['temperature_2m','relative_humidity_2m','apparent_temperature','precipitation_probability','precipitation','weather_code','wind_speed_10m'];
    $dayFields=['weather_code','temperature_2m_max','temperature_2m_min','apparent_temperature_max','apparent_temperature_min','precipitation_sum','precipitation_probability_max','rain_sum','snowfall_sum','wind_speed_10m_max','wind_gusts_10m_max','wind_direction_10m_dominant','uv_index_max','sunrise','sunset','sunshine_duration','daylight_duration'];
    $params=['latitude'=>$place['latitude'],'longitude'=>$place['longitude'],'timezone'=>'auto','forecast_days'=>14,'timeformat'=>'unixtime','temperature_unit'=>'celsius','wind_speed_unit'=>'kmh','precipitation_unit'=>'mm','current'=>implode(',',$currentFields),'hourly'=>implode(',',$hourFields),'daily'=>implode(',',$dayFields)];
    $raw=remoteJson('https://api.open-meteo.com/v1/forecast?'.http_build_query($params));
    $current=['time'=>(int)($raw['current']['time']??time())];foreach($currentFields as $field)$current[$field]=isset($raw['current'][$field])&&is_numeric($raw['current'][$field])?(float)$raw['current'][$field]:null;
    return ['location'=>$place,'timezone'=>(string)($raw['timezone']??$place['timezone']),'fetchedAt'=>gmdate(DATE_ATOM),'stale'=>false,'current'=>$current,'hourly'=>weatherRows($raw['hourly']??[],$hourFields,14*24+24),'daily'=>weatherRows($raw['daily']??[],$dayFields,14)];
}
function requiredText(array $data,string $key,int $max=200): string {
    $v=trim((string)($data[$key]??'')); if ($v==='' || strlen($v)>$max*4) fail('Bitte „'.$key.'“ prüfen.'); return $v;
}
function checkedDate(string $v, bool $optional=false): string {
    if ($optional && $v==='') return '';
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); if (!$d || $d->format('Y-m-d')!==$v) fail('Ungültiges Datum.'); return $v;
}
function getBodyAllowed(): void {
    $m=$_SERVER['REQUEST_METHOD']??'GET'; if (in_array($m,['GET','HEAD','OPTIONS'],true)) return;
    if (($_SERVER['HTTP_X_FAMILY_REQUEST']??'')!=='1') fail('Ungültige Anfragequelle.',403);
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if ($origin!=='') { $host=parse_url($origin,PHP_URL_HOST); if (!$host || strtolower($host)!==strtolower($_SERVER['HTTP_HOST']??'')) fail('Anfragen von fremden Seiten sind gesperrt.',403); }
}
function stateData(string $version): array {
    $kinds=[]; foreach (APP_KINDS as $kind) $kinds[$kind]=allRecords($kind);
    $settings=array_merge(APP_SETTINGS,(array)meta('settings',[])); $members=$kinds['members'];
    $completions=pdo()->query('SELECT task_id,day_key AS day,member_id,points,title,completed_at FROM fo_completions')->fetchAll();
    $redemptions=pdo()->query('SELECT id,member_id,reward_id,title,cost,redeemed_at FROM fo_redemptions ORDER BY redeemed_at DESC LIMIT 100')->fetchAll();
    $points=[];
    foreach ($members as $m) {
        $q=pdo()->prepare('SELECT COALESCE(SUM(points),0) FROM fo_completions WHERE member_id=?');$q->execute([$m['id']]);$earned=(int)$q->fetchColumn();
        foreach ($kinds['pointAwards'] as $a) if (($a['memberId']??'')===$m['id']) $earned+=(int)($a['points']??0);
        $q=pdo()->prepare('SELECT COALESCE(SUM(cost),0) FROM fo_redemptions WHERE member_id=?');$q->execute([$m['id']]);$spent=(int)$q->fetchColumn();
        $points[$m['id']]=['earned'=>$earned,'spent'=>$spent,'available'=>$earned-$spent];
    }
    return [...$kinds,'revision'=>(int)meta('revision',0),'settings'=>$settings,'weather'=>meta('weather',['locations'=>[],'primaryId'=>'']),
        'serverDate'=>(new DateTimeImmutable('now',new DateTimeZone($settings['timezone']?:'Europe/Berlin')))->format('Y-m-d'),
        'completions'=>$completions,'redemptions'=>$redemptions,'points'=>$points,'google'=>[],'pendingSync'=>[],
        'immichConfigured'=>false,'parentPasswordConfigured'=>!!meta('parentPassword'),'version'=>$version];
}

$configPath=dirname(__DIR__).'/config.php'; $cfg=is_file($configPath)?require $configPath:[]; $version=$cfg['version']??'0.1.0-allinkl';
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'"); header('Cache-Control: no-store');
session_name('family_session'); session_set_cookie_params(['lifetime'=>2592000,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']); session_start();
getBodyAllowed(); $db=pdo(); $path=parse_url($_SERVER['REQUEST_URI']??'/api',PHP_URL_PATH)?:'/api'; $path=preg_replace('#^/api#','',$path)?:'/'; $method=$_SERVER['REQUEST_METHOD']??'GET';
try {
    if ($path==='/health' && $method==='GET') respond(['ok'=>true,'version'=>$version]);
    if ($path==='/status' && $method==='GET') respond(['configured'=>!!meta('password'),'authenticated'=>!empty($_SESSION['family_ok']),'version'=>$version,'googleConfigured'=>false,'platform'=>'allinkl']);
    if ($path==='/setup' && $method==='POST') {
        if (meta('password')) fail('Diese Familienzentrale ist bereits eingerichtet.',409);
        $d=inputJson(); $pw=(string)($d['password']??''); if (strlen($pw)<12||strlen($pw)>200) fail('Das Familienpasswort braucht 12 bis 200 Zeichen.');
        $names=$d['names']??[]; if (!is_array($names)||count($names)<1||count($names)>20) fail('Bitte 1 bis 20 Familienmitglieder anlegen.');
        $db->beginTransaction(); try { setMeta('password',password_hash($pw,PASSWORD_DEFAULT)); setMeta('settings',array_merge(APP_SETTINGS,['familyName'=>trim((string)($d['familyName']??'Unsere Familie'))]));
            $colors=['#6366f1','#ec4899','#0ea5e9','#f59e0b','#10b981','#8b5cf6']; foreach(array_values($names) as $i=>$name) putRecord('members',newId(),['name'=>trim((string)$name),'color'=>$colors[$i%count($colors)],'role'=>$i<2?'adult':'child','avatarImage'=>'']);
            putRecord('lists','shopping',['title'=>'Einkaufsliste']); $db->commit();
        } catch(Throwable $e){$db->rollBack();throw $e;} session_regenerate_id(true);$_SESSION['family_ok']=true; respond(['ok'=>true],201);
    }
    if ($path==='/login' && $method==='POST') {
        $ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));$q=$db->prepare('SELECT attempts,window_started FROM fo_login_attempts WHERE ip_hash=?');$q->execute([$ip]);$limiter=$q->fetch();$now=time();
        if($limiter && $now-(int)$limiter['window_started']<600 && (int)$limiter['attempts']>=10)fail('Zu viele Anmeldeversuche. Bitte in 10 Minuten erneut versuchen.',429);
        $d=inputJson();if (!password_verify((string)($d['password']??''),(string)meta('password',''))) {
            $attempts=$limiter&&$now-(int)$limiter['window_started']<600?(int)$limiter['attempts']+1:1;$q=$db->prepare('INSERT INTO fo_login_attempts(ip_hash,attempts,window_started) VALUES (?,?,?) ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),window_started=VALUES(window_started)');$q->execute([$ip,$attempts,$limiter&&$now-(int)$limiter['window_started']<600?(int)$limiter['window_started']:$now]);fail('Das Passwort stimmt nicht.',401);
        }
        $q=$db->prepare('DELETE FROM fo_login_attempts WHERE ip_hash=?');$q->execute([$ip]);session_regenerate_id(true); $_SESSION['family_ok']=true; respond(['ok'=>true]);
    }
    if (str_starts_with($path,'/')) {
        if (empty($_SESSION['family_ok'])) { header('Set-Cookie: family_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0'); respond(['error'=>'Bitte erneut anmelden.','code'=>'SESSION_EXPIRED','version'=>$version],401); }
        if ($path==='/logout' && $method==='POST') { $_SESSION=[]; session_destroy(); respond(['ok'=>true]); }
        if ($path==='/state' && $method==='GET') respond(stateData($version));
        if ($path==='/changelog' && $method==='GET') respond(['version'=>$version,'previousVersion'=>'','changeId'=>$version,'releases'=>APP_RELEASES]);
        if ($path==='/export' && $method==='GET') { header('Content-Disposition: attachment; filename="familien-daten-'.date('Y-m-d').'.json"'); respond(['format'=>'familien-organisierer-export','version'=>$version,'exportedAt'=>gmdate(DATE_ATOM),...stateData($version)]); }
        if ($path==='/settings' && $method==='PUT') { $d=inputJson(); if ((int)($d['_revision']??-1)!==(int)meta('revision',0)) fail('Die Einstellungen wurden zwischenzeitlich geändert. Bitte neu laden.',409); unset($d['_revision']); $s=array_merge(APP_SETTINGS,(array)meta('settings',[])); foreach($s as $key=>$default) if(array_key_exists($key,$d)) $s[$key]=$d[$key]; try{new DateTimeZone((string)$s['timezone']);}catch(Throwable $e){fail('Ungültige Zeitzone.');} $s['photoInterval']=max(3,min(300,(int)$s['photoInterval'])); if(!in_array($s['photoFit'],['contain','cover'],true))$s['photoFit']='contain'; setMeta('settings',$s); bump(); respond($s); }
        if ($path==='/weather/locations' && $method==='PUT') { $d=inputJson(); if ((int)($d['_revision']??-1)!==(int)meta('revision',0)) fail('Die Einstellungen wurden zwischenzeitlich geändert. Bitte neu laden.',409); $locations=array_map('weatherPlace',array_slice(array_values(array_filter($d['locations']??[],fn($p)=>is_array($p)&&isset($p['latitude'],$p['longitude'],$p['name']))),0,8));$primary=(string)($d['primaryId']??'');if($primary!==''&&!in_array($primary,array_column($locations,'id'),true))fail('Den Wetterort für den Header bitte aus den gespeicherten Orten auswählen.');$weather=['locations'=>$locations,'primaryId'=>$primary?:($locations[0]['id']??'')]; setMeta('weather',$weather); bump(); respond($weather); }
        if ($path==='/updates/status' && $method==='GET') respond(['supported'=>false,'phase'=>'unavailable','message'=>'Updates werden bei der ALL-INKL-Version manuell per SFTP/FTP eingespielt.','currentVersion'=>$version]);
        if (str_starts_with($path,'/updates/')) fail('Updates werden bei der ALL-INKL-Version manuell per SFTP/FTP eingespielt.',501);
        if ($path==='/recipes/import' && $method==='POST') fail('Der Rezeptimport wird in dieser ALL-INKL-Version noch nicht unterstützt.',501);
        if ($path==='/weather/search' && $method==='GET') { $query=trim((string)($_GET['q']??''));if(strlen($query)<2)fail('Bitte mindestens zwei Zeichen für die Ortssuche eingeben.');$data=remoteJson('https://geocoding-api.open-meteo.com/v1/search?'.http_build_query(['name'=>$query,'count'=>8,'language'=>'de','format'=>'json']));$places=[];foreach(array_slice($data['results']??[],0,8) as $r){try{$places[]=weatherPlace(['name'=>$r['name']??'','region'=>$r['admin1']??'','country'=>$r['country']??'','latitude'=>$r['latitude']??NAN,'longitude'=>$r['longitude']??NAN,'timezone'=>$r['timezone']??'UTC']);}catch(Throwable $e){}}respond($places); }
        if ($path==='/weather/forecast' && $method==='GET') { $weather=meta('weather',['locations'=>[]]);$place=null;foreach($weather['locations'] as $p)if($p['id']===(string)($_GET['location']??''))$place=$p;if(!$place)fail('Wetterort nicht gefunden. Bitte zuerst einen Ort hinzufügen.',404);respond(weatherForecast($place)); }
        if ($path==='/photos/upload' && $method==='POST') respond(uploadImage('photos',20971520),201);
        if ($path==='/photos' && $method==='GET') {
            $dir=dirname(__DIR__).'/data/photos';$items=[];
            if(is_dir($dir))foreach(scandir($dir)?:[] as $name)if(preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\.(jpg|png|gif|webp)$/',$name))$items[]=['id'=>$name,'name'=>$name,'url'=>'/api/photos/file?file='.rawurlencode($name)];
            respond($items);
        }
        if ($path==='/photos/file' && $method==='GET') sendImage('photos',(string)($_GET['file']??''));
        if ($path==='/images/ui' && $method==='POST') respond(['imageFile'=>uploadImage('ui')['name']],201);
        if ($path==='/images/ui' && $method==='GET') sendImage('ui',(string)($_GET['file']??''));
        if ($path==='/tasks/image' && $method==='POST') respond(['imageFile'=>uploadImage('tasks')['name']],201);
        if ($path==='/tasks/image' && $method==='GET') sendImage('tasks',(string)($_GET['file']??''));
        if ($path==='/shopping/generate' && $method==='POST') respond(['count'=>0]);
        if (preg_match('#^/records/([a-zA-Z]+)/?([a-zA-Z0-9-]*)$#',$path,$m) && in_array($m[1],APP_KINDS,true)) {
            $kind=$m[1];$id=$m[2]??'';
            if ($kind==='pointAwards') fail('Punktebuchungen müssen über die geschützte Punktefunktion erfolgen.',403);
            if (($method==='POST'&&!$id)||($method==='PUT'&&$id)) {
                $d=inputJson();$old=$id?record($kind,$id):null;if($id&&!$old)fail('Eintrag wurde nicht gefunden.',404);if($old&&(int)($d['_rev']??-1)!==$old['_rev'])fail('Der Eintrag wurde auf einem anderen Gerät geändert. Bitte neu öffnen.',409);
                if($kind!=='meals') requiredText($d,($kind==='members'||$kind==='birthdays')?'name':'title',160);
                if($kind==='events'){checkedDate((string)($d['startDate']??''));checkedDate((string)($d['endDate']??$d['startDate']??''));}
                if($kind==='tasks'){$d['points']=max(0,min(1000,(int)($d['points']??0)));if(!in_array($d['repeat']??'none',['none','daily','weekdays','weekly'],true))fail('Ungültige Wiederholung.');}
                if($kind==='meals'){checkedDate((string)($d['date']??''));$id=$id?:((string)$d['date'].'-'.((string)($d['slot']??'dinner')));}
                unset($d['_rev']); respond(putRecord($kind,$id?:newId(),$d),$old?200:201);
            }
            if($method==='DELETE'&&$id){$old=record($kind,$id);if(!$old)fail('Eintrag wurde nicht gefunden.',404);$d=inputJson();if((int)($d['_rev']??-1)!==$old['_rev'])fail('Der Eintrag wurde zwischenzeitlich geändert. Bitte neu öffnen.',409);if($kind==='members')fail('Familienmitglieder bleiben wegen der Punktehistorie erhalten.');if($kind==='lists'&&$id==='shopping')fail('Die Einkaufsliste bleibt erhalten.');if($kind==='recipes'){foreach(allRecords('meals') as $meal)if(($meal['recipeId']??'')===$id)fail('Dieses Rezept ist noch im Essensplan. Zuerst dort entfernen.');}if($kind==='lists'){foreach(allRecords('items') as $item)if(($item['listId']??'')===$id){$del=$db->prepare('DELETE FROM fo_records WHERE kind=? AND record_id=?');$del->execute(['items',$item['id']]);}}$q=$db->prepare('DELETE FROM fo_records WHERE kind=? AND record_id=?');$q->execute([$kind,$id]);bump();respond(['ok'=>true]);}
        }
        if (preg_match('#^/tasks/([a-zA-Z0-9-]+)/(complete|assign)$#',$path,$m) && $method==='POST') {
            $d=inputJson();$task=record('tasks',$m[1]);if(!$task)fail('Aufgabe nicht gefunden.',404);
            if($m[2]==='assign'){if((int)($d['_rev']??-1)!==$task['_rev'])fail('Die Aufgabe wurde auf einem anderen Gerät geändert. Bitte neu laden.',409);$task['memberId']=(string)($d['memberId']??'');respond(putRecord('tasks',$m[1],$task));}
            $date=checkedDate((string)($d['date']??''));$key=($task['repeat']??'none')==='none'?'once':$date;$q=$db->prepare('INSERT IGNORE INTO fo_completions VALUES (?,?,?,?,?,?)');$q->execute([$m[1],$key,(string)(!empty($task['memberId'])?$task['memberId']:($d['memberId']??'')),(int)($task['points']??0),(string)$task['title'],gmdate(DATE_ATOM)]);
            bump();respond(['ok'=>true]);
        }
        if ($path==='/points/award' && $method==='POST') { $d=inputJson();$parent=meta('parentPassword');if(!$parent||!password_verify((string)($d['password']??''),(string)$parent))fail('Das Elternpasswort stimmt nicht.',403);$id=(string)($d['requestId']??'');$memberId=(string)($d['memberId']??'');$points=(int)($d['points']??0);$reason=trim((string)($d['reason']??''));if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$id)||!record('members',$memberId)||$points<1||$points>100000||$reason===''||strlen($reason)>640)fail('Ungültige Punktebuchung.');if(record('pointAwards',$id))fail('Diese Punktebuchung wurde bereits erfasst.',409);respond(putRecord('pointAwards',$id,['memberId'=>$memberId,'points'=>$points,'reason'=>$reason,'awardedAt'=>gmdate(DATE_ATOM)]),201); }
        if (preg_match('#^/rewards/([a-zA-Z0-9-]+)/redeem$#',$path,$m) && $method==='POST') {
            $d=inputJson();$memberId=(string)($d['memberId']??'');$reward=record('rewards',$m[1]);if(!$reward)fail('Belohnung nicht gefunden.',404);
            $points=stateData($version)['points'][$memberId]['available']??0;if($points<(int)$reward['cost'])fail('Dafür fehlen noch Punkte.');
            $id=(string)($d['requestId']??newId());$q=$db->prepare('INSERT IGNORE INTO fo_redemptions VALUES (?,?,?,?,?,?)');$q->execute([$id,$memberId,$reward['id'],$reward['title'],(int)$reward['cost'],gmdate(DATE_ATOM)]);bump();respond(['ok'=>true]);
        }
        if ($path==='/parent-password' && $method==='PUT') { $d=inputJson();$family=meta('password');if(!$family||!password_verify((string)($d['familyPassword']??''),(string)$family))fail('Das Familienpasswort stimmt nicht.',403);$old=meta('parentPassword');if($old&&!password_verify((string)($d['currentPassword']??''),(string)$old))fail('Das Elternpasswort stimmt nicht.',403);$new=(string)($d['newPassword']??'');if(strlen($new)<8||$new!==($d['confirmPassword']??''))fail('Bitte das neue Elternpasswort prüfen.');setMeta('parentPassword',password_hash($new,PASSWORD_DEFAULT));bump();respond(['ok'=>true]); }
        if ($path==='/password' && $method==='POST') { $d=inputJson();$old=meta('password');if(!$old||!password_verify((string)($d['currentPassword']??''),(string)$old))fail('Das bisherige Passwort stimmt nicht.',401);$new=(string)($d['newPassword']??'');if(strlen($new)<12||strlen($new)>200)fail('Das Familienpasswort braucht 12 bis 200 Zeichen.');setMeta('password',password_hash($new,PASSWORD_DEFAULT));session_regenerate_id(true);respond(['ok'=>true]); }
        fail('Funktion nicht gefunden.',404);
    }
} catch (Throwable $e) { error_log('Organizer API error: '.$e->getMessage()); fail('Ein Serverfehler ist aufgetreten. Bitte erneut versuchen.',500); }
