<?php
declare(strict_types=1);

/**
 * Отправка HTML-писем с корректной кодировкой.
 * Требует константу EMAIL_FROM.
 */

function sendHtmlMail(string $to, string $subject, string $html): void {
    $encSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    // 👇 Отправитель по просьбе: Чат-бот "Клевер"
    $fromName   = mb_encode_mimeheader('Чат-бот "Клевер"', 'UTF-8', 'B', "\r\n") . ' <'.EMAIL_FROM.'>';

    $body = chunk_split(base64_encode($html), 76, "\r\n");

    $headers = [
        'From: '.$fromName,
        'Reply-To: '.EMAIL_FROM,
        'MIME-Version: 1.0',
        'Date: '.date('r'),
        'Message-ID: <'.bin2hex(random_bytes(8)).'@klever27.ru>',
        'X-Mailer: PHP/'.PHP_VERSION,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];

    @mail($to, $encSubject, $body, implode("\r\n", $headers));
}
