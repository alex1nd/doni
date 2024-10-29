<?php
// Ваши данные авторизации для API
define('WEBHOOK_URL', 'https://donyurist.bitrix24.ru/rest/1/*********************************/');

// Получаем данные, отправленные из Bitrix24
$rawInput = file_get_contents('php://input');
parse_str($rawInput, $input);

// Проверяем, что событие относится к добавлению лида
if (isset($input['event']) && strtoupper($input['event']) == 'ONCRMLEADADD') {
    $newLeadId = $input['data']['FIELDS']['ID'] ?? null;
    
    if ($newLeadId) {
        // Запрашиваем данные нового лида, включая номер телефона и источник
        $queryData = [
            'id' => $newLeadId
        ];
        
        $response = file_get_contents(WEBHOOK_URL . 'crm.lead.get?' . http_build_query($queryData));
        
        if ($response !== false) {
            $leadData = json_decode($response, true);
            
            // Проверяем, что источник лида соответствует 'SOURCE_ID' => '16'
            if (isset($leadData['result']['SOURCE_ID']) && $leadData['result']['SOURCE_ID'] == '16') {
                $phone = $leadData['result']['PHONE'][0]['VALUE'];
                
                // Ищем лиды с таким же номером телефона
                $leads = findLeadByPhone($phone);
                
                if (count($leads) > 1) {
                    // Если найден дубль, обновляем основной лид и удаляем дубль
                    $mainLead = $leads[0];
                    $duplicateLead = $leads[1];
                    
                    $comment = "Информация из дубля: " . $duplicateLead['TITLE'];
                    updateLead($mainLead['ID'], $comment);
                    
                    // Удаляем дубль с попытками
                    deleteLeadWithRetries($duplicateLead['ID']);
                }
            }
        }
    }
}

// Функция для поиска лида по номеру телефона
function findLeadByPhone($phone) {
    $queryData = [
        'filter' => ['PHONE' => $phone],
        'select' => ['ID', 'TITLE', 'PHONE']
    ];
    
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.list?' . http_build_query($queryData));
    $result = json_decode($response, true);
    return $result['result'] ?? [];
}

// Функция для обновления существующего лида
function updateLead($leadId, $newComment) {
    $queryData = [
        'id' => $leadId
    ];
    
    $response = file_get_contents(WEBHOOK_URL . 'crm.lead.get?' . http_build_query($queryData));
    if ($response === false) {
        return;
    }
    
    $leadData = json_decode($response, true);
    $currentComment = $leadData['result']['COMMENTS'] ?? '';
    $updatedComment = $currentComment . "\n" . $newComment;

    $queryData = [
        'id' => $leadId,
        'fields' => [
            'COMMENTS' => $updatedComment
        ]
    ];
    
    file_get_contents(WEBHOOK_URL . 'crm.lead.update?' . http_build_query($queryData));
}

// Функция для удаления лида с повторной попыткой в случае неудачи
function deleteLeadWithRetries($leadId) {
    $attempts = 0;
    $maxAttempts = 3;
    while ($attempts < $maxAttempts) {
        $response = file_get_contents(WEBHOOK_URL . 'crm.lead.delete?id=' . $leadId);
        $result = json_decode($response, true);

        if (isset($result['result']) && $result['result'] === true) {
            return true;
        }

        $attempts++;
        sleep(3); // Ждем 1 секунду перед повторной попыткой
    }

    return false;
}
?>
