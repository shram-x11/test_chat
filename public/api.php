<?php

declare(strict_types=1);

require __DIR__ . '/../app/session.php';

const MESSAGES = 'chat:messages';
const BATCH_SIZE = 100;

function reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function readPayload(): array
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') {
        reply(415, ['error' => 'Ожидается JSON.']);
    }

    $body = file_get_contents('php://input', false, null, 0, 8193);
    if ($body === false || strlen($body) > 8192 || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        reply(413, ['error' => 'Запрос слишком большой.']);
    }

    $data = json_decode($body, true, 8);
    if (!is_array($data)) {
        reply(400, ['error' => 'Некорректный JSON.']);
    }

    return $data;
}

function chatText(mixed $value, int $limit, string $error): string
{
    $text = is_string($value) ? preg_replace('/\A\s+|\s+\z/u', '', $value) : null;
    if ($text === null || !preg_match('/\A[^\p{Cc}\p{Zl}\p{Zp}]{1,' . $limit . '}\z/u', $text)) {
        reply(422, ['error' => $error]);
    }

    return $text;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';
$method = match ($action) {
    'poll' => 'GET',
    'join', 'send', 'leave' => 'POST',
    default => null,
};
if ($method === null) {
    reply(404, ['error' => 'Запрос не найден.']);
}
if ($_SERVER['REQUEST_METHOD'] !== $method) {
    header('Allow: ' . $method);
    reply(405, ['error' => 'Метод запроса не поддерживается.']);
}

$input = $method === 'POST' ? readPayload() : $_GET;
$tab = $input['tab'] ?? '';
$providedToken = $method === 'POST' ? ($input['token'] ?? '') : ($_SERVER['HTTP_X_CHAT_TOKEN'] ?? '');
openChatSession();
$token = $_SESSION['token'] ?? '';
session_write_close();

if ($token === '' || !is_string($providedToken) || !hash_equals($token, $providedToken)) {
    reply(403, ['error' => 'Сессия истекла. Обновите страницу.']);
}
if (!is_string($tab) || !preg_match('/\A[0-9a-f]{32}\z/', $tab)) {
    reply(400, ['error' => 'Некорректный идентификатор вкладки.']);
}

$after = $input['after'] ?? '';
if ($action === 'poll' && (!is_string($after) || ($after !== '' && !preg_match('/\A[0-9]{1,16}-[0-9]{1,18}\z/', $after)))) {
    reply(400, ['error' => 'Некорректный ID сообщения.']);
}

$value = match ($action) {
    'join' => chatText($input['name'] ?? null, 40, 'Введите имя длиной от 1 до 40 символов.'),
    'send' => chatText($input['text'] ?? null, 1000, 'Введите сообщение длиной от 1 до 1000 символов.'),
    default => '',
};

try {
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST') ?: 'redis', 6379, 2.0, read_timeout: 8.0);
    $participant = hash_hmac('sha256', $tab, $token);
    $result = $redis->eval(file_get_contents(__DIR__ . '/../app/room.lua'), [
        MESSAGES,
        'chat:names',
        'chat:online',
        'chat:send-limit:' . hash('sha256', $token),
        $action,
        $participant,
        $value,
    ], 4);

    switch ($result[0] ?? null) {
        case 410:
            reply(410, ['error' => 'Время присутствия истекло.']);
        case 429:
            reply(429, ['error' => 'Сообщения отправляются слишком часто.']);
        case 200:
            break;
        default:
            throw new RedisException($redis->getLastError() ?: 'Room command failed');
    }

    if ($action !== 'poll') {
        reply(200, ['ok' => true]);
    }

    if ($after === '') {
        $entries = $redis->xRange(MESSAGES, $result[1], '+', BATCH_SIZE);
    } else {
        $streams = $redis->xRead([MESSAGES => $after], BATCH_SIZE, 5000);
        $entries = $streams[MESSAGES] ?? [];
    }

    $messages = [];
    foreach ($entries ?: [] as $id => $entry) {
        $messages[] = ['id' => $id] + $entry;
    }
    $users = $redis->hVals('chat:names');
    sort($users, SORT_STRING);
    reply(200, ['messages' => $messages, 'users' => $users]);
} catch (RedisException $error) {
    error_log($error->getMessage());
    reply(503, ['error' => 'Чат временно недоступен.']);
}
