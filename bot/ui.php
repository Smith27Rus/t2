<?php
/**
 * Модуль ui.php:
 * - все тексты и клавиатуры (reply/inline), заголовки;
 * - форматирование блока контактов.
 * Здесь нет логики переходов — только «как показать».
 */

declare(strict_types=1);

/* Главная reply-клава и вспомогательные сообщения */
function mainReplyKeyboard(): array {
    return [
        [['text'=>'🗓 Запись на приём'], ['text'=>'📞 Контакты']],
        [['text'=>'🔄 Сменить клинику'], ['text'=>'ℹ️ Справка по боту']],
    ];
}
function sendKeyboardMain(int|string $chatId, string $clinicLabel): void {
    tg('sendMessage', [
        'chat_id'=>$chatId,
        'text'=>"Вы выбрали: <b>{$clinicLabel}</b>\nЧто вас интересует?",
        'parse_mode'=>'HTML',
        'reply_markup'=>json_encode(['keyboard'=>mainReplyKeyboard(),'resize_keyboard'=>true,'is_persistent'=>true])
    ]);
}
function restoreMainKeyboard(int|string $chatId): void {
    tg('sendMessage', [
        'chat_id'=>$chatId,
        'text'=>' ',
        'reply_markup'=>json_encode(['keyboard'=>mainReplyKeyboard(),'resize_keyboard'=>true,'is_persistent'=>true])
    ]);
}

/* Выбор клиники */
function clinicSelectKeyboard(): string {
    $cl = clinics();
    return inlineButtons([
        [ ['text'=>$cl['neuro']['title'],  'callback_data'=>'clinic:neuro'] ],
        [ ['text'=>$cl['speech']['title'], 'callback_data'=>'clinic:speech'] ],
    ]);
}
function askClinic(int $chatId): void {
    $text = "👋 Добро пожаловать в чат-бот «Клевер»!\n\n🏥 Пожалуйста, выберите клинику:";
    tg('sendMessage', ['chat_id'=>$chatId,'text'=>$text,'reply_markup'=>clinicSelectKeyboard()]);
}

/* Заголовки карточек */
function headerAppointment(array $st): string {
    $cl = clinics(); $ct = $cl[$st['clinic']]['title'] ?? '';
    return "🗓 <b>Запись на приём:</b> {$ct}\n\n";
}
function headerDeduction(array $st): string {
    $cl = clinics(); $ct = $cl[$st['clinic']]['title'] ?? '';
    return "💰 <b>Налоговый вычет:</b> {$ct}\n\n";
}
function headerComplaint(array $st): string {
    $cl = clinics(); $ct = $cl[$st['clinic']]['title'] ?? '';
    return "⚠️ <b>Жалоба руководителю:</b> {$ct}\n\n";
}

/* Текст с контактами */
function formatContacts(array $c): string {
    $t="📞 Контакты — <b>".htmlspecialchars($c['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>\n";
    if(!empty($c['address'])) $t.="📍 Адрес: ".($c['address'])."\n";
    if(!empty($c['phone'])) {
        $tel = preg_replace('~\D+~','',$c['phone']);
        $t.="☎️ Телефон: <a href=\"tel:+{$tel}\">".($c['phone'])."</a>\n";
    }
    if(!empty($c['whatsapp'])){
        $wa = preg_replace('~\D+~','',$c['whatsapp']);
        $t.="💬 WhatsApp: <a href=\"https://wa.me/{$wa}\">".$c['whatsapp']."</a>\n";
    }
    if(!empty($c['email'])) $t.="✉️ Почта: <a href=\"mailto:{$c['email']}\">{$c['email']}</a>\n";
    if(!empty($c['site']))  $t.="🌐 Сайт: <a href=\"{$c['site']}\">{$c['site']}</a>\n";
    if(!empty($c['hours'])) $t.="🕘 График: {$c['hours']}";
    return $t;
}

/** Инлайн-кнопки: Позвонить (карточка), WhatsApp, Сайт, На карте (из c['map'] или по адресу). */
function contactsInlineMarkup(array $c): string {
    $rows = [];

    // Ряд 1: Позвонить (через sendContact) + WhatsApp
    $r1 = [];
    if (!empty($c['phone']) && FEATURE_CONTACT_CARD) {
        $r1[] = ['text'=>'📞 Позвонить','callback_data'=>'contact:card'];
    }
    if (!empty($c['whatsapp'])) {
        $wa = preg_replace('~\D+~','', $c['whatsapp']);
        if ($wa) $r1[] = ['text'=>'💬 WhatsApp','url'=>'https://wa.me/'.$wa];
    }
    if ($r1) $rows[] = $r1;

    // Ряд 2: Сайт + На карте
    $r2 = [];
    if (!empty($c['site'])) $r2[] = ['text'=>'🌐 Сайт','url'=>$c['site']];
    if (!empty($c['map'])) {
        $r2[] = ['text'=>'🗺 На карте','url'=>$c['map']]; // короткие yandex-ссылки из ref/contacts.php
    } elseif (!empty($c['address'])) {
        $r2[] = ['text'=>'🗺 На карте','url'=>'https://yandex.ru/maps/?text='.rawurlencode($c['address'])];
    }
    if ($r2) $rows[] = $r2;

    return inlineButtons($rows ?: [[['text'=>' ', 'callback_data'=>'noop']]]);
}
