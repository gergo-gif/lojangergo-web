<?php
// Teszt-eredmény küldése e-mailben + feliratkozás a Brevo-listára.
// A titkos adatok NEM itt vannak: a public_html MELLETTI lojangergo-config.php-ban (lásd README).

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function fail($code, $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method');

$cfgPath = dirname(__DIR__, 2) . '/lojangergo-config.php';
if (!is_file($cfgPath)) fail(500, 'config');
$cfg = require $cfgPath;
foreach (['brevo_api_key', 'list_id', 'sender_email', 'sender_name'] as $k) if (empty($cfg[$k])) fail(500, 'config');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) fail(400, 'input');

// spam-csapda: ezt a mezőt ember nem látja és nem tölti ki
if (!empty($in['weboldal'])) { echo json_encode(['ok' => true]); exit; }

$nev = trim(mb_substr((string)($in['nev'] ?? ''), 0, 60));
$email = trim(mb_strtolower((string)($in['email'] ?? '')));
$elem = (string)($in['elem'] ?? '');
$masodik = (string)($in['masodik'] ?? '');
$hirlevel = !empty($in['hirlevel']);   // 1. pipa: hírlevél (nem kötelező)
$eredmeny = !empty($in['eredmeny']);   // 2. pipa: teszteredmény e-mailben (kötelező)

$ELEMEK = ['L', 'T', 'V', 'F'];
if ($nev === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($elem, $ELEMEK, true) || !$eredmeny) fail(422, 'invalid');
if (!in_array($masodik, $ELEMEK, true) || $masodik === $elem) $masodik = '';

// egyszerű korlát: IP-nként óránként max. 5 beküldés
$rlDir = sys_get_temp_dir() . '/lg-teszt-rl';
@mkdir($rlDir, 0700, true);
$rlFile = $rlDir . '/' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('YmdH'));
$cnt = (int)@file_get_contents($rlFile);
if ($cnt >= 5) fail(429, 'rate');
@file_put_contents($rlFile, (string)($cnt + 1));

$R = require __DIR__ . '/../private/teszt-eredmenyek.php';
$r = $R[$elem];
$s = $masodik ? $R[$masodik] : null;

function brevo($path, $payload, $key) {
    $ch = curl_init('https://api.brevo.com/v3' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function brevo_attr($name, $type, $key) {
    $ch = curl_init('https://api.brevo.com/v3/contacts/attributes/normal/' . $name);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode(['type' => $type]),
    ]);
    curl_exec($ch); curl_close($ch); // ha már létezik, a Brevo hibát ad: ez rendben van
}

// 0) a saját mezők létrehozása a Brevóban (csak egyszer fut le)
$flag = $rlDir . '/attrs-ok';
if (!is_file($flag)) {
    brevo_attr('ELEM', 'text', $cfg['brevo_api_key']);
    brevo_attr('MASODIK_ELEM', 'text', $cfg['brevo_api_key']);
    brevo_attr('TESZT_DATUM', 'date', $cfg['brevo_api_key']);
    @touch($flag);
}

// 1) hírlevél-feliratkozás: csak ha az 1. pipát bejelölte
if ($hirlevel) {
[$c1, $b1] = brevo('/contacts', [
    'email' => $email,
    'attributes' => [
        'FIRSTNAME' => $nev,
        'ELEM' => $r['name'],
        'MASODIK_ELEM' => $s ? $s['name'] : '',
        'TESZT_DATUM' => date('Y-m-d'),
    ],
    'listIds' => [(int)$cfg['list_id']],
    'updateEnabled' => true,
], $cfg['brevo_api_key']);
if ($c1 >= 300 && $c1 !== 204) { error_log('Brevo contact ' . $c1 . ' ' . $b1); fail(502, 'brevo'); }
}

// 2) az eredmény-levél
$e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
$todo = '';
foreach ($r['todo'] as $i => $t) $todo .= '<li style="margin:0 0 10px">' . $e($t) . '</li>';
$second = $s ? '<p style="margin:24px 0 0;font-size:15px;color:#5A4C42">Ui.: A második legerősebb elemed: <strong>' . $e($s['name']) . ' (' . $e($s['elem']) . ')</strong>. Ha a kettőt együtt, személyesen szeretnéd megnézni, <a href="https://lojangergo.com/egyeni-konzultacio/" style="color:#2A211C">foglalj egy első beszélgetést</a>.</p>' : '';

$html = '<!doctype html><html lang="hu"><body style="margin:0;background:#F5EDE0;font-family:Arial,Helvetica,sans-serif;color:#2A211C">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5EDE0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF">
<tr><td style="background:' . $r['bg'] . ';color:' . $r['fg'] . ';padding:36px 32px">
<p style="margin:0 0 8px;font-size:13px;letter-spacing:2px;text-transform:uppercase">A te elemed: ' . $e($r['elem']) . '</p>
<h1 style="margin:0;font-size:44px;line-height:1;text-transform:uppercase">' . $e($r['name']) . '</h1>
</td></tr>
<tr><td style="padding:32px;font-size:16px;line-height:1.6">
<p style="margin:0 0 16px">Szia ' . $e($nev) . '!</p>
<p style="margin:0 0 16px">Köszönöm, hogy kitöltötted a tesztet. Az eredményed: <strong>' . $e($r['elem']) . '</strong>, vagyis <strong>' . $e($r['name']) . '</strong>.</p>
<h2 style="margin:28px 0 8px;font-size:20px">Mit jelent ez?</h2>
<p style="margin:0 0 16px">' . $e($r['lead']) . '</p>
<h2 style="margin:28px 0 8px;font-size:20px">Honnan ismerheted fel?</h2>
<p style="margin:0 0 16px">' . $e($r['signs']) . '</p>
<h2 style="margin:28px 0 8px;font-size:20px">Amit már ma megtehetsz</h2>
<ol style="margin:0 0 16px;padding-left:20px">' . $todo . '</ol>
<h2 style="margin:28px 0 8px;font-size:20px">A következő lépés</h2>
<p style="margin:0 0 20px">' . $e($r['next']) . '</p>
<p style="margin:0 0 8px"><a href="' . $e($r['href']) . '" style="display:inline-block;background:' . $r['bg'] . ';color:' . $r['fg'] . ';text-decoration:none;font-weight:bold;padding:14px 22px">' . $e($r['cta']) . '</a></p>
' . $second . '
<p style="margin:28px 0 0">Gergő</p>
</td></tr>
<tr><td style="padding:20px 32px;font-size:12px;color:#5A4C42;background:#EADFCB">' . ($hirlevel
  ? 'Ezt a levelet azért kaptad, mert kitöltötted a tesztet a lojangergo.com oldalon, és feliratkoztál a hírlevelemre. Ha nem szeretnél több levelet, <a href="mailto:hello@lojangergo.com?subject=Leiratkoz%C3%A1s" style="color:#5A4C42">írj nekem egy rövid üzenetet</a>, vagy kattints bármelyik hírlevél alján a leiratkozás linkre.'
  : 'Ezt a levelet azért kaptad, mert kitöltötted a tesztet a lojangergo.com oldalon, és kérted az eredményedet. Ezen kívül nem küldök neked leveleket.') . '</td></tr>
</table></td></tr></table></body></html>';

// sima szöveges változat is (a spamszűrők jobban szeretik, ha van)
$txt = "Szia $nev!\n\nKöszönöm, hogy kitöltötted a tesztet. Az eredményed: {$r['elem']}, vagyis {$r['name']}.\n\n"
     . "MIT JELENT EZ?\n{$r['lead']}\n\nHONNAN ISMERHETED FEL?\n{$r['signs']}\n\nAMIT MÁR MA MEGTEHETSZ\n";
foreach ($r['todo'] as $i => $t) $txt .= ($i + 1) . ". $t\n";
$txt .= "\nA KÖVETKEZŐ LÉPÉS\n{$r['next']}\n{$r['cta']}: {$r['href']}\n";
if ($s) $txt .= "\nUi.: A második legerősebb elemed: {$s['name']} ({$s['elem']}).\n";
$txt .= "\nGergő\n";

[$c2, $b2] = brevo('/smtp/email', [
    'sender' => ['email' => $cfg['sender_email'], 'name' => $cfg['sender_name']],
    'replyTo' => ['email' => $cfg['sender_email'], 'name' => $cfg['sender_name']],
    'to' => [['email' => $email, 'name' => $nev]],
    'subject' => 'A te elemed: ' . $r['name'],
    'htmlContent' => $html,
    'textContent' => $txt,
    'tags' => ['teszt-eredmeny'],
], $cfg['brevo_api_key']);
if ($c2 >= 300) { error_log('Brevo email ' . $c2 . ' ' . $b2); fail(502, 'brevo'); }

echo json_encode(['ok' => true]);
