<?php
/**
 * Klever — Проверка справочников (services/doctors/contacts/clinics)
 * URL: /bot/tools/ref_validator.php?secret=klever_webhook_secret_2025
 * Показывает, что подключается, где пусто, и примеры первых элементов.
 */

declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Vladivostok');

const SECRET = 'klever_webhook_secret_2025';
const REF_DIR = __DIR__ . '/../ref';

if (($_GET['secret'] ?? '') !== SECRET) {
    http_response_code(403);
    exit('<h1>403 Forbidden</h1>');
}

function loadSafe(string $file) {
    $path = REF_DIR . '/' . $file;
    if (!is_file($path)) return ['ok'=>false,'err'=>'Файл не найден','val'=>null];
    $val = require $path;
    return ['ok'=>true,'err'=>null,'val'=>$val];
}
function statusRow(string $name, $val): array {
    $ok = true; $msg = 'ОК';
    if (is_array($val)) {
        if (empty($val)) { $ok=false; $msg='Массив пуст'; }
    } else {
        $ok=false; $msg='Не массив';
    }
    return [$name, $ok, $msg];
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$checks = [
    'clinics.php',
    'contacts.php',
    'services_neuro.php',
    'services_speech.php',
    'doctors_neuro.php',
    'doctors_speech.php',
];

$results = [];
$details = [];

foreach ($checks as $f) {
    $r = loadSafe($f);
    if (!$r['ok']) {
        $results[] = [$f, false, $r['err']];
        $details[$f] = null;
        continue;
    }
    if ($f === 'clinics.php' || $f === 'contacts.php') {
        if (!is_array($r['val']) || count($r['val']) === 0) {
            $results[] = [$f, false, 'Не массив или пусто'];
        } else {
            $results[] = [$f, true, 'ОК'];
        }
        $details[$f] = $r['val'];
        continue;
    }
    // services_*.php / doctors_*.php — должны быть массивы-списки
    [$name, $ok, $msg] = statusRow($f, $r['val']);
    $results[] = [$name, $ok, $msg];
    $details[$f] = $r['val'];
}

?>
<!doctype html>
<html lang="ru">
<meta charset="utf-8">
<title>Klever: проверка справочников</title>
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;padding:24px;}
h1,h2{color:#58a6ff;margin:.6em 0}
table{border-collapse:collapse;width:100%;margin:1em 0}
td,th{border:1px solid #30363d;padding:8px 12px;vertical-align:top}
.ok{color:#3fb950;font-weight:600}
.bad{color:#f85149;font-weight:600}
pre{background:#161b22;padding:10px;border-radius:6px;overflow-x:auto}
small{color:#8b949e}
code{color:#c9d1d9}
</style>

<h1>🧪 Klever — Проверка справочников</h1>

<table>
  <tr><th>Файл</th><th>Статус</th><th>Комментарий</th></tr>
  <?php foreach ($results as [$name,$ok,$msg]): ?>
    <tr>
      <td><?=h($name)?></td>
      <td class="<?=$ok?'ok':'bad'?>"><?=$ok?'ОК':'Проблема'?></td>
      <td><?=h($msg)?></td>
    </tr>
  <?php endforeach; ?>
</table>

<h2>📋 Детали</h2>
<?php foreach ($details as $file => $val): ?>
  <h3><?=h($file)?></h3>
  <?php if ($val === null): ?>
    <p class="bad">Файл не найден.</p>
  <?php elseif (is_array($val)): ?>
    <?php if (in_array($file, ['clinics.php','contacts.php'], true)): ?>
      <table>
        <tr><th>Ключ</th><th>Пример</th></tr>
        <?php foreach ($val as $k=>$v): ?>
          <tr>
            <td><code><?=h((string)$k)?></code></td>
            <td><pre><?php echo h(var_export($v,true)); ?></pre></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p>Элементов: <b><?=count($val)?></b></p>
      <pre><?php echo h(implode("\n", array_slice(array_map('strval',$val),0,10))); ?></pre>
      <?php if (count($val) === 0): ?>
        <p class="bad">Заполни массив строками, например:
        <pre><?php echo h("<?php\nreturn [\n    'Иванов И.И. — Врач',\n    'Петров П.П. — Врач',\n];"); ?></pre></p>
      <?php endif; ?>
    <?php endif; ?>
  <?php else: ?>
    <p class="bad">Ожидался массив, получено: <?=h(gettype($val))?></p>
  <?php endif; ?>
<?php endforeach; ?>

<p><small>Путь справочников: <code><?=h(REF_DIR)?></code> • Время: <?=date('Y-m-d H:i:s')?></small></p>
