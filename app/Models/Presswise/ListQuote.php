<?php

namespace App\Models\Presswise;

use App\Services\ZohoService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Carbon\Carbon;

// use \com\zoho\crm\api\record\Quotes;
use \com\zoho\crm\api\record\Field;

class ListQuote extends Model
{
    use HasFactory;

    protected $connection = 'presswise_price';
    protected $table = 'list_quote';

    const CREATED_AT = 'created';
    const UPDATED_AT = 'modified';

    const STATUS_NEW = 'new';

    const STATUS_MAP =
    [
        'won' => 'Closed Won',
        'lost' => 'Closed Lost',
        'canceled' => 'On Hold', // ??
        'new' => 'Draft',
        'open' => 'Negotiation',
    ];

    // Set Service Team as the default CSR if one can't be found
    const DEFAULT_CSR = ['id' => '1438057000000087178'];

    public function scopeCreatedSince($query, $ts)
    {
        return $query->where(self::CREATED_AT, '>', $ts);
    }

    public function scopeUpdatedSince($query, $ts)
    {
        return $query->where(self::UPDATED_AT, '>', $ts);
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeDelayed($query)
    {
        return $query->where(self::CREATED_AT, '<=', Carbon::now()->subtract(15, 'minute'));
    }

    // Presswise has quote "revisions" by recording a new record with a revision id with the same quoteID
    // This scope pulls in the first revision on record
    public function scopeFirstRevision($query)
    {
        return $query->where('revision', 1);
    }

    public function quoteItems()
    {
        return $this->hasMany(QuoteItems::class, "quoteItemQuoteID", "quoteID")->orderBy("itemRow");
    }

    public function listCustomer()
    {
        return $this->hasOne(ListCustomer::class, "customerID", "customerID");
    }

    public function csrListSubscriber()
    {
        return $this->hasOne(ListSubscriber::class, "userID", "csrmanID");
    }

    protected function mapStatusToQuoteStage($status)
    {
        return self::STATUS_MAP[$status] ?? 'Draft';
    }

    public function updateZohoRecord(\com\zoho\crm\api\record\Record &$record)
    {
        $record->addFieldValue(new Field('Quote_Stage'), $this->mapStatusToQuoteStage($this->status));
    }

    public function toZoho()
    {
        $record = new \com\zoho\crm\api\record\Record;
        // $record->addFieldValue(new Field('Owner'), array(
        //     'name' => 'David Kovacs',
        //     'id' => '1438057000000083001',
        //     'email' => 'david@allegraprinceton.com',
        // ));
        // $record->addFieldValue(new Field('$currency_symbol'), '$');
        // $record->addFieldValue(new Field('Tax'), 0);
        $record->addFieldValue(new Field('Public_Quote_Notes'), $this->quoteNotes);
        // $record->addFieldValue(new Field('$state'), 'save');
        // $record->addFieldValue(new Field('$converted'), false);
        // $record->addFieldValue(new Field('$process_flow'), false);
        // $record->addFieldValue(new Field('Deal_Name'), NULL);
        // $record->addFieldValue(new Field('URL_1'), NULL);
        // $record->addFieldValue(new Field('Exchange_Rate'), 1);
        $record->addFieldValue(new Field('Confidence'), $this->confidence);
        $record->addFieldValue(new Field('Currency'), 'USD');
        // $record->addFieldValue(new Field('Billing_Country'), NULL);
        // $record->addFieldValue(new Field('id'), '1438057000029929072');
        // $record->addFieldValue(new Field('Carrier'), 'FedEX');
        // $record->addFieldValue(new Field('$approved'), true);
        // $record->addFieldValue(new Field('$approval'), array(
        //     'delegate' => false,
        //     'approve' => false,
        //     'reject' => false,
        //     'resubmit' => false,
        // ));
        // $record->addFieldValue(new Field('Billing_Street'), NULL);
        // $record->addFieldValue(new Field('Adjustment'), 0);
        // $record->addFieldValue(new Field('Created_Time'), \DateTime::__set_state(array(
        //     'date' => '2020-03-11 19:37:19.000000',
        //     'timezone_type' => 3,
        //     'timezone' => 'UTC',
        // )));
        // $record->addFieldValue(new Field('$editable'), true);
        // $record->addFieldValue(new Field('Billing_Code'), NULL);
        $record->addFieldValue(new Field('Customer_PO'), $this->customerPO);
        $grand_total = 0;
        $items = [];
        foreach ($this->quoteItems()->where('quoteItemRevision', $this->revision)->get() as $quote_item) {
            $grand_total += $quote_item->quoteQuantity->grandTotal;

            $items[] = [
                'product' => [
                    'Product_Code' => $quote_item->category, // was productID
                    // 'Currency' => 'USD',
                    'name' => $quote_item->productDescription,
                    'id' => '1438057000052518001', // "custom" product id
                ],
                'quantity' => (float)$quote_item->quoteQuantity->quantity,
                'Discount' => 0,
                // 'total_after_discount' => $quote_item->quoteQuantity->grandTotal,
                // 'net_total' => $quote_item->quoteQuantity->grandTotal,
                // 'book' => NULL,
                'Tax' => 0,
                // Presswise doesn't give list/unit values so we have to calculate
                'list_price' => $quote_item->quoteQuantity->grandTotal / $quote_item->quoteQuantity->quantity,
                'unit_price' => $quote_item->quoteQuantity->grandTotal / $quote_item->quoteQuantity->quantity,
                // 'quantity_in_stock' => -10,
                'total' => $quote_item->quoteQuantity->grandTotal,
                // 'id' => '1438057000029929071',
                'product_description' => $quote_item->productDescription,
                'line_tax' => [],
            ];
        }
        $record->addFieldValue(new Field('Product_Details'), $items);

        // $record->addFieldValue(new Field('Shipping_City'), NULL);
        // $record->addFieldValue(new Field('Shipping_Country'), NULL);
        // $record->addFieldValue(new Field('PW_Select_Subtotal'), NULL);
        // $record->addFieldValue(new Field('Shipping_Code'), NULL);
        // $record->addFieldValue(new Field('Billing_City'), NULL);
        // $record->addFieldValue(new Field('Quote_Number'), '1438057000029929078');
        $record->addFieldValue(new Field('PressWise_Ref_OrderID'), "https://myag1.mypresswise.com/s/cost.php?quoteID={$this->quoteID}.1");
        $record->addFieldValue(new Field('PW_QuoteNo'), $this->quoteID);
        // $record->addFieldValue(new Field('Created_By'), \com\zoho\crm\api\users\User::__set_state(array(
        //     'keyValues' =>
        //     array(
        //         'name' => 'David Kovacs',
        //         'id' => '1438057000000083001',
        //         'email' => 'david@allegraprinceton.com',
        //     ),
        //     'keyModified' =>
        //     array(),
        // )));

        // do the dance to correlate the CSR email to the Zoho User ID
        $zohoCsrField = null;

        if ($zohoCsr = ZohoService::findUserByEmail($this->csrListSubscriber->emailAddress)) {
            $zohoCsrField = ['id' => $zohoCsr->getId()];
        } else {
            // not found; use the default "service" CSR
            $zohoCsrField = self::DEFAULT_CSR;
        }

        $record->addFieldValue(new Field('Customer_Service_Rep'), $zohoCsrField);
        // $record->setCreatedBy($zohoCsr);
        $record->addFieldValue(new Field('Owner'), $zohoCsrField);
        // $record->addFieldValue(new Field('Shipping_Street'), NULL);
        // $record->addFieldValue(new Field('Description'), NULL);
        // $record->addFieldValue(new Field('Discount'), 0);
        // $record->addFieldValue(new Field('Shipping_State'), NULL);
        // $record->addFieldValue(new Field('$review_process'), array(
        //     'approve' => false,
        //     'reject' => false,
        //     'resubmit' => false,
        // ));
        // $record->addFieldValue(new Field('PW_Quote_ID'), NULL);
        // $record->addFieldValue(new Field('Modified_By'), \com\zoho\crm\api\users\User::__set_state(array(
        //     'keyValues' =>
        //     array(
        //         'name' => 'David Kovacs',
        //         'id' => '1438057000000083001',
        //         'email' => 'david@allegraprinceton.com',
        //     ),
        //     'keyModified' =>
        //     array(),
        // )));
        $record->addFieldValue(new Field('Private_Quote_Notes'), $this->quotePrivateNotes);
        // $record->addFieldValue(new Field('$review'), NULL);
        // $record->addFieldValue(new Field('Valid_Till'), NULL);
        $account = ZohoService::findAccountByPWCustomerID($this->customerID);
        $record->addFieldValue(new Field('Account_Name'), ['id' => $account->getId()]);
        // $record->addFieldValue(new Field('Account_Name'), array(
        //     'name' => 'Kovacsco',
        //     'id' => '1438057000001601031',
        // ));
        // $record->addFieldValue(new Field('Team'), NULL);
        $record->addFieldValue(new Field('Quote_Stage'), $this->mapStatusToQuoteStage($this->status));
        if ($this->followUpDate !== '0000-00-00') {
            $record->addFieldValue(new Field('Follow_Up_Date'), $this->followUpDate);
        }
        // $record->addFieldValue(new Field('Modified_Time'), \DateTime::__set_state(array(
        //     'date' => '2021-04-04 16:02:02.000000',
        //     'timezone_type' => 3,
        //     'timezone' => 'UTC',
        // )));
        // $record->addFieldValue(new Field('Terms_and_Conditions'), NULL);
        $record->addFieldValue(new Field('Grand_Total'), $grand_total);
        $record->addFieldValue(new Field('Sub_Total'), $grand_total);
        $record->addFieldValue(new Field('Subject'), $this->quoteName);
        // $record->addFieldValue(new Field('$orchestration'), false);
        $record->addFieldValue(new Field('Contact_Name'), NULL);
        $record->addFieldValue(new Field('Production_Notes'), $this->productionNote);
        // $record->addFieldValue(new Field('Layout'), array(
        //     'name' => 'Standard',
        //     'id' => '1438057000001307057',
        // ));
        // $record->addFieldValue(new Field('$in_merge'), false);
        // $record->addFieldValue(new Field('Billing_State'), NULL);
        // $record->addFieldValue(new Field('$line_tax'), array());
        // $record->addFieldValue(new Field('Tag'), array());
        // $record->addFieldValue(new Field('$approval_state'), 'approved');
        // $record->addFieldValue(new Field('Memo'), NULL);

        return $record;
    }
}
