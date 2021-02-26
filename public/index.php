<?php

namespace Index;

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$users = ['mike', 'mishel', 'adel', 'keks', 'kamila'];


$app->get('/', function ($request, $response) {
    return $response->write('Main Page');
});

$app->get('/users', function ($request, $response, $args) use ($users) {
    $searchRequest = $request->getQueryParam('term');
    if ($searchRequest != null) {
        $result = [];
        foreach ($users as $user) {
            strpos($user, $searchRequest) !== false ? $result[] = $user : null;
        }
        $params = ['users' => $users, 'searchRequest' => $searchRequest, 'result' => $result];
        return $this->get('renderer')->render($response, "users/index.phtml", $params);
    } else {
        $params = ['users' => $users, 'result' => null];
        return $this->get('renderer')->render($response, "users/index.phtml", $params);
    }
});

$app->run();
