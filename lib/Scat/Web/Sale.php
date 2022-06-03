<?php
namespace Scat\Web;

use \Psr\Container\ContainerInterface;
use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;
use \Respect\Validation\Validator as v;

class Sale {
  public function __construct(
    private \Scat\Service\Cart $carts,
    private \Scat\Service\Catalog $catalog,
    private \Scat\Service\Auth $auth,
    private View $view
  ) {
  }

  /* XXX Hardwired for the info the Scat side needs to pull orders. */
  public function listSales(Request $request, Response $response) {
    $person= $this->auth->get_person_details($request);
    $key= $request->getParam('key');

    error_log(json_encode($person));

    if (!$this->auth->verify_access_key($key) &&
        (!$person || $person->role != 'employee'))
    {
      throw new \Slim\Exception\HttpForbiddenException($request, "Wrong key");
    }

    $status= $request->getParam('carts') ? 'cart' : 'paid';

    $sales= $this->carts->findByStatus(
      status: $status,
      yesterday: $status == 'cart',
      limit: $key ? null : 100,
    );

    $accept= $request->getHeaderLine('Accept');
    if ($request->getParam('json') ||
        strpos($accept, 'application/json') !== false)
    {
      return $response->withJson($sales);
    }

    return $this->view->render($response, 'sale/list.html', [
      'sales' => $sales,
    ]);
  }

  public function sale(Request $request, Response $response, $uuid) {
    $sale= $this->carts->findByUuid($uuid);
    if (!$sale) {
      throw new \Slim\Exception\HttpNotFoundException($request);
    }

    $accept= $request->getHeaderLine('Accept');
    if ($request->getParam('json') ||
        strpos($accept, 'application/json') !== false)
    {
      return $response->withJson([
        'sale' => $sale,
        'shipping_address' => $sale->shipping_address(),
        'items' => $sale->items()->find_many(),
        'payments' => $sale->payments()->find_many(),
        'notes' => $sale->notes()->find_many(),
      ]);
    }

    // TODO redirect to correct URL depending on $sale->status
  }

  public function saleJson(Request $request, Response $response, $uuid) {
    $sale= $this->carts->findByUuid($uuid);
    if (!$sale) {
      throw new \Slim\Exception\HttpNotFoundException($request);
    }

    return $response->withJson([
      'sale' => $sale,
      'shipping_address' => $sale->shipping_address(),
      'items' => $sale->items()->find_many(),
      'payments' => $sale->payments()->find_many(),
      'notes' => $sale->notes()->find_many(),
    ]);
  }

  public function thanks(Request $request, Response $response, $uuid) {
    $sale= $this->carts->findByUuid($uuid);
    if (!$sale) {
      throw new \Slim\Exception\HttpNotFoundException($request);
    }

    if ($sale->status != 'paid') {
      if ($sale->status == 'sale') {
        // redirect to sale
      }
      if ($sale->status == '') {
        // other stuff
      }
    }

    return $this->view->render($response, 'sale/thanks.html', [
      'sale' => $sale,
    ]);
  }

  public function setStatus(Request $request, Response $response, $uuid) {
    $sale= $this->carts->findByUuid($uuid);
    if (!$sale) {
      throw new \Slim\Exception\HttpNotFoundException($request);
    }

    $status= $request->getParam('status');

    if (!in_array($status, array('new','cart','review','unpaid','paid',
                                 'processing','shipped','cancelled','onhold')))
    {
      // XXX better error handling
      throw new \Exception("Didn't understand requested status {$status}.");
    }

    $sale->status= $status;
    $sale->save();

    return $response->withJson($sale);
  }

  public function setAbandonedLevel(
    Request $request,
    Response $response,
    $uuid
  ) {
    $sale= $this->carts->findByUuid($uuid);
    if (!$sale) {
      throw new \Slim\Exception\HttpNotFoundException($request);
    }

    $abandoned_level= (int)$request->getParam('abandoned_level');

    $sale->abandoned_level= $abandoned_level;
    $sale->save();

    return $response->withJson($sale);
  }
}
