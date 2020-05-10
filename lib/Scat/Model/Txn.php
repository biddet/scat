<?php
namespace Scat\Model;

class Txn extends \Scat\Model {
  private $_totals;

  public function formatted_number() {
    $created= new \DateTime($this->created);
    return $this->type == 'vendor' ?
      ($created->format('Y') > 2013 ?
       $created->format('y') . $this->number : // Y3K
       $created->format('Y') . '-' . $this->number) :
      ($created->format("Y") . "-" . $this->number);
  }

  public function friendly_type() {
    switch ($this->type) {
      case 'vendor':
        return 'Purchase Order';
      case 'correction':
        return 'Correction';
      case 'drawer':
        return 'Till Count';
      case 'customer':
        return $this->returned_from_id ? 'Return' : 'Sale';
    }
  }

  public function items() {
    return $this->has_many('TxnLine');
  }

  public function notes() {
    return $this->has_many('Note', 'attach_id');
  }

  public function payments() {
    return $this->has_many('Payment');
  }

  public function person() {
    return $this->belongs_to('Person')->find_one();
  }

  public function shipping_address() {
    return $this->belongs_to('Address', 'shipping_address_id')->find_one();
  }

  function clearItems() {
    $this->orm->get_db()->beginTransaction();
    $this->items()->delete_many();
    $this->filled= null;
    $this->save();
    $this->orm->get_db()->commit();
    return true;
  }

  private function _loadTotals() {
    if ($this->_totals) return $this->_totals;

    $q= "SELECT ordered, allocated,
                taxed, untaxed,
                CAST(tax_rate AS DECIMAL(9,2)) tax_rate,
                taxed + untaxed subtotal,
                IF(uuid IS NOT NULL, /* Tax calculated per-line */
                   tax,
                   CAST(ROUND_TO_EVEN(taxed * (tax_rate / 100), 2)
                        AS DECIMAL(9,2))) AS tax,
                IF(uuid IS NOT NULL,
                   taxed + untaxed + tax,
                   CAST(ROUND_TO_EVEN(taxed * (1 + tax_rate / 100), 2) + untaxed
                        AS DECIMAL(9,2))) total,
                IFNULL(total_paid, 0.00) total_paid
          FROM (SELECT
                txn.uuid,
                SUM(ordered) * IF(txn.type = 'customer', -1, 1) AS ordered,
                SUM(allocated) * IF(txn.type = 'customer', -1, 1) AS allocated,
                CAST(ROUND_TO_EVEN(
                  SUM(IF(txn_line.taxfree, 1, 0) *
                    IF(type = 'customer', -1, 1) * ordered *
                    sale_price(retail_price, discount_type, discount)),
                  2) AS DECIMAL(9,2))
                untaxed,
                CAST(ROUND_TO_EVEN(
                  SUM(IF(txn_line.taxfree, 0, 1) *
                    IF(type = 'customer', -1, 1) * ordered *
                    sale_price(retail_price, discount_type, discount)),
                  2) AS DECIMAL(9,2))
                taxed,
                tax_rate,
                SUM(tax) AS tax,
                CAST((SELECT SUM(amount)
                        FROM payment
                       WHERE txn.id = payment.txn_id)
                     AS DECIMAL(9,2)) AS total_paid
           FROM txn
           LEFT JOIN txn_line ON (txn.id = txn_line.txn_id)
          WHERE txn.id = {$this->id}) t";
    $this->orm->raw_execute($q);
    $st= $this->orm->get_last_statement();
    return ($this->_totals= $st->fetch(\PDO::FETCH_ASSOC));
  }

  public function subtotal() {
    return $this->_loadTotals()['subtotal'];
  }

  public function tax() {
    return $this->_loadTotals()['tax'];
  }

  public function total() {
    return $this->_loadTotals()['total'];
  }

  public function total_paid() {
    return $this->_loadTotals()['total_paid'];
  }

  public function due() {
    $total= $this->_loadTotals();
    return $total['total'] - $total['total_paid'];
  }

  public function ordered() {
    $total= $this->_loadTotals();
    return $total['ordered'];
  }

  public function allocated() {
    $total= $this->_loadTotals();
    return $total['ordered'];
  }

  public function getInvoicePDF($variation= '') {
    $loader= new \Twig\Loader\FilesystemLoader('../ui/');
    $twig= new \Twig\Environment($loader, [ 'cache' => false ]);

    $template= $twig->load('print/invoice.html');
    $html= $template->render([ 'txn' => $this, 'variation' => $variation ]);

    define('_MPDF_TTFONTDATAPATH', '/tmp/ttfontdata');
    @mkdir(_MPDF_TTFONTDATAPATH);

    $mpdf= new \Mpdf\Mpdf([ 'mode' => 'utf-8', 'format' => 'letter',
                            'tempDir' => '/tmp',
                            'default_font_size' => 11  ]);
    $mpdf->setAutoTopMargin= 'stretch';
    $mpdf->setAutoBottomMargin= 'stretch';
    $mpdf->writeHTML($html);

    return $mpdf;
  }

  public function shipments() {
    return $this->has_many('Shipment');
  }

  public function applyPriceOverrides(\Scat\Service\Catalog $catalog) {
    // Not an error, but we don't do anything
    if ($this->type != 'customer') {
      return;
    }

    $discounts= $catalog->getPriceOverrides();

    foreach ($discounts as $d) {
      if ($d->pattern_type == 'product') {
        $condition= "`product_id` = '{$d->pattern}'";
      } else {
        $condition= "`code` {$d->pattern_type} '{$d->pattern}'";
      }

      $items= $this->items()
        ->select('txn_line.*')
        ->select('item.retail_price', 'original_retail_price')
        ->select('item.discount', 'original_discount')
        ->select('item.discount_type', 'original_discount_type')
        ->join('item', [ 'item_id', '=', 'item.id' ])
        ->where('txn_id', $this->id)
        ->where_raw($condition)
        ->where_raw('NOT `discount_manual`');

      /* turn off logging here, it's just too much */
      \ORM::configure('logging', false);
      $count= abs($items->sum('ordered'));
      $items->limit(null); // reset limit that sum() injects into $items
      \ORM::configure('logging', true);

      if (!$count) {
        continue;
      }

      $new_discount= 0;
      $new_discount_type= '';

      $breaks= explode(',', $d->breaks);
      $discount_types= explode(',', $d->discount_types);
      $discounts= explode(',', $d->discounts);

      foreach ($breaks as $i => $qty) {
        if ($count >= $qty) {
          $new_discount_type= $discount_types[$i];
          $new_discount= $discounts[$i];
        }
      }

      foreach ($items->find_many() as $item) {
        if ($new_discount) {
          if ($new_discount_type != 'additional_percentage') {
            $item->discount= $new_discount;
            $item->discount_type= $new_discount_type;
          } else {
            $item->discount= $this->calcSalePrice(
              $this->calcSalePrice($item->original_retail_price,
                                   $item->original_discount_type,
                                   $item->original_discount),
              'percentage',
              $new_discount
            );
            $item->discount_type= 'fixed';
          }
        } else {
          $item->discount= $item->original_discount;
          $item->discount_type= $item->original_discount_type;
        }
        $item->save();
      }
    }

    foreach ($this->payments() as $payment) {
      if ($payment->method == 'discount' && $payment->discount) {
        // Force total() to be calculated
        unset($txn->_totals);
        $payment->amount= $payment->discount / 100 * $txn->total();
        $payment->save();
      }
    }
  }

  public function recalculateTax(\Scat\Service\Tax $tax) {
    if ($this->type != 'customer') {
      return;
    }

    if ($this->shipping_address_id > 1) {
      throw new Exception("Don't know how to calculate tax on shipped orders.");
    }

    /* Really need to nail down how TaxCloud does rounding. */
    $scale= bcscale(5);
    $tax_rate= bcdiv($this->tax_rate, 100);

    foreach ($this->items()->find_many() as $line) {
      if (!in_array($line->tic, [ '91082', '10005', '11000' ])) {
        $tax= bcmul(bcmul($line->ordered * -1, $line->sale_price()),
                    $tax_rate);
        if ($tax != $line->tax) {
          $line->tax= $tax;
          $line->save();
        }
      }
    }

    bcscale($scale);
  }
}
