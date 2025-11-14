<?php
/**
 * Модуль config.php:
 * - все константы и фиче-флаги;
 * - регистрация команд в Telegram (setCommands / ensureCommandsInit).
 * В конфиге НИКАКОЙ прикладной логики.
 */

declare(strict_types=1);

const BOT_TOKEN  = '7971315021:AAEMCa6GGFThXVn3cqR4H43M48yg5InSCNY';
const API        = 'https://api.telegram.org/bot'.BOT_TOKEN.'/';
const FILE_API   = 'https://api.telegram.org/file/bot'.BOT_TOKEN.'/';
const ADMIN_CHAT = -1003119914761;

const DATA_DIR = __DIR__ . '/data';
const MOD_DIR  = __DIR__ . '/modules';
const REF_DIR  = __DIR__ . '/ref';

const EMAIL_FROM = 'noreply@klever27.ru';
const EMAIL_TO   = 'destroyer@a-smith.ru';

const PAGE_SIZE = 12;

/** Фичефлаги (быстрые выключатели) */
const FEATURE_CONTACT_CARD = true;  // посылать нативную карточку контакта по кнопке «Позвонить»

/** Регистрация /команд и кнопки меню в чате */
function setCommands(): void {
    $cmds = [
        ['command'=>'appointment','description'=>'Запись на приём'],
        ['command'=>'deduction','description'=>'Налоговый вычет'],
        ['command'=>'feedback','description'=>'Оставить отзыв'],
        ['command'=>'complaint','description'=>'Жалоба руководителю'],
        ['command'=>'start','description'=>'Перезапустить бота'],
    ];
    tg('setMyCommands', ['commands'=>json_encode($cmds, JSON_UNESCAPED_UNICODE)]);
    tg('setChatMenuButton', ['menu_button'=>json_encode(['type'=>'commands'])]);
}
function ensureCommandsInit(): void {
    static $once = null;
    if ($once === null) { setCommands(); $once = true; }
}
