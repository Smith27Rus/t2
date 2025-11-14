<?php
/**
 * Инлайн-календарь: рендер месяца с навигацией, запрет прошлых дат и воскресений (воскресенье помечается «❌»), обработка колбэков cal:nav и cal:pick.
 */

namespace Klever;

use DateTime;

class Calendar {
    private array $months = [
        1=>'Январь', 2=>'Февраль', 3=>'Март', 4=>'Апрель',
        5=>'Май', 6=>'Июнь', 7=>'Июль', 8=>'Август',
        9=>'Сентябрь', 10=>'Октябрь', 11=>'Ноябрь', 12=>'Декабрь'
    ];

    public function render(int $year, int $month, ?string $selected = null): array {
        $today = new DateTime('today');
        $curY = (int)$today->format('Y');
        $curM = (int)$today->format('n');
        $curD = (int)$today->format('j');

        $first = new DateTime("$year-$month-01");
        $daysInMonth = (int)$first->format('t');
        $dow = (int)$first->format('N');

        $rows = [];
        $caption = $this->months[$month] . " $year";

        $prevM = $month - 1; $prevY = $year;
        if ($prevM < 1) { $prevM = 12; $prevY--; }
        $nextM = $month + 1; $nextY = $year;
        if ($nextM > 12) { $nextM = 1; $nextY++; }

        $rows[] = [
            ['text'=>'◀️','callback_data'=>"cal:nav:$prevY:$prevM"],
            ['text'=>$caption,'callback_data'=>'noop'],
            ['text'=>'▶️','callback_data'=>"cal:nav:$nextY:$nextM"]
        ];

        $row = array_fill(0, $dow - 1, ['text'=>' ', 'callback_data'=>'noop']);

        for ($day=1; $day <= $daysInMonth; $day++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $ts = new DateTime($dateStr);
            $isPast = $ts < $today;
            $weekday = (int)$ts->format('N');
            $isSunday = ($weekday === 7);
            $isToday = ($year === $curY && $month === $curM && $day === $curD);

            if ($isPast) {
                $label = '✖';
                $cb = 'noop';
            } elseif ($isSunday) {
                $label = '❌';
                $cb = 'noop';
            } elseif ($isToday) {
                $label = '●';
                $cb = "cal:pick:$dateStr";
            } else {
                $label = (string)$day;
                $cb = "cal:pick:$dateStr";
            }

            $row[] = ['text'=>$label, 'callback_data'=>$cb];

            if (count($row) === 7) {
                $rows[] = $row;
                $row = [];
            }
        }

        if ($row) {
            while (count($row) < 7) $row[] = ['text'=>' ', 'callback_data'=>'noop'];
            $rows[] = $row;
        }

        $rows[] = [
            ['text'=>'⬅️ Назад','callback_data'=>'back:appointment:get_phone'],
            ['text'=>'❌ Отмена','callback_data'=>'cancel']
        ];

        return [json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE)];
    }

    public function handleParts(array $parts): array {
        $type = $parts[1] ?? '';
        if ($type === 'pick') {
            return ['picked' => $parts[2] ?? null, 'markup' => null];
        }
        if ($type === 'nav') {
            $y = (int)($parts[2] ?? date('Y'));
            $m = (int)($parts[3] ?? date('n'));
            [$markup] = $this->render($y,$m);
            return ['picked' => null, 'markup' => $markup];
        }
        return ['picked'=>null,'markup'=>null];
    }
}
