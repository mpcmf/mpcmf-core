<?php
// HTTP fixture for reactCurl tests, served by `php -S`.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/slow':
        usleep((int)(((float)($_GET['s'] ?? 1)) * 1e6));
        echo 'slow';
        break;
    case '/big':
        echo str_repeat('x', 1024 * (int)($_GET['kb'] ?? 100));
        break;
    case '/code':
        http_response_code((int)($_GET['c'] ?? 500));
        echo 'body-of-' . ($_GET['c'] ?? 500);
        break;
    case '/loop':
        header('Location: /loop', true, 302);
        break;
    case '/redir':
        header('Location: /ok?from=redir', true, 302);
        break;
    case '/redir-echo':
        header('Location: /echo', true, (int)($_GET['c'] ?? 302));
        break;
    case '/setcookie':
        setcookie((string)($_GET['n'] ?? 'sid'), (string)($_GET['v'] ?? 'value'), ['path' => '/']);
        echo 'set';
        break;
    case '/ts':
        // arrival time of the request, for throttling tests
        echo sprintf('%.6f', microtime(true));
        if (isset($_GET['s'])) {
            usleep((int)((float)$_GET['s'] * 1e6));
        }
        break;
    case '/echo':
        header('Content-Type: application/json');
        header('X-Fixture: echo');
        echo json_encode([
            'm' => $_SERVER['REQUEST_METHOD'],
            'q' => $_GET,
            'p' => file_get_contents('php://input'),
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'proto' => $_SERVER['SERVER_PROTOCOL'],
            'cookie' => $_SERVER['HTTP_COOKIE'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'ctype' => $_SERVER['CONTENT_TYPE'] ?? '',
        ]);
        break;
    default:
        echo 'ok';
}
