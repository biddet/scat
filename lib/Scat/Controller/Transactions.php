<?php
namespace Scat\Controller;

use \Psr\Container\ContainerInterface;
use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;
use \Respect\Validation\Validator as v;

class Transactions {
  private $view, $txn, $data, $catalog, $pole, $tax;

  public function __construct(View $view, \Scat\Service\Txn $txn,
                              \Scat\Service\Config $config,
                              \Scat\Service\Data $data,
                              \Scat\Service\Catalog $catalog,
                              \Scat\Service\PoleDisplay $pole,
                              \Scat\Service\Tax $tax)
  {
    $this->view= $view;
    $this->txn= $txn;
    $this->config= $config;
    $this->data= $data;
    $this->catalog= $catalog;
    $this->pole= $pole;
    $this->tax= $tax;
  }

  public function search(Request $request, Response $response, $type) {
    $q= trim($request->getParam('q'));

    if (preg_match('/^((%V|@)INV-)(\d+)/i', $q, $m)) {
      $txn= $this->txn->fetchById($m[3]);
      if ($txn) {
        return $response->withRedirect(
          ($txn->type == 'customer' ? '/sale/' : '/purchase/') . $txn->id
        );
      }
    }

    if (preg_match('/^\d\d\d\d-(\d+)/i', $q, $m)) {
      $txn= $this->txn->fetchByNumber($m[1]);
      if ($txn) {
        return $response->withRedirect(
          ($txn->type == 'customer' ? '/sale/' : '/purchase/') . $txn->id
        );
      }
    }

    $page= (int)$request->getParam('page');
    $limit= (int)$request->getParam('limit') ?: 25;

    $txns= $this->txn->find($type, $page, $limit, $q);
    if (($status= $request->getParam('status'))) {
      $txns= $txns->where('status', $status);
    }

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') !== false) {
      return $response->withJson($txns->find_many());
    }

    return $this->view->render($response, 'txn/index.html', [
      'type' => $type,
      'txns' => $txns->find_many(),
      'page' => $page,
      'limit' => $limit,
      'status' => $status,
      'q' => $q,
    ]);
  }
  public function sales(Request $request, Response $response) {
    return $this->search($request, $response, 'customer');
  }

  public function sale(Request $request, Response $response, $id) {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if ($txn->type == 'vendor') {
      return $response->withRedirect('/purchase/' . $txn->id);
    }
    if ($txn->type == 'correction') {
      return $response->withRedirect('/correction/' . $txn->id);
    }

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') !== false) {
      return $response->withJson($txn);
    }

    if (($block= $request->getParam('block'))) {
      $html= $this->view->fetchBlock('sale/txn.html', $block, [
        'txn' => $txn,
      ]);

      $response->getBody()->write($html);
      return $response;
    }

    return $this->view->render($response, 'sale/txn.html', [
      'txn' => $txn,
    ]);
  }

  public function saleByUuid(Request $request, Response $response, $uuid) {
    $txn= $this->txn->fetchByUuid($uuid);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    return $response->withJson($txn);
  }

  public function createSale(Request $request, Response $response)
  {
    $this->data->beginTransaction();

    $copy_from_id= $request->getParam('copy_from_id');
    $copy= $copy_from_id ? $this->txn->fetchById($copy_from_id) : null;

    $sale= $this->txn->create('customer', [
      'tax_rate' => $this->tax->default_rate,
    ]);

    if ($copy) {
      // Just copy a limited number of fields
      foreach ([
        'person_id', 'shipping_address_id', 'tax_rate',
        'returned_from_id', 'no_rewards'
      ] as $field) {
        $sale->set($field, $copy->$field);
      }
    }

    $sale->save();

    if ($copy) {
      foreach ($copy->items()->find_many() as $line) {
        $new= $sale->items()->create();
        $data= $line->as_array();
        unset($data['id']); // don't copy id!
        unset($data['sale_price']); // or sale_price
        $new->set($data);
        $new->txn_id= $sale->id;
        $new->save();
      }
      /* We don't copy notes. */
    }

    $with_item_id= $request->getParam('item_id');
    if ($with_item_id) {
      $this->doAddItem($request, $sale, $with_item_id);
    }

    $this->data->commit();

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') !== false) {
      $response= $response->withStatus(201)
                          ->withHeader('Location', '/sale/' . $sale->id);
      return $response->withJson($sale);
    }

    return $response->withRedirect('/sale/' . $sale->id);
  }

  public function createReturn(Request $request, Response $response, $id)
  {
    $orig= $this->txn->fetchById($id);
    if (!$orig)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $this->data->beginTransaction();

    $sale= $this->txn->create('customer', [
      'tax_rate' => $this->tax->default_rate,
    ]);

    // Just copy a limited number of fields
    // Not 'tax_rate' since an in-person exchange of an online pick-up order
    // needs to calculate tax on new items correctly
    foreach ([
      'person_id', 'shipping_address_id', 'no_rewards'
    ] as $field) {
      $sale->set($field, $orig->$field);
    }
    $sale->returned_from_id= $orig->id;

    $sale->save();

    foreach ($orig->items()->find_many() as $line) {
      // don't return returned items
      if ($line->ordered > 0) continue;

      $new= $sale->items()->create();
      $data= $line->as_array();
      unset($data['id']); // don't copy id!
      unset($data['sale_price']); // can't copy calculated field
      // flip some values
      $data['ordered']= -$data['ordered'];
      $data['allocated']= -$data['allocated'];
      $data['tax']= -$data['tax'];
      $new->set($data);
      $new->data($line->data()); // XXX fix data() magic
      $new->txn_id= $sale->id;
      $new->returned_from_id= $line->id;
      $new->save();
    }

    /* The only payments we copy were loyalty rewards. */
    foreach ($orig->payments()->find_many() as $payment) {
      if ($payment->method != 'loyalty') continue;

      $new= $sale->payments()->create();
      $data= $payment->as_array();
      unset($data['id']); // don't copy id
      // flip some values
      $data['amount']= -$data['amount'];
      $new->set($data);
      $new->data($line->data()); // XXX fix data() magic
      $new->txn_id= $sale->id;
      $new->save();
    }

    /* We don't copy notes. */

    $this->data->commit();

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') !== false) {
      $response= $response->withStatus(201)
                          ->withHeader('Location', '/sale/' . $sale->id);
      return $response->withJson($sale);
    }

    return $response->withRedirect('/sale/' . $sale->id);
  }

  public function updateSale(
    Request $request,
    Response $response,
    \Scat\Service\Ordure $ordure,
    \Scat\Service\AmazonPay $amzn,
    $id
  ) {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $changed= [];

    foreach ($txn->getFields() as $field) {
      if ($field == 'id') continue;
      $value= $request->getParam($field);
      if ($field == 'tax_rate' && $value === 'def') {
        $value= $this->tax->default_rate;
      }
      if ($field == 'person_id' && $value !== null && $txn->person_id != $value)
      {
        $txn->clearLoyalty();
      }
      if ($value !== null && $value != $txn->get($field)) {
        @$changed[$field]++;
        $txn->set($field, $value);
      }
    }

    if (isset($changed['tax_rate'])) {
      $txn->recalculateTax($this->tax);
    }
    if (isset($changed['person_id'])) {
      $txn->rewardLoyalty();
    }

    // Pass along status change to Ordure when shipping
    if (array_key_exists('status', $changed) && $txn->online_sale_id) {
      if (in_array($txn->status,
                    [ 'readyforpickup', 'shipping', 'shipped', 'complete']))
      {
        error_log("{$txn->uuid}: Capturing payments and marking shipped");
        $txn->captureAmazonPayments($amzn);
        $ordure->markOrderShipped($txn->uuid);
      }
    }

    $txn->save();

    return $response->withJson($txn);
  }

  public function deleteSale(Request $request, Response $response, $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $this->data->beginTransaction();

    // delete any discounts
    $txn->payments()->where('method', 'discount')->delete_many();

    if ($txn->payments()->count()) {
      throw new \Exception("Can't delete sale with payments.");
    }

    if (!$request->getParam('force')) {
      if ($txn->online_sales_id) {
        throw new \Exception("Can't delete online sales.");
      }

      if ($txn->items()->count()) {
        throw new \Exception("Can't delete sale with items.");
      }
    }

    // get rid of items (already bailed if we aren't forcing this)
    $txn->items()->delete_many();
    // and notes
    $txn->notes()->delete_many();

    $txn->delete();

    $this->data->commit();

    return $response->withJson($txn);
  }

  public function printSaleInvoice(Request $request, Response $response,
                                    \Scat\Service\Printer $print, $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $var= $request->getParam('variation') ?: 'invoice';

    $pdf= $txn->getInvoicePDF($var);

    if ($request->getParam('download')) {
      $response->getBody()->write($pdf->Output('', 'S'));
      return $response->withHeader('Content-type', 'application/pdf');
    }

    return $print->printPDF($response, 'letter', $pdf->Output('', 'S'));
  }

  public function printSaleReceipt(Request $request, Response $response,
                                    \Scat\Service\Printer $print, $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $var= $request->getParam('variation') ?: 'invoice';

    $pdf= $txn->getReceiptPDF($var);

    $open= $txn->used_cash() ? ':open' : '';
    if ($open) error_log("Opening cash drawer");

    return $print->printPDF($response, "receipt$open", $pdf->Output('', 'S'));
  }

  protected function doAddItem($request, $txn, $item_id) {
    $item= $this->catalog->getItemById($item_id);
    if (!$item) {
      throw new \Slim\Exception\HttpNotFoundException($request);
    }

    $unique= preg_match('/^ZZ-(frame|print|univ|canvas|stretch|float|panel|giftcard|ship)/i', $item->code);

    if (!$unique) {
      $line=
        $txn->items()
            ->where('item_id', $item->id)
            ->where_null('kit_id') /* Don't include kit items */
            ->where_null('returned_from_id') /* Don't include returned items */
            ->find_one();
    }

    if ($unique || !$line) {
      $line= $txn->items()->create();
      $line->txn_id= $txn->id;
      $line->item_id= $item->id;

      /* Get pricing for vendor items */
      if ($txn->type == 'vendor') {
        // default to full retail
        $line->retail_price= $item->retail_price;

        if ($txn->person_id) {
          $vendor_item=
            $txn->person()->items()->where('item_id', $item->id)->find_one();
          if ($vendor_item) {
            if ($vendor_item->promo_price > 0) {
              /* Sometimes promo_price > net_price */
              $line->retail_price=
                min($vendor_item->net_price, $vendor_item->promo_price);
            } else {
              $line->retail_price= $vendor_item->net_price;
            }
          }
        }

      } else {
        $line->retail_price= $item->retail_price;
        $line->discount= $item->discount;
        $line->discount_type= $item->discount_type;
      }

      $line->taxfree= $item->taxfree;
      $line->tic= $item->tic;
    }

    $quantity= $request->getParam('quantity') ?: 1;

    $line->ordered+= $quantity * ($txn->type == 'customer') ? -1 : 1;

    if ($line->is_new()) {
      error_log("Added {$line->ordered} {$item->code} to txn {$txn->id}");
    } else {
      error_log("Updated to {$line->ordered} {$item->code} on txn {$txn->id}");
    }

    $line->save();

    /* Is this a kit? Need to add kit items or adjust quantities */
    if ($item->is_kit) {
      $this->updateKitQuantities($txn, $line, $item);
    }

    // txn no longer filled?
    if ($txn->status == 'filled') {
      $txn->status= 'new';
      $txn->filled= NULL;
      $txn->save();
    }

    $txn->applyPriceOverrides($this->catalog);
    $txn->recalculateTax($this->tax);

    $this->pole->displayPrice($item->name, $item->sale_price());

    $line->reload();

    return $line;
  }

  /* Items (aka lines) */
  public function addItem(Request $request, Response $response, $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if (!$txn->allow_item_changes()) {
      throw new \Scat\Exception\HttpConflictException($request,
        "Unable to add item to transaction because it is {$txn->status}."
      );
    }

    if ($request->getUploadedFiles()) {
      return $this->handleUploadedItems($request, $response, $txn);
    }

    $item_id= $request->getParam('item_id');

    $this->data->beginTransaction();

    $line= $this->doAddItem($request, $txn, $item_id);

    $this->data->commit();

    return $response->withJson($line);
  }

  // XXX this assumes kit contents haven't changed
  public function updateKitQuantities($txn, $line, $item) {
    foreach ($item->kit_items()->find_many() as $kit_item) {
      $kit_line= null;
      if ($line->id) {
        $kit_line= $txn->items()
          ->where('item_id', $kit_item->item_id)
          ->where('kit_id', $item->id)
          ->find_one();
      }
      if (!$kit_line) {
        $kit_line= $txn->items()->create();
        $kit_line->txn_id= $txn->id;
        $kit_line->kit_id= $item->id;
        $kit_line->item_id= $kit_item->item_id;
        $kit_line->retail_price= 0.00;
        $kit_line->tax= 0.00;
      }

      $kit_line->ordered= $line->ordered * $kit_item->quantity;

      $kit_line->save();
    }
  }

  public function handleUploadedItems(Request $request, Response $response,
                                      $txn)
  {
    $update_only= false;

    foreach ($request->getUploadedFiles() as $file) {
      $fn= $file->getClientFilename();
      $stream= $file->getStream();
      $tmpfn= ($stream->getMetaData())['uri'];

      /* Grab the first line for detecting file type */
      $line= $stream->read(1024);
      $stream->close();

      $temporary= "TEMPORARY";
      // If DEBUG, we leave behind the vendor_order table
      if ($GLOBALS['DEBUG']) {
        $this->data->execute("DROP TABLE IF EXISTS vendor_order");
        $temporary= "";
      }

      error_log("Loading order data from '$fn'\n");

      $q= "CREATE $temporary TABLE vendor_order (
             line int,
             status varchar(255),
             item_no varchar(255),
             item_id int unsigned,
             sku varchar(255),
             cust_item varchar(255),
             description varchar(255),
             ordered int,
             shipped int,
             backordered int,
             msrp decimal(9,2),
             discount decimal(9,2),
             net decimal(9,2),
             unit varchar(255),
             ext decimal(9,2),
             barcode varchar(255),
             account_no varchar(255),
             po_no varchar(255),
             order_no varchar(255),
             bo_no varchar(255),
             invoice_no varchar(255),
             box_no varchar(255),
             key (item_id), key(item_no), key(sku))";

      $this->data->execute($q);

      // SLS order?
      if (preg_match('/^"?linenum"?[,\t]"?qty/', $line)) {
        error_log("Loading SLS text data\n");
        // linenum,qty_shipped,sls_sku,cust_item_numb,description,upc,msrp,net_cost,pkg_id,extended_cost
        $sep= preg_match("/,/", $line) ? "," : "\t";
        $q= "LOAD DATA LOCAL INFILE '$tmpfn'
             INTO TABLE vendor_order
             FIELDS TERMINATED BY '$sep' OPTIONALLY ENCLOSED BY '\"'
             IGNORE 1 LINES
             (line, @shipped, item_no, cust_item, description, @upc,
              msrp, net, box_no, ext)
             SET barcode = REPLACE(@upc, 'UPC->', ''),
                 sku = item_no,
                 ordered = @shipped, backordered = @shipped, shipped = 0";
        $this->data->execute($q);

      // SLS order (XLS)
      } elseif (preg_match('/K.*\\.xls/i', $fn)) {
        error_log("Loading SLS Excel data\n");
        $reader= new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        $reader->setReadDataOnly(true);

        $spreadsheet= $reader->load($tmpfn);
        $sheet= $spreadsheet->getActiveSheet();
        $i= 0; $rows= [];
        foreach ($sheet->getRowIterator() as $row) {
          if ($i++) {
            $data= [];
            $cellIterator= $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
              $data[]= $this->data->escape($cell->getValue());
            }
            $rows[]= '(' . join(',', $data) . ')';
          }
        }
        $q= "INSERT INTO vendor_order (line, ordered, item_no, cust_item, description, barcode, msrp, net, box_no, ext, bo_no) VALUES " . join(',', $rows);
        $this->data->execute($q);

        $q= "UPDATE vendor_order SET backordered = ordered, shipped = 0";
        $this->data->execute($q);

      } elseif (preg_match('/^Vendor Name	Assortment Item Number/', $line)) {
        error_log("Loading Mac assortment data\n");
        // MacPherson assortment
        $q= "LOAD DATA LOCAL INFILE '$tmpfn'
             INTO TABLE vendor_order
             FIELDS TERMINATED BY '\t'
             IGNORE 1 LINES
             (@vendor_name, @asst_item_no, item_no, @asst_description, @shipped,
              @change_flag, @change_date, sku, description, unit, msrp, net,
              @customer, @product_code_type, barcode, @reno, @elgin, @atlanta,
              @catalog_code, @purchase_unit, @purchase_qty, cust_item,
              @pending_msrp, @pending_date, @pending_net, @promo_net, @promo_name,
              @abc_flag, @vendor, @group_code, @catalog_description)
             SET ordered = @shipped, shipped = @shipped";
        $this->data->execute($q);

      } elseif (preg_match('/^"?sls_sku.*asst_qty/', $line)) {
        error_log("Loading SLS assortment data\n");
        // SLS assortment
        # sls_sku,cust_sku,description,vendor_name,msrp,reg_price,reg_discount,promo_price,promo_discount,upc1,upc2,upc2_qty,upc3,upc3_qty,min_ord_qty,level1,level2,level3,level4,level5,ltl_only,add_date,asst_qty,
        $sep= preg_match("/,/", $line) ? "," : "\t";
        $q= "LOAD DATA LOCAL INFILE '$tmpfn'
             INTO TABLE vendor_order
             FIELDS TERMINATED BY '$sep'
             OPTIONALLY ENCLOSED BY '\"'
             LINES TERMINATED BY '\n'
             IGNORE 1 LINES
             (item_no, @cust_sku, description, @vendor_name,
              msrp, net, @reg_discount, @promo_price, @promo_discount,
              barcode, @upc2, @upc2_qty, @upc3, @upc3_qty,
              @min_ord_qty, @level2, @level2, @level3, @level4, @level5,
              @ltl_only, @add_date, @asst_qty)
             SET ordered = @asst_qty, shipped = @asst_qty";
        $this->data->execute($q);

      } elseif (preg_match('/^,Name,MSRP/', $line)) {
        error_log("Loading CSV data\n");
        // CSV
        $q= "LOAD DATA LOCAL INFILE '$tmpfn'
             INTO TABLE vendor_order
             FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"'
             IGNORE 1 LINES
             (item_no, description, @msrp, @sale, @net, @qty, @ext, barcode)
             SET ordered = @qty, shipped = @qty,
                 msrp = REPLACE(@msrp, '$', ''), net = REPLACE(@net, '$', '')";
        $this->data->execute($q);

      } elseif (preg_match('/^Invoice#;/', $line)) {
        error_log("Loading ColArt data\n");
        $q= "LOAD DATA LOCAL INFILE '$tmpfn'
             INTO TABLE vendor_order
             FIELDS TERMINATED BY ';'
             LINES TERMINATED BY '\r\n'
             IGNORE 1 LINES
             (@invoice_no, @order_no, @order_line, @upc, @code, @alias,
              @country_of_origin, @customs_code, description, @qty, msrp,
              @discount_pct, net, @ext, @currency)
             SET item_no= @code, barcode= REPLACE(@upc, \"'\", ''),
                 ordered = @qty, shipped = @qty";
        $this->data->execute($q);

        $update_only= true;

      } elseif (preg_match('/^code\tqty/', $line)) {
        error_log("Loading order file\n");
        $q= "LOAD DATA LOCAL INFILE '$tmpfn'
             INTO TABLE vendor_order
             FIELDS TERMINATED BY '\t' OPTIONALLY ENCLOSED BY '\"'
             IGNORE 1 LINES
             (item_no, @qty)
             SET sku = item_no, ordered = @qty, shipped = @qty";
        $this->data->execute($q);

      } elseif (($json= json_decode(file_get_contents($tmpfn)))) {
        error_log("Loading JSON order\n");
        foreach ($json->items as $item) {
          $q= "INSERT INTO vendor_order
                  SET item_no = '" . $this->data->escape($item->code) . "',
                      description = '" . $this->data->escape($item->name) . "',
                      ordered = -" . (int)$item->quantity . ",
                      shipped = -" . (int)$item->quantity . ",
                      msrp = '" . $this->data->escape($item->retail_price) . "',
                      net = '" . $this->data->escape($item->sale_price) . "'";
          $this->data->execute($q);
        }

      } else {
        error_log("Loading Mac text data\n");
        // MacPherson's order
        $q= "LOAD DATA LOCAL INFILE '$tmpfn'
             INTO TABLE vendor_order
             CHARACTER SET 'latin1'
             FIELDS TERMINATED BY '\t' OPTIONALLY ENCLOSED BY '\"'
             IGNORE 1 LINES
             (line, status, item_no, sku, cust_item, description, ordered,
              @shipped, backordered, msrp, discount, net, unit, ext, barcode,
              account_no, po_no, order_no, bo_no, invoice_no, box_no)
             SET
                shipped = 0,
                ordered = IF(@shipped > 0, @shipped, ordered),
                backordered = IF(@shipped > 0, @shipped + backordered, backordered)
             ";
        $this->data->execute($q);

        /* Fix quantities on full backorder */
        $q= "SELECT SUM(shipped + backordered) AS ordered
               FROM vendor_order
              WHERE IFNULL(unit,'') != 'AS'";
        $ordered=
          $this->data->for_table('vendor_order')->raw_query($q)->find_one();

        if (!$ordered->ordered) {
          $this->data->execute("UPDATE vendor_order SET backordered = ordered");
        }
      }

      // Identify vendor items by SKU
      $q= "UPDATE vendor_order, vendor_item
              SET vendor_order.item_id = vendor_item.item_id
            WHERE vendor_order.sku != '' AND vendor_order.sku IS NOT NULL
              AND vendor_order.sku = vendor_item.vendor_sku
              AND vendor_id = {$txn->person_id}
              AND vendor_item.active";
      $this->data->execute($q);

      // Identify vendor items by code
      $q= "UPDATE vendor_order, vendor_item
              SET vendor_order.item_id = vendor_item.item_id
            WHERE (NOT vendor_order.item_id OR vendor_order.item_id IS NULL)
              AND vendor_order.item_no != '' AND vendor_order.item_no IS NOT NULL
              AND vendor_order.item_no = vendor_item.code
              AND vendor_id = {$txn->person_id}
              AND vendor_item.active";
      $this->data->execute($q);

      // Identify vendor items by barcode
      $q= "UPDATE vendor_order
              SET item_id = IF(barcode != '',
                            IFNULL((SELECT item.id
                                      FROM item
                                      JOIN barcode ON barcode.item_id = item.id
                                     WHERE vendor_order.barcode = barcode.code
                                     LIMIT 1),
                                   0),
                            0)
            WHERE NOT item_id OR item_id IS NULL";
      $this->data->execute($q);

      // Identify items by code
      $q= "UPDATE vendor_order, item
              SET vendor_order.item_id = item.id
            WHERE (NOT vendor_order.item_id OR vendor_order.item_id IS NULL)
              AND vendor_order.item_no != '' AND vendor_order.item_no IS NOT NULL
              AND vendor_order.item_no = item.code";
      $this->data->execute($q);

      // Identify items by barcode
      $q= "UPDATE vendor_order, barcode
              SET vendor_order.item_id = barcode.item_id
            WHERE (NOT vendor_order.item_id OR vendor_order.item_id IS NULL)
              AND vendor_order.barcode != '' AND vendor_order.barcode IS NOT NULL
              AND vendor_order.barcode = barcode.code";
      $this->data->execute($q);

      // For non-vendor orders, fail if we didn't recognize all items
      if ($txn->type != 'vendor') {
        $count= $this->data->for_table('vendor_order')
                     ->raw_query("SELECT COUNT(*) FROM vendor_order
                                   WHERE (NOT item_id OR item_id IS NULL")
                     ->find_one();
        if ($count) {
          throw new \Exception("Not all items available for order!");
        }
      }

      /* Start a transaction now that we're working with live data */
      $this->data->beginTransaction();

      if ($update_only) {
        $q= "SELECT COUNT(*) total
               FROM vendor_order
              WHERE item_id = 0 or item_id IS NULL";
        $unknown= $this->data->fetch_single_value($q);

        if ($unknown) {
          throw new \Exception("Upload includes items not in the catalog");
        }

        $q= "SELECT COUNT(*) total
               FROM vendor_order
               LEFT JOIN txn_line ON vendor_order.item_id = txn_line.item_id
                                 AND txn_id = {$txn->id}
              WHERE txn_line.id IS NULL";
        $unknown= $this->data->fetch_single_value($q);

        if ($unknown) {
          throw new \Exception("Upload includes items not on original order");
        }


        $q= "UPDATE txn_line, vendor_order
                SET allocated = allocated + vendor_order.shipped
              WHERE txn_line.item_id = vendor_order.item_id
                AND txn_id = {$txn->id}";
        $this->data->execute($q);

      } else {
        // Make sure we have all the items
        $q= "INSERT IGNORE INTO item (code, brand_id, name, retail_price, active)
             SELECT item_no AS code,
                    0 AS brand_id,
                    description AS name,
                    msrp AS retail_price,
                    1 AS active
               FROM vendor_order
              WHERE (NOT item_id OR item_id IS NULL) AND msrp > 0 AND IFNULL(unit,'') != 'AS'";
        $this->data->execute($q);

        if ($this->data->get_last_statement()->rowCount()) {
          # Attach order lines to new items
          $q= "UPDATE vendor_order, item
                  SET vendor_order.item_id = item.id
                WHERE (NOT vendor_order.item_id OR vendor_order.item_id IS NULL)
                  AND vendor_order.item_no != '' AND vendor_order.item_no IS NOT NULL
                  AND vendor_order.item_no = item.code";
          $this->data->execute($q);
        }

        // Make sure all the items are active
        $q= "UPDATE item, vendor_order
                SET item.active = 1
              WHERE vendor_order.item_id = item.id";
        $this->data->execute($q);

        // Make sure we know all the barcodes
        $q= "INSERT IGNORE INTO barcode (item_id, code, quantity)
             SELECT item_id,
                    REPLACE(REPLACE(barcode, 'E-', ''), 'U-', '') AS code,
                    1 AS quantity
              FROM vendor_order
             WHERE item_id AND barcode != ''";
        $this->data->execute($q);

        // Link items to vendor items if they aren't already
        $q= "UPDATE vendor_item, vendor_order
                SET vendor_item.item_id = vendor_order.item_id
              WHERE NOT vendor_item.item_id
                AND vendor_item.code = vendor_order.item_no
                AND vendor_item.active";
        $this->data->execute($q);

        // Get pricing for items if vendor_order didn't have them
        $q= "UPDATE vendor_order, vendor_item
                SET msrp = vendor_item.retail_price,
                    net = vendor_item.net_price
              WHERE msrp IS NULL
                AND vendor_order.item_id = vendor_item.item_id
                AND vendor_id = {$txn->person_id}
                AND vendor_item.active";
        $this->data->execute($q);

        // Add items to order
        $q= "INSERT INTO txn_line (txn_id, item_id, ordered, allocated, retail_price)
             SELECT {$txn->id} txn_id, item_id,
                    ordered, shipped, net
               FROM vendor_order
              WHERE (shipped OR backordered)
                AND (item_id != 0 AND item_id IS NOT NULL)";
        $this->data->execute($q);
      }

      $this->data->commit();
    }

    return $response->withJson($txn);
  }

  public function updateItem(Request $request, Response $response,
                              $id, $line_id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if (!$request->getParam('force') && !$txn->allow_item_changes()) {
      throw new \Scat\Exception\HttpConflictException($request,
        "Unable to modify item because transaction is {$txn->status}."
      );
    }

    $line= $txn->items()->find_one($line_id);
    if (!$line)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $this->data->beginTransaction();

    foreach ($line->getFields() as $field) {
      if ($field == 'id') continue;
      $value= $request->getParam($field);
      if ($value !== null) {
        $line->set($field, $value);
      }
    }

    // Have to handle this here because it depends on $txn
    $quantity= $request->getParam('quantity');
    if (strlen($quantity)) {
      /* special case: #/# lets us split line with two quantities */
      if (preg_match('!^(\d+)/(\d+)$!', $quantity, $m)) {
        if ($line->returned_from_id) {
          throw new \Scat\Exception\HttpConflictException($request,
            "Unable to split an item that is being returned."
          );
        }
        if ($line->item()->is_kit) {
          throw new \Scat\Exception\HttpConflictException($request,
            "Unable to split kit items."
          );
        }

        $quantity= (int)$m[2] * ($txn->type == 'customer' ? -1 : 1);

        $new= $txn->items()->create();
        $new->txn_id= $txn->id;
        $new->item_id= $line->item_id;
        $new->ordered= $quantity;
        $new->retail_price= $line->retail_price;
        $new->discount_type= $line->discount_type;
        $new->discount= $line->discount;
        $new->discount_manual= $line->discount_manual;
        $new->taxfree= $line->taxfree;
        $new->save();

        $quantity= (int)$m[1];
      } else {
        $quantity= (int)$quantity;
      }

      $quantity= $quantity * ($txn->type == 'customer' ? -1 : 1);

      if ($line->returned_from_id) {
        if ($quantity < 0) {
          throw new \Scat\Exception\HttpConflictException($request,
            "Quantity must be negative."
          );
        }
        $returned_from= $line->returned_from();
        if ($quantity > -$returned_from->allocated) {
          throw new \Scat\Exception\HttpConflictException($request,
            "Can't return more than originally purchased."
          );
        }
      } else {
        if ($txn->type == 'customer' && $quantity >= 0) {
          throw new \Scat\Exception\HttpConflictException($request,
            "Quantity must be greater than 0."
          );
        }
      }

      $line->ordered= $quantity;

      $item= $line->item();
      if ($item->is_kit) {
        $this->updateKitQuantities($txn, $line, $item);
      }
    }

    $line->save();

    // txn no longer filled?
    if ($txn->status == 'filled') {
      $txn->status= 'new';
      $txn->filled= NULL;
      $txn->save();
    }

    if (in_array($txn->status, [ 'new', 'filled' ])) {
      $txn->applyPriceOverrides($this->catalog);
      $txn->recalculateTax($this->tax);
    }

    $this->data->commit();

    return $response->withJson($line);
  }

  public function removeItem(Request $request, Response $response,
                              $id, $line_id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if (!$request->getParam('force') && !$txn->allow_item_changes()) {
      throw new \Scat\Exception\HttpConflictException($request,
        "Unable to remove item because transaction is {$txn->status}."
      );
    }

    $line= $txn->items()->find_one($line_id);
    if (!$line)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $this->data->beginTransaction();

    if ($line->item()->is_kit) {
      $txn->items()->where('kit_id', $line->item_id)->delete_many();
    }

    error_log("Removed {$line->ordered} {$line->item()->code} from txn $txn->id");

    $line->delete();

    if (in_array($txn->status, [ 'new', 'filled' ])) {
      $txn->applyPriceOverrides($this->catalog);
      $txn->recalculateTax($this->tax);
    }

    $this->data->commit();

    return $response->withJson(true);
  }

  /* Payments */
  public function payments(Request $request, Response $response,
                            $id, $payment_id= null)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $payment= $payment_id ? $txn->payments()->find_one($payment_id) : null;
    if ($payment_id && !$payment)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/vnd.scat.dialog+html') !== false) {
      $method= $request->getParam('method') ?: 'choose';
      return $this->view->render($response, 'dialog/pay-' . $method . '.html', [
        'txn' => $txn,
        'payment' => $payment,
        'other_method' => $request->getParam('other_method')
      ]);
    }

    return $response->withJson($payment);
  }


  public function addPayment(Request $request, Response $response,
                              \Scat\Service\AmazonPay $amazon,
                              \Scat\Service\PayPal $paypal,
                              \Scat\Service\Giftcard $gift,
                              \Scat\Service\Dejavoo $dejavoo,
                              \Scat\Service\Config $config, $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $this->data->beginTransaction();

    $method= $request->getParam('method');
    $amount= $request->getParam('amount');
    $amount= ltrim($amount, '$'); # get rid of leading $

    $change= (($method == 'cash' || $method == 'gift') ? true : false);
    if ($request->getParam('no_change')) {
      $change= false;
    }

    if (!$txn->canPay($method, $amount)) {
      throw new \Exception("Amount is too much.");
    }

    $data= $cc_data= $discount= null;

    switch ($method) {
    case 'refund':
      $other_method= $request->getParam('other_method');

      switch ($other_method) {
      case 'amazon':
        if ($amount <= 0) {
          throw new \Exception("Can only handle refunds.");
        }
        if (!$txn->returned_from_id) {
          throw new \Exception("Can't find original transaction to refund.");
        }

        $original= $txn->returned_from();
        $original_payment=
          $original->payments()->where('method', 'amazon')->find_one();

        if (!$original_payment) {
          throw new \Exception("Unable to find Amazon payment on original transaction {$original->id}");
        }

        $charge= $original_payment->data();

        $data= $amazon->refund($charge->chargeId, $amount, $txn->uuid);
        $amount= -$amount;

        break;

      case 'credit':
        // use -$amount because that's how refunds roll
        $result= $dejavoo->runTransaction($txn, -$amount);

        $amount= $result['amount'];
        $cc_data= $result['data'];

        break;

      case 'paypal':
        if ($amount <= 0) {
          throw new \Exception("Can only handle refunds.");
        }
        if (!$txn->returned_from_id) {
          throw new \Exception("Can't find original transaction to refund.");
        }

        $original= $txn->returned_from();
        $original_payment= null;

        foreach ($original->payments()->find_many() as $pay) {
          if ($pay->method == 'paypal') {
            $original_payment= $pay;
            break;
          }
        }

        if (!$original_payment) {
          throw new \Exception("Unable to find PayPal payment on original transaction");
        }

        $charge= json_decode($original_payment->data);

        $capture_id= $charge->purchase_units[0]->payments->captures[0]->id;

        $res= $paypal->refund($capture_id, $amount);

        $data= $res->result;
        $amount= -$amount;

        break;

      case 'stripe':
        if ($amount <= 0) {
          throw new \Exception("Can only handle refunds.");
        }
        if (!$txn->returned_from_id) {
          throw new \Exception("Can't find original transaction to refund.");
        }

        $original= $txn->returned_from();
        $original_payment= null;

        foreach ($original->payments()->find_many() as $pay) {
          if ($pay->method == 'stripe') {
            $original_payment= $pay;
            break;
          }
        }

        if (!$original_payment) {
          throw new \Exception("Unable to find Stripe payment on original transaction");
        }

        $charge= json_decode($original_payment->data);

        $stripe= new \Stripe\StripeClient($config->get('stripe.secret_key'));

        $data= $stripe->refunds->create([
          'charge' => $charge->charge_id,
          'amount' => (integer)(new \Decimal\Decimal(100) * $amount),
        ]);

        $amount= -$amount;
        break;

      case 'gift':
        if (!$txn->person_id) {
          throw new \Exception("Must be a person associated with the sale.");
        }

        $person= $txn->person();

        $card= $person->store_credit();

        if (!$card) {
          $card= $gift->create();

          $person->giftcard_id= $card->id;
          $person->save();
        }

        $card->add_txn($amount, $txn->id);

        $amount= -$amount;
        break;
      }

      $method= $other_method;
      break; // end of refund handling

    case 'other':
      $method= $request->getParam('other_method');
      if (!$method) {
        throw new \Exception("No other payment method supplied.");
      }
      /* Nothing else special to be done. */
      break;

    case 'cash':
      /* Nothing special to be done. */
      break;

    case 'credit':
      $result= $dejavoo->runTransaction($txn, $amount);

      $amount= $result['amount'];
      $cc_data= $result['data'];

      break;

    case 'gift':
      $data= [
        'card' => $request->getParam('card')
      ];
      if ($data['card']) {
        $gift->add_txn($data['card'], -$amount, $txn->id);
      }
      break;

    case 'discount':
      if (preg_match('!^(/)?\s*([0-9.]+)(%|/)?\s*$!', $amount, $m)) {
        if ($m[1] || $m[3]) {
          $amount= round($txn->total() * $m[2] / 100,
                         2, PHP_ROUND_HALF_EVEN);
          $discount= $m[2];
        }
      }
      break;

    case 'loyalty':
      $id= $request->getParam('reward_id');
      $data= $this->data->factory('LoyaltyReward')->find_one($id);
      $amount= -$data->item()->retail_price;
      break;

    default:
      throw new \Exception("Don't know how to handle a '$method' payment");
    }

    $payment= $txn->payments()->create();
    $payment->method= $method;
    $payment->txn_id= $txn->id();
    $payment->amount= $amount;
    if ($discount) {
      $payment->discount= $discount;
    }
    if ($cc_data) {
      foreach ($cc_data as $key => $value) {
        $payment->$key= $value;
      }
    }
    if ($data) {
      $payment->data= json_encode($data);
    }
    $payment->set_expr('processed', 'NOW()');
    $payment->set_expr('captured', 'NOW()');
    $payment->save();

    $txn->flushTotals(); // Need to recalculate totals

    // if total > 0 and amount + paid > total, add change record
    $change_paid= 0.0;
    if ($change && $txn->total() > 0 && $txn->total_paid() > $txn->total()) {
      $change_paid= bcsub($txn->total(), $txn->total_paid());

      $payment= $txn->payments()->create();
      $payment->method= 'change';
      $payment->txn_id= $txn->id();
      $payment->amount= $change_paid;
      $payment->set_expr('processed', 'NOW()');
      $payment->save();
    }

    $txn->flushTotals(); // Need to recalculate totals

    if ($txn->total() == $txn->total_paid()) {
      $txn->paid= date('Y-m-d H:i:s'); // not set_expr(), we need actual value
      if (!$txn->filled) {
        // TODO could do this all at once in a raw query
        foreach ($txn->items()->find_many() as $item) {
          $item->allocated= $item->ordered;
          $item->save();
        }
        $txn->set_expr('filled', 'NOW()');
      }

      $txn->rewardLoyalty();

      if (in_array($txn->status, [ 'new', 'filled' ])) {
        $txn->status= 'complete';
      }

      if (!$txn->tax_captured) {
        try {
          $txn->captureTax($this->tax);
        } catch (\Exception $e) {
          error_log("Error capturing tax: " . $e->getMessage());
        }
      }
    } else {
      $txn->paid= NULL;
      $txn->status= 'new'; // XXX not right, what about filled?
    }
    $txn->save();

    $this->data->commit();

    $txn->reload();

    return $response->withJson($txn);
  }

  public function modifyPayment(
    Request $request, Response $response,
    \Scat\Service\AmazonPay $amzn,
    $id, $payment_id
  ) {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $payment= $payment_id ? $txn->payments()->find_one($payment_id) : null;
    if (!$payment)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if ($request->getParam('cancel')) {
      if ($payment->method == 'amazon' && !$payment->captured) {
        $payment->amznCancel($amzn);
        $payment->set_expr('captured', 'NOW()');
        $payment->save();

        return $response->withJson($payment);
      }
    }

    throw new \Exception("Unable to modify payment.");
  }

  public function clearLoyaltyReward(Request $request, Response $response, $id)
  {
    $txn= $this->txn->fetchById($id);
    if ($txn->paid) {
      throw new \Exception("Can't remove loyalty reward after all paid.");
    }
    $txn->payments()->where('method', 'loyalty')->delete_many();
    return $response->withJson([ 'message' => 'Loyalty rewards cleared.' ]);
  }

  public function removeDiscount(Request $request, Response $response, $id)
  {
    $txn= $this->txn->fetchById($id);
    if ($txn->paid) {
      throw new \Exception("Can't remove discount reward after all paid.");
    }
    $txn->payments()->where('method', 'discount')->delete_many();
    return $response->withJson([ 'message' => 'Discount removed.' ]);
  }

  public function emailForm(Request $request, Response $response, $id) {
    $txn= $this->txn->fetchById($id);

    $data= [];
    if (($canned= $request->getParam('canned'))) {
      $data= $this->data->factory('CannedMessage')
        ->where('slug', $canned)
        ->find_one();
      $data= $data->as_array();
    }
    if (($full_invoice= $request->getParam('full_invoice'))) {
      $data['full_invoice']= true;
    }

    $data['txn']= $txn;

    return $this->view->render($response, 'dialog/email-invoice.html', $data);
  }

  public function email(Request $request, Response $response, $id,
                        \Scat\Service\Email $email)
  {
    $txn= $this->txn->fetchById($id);

    $to_name= $request->getParam('name');
    $to_email= $request->getParam('email');
    $subject= trim($request->getParam('subject'));

    $body= $this->view->fetch('email/invoice.html', [
      'txn' => $txn,
      'subject' => $subject,
      'content' => $request->getParam('content'),
      'full_invoice' => $request->getParam('full_invoice'),
    ]);

    $attachments= [];
    if ($request->getParam('include_details')) {
      $pdf= $txn->getInvoicePDF();
      $attachments[]= [
        base64_encode($pdf->Output('', 'S')),
        'application/pdf',
        (($txn->type == 'vendor') ? 'PO' : 'I') .
          $txn->formatted_number() . '.pdf',
        'attachment'
      ];
      if ($txn->type == 'vendor') {
        $tsv= $txn->getInvoiceTsv();
        $attachments[]= [
          base64_encode($tsv),
          'text/tab-separated-values',
          (($txn->type == 'vendor') ? 'PO' : 'I') .
            $txn->formatted_number() . '.tsv',
          'attachment'
        ];
      }
    }

    /* Pick up cc: */
    $options= [];
    if (($cc= $request->getParam('cc_email'))) {
      $options['cc']= $cc;
    }

    $res= $email->send([ $to_email => $to_name ],
                       $subject, $body, $attachments, $options);

    /* Attach email as a note */
    $note= $txn->createNote();
    $note->source= 'email';
    $note->content= $subject;
    $note->full_content= $body;
    $note->save();

    if (($status= $request->getParam('new_status'))) {
      $txn->set('status', $status);
      $txn->save();
    }

    return $response->withJson([ "message" => "Email sent." ]);
  }

  /* Shipping address */
  public function shippingAddress(Request $request, Response $response, $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/vnd.scat.dialog+html') !== false) {
      return $this->view->render($response, 'dialog/address.html', [
        'txn' => $txn,
      ]);
    }

    return $response->withJson($txn->address());
  }

  public function updateShippingAddress(Request $request, Response $response,
                                        \Scat\Service\Shipping $shipping,
                                        $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $this->data->beginTransaction();

    $details= $request->getParams();

    if (@$details['pickup'] == 1) {
      $txn->shipping_address_id= 1;
    } else {
      $details['verify']= [ 'delivery' ];
      $easypost_address= $shipping->createAddress($details);

      /* We always create a new address. */
      $address= $this->data->factory('Address')->create();
      $address->easypost_id= $easypost_address->id;
      $address->name= $easypost_address->name;
      $address->company= $easypost_address->company;
      $address->street1= $easypost_address->street1;
      $address->street2= $easypost_address->street2;
      $address->city= $easypost_address->city;
      $address->state= $easypost_address->state;
      $address->zip= $easypost_address->zip;
      $address->country= $easypost_address->country;
      $address->phone= $easypost_address->phone;
      $address->timezone=
        $easypost_address->verifications->delivery->details->time_zone;
      $address->save();

      $txn->shipping_address_id= $address->id;
    }

    $txn->save();

    $this->data->commit();

    return $response->withJson($address);
  }

  /* Shipments */

  public function saleShipments(Request $request, Response $response,
                                \Scat\Service\Shipping $shipping,
                                $id, $shipment_id= null)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $shipment= $shipment_id ? $txn->shipments()->find_one($shipment_id) : null;
    if ($shipment_id && !$shipment)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $label_date= $request->getParam('label_date');
    if (!$label_date) {
      if (date('H') >= 12) {
        $day= (date('w') == 5) ? 'saturday' : 'weekday';
        $label_date= date("Y-m-d", strtotime("next $day"));
      } else {
        $label_date= date("Y-m-d");
      }
    }

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/vnd.scat.dialog+html') !== false) {
      $dialog= ($request->getParam('tracker') ?
                'dialog/tracker.html' :
                'dialog/create-shipment.html');
      return $this->view->render($response, $dialog, [
        'txn' => $txn,
        'shipment' => $shipment,
        'label_date' => $label_date,
        'easypost' =>
          $shipment ? $shipping->getShipment($shipment) : null,
      ]);
    }

    return $response->withJson($shipment);
  }

  public function printShipmentLabel(Request $request, Response $response,
                                      \Scat\Service\Printer $print,
                                      \Scat\Service\Shipping $shipping,
                                      $id, $shipment_id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $shipment= $shipment_id ? $txn->shipments()->find_one($shipment_id) : null;
    if ($shipment_id && !$shipment)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if (!$shipment->method_id)
      throw new \Slim\Exception\HttpNotFoundException($request,
        "No details found for that shipment.");

    $details= $shipping->getShipment($shipment);

    $PNG= 1;

    if ($PNG) {
      $png= file_get_contents($details->postage_label->label_url);

      return $print->printPNG($response, 'shipping-label', $png);
    }

    if ($ZPL) {
      if (!$details->postage_label->label_zpl_url) {
        $details->label([ 'file_format' => 'zpl' ]);
      }

      $zpl= file_get_contents($details->postage_label->label_zpl_url);

      return $print->printZPL($response, 'shipping-label', $zpl);
    }

    if (!$details->postage_label->label_pdf_url) {
      $details->label([ 'file_format' => 'pdf' ]);
    }

    $pdf= file_get_contents($details->postage_label->label_pdf_url);

    return $print->printPDF($response, 'shipping-label', $pdf);
  }

  public function updateShipment(Request $request, Response $response,
                                  \Scat\Service\Shipping $shipping,
                                  $id, $shipment_id= null)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $shipment= $shipment_id ? $txn->shipments()->find_one($shipment_id) : null;
    if ($shipment_id && !$shipment)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if (!$shipment) {
      $shipment= $txn->shipments()->create();
      $shipment->txn_id= $txn->id;
    }

    foreach ($shipment->getFields() as $field) {
      if ($field == 'id') continue;
      $value= $request->getParam($field);
      if ($value !== null) {
        $shipment->set($field, $value);
      }
    }

    /* New tracking code? */
    if (($tracking_code= $request->getParam('tracking_code'))) {
      $shipment->tracker_id= $shipping->createTracker(
        $tracking_code,
        $request->getParam('carrier'),
      );
      /* Wait for webhook to update status. */
      $shipment->status= 'unknown';
    }

    /* New package info? */
    if (($weight= $request->getParam('weight'))) {
      $dims= preg_split('/[^\d.]+/', trim($request->getParam('dimensions')));
      if (preg_match('/(([0-9.]+)( *lbs)?)? +(([0-9.]+) *oz)?/', $weight, $m)) {
        $weight= $m[2] + (@$m[5] / 16);
      }

      $parcel= [
        'weight' => $weight * 16, // Needs to be oz.
      ];
      if (count($dims) == 3) {
        $parcel['length']= $dims[0];
        $parcel['width']= $dims[1];
        $parcel['height']= $dims[2];
      }
      $predefined_package= $request->getParam('predefined_package');
      if (strlen($predefined_package)) {
        $parcel['predefined_package']= $predefined_package;
      }
      if ($request->getParam('letter')) {
        $parcel['predefined_package']= 'Letter';
      }

      $options= [
        'invoice_number' => $txn->formatted_number(),
        'label_date' => $request->getParam('label_date') . 'T19:00:00-08:00',
      ];
      if ($request->getParam('hazmat')) {
        $options['hazmat']= 'LIMITED_QUANTITY';
      }
      if ($request->getParam('signature')) {
        $options['delivery_confirmation']= 'SIGNATURE';
      }

      $details= [
        'from_address' => $shipping->getDefaultFromAddress(),
        'to_address' =>
          $shipping->retrieveAddress($txn->shipping_address()->easypost_id),
        'parcel' => $parcel,
        'options' => $options,
      ];

      if ($request->getParam('is_return')) {
        $details['is_return']= true;
      }

      $extra= $shipping->createShipment($details, null, true);

      $shipment->weight= $weight;
      if (count($dims) == 3) {
        $shipment->length= $dims[0];
        $shipment->width= $dims[1];
        $shipment->height= $dims[2];
      }

      $shipment->method_id= $extra->id;
      $shipment->status= 'pending';
    }

    /* Re-rate? */
    if (($rerate= $request->getParam('rerate'))) {
      $ep= $shipping->getShipment($shipment);
      $ep->regenerate_rates();

      $shipment->status= 'pending';
    }

    /* Select a rate? */
    if (($rate_id= $request->getParam('rate_id'))) {
      $ep= $shipping->getShipment($shipment);
      $details= [ 'rate' => [ 'id' => $rate_id ] ];
      $insurance= $txn->subtotal();
      $no_insurance= (int)$request->getParam('no_insurance');
      if ($insurance > 100.00 && !$no_insurance) {
        $details['insurance']= $insurance;
      }

      $res= $ep->buy($details);

      $shipment->carrier= $res->selected_rate->carrier;
      $shipment->service= $res->selected_rate->service;
      $shipment->rate= $res->selected_rate->rate;
      $shipment->insurance= $res->insurance;

      $shipment->status= 'unknown';
      $shipment->tracker_id= $ep->tracker->id;
    }

    if (!$shipment->method_id && !$shipment->tracker_id) {
      throw new \Exception("Not enough information to create shipment.");
    }

    $shipment->save();

    return $response->withJson($shipment);
  }

  /* Deliveries */
  public function saleDeliveries(Request $request, Response $response,
                                  $id, $delivery_id= null)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/vnd.scat.dialog+html') !== false) {
      return $this->view->render($response, 'dialog/delivery.html', [
        'txn' => $txn,
      ]);
    }

    // TODO return delivery info
    return $response->withJson($txn);
  }

  public function updateDelivery(Request $request, Response $response,
                                  \Scat\Service\Email $email,
                                  $id, $delivery_id= null)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $shipment= $delivery_id ? $txn->shipments()->find_one($delivery_id) : null;
    if ($delivery_id && !$shipment)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if (!$shipment) {
      $shipment= $txn->shipments()->create();
      $shipment->txn_id= $txn->id;
      $shipment->method= 'shipdistrict';
    }

    foreach ($shipment->getFields() as $field) {
      if ($field == 'id') continue;
      $value= $request->getParam($field);
      if ($value !== null) {
        if ($value === '') $value= null;
        if ($field == 'rate')
          $value= preg_replace('/^\\$/', '', $value);
        $shipment->set($field, $value);
      }
    }

    $to_name= $this->config->get('delivery.name');
    $to_email= $this->config->get('delivery.email');
    $subject= sprintf("%s delivery (%s)",
                      $shipment->is_new() ? 'New' : 'Updated',
                      $txn->formatted_number());

    $body= $this->view->fetch('email/delivery.html', [
      'txn' => $txn,
      'delivery' => $shipment,
      'subject' => $subject
    ]);

    $attachments= [];

    $res= $email->send([ $to_email => $to_name ],
                       $subject, $body, $attachments);

    $shipment->save();

    return $response->withJson($shipment);
  }

  /* Drop-ships */
  public function saleDropShips(Request $request, Response $response,
                                $id, $dropship_id= null)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $dropship= $dropship_id ? $txn->dropships()->find_one($dropship_id) : null;
    if ($dropship_id && !$dropship)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/vnd.scat.dialog+html') !== false) {
      $vendors= $this->data->factory('Person')
                      ->where('active', 1)
                      ->where('role', 'vendor')
                      ->order_by_asc(['company', 'name'])
                      ->find_many();

      return $this->view->render($response, 'dialog/dropship.html', [
        'vendors' => $vendors,
        'txn' => $txn,
        'dropship' => $dropship
      ]);
    }

    return $response->withJson($dropship);
  }

  public function createDropShip(Request $request, Response $response,
                                  $id, $dropship_id= null)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $dropship= $dropship_id ? $txn->dropships()->find_one($dropship_id) : null;
    if ($dropship_id && !$dropship)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $this->data->beginTransaction();

    if (!$dropship) {
      $dropship= $this->txn->create('vendor');
      $dropship->shipping_address_id= $txn->shipping_address_id;
      $dropship->returned_from_id= $txn->id;
    }

    foreach ($dropship->getFields() as $field) {
      if ($field == 'id') continue;
      $value= $request->getParam($field);
      if ($value !== null) {
        $dropship->set($field, $value);
      }
    }

    $vendor= $dropship->person();
    if (!$vendor->role == 'vendor') {
      throw new \Slim\Exception\HttpBadRequestException($request,
        "No vendor supplied for dropship!"
      );
    }

    /* New dropship? Add items that this vendor has available. */
    if (!$dropship_id) {
      foreach ($txn->items()->find_many() as $item) {
        $vi= $vendor->items()->where('item_id', $item->item_id)->find_one();
        if ($vi) {
          $new= $dropship->items()->create();
          $new->txn_id= $dropship->id;
          $new->item_id= $item->item_id;
          $new->ordered= -1 * $item->ordered;
          $new->retail_price= ($vi->promo_price > 0 && $new->ordered > $vi->promo_quantity) ? $vi->promo_price : $vi->net_price;
          $new->save();
        }
      }
    }

    $dropship->save();

    if ($txn->status == 'paid') {
      $txn->status= 'processing';
      $txn->save();
    }

    $this->data->commit();

    return $response->withJson($dropship);
  }

  public function captureTax(Request $request, Response $response, $id)
  {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    if ($txn->tax_captured) {
      return $response->withJson([ 'message' => 'Already captured.' ]);
    }

    $txn->captureTax($this->tax);

    return $response->withJson($txn);
  }

  public function report(Request $request, Response $response, $id) {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/vnd.scat.dialog+html') !== false) {
      return $this->view->render($response, 'dialog/txn-report.html', [
        'txn' => $txn,
      ]);
    }

    return $response->withJson($txn);
  }

  /* PURCHASES */

  public function purchases(Request $request, Response $response) {
    return $this->search($request, $response, 'vendor');
  }

  public function reorderForm(Request $request, Response $response) {
    $extra= $extra_field= $extra_field_name= '';

    $which= (int)$request->getParam('which');

    $vendor_code= "NULL";
    $vendor_id= (int)$request->getParam('vendor_id');
    if ($vendor_id > 0) {
      $vendor_code= "(SELECT code FROM vendor_item WHERE vendor_id = $vendor_id AND item_id = item.id AND vendor_item.active LIMIT 1)";
      $extra= "AND EXISTS (SELECT id
                             FROM vendor_item
                            WHERE vendor_id = $vendor_id
                              AND item_id = item.id
                              AND vendor_item.active)";
      $extra_field= "(SELECT MIN(IF(promo_quantity, promo_quantity,
                                    purchase_quantity))
                        FROM vendor_item
                       WHERE item_id = item.id
                         AND vendor_id = $vendor_id
                         AND vendor_item.active)
                      AS minimum_order_quantity,
                     (SELECT MIN(vendor_item.id)
                        FROM vendor_item
                        JOIN person ON vendor_item.vendor_id = person.id
                      WHERE item_id = item.id
                        AND vendor_id = $vendor_id
                        AND vendor_item.active)
                      AS vendor_item_id,
                     (SELECT MIN(IF(promo_price, promo_price, net_price))
                        FROM vendor_item
                        JOIN person ON vendor_item.vendor_id = person.id
                      WHERE item_id = item.id
                        AND vendor_id = $vendor_id
                        AND vendor_item.active)
                      AS cost,
                     (SELECT MIN(IF(promo_price, promo_price, net_price)
                                 * ((100 - vendor_rebate) / 100))
                        FROM vendor_item
                        JOIN person ON vendor_item.vendor_id = person.id
                      WHERE item_id = item.id
                        AND vendor_id = $vendor_id
                        AND vendor_item.active) -
                     (SELECT MIN(IF(promo_price, promo_price, net_price)
                                 * ((100 - vendor_rebate) / 100))
                        FROM vendor_item
                        JOIN person ON vendor_item.vendor_id = person.id
                       WHERE item_id = item.id
                         AND NOT special_order
                         AND vendor_id != $vendor_id
                         AND vendor_item.active)
                     cheapest,
                     (SELECT MIN(special_order) FROM vendor_item
                       WHERE item_id = item.id
                         AND vendor_id = $vendor_id) special_order, ";
      $extra_field_name= "minimum_order_quantity, vendor_item_id, cheapest, cost,special_order,";
    } else if ($vendor_id < 0) {
      // No vendor
      $extra= "AND NOT EXISTS (SELECT id
                                 FROM vendor_item
                                WHERE item_id = item.id
                                  AND vendor_item.active)";
    }

    $code= trim($request->getParam('code'));
    if ($code) {
      $extra.= " AND code LIKE " . $this->data->escape($code.'%');
    }
    if ($which == 2) {
      $criteria= '1=1';
    } elseif ($which == 1) {
      $criteria= '(minimum_quantity > 0)';
    } else {
      $criteria= '(ordered IS NULL OR NOT ordered)
                    AND IFNULL(stock, 0) < minimum_quantity';
    }
    $q= "SELECT id, code, vendor_code, name, stock,
                minimum_quantity, last3months, no_backorder,
                $extra_field_name
                order_quantity
           FROM (SELECT item.id,
                        item.code,
                        $vendor_code AS vendor_code,
                        name,
                        SUM(allocated) stock,
                        minimum_quantity,
                        (SELECT -1 * SUM(allocated)
                           FROM txn_line JOIN txn ON (txn_id = txn.id)
                          WHERE type = 'customer'
                            AND txn_line.item_id = item.id
                            AND filled > NOW() - INTERVAL 3 MONTH)
                        AS last3months,
                        item.no_backorder,
                        (SELECT SUM(ordered - allocated)
                           FROM txn_line JOIN txn ON (txn_id = txn.id)
                          WHERE type = 'vendor'
                            AND txn_line.item_id = item.id
                            AND created > NOW() - INTERVAL 12 MONTH)
                        AS ordered,
                        $extra_field
                        IF(minimum_quantity > minimum_quantity - SUM(allocated),
                           minimum_quantity,
                           minimum_quantity - IFNULL(SUM(allocated), 0))
                          AS order_quantity
                   FROM item
                   LEFT JOIN txn_line ON (item_id = item.id)
                  WHERE purchase_quantity
                    AND item.active AND NOT item.deleted
                    $extra
                  GROUP BY item.id
                  ORDER BY code) t
           WHERE $criteria
           ORDER BY code
          ";

    $this->data->configure('logging', false);
    $items= $this->data->for_table('item')->raw_query($q)->find_many();
    $this->data->configure('logging', true);

    return $this->view->render($response, 'purchase/reorder.html', [
      'items' => $items,
      'which' => $which,
      'code' => $code,
      'vendor_id' => $vendor_id,
      'person' => $this->data->factory('Person')->find_one($vendor_id)
    ]);
  }

  public function createPurchase(Request $request, Response $response) {
    $vendor_id= $request->getParam('vendor_id');

    error_log("Creating purchase for $vendor_id");

    if (!$vendor_id) {
      throw new \Exception("No vendor specified.");
    }

    $purchase= $this->txn->create('vendor', [
      'person_id' => $vendor_id,
      'tax_rate' => 0,
    ]);

    $purchase->save();

    /* Pass through to addToPurchase() to handle adding items */
    return $this->addToPurchase($request, $response, $purchase->id);
  }

  public function addToPurchase(Request $request, Response $response, $id) {
    $purchase= $this->txn->fetchById($id);
    if (!$purchase) {
      throw new \Exception("Unable to find transaction.");
    }

    $this->data->beginTransaction();

    $vendor_id= $purchase->person_id;

    if (!$vendor_id) {
      throw new \Exception("No vendor specified.");
    }

    $items= $request->getParam('item');
    if ($items) {
      foreach ($items as $item_id => $quantity) {
        if (!$quantity) {
          continue;
        }

        // TODO should be using Catalog Service for this
        $vendor_items=
          \Scat\Model\VendorItem::findByItemIdForVendor($item_id,
                                                  $vendor_id);

        // Get the lowest available price for our quantity
        $price= 0;
        foreach ($vendor_items as $item) {
          $contender= ($item->promo_price > 0.00 &&
                       $quantity >= $item->promo_quantity) ?
                      $item->promo_price :
                      (($quantity >= $item->purchase_quantity) ?
                       $item->net_price :
                       0);
          $price= ($price && $price < $contender) ?
                  $price :
                  $contender;
        }

        if (!$price) {
          error_log("Failed to get price for $item_id");
          // Just use the first one we found
          $price= $vendor_items[0]->net_price;
        }

        $item= $purchase->items()->create();
        $item->txn_id= $purchase->id;
        $item->item_id= $item_id;
        $item->ordered= $quantity;
        $item->retail_price= $price;
        $item->save();
      }
    }

    $this->data->commit();

    $path= '/purchase/' . $purchase->id;

    return $response->withRedirect($path);
  }

  public function purchase(Request $request, Response $response, $id) {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') !== false) {
      return $response->withJson($txn);
    }

    if (($block= $request->getParam('block'))) {
      $html= $this->view->fetchBlock('purchase/txn.html', $block, [
        'txn' => $txn,
      ]);

      $response->getBody()->write($html);
      return $response;
    }

    return $this->view->render($response, 'purchase/txn.html', [
      'txn' => $txn,
    ]);
  }

  public function markAllReceived(Request $request, Response $response, $id) {
    $purchase= $this->txn->fetchById($id);
    if (!$purchase) {
      throw new \Exception("Unable to find transaction.");
    }

    $this->data->beginTransaction();

    foreach ($purchase->items()->find_many() as $line) {
      $line->allocated= $line->ordered;
      $line->save();
    }

    $purchase->set_expr('filled', 'NOW()');
    $purchase->status= 'complete';
    $purchase->save();

    $this->data->commit();

    return $response->withJson($purchase);
  }

  public function clearAll(Request $request, Response $response, $id) {
    $purchase= $this->txn->fetchById($id);
    if (!$purchase) {
      throw new \Exception("Unable to find transaction.");
    }

    if (!in_array($purchase->status, [ 'new', 'processing' ])) {
      throw new \Exception("Purchase must be in 'new' or 'processing' status to clear.");
    }

    $purchase->clearItems();

    return $response->withJson($purchase);
  }

  public function exportPurchase(Request $request, Response $response, $id) {
    $purchase= $this->txn->fetchById($id);
    if (!$purchase) {
      throw new \Exception("Unable to find transaction.");
    }

    $content_type= 'text/tsv';
    $ext= 'tsv';

    if ($purchase->person_id == 3757) { // XXX hardcoded
      $content_type= 'application/vnd.ms-excel';
      $ext= 'xls';
    }

    $name= 'PO' . $purchase->formatted_number() . '.' . $ext;

    $response= $response
      ->withHeader('Content-type', $content_type)
      ->withHeader('Content-disposition',
                    'attachment; filename="' . $name . '"')
      ->withHeader('Cache-control', 'max-age=0');

    if ($ext == 'xls') {
      $xls= new \PhpOffice\PhpSpreadsheet\Spreadsheet();

      $xls->setActiveSheetIndex(0);
      $row= 1;

      $xls->getActiveSheet()->setCellValueByColumnAndRow(1, $row, "code")
                            ->setCellValueByColumnAndRow(2, $row, "")
                            ->setCellValueByColumnAndRow(3, $row, "qty");
      $row+=1;

      foreach ($purchase->items()->find_many() as $item) {
        $code= $item->vendor_sku() ?: $item->code();
        $xls->getActiveSheet()->setCellValueByColumnAndRow(1, $row, $code)
                              ->setCellValueByColumnAndRow(2, $row, "")
                              ->setCellValueByColumnAndRow(3, $row,
                                                           $item->ordered);
        $row+=1;
      }

      $file= tempnam(sys_get_temp_dir(), 'export');

      $objWriter= \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($xls, 'Xls');
      $objWriter->save($file);

      $newStream= new \GuzzleHttp\Psr7\LazyOpenStream($file, 'r');
      return $response->withBody($newStream);

      // XXX leaves behind the temp file, should probably unlink() it

    } else {
      $body= $response->getBody();

      $body->write("code\tqty\r\n");

      foreach ($purchase->items()->find_many() as $item) {
        $body->write(
          ($item->vendor_sku() ?: $item->code()) . "\t" .
          $item->ordered . "\r\n"
        );
      }

      return $response->withBody($body);
    }
  }

  public function corrections(Request $request, Response $response) {
    return $this->search($request, $response, 'correction');
  }

  public function correction(Request $request, Response $response, $id) {
    $txn= $this->txn->fetchById($id);
    if (!$txn)
      throw new \Slim\Exception\HttpNotFoundException($request);

    $accept= $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') !== false) {
      return $response->withJson($txn);
    }

    if (($block= $request->getParam('block'))) {
      $html= $this->view->fetchBlock('correction/txn.html', $block, [
        'txn' => $txn,
      ]);

      $response->getBody()->write($html);
      return $response;
    }

    return $this->view->render($response, 'correction/txn.html', [
      'txn' => $txn,
    ]);
  }
}
