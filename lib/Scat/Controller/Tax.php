<?php
namespace Scat\Controller;

use \Psr\Container\ContainerInterface;
use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;
use \Respect\Validation\Validator as v;

class Tax {
  private $tax;

  public function __construct(\Scat\Service\Tax $tax) {
    $this->tax= $tax;
  }

  function ping(Request $request, Response $response) {
    return $response->withJson($this->tax->ping());
  }

  function getTICs(Request $request, Response $response) {
    return $response->withJson($this->tax->getTICs());
  }

  function test(Request $request, Response $response) {
    $data= [
      'customerID' => 0,
      'cartID' => 'testing-things-here',
      'deliveredBySeller' => false,
      'origin' => [
        'Address1' => '645 S Los Angeles St',
        'Address2' => '',
        'City' => 'Los Angeles',
        'State' => 'CA',
        'Zip5' => '90014',
        'Zip4' => '',
      ],
      'destination' => [
        'Address1' => '645 S Los Angeles St',
        'Address2' => '',
        'City' => 'Los Angeles',
        'State' => 'CA',
        'Zip5' => '90014',
        'Zip4' => '',
      ],
      'cartItems' => [
        [
          'Index' => 0,
          'ItemID' => 'AA5502',
          'TIC' => '00000',
          'Price' => '3.00',
          'Qty' => 2,
        ],
      ]
    ];

    return $response->withJson($this->tax->lookup($data));
  }

  function captureMissing(Request $request, Response $response,
                          \Scat\Service\Txn $txn)
  {
    $missed= $txn->find('customer', 0, 100, 'tax_captured:0')->find_many();

    $errors= [];

    foreach ($missed as $sale) {
      if ($sale->paid) {
        try {
          $sale->captureTax($this->tax);
        } catch (\Exception $e) {
          $message= $e->getMessage();
          if (preg_match('/^This transaction has already been captured/', $message)) {
            $sale->set_expr('tax_captured', 'NOW()');
            $sale->save();
          }
          if (preg_match('/^This transaction has already been marked as authorized/', $message)) {
            $sale->set_expr('tax_captured', 'NOW()');
            $sale->save();
          }
          if (preg_match('/^A matching lookup could not be found for this authorization/', $message)) {
            try {
              $sale->captureTax($this->tax, true);
              $sale->set_expr('tax_captured', 'NOW()');
              $sale->save();
              $message= "Couldn't find existing lookup, forced a new one and captured";
            } catch (\Exception $e) {
              $message= $e->getMessage();
            }
          }
          $errors[]= $sale->id . ": " . $message;
        }
      }
    }

    return $errors ? $response->withJson($errors) : $response;
  }
}
