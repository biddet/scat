<?
require '../scat.php';
require '../lib/item.php';

$q= "SELECT id, code, name,
            (SELECT MIN(created)
               FROM txn
               JOIN txn_line ON txn.id = txn_line.txn_id
              WHERE txn_line.item_id = item.id 
                AND type = 'vendor') first_seen,
            (SELECT SUM(ordered)
               FROM txn_line
              WHERE txn_line.item_id = item.id) stocked,
            (SELECT MAX(paid)
               FROM txn
               JOIN txn_line ON txn.id = txn_line.txn_id
              WHERE txn_line.item_id = item.id 
                AND type = 'customer') last_sale
       FROM item
      WHERE item.active AND NOT item.deleted
        AND minimum_quantity
     HAVING (first_seen < NOW() - INTERVAL 1 YEAR)
        AND (last_sale IS NULL OR last_sale < NOW() - INTERVAL 1 YEAR)
        AND (stocked > 0)
      ORDER BY code";

$r= $db->query($q)
  or die_query($db, $q);

head("Dogs @ Scat", true);
?>
<p>These are in-stock items that have not moved in more than a year.</p>
<div id="results">
  <table class="table table-striped table-hover table-sort">
    <thead>
      <tr>
        <th class="num">#</th>
        <th>Code</th>
        <th>Name</th>
        <th>Last</th>
        <th>Stocked</th>
      </tr>
    </thead>
    <tbody>
<?while ($row= $r->fetch_assoc()) {?>
      <tr>
        <td class="num"><?=++$id?></td>
        <td>
          <a href="/catalog/item/<?=$row['code']?>"><?=ashtml($row['code'])?></a>
        </td>
        <td>
          <?=ashtml($row['name'])?>
        </td>
        <td>
          <?=ashtml($row['last_sale'])?>
        </td>
        <td>
          <?=ashtml($row['stocked'])?>
        </td>
      </tr>
<?}?>
    </tbody>
  </table>
</div>
<?
foot();
?>
