<?php
declare(strict_types=1);

/**
 * Хранилище состояния пользователя на файловой системе.
 * Требует константу DATA_DIR.
 */

function statePath(int $uid): string { return DATA_DIR.'/u_'.$uid.'.json'; }

function loadState(int $uid): array {
    $p = statePath($uid);
    if (!is_file($p)) return ['step'=>null,'flow'=>null,'clinic'=>null,'data'=>[],'files'=>[]];
    $j = json_decode((string)@file_get_contents($p), true);
    return is_array($j)?$j:['step'=>null,'flow'=>null,'clinic'=>null,'data'=>[],'files'=>[]];
}

function saveState(int $uid, array $st): void {
    @file_put_contents(statePath($uid), json_encode($st, JSON_UNESCAPED_UNICODE));
}

function fullReset(int $uid): array {
    $st=['step'=>null,'flow'=>null,'clinic'=>null,'data'=>[],'files'=>[]];
    saveState($uid,$st);
    return $st;
}

function resetFlow(int $uid, array &$st): void {
    $clinic = $st['clinic'] ?? null;
    $st = ['step'=>null,'flow'=>null,'clinic'=>$clinic,'data'=>[],'files'=>[]];
    saveState($uid,$st);
}
