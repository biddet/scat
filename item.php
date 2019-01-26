<?
require 'scat.php';
require 'lib/item.php';

$code= $_GET['code'];
$id= (int)$_GET['id'];

if (!$code && !$id) exit;

if (!$id && $code) {
  $r= $db->query("SELECT id FROM item WHERE code = '" .
                 $db->real_escape_string($code) . "'");
  if (!$r) die($m->error);

  if (!$r->num_rows)
      die("<h2>No item found.</h2>");

  $id= $r->fetch_row();
  $id= $id[0];
}

$product= array('id' => 0, 'name' => ''); /* Bare necessities for KO */

$item= item_load($db, $id);

if ($item['product_id']) {
  $product= Model::factory('Product')
              ->find_one($item['product_id'])
              ->as_array();
}

$q= "SELECT pattern_type, minimum_quantity, discount_type, discount, expires
       FROM price_override
      WHERE (pattern_type = 'product' AND pattern = {$item['product_id']})
         OR (pattern_type = 'like'  AND '{$item['code']}' LIKE pattern)
         OR (pattern_type = 'rlike' AND '{$item['code']}' RLIKE pattern)
      ORDER BY minimum_quantity";

$r= $db->query($q)
  or die_query($db, $q);

$price_overrides= array();
while (($row= $r->fetch_assoc())) {
  $price_overrides[]= $row;
}

$search= "";

head("Item: " . $item['name']. " @ Scat", true);

include 'item-searchform.php';
?>
<form class="form-horizontal" role="form">
  <div class="row">

    <!-- Basics -->
    <div class="col-md-6">
    <div class="panel panel-default">
      <div class="panel-heading">
        <div class="pull-right">
          <button id="active" type="button" class="btn btn-default btn-xs">
            <i class="far"
               data-bind="css: { 'fa-check-square' : item.active(),
                                'fa-square' : !item.active() }"></i>
            Active
          </button>
          <button id="reviewed" type="button" class="btn btn-default btn-xs">
            <i class="far"
               data-bind="css: { 'fa-check-square' : item.reviewed(),
                                'fa-square' : !item.reviewed() }"></i>
            Reviewed
          </button>
        </div>
        <h3 class="panel-title">Basics</h3>
        <div class="clearfix"></div>
      </div>
      <div class="panel-body">

        <div class="form-group">
          <label for="code" class="col-sm-2 control-label">
            Code
          </label>
          <div class="col-sm-10">
            <p class="form-control-static" id="code"
               data-bind="jeditable: item.code, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="name" class="col-sm-2 control-label">
            Name
          </label>
          <div class="col-sm-10">
            <p class="form-control-static" id="name"
               data-bind="jeditable: item.name, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="brand" class="col-sm-2 control-label">Brand</label>
          <div class="col-sm-10">
            <p class="form-control-static" id="brand_id"
               data-bind="jeditable: item.brand, jeditableOptions: { type: 'select', submit: 'OK', loadurl: 'api/brand-list.php', onupdate: saveItemProperty, onblur: 'cancel', cssclass: 'form-inline' }"></p>
          </div>
        </div>

      </div>
    </div>
    </div>

    <!-- Pricing -->
    <div class="col-md-3">
    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title">Pricing</h3>
      </div>
      <div class="panel-body">
        
        <div class="well">
          <h3 class="text-center" style="margin: 0px; padding: 0px"
              data-bind="text: amount(item.sale_price())">$0.00</h3>
        </div>

        <div class="form-group">
          <label for="retail_price" class="col-sm-4 control-label">List</label>
          <div class="col-sm-8">
            <p class="form-control-static" id="retail_price"
               data-bind="jeditable: item.retail_price, jeditableOptions: { ondisplay: amount, data: item.retail_price(), onupdate: saveItemProperty, onblur: 'cancel' }, css: { 'text-danger': differentVendorPrice() }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="discount" class="col-sm-4 control-label">Discount</label>
          <div class="col-sm-8">
            <p class="form-control-static" id="discount"
               data-bind="jeditable: item.discount, jeditableOptions: { ondisplay: function() { return item.discount_label() ? item.discount_label() : item.discount() ? amount(item.discount()) : '' } , data : item.discount(), onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group"
             data-bind="visible: price_overrides().length">
          <label class="col-sm-4 control-label">Overrides</label>
          <div class="col-sm-8"
               data-bind="foreach: price_overrides">
            <p class="form-control-static">
              <span data-bind="text: $parent.formatDiscount($data.discount_type(), $data.discount())"></span> for <span data-bind="text: $data.minimum_quantity"></span>
            </p>
          </div>
        </div>

      </div>
    </div>
    </div>

    <!-- Inventory -->
    <div class="col-md-3">
    <div class="panel panel-default">
      <div class="panel-heading">
        <div class="pull-right">
          <button class="btn btn-default btn-xs"
                  data-bind="click: function() {
                                      Scat.showNotes({ kind: 'item',
                                                       attach_id: item.id() })
                                    }">
            Notes
            <span id="item-notes" class="badge"></span>
          </button>
        </div>
        <h3 class="panel-title">Inventory</h3>
      </div>
      <div class="panel-body">

        <div class="form-group">
          <label for="stock" class="col-sm-8 control-label">Currently in Stock</label>
          <div class="col-sm-4">
            <p class="form-control-static" id="stock"
               data-bind="jeditable: item.stock, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>
        <div class="form-group">
          <label for="minimum_quantity" class="col-sm-8 control-label">
            Minimum Quantity
          </label>
          <div class="col-sm-4">
            <p class="form-control-static" id="minimum_quantity"
               data-bind="jeditable: item.minimum_quantity, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>
        <div class="form-group">
          <label for="purchase_quantity" class="col-sm-8 control-label">
            Purchase Quantity
          </label>
          <div class="col-sm-4">
            <p class="form-control-static" id="purchase_quantity"
               data-bind="jeditable: item.purchase_quantity, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="inventoried" class="col-sm-8 control-label">
            Inventoried
          </label>
          <div class="col-sm-4">
            <p class="form-control-static" id="inventoried"
               data-bind="text: moment(item.inventoried()).calendar()"></p>
          </div>
        </div>
      </div>
    </div>
    </div>
  </div><!-- /.row -->

  <div class="row">

    <!-- Catalog -->
    <div class="col-md-6">
    <div class="panel panel-default">
      <div class="panel-heading">
        <div class="pull-right">
          <a class="btn btn-default btn-xs"
             data-bind="attr: { href: '<?=ORDURE?>/' + item.code() }"
             target="_blank">
            <i class="fas fa-external-link-alt"></i>
            Website
          </a>
        </div>
        <h3 class="panel-title">Catalog</h3>
        <div class="clearfix"></div>
      </div>
      <div class="panel-body">

        <div class="form-group">
          <label for="product" class="col-sm-4 control-label">
            Product
          </label>
         
          <div class="col-sm-8">
            <p class="form-control-static" id="product_id"
               data-bind="jeditable: product.name, jeditableOptions: { type: 'select2', 'select2' : { ajax: { url: 'api/product-find.php', dataType: 'json' } }, onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="short_name" class="col-sm-4 control-label">
            Short Name
          </label>
          <div class="col-sm-8">
            <p class="form-control-static" id="short_name"
               data-bind="jeditable: item.short_name, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="variation" class="col-sm-4 control-label">
            Variation
          </label>
          <div class="col-sm-8">
            <p class="form-control-static" id="variation"
               data-bind="jeditable: item.variation, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="tic" class="col-sm-4 control-label">
            <abbr class="initialism"
                  title="Taxability Information Code">TIC</abbr>
          </label>
          <div class="col-sm-8">
            <p class="form-control-static" id="tic"
               data-bind="jeditable: item.tic, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <label for="color" class="col-sm-4 control-label">
            Color
            <span
                 data-bind="if: item.color() != '',
                            style: { background: '#' + item.color() }">
              &nbsp; &nbsp; &nbsp;
            </span>
          </label>
          <div class="col-sm-8">
            <p class="form-control-static" id="color"
               data-bind="jeditable: item.color, jeditableOptions: { textType: 'color', onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>

        <div class="form-group">
          <div class="col-sm-12">
            <a class="btn btn-default"
               data-bind="visible: item.product_id(),
                          attr: { href: 'catalog-product.php?id=' +
                                         item.product_id() }">
              <i class="fa fa-cubes"></i> Product
            </a>

            <a class="btn btn-default"
               data-bind="visible: item.product_id(),
                          attr: { href: 'items.php?search=product:' +
                                         item.product_id() }">
              <i class="far fa-clipboard"></i> Product List
            </a>

            <a class="btn btn-default"
               data-bind="visible: item.product_id(),
                          attr: { href: 'report-performance.php?product=' +
                                         item.product_id() }">
              <i class="far fa-chart-bar"></i> Performance
            </a>
          </div>
        </div>

      </div>
    </div>
    </div>

    <div class="col-md-3">

    <!-- Barcodes -->
    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title">Barcodes</h3>
      </div>
      <table id="barcodes" class="table table-striped">
        <tbody data-bind="foreach: item.barcode_list">
          <tr>
            <td><span data-bind="text: $data.code"></span></td>
            <td><span data-bind="text: $data.quantity, jeditable: $data.quantity, jeditableOptions: { onupdate: editBarcodeQuantity, onblur: 'cancel' }"></span></td>
            <td><button type="button" class="btn btn-default btn-xs" data-bind="click: $parent.removeBarcode"><i class="far fa-trash-alt"></i></button></td>
          </tr>
        </tbody>
      </table>

      <div class="panel-footer">
        <button id="new-barcode" class="btn btn-default">
          <i class="fa fa-barcode"></i> New
        </button>
        <div class="btn-group">
          <button type="button" class="btn btn-default"
                  data-bind="click: printBarcode">
            <i class="fa fa-print"></i> Print
          </button>
          <button type="button" class="btn btn-default dropdown-toggle"
                  data-toggle="dropdown" aria-haspopup="true"
                  aria-expanded="false">
            <span class="caret"></span>
            <span class="sr-only">Toggle Dropdown</span>
          </button>
          <ul class="dropdown-menu">
            <li>
              <a data-bind="click: printBarcode" data-multiple="1">
                Multiple
              </a>
            </li>
            <li>
              <a data-bind="click: printBarcode" data-noprice="1">
                No price
              </a>
            </li>
            <li>
              <a data-bind="click: printBarcode"
                 data-multiple="1" data-noprice="1">
                Multiple, no price
              </a>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Properties -->
    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title">Properties</h3>
      </div>

      <div class="panel-body">
        <div class="form-group">
          <label for="weight" class="col-sm-4 control-label">
            Weight (lbs)
          </label>
          <div class="col-sm-8">
            <p class="form-control-static" id="weight"
               data-bind="jeditable: item.weight, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel' }"></p>
          </div>
        </div>
        <div class="form-group">
          <label for="length" class="col-sm-4 control-label">
            Dimensions
          </label>
          <div class="col-sm-8">
            <p class="form-control-static">
              <span id="length"
                 data-bind="jeditable: item.length, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel', style: 'display: inline', width: '4em' }"></span>"
              &times;
              <span id="width"
                 data-bind="jeditable: item.width, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel', style: 'display: inline', width: '4em' }"></span>"
              &times;
              <span id="height"
                 data-bind="jeditable: item.height, jeditableOptions: { onupdate: saveItemProperty, onblur: 'cancel', style: 'display: inline', width: '4em' }"></span>"
            </p>
          </div>
        </div>
        <button id="prop65" type="button" class="btn btn-default"
                data-bind="click: toggleProperty">
          <i class="far"
             data-bind="css: { 'fa-check-square' : item.prop65(),
                              'fa-square' : !item.prop65() }"></i>
          Prop. 65
        </button>
        <button id="hazmat" type="button" class="btn btn-default"
                data-bind="click: toggleProperty">
          <i class="far"
             data-bind="css: { 'fa-check-square' : item.hazmat(),
                              'fa-square' : !item.hazmat() }"></i>
          Hazardous Material
        </button>
        <button id="oversized" type="button" class="btn btn-default"
                data-bind="click: toggleProperty">
          <i class="far"
             data-bind="css: { 'fa-check-square' : item.oversized(),
                              'fa-square' : !item.oversized() }"></i>
          Oversized
        </button>
      </div>
    </div>

    </div>

    <div class="col-sm-3">
      <div class="panel panel-default">
        <div class="panel-heading">
          <h3 class="panel-title">Sales</h3>
        </div>
        <div class="panel-body">

          <div class="form-group">
            <label for="quarter" class="col-sm-6 control-label">
             Last 3 Months
            </label>
            <div class="col-sm-6">
              <p class="form-control-static" id="quarter">
                <span data-bind="text: Scat.amount(sales.last3()[0])">$0.00</span>
                (<span data-bind="text: sales.last3()[1]">0</span>)
              </p>
            </div>
          </div>

          <div class="form-group">
            <label for="year" class="col-sm-6 control-label">
             Last Year
            </label>
            <div class="col-sm-6">
              <p class="form-control-static" id="year">
                <span data-bind="text:Scat.amount(sales.last12()[0])">$0.00</span>
                (<span data-bind="text: sales.last12()[1]">0</span>)
              </p>
            </div>
          </div>

        </div>
      </div>
    </div>

  </div><!-- /.row -->

</form>
<script>
$('#active').on('click', function(ev) {
  ev.preventDefault();
  var item= itemModel.item;

  Scat.api('item-update', { item: item.id(), active: item.active() ? 0 : 1 })
      .done(function (data) {
        loadItem(data);
      });
});
$('#reviewed').on('click', function(ev) {
  ev.preventDefault();
  var item= itemModel.item;

  Scat.api('item-update',
           { item: item.id(), reviewed: item.reviewed() ? 0 : 1 })
      .done(function (data) {
        loadItem(data);
      });
});
function editBarcodeQuantity(value, settings) {
  var item= itemModel.item;
  var row= $(this).closest('tr');
  var code= $('td:nth(0)', row).text();

  Scat.api('item-barcode-update',
           { item: item.id, code: code, quantity: value})
      .done(function (data) {
        loadItem(data);
      });
}
$('#new-barcode').editable(function(value, settings) {
  var item= itemModel.item;
  Scat.api('item-barcode-update',
            { item: item.id, code: value })
      .done(function (data) {
        loadItem(data);
      });
  return  $(this).data('original');
}, {
  event: 'click',
  cssclass: 'form-inline',
  placeholder: '',
  data: function(value, settings) {
    $(this).data('original', value);
    return '';
  },
});
</script>
<?
$vendor_items= item_load_vendor_items($db, $id);
?>
<div class="panel-group" id="accordion" role="tablist" aria-multiselectable="true">
  <div class="panel panel-default">
    <div class="panel-heading" role="tab" id="vendorsHeader">
      <a class="accordion-toggle collapsed" role="button" data-toggle="collapse" href="#vendors" aria-expanded="false" aria-controls="vendors">
        <h4 class="panel-title">Vendors</h4>
      </a>
    </div>
    <div id="vendors" class="panel-collapse collapse" role="tabpanel" aria-labelledby="vendorsHeader">
<table id="vendors" class="table table-striped table-hover">
  <thead>
    <tr>
      <th></th>
      <th>Company</th>
      <th>Code</th>
      <th>List</th>
      <th>Net</th>
      <th>Promo</th>
      <th>Sale</th>
      <th>Special?</th>
      <th>Quantity</th>
    </tr>
  </thead>
  <tfoot>
    <tr>
      <td colspan="9">
        <button class="btn btn-primary" data-bind="click: editVendorItem">
          Add Vendor Item
        </button>
        <button class="btn btn-primary" data-bind="click: findVendorItem">
          Find Vendor Items
        </button>
      </td>
    </tr>
  </tfoot>
  <tbody data-bind="foreach: vendor_items">
    <tr>
      <td>
        <button type="button" class="btn btn-default btn-xs"
                data-bind="click: $parent.editVendorItem">
          <i class="fa fa-edit"></i>
        </button>
        <button type="button" class="btn btn-default btn-xs"
                data-bind="click: $parent.detachVendorItem">
          <i class="fa fa-unlink"></i>
        </button>
      </td>
      <td data-bind="text: $data.vendor_name"></td>
      <td data-bind="text: $data.code"></td>
      <td data-bind="text: amount($data.retail_price())"></td>
      <td data-bind="text: amount($data.net_price())"></td>
      <td data-bind="text: amount($data.promo_price())"></td>
      <td data-bind="text: amount($data.net_price() / 0.6) + ' - ' + amount($data.net_price() / 0.5)"></td>
      <td><i class="far" data-bind="css: { 'fa-check-square': $data.special_order() == '1', 'fa-square': $data.special_order() == '0' }"></i></td>
      <td data-bind="text: $data.purchase_quantity"></td>
    </tr>
  </tbody>
</table>
    </div>
  </div>
  <div class="panel panel-default">
    <div class="panel-heading" role="tab" id="historyHeader">
      <a class="accordion-toggle collapsed" role="button" data-toggle="collapse" href="#history" aria-expanded="false" aria-controls="history">
        <h4 class="panel-title">History</h4>
      </a>
    </div>
    <div id="history" class="panel-collapse collapse collapsed" role="tabpanel" aria-labelledby="historyHeader">
<?

function RunningTotal($row) {
  static $count= 0;
  $count= $count + $row[4];
  return $count;
}

$q= "SELECT DATE_FORMAT(created, '%a, %b %e %Y %H:%i') Date,
            CONCAT(txn, '|', txn.type, '|', txn.number) AS Transaction\$txn,
            CASE type
              WHEN 'customer' THEN IF(SUM(allocated) <= 0, 'Sale', 'Return')
              WHEN 'vendor' THEN 'Stock'
              WHEN 'correction' THEN 'Correction'
              WHEN 'drawer' THEN 'Till Count'
              ELSE type
            END Type,
            AVG(sale_price(retail_price, discount_type, discount))
              AS Price\$dollar,
            SUM(allocated) AS Quantity\$right
       FROM txn_line
       JOIN txn ON (txn_line.txn = txn.id)
      WHERE item = $id
      GROUP BY txn
      ORDER BY created";

dump_table($db->query($q), 'RunningTotal$right');
dump_query($q);
?>
    </div>
  </div>
</div>
<?

$q= "SELECT DATE(created) AS x,
            SUM(ABS(allocated)) AS y
       FROM txn_line
       JOIN txn ON (txn_line.txn = txn.id)
      WHERE item = $id AND type = 'customer'
      GROUP BY created
      ORDER BY created";

$r= $db->query($q);

$data= "[";
while ($row= $r->fetch_assoc()) {
  $data.= "{ x: '{$row['x']}', y: {$row['y']} },";
}
$data.= "]";
?>
<div class="container">
  <div class="chart-container" style="position: relative">
   <canvas id="sales-chart"></canvas>
  </div>
</div>
<script>
$(function() {

var data= {
  datasets: [{
    label: 'Sales',
    data: <?=$data?>
  }]
};

var options= {
  legend: {
    display: false,
  },
  scales: {
    xAxes: [{
      type: 'time',
      time: {
        unit: 'day',
        stepSize: 90
      }
    }]
  }
};

var salesChart= new Chart(document.getElementById('sales-chart'), {
                               type: 'bar',
                               data: data,
                               options: options
                         });

});
</script>
<button type="button" class="btn btn-default" data-bind="click: mergeItem">
  Merge
</button>
<?


foot();
?>
<script>
var model= {
  search: '<?=ashtml($search);?>',
  all: <?=(int)$all?>,
  item: <?=json_encode($item);?>,
  product: <?=json_encode($product);?>,
  vendor_items: <?=json_encode($vendor_items);?>,
  price_overrides: <?=json_encode($price_overrides);?>,
  brands: [],
  sales: { last3: [ 0.00, 0], last12: [ 0.00, 0 ] },
};

var itemModel= ko.mapping.fromJS(model);

itemModel.differentVendorPrice= function() {
  var ret= false;
  $.each(itemModel.vendor_items(), function (i, vi) {
    if (vi.retail_price() != 0 &&
        vi.retail_price() != itemModel.item.retail_price()) {
      ret= true;
    }
  });
  return ret;
}

itemModel.printBarcode= function(place, ev) {
  var item= itemModel.item.id();

  var noprice= $(ev.target).data('noprice') || 0;

  var qty= 1;
  if ($(ev.target).data('multiple')) {
    qty= window.prompt("How many?", "1");
  }

  if (!qty)
    return;

  Scat.printDirect('labels-price',
                   { id: item, noprice: noprice, quantity: qty });
}

itemModel.removeBarcode= function(place) {
  Scat.api('item-barcode-delete', { item: itemModel.item.id, code: place.code })
      .done(function (data) {
        loadItem(data);
      });
}

itemModel.mergeItem= function(place) {
  var code= window.prompt("Please enter the item to merge this one into.", "");

  if (code) {
    Scat.api('item-merge', { from: itemModel.item.id, to: code })
        .done(function (data) {
          loadItem(data);
        });
  }
}

itemModel.editVendorItem= function(item) {
  Scat.dialog('item-vendor-item').done(function (html) {
    var panel= $(html);

    var vendorItem= item.vendor_sku ? ko.mapping.toJS(item) :
                    { id: 0, vendor: 0, item: item.item.id(),
                      vendor_sku: item.item.code(), name: item.item.name(),
                      retail_price: item.item.retail_price(),
                      net_price: 0.00, promo_price: null,
                      purchase_quantity: 1, barcode: null };

    vendorItem.vendors= [];
    vendorItem.error= '';

    panel.on('shown.bs.modal', function() {
      $(".initial-focus", this).focus();
    });

    panel.on('hidden.bs.modal', function() {
      $(this).remove();
    });

    Scat.api('person-list', { role: 'vendor' })
        .done(function (data) {
          ko.mapping.fromJS({ vendors: data }, vendorItemModel);
          vendorItemModel.vendor.valueHasMutated();
        })
        .fail(function (jqxhr, textStatus, error) {
          var data= $.parseJSON(jqxhr.responseText);
          vendor_item.error(textStatus + ', ' + error + ': ' + data.text)
        });


    vendorItemModel= ko.mapping.fromJS(vendorItem);

    vendorItemModel.saveItem= function(place, ev) {
      var vendorItem= ko.mapping.toJS(vendorItemModel);
      delete vendorItem.vendors;
      delete vendorItem.error;

      Scat.api(vendorItem.id ? 'vendor-item-update' : 'vendor-item-add',
               vendorItem)
          .done(function (data) {
            $(place).closest('.modal').modal('hide');
            if (data.vendor_item) {
              for (var i= 0; i < itemModel.vendor_items().length; i++) {
                if (data.vendor_item.id === itemModel.vendor_items()[i].id()) {
                  ko.mapping.fromJS(data.vendor_item, {},
                                    itemModel.vendor_items()[i]);
                  return;
                }
              }
              itemModel.vendor_items.push(ko.mapping.fromJS(data.vendor_item));
            }
          });
    }

    vendorItemModel.selectedVendor= ko.computed({
      read: function () {
        return this.vendor();
      },
      write: function (value) {
        if (typeof value != 'undefined' && value != '') {
          this.vendor(value);
        }
      },
      owner: vendorItemModel
    }).extend({ notify: 'always' });
 

    ko.applyBindings(vendorItemModel, panel[0]);
    panel.appendTo($('body')).modal();
  });
}

itemModel.detachVendorItem= function(item) {
  Scat.api('vendor-item-update', { id: item.id, item: 0 })
      .done(function (data) {
        itemModel.vendor_items.remove(function (item) {
           return item.id() == data.vendor_item.id;
        });
      });
}

itemModel.findVendorItem= function() {
  Scat.api('item-find-vendor-items', { id: this.item.id() })
      .done(function (data) {
        ko.mapping.fromJS(data, itemModel);
      });
}

itemModel.formatDiscount= function(discount_type, discount) {
  var val= parseFloat(discount).toFixed(2);
  switch (discount_type) {
    case 'percentage':
      return val + '%';

    case 'additional_percentage':
      return '+' + val + '%';

    case 'relative':
      return '-' + Scat.amount(val);

    case 'fixed':
      return Scat.amount(val);
  }
  return "???";
}

itemModel.toggleProperty= function(obj, ev) {
  item= this.item;
  prop= ev.target.id;
  data= { item: item.id() };
  data[prop]= item[prop]() ? 0 : 1;

  Scat.api('item-update', data)
      .done(function (data) {
        loadItem(data);
      });
}

ko.applyBindings(itemModel);

function loadItem(data) {
  ko.mapping.fromJS(data, itemModel);
}

function saveItemProperty(value, settings) {
  var item= itemModel.item;
  var data= { item: item.id() };
  var key= this.id;
  data[key]= value;

  item[key]("\0"); // force knockout to update this observable when item updated
  if (key == 'product_id' && itemModel.product.name) { // hack for product
    itemModel.product.name("\0");
  }

  Scat.api('item-update', data)
      .done(function (data) {
        loadItem(data);
      });

  return '<span><i class="fa fa-spinner fa-spin"></i></span>';
}

Scat.api('note-count', { kind: 'item', attach_id: model.item.id })
    .done(function(data) {
      $('#item-notes').text(data.notes);
    });

$(function() {
  Scat.api('item-sales', { id: model.item.id, days: 90 })
      .done(function(data) {
        itemModel.sales.last3([ data.sales.amount, data.sales.items ]);
      });

  Scat.api('item-sales', { id: model.item.id, days: 365 })
      .done(function(data) {
        itemModel.sales.last12([ data.sales.amount, data.sales.items ]);
      });
});

</script>
