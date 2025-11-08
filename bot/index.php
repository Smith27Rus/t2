<?php
/**
 * Telegram-бот «Клевер» (bootstrap):
 * - только начальная инициализация окружения и подключение модулей;
 * - парсинг update и делегирование в handleUpdate() из flows.php.
 * Никакой бизнес-логики здесь нет — правим её в модулях.
 */

declare(strict_types=1);
mb_internal_encoding('UTF-8');
mb_language('uni');
date_default_timezone_set('Asia/Vladivostok');

// 1) Конфиг: константы окружения (BOT_TOKEN, API, пути, EMAIL_*), setCommands()/ensureCommandsInit()
//    Всё, что понадобится остальным модулям на старте.
require_once __DIR__ . '/config.php';

@is_dir(DATA_DIR) || @mkdir(DATA_DIR, 0775, true); // 2) Готовим рабочую директорию (state/logs и т.п.)

/* 3) Базовые инфраструктурные модули (без бизнес-логики, переиспользуются везде) */
require_once MOD_DIR.'/logger.php';     // логирование (elog/dlog)
require_once MOD_DIR.'/validators.php'; // валидации/парсеры (телефон, email, даты)
require_once MOD_DIR.'/paginator.php';  // пагинация списков (кнопки «вперёд/назад», PAGE_SIZE)
require_once MOD_DIR.'/Calendar.php';   // inline-календарь (рендер и обработка колбэков)
require_once MOD_DIR.'/tg.php';         // низкоуровневые вызовы Telegram API (tg(), sendMessage, inlineButtons, ack...)
require_once MOD_DIR.'/mail.php';       // почтовая отправка (sendHtmlMail)
require_once MOD_DIR.'/state.php';      // хранилище состояния (loadState/saveState/resetFlow/fullReset)

/* 4) Новые «тонкие» модули (поверх инфраструктуры) */
require_once __DIR__ . '/helpers.php';  // вспомогательные утилиты уровня приложения (vCard/телефон и пр.)
require_once __DIR__ . '/ui.php';       // UI-утилиты: клавиатуры/инлайны, форматирование текстов, заголовки
require_once __DIR__ . '/flows.php';    // маршрутизация апдейтов и сценарии (handleUpdate)

/* 5) Рантайм: принимаем апдейт и делегируем логику в flows */
$update = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!$update) { http_response_code(200); exit; }

ensureCommandsInit();  // на каждом запуске гарантируем, что /команды и «Меню» выставлены
handleUpdate($update); // ВЕСЬ роутинг и бизнес-логика внутри flows.php

http_response_code(200);

/*
===============================================================================
ДЕРЕВО ПОДКЛЮЧЕНИЙ (корень = bot/) и ЗАВИСИМОСТИ
===============================================================================

bot/
├─ index.php          (bootstrap: init + require + делегирование в flows)
├─ config.php         (константы путей/токенов/email + setCommands/ensureCommandsInit)
├─ helpers.php        (утилиты уровня приложения: vCard/телефон и прочие помощники)
├─ ui.php             (UI-утилиты: клавиатуры/инлайны, текстовые шаблоны)
├─ flows.php          (handleUpdate + сценарии /appointment /deduction /complaint /contacts)
├─ data/              (runtime-состояние/логи; создаётся автоматически)
├─ modules/
│  ├─ logger.php      (логирование; использует DATA_DIR из config)
│  ├─ validators.php  (валидации/парсеры; вызывается helpers/ui/flows)
│  ├─ paginator.php   (пагинация; использует PAGE_SIZE из config; дергается из flows/ui)
│  ├─ Calendar.php    (инлайн-календарь; вызывается из flows через ui)
│  ├─ tg.php          (обёртки Telegram API; использует API/BOT_TOKEN из config)
│  ├─ mail.php        (отправка почты; использует EMAIL_* из config)
│  └─ state.php       (хранилище состояния; использует DATA_DIR из config)
└─ ref/               (НЕИЗМЕНЯЕМЫЕ справочники/контент, подключаются через REF_DIR)
   ├─ clinics.php
   ├─ contacts.php
   ├─ services_neuro.php
   ├─ services_speech.php
   ├─ doctors_neuro.php
   └─ doctors_speech.php

-----------------------
ГЛАВНЫЕ ЗАВИСИМОСТИ:
-----------------------
config.php  → база для всех: BOT_TOKEN, API, DATA_DIR, EMAIL_*, PAGE_SIZE, MOD_DIR, REF_DIR
logger.php  → зависит от DATA_DIR (куда писать логи)
validators.php → автономен; используется helpers/ui/flows
paginator.php  → использует PAGE_SIZE; используется flows/ui
Calendar.php   → автономен; вызывается flows/ui
tg.php         → зависит от API/BOT_TOKEN; используется везде для Telegram-запросов
mail.php       → зависит от EMAIL_*; вызывается из flows
state.php      → зависит от DATA_DIR; вызывается из flows (load/save/reset)
helpers.php    → использует config + validators (+ tg при необходимости)
ui.php         → использует tg + validators + config; отдаёт готовые клавиатуры/разметку flows
flows.php      → «дирижёр»: использует ui/helpers/state/tg/validators/paginator/Calendar/mail/logger
ref/*          → файловые справочники; читаются через REF_DIR (только на чтение)

===============================================================================
*/
