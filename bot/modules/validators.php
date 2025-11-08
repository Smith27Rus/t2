<?php
/**
 * validators.php — проверка и нормализация пользовательских данных.
 * Ответственность:
 * - телефон / email
 * - дата дд.мм.гггг ⇄ yyyy-mm-dd
 * - форматтеры без бизнес-смысла
 */

declare(strict_types=1);

function normalizePhone(string $raw): string {
    // Оставляем только цифры и ведущий плюс
    $raw = trim($raw);
    if ($raw === '') return '';
    $raw = preg_replace('~[^\d+]+~', '', $raw);
    // Если без "+", но начинается с 8/7 и длина 11 — нормализуем к +7
    if ($raw[0] !== '+' && preg_match('~^([78])(\d{10})$~', $raw, $m)) {
        return '+7'.$m[2];
    }
    return $raw;
}

function isValidPhone(string $raw): bool {
    $n = normalizePhone($raw);
    return (bool)preg_match('~^\+?\d{10,15}$~', $n);
}

function isValidEmail(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function fmtDmy(string $iso): string {
    // yyyy-mm-dd → dd.mm.yyyy
    if(!preg_match('~^\d{4}-\d{2}-\d{2}$~',$iso)) return $iso;
    [$y,$m,$d]=explode('-',$iso);
    return sprintf('%02d.%02d.%04d', (int)$d, (int)$m, (int)$y);
}

function parseDmyToIso(string $dmy): ?string {
    // dd.mm.yyyy → yyyy-mm-dd
    if(!preg_match('~^\s*(\d{2})\.(\d{2})\.(\d{4})\s*$~', $dmy, $m)) return null;
    $d=(int)$m[1]; $mm=(int)$m[2]; $y=(int)$m[3];
    if(!checkdate($mm,$d,$y)) return null;
    return sprintf('%04d-%02d-%02d',$y,$mm,$d);
}
