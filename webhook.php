<?php

// Загружаем конфигурацию
$configFilePath = 'config.json';

define('MESSAGE_LOG_FILE', 'sent_messages.json');
define('ERROR_LOG_FILE', 'error.log');

function logError($message) {
    $date = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG_FILE, "[$date] $message\n", FILE_APPEND);
}

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

// Функция отправки запросов к Telegram API
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
function isUserSubscribed($userId, $channelUsername)
{
    $response = sendRequest('getChatMember', [
        'chat_id' => $channelUsername,
        'user_id' => $userId
    ]);
    // Логгирование ответа API
    //file_put_contents('subscription_log.txt', date('Y-m-d H:i:s') . " - Response: " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    $response = json_decode($response, true);
    if (isset($response['ok']) && $response['ok']) {
        $status = $response['result']['status'];
        // Пользователь считается подписанным, если статус "member", "administrator" или "creator"
        return in_array($status, ['member', 'administrator', 'creator']);
    }

    return false;
}

// Чтение входящих данных
$content = file_get_contents('php://input');
if ($content === false) {
    logError("Failed to read incoming data.");
    exit;
}
$update = json_decode($content, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logError("JSON decoding error: " . json_last_error_msg());
    exit;
}

// Обрабатываем сообщение
if (isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];
    $text = $update['message']['text'];

    // Получаем информацию о пользователе
    $userId = $update['message']['from']['id'];
    $firstName = $update['message']['from']['first_name'] ?? '';
    $lastName = $update['message']['from']['last_name'] ?? '';
    $username = $update['message']['from']['username'] ?? '';

    // Файл для сохранения данных пользователей
    $chatDataJson  = 'chat_data.json';
    if (file_get_contents($chatDataJson) === false) {
    logError("Failed to read chat data file: $chatDataJson");
    exit;
    }
    if (!file_exists($chatDataJson )) {
        file_put_contents($chatDataJson , json_encode([]));
    }

    // Загружаем данные пользователей из файла
    $activeChats = json_decode(file_get_contents($chatDataJson ), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
    logError("JSON decoding error in $chatFile: " . json_last_error_msg());
    exit;
    }
    // Проверяем, существует ли уже запись для этого чата
    $found = false;
    foreach ($activeChats as &$chat) {
        if ($chat['id'] == $chatId) {
            // Обновляем информацию о пользователе, если данные изменились
            $chat['first_name'] = $firstName;
            $chat['last_name'] = $lastName;
            $chat['username'] = $username;
            $found = true;
            break;
        }
    }

    // Если запись для этого чата отсутствует, добавляем новую
    if (!$found) {
        $activeChats[] = [
            'id' => $chatId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username
        ];
    }

    // Сохраняем обновленные данные в файл
    if (file_put_contents($chatDataJson , json_encode($activeChats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        logError("Failed to write data to file $chatFile");
    }
    // // Проверка подписки на канал
    $channelUsername = '@gomselmashofficial';

    if (!isUserSubscribed($userId, $channelUsername)) {
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML',
            'text' => "<b>Просим вас подписаться на наш канал.</b>\nПожалуйста, подпишитесь на <a href='https://t.me/gomselmashofficial'>наш канал</a>, чтобы продолжить использование бота."
        ]);
        exit; // Завершаем выполнение, если пользователь не подписан
    }
    // Обработка команд от администратора
    if (str_starts_with($text, '/broadcast_photo') && $chatId == $config['adminId']) {
        $params = explode(' ', $text, 3);

        if (count($params) < 3) {
            sendRequest('sendMessage', ['chat_id' => $chatId, 'parse_mode' => 'HTML', 'text' => 'Ошибка: необходимо указать URL изображения и текст сообщения.']);
            return;
        }

        // Разбираем параметры
        $photoUrl = $params[1]; // URL изображения
        $caption = $params[2];  // Текст сообщения

        $task = [
            'type' => 'photo',
            'photo' => $photoUrl,
            'caption' => $caption,
            'created_at' => time(),
        ];
        file_put_contents("broadcast_task.json", json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        exec("php broadcast_service.php > /dev/null &");

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Рассылка с фото поставлена в очередь ✅'
        ]);
    } elseif (str_starts_with($text, '/broadcast_test') && $chatId == $config['adminId']) {
        $params = explode(' ', $text, 3);

        if (count($params) < 3) {
            sendRequest('sendMessage', ['chat_id' => $chatId, 'parse_mode' => 'HTML', 'text' => 'Ошибка: необходимо указать URL изображения и текст сообщения.']);
            return;
        }

        // Разбираем параметры
        $photoUrl = $params[1]; // URL изображения
        $caption = $params[2];  // Текст сообщения

        $task = [
            'type' => 'test',
            'photo' => $photoUrl,
            'caption' => $caption,
            'created_at' => time(),
        ];
        file_put_contents("broadcast_task.json", json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        exec("php broadcast_service.php > /dev/null &");

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Тестовая рассылка поставлена в очередь ✅'
        ]);
    } elseif (str_starts_with($text, '/broadcast_all') && $chatId == $config['adminId']) {
        // Извлекаем сообщение для рассылки
        $broadcastMessage = trim(substr($text, strlen('/broadcast_all')));

        if (empty($broadcastMessage)) {
            sendRequest('sendMessage', ['chat_id' => $chatId, 'parse_mode' => 'HTML', 'text' => 'Ошибка: сообщение для рассылки пустое.']);
            return;
        }

        $task = [
            'type' => 'text',
            'content' => $broadcastMessage,
            'created_at' => time(),
        ];
        file_put_contents("broadcast_task.json", json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        exec("php broadcast_service.php > /dev/null &");

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Рассылка текста поставлена в очередь ✅'
        ]);
    } elseif ($text === '/delete_last_broadcast' && $chatId == $config['adminId']) {
        $task = [
            'type' => 'deleteLastBroadcast',
            'created_at' => time(),
        ];
        file_put_contents("broadcast_task.json", json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        exec("php broadcast_service.php > /dev/null &");

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Удаление в очереди ✅'
        ]);
    }
    switch (mb_strtolower($text)) {
        case "вернуться":
        case "/start":
            $keyboard = [
                'keyboard' => [
                    [
                        ['text' => 'Фотоматериалы 📸'],
                        ['text' => 'Видеоматериалы 🎥'],
                    ],
                    [
                        ['text' => 'Каталоги 🗂️'],
                        ['text' => 'Покупка 💸']
                        
                    ],
                    [
                        ['text' => 'Сервис 🛠️']
                    ]
                ],
                'resize_keyboard' => true // Изменяем размер клавиш под экран
            ];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Что вас интересует?",
                'reply_markup' => json_encode($keyboard)
            ]);
            break;

        case "фотоматериалы 📸":
            $keyboardPhotoModels = [
                'keyboard' => [
                    [
                        ['text' => "Зерноуборочные комбайны 📸"],
                        ['text' => "Кормоуборочные комбайны 📸"]
                    ],
                    [
                        ['text' => "Самоходные косилки 📸"],
                        ['text' => "Фотографии без фона 📸"]
                    ],
                    [
                        ['text' => "Вернуться"]
                    ]
                ],
                'resize_keyboard' => true
            ];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите категорию продукции",
                'reply_markup' => json_encode($keyboardPhotoModels)
            ]);
            break;

        case "видеоматериалы 🎥":
            $link = $config['videoLinks']['main'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;
    
        case "каталоги 🗂️":
            $keyboardCatalogsModels = [
                'keyboard' => [
                    [
                        ['text' => "Зерноуборочные комбайны 🗂️"],
                        ['text' => "Кормоуборочные комбайны 🗂️"]
                    ],
                    [
                        ['text' => "Самоходные косилки 🗂️"],
                        ['text' => "Вернуться"]
                    ]
                ],
                'resize_keyboard' => true
            ];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите категорию продукции",
                'reply_markup' => json_encode($keyboardCatalogsModels)
            ]);
            break;

        case "покупка 💸":
            $keyboardPurchase = [
                'keyboard' => [
                    [
                        ['text' => "Техника 🌾"],
                        ['text' => "Запчасти ⚙️"],
                        ['text' => "Вернуться"]
                    ]
                ],
                'resize_keyboard' => true
            ];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Что вы хотите приобрести?",
                'reply_markup' => json_encode($keyboardPurchase)
            ]);
            break;

        case "сервис 🛠️":
            $keyboardService = [
                'keyboard' => [
                    [
                        ['text' => "Беларусь 🇧🇾🛠️"],
                        ['text' => "Россия 🇷🇺🛠️"]
                    ],
                    [
                        ['text' => "Казахстан 🇰🇿🛠️"],
                        ['text' => "Вернуться"]
                    ]
                ],
                'resize_keyboard' => true
            ];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите регион",
                'reply_markup' => json_encode($keyboardService)
            ]);
            break;

        case "техника 🌾":
            $keyboardVehiclesPurchase = [
                'keyboard' => [
                    [
                        ['text' => "Беларусь 🇧🇾🌾"],
                        ['text' => "Россия 🇷🇺🌾"]
                    ],
                    [
                        ['text' => "Казахстан 🇰🇿🌾"],
                        ['text' => "Вернуться"]
                    ]
                ],
                'resize_keyboard' => true
            ];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите регион",
                'reply_markup' => json_encode($keyboardVehiclesPurchase)
            ]);
            break;

        case "запчасти ⚙️":
            $keyboardSparesPurchase = [
                'keyboard' => [
                    [
                        ['text' => "Беларусь 🇧🇾⚙️"],
                        ['text' => "Россия 🇷🇺⚙️"]
                    ],
                    [
                        ['text' => "Казахстан 🇰🇿⚙️"],
                        ['text' => "Вернуться"]
                    ]
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите регион",
                'reply_markup' => json_encode($keyboardSparesPurchase)
            ]);
            break;
        case "казахстан 🇰🇿🌾":
            $replyKeyboardKazakhstan = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾🌾"], ["text" => "Россия 🇷🇺🌾"]],
                    [["text" => "Казахстан 🇰🇿🌾"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['KZ']['Venicles'],
                'reply_markup' => json_encode($replyKeyboardKazakhstan)
            ]);
            break;
        case "казахстан 🇰🇿⚙️":
            $replyKeyboardKazakhstan = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾⚙️"], ["text" => "Россия 🇷🇺⚙️"]],
                    [["text" => "Казахстан 🇰🇿⚙️"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];


            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['KZ']['Spares'],
                'reply_markup' => json_encode($replyKeyboardKazakhstan)
            ]);
            break;
        case "казахстан 🇰🇿🛠️":
            $replyKeyboardKazakhstan = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾🛠️"], ["text" => "Россия 🇷🇺🛠️"]],
                    [["text" => "Казахстан 🇰🇿🛠️"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];


            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['KZ']['Service'],
                'reply_markup' => json_encode($replyKeyboardKazakhstan)
            ]);
            break;
        case "россия 🇷🇺🌾":
            $replyKeyboardRussia = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾🌾"], ["text" => "Россия 🇷🇺🌾"]],
                    [["text" => "Казахстан 🇰🇿🌾"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['RU']['Venicles'],
                'reply_markup' => json_encode($replyKeyboardRussia)
            ]);
            break;
        case "россия 🇷🇺⚙️":
            $replyKeyboardRussia = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾⚙️"], ["text" => "Россия 🇷🇺⚙️"]],
                    [["text" => "Казахстан 🇰🇿⚙️"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['RU']['Spares'],
                'reply_markup' => json_encode($replyKeyboardRussia)
            ]);
            break;
        case "россия 🇷🇺🛠️":
            $replyKeyboardRussia = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾🛠️"], ["text" => "Россия 🇷🇺🛠️"]],
                    [["text" => "Казахстан 🇰🇿🛠️"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['RU']['Service'],
                'reply_markup' => json_encode($replyKeyboardRussia)
            ]);
            break;
        case "беларусь 🇧🇾🌾":
            $replyKeyboardBelarus = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾🌾"], ["text" => "Россия 🇷🇺🌾"]],
                    [["text" => "Казахстан 🇰🇿🌾"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['BY']['Venicles'],
                'reply_markup' => json_encode($replyKeyboardBelarus)
            ]);
            break;
        case "беларусь 🇧🇾⚙️":
            $replyKeyboardBelarus = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾⚙️"], ["text" => "Россия 🇷🇺⚙️"]],
                    [["text" => "Казахстан 🇰🇿⚙️"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['BY']['Spares'],
                'reply_markup' => json_encode($replyKeyboardBelarus)
            ]);
            break;
        case "беларусь 🇧🇾🛠️":
            $replyKeyboardBelarus = [
                'keyboard' => [
                    [["text" => "Беларусь 🇧🇾🛠️"], ["text" => "Россия 🇷🇺🛠️"]],
                    [["text" => "Казахстан 🇰🇿🛠️"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => $config['contacts']['BY']['Service'],
                'reply_markup' => json_encode($replyKeyboardBelarus)
            ]);
            break;
        case "зерноуборочные комбайны 📸":
            $replyKeyboardGrainHarvestersPhoto = [
                'keyboard' => [
                    [["text" => "GS2124 📸"], ["text" => "GH800/GH810 📸"]],
                    [["text" => "GR700 📸"], ["text" => "GS12A1 📸"]],
                    [["text" => "GS400 📸"], ["text" => "GS5 📸"]],
                    [["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите модель",
                'reply_markup' => json_encode($replyKeyboardGrainHarvestersPhoto)
            ]);
            break;

        case "кормоуборочные комбайны 📸":
            $replyKeyboardForageHarvestersPhoto = [
                'keyboard' => [
                    [["text" => "FS650 📸"], ["text" => "FS80 PRO 📸"]],
                    [["text" => "FS3000 (К-Г-6) 📸"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите модель",
                'reply_markup' => json_encode($replyKeyboardForageHarvestersPhoto)
            ]);
            break;

        case "самоходные косилки 📸":
            $replyKeyboardSelfPropelledWindrowersPhoto = [
                'keyboard' => [
                    [["text" => "CS200 📸"], ["text" => "CS140 📸"]],
                    [["text" => "CS100 📸"], ["text" => "CS150 CROSS 📸"]],
                    [["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите модель",
                'reply_markup' => json_encode($replyKeyboardSelfPropelledWindrowersPhoto)
            ]);
            break;
        case "зерноуборочные комбайны 🗂️":
            $replyKeyboardGrainHarvestersCatalog = [
                'keyboard' => [
                    [["text" => "GS2124 📄"], ["text" => "GH800/GH810 📄"]],
                    [["text" => "GR700 📄"], ["text" => "GS12A1 📄"]],
                    [["text" => "GS400 📄"], ["text" => "GS5 📄"]],
                    [["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите модель",
                'reply_markup' => json_encode($replyKeyboardGrainHarvestersCatalog)
            ]);
            break;

        case "кормоуборочные комбайны 🗂️":
            $replyKeyboardForageHarvestersCatalog = [
                'keyboard' => [
                    [["text" => "FS650 📄"], ["text" => "FS80 PRO 📄"]],
                    [["text" => "FS3000 (К-Г-6) 📄"], ["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите модель",
                'reply_markup' => json_encode($replyKeyboardForageHarvestersCatalog)
            ]);
            break;

        case "самоходные косилки 🗂️":
            $replyKeyboardSelfPropelledWindrowersCatalog = [
                'keyboard' => [
                    [["text" => "CS200 📄"], ["text" => "CS140 📄"]],
                    [["text" => "CS100 📄"], ["text" => "CS150 CROSS 📄"]],
                    [["text" => "Вернуться"]],
                ],
                'resize_keyboard' => true
            ];

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Выберите модель",
                'reply_markup' => json_encode($replyKeyboardSelfPropelledWindrowersCatalog)
            ]);
            break;

         case "фотографии без фона 📸":
            $link = $config['photoLinks']['background_none'];
            sendRequest('sendMessage', [
                 'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                 'text' => "Ссылка для скачивания: $link"
             ]);
            break;

        case "gs2124 📸":
            $link = $config['photoLinks']['gs2124'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gh800/gh810 📸":
            $link = $config['photoLinks']['gh800_gh810'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gr700 📸":
            $link = $config['photoLinks']['gr700'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs12a1 📸":
            $link = $config['photoLinks']['gs12a1'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs400 📸":
            $link = $config['photoLinks']['gs400'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs5 📸":
            $link = $config['photoLinks']['gs5'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs200 📸":
            $link = $config['photoLinks']['gs200'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "fs650 📸":
            $link = $config['photoLinks']['fs650'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "fs80 pro 📸":
            $link = $config['photoLinks']['fs80_pro'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "fs3000 (к-г-6) 📸":
            $link = $config['photoLinks']['fs3000'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "cs200 📸":
            $link = $config['photoLinks']['cs200'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "cs150 cross 📸":
            $link = $config['photoLinks']['cs150'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;
        case "cs140 📸":
            $link = $config['photoLinks']['cs140'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;
        case "cs100 📸":
            $link = $config['photoLinks']['cs100'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;
        case "gs2124 📄":
            $link = $config['catalogLinks']['gs2124'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gh800/gh810 📄":
            $link = $config['catalogLinks']['gh800_gh810'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gr700 📄":
            $link = $config['catalogLinks']['gr700'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs12a1 📄":
            $link = $config['catalogLinks']['gs12a1'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs400 📄":
            $link = $config['catalogLinks']['gs400'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs5 📄":
            $link = $config['catalogLinks']['gs5'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "gs200 📄":
            $link = $config['catalogLinks']['gs200'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "fs650 📄":
            $link = $config['catalogLinks']['fs650'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "fs80 pro 📄":
            $link = $config['catalogLinks']['fs80_pro'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "fs3000 (к-г-6) 📄":
            $link = $config['catalogLinks']['fs3000'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "cs200 📄":
            $link = $config['catalogLinks']['cs200'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;

        case "cs150 cross 📄":
            $link = $config['catalogLinks']['cs150'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;
        case "cs140 📄":
            $link = $config['catalogLinks']['cs140'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;
        case "cs100 📄":
            $link = $config['catalogLinks']['cs100'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "Ссылка для скачивания: $link"
            ]);
            break;
        default:
            $keyboard = [
                'keyboard' => [
                    [
                        ['text' => 'Фотоматериалы 📸'],
                        ['text' => 'Каталоги 🗂️']
                    ],
                    [
                        ['text' => 'Покупка 💸'],
                        ['text' => 'Сервис 🛠️']
                    ]
                ],
                'resize_keyboard' => true
            ];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'HTML',
                'text' => "К сожалению, я не могу отвечать на сообщения. Пожалуйста, используйте предложенные команды, чтобы получить интересующую информацию!",
                'reply_markup' => json_encode($keyboard)
            ]);
            break;
    }
}
