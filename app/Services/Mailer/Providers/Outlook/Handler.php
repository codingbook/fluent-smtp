<?php

namespace FluentMail\App\Services\Mailer\Providers\Outlook;

use FluentMail\App\Models\Settings;
use FluentMail\App\Services\Mailer\BaseHandler;
use FluentMail\Includes\Support\Arr;

class Handler extends BaseHandler {
    private $logger;

    private function log($method, ...$args) {
        if ($this->logger && method_exists($this->logger, $method)) {
            call_user_func_array([$this->logger, $method], $args);
        }
    }

    public function __construct() {
        parent::__construct();

        // Initialize logger only if available
        if (class_exists('FluentMail\App\Services\Mailer\Providers\Outlook\Logger')) {
            $this->logger = Logger::getInstance();
        }
    }

    public function send() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'existing_row_id' => $this->existing_row_id,
            'is_fallback' => !empty($this->existing_row_id)
        ]);

        // Log fallback activation if this is a fallback attempt
        if (!empty($this->existing_row_id)) {
            $this->log('logInfo', 'FALLBACK ACTIVATED - Outlook Provider', [
                'existing_row_id' => $this->existing_row_id,
                'reason' => 'Primary connection failed, using configured fallback Outlook connection',
                'fallback_settings' => $this->settings
            ]);
        }

        try {
            $this->phpMailer->Encoding = 'base64';
            $this->log('logEmailOperation', 'set_encoding', [
                'encoding' => 'base64'
            ]);

            $preSendResult          = $this->preSend();
            $phpMailerPreSendResult = $this->phpMailer->preSend();

            $this->log('logEmailOperation', 'pre_send_checks', [
                'preSend_result'           => $preSendResult,
                'phpMailer_preSend_result' => $phpMailerPreSendResult
            ]);

            if ($preSendResult && $phpMailerPreSendResult) {
                $result = $this->postSend();
                $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
                return $result;
            }

            $error = new \WP_Error(422, __('Something went wrong!', 'fluent-smtp'), []);
            $this->log('logError', __CLASS__, __FUNCTION__, 'Pre-send checks failed', [
                'preSend_result'           => $preSendResult,
                'phpMailer_preSend_result' => $phpMailerPreSendResult
            ]);

            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $error, microtime(true) - $startTime);
            return $this->handleResponse($error);
        } catch (\Exception $e) {
            $this->log('logError', __CLASS__, __FUNCTION__, $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    protected function postSend() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        try {
            $returnResponse = $this->sendViaApi();
            $this->log('logEmailOperation', 'post_send_success', [
                'response' => $returnResponse
            ]);
        } catch (\Exception $e) {
            $returnResponse = new \WP_Error(422, $e->getMessage(), []);
            $this->log('logError', __CLASS__, __FUNCTION__, $e->getMessage(), [
                'exception' => $e,
                'wp_error'  => $returnResponse
            ]);
        }

        $this->response = $returnResponse;
        $result         = $this->handleResponse($this->response);

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    public function setSettings($settings) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'settings' => $settings
        ]);

        if (Arr::get($settings, 'key_store') == 'wp_config') {
            $settings['client_id']     = defined('FLUENTMAIL_OUTLOOK_CLIENT_ID') ? FLUENTMAIL_OUTLOOK_CLIENT_ID : '';
            $settings['client_secret'] = defined('FLUENTMAIL_OUTLOOK_CLIENT_SECRET') ? FLUENTMAIL_OUTLOOK_CLIENT_SECRET : '';

            $this->log('logEmailOperation', 'settings_from_wp_config', [
                'client_id_defined'     => defined('FLUENTMAIL_OUTLOOK_CLIENT_ID'),
                'client_secret_defined' => defined('FLUENTMAIL_OUTLOOK_CLIENT_SECRET')
            ]);
        }

        $this->settings = $settings;

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $this, microtime(true) - $startTime);
        return $this;
    }

    private function sendViaApi() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        try {
            $mimeMessage = $this->phpMailer->getSentMIMEMessage();
            $mime        = chunk_split(base64_encode($mimeMessage), 76, "\n");

            $this->log('logEmailOperation', 'mime_preparation', [
                'original_size' => strlen($mimeMessage),
                'encoded_size'  => strlen($mime)
            ]);

            $data = $this->getSetting();
            $this->log('logEmailOperation', 'get_settings', [
                'settings_keys' => array_keys($data)
            ]);

            $accessToken = $this->getAccessToken($data);
            $this->log('logTokenOperation', 'get_access_token', [
                'token_length' => strlen($accessToken),
                'token_valid'  => !empty($accessToken)
            ]);

            $api = (new API($data['client_id'], $data['client_secret']));
            $this->log('logEmailOperation', 'api_instance_created', [
                'client_id' => $data['client_id']
            ]);

            $result = $api->sendMime($mime, $accessToken);

            if (is_wp_error($result)) {
                $errorMessage = $result->get_error_message();
                $this->log('logError', __CLASS__, __FUNCTION__, 'API returned WP_Error', [
                    'error_message' => $errorMessage,
                    'error_code'    => $result->get_error_code(),
                    'error_data'    => $result->get_error_data()
                ]);

                $this->log('logFunctionExit', __CLASS__, __FUNCTION__, new \WP_Error(422, $errorMessage, []), microtime(true) - $startTime);
                return new \WP_Error(422, $errorMessage, []);
            } else {
                $response = array(
                    'RequestId' => $result['request-id']
                );

                $this->log('logEmailOperation', 'send_success', [
                    'request_id' => $result['request-id'],
                    'response'   => $result
                ]);

                $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $response, microtime(true) - $startTime);
                return $response;
            }
        } catch (\Exception $e) {
            $this->log('logError', __CLASS__, __FUNCTION__, $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    public function validateProviderInformation($connection) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'connection' => $connection
        ]);

        $errors = [];

        $keyStoreType = $connection['key_store'];
        $this->log('logEmailOperation', 'validation_start', [
            'key_store_type' => $keyStoreType
        ]);

        $clientId     = Arr::get($connection, 'client_id');
        $clientSecret = Arr::get($connection, 'client_secret');

        if ($keyStoreType == 'db') {
            if (!$clientId) {
                $errors['client_id']['required'] = __('Application Client ID is required.', 'fluent-smtp');
            }

            if (!$clientSecret) {
                $errors['client_secret']['required'] = __('Application Client Secret key is required.', 'fluent-smtp');
            }

            $this->log('logEmailOperation', 'db_validation', [
                'client_id_provided'     => !empty($clientId),
                'client_secret_provided' => !empty($clientSecret)
            ]);
        } else if ($keyStoreType == 'wp_config') {
            if (!defined('FLUENTMAIL_OUTLOOK_CLIENT_ID') || !FLUENTMAIL_OUTLOOK_CLIENT_ID) {
                $errors['client_id']['required'] = __('Please define FLUENTMAIL_OUTLOOK_CLIENT_ID in wp-config.php file.', 'fluent-smtp');
            } else {
                $clientId = FLUENTMAIL_OUTLOOK_CLIENT_ID;
            }

            if (!defined('FLUENTMAIL_OUTLOOK_CLIENT_SECRET') || !FLUENTMAIL_OUTLOOK_CLIENT_SECRET) {
                $errors['client_secret']['required'] = __('Please define FLUENTMAIL_OUTLOOK_CLIENT_SECRET in wp-config.php file.', 'fluent-smtp');
            } else {
                $clientSecret = FLUENTMAIL_OUTLOOK_CLIENT_SECRET;
            }

            $this->log('logEmailOperation', 'wp_config_validation', [
                'client_id_defined'     => defined('FLUENTMAIL_OUTLOOK_CLIENT_ID'),
                'client_secret_defined' => defined('FLUENTMAIL_OUTLOOK_CLIENT_SECRET')
            ]);
        }

        if ($errors) {
            $this->log('logError', __CLASS__, __FUNCTION__, 'Validation errors found', [
                'errors' => $errors
            ]);
            $this->throwValidationException($errors);
        }

        $accessToken = Arr::get($connection, 'access_token');
        $authToken   = Arr::get($connection, 'auth_token');

        $this->log('logTokenOperation', 'token_validation', [
            'has_access_token' => !empty($accessToken),
            'has_auth_token'   => !empty($authToken)
        ]);

        if (!$accessToken && $authToken) {
            $this->log('logTokenOperation', 'generating_token_from_auth', [
                'auth_token_length' => strlen($authToken)
            ]);

            $tokens = (new API($clientId, $clientSecret))->generateToken($authToken);
            if (is_wp_error($tokens)) {
                $errors['auth_token']['required'] = $tokens->get_error_message();
                $this->log('logError', __CLASS__, __FUNCTION__, 'Token generation failed', [
                    'wp_error' => $tokens
                ]);
            } else {
                $this->log('logTokenOperation', 'token_generation_success', [
                    'tokens_received' => array_keys($tokens)
                ]);

                add_filter('fluentmail_saving_connection_data', function ($con, $provider) use ($connection, $tokens) {

                    if ($provider != 'outlook') {
                        return $con;
                    }

                    if (Arr::get($con, 'connection.sender_email') != $connection['sender_email']) {
                        return $con;
                    }

                    $con['connection']['refresh_token'] = $tokens['refresh_token'];
                    $con['connection']['access_token']  = $tokens['access_token'];
                    $con['connection']['auth_token']    = '';
                    $con['connection']['expire_stamp']  = time() + $tokens['expires_in'];

                    return $con;
                }, 10, 2);
            }
        } else if (!$authToken && !$accessToken) {
            $errors['auth_token']['required'] = __('Please Provide Auth Token.', 'fluent-smtp');
            $this->log('logError', __CLASS__, __FUNCTION__, 'No tokens provided', [
                'has_access_token' => !empty($accessToken),
                'has_auth_token'   => !empty($authToken)
            ]);
        }

        if ($errors) {
            $this->log('logError', __CLASS__, __FUNCTION__, 'Final validation errors', [
                'errors' => $errors
            ]);
            $this->throwValidationException($errors);
        }

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, null, microtime(true) - $startTime);
    }

    private function saveNewTokens($existingData, $tokens) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'existing_data' => $existingData,
            'tokens'        => $tokens
        ]);

        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            $this->log('logError', __CLASS__, __FUNCTION__, 'Invalid tokens provided', [
                'has_access_token'  => !empty($tokens['access_token']),
                'has_refresh_token' => !empty($tokens['refresh_token'])
            ]);
            return false;
        }

        $senderEmail = $existingData['sender_email'];

        $existingData['access_token']  = $tokens['access_token'];
        $existingData['refresh_token'] = $tokens['refresh_token'];
        $existingData['expire_stamp']  = $tokens['expires_in'] + time();

        $this->log('logTokenOperation', 'saving_new_tokens', [
            'sender_email' => $senderEmail,
            'expire_stamp' => $existingData['expire_stamp']
        ]);

        (new Settings())->updateConnection($senderEmail, $existingData);
        $result = fluentMailGetProvider($senderEmail, true); // we are clearing the static cache here

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    private function getAccessToken($config) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'config' => $config
        ]);

        $accessToken = $config['access_token'];
        $currentTime = time();
        $expireTime  = $config['expire_stamp'];
        $bufferTime  = 300; // 5 minutes buffer

        $this->log('logTokenOperation', 'checking_token_expiry', [
            'current_time'     => $currentTime,
            'expire_stamp'     => $expireTime,
            'buffer_time'      => $bufferTime,
            'will_expire_soon' => ($expireTime - $bufferTime) < $currentTime
        ]);

        // check if expired or will be expired in 300 seconds
        if (($expireTime - $bufferTime) < $currentTime) {
            $this->log('logTokenOperation', 'token_refresh_needed', [
                'reason' => 'token_expired_or_expiring_soon'
            ]);

            $fluentAPi = (new API($config['client_id'], $config['client_secret']));

            $tokens = $fluentAPi->sendTokenRequest('refresh_token', [
                'refresh_token' => $config['refresh_token']
            ]);

            if (is_wp_error($tokens)) {
                $this->log('logError', __CLASS__, __FUNCTION__, 'Token refresh failed', [
                    'wp_error' => $tokens
                ]);
                $this->log('logFunctionExit', __CLASS__, __FUNCTION__, false, microtime(true) - $startTime);
                return false;
            }

            $this->log('logTokenOperation', 'token_refresh_success', [
                'new_tokens' => array_keys($tokens)
            ]);

            $this->saveNewTokens($config, $tokens);

            $accessToken = $tokens['access_token'];
        } else {
            $this->log('logTokenOperation', 'token_still_valid', [
                'time_remaining' => $expireTime - $currentTime
            ]);
        }

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $accessToken, microtime(true) - $startTime);
        return $accessToken;
    }

    public function getConnectionInfo($connection) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'connection' => $connection
        ]);

        if (Arr::get($connection, 'key_store') == 'wp_config') {
            $connection['client_id']     = defined('FLUENTMAIL_OUTLOOK_CLIENT_ID') ? FLUENTMAIL_OUTLOOK_CLIENT_ID : '';
            $connection['client_secret'] = defined('FLUENTMAIL_OUTLOOK_CLIENT_SECRET') ? FLUENTMAIL_OUTLOOK_CLIENT_SECRET : '';

            $this->log('logEmailOperation', 'connection_info_wp_config', [
                'client_id_defined'     => defined('FLUENTMAIL_OUTLOOK_CLIENT_ID'),
                'client_secret_defined' => defined('FLUENTMAIL_OUTLOOK_CLIENT_SECRET')
            ]);
        }

        $this->getAccessToken($connection);
        $info       = fluentMailgetConnection($connection['sender_email']);
        $connection = $info->getSetting();

        $currentTime      = time();
        $expireTime       = $connection['expire_stamp'];
        $timeRemaining    = $expireTime - $currentTime;
        $minutesRemaining = intval($timeRemaining / 60);

        $extraRow = [
            'title'   => __('Token Validity', 'fluent-smtp'),
            'content' => 'Valid (' . $minutesRemaining . 'm)'
        ];

        if ($expireTime < $currentTime) {
            $extraRow['content'] = 'Invalid. Please re-authenticate';
            $this->log('logWarning', __CLASS__, __FUNCTION__, 'Token has expired', [
                'expire_time'  => $expireTime,
                'current_time' => $currentTime
            ]);
        } else {
            $this->log('logTokenOperation', 'token_validity_check', [
                'minutes_remaining' => $minutesRemaining,
                'expire_time'       => $expireTime,
                'current_time'      => $currentTime
            ]);
        }

        $connection['extra_rows'] = [$extraRow];

        $result = [
            'info' => (string) fluentMail('view')->make('admin.general_connection_info', [
                'connection' => $connection
            ])
        ];

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }
}
