<?php

namespace App\Services;

use com\zoho\crm\api\record\RecordOperations;
use com\zoho\crm\api\HeaderMap;
use com\zoho\crm\api\Param;
use com\zoho\crm\api\ParameterMap;
use com\zoho\crm\api\users\APIException;
use com\zoho\crm\api\record\BodyWrapper;
use com\zoho\crm\api\record\GetRecordsHeader;
use com\zoho\crm\api\record\GetRecordsParam;
use com\zoho\crm\api\record\ResponseWrapper;
use com\zoho\crm\api\record\SearchRecordsParam;
use com\zoho\crm\api\record\Quotes;
use com\zoho\crm\api\users\UsersOperations;
use com\zoho\crm\api\users\GetUsersHeader;
use com\zoho\crm\api\users\GetUserHeader;
use com\zoho\crm\api\users\GetUsersParam;
use com\zoho\crm\api\record\ActionWrapper;
use com\zoho\crm\api\record\SuccessResponse;
use com\zoho\crm\api\record\FileDetails;
use com\zoho\crm\api\util\Choice;
use com\zoho\crm\api\record\InventoryLineItems;
use com\zoho\crm\api\tags\Tag;
use com\zoho\crm\api\record\PricingDetails;
use com\zoho\crm\api\record\Participants;
use com\zoho\crm\api\record\Comment;
use com\zoho\crm\api\record\LineTax;
use com\zoho\crm\api\attachments\Attachment;
use com\zoho\crm\api\layouts\Layout;
use com\zoho\crm\api\record\RemindAt;
use com\zoho\crm\api\record\RecurringActivity;
use com\zoho\crm\api\record\Consent;

use com\zoho\crm\api\record\Record;
use com\zoho\crm\api\users\User;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ZohoRecordNotFoundException extends \Exception
{
}

class ZohoService
{
    /**
     * Handle Zoho Response
     * Zoho has a weird anti-pattern response format. This method does its best to make sense of it.
     */
    protected static function handleResponse($response, $statusCodes = [200])
    {
        if ($response != null) {
            if (!in_array($response->getStatusCode(), $statusCodes)) {
                if ($response->getStatusCode() === 204) {
                    throw new ZohoRecordNotFoundException("Zoho record not found");
                } else {
                    throw new \RuntimeException("Zoho API call failed with status code: {$response->getStatusCode()}");
                }
            }

            if ($response->isExpected()) {
                $actionHandler = $response->getObject();

                if ($actionHandler instanceof ActionWrapper) {
                    //Get the received ResponseWrapper instance
                    $actionWrapper = $actionHandler;

                    //Get the list of obtained ActionResponse instances
                    $actionResponses = $actionWrapper->getData();

                    foreach ($actionResponses as $actionResponse) {
                        //Check if the request is successful
                        if ($actionResponse instanceof SuccessResponse) {
                            return $actionResponse;
                        }
                        //Check if the request returned an exception
                        else if ($actionResponse instanceof APIException) {
                            throw $actionResponse;
                        }
                    }
                }
                //Check if the request returned an exception
                else if ($actionHandler instanceof APIException) {
                    throw $actionHandler;
                }
            } else {
                throw new RuntimeException('Unexpected response from Zoho API');
            }
        }
    }

    public static function saveQuote(\com\zoho\crm\api\record\Record $record)
    {
        return self::saveRecord("Quotes", $record);
    }

    public static function saveRecord($module, \com\zoho\crm\api\record\Record $record)
    {
        // modified from https://github.com/mi-squared/zohocrm-php-sdk/blob/master/samples/src/com/zoho/crm/api/record/Record.php#L1828
        //Get instance of RecordOperations Class that takes moduleAPIName as parameter
        $recordOperations = new RecordOperations();

        //Get instance of BodyWrapper Class that will contain the request body
        $bodyWrapper = new BodyWrapper();

        //List of Record instances
        $records = [$record];

        //Set the list to Records in BodyWrapper instance
        $bodyWrapper->setData($records);

        //Call createRecords method that takes BodyWrapper instance as parameter.
        return self::handleResponse($recordOperations->createRecords($module, $bodyWrapper), [200, 201]);
    }

    /**
     * <h3> Update Record</h3>
     * This method is used to update a single record of a module with ID and print the response.
     * @param moduleAPIName - The API Name of the record's module.
     * @param recordId - The ID of the record to be obtained.
     * @throws Exception
     */
    public static function updateRecord(string $moduleAPIName, \com\zoho\crm\api\record\Record $record)
    {
        //Get instance of RecordOperations Class
        $recordOperations = new RecordOperations();

        //Get instance of BodyWrapper Class that will contain the request body
        $request = new BodyWrapper();

        //Set the list to Records in BodyWrapper instance
        $request->setData([$record]);

        // $trigger = array("approval", "workflow", "blueprint");

        // $request->setTrigger($trigger);

        //Call updateRecord method that takes BodyWrapper instance, ModuleAPIName and recordId as parameter.
        $response = $recordOperations->updateRecord($record->getId(), $moduleAPIName, $request);

        return self::handleResponse($response);
    }

    public static function findRecords($moduleAPIName, ParameterMap $paramInstance, HeaderMap $headerInstance)
    {
        $recordOperations = new RecordOperations();

        $paramInstance = new ParameterMap();
        $paramInstance->add(GetRecordsParam::approved(), "both");

        $headerInstance = new HeaderMap();

        // $ifmodifiedsince = date_create("2020-06-02T11:03:06+05:30")->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        // $headerInstance->add(GetRecordsHeader::IfModifiedSince(), $ifmodifiedsince);

        //Call getRecord method that takes paramInstance, moduleAPIName as parameter
        return $recordOperations->getRecords($moduleAPIName, $paramInstance, $headerInstance);
    }

    // PW_Order_ID
    public static function findInvoiceByPW_Order_ID($orderID)
    {
        $moduleAPIName = "Invoices";

        //Get instance of RecordOperations Class that takes moduleAPIName as parameter
        $recordOperations = new RecordOperations();
        $paramInstance = new ParameterMap();
        $paramInstance->add(SearchRecordsParam::criteria(), "((PW_Order_ID:equals:{$orderID}))");

        $response = $recordOperations->searchRecords($moduleAPIName, $paramInstance);

        if (
            $response != null
        ) {
            if ($response->getStatusCode() === 204) {
                throw new ZohoRecordNotFoundException("Presswise invoice {$orderID} not found in Zoho");
            }

            if ($response->getStatusCode() != 200) {
                throw new \RuntimeException("Zoho Search Invoices failed with status code: {$response->getStatusCode()}");
            }

            if ($response->isExpected()) {
                //Get the object from response
                $responseHandler = $response->getObject();

                if ($responseHandler instanceof ResponseWrapper) {
                    $responseWrapper = $responseHandler;

                    //Get the obtained Record instance
                    $records = $responseWrapper->getData();

                    foreach ($records as $record) {
                        return $record;
                    }
                }
                //Check if the request returned an exception
                else throw $responseHandler;
            } else {
                throw new \RuntimeException("Zoho Search Account response not expected");
            }
        }
    }

    public static function findQuoteByPW_QuoteNo($pwQuoteNo)
    {
        $moduleAPIName = "Quotes";

        //Get instance of RecordOperations Class that takes moduleAPIName as parameter
        $recordOperations = new RecordOperations();
        $paramInstance = new ParameterMap();
        $paramInstance->add(SearchRecordsParam::criteria(), "((PW_QuoteNo:equals:{$pwQuoteNo}))");

        $response = $recordOperations->searchRecords($moduleAPIName, $paramInstance);

        if ($response != null) {
            if ($response->getStatusCode() === 204) {
                throw new ZohoRecordNotFoundException("Presswise quote {$pwQuoteNo} not found in Zoho");
            }

            if ($response->getStatusCode() != 200) {
                throw new \RuntimeException("Zoho Search Accounts failed with status code: {$response->getStatusCode()}");
            }

            if ($response->isExpected()) {
                //Get the object from response
                $responseHandler = $response->getObject();

                if ($responseHandler instanceof ResponseWrapper) {
                    $responseWrapper = $responseHandler;

                    //Get the obtained Record instance
                    $records = $responseWrapper->getData();

                    foreach ($records as $record) {
                        return $record;
                    }
                }
                //Check if the request returned an exception
                else throw $responseHandler;
            } else {
                throw new \RuntimeException("Zoho Search Account response not expected");
            }
        }
    }

    public static function findAccountByPWCustomerID($customerId)
    {
        $moduleAPIName = "Accounts";

        //Get instance of RecordOperations Class that takes moduleAPIName as parameter
        $recordOperations = new RecordOperations();
        $paramInstance = new ParameterMap();
        $paramInstance->add(SearchRecordsParam::criteria(), "((PW_CustomerID:equals:{$customerId}))");

        // $paramInstance->add(SearchRecordsParam::email(), "raja@gmail.com");
        // $paramInstance->add(SearchRecordsParam::phone(), "234567890");
        // $paramInstance->add(SearchRecordsParam::word(), "First Name Last Name");
        // $paramInstance->add(SearchRecordsParam::converted(), "both");
        // $paramInstance->add(SearchRecordsParam::approved(), "both");
        // $paramInstance->add(SearchRecordsParam::page(), 1);
        // $paramInstance->add(SearchRecordsParam::perPage(), 2);

        $response = $recordOperations->searchRecords($moduleAPIName, $paramInstance);

        if ($response != null) {
            if ($response->getStatusCode() === 204) {
                throw new ZohoRecordNotFoundException("Presswise customer {$customerId} not found in Zoho");
            }

            if ($response->getStatusCode() != 200) {
                throw new \RuntimeException("Zoho Search Accounts failed with status code: {$response->getStatusCode()}");
            }

            if ($response->isExpected()) {
                //Get the object from response
                $responseHandler = $response->getObject();

                if ($responseHandler instanceof ResponseWrapper) {
                    $responseWrapper = $responseHandler;

                    //Get the obtained Record instance
                    $records = $responseWrapper->getData();

                    foreach ($records as $record) {
                        return $record;
                    }
                }
                //Check if the request returned an exception
                else throw $responseHandler;
            } else {
                throw new \RuntimeException("Zoho Search Account response not expected");
            }
        }
    }

    /**
     * <h3> Get Users </h3>
     * This method is used to retrieve the users data specified in the API request.
     * You can specify the type of users that needs to be retrieved using the Users API.
     * @throws Exception
     */
    public static function getUsers()
    {
        if (Cache::has("zoho.ActiveUsers")) {
            return Cache::get("zoho.ActiveUsers");
        }
        //Get instance of UsersOperations Class
        $usersOperations = new UsersOperations();

        //Get instance of ParameterMap Class
        $paramInstance = new ParameterMap();
        $paramInstance->add(GetUsersParam::type(), "ActiveUsers");
        $paramInstance->add(GetUsersParam::page(), 1);
        $paramInstance->add(GetUsersParam::perPage(), 200);

        $headerInstance = new HeaderMap();
        // $ifmodifiedsince = date_create("2010-07-15T17:58:47+05:30")->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        // $headerInstance->add(GetUsersHeader::IfModifiedSince(), $ifmodifiedsince);

        //Call getUsers method that takes paramInstance as parameter
        $response = $usersOperations->getUsers($paramInstance, $headerInstance);

        if ($response != null) {
            if ($response->getStatusCode() != 200) {
                throw new \RuntimeException("Zoho ActiveUsers search failed with HTTP {$response}");
            }

            $responseHandler = $response->getObject();

            if ($responseHandler instanceof \com\zoho\crm\api\users\ResponseWrapper) {
                $responseWrapper = $responseHandler;

                //Get the list of obtained User instances
                $users = $responseWrapper->getUsers();
                Cache::put("zoho.ActiveUsers", $users, now()->addMinutes(3600));
                return $users;
            } else {
                throw $responseHandler;
            }
        } else {
            throw new \RuntimeException("Zoho getUsers response empty?");
        }
    }

    public static function findUserByEmail($email)
    {
        foreach (self::getUsers() as $user) {
            if (strtolower($user->getEmail()) == $email) {
                return $user;
            }
        }

        return false;
    }


    public static function getAccounts()
    {
        $recordOperations = new RecordOperations();

        $paramInstance = new ParameterMap();

        // $paramInstance->add(GetRecordsParam::approved(), "both");
        // $paramInstance->add(new Param("PW_CustomerID"))

        $headerInstance = new HeaderMap();

        // $ifmodifiedsince = date_create("2020-06-02T11:03:06+05:30")->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        // $headerInstance->add(GetRecordsHeader::IfModifiedSince(), $ifmodifiedsince);

        $moduleAPIName = "Accounts";

        //Call getRecord method that takes paramInstance, moduleAPIName as parameter
        $response = $recordOperations->getRecords($moduleAPIName, $paramInstance, $headerInstance);
        // var_export($response);
        // echo "begin";

        if ($response != null) {
            //Get the status code from response
            echo ("Status Code: " . $response->getStatusCode() . "\n");

            //Get object from response
            $responseHandler = $response->getObject();

            if ($responseHandler instanceof ResponseWrapper) {
                //Get the received ResponseWrapper instance
                $responseWrapper = $responseHandler;

                //Get the list of obtained Record instances
                $records = $responseWrapper->getData();

                if ($records != null) {
                    // $recordClass = 'com\zoho\crm\api\record\Quote';

                    foreach ($records as $record) {

                        //Get the ID of each Record
                        echo ("Record ID: " . $record->getId() . "\n");

                        //Get the createdBy User instance of each Record
                        $createdBy = $record->getCreatedBy();

                        //Check if createdBy is not null
                        if ($createdBy != null) {
                            //Get the ID of the createdBy User
                            echo ("Record Created By User-ID: " . $createdBy->getId() . "\n");

                            //Get the name of the createdBy User
                            echo ("Record Created By User-Name: " . $createdBy->getName() . "\n");

                            //Get the Email of the createdBy User
                            echo ("Record Created By User-Email: " . $createdBy->getEmail() . "\n");
                        }

                        //Get the CreatedTime of each Record
                        echo ("Record CreatedTime: ");

                        print_r($record->getCreatedTime());

                        echo ("\n");

                        //Get the modifiedBy User instance of each Record
                        $modifiedBy = $record->getModifiedBy();

                        //Check if modifiedBy is not null
                        if ($modifiedBy != null) {
                            //Get the ID of the modifiedBy User
                            echo ("Record Modified By User-ID: " . $modifiedBy->getId() . "\n");

                            //Get the name of the modifiedBy User
                            echo ("Record Modified By User-Name: " . $modifiedBy->getName() . "\n");

                            //Get the Email of the modifiedBy User
                            echo ("Record Modified By User-Email: " . $modifiedBy->getEmail() . "\n");
                        }

                        //Get the ModifiedTime of each Record
                        echo ("Record ModifiedTime: ");

                        print_r($record->getModifiedTime());

                        print_r("\n");

                        //Get the list of Tag instance each Record
                        $tags = $record->getTag();

                        //Check if tags is not null
                        if ($tags != null) {
                            foreach ($tags as $tag) {
                                //Get the Name of each Tag
                                echo ("Record Tag Name: " . $tag->getName() . "\n");

                                //Get the Id of each Tag
                                echo ("Record Tag ID: " . $tag->getId() . "\n");
                            }
                        }

                        //To get particular field value
                        echo ("Record Field Value: " . $record->getKeyValue("Last_Name") . "\n"); // FieldApiName

                        echo ("Record KeyValues: \n");
                        //Get the KeyValue map
                        foreach ($record->getKeyValues() as $keyName => $value) {
                            echo "\$record->addFieldValue(new Field(" . var_export($keyName, true) . "), " . var_export($value, true) . ");\n";
                            // echo ("Field APIName: " . $keyName . " \tValue: ");

                            // print_r($value);

                            // echo ("\n");
                        }

                        die();
                    }
                }
            }
        }
    }

    public static function dumpRecordsAsPhp(string $moduleAPIName)
    {
        //example
        //$moduleAPIName = "Leads";

        //Get instance of RecordOperations Class that takes moduleAPIName as parameter
        $recordOperations = new RecordOperations();

        $paramInstance = new ParameterMap();

        // $paramInstance->add(GetRecordsParam::approved(), "true");

        // $paramInstance->add(GetRecordsParam::converted(), "1234");

        // $paramInstance->add(GetRecordsParam::cvid(), "3477061000000089005");

        // $ids = array("3477061000005623115", "3477061000004352001");

        // foreach($ids as $id)
        // {
        // 	$paramInstance->add(GetRecordsParam::ids(), $id);
        // }

        // $paramInstance->add(GetRecordsParam::uid(), "3477061000005181008");

        // $fieldNames = array("Last_Name", "City");

        // foreach($fieldNames as $fieldName)
        // {
        // $paramInstance->add(GetRecordsParam::fields(), "id");
        // }

        // $paramInstance->add(GetRecordsParam::sortBy(), "Email");

        // $paramInstance->add(GetRecordsParam::sortOrder(), "desc");

        $paramInstance->add(GetRecordsParam::page(), 1);

        $paramInstance->add(GetRecordsParam::perPage(), 3);

        // $startdatetime = date_create("2020-06-27T15:10:00+05:30")->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        // $paramInstance->add(GetRecordsParam::startDateTime(), $startdatetime);

        // $enddatetime = date_create("2020-06-29T15:10:00+05:30")->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        // $paramInstance->add(GetRecordsParam::endDateTime(), $enddatetime);

        // $paramInstance->add(GetRecordsParam::territoryId(), "3477061000003051357");

        // $paramInstance->add(GetRecordsParam::includeChild(), true);

        $headerInstance = new HeaderMap();

        // $datetime = date_create("2021-02-26T15:28:34+05:30")->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        // $headerInstance->add(GetRecordsHeader::IfModifiedSince(), $datetime);

        //Call getRecords method
        $response = $recordOperations->getRecords($moduleAPIName, $paramInstance, $headerInstance);

        if ($response != null) {
            //Get the status code from response
            echo ("Status code " . $response->getStatusCode() . "\n");

            if (in_array($response->getStatusCode(), array(204, 304))) {
                echo ($response->getStatusCode() == 204 ? "No Content\n" : "Not Modified\n");

                return;
            }

            if ($response->isExpected()) {
                //Get the object from response
                $responseHandler = $response->getObject();

                if ($responseHandler instanceof ResponseWrapper) {
                    //Get the received ResponseWrapper instance
                    $responseWrapper = $responseHandler;

                    //Get the obtained Record instances
                    $records = $responseWrapper->getData();

                    foreach ($records as $record) {
                        var_export($record);
                        die();
                        //Get the ID of each Record
                        echo ("Record ID: " . $record->getId() . "\n");

                        //Get the createdBy User instance of each Record
                        $createdBy = $record->getCreatedBy();

                        //Check if createdBy is not null
                        if ($createdBy != null) {
                            //Get the ID of the createdBy User
                            echo ("Record Created By User-ID: " . $createdBy->getId() . "\n");

                            //Get the name of the createdBy User
                            echo ("Record Created By User-Name: " . $createdBy->getName() . "\n");

                            //Get the Email of the createdBy User
                            echo ("Record Created By User-Email: " . $createdBy->getEmail() . "\n");
                        }

                        //Get the CreatedTime of each Record
                        echo ("Record CreatedTime: ");

                        print_r($record->getCreatedTime());

                        echo ("\n");

                        //Get the modifiedBy User instance of each Record
                        $modifiedBy = $record->getModifiedBy();

                        //Check if modifiedBy is not null
                        if ($modifiedBy != null) {
                            //Get the ID of the modifiedBy User
                            echo ("Record Modified By User-ID: " . $modifiedBy->getId() . "\n");

                            //Get the name of the modifiedBy User
                            echo ("Record Modified By User-Name: " . $modifiedBy->getName() . "\n");

                            //Get the Email of the modifiedBy User
                            echo ("Record Modified By User-Email: " . $modifiedBy->getEmail() . "\n");
                        }

                        //Get the ModifiedTime of each Record
                        echo ("Record ModifiedTime: ");

                        print_r($record->getModifiedTime());

                        echo ("\n");

                        //Get the list of Tag instance each Record
                        $tags = $record->getTag();

                        //Check if tags is not null
                        if ($tags != null) {
                            foreach ($tags as $tag) {
                                //Get the Name of each Tag
                                echo ("Record Tag Name: " . $tag->getName() . "\n");

                                //Get the Id of each Tag
                                echo ("Record Tag ID: " . $tag->getId() . "\n");
                            }
                        }

                        //To get particular field value 
                        echo ("Record Field Value: " . $record->getKeyValue("Last_Name") . "\n"); // FieldApiName

                        echo ("Record KeyValues : \n");

                        //Get the KeyValue map
                        foreach ($record->getKeyValues() as $keyName => $value) {
                            if ($value != null) {
                                if ((is_array($value) && sizeof($value) > 0) && isset($value[0])) {
                                    if ($value[0] instanceof FileDetails) {
                                        $fileDetails = $value;

                                        foreach ($fileDetails as $fileDetail) {
                                            $fileDetails = $value;

                                            foreach ($fileDetails as $fileDetail) {
                                                //Get the Extn of each FileDetails
                                                echo ("Record FileDetails Extn: " . $fileDetail->getExtn() . "\n");

                                                //Get the IsPreviewAvailable of each FileDetails
                                                echo ("Record FileDetails IsPreviewAvailable: " . $fileDetail->getIsPreviewAvailable() . "\n");

                                                //Get the DownloadUrl of each FileDetails
                                                echo ("Record FileDetails DownloadUrl: " . $fileDetail->getDownloadUrl() . "\n");

                                                //Get the DeleteUrl of each FileDetails
                                                echo ("Record FileDetails DeleteUrl: " . $fileDetail->getDeleteUrl() . "\n");

                                                //Get the EntityId of each FileDetails
                                                echo ("Record FileDetails EntityId: " . $fileDetail->getEntityId() . "\n");

                                                //Get the Mode of each FileDetails
                                                echo ("Record FileDetails Mode: " . $fileDetail->getMode() . "\n");

                                                //Get the OriginalSizeByte of each FileDetails
                                                echo ("Record FileDetails OriginalSizeByte: " . $fileDetail->getOriginalSizeByte() . "\n");

                                                //Get the PreviewUrl of each FileDetails
                                                echo ("Record FileDetails PreviewUrl: " . $fileDetail->getPreviewUrl() . "\n");

                                                //Get the FileName of each FileDetails
                                                echo ("Record FileDetails FileName: " . $fileDetail->getFileName() . "\n");

                                                //Get the FileId of each FileDetails
                                                echo ("Record FileDetails FileId: " . $fileDetail->getFileId() . "\n");

                                                //Get the AttachmentId of each FileDetails
                                                echo ("Record FileDetails AttachmentId: " . $fileDetail->getAttachmentId() . "\n");

                                                //Get the FileSize of each FileDetails
                                                echo ("Record FileDetails FileSize: " . $fileDetail->getFileSize() . "\n");

                                                //Get the CreatorId of each FileDetails
                                                echo ("Record FileDetails CreatorId: " . $fileDetail->getCreatorId() . "\n");

                                                //Get the LinkDocs of each FileDetails
                                                echo ("Record FileDetails LinkDocs: " . $fileDetail->getLinkDocs() . "\n");
                                            }
                                        }
                                    } else if ($value[0] instanceof Choice) {
                                        $choice = $value;

                                        foreach ($choice as $choiceValue) {
                                            echo ("Record " . $keyName . " : " . $choiceValue->getValue() . "\n");
                                        }
                                    } else if ($value[0] instanceof InventoryLineItems) {
                                        $productDetails = $value;

                                        foreach ($productDetails as $productDetail) {
                                            $lineItemProduct = $productDetail->getProduct();

                                            if ($lineItemProduct != null) {
                                                echo ("Record ProductDetails LineItemProduct ProductCode: " . $lineItemProduct->getProductCode() . "\n");

                                                echo ("Record ProductDetails LineItemProduct Currency: " . $lineItemProduct->getCurrency() . "\n");

                                                echo ("Record ProductDetails LineItemProduct Name: " . $lineItemProduct->getName() . "\n");

                                                echo ("Record ProductDetails LineItemProduct Id: " . $lineItemProduct->getId() . "\n");
                                            }

                                            echo ("Record ProductDetails Quantity: " . $productDetail->getQuantity() . "\n");

                                            echo ("Record ProductDetails Discount: " . $productDetail->getDiscount() . "\n");

                                            echo ("Record ProductDetails TotalAfterDiscount: " . $productDetail->getTotalAfterDiscount() . "\n");

                                            echo ("Record ProductDetails NetTotal: " . $productDetail->getNetTotal() . "\n");

                                            if ($productDetail->getBook() != null) {
                                                echo ("Record ProductDetails Book: " . $productDetail->getBook() . "\n");
                                            }

                                            echo ("Record ProductDetails Tax: " . $productDetail->getTax() . "\n");

                                            echo ("Record ProductDetails ListPrice: " . $productDetail->getListPrice() . "\n");

                                            echo ("Record ProductDetails UnitPrice: " . $productDetail->getUnitPrice() . "\n");

                                            echo ("Record ProductDetails QuantityInStock: " . $productDetail->getQuantityInStock() . "\n");

                                            echo ("Record ProductDetails Total: " . $productDetail->getTotal() . "\n");

                                            echo ("Record ProductDetails ID: " . $productDetail->getId() . "\n");

                                            echo ("Record ProductDetails ProductDescription: " . $productDetail->getProductDescription() . "\n");

                                            $lineTaxes = $productDetail->getLineTax();

                                            foreach ($lineTaxes as $lineTax) {
                                                echo ("Record ProductDetails LineTax Percentage: " . $lineTax->getPercentage() . "\n");

                                                echo ("Record ProductDetails LineTax Name: " . $lineTax->getName() . "\n");

                                                echo ("Record ProductDetails LineTax Id: " . $lineTax->getId() . "\n");

                                                echo ("Record ProductDetails LineTax Value: " . $lineTax->getValue() . "\n");
                                            }
                                        }
                                    } else if ($value[0] instanceof Tag) {
                                        $tagList = $value;

                                        foreach ($tagList as $tag) {
                                            //Get the Name of each Tag
                                            echo ("Record Tag Name: " . $tag->getName() . "\n");

                                            //Get the Id of each Tag
                                            echo ("Record Tag ID: " . $tag->getId() . "\n");
                                        }
                                    } else if ($value[0] instanceof PricingDetails) {
                                        $pricingDetails = $value;

                                        foreach ($pricingDetails as $pricingDetail) {
                                            echo ("Record PricingDetails ToRange: " . $pricingDetail->getToRange() . "\n");

                                            echo ("Record PricingDetails Discount: " . $pricingDetail->getDiscount() . "\n");

                                            echo ("Record PricingDetails ID: " . $pricingDetail->getId() . "\n");

                                            echo ("Record PricingDetails FromRange: " . $pricingDetail->getFromRange() . "\n");
                                        }
                                    } else if ($value[0] instanceof Participants) {
                                        $participants = $value;

                                        foreach ($participants as $participant) {
                                            echo ("RelatedRecord Participants Name: " . $participant->getName() . "\n");

                                            echo ("RelatedRecord Participants Invited: " . $participant->getInvited() . "\n");

                                            echo ("RelatedRecord Participants ID: " . $participant->getId() . "\n");

                                            echo ("RelatedRecord Participants Type: " . $participant->getType() . "\n");

                                            echo ("RelatedRecord Participants Participant: " . $participant->getParticipant() . "\n");

                                            echo ("RelatedRecord Participants Status: " . $participant->getStatus() . "\n");
                                        }
                                    } else if ($value[0] instanceof Record) {
                                        $recordList = $value;

                                        foreach ($recordList as $record1) {
                                            //Get the details map
                                            foreach ($record1->getKeyValues() as $key => $value1) {
                                                //Get each value in the map
                                                echo ($key . " : ");

                                                print_r($value1);

                                                echo ("\n");
                                            }
                                        }
                                    } else if ($value[0] instanceof LineTax) {
                                        $lineTaxes = $value;

                                        foreach ($lineTaxes as $lineTax) {
                                            echo ("Record ProductDetails LineTax Percentage: " . $lineTax->getPercentage() . "\n");

                                            echo ("Record ProductDetails LineTax Name: " . $lineTax->getName() . "\n");

                                            echo ("Record ProductDetails LineTax Id: " . $lineTax->getId() . "\n");

                                            echo ("Record ProductDetails LineTax Value: " . $lineTax->getValue() . "\n");
                                        }
                                    } else if ($value[0] instanceof Comment) {
                                        $comments = $value;

                                        foreach ($comments as $comment) {
                                            echo ("Record Comment CommentedBy: " . $comment->getCommentedBy() . "\n");

                                            echo ("Record Comment CommentedTime: ");

                                            print_r($comment->getCommentedTime());

                                            echo ("\n");

                                            echo ("Record Comment CommentContent: " . $comment->getCommentContent() . "\n");

                                            echo ("Record Comment Id: " . $comment->getId() . "\n");
                                        }
                                    } else if ($value[0] instanceof Attachment) {
                                        $attachments = $value;

                                        foreach ($attachments as $attachment) {
                                            //Get the owner User instance of each attachment
                                            $owner = $attachment->getOwner();

                                            //Check if owner is not null
                                            if ($owner != null) {
                                                //Get the Name of the Owner
                                                echo ("Record Attachment Owner User-Name: " . $owner->getName() . "\n");

                                                //Get the ID of the Owner
                                                echo ("Record Attachment Owner User-ID: " . $owner->getId() . "\n");

                                                //Get the Email of the Owner
                                                echo ("Record Attachment Owner User-Email: " . $owner->getEmail() . "\n");
                                            }

                                            //Get the modified time of each attachment
                                            echo ("Record Attachment Modified Time: ");

                                            print_r($attachment->getModifiedTime());

                                            echo ("\n");

                                            //Get the name of the File
                                            echo ("Record Attachment File Name: " . $attachment->getFileName() . "\n");

                                            //Get the created time of each attachment
                                            echo ("Record Attachment Created Time: ");

                                            print_r($attachment->getCreatedTime());

                                            echo ("\n");

                                            //Get the Attachment file size
                                            echo ("Record Attachment File Size: " . $attachment->getSize() . "\n");

                                            //Get the parentId Record instance of each attachment
                                            $parentId = $attachment->getParentId();

                                            //Check if parentId is not null
                                            if ($parentId != null) {
                                                //Get the parent record Name of each attachment
                                                echo ("Record Attachment parent record Name: " . $parentId->getKeyValue("name") . "\n");

                                                //Get the parent record ID of each attachment
                                                echo ("Record Attachment parent record ID: " . $parentId->getId() . "\n");
                                            }

                                            //Get the attachment is Editable
                                            echo ("Record Attachment is Editable: " . $attachment->getEditable() . "\n");

                                            //Get the file ID of each attachment
                                            echo ("Record Attachment File ID: " . $attachment->getFileId() . "\n");

                                            //Get the type of each attachment
                                            echo ("Record Attachment File Type: " . $attachment->getType() . "\n");

                                            //Get the seModule of each attachment
                                            echo ("Record Attachment seModule: " . $attachment->getSeModule() . "\n");

                                            //Get the modifiedBy User instance of each attachment
                                            $modifiedBy = $attachment->getModifiedBy();

                                            //Check if modifiedBy is not null
                                            if ($modifiedBy != null) {
                                                //Get the Name of the modifiedBy User
                                                echo ("Record Attachment Modified By User-Name: " . $modifiedBy->getName() . "\n");

                                                //Get the ID of the modifiedBy User
                                                echo ("Record Attachment Modified By User-ID: " . $modifiedBy->getId() . "\n");

                                                //Get the Email of the modifiedBy User
                                                echo ("Record Attachment Modified By User-Email: " . $modifiedBy->getEmail() . "\n");
                                            }

                                            //Get the state of each attachment
                                            echo ("Record Attachment State: " . $attachment->getState() . "\n");

                                            //Get the ID of each attachment
                                            echo ("Record Attachment ID: " . $attachment->getId() . "\n");

                                            //Get the createdBy User instance of each attachment
                                            $createdBy = $attachment->getCreatedBy();

                                            //Check if createdBy is not null
                                            if ($createdBy != null) {
                                                //Get the name of the createdBy User
                                                echo ("Record Attachment Created By User-Name: " . $createdBy->getName() . "\n");

                                                //Get the ID of the createdBy User
                                                echo ("Record Attachment Created By User-ID: " . $createdBy->getId() . "\n");

                                                //Get the Email of the createdBy User
                                                echo ("Record Attachment Created By User-Email: " . $createdBy->getEmail() . "\n");
                                            }

                                            //Get the linkUrl of each attachment
                                            echo ("Record Attachment LinkUrl: " . $attachment->getLinkUrl() . "\n");
                                        }
                                    } else {
                                        echo "UNKNOWN Class" . get_class($value[0]) . ":\n";
                                        echo ($keyName . " : ");

                                        print_r($value);

                                        echo ("\n");
                                    }
                                }
                            } else if ($value instanceof Layout) {
                                $layout = $value;

                                if ($layout != null) {
                                    echo ("Record " . $keyName . " ID: " . $layout->getId() . "\n");

                                    echo ("Record " . $keyName . " Name: " . $layout->getName() . "\n");
                                }
                            } else if ($value instanceof User) {
                                $user = $value;

                                if ($user != null) {
                                    echo ("Record " . $keyName . " User-ID: " . $user->getId() . "\n");

                                    echo ("Record " . $keyName . " User-Name: " . $user->getName() . "\n");

                                    echo ("Record " . $keyName . " User-Email: " . $user->getEmail() . "\n");
                                }
                            } else if ($value instanceof Record) {
                                $recordValue = $value;

                                echo ("Record " . $keyName . " ID: " . $recordValue->getId() . "\n");

                                echo ("Record " . $keyName . " Name: " . $recordValue->getKeyValue("name") . "\n");
                            } else if ($value instanceof Choice) {
                                $choiceValue = $value;

                                echo ("Record " . $keyName . " : " . $choiceValue->getValue() . "\n");
                            } else if ($value instanceof RemindAt) {
                                echo ($keyName . ": " . $value->getAlarm() . "\n");
                            } else if ($value instanceof RecurringActivity) {
                                echo ($keyName . " : RRULE" . ": " . $value->getRrule() . "\n");
                            } else if ($value instanceof Consent) {
                                $consent = $value;

                                echo ("Record Consent ID: " . $consent->getId());

                                //Get the Owner User instance of each attachment
                                $owner = $consent->getOwner();

                                //Check if owner is not null
                                if ($owner != null) {
                                    //Get the name of the owner User
                                    echo ("Record Consent Owner Name: " . $owner->getName());

                                    //Get the ID of the owner User
                                    echo ("Record Consent Owner ID: " . $owner->getId());

                                    //Get the Email of the owner User
                                    echo ("Record Consent Owner Email: " . $owner->getEmail());
                                }

                                $consentCreatedBy = $consent->getCreatedBy();

                                //Check if createdBy is not null
                                if ($consentCreatedBy != null) {
                                    //Get the name of the CreatedBy User
                                    echo ("Record Consent CreatedBy Name: " . $consentCreatedBy->getName());

                                    //Get the ID of the CreatedBy User
                                    echo ("Record Consent CreatedBy ID: " . $consentCreatedBy->getId());

                                    //Get the Email of the CreatedBy User
                                    echo ("Record Consent CreatedBy Email: " . $consentCreatedBy->getEmail());
                                }

                                $consentModifiedBy = $consent->getModifiedBy();

                                //Check if createdBy is not null
                                if ($consentModifiedBy != null) {
                                    //Get the name of the ModifiedBy User
                                    echo ("Record Consent ModifiedBy Name: " . $consentModifiedBy->getName());

                                    //Get the ID of the ModifiedBy User
                                    echo ("Record Consent ModifiedBy ID: " . $consentModifiedBy->getId());

                                    //Get the Email of the ModifiedBy User
                                    echo ("Record Consent ModifiedBy Email: " . $consentModifiedBy->getEmail());
                                }

                                echo ("Record Consent CreatedTime: " . $consent->getCreatedTime());

                                echo ("Record Consent ModifiedTime: " . $consent->getModifiedTime());

                                echo ("Record Consent ContactThroughEmail: " . $consent->getContactThroughEmail());

                                echo ("Record Consent ContactThroughSocial: " . $consent->getContactThroughSocial());

                                echo ("Record Consent ContactThroughSurvey: " . $consent->getContactThroughSurvey());

                                echo ("Record Consent ContactThroughPhone: " . $consent->getContactThroughPhone());

                                echo ("Record Consent MailSentTime: " . $consent->getMailSentTime() . toString());

                                echo ("Record Consent ConsentDate: " . $consent->getConsentDate() . toString());

                                echo ("Record Consent ConsentRemarks: " . $consent->getConsentRemarks());

                                echo ("Record Consent ConsentThrough: " . $consent->getConsentThrough());

                                echo ("Record Consent DataProcessingBasis: " . $consent->getDataProcessingBasis());

                                //To get custom values
                                echo ("Record Consent Lawful Reason: " . $consent->getKeyValue("Lawful_Reason"));
                            } else {
                                //Get each value in the map
                                echo ($keyName . " : ");

                                print_r($value);

                                echo ("\n");
                            }
                        }
                    }

                    //Get the Object obtained Info instance
                    $info = $responseWrapper->getInfo();

                    //Check if info is not null
                    if ($info != null) {
                        if ($info->getPerPage() != null) {
                            //Get the PerPage of the Info
                            echo ("Record Info PerPage: " . $info->getPerPage() . "\n");
                        }

                        if ($info->getCount() != null) {
                            //Get the Count of the Info
                            echo ("Record Info Count: " . $info->getCount() . "\n");
                        }

                        if ($info->getPage() != null) {
                            //Get the Page of the Info
                            echo ("Record Info Page: " . $info->getPage() . "\n");
                        }

                        if ($info->getMoreRecords() != null) {
                            //Get the MoreRecords of the Info
                            echo ("Record Info MoreRecords: " . $info->getMoreRecords() . "\n");
                        }
                    }
                }
                //Check if the request returned an exception
                else if ($responseHandler instanceof APIException) {
                    //Get the received APIException instance
                    $exception = $responseHandler;

                    //Get the Status
                    echo ("Status: " . $exception->getStatus()->getValue() . "\n");

                    //Get the Code
                    echo ("Code: " . $exception->getCode()->getValue() . "\n");

                    if ($exception->getDetails() != null) {
                        echo ("Details: ");

                        //Get the details map
                        foreach ($exception->getDetails() as $key => $value) {
                            //Get each value in the map
                            echo ($key . " : " . $value . "\n");
                        }
                    }
                    //Get the Message
                    echo ("Message: " . $exception->getMessage()->getValue() . "\n");
                }
            } else {
                print_r($response);
            }
        }
    }
}
