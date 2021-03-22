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
    $uniqueId = substr(implode($chars), 0, $len);
    return $uniqueId;
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



$users = [
    ['name' => 'admin', 'passwordDigest' => hash('sha256', 'secret')],
    ['name' => 'mike', 'passwordDigest' => hash('sha256', 'superpass')],
    ['name' => 'kate', 'passwordDigest' => hash('sha256', 'strongpass')]
];

// Главная страница
$app->get('/', function ($request, $response) use ($router) {
    // проверяем на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
        'flash' => $messages,
        'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
        $response->write('<H>Welcome to AliParser</H><br><br>');
        // объявляем именованные маршруты
        $products = $router->urlFor('products'); // /products
        $newProduct = $router->urlFor('productNew'); // /products/new
        $account = $router->urlFor('account');
        $links = "<a href='{$products}'>All products</a> <br> <a href='{$newProduct}'>Add new product</a><br>
            <a href='{$account}'>Account</a>";
        return $response->write($links);
    }
});


$app->get('/account', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    $params = [
        'flash' => $messages,
        'currentUser' => $_SESSION['user'] ?? null
    ];
    return $this->get('renderer')->render($response, "account.phtml", $params);
})->setName('account');

// Выводим список всех товаров
$app->get('/products', function ($request, $response) {
    // проверяем на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
        // читаем карту товаров из куков
        $cart = json_decode($request->getCookieParam('cart', json_encode([])), true);
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
            return $response->write("Wooops, not found! <br> <a href='/products?page={$page}'>Back</a>
            ")->withStatus(404);
        }
        $searchRequest = $request->getQueryParam('term');
        // если поисковой запрос содержит значение (не нулл)
        if ($searchRequest != null) {
            $result = array_filter($productsData, function ($product) use ($searchRequest, $page, $messages, $cart) {
                if (is_int(strripos($product['title'], $searchRequest))) {
                    return [
                        'id' => $product['id'],
                        'title' => $product['title'],
                        'description' => $product['description'],
                        'page' => $page,
                        'cart' => $cart
                    ];
                }
            });
            $params = [
                'products' => $result,
                'searchRequest' => $searchRequest,
                'flash' => $messages, 'page' => $page,
                'cart' => $cart
            ];
            return $this->get('renderer')->render($response, "products/index.phtml", $params);
        } else {
            // если поисковой запрос НЕ содержит значения, то передаем ВСЕ данные для полного отображения
            $params = ['products' => $slicedPosts,
                'searchRequest' => $searchRequest,
                'page' => $page,
                'flash' => $messages,
                'cart' => $cart
            ];
            return $this->get('renderer')->render($response, "products/index.phtml", $params);
        }
    }
})->setName('products');


// выводим только один продукт по ID по динамическому маршруту
$app->get('/product/{id}', function ($request, $response, $args) use ($router) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
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
    }
})->setName('singleProduct');


// форма добавления нового товара
$app->get('/products/new', function ($request, $response) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
        $params = [
            'product' => ['id' => '', 'title' => '', 'description' => ''],
            'errors' => [],
        ];
        return $this->get('renderer')->render($response, "products/new.phtml", $params);
    }
})->setName('productNew');


// Отправляем запрос на ДОБАВЛЕНИЕ товара
$app->post('/products', function ($request, $response) use ($router) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
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
    }
});


// Форма обновления товара
$app->get('/product/{id}/edit', function ($req, $response, $args) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
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
    }
})->setName('editProduct');


// Обработчик ОБНОВЛЕНИЯ ТОВАРА
$app->patch('/product/{id}', function ($request, $response, $args) use ($router) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
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
        $params = ['product' => ['id' => $searchId, 'title' => $data['title'], 'description' => $data['description']],
            'errors' => validate($data)
        ];
        $response = $response->withStatus(422); // статус страницы если были ошибки при вводе
        return $this->get('renderer')->render($response, 'products/edit.phtml', $params);
    }
});


// Форма удаления товара
$app->get('/product/{id}/delete', function ($req, $response, $args) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
        $productsData = getProductsData();
        $searchId = $args['id'];
        $findedProduct = array_reduce($productsData, function ($acc, $product) use ($searchId) {
            return $searchId === $product['id'] ? $product : $acc;
        }, []);
        $params = [
            'product' => $findedProduct,
        ];
        return $this->get('renderer')->render($res, 'products/delete.phtml', $params);
    }
})->setName('deleteProduct');


// DELETE запрос на удаление
$app->delete('/product/{id}', function ($request, $response, $args) use ($router) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
        $searchId = $args['id'];
        foreach (getProductsData() as $product) {
            if ($product['id'] !== $searchId) { // если ид НЕ совпал то записываем продукты все
                $updatedListOfProducts[] = json_encode($product); // ложим обычный продукт в новый список
            }
        }
        // в противном случае ИД совпадет, и тогда он просто не попадет в список
        $this->get('flash')->addMessage('success', 'Success! Product has been deleted');
        $updatedListOfProducts = implode("\n", $updatedListOfProducts);
        unlink('base.txt'); // удаляем старый файл
        // записываем обновленный файл - плохая реализация так как при многопоточном режиме могут быть проблемы!
        file_put_contents('base.txt', $updatedListOfProducts . "\n", FILE_APPEND);
        return $response->withRedirect('/products');
    }
});


// обработчик добавления товаров в корзину через куки
$app->post('/cart-items', function ($request, $response) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
        $item = $request->getParsedBodyParam('item'); // достали товар из запроса на добавление
        $page = $item['page']; // получили страницу с которой добавили товар
        $cart = json_decode($request->getCookieParam('cart', json_encode([])), true);
        $id = $item['id'];
        if (!isset($cart[$id])) {
            $cart[$id] = ['title' => $item['title'], 'count' => 1];
        } else {
            $cart[$id]['count'] += 1;
        }
        $encodedCart = json_encode($cart);
        return $response->withHeader('Set-Cookie', "cart={$encodedCart}")->withRedirect("/products?page={$page}");
    }
});


// обработчик ОЧИСТКИ корзины через куки
$app->delete('/cart-items', function ($request, $response) {
    // проверочка на залогиненность
    if (!isset($_SESSION['user'])) {
        $messages = $this->get('flash')->getMessages();
        $params = [
            'flash' => $messages,
            'currentUser' => $_SESSION['user'] ?? null
        ];
        return $this->get('renderer')->render($response, 'account.phtml', $params);
    } else {
        $item = $request->getParsedBodyParam('item'); // инфа о всех товарах
        $page = $item['page']; // берем страницу с которой добавили товар, для будущего редиректа
        $encodedCart = json_encode([]); // для очистки передаем пустую карту
        return $response->withHeader('Set-Cookie', "cart={$encodedCart}")->withRedirect("/products?page={$page}");
    }
});


// обработчик ВХОДА
$app->post('/session', function ($request, $response, $args) use ($users) {
    $currentUser = $request->getParsedBodyParam('user');
    foreach ($users as $userFromBase) {
        // если текущие данные совпадают с теми что в базе
        if (
            hash('sha256', $currentUser['password']) === $userFromBase['passwordDigest'] &&
            $currentUser['name'] === $userFromBase['name']
        ) {
            $_SESSION['user'] = ['name' => $currentUser['name'], 'password' => $currentUser['password']];
            return $response->withRedirect('/');
        } else {
            // если не совпадают
            $this->get('flash')->addMessage('error', 'Wrong password or name');
            return $response->withRedirect('/');
        }
    }
});


// обработчик выхода - просто чистимс сессию
$app->delete('/session', function ($request, $response) {
    $_SESSION = array();
    session_destroy();
    return $response->withRedirect('/');
});

$app->run();
