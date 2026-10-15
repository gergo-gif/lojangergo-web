<?php
// A következő családállítás szabad helyei a Stripe fizetési linkek alapján, 5 percig gyorsítótárazva.
// A Stripe-kulcs (csak olvasási jogú, "restricted key") a public_html MELLETTI lojangergo-config.php-ban van: 'stripe_key'.
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

// Fizetési linkek: ezt kell átírni, ha új alkalom jön.
const ALKALOM = [
    'allito' => ['url' => '', 'max' => 5],
    'segito' => ['url' => '', 'max' => 10],
];
const TTL = 300;

$cacheFile = sys_get_temp_dir() . '/lg-csaladallitas-helyek.json';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < TTL) { readfile($cacheFile); exit; }

$cfgPath = dirname(__DIR__, 2) . '/lojangergo-config.php';
$cfg = is_file($cfgPath) ? require $cfgPath : [];
$key = $cfg['stripe_key'] ?? '';
if ($key === '') { echo json_encode(['ok' => false]); exit; }

function stripe_get($path, $key) {
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERPWD => $key . ':']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 ? json_decode($body, true) : null;
}

// a link URL-jéből megkeressük a link azonosítóját
$linkek = stripe_get('/payment_links?limit=100', $key);
if (!$linkek) { echo json_encode(['ok' => false]); exit; }
$idByUrl = [];
foreach ($linkek['data'] as $l) $idByUrl[$l['url']] = $l['id'];

$ki = ['ok' => true];
foreach (ALKALOM as $nev => $a) {
    $id = $idByUrl[$a['url']] ?? null;
    if (!$id) { $ki[$nev] = null; continue; }
    $db = 0; $after = '';
    do {
        $r = stripe_get('/checkout/sessions?limit=100&status=complete&payment_link=' . urlencode($id) . $after, $key);
        if (!$r) { echo json_encode(['ok' => false]); exit; }
        foreach ($r['data'] as $s) if (($s['payment_status'] ?? '') === 'paid') $db++;
        $after = $r['has_more'] ? '&starting_after=' . end($r['data'])['id'] : '';
    } while ($after);
    $ki[$nev] = ['max' => $a['max'], 'szabad' => max(0, $a['max'] - $db)];
}
$json = json_encode($ki);
@file_put_contents($cacheFile, $json);
echo $json;
