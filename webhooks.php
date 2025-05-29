<?php

use AmoCRM\OAuth2\Client\Provider\AmoCRM;

include_once __DIR__ . '/src/bootstrap.php';

$logFile = __DIR__ . '/webhooks.log';

$provider = new AmoCRM([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'redirectUri' => $redirectUri,
]);

$accessToken = getValidAccessToken($provider, $logFile);

$rawInput = file_get_contents('php://input');
parse_str($rawInput, $input);

// Проверяем валидность данных
if (empty($input)) {
    file_put_contents($logFile, 'Отсутствуют данные для обработки' . PHP_EOL, FILE_APPEND);
}

$leadsAdd = $input['leads']['add'] ?? [];
$leadsUpdate = $input['leads']['update'] ?? [];
$contactsAdd = $input['contacts']['add'] ?? [];
$contactsUpdate = $input['contacts']['update'] ?? [];

// Обработка события создания сделки
if (!empty($leadsAdd)) {
    foreach ($leadsAdd as $lead) {
        addNote($lead, $provider, $accessToken, 'add', $logFile, 'leads');
    }
}

// Обработка события изменения сделки
if (!empty($leadsUpdate)) {
    foreach ($leadsUpdate as $lead) {
        addNote($lead, $provider, $accessToken, 'update', $logFile, 'leads');
    }
}

// Обработка события создания контакта
if (!empty($contactsAdd)) {
    foreach ($contactsAdd as $lead) {
        addNote($lead, $provider, $accessToken, 'add', $logFile, 'contacts');
    }
}

// Обработка события изменения контакта
if (!empty($contactsUpdate)) {
    foreach ($contactsUpdate as $lead) {
        addNote($lead, $provider, $accessToken, 'update', $logFile, 'contacts');
    }
}

/**
 * Функция добавления примечания.
 */
function addNote(array $entity, &$provider, $accessToken, $eventType, $logFile, string $entityType): void
{
    $leadId = $entity['id'] ?? null;

    if (!$leadId) {
        file_put_contents($logFile, "Ошибка: отсутствует ID сделки/контакта {$entityType}" . PHP_EOL, FILE_APPEND);
        return;
    }

    if (empty($entity['name'])) {
        file_put_contents($logFile, "Ошибка: отсутствует имя {$entityType}" . PHP_EOL, FILE_APPEND);
        return;
    }

    // Форматирование времени
    $timeField = ($eventType === 'add') ? 'created_at' : 'updated_at';
    $timestamp = $entity[$timeField] ?? time();
    $formattedTime = date('d.m.Y H:i:s', $timestamp);

    // Определение label и склонение для сообщений
    $entityLabels = [
        'leads' => ['name' => 'Сделка', 'gender' => 'feminine'],
        'contacts' => ['name' => 'Контакт', 'gender' => 'masculine'],
    ];

    if (!isset($entityLabels[$entityType])) {
        file_put_contents($logFile, "Ошибка: неизвестный тип сущности - {$entityType}\n", FILE_APPEND);
        return;
    }

    $label = $entityLabels[$entityType]['name'];
    $gender = $entityLabels[$entityType]['gender'];

    $verbAdd = ($gender === 'masculine') ? 'создан' : 'создана';
    $verbUpdate = ($gender === 'masculine') ? 'обновлен' : 'обновлена';

    // Генерирование текста примечания
    if ($eventType === 'add') {
        $text = "{$label} {$verbAdd}\n";;
        $text .= "Название: {$entity['name']}\n";
        $text .= "Ответственный: {$entity['responsible_user_id']}\n";
        $text .= "Дата создания: $formattedTime";
    } else {
        $text = "{$label} {$verbUpdate}\n";;
        $text .= "Измененные поля:\n";

        // Отправление запроса в API для получения изменений данных по событию сущности
        try {
            $data = $provider->getHttpClient()
                ->request('GET', $provider->urlAccount() . "api/v4/events?filter[entity_id][]={$leadId}&filter[entity]={$entityType}", [
                    'headers' => $provider->getHeaders($accessToken),
                ]);

            $statusCode = $data->getStatusCode();
            $responseBody = json_decode($data->getBody()->getContents(), true);
            $inputArray = $responseBody['_embedded']['events'][0]['value_after'][0];

            foreach ($inputArray as $mainKey => $subArray) {
                foreach ($subArray as $key => $value) {
                    $text .="$mainKey - $key : $value\n";
                }
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                file_put_contents($logFile, "Изменённые данные успешно получены для сделки/контакта по ID: $leadId\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, "Ошибка при получении измененных данных: $responseBody\n", FILE_APPEND);
            }

        } catch (Exception $e) {
            file_put_contents($logFile, "API Exception: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }

        $text .= "Дата изменения: $formattedTime";
    }

    // Формирование данных для примечания
    $noteData = [[
        'entity_id' => (int)$leadId,
        'note_type' => 'common',
        'params' => ['text' => $text]
    ]];

    // Отправление запроса в API
    try {
        $data = $provider->getHttpClient()
            ->request('POST', $provider->urlAccount() . "api/v4/{$entityType}/{$leadId}/notes", [
                'headers' => $provider->getHeaders($accessToken),
                'json' => $noteData
            ]);

        $statusCode = $data->getStatusCode();
        $responseBody = (string)$data->getBody();

        file_put_contents($logFile, "API Response Status: $statusCode\n", FILE_APPEND);
        file_put_contents($logFile, "API Response Body: $responseBody\n", FILE_APPEND);

        if ($statusCode >= 200 && $statusCode < 300) {
            file_put_contents($logFile, "Примечание успешно добавлено для сделки/контакта ID: $leadId\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "Ошибка при добавлении примечания: $responseBody\n", FILE_APPEND);
        }

    } catch (Exception $e) {
        file_put_contents($logFile, "API Exception: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
}
