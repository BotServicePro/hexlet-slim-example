<?php

namespace Index;

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

session_start();

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

// подключаем флэш
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

// Получаем роутер – объект отвечающий за хранение и обработку маршрутов
$router = $app->getRouteCollector()->getRouteParser();

// функция подготовки данных из товаров
function getProductsData(): array
{
    $data = file_get_contents('base.txt');
    $productsData = explode("\n", $data);
    array_pop($productsData); // удалили пустое последнее значение
    // приводим все данные к массивам для удобного чтения
    $readyData = array_map(function ($user) {
        $decodedProductData = json_decode($user, true);
        return $decodedProductData;
    }, $productsData);
    return $readyData;
}
$productsData = getProductsData();

// функция создания уникального ID
function makeUniqueId($len = 5): string
{
    $chars = array_merge(range('a', 'z'), range('1', '9'));
    shuffle($chars);
    $randChars = substr(implode($chars), 0, $len);
    return $randChars;
}

// функци валидатор данных
function validate($data): array
{
    $errors = [];
    if ($data['title'] === '') {
        $errors['title'] = "Title can't be blank!";
    }
    if ($data['description'] === '') {
        $errors['description'] = "Description can't be blank!";
    }
    return $errors;
}

// Главная страница
$app->get('/', function ($request, $response) use ($router) {
    $response->write('<H>Welcome to AliParser</H><br><br>');
    // объявляем именованные маршруты
    $products = $router->urlFor('products'); // /products
    $productNew = $router->urlFor('productNew'); // /products/new
    $links = "<a href='{$products}'>All products</a> <br> <a href='{$productNew}'>Add new product</a>";
    return $response->write($links);
});

// Выводим список всех товаров
$app->get('/products', function ($request, $response) use ($productsData) {
    $per = 5;
    $page = $request->getQueryParam('page', 1);
    $slicedPosts = array_slice($productsData, ($page - 1) * $per, $per);
    $messages = $this->get('flash')->getMessages(); // читаем флэш сообщение которое образовалось в POST запросе
    // если есть сообщения для вывода то выводим
    //print_r($messages);
    if (!empty($messages)) {
        $message = $messages['success'][0];
        print_r("<H2>{$message}</H2>");
    }
    // если продуктов на странице нет
    if (count($slicedPosts) === 0) {
        --$page;
        return $response->write("Wooops, not found! <br> <a href='/products?page={$page}'>Back</a>")->withStatus(404);
    }
    $searchRequest = $request->getQueryParam('term');
    // если поисковой запрос содержит значение (не нулл)
    if ($searchRequest != null) {
        $result = array_filter($productsData, function ($product) use ($searchRequest, $page, $messages) {
            if (is_int(strripos($product['title'], $searchRequest))) {
                return [
                    'id' => $product['id'],
                    'title' => $product['title'],
                    'description' => $product['description'],
                    'page' => $page
                ];
            }
        });
        $params = ['products' => $result, 'searchRequest' => $searchRequest, 'flash' => $messages];
        return $this->get('renderer')->render($response, "products/index.phtml", $params);
    } else {
        // если поисковой запрос НЕ содержит значения, то передаем ВСЕ данные для полного отображения
        $params = ['products' => $slicedPosts,
            'searchRequest' => $searchRequest,
            'page' => $page,
            'flash' => $messages
        ];
        return $this->get('renderer')->render($response, "products/index.phtml", $params);
    }
})->setName('products');

// выводим только один продукт по ID по динамическому маршруту
$app->get('/product/{id}', function ($request, $response, $args) use ($productsData) {
    $searchId = $args['id'];
    $findedProduct = array_reduce($productsData, function ($acc, $product) use ($searchId) {
        return $searchId === $product['id'] ? $product : $acc;
    }, []);
    if (count($findedProduct) > 0) {
        $params = ['product' => [
            'id' => $findedProduct['id'],
            'title' => $findedProduct['title'],
            'description' => $findedProduct['description']
            ],
        ];
        return $this->get('renderer')->render($response, "products/single.phtml", $params);
    }
    return $response->write('Woooops! Product not found!<br><a href="/products">All product</a>')->withStatus(404);
})->setName('singleProduct');

// форма добавления нового товара
$app->get('/products/new', function ($request, $response) {
    $params = [
        'product' => ['id' => '', 'title' => '', 'description' => ''],
        'errors' => [],
    ];
    return $this->get('renderer')->render($response, "products/new.phtml", $params);
})->setName('productNew');

// Отправляем запрос на ДОБАВЛЕНИЕ товара
$app->post('/products', function ($request, $response) use ($router) {
    //$data = $request->getParsedBody('product')['product'];
    $data = $request->getParsedBodyParam('product');
    if (empty(validate($data))) { // если ошибок нет
        $data = ['id' => makeUniqueId(), 'title' => $data['title'], 'description' => $data['description']];
        $this->get('flash')->addMessage('success', 'Success! Product has been created!');
        file_put_contents('base.txt', json_encode($data) . "\n", FILE_APPEND);
        $url = $router->urlFor('products');
        return $response->withRedirect($url);
    } elseif (!empty(validate($data))) { // если есть ошибки
        $params = [
            'product' => $data,
            'errors' => validate($data)
        ];
        $response = $response->withStatus(422); // статус страницы если были ошибки при вводе
        return $this->get('renderer')->render($response, 'products/new.phtml', $params);
    }
});
$app->run();
