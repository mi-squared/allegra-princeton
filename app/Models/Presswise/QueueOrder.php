<?php

namespace App\Models\Presswise;

use Illuminate\Database\Eloquent\Model;

use \com\zoho\crm\api\record\Field;
use \com\zoho\crm\api\util\Choice;
use \com\zoho\crm\api\record\InventoryLineItems;
use \com\zoho\crm\api\record\LineItemProduct;
use App\Services\ZohoService;


class QueueOrder extends Model
{
    protected $connection = 'presswise_order';
    protected $table = 'queue_order';
    protected $primaryKey = 'orderID';

    const CREATED_AT = 'createDate';
    const UPDATED_AT = 'lastModified';

    protected $casts = [
        'dueDate' => 'datetime',
        'subTotal' => 'double',
        'taxCost' => 'double',
    ];

    const STATUS_MAP =
    [
        'New' => '',
        'On Hold' => '',
        'Files Needed' => '',
        'Prepress Review' => '',
        'AUTO Proof Pending' => '',
        'Waiting Approval' => '',
        'Payment Pending' => '',
        'Ready' => '',
        'AUTO New' => '',
        'AUTO Artwork Pending' => '',
        'AUTO Artwork Exception' => '',
        'AUTO Data Pending' => '',
        'AUTO Data Exception' => '',
        'AUTO Payment Pending' => '',
        'AUTO Payment Exception' => '',
        'AUTO Ready Pending' => '',
        'AUTO Ready Exception' => '',
        'Production' => '',
        'Shipped' => '',
        'Closed' => '',
        'Cancelled' => '',
    ];

    // public function quoteQuantity()
    // {
    //     return $this->hasOne(QuoteQuantities::class, "quoteQuantItemRow")->ofMany("quoteQuantID", "min");
    // }

    public function queueJobs()
    {
        return $this->hasMany(QueueJob::class, "orderID", "orderID");
    }

    public function scopeCreatedSince($query, $ts)
    {
        return $query->where(self::CREATED_AT, '>', $ts);
    }

    public function scopeUpdatedSince($query, $ts)
    {
        return $query->where(self::UPDATED_AT, '>', $ts);
    }

    public function updateZohoRecord(\com\zoho\crm\api\record\Record &$record)
    {
        $record->addFieldValue(new Field('Status'), new Choice($this->status));
    }

    public function toZoho()
    {
        $record = new \com\zoho\crm\api\record\Record;

        $record->addFieldValue(new Field('Status'), new Choice($this->status));
        $layout = new \com\zoho\crm\api\layouts\Layout();
        $layout->setId('1438057000029934001');
        $record->addFieldValue(new Field('Layout'), $layout);

        // $record->addFieldValue(new Field(''), $this->webID);

        $record->addFieldValue(new Field('Purchase_Order'), $this->customerPO);
        $record->addFieldValue(new Field('Subject'), $this->quoteName);
        // $record->addFieldValue(new Field(''), $this->btTerms);
        // $record->addFieldValue(new Field('Owner'), $this->salesmanID);
        // $record->addFieldValue(new Field(''), $this->csrmanid);
        // $record->addFieldValue(new Field(''), $this->prepressid);
        // $record->addFieldValue(new Field(''), $this->designerID);
        // $record->addFieldValue(new Field(''), $this->createDate);
        // $record->addFieldValue(new Field('Invoice_Number'), $this->orderID);
        $record->addFieldValue(new Field('PW_Order_ID'), $this->orderID);

        if ($this->dueDate) {
            $record->addFieldValue(new Field('Due_Date'), $this->dueDate->toDate());
        }
        // $record->addFieldValue(new Field('Contact_Name'), $this->userID); // TODO
        $record->addFieldValue(new Field('Invoice_Date'), $this->salesDate);

        $account = ZohoService::findAccountByPWCustomerID($this->customerID);
        // mark the account name and id as modified so it'll get sent during the request
        $account->addFieldValue(new Field('Account_Name'), $account->getKeyValue('Account_Name'));
        $account->setId($account->getId());
        $record->addFieldValue(new Field('Account_Name'), $account);

        $record->addFieldValue(new Field('PW_URL'), "https://myag1.mypresswise.com/o/order.php?orderID={$this->orderID}");

        // $record->addFieldValue(new Field(''), $this->invoiceNote);
        // $record->addFieldValue(new Field(''), $this->shipNote);
        // $record->addFieldValue(new Field(''), $this->groupID);
        $record->addFieldValue(new Field('Sub_Total'), $this->subTotal);
        // $record->addFieldValue(new Field(''), $this->shipCost);

        // TODO add rush cost to adjustment
        $record->addFieldValue(new Field('Tax'), $this->taxCost);

        $grand_total = 0;
        $items = [];

        foreach ($this->queueJobs()->get() as $line_item) {
            $grand_total += $line_item->price;

            $row = new InventoryLineItems();
            $product = new LineItemProduct();
            $product->setProductCode($line_item->category);
            $product->setName($line_item->productDesc);
            $product->setId('1438057000052518001');

            $row->setProduct($product);
            $row->setQuantity($line_item->quantity);
            $row->setListPrice($line_item->price / $line_item->quantity);
            $row->setUnitPrice($line_item->price / $line_item->quantity);
            $row->setTotal($line_item->price);
            $row->setProductDescription($line_item->productDesc);

            $items[] = $row;
            //     'total' => $line_item->price,
            //     'product_description' => $line_item->productDesc,


            // $items[] = [
            //     'product' => [
            //         'Product_Code' => $line_item->category, // was productID
            //         // 'Currency' => 'USD',
            //         'name' => $line_item->productDesc,
            //         'id' => '1438057000052518001', // "custom" product id
            //     ],
            //     'quantity' => (float)$line_item->quantity,
            //     'Discount' => 0,
            //     // 'total_after_discount' => $line_item->price,
            //     // 'net_total' => $line_item->price,
            //     // 'book' => NULL,
            //     'Tax' => 0,
            //     // Presswise doesn't give list/unit values so we have to calculate
            //     'list_price' => $line_item->price / $line_item->quantity,
            //     'unit_price' => $line_item->price / $line_item->quantity,
            //     // 'quantity_in_stock' => -10,
            //     'total' => $line_item->price,
            //     // 'id' => '1438057000029929071',
            //     'product_description' => $line_item->productDesc,
            //     'line_tax' => [],
            // ];
        }
        $record->addFieldValue(new Field('Product_Details'), $items);
        $record->addFieldValue(new Field('PW_Subtotal'), $grand_total);
        /*
        'Product_Details' =>
    array (
      0 =>
      com\zoho\crm\api\record\InventoryLineItems::__set_state(array(
         'keyValues' =>
        array (
          'product' =>
          com\zoho\crm\api\record\LineItemProduct::__set_state(array(
             'keyValues' =>
            array (
              'Product_Code' => NULL,
              'Currency' => 'USD',
              'name' => 'Admissions Event Magnets Spring 2020',
              'id' => '1438057000029934270',
            ),
             'keyModified' =>
            array (
            ),
          )),
          'quantity' => 1.0,
          'Discount' => '0',
          'total_after_discount' => 1939.5,
          'net_total' => 1939.5,
          'book' => NULL,
          'Tax' => 0.0,
          'list_price' => 1939.5,
          'unit_price' => NULL,
          'quantity_in_stock' => -1.0,
          'total' => 1939.5,
          'id' => '1438057000029934281',
          'product_description' => '',
          'line_tax' =>
          array (
          ),
        ),
         'keyModified' =>
        array (
        ),
      )),
    ),
        */
        // ### Fields ###

        // PWPaymentBalance :
        // Tax : 0
        // $followers :
        // $process_flow :
        // Billing_Country :
        // Billing_Street :
        // Adjustment : 0
        // $followed :
        // Billing_Code :
        // Excise_Duty :
        // Shipping_City :
        // Shipping_Country :
        // Shipping_Code :
        // Billing_City :
        // Purchase_Order :
        // Shipping_Street :
        // Description :
        // Discount : 0
        // Shipping_State :
        // $review :
        // Sales_Commission :
        // Due_Date :
        // Terms_and_Conditions :
        // $orchestration :
        // $in_merge :
        // Billing_State :
        // $line_tax

        /*### InventoryLineItems ###

        Record ProductDetails LineItemProduct ProductCode:
        Record ProductDetails LineItemProduct Currency: USD
        Record ProductDetails LineItemProduct Name: Admissions Event Magnets Spring 2020
        Record ProductDetails LineItemProduct Id: 1438057000029934270
        Record ProductDetails Quantity: 1
        Record ProductDetails Discount: 0
        Record ProductDetails TotalAfterDiscount: 1939.5
        Record ProductDetails NetTotal: 1939.5
        Record ProductDetails Tax: 0
        Record ProductDetails ListPrice: 1939.5
        Record ProductDetails UnitPrice:
        Record ProductDetails QuantityInStock: -1
        Record ProductDetails Total: 1939.5
        Record ProductDetails ID: 1438057000029934281
        Record ProductDetails ProductDescription:
        */

        return $record;
    }
}
