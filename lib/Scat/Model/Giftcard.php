<?php
namespace Scat\Model;

include dirname(__FILE__).'/../../../extern/php-barcode.php';

class Giftcard extends \Scat\Model {
  public function card() {
    return $this->id . $this->pin;
  }

  public function txns() {
    return $this->has_many('Giftcard_Txn', 'card_id');
  }

  public function created() {
    return $this->txns()->min('entered');
  }

  public function last_seen() {
    return $this->txns()->max('entered');
  }

  public function balance() {
    return $this->txns()->sum('amount');
  }

  public function owner() {
    return $this->has_one('Person')->find_one();
  }

  public function jsonSerialize() {
    $history= array();
    $balance= new \Decimal\Decimal(0);
    $latest= "";

    $txns= $this->txns()
             ->select('*')
             ->select_expr("IF(type = 'vendor' && YEAR(created) > 2013,
                               CONCAT(SUBSTRING(YEAR(created), 3, 2), number),
                               CONCAT(DATE_FORMAT(created, '%Y-'), number))",
                           'txn_name')
             ->left_outer_join('txn',
                               array('txn.id', '=', 'giftcard_txn.txn_id'))
             ->find_many();

    foreach ($txns as $txn) {
      $history[]= array( 'entered' => $txn->entered,
                         'amount' => $txn->amount,
                         'txn_id' => $txn->txn_id,
                         'txn_name' => $txn->txn_name );
      $balance= $balance + new \Decimal\Decimal($txn->amount);
      $latest= $txn->entered;
    }

    return array(
      'id' => $this->id,
      'pin' => $this->pin,
      'card' => $this->id . $this->pin,
      'expires' => $this->expires,
      'history' => $history,
      'balance' => (string)$balance->round(2),
      'latest' => $latest,
    );
  }

  public function getPDF() {
    $card= $this->id . $this->pin;

    $balance= new \Decimal\Decimal(0);

    $txns= $this->txns()
             ->select('*')
             ->select_expr("IF(type = 'vendor' && YEAR(created) > 2013,
                               CONCAT(SUBSTRING(YEAR(created), 3, 2), number),
                               CONCAT(DATE_FORMAT(created, '%Y-'), number))",
                           'txn_name')
             ->left_outer_join('txn',
                               array('txn.id', '=', 'giftcard_txn.txn_id'))
             ->find_many();

    foreach ($txns as $txn) {
      $balance= $balance + new \Decimal\Decimal($txn->amount);
    }

    $issued= (new \Datetime($this->issued))->format('l, F j, Y');

    /* Work around deprecations in FPDF code */
    $error_reporting= error_reporting(E_ALL ^ E_DEPRECATED);

    // initiate FPDI
    $pdf = new \setasign\Fpdi\Fpdi('P', 'in', array(8.5, 11));
    // add a page
    $pdf->AddPage();
    // set the source file
    $pdf->setSourceFile("../print/blank-gift-card.pdf");
    // import page 1
    $tplIdx = $pdf->importPage(1);
    // use the imported page
    $pdf->useTemplate($tplIdx);

    // now write some text above the imported page
    $pdf->SetFont('Helvetica');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFontSize(($basefontsize= 18));

    if ($balance) {
      $balance= (string)$balance->round(2);
      $width= $pdf->GetStringWidth('$' . $balance);
      $pdf->SetXY(4.25 - ($width / 2), 2.5);
      $pdf->Write(0, '$' . $balance);
    }

    $width= $pdf->GetStringWidth($issued);
    $pdf->SetXY(4.25 - ($width / 2), 3.25);
    $pdf->Write(0, $issued);

    $code= "RAW-$card";
    $type= "code39";

    \Barcode::fpdf($pdf, '000000',
                   4.25, 5,
                   0 /* angle */, $type,
                   $code, (1/72), $basefontsize/2/72);

    $pdf->SetFontSize(10);
    $width= $pdf->GetStringWidth($card);
    $pdf->SetXY(4.25 - ($width / 2), 5.2);
    $pdf->Write(0, $card);

    ob_start();
    $pdf->Output();
    $content= ob_get_contents();
    ob_end_clean();

    error_reporting($error_reporting);

    return $content;
  }

  function add_txn($amount, $txn_id= 0) {
    $txn= $this->txns()->create();
    $txn->amount= $amount;
    $txn->card_id= $this->id;
    if ($txn_id) $txn->txn_id= $txn_id;
    $txn->save();
  }
}

class Giftcard_Txn extends \Scat\Model {
  public function card() {
    return $this->belongs_to('Giftcard', 'card_id')->find_one();
  }

  public function txn() {
    return $this->belongs_to('Txn')->find_one();
  }
}
