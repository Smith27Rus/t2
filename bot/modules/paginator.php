<?php
/**
 * paginator.php — универсальная разбивка массивов на страницы
 * под inline-клавиатуру Telegram.
 * Ответственность:
 * - buildPagedList(array $list, int $page, string $mode): array
 * Возвращает [ $rows, $page, $pages ]
 * Где $rows — готовые строки для inlineKeyboard.
 */

declare(strict_types=1);

if (!defined('PAGE_SIZE')) {
    // дефолт на случай, если константа не задана
    define('PAGE_SIZE', 12);
}

function buildPagedList(array $list, int $page, string $mode): array {
    $total = count($list);
    $pages = max(1, (int)ceil(max(1,$total)/PAGE_SIZE));
    $page  = max(0, min($pages-1, $page));
    $slice = array_slice($list, $page*PAGE_SIZE, PAGE_SIZE);

    $rows=[];
    foreach($slice as $it){
        $rows[] = [ ['text'=>$it,'callback_data'=>'item:'.substr(md5($it),0,10)] ];
    }
    if($pages>1){
        $rows[] = [
            ['text'=>'◀️','callback_data'=>'apptpage:'.$mode.':'.max(0,$page-1)],
            ['text'=>'Стр. '.($page+1).'/'.$pages,'callback_data'=>'noop'],
            ['text'=>'▶️','callback_data'=>'apptpage:'.$mode.':'.min($pages-1,$page+1)],
        ];
    }
    // Хвост навигации добавляется в сценарии (index) — чтобы не привязывать пагинатор к конкретному флоу
    return [$rows,$page,$pages];
}
