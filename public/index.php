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

// Главная страница
$app->get('/', function ($request, $response) use ($router) {
    $response->write('<H>Welcome to AliParser</H><br><br>');
    // объявляем именованные маршруты
    //$router->urlFor('products'); // /products
    //$router->urlFor('addProduct'); // /products/new
    $links = '<a href="/products">All products</a> <br> <a href="/products/new">Add new product</a>';
    return $response->write($links);
});

// Выводим список всех товаров
$app->get('/products', function ($request, $response) use ($productsData) {
    $searchRequest = $request->getQueryParam('term');
    // если поисковой запрос содержит значение (не нулл)
    if ($searchRequest != null) {
        $result = array_filter($productsData, function ($product) use ($searchRequest) {
            if (is_int(strripos($product['title'], $searchRequest))) {
                return [
                    'id' => $product['id'],
                    'title' => $product['title'],
                    'description' => $product['description']];
            }
        });
        $params = ['products' => $result, 'searchRequest' => $searchRequest];
        return $this->get('renderer')->render($response, "products/index.phtml", $params);
    } else {
        // если поисковой запрос НЕ содержит значения, то передаем ВСЕ данные для полного отображения
        $params = ['products' => $productsData];
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
        $params = [
            'product' => [
                'id' => $findedProduct['id'],
                'title' => $findedProduct['title'],
                'description' => $findedProduct['description']
            ],
        ];
        return $this->get('renderer')->render($response, "products/single.phtml", $params);
    }
    return $response->write('Woooops! Product not found!<br><a href="/products">All product</a>');


})->setName('singleProduct');





// форма добавления нового товара
$app->get('/products/new', function ($request, $response) {
    $params = [
        'product' => ['id' => '', 'title' => '', 'description' => ''],
        'errors' => [],
    ];
    return $this->get('renderer')->render($response, "products/new.phtml", $params);
})->setName('addProduct');

// Отправляем запрос на ДОБАВЛЕНИЕ товара
$app->post('/products', function ($request, $response) {
    $data = $request->getParsedBody('product')['product'];
    $id = makeUniqueId();
    $title = $data['title'];
    $description = $data['description'];
    $data = ['id' => $id, 'title' => $title, 'description' => $description];
    $params = [
        'product' => ['id' => '', 'title' => '', 'description' => ''],
        'errors' => [],
    ];
    file_put_contents('base.txt', json_encode($data) . "\n", FILE_APPEND);
    return $this->get('renderer')->render($response, "products/new.phtml", $params);
});

$app->run();
