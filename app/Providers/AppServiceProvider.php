<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use com\zoho\api\authenticator\OAuthToken;
use com\zoho\api\authenticator\TokenType;
use com\zoho\api\authenticator\store\DBStore;
use com\zoho\api\authenticator\store\FileStore;
use com\zoho\crm\api\Initializer;
use com\zoho\crm\api\UserSignature;
use com\zoho\crm\api\SDKConfigBuilder;
use com\zoho\crm\api\dc\USDataCenter;
use com\zoho\api\logger\Logger;
use com\zoho\api\logger\Levels;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Zoho
        // boilerplate from https://github.com/zoho/zohocrm-php-sdk#sdk-sample-code

        /*
            * Create an instance of Logger Class that takes two parameters
            * 1 -> Level of the log messages to be logged. Can be configured by typing Levels "::" and choose any level from the list displayed.
            * 2 -> Absolute file path, where messages need to be logged.
        */
        // TODO propper logging
        $logger = Logger::getInstance(\com\zoho\api\logger\Levels::INFO, storage_path("logs/zoho.log"));

        // Create an UserSignature instance that takes user Email as parameter
        // TODO config based
        $user = new UserSignature(env("ZOHO_USER"));

        /*
            * Configure the environment
            * which is of the pattern Domain.Environment
            * Available Domains: USDataCenter, EUDataCenter, INDataCenter, CNDataCenter, AUDataCenter
            * Available Environments: PRODUCTION(), DEVELOPER(), SANDBOX()
        */
        $environment = USDataCenter::PRODUCTION();

        /*
            * Create a Token instance
            * 1 -> OAuth client id.
            * 2 -> OAuth client secret.
            * 3 -> REFRESH/GRANT token.
            * 4 -> Token type(REFRESH/GRANT).
            * 5 -> OAuth redirect URL.
        */
        $token = new OAuthToken(env("ZOHO_CLIENT_ID"), env("ZOHO_CLIENT_SECRET"), "REFRESH/GRANT token", TokenType::GRANT, null);


        //Parameter containing the absolute file path to store tokens
        $tokenstore = new FileStore(storage_path("zoho_tokens.txt"));

        $autoRefreshFields = false;
        $pickListValidation = false;
        $connectionTimeout = 2;
        $timeout = 2;
        $enableSSLVerification = true;

        $sdkConfig = (new SDKConfigBuilder())->setAutoRefreshFields($autoRefreshFields)->setPickListValidation($pickListValidation)->setSSLVerification($enableSSLVerification)->connectionTimeout($connectionTimeout)->timeout($timeout)->build();

        $resourcePath = storage_path("zoho");

        /*
          * Call static initialize method of Initializer class that takes the following arguments
          * 1 -> UserSignature instance
          * 2 -> Environment instance
          * 3 -> Token instance
          * 4 -> TokenStore instance
          * 5 -> SDKConfig instance
          * 6 -> resourcePath - A String
          * 7 -> Log instance (optional)
          * 8 -> RequestProxy instance (optional)
        */
        Initializer::initialize($user, $environment, $token, $tokenstore, $sdkConfig, $resourcePath, $logger, null);
    }
}
