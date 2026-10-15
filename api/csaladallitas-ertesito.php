<?php
// Családállítás: feliratkozás a következő alkalmak értesítőjére (Brevo-lista + értesítő Gergőnek + visszaigazolás).
// Titkos adatok: a public_html MELLETTI lojangergo-config.php (lásd README).

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function fail($code, $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method');

$cfgPath = dirname(__DIR__, 2) . '/lojangergo-config.php';
if (!is_file($cfgPath)) fail(500, 'config');
$cfg = require $cfgPath;
foreach (['brevo_api_key', 'sender_email', 'sender_name'] as $k) if (empty($cfg[$k])) fail(500, 'config');
$listId = (int)($cfg['csaladallitas_list_id'] ?? 0); // ha még nincs megadva, csak az értesítő levél megy ki
$notify = $cfg['notify_email'] ?? $cfg['sender_email'];

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) fail(400, 'input');
if (!empty($in['weboldal'])) { echo json_encode(['ok' => true]); exit; }   // spam-csapda

$nev = trim(mb_substr((string)($in['nev'] ?? ''), 0, 60));
$email = trim(mb_strtolower((string)($in['email'] ?? '')));
if ($nev === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($in['hozzajarulas'])) fail(422, 'invalid');

// IP-nként óránként max. 5
$rlDir = sys_get_temp_dir() . '/lg-teszt-rl';
@mkdir($rlDir, 0700, true);
$rlFile = $rlDir . '/c-' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('YmdH'));
$cnt = (int)@file_get_contents($rlFile);
if ($cnt >= 5) fail(429, 'rate');
@file_put_contents($rlFile, (string)($cnt + 1));

function brevo($method, $path, $payload, $key) {
    $ch = curl_init('https://api.brevo.com/v3' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
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
$key = $cfg['brevo_api_key'];

// mezők létrehozása (egyszer)
$flag = $rlDir . '/attrs-csalad-ok';
if (!is_file($flag)) {
    brevo('POST', '/contacts/attributes/normal/CSALAD_FELIRATKOZAS', ['type' => 'date'], $key);
    @touch($flag);
}

$listaOk = false;
if ($listId > 0) {
    [$c1, $b1] = brevo('POST', '/contacts', [
        'email' => $email,
        'attributes' => ['FIRSTNAME' => $nev, 'CSALAD_FELIRATKOZAS' => date('Y-m-d')],
        'listIds' => [$listId],
        'updateEnabled' => true,
    ], $key);
    $listaOk = $c1 < 300;
    if (!$listaOk) error_log('Brevo csalad contact ' . $c1 . ' ' . $b1);
}

// értesítő Gergőnek (így akkor sem vész el jelentkező, ha a lista még nincs beállítva)
$e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
[$c2, $b2] = brevo('POST', '/smtp/email', [
    'sender' => ['email' => $cfg['sender_email'], 'name' => 'lojangergo.com'],
    'to' => [['email' => $notify]],
    'replyTo' => ['email' => $email, 'name' => $nev],
    'subject' => 'Új feliratkozó a családállítás-értesítőre: ' . $nev,
    'htmlContent' => '<p><strong>' . $e($nev) . '</strong> &lt;' . $e($email) . '&gt;</p><p>Feliratkozott a következő családállítások értesítőjére.<br>Időpont: ' . date('Y-m-d H:i') . '</p><p>'
        . ($listaOk ? 'Felkerült a Brevo-listára.' : 'FIGYELEM: a Brevo-listára NEM került fel, ezt kézzel kell pótolni.') . '</p>',
    'textContent' => "$nev <$email>\nFeliratkozott a családállítás-értesítőre.\nIdőpont: " . date('Y-m-d H:i') . "\n"
        . ($listaOk ? 'Felkerült a Brevo-listára.' : 'FIGYELEM: a Brevo-listára NEM került fel, ezt kézzel kell pótolni.'),
    'tags' => ['csalad-feliratkozas'],
], $key);
if ($c2 >= 300) error_log('Brevo csalad notify ' . $c2 . ' ' . $b2);

// visszaigazolás a jelentkezőnek
$visszaHtml = '<!doctype html><html lang="hu"><body style="margin:0;background:#F5EDE0;font-family:Arial,Helvetica,sans-serif;color:#2A211C">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5EDE0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF">
<tr><td style="background:#4F5A2A;color:#FFFFFF;padding:32px"><h1 style="margin:0;font-size:36px;line-height:1;text-transform:uppercase">Megkaptam!</h1></td></tr>
<tr><td style="padding:32px;font-size:16px;line-height:1.6">
<p style="margin:0 0 16px">Szia ' . $e($nev) . '!</p>
<p style="margin:0 0 16px">Feliratkoztál a tematikus családállítások értesítőjére.</p>
<p style="margin:0 0 16px">Amint meghirdetem a következő alkalmat, e-mailben elküldöm a témát, az időpontot és a jelentkezés módját. A feliratkozás nem kötelez semmire, és bármikor leiratkozhatsz: elég, ha válaszolsz erre a levélre.</p>
<p style="margin:0 0 16px">Ha kérdésed van, csak válaszolj erre a levélre.</p>
<p style="margin:24px 0 0">Gergő</p>
</td></tr>
<tr><td style="padding:20px 32px;font-size:12px;color:#5A4C42;background:#EADFCB">Ezt a levelet azért kaptad, mert feliratkoztál a családállítás-értesítőre a lojangergo.com oldalon.</td></tr>
</table></td></tr></table></body></html>';
[$c3, $b3] = brevo('POST', '/smtp/email', [
    'sender' => ['email' => $cfg['sender_email'], 'name' => $cfg['sender_name']],
    'replyTo' => ['email' => $cfg['sender_email'], 'name' => $cfg['sender_name']],
    'to' => [['email' => $email, 'name' => $nev]],
    'subject' => 'Feliratkoztál a családállítás-értesítőre',
    'htmlContent' => $visszaHtml,
    'textContent' => "Szia $nev!\n\nFeliratkoztál a tematikus családállítások értesítőjére.\n\nAmint meghirdetem a következő alkalmat, e-mailben elküldöm a témát, az időpontot és a jelentkezés módját. Bármikor leiratkozhatsz: elég, ha válaszolsz erre a levélre.\n\nGergő\n",
    'tags' => ['csalad-visszaigazolas'],
], $key);
if ($c3 >= 300) error_log('Brevo csalad confirm ' . $c3 . ' ' . $b3);

if (!$listaOk && $c2 >= 300) fail(502, 'brevo');
echo json_encode(['ok' => true, 'lista' => $listaOk, 'ertesito' => $c2 < 300, 'visszaigazolas' => $c3 < 300]);
