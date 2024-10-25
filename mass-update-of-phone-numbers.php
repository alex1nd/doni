<?php
// Ваши данные авторизации для API
define('WEBHOOK_URL', 'https://***************.bitrix24.ru/rest/1/***************/');

// Логируем старт скрипта
file_put_contents('log.txt', "Mass phone update script started\n", FILE_APPEND);

// Получаем все лиды с определенным источником
$leads = getLeadsBySource('6'); // Замените '16' на ID нужного источника

foreach ($leads as $lead) {
    // Проверяем телефонные номера в каждом лиде
    $phoneUpdated = false;
    $phones = $lead['PHONE'];
    $newPhones = [];

    foreach ($phones as $phoneInfo) {
        $originalPhone = $phoneInfo['VALUE'];
        $phoneId = $phoneInfo['ID']; // Сохраняем ID телефона

        // Если номер начинается с "7" и не содержит "+7", то нормализуем его
        if (strpos($originalPhone, '7') === 0 && strpos($originalPhone, '+7') !== 0) {
            $newPhone = '+7' . substr($originalPhone, 1);
            $phoneUpdated = true;

            // Логируем изменение
            file_put_contents('log.txt', "Updating phone for lead ID: {$lead['ID']} from $originalPhone to $newPhone\n", FILE_APPEND);

            // Заменяем старый номер на нормализованный, сохраняя ID телефона
            $newPhones[] = ['ID' => $phoneId, 'VALUE' => $newPhone, 'VALUE_TYPE' => $phoneInfo['VALUE_TYPE']];
        } else {
            // Если номер не требует изменения, просто добавляем его как есть
            $newPhones[] = $phoneInfo;
        }
    }

    // Если был найден и обновлен номер, обновляем телефоны в лиде
    if ($phoneUpdated) {
        updateLeadPhone($lead['ID'], $newPhones);
    }
}

file_put_contents('log.txt', "Mass phone update script completed\n", FILE_APPEND);

// Функция для получения лидов по источнику
function getLeadsBySource($sourceId) {
    $allLeads = [];
    $start = 0;

    do {
        $queryData = [
            'select' => ['ID', 'TITLE', 'PHONE'],
            'filter' => ['SOURCE_ID' => $sourceId, 'HAS_PHONE' => 'Y'],
            'start' => $start
        ];

        $response = file_get_contents(WEBHOOK_URL . 'crm.lead.list?' . http_build_query($queryData));
        if ($response === false) {
            file_put_contents('log.txt', "Error fetching lead list: " . $http_response_header[0] . "\n", FILE_APPEND);
            break;
        }

        $result = json_decode($response, true);
        $leads = $result['result'] ?? [];
        $allLeads = array_merge($allLeads, $leads);
        $start = $result['next'] ?? null;

    } while ($start !== null);

    return $allLeads;
}

// Функция для обновления номера телефона в лиде
function updateLeadPhone($leadId, $phones) {
    $queryData = [
        'id' => $leadId,
        'fields' => [
            'PHONE' => $phones
        ]
    ];

    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.update?' . http_build_query($queryData));
    if ($response === false) {
        file_put_contents('log.txt', "Error updating lead phone for ID: $leadId\n", FILE_APPEND);
    } else {
        file_put_contents('log.txt', "Successfully updated phone for lead ID: $leadId\n", FILE_APPEND);
    }
}
?>
