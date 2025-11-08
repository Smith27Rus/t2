<?php
declare(strict_types=1);

/**
 * Низкоуровневый Telegram-клиент и примитивы UI.
 * Требует заранее определённых констант: API, FILE_API.
 * Использует elog() из index.php для логирования.
 */

function tg(string $method, array $params = []): array {
    $ch = curl_init(API.$method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $out = curl_exec($ch);
    if ($out === false) { if (function_exists('elog')) elog("cURL ".curl_errno($ch).": ".curl_error($ch)." in $method"); }
    curl_close($ch);

    $j = json_decode((string)$out, true);
    if (!is_array($j) || !isset($j['ok'])) { if (function_exists('elog')) elog("Bad TG response in $method: ".substr((string)$out,0,200)); }
    return is_array($j) ? $j : ['ok'=>false,'result'=>null];
}

function sendMessage(int|string $chatId, string $text, array $extra=[]): void {
    $p = array_merge(['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true], $extra);
    tg('sendMessage', $p);
}

function editMessageReplyMarkup(int|string $chatId, int $messageId, string $markup): void {
    tg('editMessageReplyMarkup', ['chat_id'=>$chatId, 'message_id'=>$messageId, 'reply_markup'=>$markup]);
}

function editMessageText(int|string $chatId, int $messageId, string $text, string $markup=''): void {
    $params = ['chat_id'=>$chatId,'message_id'=>$messageId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
    if ($markup !== '') $params['reply_markup'] = $markup;
    tg('editMessageText', $params);
}

function getFileLink(string $fileId): ?string {
    $r = tg('getFile', ['file_id'=>$fileId]);
    if (!($r['ok']??false)) return null;
    $path = $r['result']['file_path']??null;
    return $path ? FILE_API.$path : null;
}

function inlineButtons(array $rows): string {
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function kb_back_cancel(string $flow, string $where=''): string {
    $back = 'back:'.$flow.($where!==''?':'.$where:'');
    return inlineButtons([[['text'=>'⬅️ Назад','callback_data'=>$back],['text'=>'❌ Отмена','callback_data'=>'cancel']]]);
}

function ack(string $cbId, string $text = '', int $cache = 0): void {
    tg('answerCallbackQuery', ['callback_query_id'=>$cbId, 'text'=>$text, 'cache_time'=>$cache]);
}
