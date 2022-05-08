<?php
require '../vendor/autoload.php';

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;
use \Respect\Validation\Validator as v;
use \Slim\Routing\RouteCollectorProxy as RouteCollectorProxy;

/* Some defaults */
error_reporting(E_ALL & ~E_NOTICE);
$tz= @$_ENV['PHP_TIMEZONE'] ?: @$_ENV['TZ'];
if ($tz) date_default_timezone_set($tz);
bcscale(2);

$DEBUG= $ORM_DEBUG= false;
$config_file= @$_ENV['SCAT_CONFIG'] ?: dirname(__FILE__).'/../config.php';
if (file_exists($config_file)) {
  $config= require $config_file;
} else {
  $config= [
    'data' => [
      'dsn' => 'mysql:host=db;dbname=scat;charset=utf8mb4',
      'options' => [
        'return_result_sets' => true,
        'username' => $_ENV['MYSQL_USER'],
        'password' => $_ENV['MYSQL_PASSWORD'],
      ],
    ],
  ];
}

$builder= new \DI\ContainerBuilder();
/* Need to set up definitions for services that require manual setup */
$builder->addDefinitions([
  'Slim\Views\Twig' => \DI\get('view'),
  'Scat\Service\Data' => \DI\get('data'),
  'Scat\Service\Config' => \DI\get('config'),
]);
$container= $builder->build();

$container->set('config', new \Scat\Service\Config());

$app= \DI\Bridge\Slim\Bridge::create($container);

$app->addRoutingMiddleware();

/* Twig for templating */
$container->set('view', function($container) {
  /* No cache for now */
  $view= \Slim\Views\Twig::create(
    [ '../ui/web', '../ui/shared' ],
    [ 'cache' => false ]
  );

  /* Set timezone for date functions */
  $tz= @$_ENV['PHP_TIMEZONE'] ?: @$_ENV['TZ'];
  if ($tz) {
    $view->getEnvironment()
      ->getExtension(\Twig\Extension\CoreExtension::class)
      ->setTimezone($tz);
  }

  // Add the Markdown extension
  $engine= new \Aptoma\Twig\Extension\MarkdownEngine\MichelfMarkdownEngine();
  $view->addExtension(new \Aptoma\Twig\Extension\MarkdownExtension($engine));

  // Add the HTML extension
  $view->addExtension(new \Twig\Extra\Html\HtmlExtension());

  // Add our Twig extensions
  $view->addExtension(new \Scat\TwigExtension($container->get('config')));

  return $view;
});
$app->add(\Slim\Views\TwigMiddleware::createFromContainer($app));

/* Hook up the data service, but not lazily because we rely on side-effects */
$container->set('data', new \Scat\Service\Data($config['webdata']));

$app->add(new \Middlewares\TrailingSlash());

$errorMiddleware= $app->addErrorMiddleware($DEBUG, true, true);

$errorHandler= $errorMiddleware->getDefaultErrorHandler();
$errorHandler->registerErrorRenderer('application/json',
                                     \Scat\JsonErrorRenderer::class);

/* 404 */
$errorMiddleware->setErrorHandler(
  \Slim\Exception\HttpNotFoundException::class,
  function (Request $request, Throwable $exception,
            bool $displayErrorDetails) use ($container)
  {
    $response= new \Slim\Psr7\Response();

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') !== false) {
      $response->getBody()->write(json_encode([ 'error' => 'Not found.' ]));
      return $response
              ->withStatus(404)
              ->withHeader('Content-Type', 'application/json');
    }

    return $container->get('view')->render($response, '404.html')
      ->withStatus(404)
      ->withHeader('Content-Type', 'text/html');
  });

/* ROUTES */

/* Catalog */
$app->group('/art-supplies', function (RouteCollectorProxy $app) {
  /* web has it's own search implementation */
  $app->get('/search', [ \Scat\Web\Catalog::class, 'search' ])
      ->setName('catalog-search');
  $app->get('/whats-new', [ \Scat\Controller\Catalog::class, 'whatsNew' ])
      ->setName('catalog-whats-new');
  $app->get('/brand[/{brand}]', [ \Scat\Controller\Catalog::class, 'brand' ])
      ->setName('catalog-brand');
  $app->get('[/{dept}[/{subdept}[/{product}[/{item}]]]]',
            [ \Scat\Controller\Catalog::class, 'catalogPage' ])
      ->setName('catalog')
      ->add(function ($request, $handler) {
          return $handler->handle($request->withAttribute('no_solo_item', true));
      });
});

/* Buy a Gift Card */
// TODO

/* Cart */
$app->group('/cart', function (RouteCollectorProxy $app) {
  $app->get('', [ \Scat\Web\Cart::class, 'cart' ])
      ->setName('cart');
})->add($container->get(\Scat\Middleware\Cart::class));

/* Contact */
// TODO

/* Tracking */
// TODO

/* Auth & Rewards */
$app->group('', function (RouteCollectorProxy $app) {
  $app->get('/account', [ \Scat\Web\Auth::class, 'account' ])
      ->setName('account');
  $app->get('/login/key/{key:.*}', [ \Scat\Web\Auth::class, 'handleLoginKey' ])
      ->setName('handleLoginKey');
  $app->get('/login', [ \Scat\Web\Auth::class, 'loginForm' ])
      ->setName('login');
  $app->post('/login', [ \Scat\Web\Auth::class, 'handleLogin' ])
      ->setName('handleLogin');
  $app->get('/logout', [ \Scat\Web\Auth::class, 'logout' ])
      ->setName('logout');
});

/* Webhooks */

/* Info (DEBUG only) */
if ($DEBUG) {
  $app->get('/info',
            function (Request $request, Response $response) {
              ob_start();
              phpinfo();
              $response->getBody()->write(ob_get_clean());
              return $response;
            })->setName('info');
}

/* Pages (everything else) */
$app->get('/{param:.*}', [ \Scat\Web\Page::class, 'page' ]);
$app->post('/{param:.*}', [ \Scat\Web\Page::class, 'savePage' ]);

$app->run();
