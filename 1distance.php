<?php
//http://donjurist.ru/distance.php?lead_id={{ID элемента CRM}}&status=14
define('WEBHOOK_URL', 'https://donyurist.bitrix24.ru/rest/1/vpjaoorfem4q9fvy/');
define('CALENDAR_WEBHOOK_URL', 'https://donyurist.bitrix24.ru/rest/1/cm10v8rybvgyc7bh/');

$sourceNames = [
    'CALL' => 'Ростов заявка',
    'ADVERTISING' => 'Ростов звонок',
    'CALLBACK' => 'Свой контакт',
    '1|TELEGRAM' => 'Телеграм',
    '1|FOS_GREEN_API' => 'WhatsApp',
    '16' => 'Определитель номера',
    'EMAIL' => 'Краснодар заявка',
    '3' => 'Краснодар звонок',
    '1|VK' => 'Вконтакте',
    '17' => 'Онлайн банкротство',
];

file_put_contents('log.txt', "Скрипт запущен\n", FILE_APPEND);

if (isset($_REQUEST['lead_id']) && !empty($_REQUEST['lead_id'])) {
    // Удаляем префикс "L_", если он есть
    $leadId = str_replace('L_', '', $_REQUEST['lead_id']);
    file_put_contents('log.txt', "Получен запрос с идентификатором лида: $leadId\n", FILE_APPEND);
    handleLeadStatusChange($leadId);
} else {
    file_put_contents('log.txt', "Параметр lead_id не передан или пустой.\n", FILE_APPEND);
}

function handleLeadStatusChange($leadId) {
    global $sourceNames;

    file_put_contents('log.txt', "Обработка лида с ID: $leadId\n", FILE_APPEND);

    $leadData = getLeadData($leadId);
    if (!$leadData) {
        file_put_contents('log.txt', "Не удалось получить данные лида с ID: $leadId\n", FILE_APPEND);
        return;
    }

    $status = $leadData['result']['STATUS_ID'];
    if (trim($status) !== '14') { 
        file_put_contents('log.txt', "Статус не соответствует 'Дистанционно', выполнение остановлено\n", FILE_APPEND);
        return;
    }

    $clientName = $leadData['result']['TITLE'];
    $sourceId = $leadData['result']['SOURCE_ID'];
    $sourceName = isset($sourceNames[$sourceId]) ? $sourceNames[$sourceId] : $sourceId;
    $meetingDateTime = date("d.m.Y H:i:s", strtotime($leadData['result']['UF_CRM_1579787937']));
    $responsibleId = $leadData['result']['ASSIGNED_BY_ID'];
    $leadLink = "https://donyurist.bitrix24.ru/crm/lead/details/{$leadId}/";
    $phone = isset($leadData['result']['PHONE'][0]['VALUE']) ? $leadData['result']['PHONE'][0]['VALUE'] : 'Телефон не указан';
    $comments = isset($leadData['result']['COMMENTS']) ? $leadData['result']['COMMENTS'] : 'Комментарий не указан';

    $eventTitle = "Дистанционно, $clientName в $sourceName, $meetingDateTime, $phone";
    $description = "Событие создано автоматически.\n$leadLink\nИмя: $clientName\nТелефон: $phone\nДата и время: $meetingDateTime\nИсточник: $sourceName\nКомментарий: $comments";

    $sectionId = getCalendarSectionId($responsibleId);
    $color = getCalendarSectionColor($responsibleId);

    if (!$sectionId) {
        file_put_contents('log.txt', "Не удалось найти секцию для пользователя с ID: $responsibleId\n", FILE_APPEND);
        return;
    }

    $dateFrom = date("Y-m-d\TH:i:sP", strtotime($meetingDateTime));
    if (eventExists($leadId, $dateFrom, $eventTitle)) {
        file_put_contents('log.txt', "Событие для лида $leadId уже существует, выполнение остановлено\n", FILE_APPEND);
        return;
    }

    $response = createCalendarEvent($responsibleId, $sectionId, $eventTitle, $meetingDateTime, $description, $color, $leadId);

    if ($response && isset($response['result'])) {
        file_put_contents('log.txt', "Событие успешно создано с ID: " . $response['result'] . "\n", FILE_APPEND);
    } else {
        file_put_contents('log.txt', "Ошибка при создании события. Ответ от calendar.event.add: " . print_r($response, true) . "\n", FILE_APPEND);
    }
}

function eventExists($leadId, $dateFrom, $eventTitle) {
    $eventParams = [
        'filter' => [
            'UF_CRM_CAL_EVENT' => "L_$leadId",
            'NAME' => $eventTitle,
            'DATE_FROM' => $dateFrom,
        ]
    ];
    $eventResponse = callAPI('calendar.event.get', $eventParams);
    
    if (isset($eventResponse['result']) && count($eventResponse['result']) > 0) {
        file_put_contents('log.txt', "Событие для лида $leadId уже существует.\n", FILE_APPEND);
        return true;
    }
    return false;
}

function callAPI($method, $params) {
    $url = CALENDAR_WEBHOOK_URL . $method . '?' . http_build_query($params);
    $response = file_get_contents($url);
    return json_decode($response, true);
}

function getCalendarSectionId($userId) {
    $queryData = [
        'type' => 'user',
        'ownerId' => $userId
    ];
    $response = file_get_contents(CALENDAR_WEBHOOK_URL . 'calendar.section.get?' . http_build_query($queryData));
    $data = json_decode($response, true);
    file_put_contents('log.txt', "Доступные секции для пользователя $userId: " . print_r($data, true) . "\n", FILE_APPEND);

    if ($data && isset($data['result']) && count($data['result']) > 0) {
        return $data['result'][0]['ID'];
    }
    return null;
}

function getCalendarSectionColor($userId) {
    $queryData = [
        'type' => 'user',
        'ownerId' => $userId
    ];
    $response = file_get_contents(CALENDAR_WEBHOOK_URL . 'calendar.section.get?' . http_build_query($queryData));
    $data = json_decode($response, true);

    if ($data && isset($data['result']) && count($data['result']) > 0) {
        return $data['result'][0]['COLOR'];
    }
    return '#9cbe1c';
}

function getLeadData($leadId) {
    $queryData = [
        'id' => $leadId,
    ];
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.get?' . http_build_query($queryData));
    file_put_contents('log.txt', "Ответ от crm.lead.get: " . $response . "\n", FILE_APPEND);
    return json_decode($response, true);
}

function createCalendarEvent($userId, $sectionId, $title, $dateTime, $description, $color, $leadId) {
    $dateFrom = date("Y-m-d\TH:i:sP", strtotime($dateTime));
    $dateTo = date("Y-m-d\TH:i:sP", strtotime($dateTime . ' +1 hour'));

    $queryData = [
        'type' => 'user',
        'ownerId' => $userId,
        'name' => $title,
        'description' => $description,
        'from' => $dateFrom,
        'to' => $dateTo,
        'skipTime' => 'N',
        'section' => $sectionId,
        'color' => $color,
        'text_color' => '#283033',
        'accessibility' => 'busy',
        'importance' => 'normal',
        'is_meeting' => 'N',
        'private_event' => 'N',
        'UF_CRM_CAL_EVENT' => ["L_{$leadId}"]
    ];

    file_put_contents('log.txt', "Запрос для calendar.event.add: " . print_r($queryData, true) . "\n", FILE_APPEND);

    $ch = curl_init(CALENDAR_WEBHOOK_URL . 'calendar.event.add');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}
?>
