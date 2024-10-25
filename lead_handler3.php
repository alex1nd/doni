<?php
// Ваши данные авторизации для API
define('WEBHOOK_URL', 'https://donyurist.bitrix24.ru/rest/1/uc4zdlah2dtbxqc3/');

// Логируем старт скрипта
file_put_contents('log.txt', "PHP script started\n", FILE_APPEND);

// Получаем данные, отправленные из Bitrix24
$rawInput = file_get_contents('php://input');
file_put_contents('log.txt', "Raw input data: " . $rawInput . "\n", FILE_APPEND);

// Преобразуем данные из URL-кодированных параметров в массив
parse_str($rawInput, $input);

// Логируем входящие данные после преобразования
file_put_contents('log.txt', "Parsed input data: " . print_r($input, true) . "\n", FILE_APPEND);

// Проверяем, что поле 'event' присутствует и его значение
if (isset($input['event'])) {
    file_put_contents('log.txt', "Event found: " . $input['event'] . "\n", FILE_APPEND);
} else {
    file_put_contents('log.txt', "No 'event' field found in input.\n", FILE_APPEND);
}

// Проверяем, что событие относится к добавлению лида
if (isset($input['event']) && strtoupper($input['event']) == 'ONCRMLEADADD') {
    // Получаем ID нового лида
    $newLeadId = $input['data']['FIELDS']['ID'] ?? null;
    
    if ($newLeadId) {
        // Логируем ID нового лида
        file_put_contents('log.txt', "Processing lead with ID: $newLeadId\n", FILE_APPEND);
        
        // Запрашиваем данные нового лида, включая номер телефона и источник
        $queryData = [
            'id' => $newLeadId
        ];
        
        $response = file_get_contents(WEBHOOK_URL . 'crm.lead.get?' . http_build_query($queryData));
        
        // Проверяем, что запрос прошел успешно
        if ($response === false) {
            file_put_contents('log.txt', "Error fetching lead data: " . $http_response_header[0] . "\n", FILE_APPEND);
        } else {
            $leadData = json_decode($response, true);
            
            // Логируем полученные данные о лиде
            file_put_contents('log.txt', "Lead data: " . print_r($leadData, true) . "\n", FILE_APPEND);
            
            // Проверяем, что источник лида соответствует 'SOURCE_ID' => '16'
            if (isset($leadData['result']['SOURCE_ID']) && $leadData['result']['SOURCE_ID'] == '16') {
                file_put_contents('log.txt', "Lead source matches: 'SOURCE_ID' = 16\n", FILE_APPEND);
                
                // Получаем телефон из данных лида и нормализуем его
                $phone = $leadData['result']['PHONE'][0]['VALUE'];
                $normalizedPhone = normalizePhone($phone);
                file_put_contents('log.txt', "Normalized phone number: $normalizedPhone\n", FILE_APPEND);
                
                // Ищем лиды с таким же нормализованным номером телефона
                $leads = findLeadByPhone($normalizedPhone);
                file_put_contents('log.txt', "Found leads: " . print_r($leads, true) . "\n", FILE_APPEND);
                
                if (count($leads) > 1) {
                    // Если найден дубль, обновляем основной лид и удаляем дубль
                    $mainLead = $leads[0];
                    $duplicateLead = $leads[1];
                    
                    $comment = "Информация из дубля: " . $duplicateLead['TITLE'];
                    file_put_contents('log.txt', "Updating main lead (ID: {$mainLead['ID']}) with comment: $comment\n", FILE_APPEND);
                    
                    updateLead($mainLead['ID'], $comment);
                    
                    file_put_contents('log.txt', "Deleting duplicate lead (ID: {$duplicateLead['ID']})\n", FILE_APPEND);
                    deleteLead($duplicateLead['ID']);
                } else {
                    file_put_contents('log.txt', "No duplicate leads found.\n", FILE_APPEND);
                }
            } else {
                file_put_contents('log.txt', "Lead source does not match 'SOURCE_ID' = 16\n", FILE_APPEND);
            }
        }
    } else {
        file_put_contents('log.txt', "Lead ID not found in input data.\n", FILE_APPEND);
    }
} else {
    file_put_contents('log.txt', "Event is not 'onCrmLeadAdd'.\n", FILE_APPEND);
}

// Функция для нормализации номера телефона
function normalizePhone($phone) {
    // Убираем все нецифровые символы и заменяем '+7' на '7'
    $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
    
    // Если номер начинается с '8', заменяем его на '7'
    if (substr($normalizedPhone, 0, 1) == '8') {
        $normalizedPhone = '7' . substr($normalizedPhone, 1);
    }
    
    return $normalizedPhone;
}

// Функция для поиска лида по нормализованному номеру телефона
function findLeadByPhone($phone) {
    $queryData = [
        'filter' => ['PHONE' => $phone],
        'select' => ['ID', 'TITLE', 'PHONE']
    ];
    
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.list?' . http_build_query($queryData));
    if ($response === false) {
        file_put_contents('log.txt', "Error fetching lead list: " . $http_response_header[0] . "\n", FILE_APPEND);
    }
    
    $result = json_decode($response, true);
    file_put_contents('log.txt', "Lead list response: " . print_r($result, true) . "\n", FILE_APPEND);
    return $result['result'] ?? [];
}

// Функция для обновления существующего лида
function updateLead($leadId, $newComment) {
    // Запрашиваем текущий комментарий лида
    $queryData = [
        'id' => $leadId
    ];
    
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.get?' . http_build_query($queryData));
    if ($response === false) {
        file_put_contents('log.txt', "Error fetching lead data for update: " . $http_response_header[0] . "\n", FILE_APPEND);
        return;
    }
    
    $leadData = json_decode($response, true);
    
    // Получаем текущий комментарий
    $currentComment = $leadData['result']['COMMENTS'] ?? '';
    
    // Объединяем старый и новый комментарии
    $updatedComment = $currentComment . "\n" . $newComment;

    // Записываем объединенный комментарий обратно в лид
    $queryData = [
        'id' => $leadId,
        'fields' => [
            'COMMENTS' => $updatedComment
        ]
    ];
    
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.update?' . http_build_query($queryData));
    if ($response === false) {
        file_put_contents('log.txt', "Error updating lead with ID: $leadId\n", FILE_APPEND);
    } else {
        file_put_contents('log.txt', "Successfully updated lead with ID: $leadId\n", FILE_APPEND);
    }
}

// Функция для удаления лида
function deleteLead($leadId) {
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.delete?id=' . $leadId);
    if ($response === false) {
        file_put_contents('log.txt', "Error deleting lead with ID: $leadId\n", FILE_APPEND);
    } else {
        file_put_contents('log.txt', "Successfully deleted lead with ID: $leadId\n", FILE_APPEND);
    }
}
?>