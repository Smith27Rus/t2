<?php
/**
 * Модуль helpers.php:
 * - помощники (работа со справочниками, форматирование дат/телефонов, карты);
 * - VCard для sendContact и link на карту;
 * - утилиты потока (buildTimeSlots, userLink).
 * ЧИСТЫЕ функции без побочных эффектов.
 */

declare(strict_types=1);

/* --------- Доступ к справочникам ref/* --------- */
function clinics(): array {
    return require REF_DIR.'/clinics.php';
}
function servicesByClinic(string $key): array {
    return $key === 'neuro'
        ? require REF_DIR.'/services_neuro.php'
        : require REF_DIR.'/services_speech.php';
}
function doctorsByClinic(string $key): array {
    return $key === 'neuro'
        ? require REF_DIR.'/doctors_neuro.php'
        : require REF_DIR.'/doctors_speech.php';
}
function contactsByClinic(string $key): array {
    $all = require REF_DIR.'/contacts.php';
    if (is_array($all) && isset($all[$key]) && is_array($all[$key])) {
        return $all[$key];
    }
    if (is_array($all)) { $first = reset($all); if (is_array($first)) return $first; }
    return [
        'title'   => 'Контакты',
        'address' => '',
        'phone'   => '',
        'whatsapp'=> '',
        'email'   => '',
        'site'    => '',
        'hours'   => '',
        'map'     => '',
    ];
}

/* --------- Форматы дат и телефонов --------- */
if (!function_exists('fmtDmy')) {
    /** ISO 8601 (YYYY-MM-DD) → dd.mm.yyyy */
    function fmtDmy(string $iso): string {
        if (!$iso) return '';
        try { $dt = new DateTime($iso); return $dt->format('d.m.Y'); } catch (\Throwable $e) { return $iso; }
    }
}
if (!function_exists('parseDmyToIso')) {
    /** dd.mm.yyyy → ISO 8601 (YYYY-MM-DD) или '' */
    function parseDmyToIso(string $dmy): string {
        $dmy = trim($dmy);
        if (!preg_match('~^(\d{2})\.(\d{2})\.(\d{4})$~', $dmy, $m)) return '';
        [$all,$d,$mth,$y] = $m;
        try { $dt = new DateTime("$y-$mth-$d"); return $dt->format('Y-m-d'); } catch (\Throwable $e) { return ''; }
    }
}

/** Приводим номер к E.164 (+7XXXXXXXXXX). Пустая строка, если не вышло. */
function phoneToE164(string $raw): string {
    $d = preg_replace('~\D+~','', $raw);
    if ($d === '') return '';
    if (strlen($d) === 11 && $d[0] === '8') $d = '7'.substr($d,1);
    return '+'.$d;
}

/** VCard 3.0 для sendContact (лучший UX в Telegram). */
function buildVCard(array $c, string $phoneE164): string {
    $title   = str_replace("\n"," ", (string)($c['title'] ?? 'Клевер'));
    $address = str_replace("\n"," ", (string)($c['address'] ?? ''));
    $email   = (string)($c['email'] ?? '');
    $site    = (string)($c['site'] ?? '');
    $lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        'N:;'.$title.';;;',
        'FN:'.$title,
        'ORG:'.$title,
        'TEL;TYPE=work,voice:'.$phoneE164,
    ];
    if ($email)  $lines[] = 'EMAIL;TYPE=INTERNET,WORK:'.$email;
    if ($site)   $lines[] = 'URL:'.$site;
    if ($address)$lines[] = 'ADR;TYPE=WORK:;;'.$address.';;;;';
    $lines[] = 'END:VCARD';
    return implode("\n", $lines);
}

/* --------- Текстовые/служебные хелперы --------- */
function userLink(array $from): string {
    $id=(int)($from['id']??0);
    $name=trim(($from['first_name']??'').' '.($from['last_name']??''))?:'Пользователь';
    $u=$from['username']??null;
    $link=$u?'https://t.me/'.$u:'tg://user?id='.$id;
    return '<a href="'.$link.'">'.htmlspecialchars($name).'</a>'.($u?' (@'.htmlspecialchars($u).')':'');
}

function buildTimeSlots(string $iso): array {
    $dt = new DateTime($iso);
    $weekday = (int)$dt->format('N'); // 1..7
    $start = 9;
    $end = ($weekday===6) ? 17 : 21; // сб короче
    $slots = [];
    for ($h=$start; $h<$end; $h++) {
        $from=sprintf('%02d:00',$h);
        $to=sprintf('%02d:00',$h+1);
        $slots[]="$from-$to";
    }
    return $slots;
}

/* --------- Сводки для подтверждения --------- */
function summarizeAppointment(string $clinicTitle, array $d, bool $forAdmin=false, string $sender=''): string {
    $date=isset($d['date'])?fmtDmy($d['date']):'';
    $time=$d['time']??'—';
    $txt="📋 <b>Запись на приём — {$clinicTitle}</b>\n".
         "👤 Имя: ".htmlspecialchars($d['name'])."\n".
         "📞 Телефон: ".htmlspecialchars($d['phone'])."\n".
         "🏷 Специалист/направление: ".htmlspecialchars($d['item'])."\n".
         "📅 Дата: ".htmlspecialchars($date)."\n".
         "⏰ Время: ".htmlspecialchars($time)."\n".
         "💬 Комментарий: ".htmlspecialchars($d['comment']??'—')."\n".
         "🔔 Способ связи: ".htmlspecialchars($d['contact_method']??'—');
    if ($forAdmin) $txt.="\n👥 Отправитель: {$sender}\n🔗 Отправлено из бота";
    return $txt;
}
function summarizeDeduction(string $clinicTitle, array $d, bool $forAdmin=false, string $sender=''): string {
    $txt="💰 <b>Налоговый вычет — {$clinicTitle}</b>\n".
         "👤 Имя: ".htmlspecialchars($d['name'])."\n".
         "📞 Телефон: ".htmlspecialchars($d['phone'])."\n".
         "✉️ Email: ".htmlspecialchars($d['email']);
    if ($forAdmin) $txt.="\n👥 Отправитель: {$sender}\n🔗 Отправлено из бота";
    return $txt;
}
function summarizeComplaint(string $clinicTitle, array $d, array $files, bool $forAdmin=false, string $sender=''): string {
    $date=isset($d['visit_date'])?fmtDmy($d['visit_date']):'';
    $t="⚠️ <b>Жалоба руководителю — {$clinicTitle}</b>\n".
       "👤 Имя: ".htmlspecialchars($d['name'])."\n".
       "📞 Телефон: ".htmlspecialchars($d['phone'])."\n".
       "📅 Дата визита: ".htmlspecialchars($date)."\n".
       "💬 Комментарий: ".htmlspecialchars($d['comment']??'—')."\n";
    if ($files) {
        $t.="📎 Вложения:\n";
        foreach ($files as $f) {
            $link=$f['link']??'';
            $t.="• ".strtoupper($f['type'])." — ".($link?"<a href=\"{$link}\">ссылка</a>":"—")."\n";
        }
    }
    if ($forAdmin) $t.="👥 Отправитель: {$sender}\n🔗 Отправлено из бота";
    return rtrim($t);
}
