<?php

define('BROADCAST_TASK_FILE', 'broadcast_task.json');
define('BROADCAST_LOCK_FILE', 'broadcast.lock');
define('MESSAGE_LOG_FILE', 'sent_messages.json');
define('ERROR_LOG_FILE', 'error.log');

function logError($message) {
    $date = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG_FILE, "[$date] $message\n", FILE_APPEND);
}

$configFilePath = 'config.json';
try {
    if (!file_exists($configFilePath)) {
        throw new Exception("ConfigError: File not found - $configFilePath");
    }

    $configContent = file_get_contents($configFilePath);
    if ($configContent === false) {
        throw new Exception("ConfigError: Failed to read file");
    }

    $config = json_decode($configContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("ConfigError: Invalid JSON - " . json_last_error_msg());
    }

} catch (Exception $e) {
    logError("[CONFIG ERROR] " . $e->getMessage());
    die("Error: Unable to load bot configuration " . $e->getMessage());
}

$botToken = $config['BotConfiguration']['BotToken'];
$apiUrl = "https://api.telegram.org/bot$botToken/";

function sendRequest($method, $data = [])
{
    global $apiUrl;
    $url = $apiUrl . $method;
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data),
            'ignore_errors' => true // получать тело даже при ошибке
        ]
    ];
    $context = stream_context_create($options);
    try {
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception("Error executing request to Telegram API: $method");
        }

        return $response;

    } catch (Exception $e) {
        logError("[API ERROR] " . $e->getMessage());
        return json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function saveBroadcastMessages($messages)
{
    try {
        $allMessages = file_exists(MESSAGE_LOG_FILE)
            ? json_decode(file_get_contents(MESSAGE_LOG_FILE), true)
            : [];

        if (!is_array($allMessages)) {
            $allMessages = [];
        }

        $allMessages[] = $messages;

        file_put_contents(MESSAGE_LOG_FILE, json_encode($allMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    } catch (Exception $e) {
        logError(" [SAVE ERROR] " . $e->getMessage());
    }
}

function deleteLastBroadcast()
{
    if (!file_exists(MESSAGE_LOG_FILE)) {
        return false;
    }

    $allMessages = json_decode(file_get_contents(MESSAGE_LOG_FILE), true);

    if (empty($allMessages)) {
        return false;
    }

    // Берем последнюю рассылку
    $lastBroadcast = array_pop($allMessages);

    // Удаляем каждое сообщение из последней рассылки
    foreach ($lastBroadcast as $msg) {
        try {
            sendRequest('deleteMessage', [
                'chat_id' => $msg['chat_id'],
                'message_id' => $msg['message_id']
            ]);
        } catch (Exception $e) {
            logError("[DELETE ERROR] " . $e->getMessage());
        }
    }

    // Записываем обновленный список рассылок без последней
    file_put_contents(MESSAGE_LOG_FILE, json_encode($allMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return true;
}

// Проверяем задание
if (!file_exists(BROADCAST_TASK_FILE)) {
    logError("No broadcast task");
    exit("No task found\n");
}

$task = json_decode(file_get_contents(BROADCAST_TASK_FILE), true);

// Проверка блокировки (защита от повторного запуска)
if (file_exists(BROADCAST_LOCK_FILE)) {
    logError("Рассылка уже выполняется");
    exit("Рассылка уже выполняется\n");
}

// Создаём файл-блокировку
file_put_contents(BROADCAST_LOCK_FILE, getmypid());

// Загружаем список чатов
$chatDataJson = 'chat_data.json';
if (!file_exists($chatDataJson)) {
    logError("No chat data");
    unlink(BROADCAST_LOCK_FILE);
    exit("No chat data\n");
}

$activeChats = json_decode(file_get_contents($chatDataJson), true);
$sentMessages = [];

// Начало рассылки
foreach ($activeChats as $chat) {
    if ($task['type'] === 'photo') {
        $response = sendRequest('sendPhoto', [
            'chat_id' => $chat['id'],
            'photo' => $task['photo'],
            'caption' => $task['caption'],
            'parse_mode' => 'HTML'
        ]);
    } elseif ($task['type'] === 'text') {
        $response = sendRequest('sendMessage', [
            'chat_id' => $chat['id'],
            'text' => $task['content'],
            'parse_mode' => 'HTML'
        ]);
    } elseif ($task['type'] === 'test') {
        $response = sendRequest('sendPhoto', [
            'chat_id' => $config['adminId'],
            'photo' => $task['photo'],
            'caption' => $task['caption'],
            'parse_mode' => 'HTML'
        ]);
    } elseif ($task['type'] === 'deleteLastBroadcast') {
        if (deleteLastBroadcast()) {
            sendRequest('sendMessage', ['chat_id' => $config['adminId'], 'text' => 'Последняя рассылка удалена.']);
        } else {
            sendRequest('sendMessage', ['chat_id' => $config['adminId'], 'text' => 'Ошибка: нет сообщений для удаления.']);
        }
        break;
    }

    $response = json_decode($response, true);
    if (isset($response['ok']) && $response['ok']) {
        $sentMessages[] = [
            'chat_id' => $chat['id'],
            'message_id' => $response['result']['message_id']
        ];
    }
    else {
        logError("Error sending chat {$chat['id']}: " . json_encode($response, JSON_UNESCAPED_UNICODE));
    }
    usleep(200000);
}

if (!empty($sentMessages)) {
    saveBroadcastMessages($sentMessages);
}
// Удаляем задачу и блокировку
unlink(BROADCAST_TASK_FILE);
unlink(BROADCAST_LOCK_FILE);

sendRequest('sendMessage', ['chat_id' => $config['adminId'], 'parse_mode' => 'HTML', 'text' => 'Операция завершена.']);