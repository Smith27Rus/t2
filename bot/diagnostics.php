<?php
/**
 * Telegram Bot Diagnostics — Klever27 (one file, no placeholders)
 * Все проверки и операции в одном месте:
 * - getWebhookInfo, API speed, доступность /bot/data, размеры логов
 * - хвосты логов (error.log, php-error.log)
 * - самотест modules/Calendar.php
 * - симуляция вебхука: POST тестового update в index.php
 * - установка/удаление вебхука с secret_token
 * - управление файлами /bot/data и сброс opcache
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Vladivostok');

const BOT_TOKEN = '7971315021:AAEMCa6GGFThXVn3cqR4H43M48yg5InSCNY';
const API = 'https://api.telegram.org/bot'.BOT_TOKEN.'/';
const DATA_DIR = __DIR__ . '/data';
const SECRET = 'klever_webhook_secret_2025';

// Доступ по секрету
if (($_GET['secret'] ?? '') !== SECRET) {
    http_response_code(403);
    exit('<h1>403 Forbidden</h1>');
}

/* ===== helpers ===== */
function tg(string $method, array $params=[]): array {
    $ch = curl_init(API.$method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$params,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_CONNECTTIMEOUT=>6,
    ]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['raw'=>$out,'err'=>$err];
}
function jsond(?string $s){ $j=json_decode((string)$s,true); return is_array($j)?$j:null; }
function pingSpeed(int $n=5): array {
    $times=[];
    for($i=0;$i<$n;$i++){
        $t0=microtime(true);
        tg('getMe');
        $t1=microtime(true);
        $times[]=(int)(($t1-$t0)*1000);
        usleep(200000);
    }
    return $times;
}
function readableSize($bytes):string {
    $u=['B','KB','MB','GB']; $i=0;
    while($bytes>=1024 && $i<count($u)-1){$bytes/=1024;$i++;}
    return round($bytes,2).' '.$u[$i];
}
function tailFile(string $path, int $lines = 200): string {
    if (!is_file($path)) return "— нет файла —";
    $arr = @file($path, FILE_IGNORE_NEW_LINES);
    if (!$arr) return "— пусто —";
    $tail = array_slice($arr, -$lines);
    return htmlspecialchars(implode("\n", $tail));
}
function baseUrlTo(string $file): string {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/');
    return $scheme.'://'.$host.$dir.'/'.$file;
}
function postToLocalWebhook(array $update): array {
    $url = baseUrlTo('index.php');
    $ch = curl_init($url);
    $payload = json_encode($update, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['url'=>$url,'code'=>$code,'errno'=>$errno,'err'=>$err,'resp'=>$resp,'json'=>$payload];
}

/* ===== live data ===== */
$phpv = phpversion();
$curlv = function_exists('curl_version') ? curl_version() : null;
$dirOk = is_dir(DATA_DIR) && is_writable(DATA_DIR);
$files = @glob(DATA_DIR.'/*') ?: [];
$logSize = 0; foreach($files as $f){ $logSize += @filesize($f) ?: 0; }

$infoWebhookRaw = tg('getWebhookInfo')['raw'];
$infoWebhook = jsond($infoWebhookRaw) ?: [];
$speed = pingSpeed(5);

$indexUrl = baseUrlTo('index.php');
$diagUrl  = baseUrlTo('diagnostics.php').'?secret='.rawurlencode(SECRET);

$apiGetWebhookInfo = API.'getWebhookInfo';
$apiSetWebhook = API.'setWebhook?url='.rawurlencode($indexUrl).'&secret_token='.rawurlencode(SECRET);
$apiDeleteWebhook = API.'deleteWebhook?drop_pending_updates=true';

/* ===== operations (POST) ===== */
$opResult = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $op = $_POST['op'] ?? '';
    if ($op === 'clear') {
        $cnt=0; foreach (glob(DATA_DIR.'/u_*.json')?:[] as $f){ @unlink($f); $cnt++; }
        $opResult = "Удалено файлов состояний: $cnt";
    } elseif ($op === 'purge') {
        $cnt=0; foreach (glob(DATA_DIR.'/*')?:[] as $f){ @unlink($f); $cnt++; }
        $opResult = "Удалено файлов в /bot/data: $cnt";
    } elseif ($op === 'opcache') {
        if (function_exists('opcache_reset')) { opcache_reset(); $opResult="opcache_reset(): OK"; }
        else { $opResult = "opcache_reset(): функция недоступна"; }
    } elseif ($op === 'simulate') {
        $fake = [
            'update_id' => time(),
            'message' => [
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => 111222333, 'type' => 'private', 'first_name' => 'KleverTest'],
                'from' => ['id' => 111222333, 'is_bot'=>false, 'first_name'=>'KleverTest'],
                'text' => '/start',
            ],
        ];
        $r = postToLocalWebhook($fake);
        $opResult = "POST → {$r['url']}\nHTTP {$r['code']}  cURL errno {$r['errno']} ({$r['err']})\n\nPayload:\n{$r['json']}\n\n=== RAW RESPONSE (headers+body) ===\n{$r['resp']}";
    } elseif ($op === 'setHook') {
        $res = tg('setWebhook', ['url'=>$indexUrl, 'secret_token'=>SECRET, 'drop_pending_updates'=>false]);
        $opResult = "setWebhook(". $indexUrl ."):\n".$res['raw'];
    } elseif ($op === 'delHook') {
        $res = tg('deleteWebhook', ['drop_pending_updates'=>true]);
        $opResult = "deleteWebhook(drop_pending_updates=true):\n".$res['raw'];
    }
}

?><!doctype html>
<html lang="ru">
<meta charset="utf-8">
<title>Диагностика бота «Клевер»</title>
<style>
:root{
  --bg:#0d1117; --fg:#e6edf3; --muted:#8b949e; --card:#161b22; --border:#30363d;
  --ok:#3fb950; --bad:#f85149; --accent:#58a6ff; --btn:#238636; --btn-danger:#f85149;
}
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;background:var(--bg);color:var(--fg);padding:22px}
h1,h2{color:var(--accent);margin:0.6em 0}
table{border-collapse:collapse;width:100%;margin:1em 0}
td,th{padding:8px 12px;border:1px solid var(--border);vertical-align:top}
pre{background:var(--card);padding:10px;border-radius:6px;overflow-x:auto;white-space:pre-wrap}
small{color:var(--muted)}
code{background:var(--card);padding:2px 6px;border-radius:4px}
.ok{color:var(--ok);font-weight:600}
.bad{color:var(--bad);font-weight:600}
.flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
button{background:var(--btn);color:#fff;border:0;border-radius:6px;padding:8px 12px;cursor:pointer}
button.danger{background:var(--btn-danger)}
input[type=text]{padding:8px 10px;border-radius:6px;border:1px solid var(--border);background:var(--card);color:var(--fg);min-width:340px}
.card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px;margin:14px 0}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
</style>

<h1>🤖 Диагностика Telegram-бота «Клевер»</h1>
<div class="card">
  <div class="flex">
    <div><b>Файл:</b> diagnostics.php</div>
    <div><b>Ссылка на себя:</b> <a href="<?=$diagUrl?>"><?=$diagUrl?></a></div>
    <div><b>index.php:</b> <a href="<?=$indexUrl?>"><?=$indexUrl?></a></div>
  </div>
</div>

<h2>📡 Вебхук</h2>
<div class="card">
  <div class="flex" style="margin-bottom:8px">
    <form method="post" class="flex">
      <button name="op" value="setHook">Установить setWebhook (с секретом)</button>
      <button class="danger" name="op" value="delHook">Удалить deleteWebhook (drop)</button>
      <button name="op" value="simulate">🧪 Симуляция вебхука (/start ➜ index.php)</button>
    </form>
  </div>
  <div class="flex" style="margin-bottom:8px">
    <div><b>API ссылки:</b></div>
    <div><a href="<?=$apiGetWebhookInfo?>" target="_blank">getWebhookInfo</a></div>
    <div><a href="<?=$apiSetWebhook?>" target="_blank">setWebhook (URL=index.php, c secret_token)</a></div>
    <div><a href="<?=$apiDeleteWebhook?>" target="_blank">deleteWebhook (drop_pending_updates)</a></div>
  </div>
  <pre><?=htmlspecialchars(print_r($infoWebhook, true))?></pre>
</div>

<h2>⚡ Скорость Telegram API (getMe ×5)</h2>
<?php $avg = round(array_sum($speed)/max(1,count($speed)),1); ?>
<div class="card">
  <p>Среднее: <b><?=$avg?> мс</b></p>
  <pre><?=htmlspecialchars(implode(" ms\n",$speed))?></pre>
</div>

<h2>🧰 Система и директории</h2>
<div class="card">
<table>
  <tr><th>Параметр</th><th>Значение</th></tr>
  <tr><td>PHP-версия</td><td><?=htmlspecialchars($phpv)?></td></tr>
  <tr><td>cURL</td><td><?=htmlspecialchars(($curlv['version']??'').' / '.($curlv['ssl_version']??''))?></td></tr>
  <tr><td>Директория /bot/data</td><td class="<?=$dirOk?'ok':'bad'?>"><?=$dirOk?'ОК (доступно на запись)':'Нет доступа'?></td></tr>
  <tr><td>Размер файлов в /bot/data</td><td><?=readableSize($logSize)?></td></tr>
  <tr><td>Загруженные модули PHP</td><td><?=htmlspecialchars(implode(', ', get_loaded_extensions()))?></td></tr>
  <tr><td>memory_limit</td><td><?=ini_get('memory_limit')?></td></tr>
  <tr><td>max_execution_time</td><td><?=ini_get('max_execution_time')?></td></tr>
  <tr><td>display_errors</td><td><?=ini_get('display_errors')?></td></tr>
  <tr><td>log_errors</td><td><?=ini_get('log_errors')?></td></tr>
</table>

<form method="post" class="flex">
  <button name="op" value="clear">🧹 Очистить состояния (u_*.json)</button>
  <button class="danger" name="op" value="purge">🗑 Удалить все файлы в /bot/data</button>
  <button name="op" value="opcache">♻️ Сбросить opcache</button>
</form>
</div>

<h2>🪵 Хвост логов</h2>
<div class="card">
<table>
  <tr><th>Файл</th><th>Последние строки</th></tr>
  <tr><td>/bot/data/error.log</td><td><pre><?=tailFile(DATA_DIR.'/error.log', 200)?></pre></td></tr>
  <tr><td>/bot/data/php-error.log</td><td><pre><?=tailFile(DATA_DIR.'/php-error.log', 200)?></pre></td></tr>
</table>
</div>

<h2>📦 Состояния пользователей</h2>
<div class="card">
<?php
$states = glob(DATA_DIR.'/u_*.json') ?: [];
echo '<p>Файлов состояний: <b>'.count($states).'</b></p>';
if ($states) {
    echo '<table><tr><th>Файл</th><th>Размер</th><th>Модифицирован</th></tr>';
    foreach ($states as $sf) {
        echo '<tr><td>'.htmlspecialchars(basename($sf)).'</td><td>'.readableSize(@filesize($sf)?:0).'</td><td>'.date('Y-m-d H:i:s', @filemtime($sf)?:time()).'</td></tr>';
    }
    echo '</table>';
}
?>
</div>

<h2>🧪 Самотест Calendar.php</h2>
<div class="card"><pre>
<?php
try {
    require_once __DIR__.'/modules/Calendar.php';
    if (class_exists('\\Klever\\Calendar')) {
        $cal = new \Klever\Calendar();
        [$markup] = $cal->render((int)date('Y'), (int)date('n'));
        echo "OK: Calendar.render() вернул разметку клавиатуры\n";
        echo "Длина reply_markup: ".strlen((string)$markup)." байт";
    } else {
        echo "Класс \\Klever\\Calendar не найден\n";
    }
} catch (Throwable $e) {
    echo "Ошибка: ".$e->getMessage()."\n@ ".$e->getFile().":".$e->getLine()."\n";
}
?>
</pre></div>

<h2>🧭 Проверка ключевых файлов</h2>
<div class="card">
<table>
<tr><th>Файл</th><th>Статус</th></tr>
<?php
$check = [
    'index.php',
    'modules/Calendar.php',
    'ref/clinics.php',
    'ref/contacts.php',
    'ref/services_neuro.php',
    'ref/services_speech.php',
    'ref/doctors_neuro.php',
    'ref/doctors_speech.php',
];
foreach ($check as $f) {
    $p = __DIR__.'/'.$f;
    $ok = is_file($p);
    echo '<tr><td>'.$f.'</td><td class="'.($ok?'ok':'bad').'">'.($ok?'ОК':'Нет файла').'</td></tr>';
}
?>
</table>
</div>

<?php if ($opResult!==null): ?>
<h2>🔧 Результат операции</h2>
<div class="card"><pre><?=htmlspecialchars($opResult)?></pre></div>
<?php endif; ?>

<p><small>Последняя проверка: <?=date('Y-m-d H:i:s')?> | diagnostics.php</small></p>
