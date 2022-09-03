"use strict";

class ScatWeb {
  // format number as $3.00 or ($3.00)
  amount (val) {
    if (typeof(val) == 'function') {
      val= val()
    }
    if (typeof(val) == 'undefined' || val == null) {
      return ''
    }
    if (typeof(val) == 'string') {
      val= parseFloat(val)
    }
    if (val < 0.0) {
      return '($' + Math.abs(val).toFixed(2) + ')'
    } else {
      return '$' + val.toFixed(2)
    }
  }

  ecommerce (event, parameters) {
    let func = () => {
      if (window.zaraz) {
        window.zaraz.ecommerce(event, parameters)
      }

      if (window.analytics) {
        switch (event) {
          case 'Product List Viewed':
            window.analytics.track('Product List Viewed', {
              'list_id':  parameters.name,
              'products': parameters.products,
            });
            break;

          case 'Product Added':
          case 'Product Removed':
            /* Shouldn't really see these, we use trackLink/trackForm */
          case 'Product Viewed':
          case 'Cart Viewed':
            window.analytics.track(event, parameters);
            break;

          case 'Checkout Started':
          case 'Order Completed':
            window.analytics.track(event, {
              'checkout_id': parameters.order_id,
              'order_id': parameters.order_id,
              'value': parameters.subtotal,
              'revenue': parameters.subtotal,
              'shipping': parameters.shipping,
              'tax': parameters.tax,
              'currency': parameters.currency,
              'products': parameters.products,
            });
            break;
        }
        return /* analytics.js actually handles everything */
      }

      if (window.uetq) {
        switch (event) {
          case 'Product Added':
            window.uetq.push('event', 'add_to_cart', {
              'ecomm_prodid': parameters.product_id,
              'ecomm_pagetype': 'product',
              'ecomm_totalvalue': parameters.price * parameters.quantity,
              'revenue_value': parameters.price * parameters.quantity,
              'currency': parameters.currency,
              'items': [
                {
                  'id': parameters.product_id,
                  'quantity': parameters.quantity,
                  'price': parameters.price,
                },
              ]
            });
            break;

          case 'Product Viewed':
            window.uetq.push('event', '', {
              'ecomm_prodid': parameters.product_id,
              'ecomm_pagetype': 'product',
            });
            break;

          case 'Cart Viewed': {
            let item_ids= parameters.products.map(x => x.product_id);
            let items= parameters.products.map((x) => ({
              'id' : x.product_id,
              'quantity' : x.quantity,
              'price' : x.price,
            }));

            window.uetq.push('event', '', {
              'ecomm_prodid' : item_ids,
              'ecomm_pagetype' : 'cart',
              'ecomm_totalvalue' : parameters.total,
              'revenue_value' : parameters.total,
              'currency' : parameters.currency,
              'items' : items,
            });
            break;
          }

          case 'Checkout Started':
            window.uetq.push('event', 'begin_checkout', {
              'revenue_value' : parameters.total,
              'currency' : parameters.currency,
            });
            break;

          case 'Order Completed':
            window.uetq.push('event', 'purchase', {
              'revenue_value' : parameters.total,
              'currency' : parameters.currency,
            });
            break;

          case 'Product List Viewed': {
            let item_ids= parameters.products.map(x => x.product_id);
            window.uetq.push('event', '', {
              'ecomm_category' : parameters.name,
              'ecomm_prodid' : item_ids,
              'ecomm_pagetype' : 'category',
            });
            break;
          }

          case 'Products Searched':
            /* Not used yet because of how our search works. */

          default:
            console.log(`No handling for ${event} with Bing, ignoring`)
        }
      }

      if (window.pintrk) {
        switch (event) {
          case 'Product Added':
            window.pintrk('track', 'addtocart', {
              'currency': parameters.currency,
              'line_items': [
                {
                  'product_id': parameters.product_id,
                  'product_quantity': parameters.quantity,
                  'product_price': parameters.price,
                },
              ]
            });
            break;

          case 'Product Viewed':
            window.pintrk('track', 'pagevisit', {
              'product_id': parameters.product_id,
            });
            break;

          case 'Order Completed':
            let items= parameters.products.map((x) => ({
              'product_id' : x.product_id,
              'product_quantity' : x.quantity,
              'product_price' : x.price,
            }));
            window.pintrk('track', 'checkout', {
              'value' : parameters.total,
              'currency' : parameters.currency,
              'line_items' : items,
            });
            break;

          case 'Cart Viewed':
          case 'Product List Viewed':
          case 'Products Searched':

          default:
            console.log(`No handling for ${event} with Pinterest, ignoring`)
        }
      }
    }

    if (window.document.readyState == 'complete') {
      func()
    } else {
      window.addEventListener('load', (event) => {
        func()
      })
    }
  }
}

let scat= new ScatWeb()
