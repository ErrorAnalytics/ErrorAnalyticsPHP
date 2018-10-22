<?php
namespace ErrorAnalytics;

class ErrorAnalytics
{
    // private static $analyticsUrl = 'https://erroranalytics.com/api/v1/error';
    private static $analyticsUrl = 'http://dev.erroranalytics.com/api/v1/error';

    private $integrationKey;
    private $callbackFunction;
    private $postMethod;
    private $errorData;

    public static function register(String $integrationKey, \Closure $callbackFunction = null)
    {
        set_error_handler(function($number, $message, $file, $line) use ($integrationKey, $callbackFunction) {
            $analytics = new \ErrorAnalytics\ErrorAnalytics($integrationKey, $callbackFunction, array($number, $message, $file, $line));
            $analytics->send();
        });

        set_exception_handler(function($exception) use ($integrationKey, $callbackFunction) {
            $analytics = new \ErrorAnalytics\ErrorAnalytics($integrationKey, $callbackFunction, $exception);
            $analytics->send();
        });
    }

    private function __construct( String $integrationKey, \Closure $callbackFunction, $errorUnparsedData = null)
    {
        $this->postMethod = false;
        $this->integrationKey = $integrationKey;
        $this->errorData = $this->getErrorData($errorUnparsedData);
    }

    private function getErrorData($errorUnparsedData)
    {
        $errorData = array(
            'url'           => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
            'get'           => $_GET,
            'post'          => $_POST,
            'request'       => $_REQUEST,
            'server'        => $_SERVER ?? 'N/P',
            'environment'   => $_ENV ?? 'N/P'
        );

        if ( is_array($errorUnparsedData) ) {
            $errorData['code']       = $errorUnparsedData[0];
            $errorData['message']    = $errorUnparsedData[1];
            $errorData['file']       = $errorUnparsedData[2];
            $errorData['line']       = $errorUnparsedData[3];
        } else {
            $errorData['code']       = $errorUnparsedData->getCode();
            $errorData['message']    = $errorUnparsedData->getMessage();
            $errorData['file']       = $errorUnparsedData->getFile();
            $errorData['line']       = $errorUnparsedData->getLine();
            $errorData['stacktrace'] = $errorUnparsedData->getTrace();
        }
        return $errorData;
    }

    private function verify()
    {
        if ( function_exists('curl_version') ) $this->postMethod = 'Curl';
        if ( function_exists('stream_context_create') ) $this->postMethod = 'StreamContext';
    }

    private function postCurl( Array $errorData )
    {
        $jsonData = json_encode($errorData);
        $contentLength = strlen($jsonData);
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, self::$analyticsUrl );
        curl_setopt( $ch, CURLOPT_POST, 1 );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            "Content-type: application/json",
            "Content-length: {$contentLength}",
            "X-Integration-Key: {$this->integrationKey}"
        ));
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $jsonData );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
        $serverResponse = curl_exec( $ch );
        curl_close( $ch );

        echo $serverResponse;

        // Further processing ...
    }

    private function postStreamContext( Array $errorData )
    {
        $jsonData = json_encode($errorData);
        $contentLength = strlen($jsonData);
        $options = array(
            'http' => array(
                'method'  => 'POST',
                'header'  => "Content-type: application/json\r\nContent-length: {$contentLength}\r\nX-Integration-Key: {$this->integrationKey}",
                'content' => $jsonData
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false
            )
        );
        $context        = stream_context_create($options);
        $serverResponse = file_get_contents(self::$analyticsUrl, false, $context);
        
        echo $serverResponse;
        // Further processing ...
    }

    public function send()
    {
        $this->verify();
        if (empty($this->postMethod)) throw new \ErrorAnalytics\Exceptions\ErrorAnalyticsException('No available post method found...');
        $methodName = "post{$this->postMethod}";
        try {
            $this->{$methodName}($this->errorData);
        } catch (Exception $e) {
            error_log($e);
        }
        return $this->afterAnalytics();
    }

    private function afterAnalytics()
    {
        if ( empty($this->callbackFunction) )
            return;
        return $this->callbackFunction( $this->errorData );
    }
}