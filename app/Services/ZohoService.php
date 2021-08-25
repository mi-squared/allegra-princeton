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
        return $recordOperations->createRecords($module, $bodyWrapper);
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
}
