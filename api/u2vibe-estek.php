<?php
// A u2vibe.com következő ismerkedős estjei (az eseményoldalak Event-adataiból), 30 percig gyorsítótárazva.
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=600');

const BASE = 'https://u2vibe.com';
const TTL = 1800;
$cacheFile = sys_get_temp_dir() . '/lg-u2vibe-estek.json';

if (is_file($cacheFile) && time() - filemtime($cacheFile) < TTL) { readfile($cacheFile); exit; }

function get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'lojangergo.com esemenyolvaso']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 ? $body : null;
}

function regi_cache($cacheFile) {
    if (is_file($cacheFile)) { readfile($cacheFile); exit; }
    echo json_encode(['ok' => false, 'estek' => []]); exit;
}

$lista = get(BASE . '/esemenyek/');
if (!$lista) regi_cache($cacheFile);
preg_match_all('#href="(/esemenyek/[a-z0-9\-]+/)"#', $lista, $m);
$utak = array_slice(array_values(array_unique($m[1])), 0, 12);

$NAPOK = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
$HONAP = ['', 'Jan', 'Feb', 'Már', 'Ápr', 'Máj', 'Jún', 'Júl', 'Aug', 'Szep', 'Okt', 'Nov', 'Dec'];
$tz = new DateTimeZone('Europe/Budapest');
$most = new DateTime('now', $tz);
$estek = [];

foreach ($utak as $ut) {
    $html = get(BASE . $ut);
    if (!$html) continue;
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ld);
    foreach ($ld[1] as $json) {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['@type'] ?? '') !== 'Event' || empty($d['startDate'])) continue;
        if (($d['eventStatus'] ?? '') === 'https://schema.org/EventCancelled') continue;
        $kezd = (new DateTime($d['startDate']))->setTimezone($tz);
        if ($kezd < $most) continue;
        $hely = $d['location']['name'] ?? '';
        $cim = $d['location']['address']['streetAddress'] ?? '';
        $cim = preg_replace('#^Budapest,\s*#u', '', $cim);
        $ar = $d['offers']['price'] ?? null;
        $estek[] = [
            'nev' => $d['name'] ?? 'Ismerkedős est',
            'kezdes' => $kezd->format(DATE_ATOM),
            'datum' => $HONAP[(int)$kezd->format('n')] . ' ' . $kezd->format('j') . '.',
            'nap' => $NAPOK[(int)$kezd->format('w')],
            'ido' => $kezd->format('G:i'),
            'helyszin' => $hely,
            'cim' => $cim,
            'ar' => $ar !== null ? number_format((float)$ar, 0, ',', ' ') . ' Ft' : '',
            'elfogyott' => str_ends_with((string)($d['offers']['availability'] ?? ''), 'SoldOut'),
            'url' => $d['url'] ?? (BASE . $ut),
        ];
        break;
    }
}

usort($estek, fn($a, $b) => strcmp($a['kezdes'], $b['kezdes']));
$ki = json_encode(['ok' => true, 'estek' => array_slice($estek, 0, 6)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($cacheFile, $ki);
echo $ki;
