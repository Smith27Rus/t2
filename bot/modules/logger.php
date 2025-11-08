<?php
/**
 * logger.php — тех. логирование и перехват фатальных ошибок.
 * Ответственность:
 * - функция elog($msg): пишет в DATA_DIR/error.log
 * - единые error/shutdown-хендлеры, чтобы видеть падения в проде
 * Никакой бизнес-логики и текстов для пользователя.
 */

declare(strict_types=1);

if (!defined('DATA_DIR')) {
    // если файл подключили раньше констант — мягко выходим
    return;
}

function elog(string $s): void {
    @is_dir(DATA_DIR) || @mkdir(DATA_DIR, 0775, true);
    @file_put_contents(DATA_DIR.'/error.log', '['.date('Y-m-d H:i:s')."] ".$s."\n", FILE_APPEND);
}

// Глобальный обработчик PHP-ошибок
set_error_handler(function($no,$str,$file,$line){
    elog("PHP[$no] $str @ $file:$line");
});

// Перехват фатальных по shutdown
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
        elog("FATAL: {$e['message']} @ {$e['file']}:{$e['line']}");
    }
});
