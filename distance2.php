// Два URL вебхуков для CRM и календаря
define('WEBHOOK_URL', 'https://donyurist.bitrix24.ru/rest/1/vpjaoorfem4q9fvy/');
define('CALENDAR_WEBHOOK_URL', 'https://donyurist.bitrix24.ru/rest/1/cm10v8rybvgyc7bh/');

// Массив для сопоставления SOURCE_ID с их значениями
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
    // Добавляйте другие сопоставления SOURCE_ID и их значений здесь
];

// Логируем старт скрипта
file_put_contents('log.txt', "Скрипт запущен\n", FILE_APPEND);

function handleLeadStatusChange($leadId) {
    global $sourceNames; // Подключаем глобальный массив

    file_put_contents('log.txt', "Обработка лида с ID: $leadId\n", FILE_APPEND);

    $leadData = getLeadData($leadId);

    if (!$leadData) {
        file_put_contents('log.txt', "Не удалось получить данные лида с ID: $leadId\n", FILE_APPEND);
        return;
    }

    $status = $leadData['result']['STATUS_ID'];
    file_put_contents('log.txt', "Статус лида: $status\n", FILE_APPEND);

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

    // Создаем заголовок и описание события с нужными данными
    $eventTitle = "Дистанционно, $clientName в $sourceName, $meetingDateTime";
    $description = "Событие создано автоматически при изменении статуса лида. [Ссылка на лид]($leadLink)";

    $sectionId = getCalendarSectionId($responsibleId);

    if (!$sectionId) {
        file_put_contents('log.txt', "Не удалось найти секцию для пользователя с ID: $responsibleId\n", FILE_APPEND);
        return;
    }

    $response = createCalendarEvent($responsibleId, $sectionId, $eventTitle, $meetingDateTime, $description, $leadId);

    if ($response && isset($response['result'])) {
        file_put_contents('log.txt', "Событие успешно создано с ID: " . $response['result'] . "\n", FILE_APPEND);
    } else {
        file_put_contents('log.txt', "Ошибка при создании события. Ответ от calendar.event.add: " . print_r($response, true) . "\n", FILE_APPEND);
    }
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

function getLeadData($leadId) {
    $queryData = [
        'id' => $leadId,
    ];
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.get?' . http_build_query($queryData));
    file_put_contents('log.txt', "Ответ от crm.lead.get: " . $response . "\n", FILE_APPEND);
    return json_decode($response, true);
}

function createCalendarEvent($userId, $sectionId, $title, $dateTime, $description, $leadId) {
    $dateFrom = date("Y-m-d\TH:i:sP", strtotime($dateTime));
    $dateTo = date("Y-m-d\TH:i:sP", strtotime($dateTime . ' +1 hour'));

    $queryData = [
        'type' => 'user',
        'ownerId' => $userId,
        'name' => $title,
        'description' => $description,
        'from' => $dateFrom,
        'to' => $dateTo,
        'skipTime' => 'Y',
        'section' => $sectionId,
        'color' => '#9cbe1c',
        'text_color' => '#283033',
        'accessibility' => 'busy',
        'importance' => 'normal',
        'is_meeting' => 'N',
        'private_event' => 'N',
        'remind' => [['type' => 'min', 'count' => 20]],
        'location' => 'Онлайн',
        'UF_CRM_CAL_EVENT' => ["L_{$leadId}"]
    ];

    file_put_contents('log.txt', "Запрос для calendar.event.add: " . print_r($queryData, true) . "\n", FILE_APPEND);

    $ch = curl_init(CALENDAR_WEBHOOK_URL . 'calendar.event.add');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents('log.txt', "HTTP статус: " . $httpCode . "\nОтвет от calendar.event.add: " . $response . "\n", FILE_APPEND);

    return json_decode($response, true);
}

// Пример использования
handleLeadStatusChange(79955);
