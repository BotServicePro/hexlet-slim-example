<?php

namespace Index;

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Flash\Messages;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Views\PhpRenderer;

session_start();

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new PhpRenderer(__DIR__ . '/../templates');
});

// подключаем флэш
$container->set('flash', fn() => new Messages());

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

// Включаем поддержку переопределения метода в самом Slim
$app->add(MethodOverrideMiddleware::class);

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
$app->get('/products', function ($request, $response) {
    $productsData = getProductsData();
    $per = 5;
    $page = $request->getQueryParam('page', 1);
    $slicedPosts = array_slice($productsData, ($page - 1) * $per, $per);
    $messages = $this->get('flash')->getMessages(); // читаем флэш сообщение которое образовалось в POST запросе
    // если есть сообщения для вывода то выводим
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
$app->get('/product/{id}', function ($request, $response, $args) use ($router) {
    $productsData = getProductsData();
    $searchId = $args['id'];
    $messages = $this->get('flash')->getMessages(); // читаем флэш сообщение которое образовалось в PATCH запросе
    // если есть сообщения для вывода то выводим
    if (!empty($messages)) {
        $message = $messages['success'][0];
        print_r("<H2>{$message}</H2>");
    }
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
    $url = $router->urlFor('products');
    return $response->write("Woooops! Product not found!<br><a href='{$url}'>All products</a>")->withStatus(404);
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
    $data = $request->getParsedBodyParam('product');
    if (empty(validate($data))) { // если ошибок нет
        $data = ['id' => makeUniqueId(), 'title' => $data['title'], 'description' => $data['description']];
        $this->get('flash')->addMessage('success', 'Success! Product has been created!');
        file_put_contents('base.txt', json_encode($data) . "\n", FILE_APPEND);
        return $response->withRedirect($router->urlFor('products'));
    } // если есть ошибки
        $params = [
            'product' => $data,
            'errors' => validate($data)
        ];
        $response = $response->withStatus(422); // статус страницы если были ошибки при вводе
        return $this->get('renderer')->render($response, 'products/new.phtml', $params);
});

// Форма обновления товара
$app->get('/product/{id}/edit', function ($req, $res, $args) {
    $productsData = getProductsData();
    $searchId = $args['id'];
    $findedProduct = array_reduce($productsData, function ($acc, $product) use ($searchId) {
        return $searchId === $product['id'] ? $product : $acc;
    }, []);
    $params = [
        'product' => $findedProduct,
        'errors' => []
    ];
    return $this->get('renderer')->render($res, 'products/edit.phtml', $params);
})->setName('editProduct');

// Обработчик ОБНОВЛЕНИЯ ТОВАРА
$app->patch('/product/{id}', function ($request, $response, $args) use ($router) {
    $searchId = $args['id']; // искомый ИД для изменения
    $data = $request->getParsedBodyParam('product'); // новые данные для изменения
    $urlToSingeProduct = $router->urlFor('singleProduct', ['id' => $searchId]);
    if (empty(validate($data))) { // если ошибок в новых данных нет
        foreach (getProductsData() as $product) {
            if ($product['id'] !== $searchId) {
                $updatedListOfProducts[] = json_encode($product); // ложим обычный продукт в новый список
            } elseif ($product['id'] === $searchId) { // если нашли именно тот ИД который хотели изменить
                $updatedListOfProducts[] = json_encode(['id' => $searchId,
                    'title' => $data['title'],
                    'description' => $data['description']
                ]);
            }
        }
        $this->get('flash')->addMessage('success', 'Success! Product has been updated!');
        $updatedListOfProducts = implode("\n", $updatedListOfProducts);
        // удаляем старый файл
        unlink('base.txt');
        // записываем обновленный файл - плохая реализация так как при многопоточном режиме могут быть проблемы!
        file_put_contents('base.txt', $updatedListOfProducts . "\n", FILE_APPEND);
        return $response->withRedirect($urlToSingeProduct);
    } // если есть ошибки
        $params = ['product'  => ['id' => $searchId, 'title' => $data['title'], 'description' => $data['description']],
            'errors' => validate($data)
        ];
        $response = $response->withStatus(422); // статус страницы если были ошибки при вводе
        return $this->get('renderer')->render($response, 'products/edit.phtml', $params);
});






// Форма удаления товара
$app->get('/product/{id}/delete', function ($req, $res, $args) {
    $id = $args['id'];
    $repo->destroy($id);
    $this->get('flash')->addMessage('success', 'School has been deleted');
    return $response->withRedirect($router->urlFor('schools'));




})->setName('deleteProduct');

// DELETE запрос на удаление
$app->delete('/product/{id}', function ($request, $response, $args) use ($router) {
    $searchId = $args['id']; // искомый ИД для изменения

});

$app->run();
