<?php
// Tréning-előregisztráció: Brevo-listára tesz + értesítést küld Gergőnek.
// Titkos adatok: a public_html MELLETTI lojangergo-config.php (lásd README).

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function fail($code, $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method');

$cfgPath = dirname(__DIR__, 2) . '/lojangergo-config.php';
if (!is_file($cfgPath)) fail(500, 'config');
$cfg = require $cfgPath;
foreach (['brevo_api_key', 'sender_email', 'sender_name'] as $k) if (empty($cfg[$k])) fail(500, 'config');
$listId = (int)($cfg['trening_list_id'] ?? 0);      // ha még nincs megadva, csak az értesítő levél megy ki
$notify = $cfg['notify_email'] ?? $cfg['sender_email'];

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) fail(400, 'input');
if (!empty($in['weboldal'])) { echo json_encode(['ok' => true]); exit; }   // spam-csapda

$nev = trim(mb_substr((string)($in['nev'] ?? ''), 0, 60));
$email = trim(mb_strtolower((string)($in['email'] ?? '')));
$muhely = (string)($in['muhely'] ?? '');
$forma = (string)($in['forma'] ?? '');
$MUHELYEK = ['Vonzerő Műhely', 'Belső Iránytű Műhely'];
$FORMAK = ['Online', 'Személyesen', 'Mindegy'];
if ($nev === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($in['hozzajarulas'])
    || !in_array($muhely, $MUHELYEK, true) || !in_array($forma, $FORMAK, true)) fail(422, 'invalid');

// IP-nként óránként max. 5
$rlDir = sys_get_temp_dir() . '/lg-teszt-rl';
@mkdir($rlDir, 0700, true);
$rlFile = $rlDir . '/t-' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('YmdH'));
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
$flag = $rlDir . '/attrs-trening-ok';
if (!is_file($flag)) {
    brevo('POST', '/contacts/attributes/normal/MUHELY', ['type' => 'text'], $key);
    brevo('POST', '/contacts/attributes/normal/FORMA', ['type' => 'text'], $key);
    brevo('POST', '/contacts/attributes/normal/ELOREG_DATUM', ['type' => 'date'], $key);
    @touch($flag);
}

$listaOk = false;
if ($listId > 0) {
    [$c1, $b1] = brevo('POST', '/contacts', [
        'email' => $email,
        'attributes' => ['FIRSTNAME' => $nev, 'MUHELY' => $muhely, 'FORMA' => $forma, 'ELOREG_DATUM' => date('Y-m-d')],
        'listIds' => [$listId],
        'updateEnabled' => true,
    ], $key);
    $listaOk = $c1 < 300;
    if (!$listaOk) error_log('Brevo trening contact ' . $c1 . ' ' . $b1);
}

// értesítő Gergőnek (így akkor sem vész el jelentkező, ha a lista még nincs beállítva)
$e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
[$c2, $b2] = brevo('POST', '/smtp/email', [
    'sender' => ['email' => $cfg['sender_email'], 'name' => 'lojangergo.com'],
    'to' => [['email' => $notify]],
    'replyTo' => ['email' => $email, 'name' => $nev],
    'subject' => 'Új tréning-előregisztráció: ' . $muhely . ' (' . $forma . ')',
    'htmlContent' => '<p><strong>' . $e($nev) . '</strong> &lt;' . $e($email) . '&gt;</p><p>Műhely: ' . $e($muhely)
        . '<br>Forma: ' . $e($forma) . '<br>Időpont: ' . date('Y-m-d H:i') . '</p><p>'
        . ($listaOk ? 'Felkerült a Brevo-listára.' : 'FIGYELEM: a Brevo-listára NEM került fel, ezt kézzel kell pótolni.') . '</p>',
    'textContent' => "$nev <$email>\nMűhely: $muhely\nForma: $forma\nIdőpont: " . date('Y-m-d H:i') . "\n"
        . ($listaOk ? 'Felkerült a Brevo-listára.' : 'FIGYELEM: a Brevo-listára NEM került fel, ezt kézzel kell pótolni.'),
    'tags' => ['trening-eloreg'],
], $key);
if ($c2 >= 300) error_log('Brevo trening notify ' . $c2 . ' ' . $b2);

if (!$listaOk && $c2 >= 300) fail(502, 'brevo');
echo json_encode(['ok' => true]);
