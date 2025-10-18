<?php

namespace FluentMail\App\Services\Mailer\Providers\Outlook;

use FluentMail\Includes\Support\Arr;

class API {
    private $clientId;
    private $clientSecret;
    private $logger;

    private function log($method, ...$args) {
        if ($this->logger && method_exists($this->logger, $method)) {
            call_user_func_array([$this->logger, $method], $args);
        }
    }

    public function __construct($clientId = '', $clientSecret = '') {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;

        // Initialize logger only if available
        if (class_exists('FluentMail\App\Services\Mailer\Providers\Outlook\Logger')) {
            $this->logger = Logger::getInstance();
        }

        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret
        ]);
    }

    public function getAuthUrl() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        try {
            $config = $this->getConfig();
            $this->log('logTokenOperation', 'get_auth_url', [
                'config' => $config
            ]);

            $fluentClient = new \FluentMail\Includes\OAuth2Provider($config);
            $authUrl      = $fluentClient->getAuthorizationUrl();

            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $authUrl, microtime(true) - $startTime);
            return $authUrl;
        } catch (\Exception $e) {
            $this->log('logError', __CLASS__, __FUNCTION__, $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    public function generateToken($authCode) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'authCode' => $authCode
        ]);

        try {
            $result = $this->sendTokenRequest('authorization_code', [
                'code' => $authCode
            ]);

            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
            return $result;
        } catch (\Exception $e) {
            $this->log('logError', __CLASS__, __FUNCTION__, $e->getMessage(), [
                'authCode'  => $authCode,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * @return mixed|string
     */
    public function sendTokenRequest($type, $params) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'type'   => $type,
            'params' => $params
        ]);

        try {
            $config = $this->getConfig();
            $this->log('logTokenOperation', 'send_token_request', [
                'type'   => $type,
                'config' => $config
            ]);

            $fluentClient = new \FluentMail\Includes\OAuth2Provider($config);
            $tokens       = $fluentClient->getAccessToken($type, $params);

            $this->log('logTokenOperation', 'token_request_success', [
                'type'            => $type,
                'tokens_received' => is_array($tokens) ? array_keys($tokens) : 'non-array'
            ]);

            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $tokens, microtime(true) - $startTime);
            return $tokens;
        } catch (\Exception $exception) {
            $error = new \WP_Error(422, $exception->getMessage());

            $this->log('logError', __CLASS__, __FUNCTION__, $exception->getMessage(), [
                'type'      => $type,
                'params'    => $params,
                'exception' => $exception,
                'wp_error'  => $error
            ]);

            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $error, microtime(true) - $startTime);
            return $error;
        }
    }

    /**
     * @return array | \WP_Error
     */
    public function sendMime($mime, $accessToken) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'mime_length' => strlen($mime),
            'accessToken' => $accessToken
        ]);

        $url     = 'https://graph.microsoft.com/v1.0/me/sendMail';
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'text/plain'
        ];

        $this->log('logApiRequest', $url, 'POST', $headers, $mime, [
            'function'  => __FUNCTION__,
            'mime_size' => strlen($mime)
        ]);

        $response = wp_remote_request($url, [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => $mime
        ]);

        if (is_wp_error($response)) {
            $this->log('logError', __CLASS__, __FUNCTION__, 'WP_Error in wp_remote_request', [
                'wp_error' => $response,
                'url'      => $url
            ]);

            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $response, microtime(true) - $startTime);
            return $response;
        }

        $responseCode    = wp_remote_retrieve_response_code($response);
        $responseHeaders = wp_remote_retrieve_headers($response);
        $responseBody    = wp_remote_retrieve_body($response);

        $this->log('logApiResponse', $url, $responseCode, $responseHeaders, $responseBody, [
            'function' => __FUNCTION__
        ]);

        if ($responseCode >= 300) {
            $error = Arr::get($response, 'response.message');

            if (!$error) {
                $responseBodyDecoded = json_decode($responseBody, true);
                $error               = Arr::get($responseBodyDecoded, 'error.message');

                if (!$error) {
                    $error = __('Something with wrong with Outlook API. Please check your API Settings', 'fluent-smtp');
                }
            }

            $wpError = new \WP_Error($responseCode, $error);

            $this->log('logError', __CLASS__, __FUNCTION__, 'API Error Response', [
                'response_code' => $responseCode,
                'error_message' => $error,
                'response_body' => $responseBody,
                'wp_error'      => $wpError
            ]);

            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $wpError, microtime(true) - $startTime);
            return $wpError;
        }

        $header = wp_remote_retrieve_headers($response);
        $result = $header->getAll();

        $this->log('logEmailOperation', 'send_mime_success', [
            'response_code' => $responseCode,
            'headers'       => $result
        ]);

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    public function getRedirectUrl() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        $url = rest_url('fluent-smtp/outlook_callback');

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $url, microtime(true) - $startTime);
        return $url;
    }

    private function getConfig() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        $config = [
            'clientId'                => $this->clientId,
            'clientSecret'            => $this->clientSecret,
            'redirectUri'             => $this->getRedirectUrl(),
            'urlAuthorize'            => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'urlAccessToken'          => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => '',
            'scopes'                  => 'https://graph.microsoft.com/user.read https://graph.microsoft.com/mail.readwrite https://graph.microsoft.com/mail.send https://graph.microsoft.com/mail.send.shared offline_access'
        ];

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $config, microtime(true) - $startTime);
        return $config;
    }

}
