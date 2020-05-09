<?
include '../scat.php';
include '../lib/txn.php';
include '../lib/item.php';
include '../lib/pole.php';

$txn_id= (int)$_REQUEST['txn'];

$item= (int)$_REQUEST['item'];
$search= $_REQUEST['q'];

if (!$search && !$item) die_jsonp('no query specified');

if (!$txn_id) {
  die_jsonp("No transaction specified.");
}

$txn= txn_load($db, $txn_id);
if ($txn['paid']) {
  die_jsonp("This order is already paid!");
}

if (!$search)
  $search= "item:$item";

$items= item_find($db, $search, (($item || $_REQUEST['all']) ? FIND_ALL : 0) | FIND_STOCKED_FIRST);

/*
  Fallback: if we found nothing, try searching for an exact match on the
  barcode to catch items inadvertantly set inactive.
*/
if (count($items) == 0) {
  $items= item_find($db, "barcode:\"$search\"", FIND_ALL);
}

/* if it is just one item, go ahead and add it to the invoice */
if (count($items) != 1) {
  die_jsonp(count($items) ? "More than one item found." : 'No items found.');
} else {
  // XXX some items should always be added on their own
  $unique= preg_match('/^ZZ-(frame|print|univ|canvas|stretch|float|panel|giftcard)/i', $items[0]['code']);

  $q= "SELECT id, ordered FROM txn_line
        WHERE txn_id = $txn_id AND item_id = {$items[0]['id']}";
  $r= $db->query($q);
  if (!$r) die_query($db, $q);

  /* if found via barcode, we may have better quantity */
  $quantity= ($txn['type'] == 'customer') ? -1 : 1;
  if ($items[0]['barcode'][$search])
    $quantity*= $items[0]['barcode'][$search];

  if (!$unique && $r->num_rows) {
    $row= $r->fetch_assoc();
    $items[0]['line_id']= $row['id'];

    $items[0]['quantity']= ($row['ordered'] + $quantity);

    $q= "UPDATE txn_line SET ordered = {$items[0]['quantity']}
          WHERE id = {$items[0]['line_id']}";
    $r= $db->query($q);
    if (!$r) die_query($db, $q);
  } else {
    $prices= ($txn['type'] == 'customer') ?
               'retail_price, discount, discount_type' :
               "IFNULL((SELECT IF(promo_price AND promo_price < net_price,
                                  promo_price, net_price)
                          FROM vendor_item
                         WHERE vendor_id = {$txn['person_id']}
                           AND item_id = item.id
                           AND vendor_item.active
                         ORDER BY 1
                         LIMIT 1), 0.00), NULL, NULL";

    $q= "INSERT INTO txn_line (txn_id, item_id, ordered,
                               retail_price, discount, discount_type,
                               taxfree, tic)
         SELECT $txn_id AS txn_id, {$items[0]['id']} AS item_id,
                $quantity AS ordered, $prices, taxfree, tic
           FROM item WHERE id = {$items[0]['id']}";
    $r= $db->query($q);
    if (!$r) die_query($db, $q);
    $items[0]['line_id']= $db->insert_id;
  }

  // XXX error handling
  txn_apply_discounts($db, $txn_id);

  $new_line= $items['0']['line_id'];

  $ret= txn_load_full($db, $txn_id);
  $ret['new_line']= $new_line;

  if ($txn['type'] == 'customer') {
    foreach ($ret['items'] as $item) {
      if ($item['line_id'] == $new_line && $item['price']) {
        pole_display_price($item['name'], $item['price']);
        break;
      }
    }
  }

  echo jsonp($ret);
}
