<?php
/**
 * Модуль flows.php:
 * - единая точка входа handleUpdate($update) + обработка callback/message;
 * - реализации шагов по потокам: appointment, deduction, complaint, contacts;
 * - вспомогательные отправки: календарь, выбор времени, карточка контакта.
 * Никаких констант и хардкода — всё берём из helpers/ui/config.
 */

declare(strict_types=1);

/* ===== Вспомогательные отправки для шагов ===== */
function sharePhoneAsk(int|string $cid, string $flow): void {
    tg('sendMessage', [
        'chat_id'=>$cid,
        'text'=>'Отправьте номер телефона или поделитесь контактом:',
        'reply_markup'=>json_encode([
            'keyboard'=>[[['text'=>'📱 Поделиться контактом','request_contact'=>true]]],
            'resize_keyboard'=>true
        ])
    ]);
    tg('sendMessage', [
        'chat_id'=>$cid,
        'text'=>"\xC2\xA0",
        'reply_markup'=>kb_back_cancel($flow,'get_phone')
    ]);
}

function sendCalendarAsk(int|string $cid, array $st): void {
    $cal=new \Klever\Calendar();
    [$markup] = $cal->render((int)date('Y'), (int)date('n'));
    tg('sendMessage', [
        'chat_id'=>$cid,
        'text'=>headerAppointment($st).'Выберите дату визита:',
        'parse_mode'=>'HTML',
        'reply_markup'=>$markup
    ]);
}

function sendChooseTimeAsk(int|string $cid, array $st): void {
    $iso   = $st['data']['date'] ?? '';
    $slots = buildTimeSlots($iso);
    $rows=[];
    for($i=0; $i<count($slots); $i+=2){
        $row=[['text'=>$slots[$i],'callback_data'=>'time:'.$slots[$i]]];
        if(isset($slots[$i+1])) $row[]=['text'=>$slots[$i+1],'callback_data'=>'time:'.$slots[$i+1]];
        $rows[]=$row;
    }
    $rows[]=[['text'=>'⬅️ Назад','callback_data'=>'back:appointment:get_date'],['text'=>'❌ Отмена','callback_data'=>'cancel']];
    tg('sendMessage',[
        'chat_id'=>$cid,
        'text'=>headerAppointment($st)."Вы выбрали дату: <b>".fmtDmy($iso)."</b>\nВыберите желаемый диапазон времени:",
        'parse_mode'=>'HTML',
        'reply_markup'=>inlineButtons($rows)
    ]);
}

/** Нативная карточка контакта (кнопка «Позвонить») */
function sendClinicContactCard(int|string $cid, array $c): void {
    if (!FEATURE_CONTACT_CARD) return;
    $phoneE164 = phoneToE164((string)($c['phone'] ?? ''));
    if (!$phoneE164) { sendMessage($cid, "Телефон недоступен."); return; }
    tg('sendContact', [
        'chat_id'      => $cid,
        'phone_number' => $phoneE164,
        'first_name'   => (string)($c['title'] ?? 'Клевер'),
        'vcard'        => buildVCard($c, $phoneE164)
    ]);
}

/* ===== Главный обработчик update ===== */
function handleUpdate(array $update): void
{
    $cb  = $update['callback_query'] ?? null;
    $msg = $update['message'] ?? null;

    if ($cb) {
        $cid  = $cb['message']['chat']['id'];
        $uid  = $cb['from']['id'];
        $from = $cb['from'];
        $data = (string)($cb['data']??'');
        $st   = loadState($uid);
        $cl   = clinics();

        ack($cb['id']);

        try {
            if ($data==='cancel') {
                resetFlow($uid,$st);
                $label = $st['clinic']&&isset($cl[$st['clinic']]) ? $cl[$st['clinic']]['title'] : '—';
                sendKeyboardMain($cid,$label);
                return;
            }

            if (str_starts_with($data,'clinic:')) {
                $key = explode(':',$data,2)[1]??'';
                if (!isset($cl[$key])) { askClinic($cid); return; }
                $st = ['step'=>null,'flow'=>null,'clinic'=>$key,'data'=>[],'files'=>[]];
                saveState($uid,$st);
                sendKeyboardMain($cid,$cl[$key]['title']);
                return;
            }

            // Контакты: карточка для звонка
            if ($data === 'contact:card') {
                $c = contactsByClinic($st['clinic']);
                sendClinicContactCard($cid, $c);
                return;
            }

            if (str_starts_with($data,'apptmode:')) {
                $mode = explode(':',$data,2)[1]??'by_service';
                $list = $mode==='by_service' ? servicesByClinic($st['clinic']) : doctorsByClinic($st['clinic']);
                if (!is_array($list)) throw new \RuntimeException('Справочник вернул не массив');

                $map = [];
                foreach ($list as $it) { $map[substr(md5($it),0,10)] = $it; }

                $st['flow']='appointment';
                $st['step']='choose_item';
                $st['data']=['mode'=>$mode,'page'=>0,'map'=>$map];
                saveState($uid,$st);

                $mid = (int)$cb['message']['message_id'];
                if (!$list) {
                    editMessageText(
                        $cid,$mid,
                        headerAppointment($st).'Список пуст. Выберите другой способ или вернитесь назад.',
                        inlineButtons([[['text'=>'⬅️ Назад','callback_data'=>'back:appointment:choose_mode'],['text'=>'❌ Отмена','callback_data'=>'cancel']]])
                    );
                    return;
                }
                [$rows] = buildPagedList($list,0,$mode);
                $rows[]=[['text'=>'⬅️ Назад','callback_data'=>'back:appointment:choose_mode'],['text'=>'❌ Отмена','callback_data'=>'cancel']];

                editMessageText(
                    $cid,$mid,
                    headerAppointment($st).($mode==='by_service'?'Выберите направление:':'Выберите специалиста:'),
                    inlineButtons($rows)
                );
                return;
            }

            if (str_starts_with($data,'apptpage:')) {
                $p = explode(':',$data);
                $mode=$p[1]??'by_service'; $page=(int)($p[2]??0);
                $list = $mode==='by_service' ? servicesByClinic($st['clinic']) : doctorsByClinic($st['clinic']);
                if (!is_array($list)) throw new \RuntimeException('Справочник вернул не массив');

                [$rows,$page,$pages]=buildPagedList($list,$page,$mode);
                $rows[]=[['text'=>'⬅️ Назад','callback_data'=>'back:appointment:choose_mode'],['text'=>'❌ Отмена','callback_data'=>'cancel']];
                $mid = (int)$cb['message']['message_id'];
                editMessageText(
                    $cid,$mid,
                    headerAppointment($st).($mode==='by_service'?'Выберите направление:':'Выберите специалиста:'),
                    inlineButtons($rows)
                );
                return;
            }

            if (str_starts_with($data,'item:')) {
                $code = explode(':',$data,2)[1]??'';
                $map = $st['data']['map'] ?? [];
                if (!isset($map[$code])) { sendMessage($cid,headerAppointment($st).'Элемент недоступен, попробуйте снова.'); return; }
                $st['data']['item']=$map[$code];
                $st['step']='get_name';
                saveState($uid,$st);
                sendMessage($cid,headerAppointment($st)."Введите ваше имя:",['reply_markup'=>kb_back_cancel('appointment','get_name')]);
                return;
            }

            if (str_starts_with($data,'cal:')) {
                $parts = explode(':',$data);
                $cal = new \Klever\Calendar();
                $res = $cal->handleParts($parts);

                if (($parts[1] ?? '')==='pick' && ($res['picked'] ?? null)) {
                    $st['data']['date']=$res['picked'];
                    $st['step']='choose_time'; saveState($uid,$st);
                    sendChooseTimeAsk($cid,$st);
                } elseif ($res['markup'] ?? null) {
                    $mid = $cb['message']['message_id'];
                    editMessageReplyMarkup($cid, $mid, $res['markup']);
                }
                return;
            }

            if (str_starts_with($data,'time:')) {
                $slot = explode(':',$data,2)[1]??'';
                $st['data']['time']=$slot;
                $st['step']='get_comment'; saveState($uid,$st);
                sendMessage($cid,headerAppointment($st)."Добавьте комментарий (или напишите «-»):",['reply_markup'=>kb_back_cancel('appointment','choose_time')]);
                return;
            }

            if (str_starts_with($data,'contact_method:')) {
                $val = explode(':',$data,2)[1]??'';
                $labels = [
                    'call'     => '📞 Перезвонить',
                    'whatsapp' => '💬 Написать в WhatsApp',
                    'telegram' => '💬 Написать в Telegram',
                ];
                $st['data']['contact_method'] = $labels[$val] ?? $val;
                $st['step']='confirm'; saveState($uid,$st);

                $clinicTitle=clinics()[$st['clinic']]['title'];
                $sumUser=summarizeAppointment($clinicTitle,$st['data'],false);
                $kb=[[['text'=>'⬅️ Назад','callback_data'=>'back:appointment:get_contact_method'],['text'=>'✅ Подтвердить','callback_data'=>'confirm:appointment']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]];
                sendMessage($cid,$sumUser,['reply_markup'=>inlineButtons($kb)]);
                return;
            }

            if ($data==='confirm:appointment') {
                $clinicTitle=clinics()[$st['clinic']]['title']; $sender=userLink($from);
                $txtAdmin=summarizeAppointment($clinicTitle,$st['data'],true,$sender);
                sendMessage(ADMIN_CHAT,$txtAdmin);
                sendMessage($cid,"✅ Заявка отправлена! Спасибо, мы свяжемся с вами в ближайшее время.");
                resetFlow($uid,$st); return;
            }
            if ($data==='confirm:deduction') {
                $clinicTitle=clinics()[$st['clinic']]['title']; $sender=userLink($from);
                $txtAdmin=summarizeDeduction($clinicTitle,$st['data'],true,$sender);
                sendMessage(ADMIN_CHAT,$txtAdmin);
                sendHtmlMail(EMAIL_TO,'Заполнение формы налогового вычета', nl2br($txtAdmin));
                sendMessage($cid,"✅ Заявка отправлена! Спасибо, мы свяжемся с вами в ближайшее время.");
                resetFlow($uid,$st); return;
            }
            if ($data==='confirm:complaint') {
                $clinicTitle=clinics()[$st['clinic']]['title']; $sender=userLink($from);
                $txtAdmin=summarizeComplaint($clinicTitle,$st['data'],$st['files'],true,$sender);
                sendMessage(ADMIN_CHAT,$txtAdmin);
                sendHtmlMail(EMAIL_TO,'Жалоба руководителю', nl2br($txtAdmin));
                sendMessage($cid,"✅ Жалоба отправлена руководителю. Спасибо, что сообщили о проблеме — мы разберёмся.");
                resetFlow($uid,$st); return;
            }

            if ($data==='attach_yes') {
                $st['step']='attach_files'; saveState($uid,$st);
                sendMessage($cid,headerComplaint($st).'Пришлите фото/видео/документы. Когда будете готовы — нажмите «Подтвердить».',[
                    'reply_markup'=>inlineButtons([[['text'=>'⬅️ Назад','callback_data'=>'back:complaint:get_comment'],['text'=>'✅ Подтвердить','callback_data'=>'confirm:complaint']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]])
                ]);
                return;
            }
            if ($data==='attach_no') {
                $st['step']='confirm'; saveState($uid,$st);
                $clinicTitle=clinics()[$st['clinic']]['title'];
                $sumUser=summarizeComplaint($clinicTitle,$st['data'],$st['files'],false);
                sendMessage($cid,$sumUser,['reply_markup'=>inlineButtons([[['text'=>'⬅️ Назад','callback_data'=>'back:complaint:get_comment'],['text'=>'✅ Подтвердить','callback_data'=>'confirm:complaint']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]])]);
                return;
            }

            if (str_starts_with($data,'back:')) {
                $parts=explode(':',$data); $flow=$parts[1]??''; $to=$parts[2]??'';
                if ($flow==='appointment'){
                    if ($to==='choose_mode'){
                        $st['step']='choose_mode'; saveState($uid,$st);
                        $kb=[[['text'=>'По направлению','callback_data'=>'apptmode:by_service'],['text'=>'По специалисту','callback_data'=>'apptmode:by_doctor']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]];
                        sendMessage($cid,headerAppointment($st).'Выберите способ записи:',['reply_markup'=>inlineButtons($kb)]);
                    } elseif ($to==='get_name'){
                        $st['step']='get_name'; unset($st['data']['phone']); saveState($uid,$st);
                        sendMessage($cid,headerAppointment($st)."Введите ваше имя:",['reply_markup'=>kb_back_cancel('appointment','get_name')]);
                    } elseif ($to==='get_phone'){
                        $st['step']='get_phone'; unset($st['data']['date'],$st['data']['time']); saveState($uid,$st);
                        sharePhoneAsk($cid,'appointment');
                    } elseif ($to==='get_date'){
                        $st['step']='get_date'; unset($st['data']['date'],$st['data']['time']); saveState($uid,$st);
                        sendCalendarAsk($cid,$st);
                    } elseif ($to==='choose_time'){
                        $st['step']='choose_time'; saveState($uid,$st);
                        sendChooseTimeAsk($cid,$st);
                    } elseif ($to==='get_comment'){
                        $st['step']='get_comment'; saveState($uid,$st);
                        sendMessage($cid,headerAppointment($st)."Добавьте комментарий (или «-»):",['reply_markup'=>kb_back_cancel('appointment','choose_time')]);
                    } elseif ($to==='get_contact_method'){
                        $st['step']='get_comment'; saveState($uid,$st);
                        sendMessage($cid,headerAppointment($st)."Добавьте комментарий (или «-»):",['reply_markup'=>kb_back_cancel('appointment','choose_time')]);
                    }
                } elseif ($flow==='deduction'){
                    if ($to==='get_name'){
                        $st['step']='get_name'; $st['data']=[]; saveState($uid,$st);
                        sendMessage($cid,headerDeduction($st)."Введите ваше имя:",['reply_markup'=>kb_back_cancel('deduction','get_name')]);
                    } elseif ($to==='get_phone'){
                        $st['step']='get_phone'; unset($st['data']['email']); saveState($uid,$st);
                        sharePhoneAsk($cid,'deduction');
                    }
                } elseif ($flow==='complaint'){
                    if ($to==='get_name'){
                        $st['step']='get_name'; $st['files']=[]; $st['data']=[]; saveState($uid,$st);
                        sendMessage($cid,headerComplaint($st)."Введите ваше имя:",['reply_markup'=>kb_back_cancel('complaint','get_name')]);
                    } elseif ($to==='get_phone'){
                        $st['step']='get_phone'; unset($st['data']['visit_date']); saveState($uid,$st);
                        sharePhoneAsk($cid,'complaint');
                    } elseif ($to==='get_visit_date'){
                        $st['step']='get_visit_date'; saveState($uid,$st);
                        sendMessage($cid,headerComplaint($st).'Введите дату визита (например, 19.11.2025):',['reply_markup'=>kb_back_cancel('complaint','get_visit_date')]);
                    } elseif ($to==='get_comment'){
                        $st['step']='get_comment'; saveState($uid,$st);
                        sendMessage($cid,headerComplaint($st).'Опишите ситуацию.',['reply_markup'=>kb_back_cancel('complaint','get_comment')]);
                    }
                }
                return;
            }

        } catch (\Throwable $e) {
            elog("CB error: ".$e->getMessage());
            sendMessage($cid, "⚠️ Произошла ошибка. Попробуйте ещё раз /start");
        }
        return;
    }

    if ($msg) {
        $cid=$msg['chat']['id']; $uid=$msg['from']['id']; $from=$msg['from'];
        try {
            $text=trim((string)($msg['text']??'')); $st=loadState($uid); $cl=clinics();

            // Шэр контакта
            if (isset($msg['contact']) && $st['flow'] && $st['step']==='get_phone') {
                $phone=$msg['contact']['phone_number']??'';
                $st['data']['phone']=$phone;
                restoreMainKeyboard($cid);

                if ($st['flow']==='appointment'){ $st['step']='get_date'; saveState($uid,$st); sendCalendarAsk($cid,$st); return; }
                if ($st['flow']==='deduction'){  $st['step']='get_email'; saveState($uid,$st); sendMessage($cid,headerDeduction($st).'Введите вашу электронную почту:',['reply_markup'=>kb_back_cancel('deduction','get_phone')]); return; }
                if ($st['flow']==='complaint'){  $st['step']='get_visit_date'; saveState($uid,$st); sendMessage($cid,headerComplaint($st).'Введите дату визита (например, 19.11.2025):',['reply_markup'=>kb_back_cancel('complaint','get_visit_date')]); return; }
            }

            // Вложения для жалобы
            if ((isset($msg['photo'])||isset($msg['document'])||isset($msg['video'])) && $st['flow']==='complaint') {
                if (isset($msg['photo']))   { $ph=end($msg['photo']);      $fid=$ph['file_id'];          $link=getFileLink($fid); $st['files'][]=['type'=>'photo','file_id'=>$fid,'link'=>$link]; }
                if (isset($msg['document'])){ $fid=$msg['document']['file_id']; $link=getFileLink($fid); $st['files'][]=['type'=>'document','file_id'=>$fid,'link'=>$link]; }
                if (isset($msg['video']))   { $fid=$msg['video']['file_id'];    $link=getFileLink($fid); $st['files'][]=['type'=>'video','file_id'=>$fid,'link'=>$link]; }
                saveState($uid,$st);
                sendMessage($cid,headerComplaint($st)."📎 Файл добавлен. Можете прикрепить ещё или нажмите «Подтвердить».",
                    ['reply_markup'=>inlineButtons([[['text'=>'⬅️ Назад','callback_data'=>'back:complaint:get_comment'],['text'=>'✅ Подтвердить','callback_data'=>'confirm:complaint']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]])]);
                return;
            }

            // Команды
            if ($text==='/start'){ $st=fullReset($uid); askClinic($cid); return; }
            if ($text==='ℹ️ Справка по боту' || $text==='/help'){
                sendMessage($cid,"Я чат-бот центра «Клевер». Помогаю быстро:\n• записаться на приём к специалистам,\n• оформить налоговый вычет за лечение,\n• оставить отзыв или направить жалобу руководителю,\n• получить контакты и справочную информацию.\n\nДоступные команды:\n/appointment — Запись на приём\n/deduction — Налоговый вычет\n/feedback — Отзыв\n/complaint — Жалоба\n/start — Перезапуск");
                return;
            }

            if ($text==='/feedback' || $text==='⭐ Отзыв'){
                $row=[[['text'=>'💚 Неврология','url'=>'https://2gis.ru/khabarovsk/firm/4926340373508276/135.066627%2C48.507797/tab/reviews?m=135.066632%2C48.507778%2F18.68'],['text'=>'🧩 Центр развития речи','url'=>'https://2gis.ru/khabarovsk/firm/70000001029431055/135.088006%2C48.490784/tab/reviews?m=135.087791%2C48.490347%2F17.88']]];
                sendMessage($cid,'Выберите площадку для отзыва:',['reply_markup'=>inlineButtons($row)]);
                return;
            }

            if ($text==='/appointment' || $text==='🗓 Запись на приём'){
                if (!$st['clinic']) { askClinic($cid); return; }
                $st['flow']='appointment'; $st['step']='choose_mode'; $st['data']=[]; $st['files']=[]; saveState($uid,$st);
                $kb=[[['text'=>'По направлению','callback_data'=>'apptmode:by_service'],['text'=>'По специалисту','callback_data'=>'apptmode:by_doctor']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]];
                sendMessage($cid,headerAppointment($st).'Выберите способ записи:',['reply_markup'=>inlineButtons($kb)]);
                return;
            }
            if ($text==='/deduction' || $text==='💰 Налоговый вычет'){
                if (!$st['clinic']) { askClinic($cid); return; }
                if ($st['clinic']!=='neuro'){
                    $kb=[[['text'=>'Переключиться на Центр неврологии','callback_data'=>'clinic:neuro']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]];
                    sendMessage($cid,headerDeduction($st).'Эта функция доступна только в Центре неврологии «Клевер». Хотите переключиться?',['reply_markup'=>inlineButtons($kb)]);
                    return;
                }
                $st=['step'=>'get_name','flow'=>'deduction','clinic'=>$st['clinic'],'data'=>[],'files'=>[]]; saveState($uid,$st);
                sendMessage($cid,headerDeduction($st)."Введите ваше имя:",['reply_markup'=>kb_back_cancel('deduction','get_name')]);
                return;
            }
            if ($text==='/complaint' || $text==='⚠️ Жалоба руководителю'){
                if (!$st['clinic']) { askClinic($cid); return; }
                $st=['step'=>'get_name','flow'=>'complaint','clinic'=>$st['clinic'],'data'=>[],'files'=>[]]; saveState($uid,$st);
                sendMessage($cid,headerComplaint($st)."Введите ваше имя:",['reply_markup'=>kb_back_cancel('complaint','get_name')]);
                return;
            }
            if ($text==='📞 Контакты'){
                if (!$st['clinic']) { askClinic($cid); return; }
                $c = contactsByClinic($st['clinic']);
                sendMessage($cid, formatContacts($c), ['parse_mode'=>'HTML','reply_markup'=>contactsInlineMarkup($c)]);
                return;
            }
            if ($text==='🔄 Сменить клинику'){ $st=fullReset($uid); askClinic($cid); return; }

            // Если клиника не выбрана — сначала спросим её
            if (!$st['clinic']) { askClinic($cid); return; }

            // Потоки
            if ($st['flow']==='appointment'){
                if ($st['step']==='choose_mode'){
                    $kb=[[['text'=>'По направлению','callback_data'=>'apptmode:by_service'],['text'=>'По специалисту','callback_data'=>'apptmode:by_doctor']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]];
                    sendMessage($cid,headerAppointment($st).'Выберите способ записи:',['reply_markup'=>inlineButtons($kb)]);
                    return;
                }
                if ($st['step']==='get_name'){
                    if ($text===''){ sendMessage($cid,headerAppointment($st).'Введите имя:',['reply_markup'=>kb_back_cancel('appointment','get_name')]); return; }
                    $st['data']['name']=$text; $st['step']='get_phone'; saveState($uid,$st);
                    sharePhoneAsk($cid,'appointment'); return;
                }
                if ($st['step']==='get_phone'){
                    if (!isValidPhone($text)){ sendMessage($cid,headerAppointment($st).'Укажите телефон в формате +7XXXXXXXXXX:',['reply_markup'=>kb_back_cancel('appointment','get_phone')]); return; }
                    $st['data']['phone']=$text; $st['step']='get_date'; saveState($uid,$st);
                    sendCalendarAsk($cid,$st); return;
                }
                if ($st['step']==='get_comment'){
                    $st['data']['comment']=($text==='-'?'':$text);
                    $st['step']='get_contact_method'; saveState($uid,$st);
                    $kb = [
                        [['text'=>'📞 Перезвонить','callback_data'=>'contact_method:call']],
                        [['text'=>'💬 Написать в WhatsApp','callback_data'=>'contact_method:whatsapp']],
                        [['text'=>'💬 Написать в Telegram','callback_data'=>'contact_method:telegram']],
                        [['text'=>'⬅️ Назад','callback_data'=>'back:appointment:choose_time'],['text'=>'❌ Отмена','callback_data'=>'cancel']],
                    ];
                    sendMessage($cid,headerAppointment($st)."Выберите удобный способ связи:",['reply_markup'=>inlineButtons($kb)]);
                    return;
                }
                if ($st['step']==='confirm'){
                    $clinicTitle=clinics()[$st['clinic']]['title']; $sumUser=summarizeAppointment($clinicTitle,$st['data'],false);
                    $kb=[[['text'=>'⬅️ Назад','callback_data'=>'back:appointment:get_contact_method'],['text'=>'✅ Подтвердить','callback_data'=>'confirm:appointment']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]];
                    sendMessage($cid,$sumUser,['reply_markup'=>inlineButtons($kb)]); return;
                }
                if ($st['step']==='choose_time'){ sendChooseTimeAsk($cid,$st); return; }
            }

            if ($st['flow']==='deduction'){
                if ($st['step']==='get_name'){
                    if ($text===''){ sendMessage($cid,headerDeduction($st).'Введите имя:',['reply_markup'=>kb_back_cancel('deduction','get_name')]); return; }
                    $st['data']['name']=$text; $st['step']='get_phone'; saveState($uid,$st);
                    sharePhoneAsk($cid,'deduction'); return;
                }
                if ($st['step']==='get_phone'){
                    if (!isValidPhone($text)){ sendMessage($cid,headerDeduction($st).'Укажите телефон в формате +7XXXXXXXXXX:',['reply_markup'=>kb_back_cancel('deduction','get_phone')]); return; }
                    $st['data']['phone']=$text; $st['step']='get_email'; saveState($uid,$st);
                    sendMessage($cid,headerDeduction($st).'Введите вашу электронную почту:',['reply_markup'=>kb_back_cancel('deduction','get_phone')]); return;
                }
                if ($st['step']==='get_email'){
                    if (!isValidEmail($text)){ sendMessage($cid,headerDeduction($st).'Укажите корректный e-mail:',['reply_markup'=>kb_back_cancel('deduction','get_phone')]); return; }
                    $st['data']['email']=$text; $st['step']='confirm'; saveState($uid,$st);
                    $clinicTitle=clinics()[$st['clinic']]['title']; $sumUser=summarizeDeduction($clinicTitle,$st['data'],false);
                    $kb=[[['text'=>'⬅️ Назад','callback_data'=>'back:deduction:get_phone'],['text'=>'✅ Подтвердить','callback_data'=>'confirm:deduction']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]];
                    sendMessage($cid,$sumUser,['reply_markup'=>inlineButtons($kb)]); return;
                }
            }

            if ($st['flow']==='complaint'){
                if ($st['step']==='get_name'){
                    if ($text===''){ sendMessage($cid,headerComplaint($st).'Введите имя:',['reply_markup'=>kb_back_cancel('complaint','get_name')]); return; }
                    $st['data']['name']=$text; $st['step']='get_phone'; saveState($uid,$st);
                    sharePhoneAsk($cid,'complaint'); return;
                }
                if ($st['step']==='get_phone'){
                    if (!isValidPhone($text)){ sendMessage($cid,headerComplaint($st).'Укажите телефон в формате +7XXXXXXXXXX:',['reply_markup'=>kb_back_cancel('complaint','get_phone')]); return; }
                    $st['data']['phone']=$text; $st['step']='get_visit_date'; saveState($uid,$st);
                    sendMessage($cid,headerComplaint($st).'Введите дату визита (например, 19.11.2025):',['reply_markup'=>kb_back_cancel('complaint','get_visit_date')]); return;
                }
                if ($st['step']==='get_visit_date'){
                    $iso=parseDmyToIso($text); if(!$iso){ sendMessage($cid,headerComplaint($st).'Укажите дату в формате дд.мм.гггг:',['reply_markup'=>kb_back_cancel('complaint','get_visit_date')]); return; }
                    $st['data']['visit_date']=$iso; $st['step']='get_comment'; saveState($uid,$st);
                    sendMessage($cid,headerComplaint($st).'Опишите ситуацию.',['reply_markup'=>kb_back_cancel('complaint','get_comment')]); return;
                }
                if ($st['step']==='get_comment'){
                    $st['data']['comment']=$text; $st['step']='attach_choice'; saveState($uid,$st);
                    sendMessage($cid,headerComplaint($st).'Хотите прикрепить файлы?',['reply_markup'=>inlineButtons([[['text'=>'Да','callback_data'=>'attach_yes'],['text'=>'Нет','callback_data'=>'attach_no']],[['text'=>'⬅️ Назад','callback_data'=>'back:complaint:get_comment'],['text'=>'❌ Отмена','callback_data'=>'cancel']]])]); return;
                }
                if ($st['step']==='attach_files'){
                    sendMessage($cid,headerComplaint($st).'Пришлите фото/видео/документы.',['reply_markup'=>inlineButtons([[['text'=>'⬅️ Назад','callback_data'=>'back:complaint:get_comment'],['text'=>'✅ Подтвердить','callback_data'=>'confirm:complaint']],[['text'=>'❌ Отмена','callback_data'=>'cancel']]])]); return;
                }
            }

            // fallback
            if ($st['clinic'] && isset($cl[$st['clinic']])) sendKeyboardMain($cid,$cl[$st['clinic']]['title']); else askClinic($cid);

        } catch (\Throwable $e) {
            elog("MSG error: ".$e->getMessage());
            sendMessage($cid, "⚠️ Произошла ошибка. Попробуйте ещё раз /start");
        }
        return;
    }
}
