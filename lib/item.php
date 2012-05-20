<?

define('FIND_ALL', 1);
define('FIND_OR', 2);

function item_find($db, $q, $options) {
  $andor= array();
  $not= array();

  $terms= preg_split('/\s+/', $q);
  foreach ($terms as $term) {
    $term= $db->real_escape_string($term);
    if (preg_match('/^code:(.+)/i', $term, $dbt)) {
      $andor[]= "(item.code LIKE '{$dbt[1]}%')";
    } elseif (preg_match('/^item:(.+)/i', $term, $dbt)) {
      $andor[]= "(item.id = '{$dbt[1]}%')";
    } elseif (preg_match('/^-(.+)/i', $term, $dbt)) {
      $not[]= "(item.code NOT LIKE '{$dbt[1]}%')";
    } else {
      $andor[]= "(item.name LIKE '%$term%'
               OR brand.name LIKE '%$term%'
               OR item.code LIKE '%$term%'
               OR barcode.code LIKE '%$term%')";
    }
  }

  $sql_criteria= join(($options & FIND_OR) ? ' OR ' : ' AND ', $andor);
  if (count($not)) {
    $sql_criteria= "($sql_criteria) AND " . join(' AND ', $not);
  }

  if (!($options & FIND_ALL))
    $sql_criteria= "($sql_criteria) AND (active AND NOT deleted)";

  $q= "SELECT
              item.id, item.code, item.name,
              brand.id brand_id, brand.name brand,
              retail_price retail_price,
              IF(item.discount_type,
                 CASE item.discount_type
                   WHEN 'percentage' THEN ROUND(retail_price * ((100 - item.discount) / 100), 2)
                   WHEN 'relative' THEN (retail_price - item.discount) 
                   WHEN 'fixed' THEN (item.discount)
                 END,
                 NULL) sale_price,
              item.discount_type, item.discount,
              CASE item.discount_type
                WHEN 'percentage' THEN CONCAT(ROUND(item.discount), '% off')
                WHEN 'relative' THEN CONCAT('$', item.discount, ' off')
              END discount_label,
              (SELECT SUM(allocated) FROM txn_line WHERE item = item.id) stock,
              (SELECT retail_price
                 FROM txn_line JOIN txn ON (txn_line.txn = txn.id)
                WHERE txn_line.item = item.id AND txn.type = 'vendor'
                  AND filled IS NOT NULL
                ORDER BY filled DESC
                LIMIT 1) last_net,
              minimum_quantity,
              GROUP_CONCAT(CONCAT(barcode.code, '!', barcode.quantity)
                           SEPARATOR ',') barcodes,
              active
         FROM item
    LEFT JOIN brand ON (item.brand = brand.id)
    LEFT JOIN barcode ON (item.id = barcode.item)
        WHERE $sql_criteria
     GROUP BY item.id
     ORDER BY 2";

  $r= $db->query($q)
    or die($db->error);

  $items= array();
  while ($item= $r->fetch_assoc()) {
    $item['stock']= (int)$item['stock'];
    $item['minimum_quantity']= (int)$item['minimum_quantity'];

    $barcodes= explode(',', $item['barcodes']);
    $item['barcode']= array();
    foreach ($barcodes as $barcode) {
      list($code, $quantity)= explode('!', $barcode);
      $item['barcode'][$code]= $quantity;
    }

    $items[]= $item;
  }

  return $items;
}

function item_load($db, $id) {
  $q= "SELECT
              item.id, item.code, item.name,
              brand.id brand_id, brand.name brand,
              retail_price retail_price,
              IF(item.discount_type,
                 CASE item.discount_type
                   WHEN 'percentage' THEN ROUND(retail_price * ((100 - item.discount) / 100), 2)
                   WHEN 'relative' THEN (retail_price - item.discount) 
                   WHEN 'fixed' THEN (item.discount)
                 END,
                 NULL) sale_price,
              item.discount_type, item.discount,
              CASE item.discount_type
                WHEN 'percentage' THEN CONCAT(ROUND(item.discount), '% off')
                WHEN 'relative' THEN CONCAT('$', item.discount, ' off')
              END discount_label,
              (SELECT SUM(allocated) FROM txn_line WHERE item = item.id) stock,
              (SELECT retail_price
                 FROM txn_line JOIN txn ON (txn_line.txn = txn.id)
                WHERE txn_line.item = item.id AND txn.type = 'vendor'
                  AND filled IS NOT NULL
                ORDER BY filled DESC
                LIMIT 1) last_net,
              minimum_quantity,
              GROUP_CONCAT(CONCAT(barcode.code, '!', barcode.quantity)
                           SEPARATOR ',') barcodes,
              active
         FROM item
    LEFT JOIN brand ON (item.brand = brand.id)
    LEFT JOIN barcode ON (item.id = barcode.item)
        WHERE item.id = $id
     GROUP BY item.id";

  $r= $db->query($q)
    or die_query($db, $q);

  $item= $r->fetch_assoc();

  if ($item['retail_price'])
    $item['retail_price']= (float)$item['retail_price'];
  if ($item['sale_price'])
    $item['sale_price']= (float)$item['sale_price'];
  if ($item['last_net'])
    $item['last_net']= (float)$item['last_net'];
  if ($item['minimum_quantity'])
    $item['minimum_quantity']= (int)$item['minimum_quantity'];
  if ($item['stock'])
    $item['stock']= (int)$item['stock'];

  $barcodes= explode(',', $item['barcodes']);
  $item['barcode']= array();
  foreach ($barcodes as $barcode) {
    list($code, $quantity)= explode('!', $barcode);
    $item['barcode'][$code]= $quantity;
  }

  return $item;
}
