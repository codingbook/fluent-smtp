<?php

namespace FluentMail\App\Services\Mailer\Providers\Postmark;

use FluentMail\App\Services\Mailer\BaseHandler;
use FluentMail\Includes\Support\Arr;

class Handler extends BaseHandler {
    use ValidatorTrait;

    private $logger;

    protected $emailSentCode = 200;

    protected $url = 'https://api.postmarkapp.com/email';

    public function __construct() {
        parent::__construct();

        // Initialize logger only if available
        if (class_exists('FluentMail\App\Services\Mailer\Providers\Postmark\Logger')) {
            $this->logger = Logger::getInstance();
        }
    }

    private function log($method, ...$args) {
        if ($this->logger && method_exists($this->logger, $method)) {
            call_user_func_array([$this->logger, $method], $args);
        }
    }

    public function send() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        if ($this->preSend() && $this->phpMailer->preSend()) {
            $result = $this->postSend();
            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
            return $result;
        }

        $error = new \WP_Error(422, __('Something went wrong!', 'fluent-smtp'), []);
        $this->log('logError', __CLASS__, __FUNCTION__, 'Pre-send failed', [
            'wp_error' => $error
        ]);
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $error, microtime(true) - $startTime);
        return $this->handleResponse($error);
    }

    public function postSend() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        $body = [
            'From'          => $this->getParam('from'),
            'To'            => $this->getTo(),
            'Subject'       => $this->getSubject(),
            'MessageStream' => $this->getSetting('message_stream', 'outbound')
        ];

        $this->log('logEmailOperation', 'building_request_body', [
            'initial_body' => $body
        ]);

        if ($replyTo = $this->getReplyTo()) {
            $body['ReplyTo'] = $replyTo;
            $this->log('logEmailOperation', 'added_reply_to', [
                'reply_to' => $replyTo
            ]);
        }

        if ($bcc = $this->getBlindCarbonCopy()) {
            $body['Bcc'] = $bcc;
            $this->log('logEmailOperation', 'added_bcc', [
                'bcc' => $bcc
            ]);
        }

        if ($cc = $this->getCarbonCopy()) {
            $body['Cc'] = $cc;
            $this->log('logEmailOperation', 'added_cc', [
                'cc' => $cc
            ]);
        }

        $contentType = $this->getHeader('content-type');
        $this->log('logEmailOperation', 'content_type_detected', [
            'content_type' => $contentType
        ]);

        if ($contentType == 'text/html') {
            $body['HtmlBody'] = $this->getParam('message');

            if ($this->getSetting('track_opens') == 'yes') {
                $body['TrackOpens'] = true;
            }

            if ($this->getSetting('track_links') == 'yes') {
                $body['TrackLinks'] = 'HtmlOnly';
            }
        } else if ($contentType == 'multipart/alternative') {
            $body['HtmlBody'] = $this->getParam('message');
            $body['TextBody'] = $this->phpMailer->AltBody;
            $this->log('logEmailOperation', 'multipart_alternative', [
                'has_html_body' => true,
                'has_text_body' => !empty($this->phpMailer->AltBody)
            ]);
        } else {
            $body['TextBody'] = $this->getParam('message');
            $this->log('logEmailOperation', 'text_only_body', [
                'content_type' => $contentType
            ]);
        }

        if (!empty($this->getParam('attachments'))) {
            $attachments         = $this->getAttachments();
            $body['Attachments'] = $attachments;
            $this->log('logAttachmentProcessing', $attachments);
        }

        // Add any custom headers
        $customHeaders = $this->phpMailer->getCustomHeaders();
        if (!empty($customHeaders)) {
            foreach ($customHeaders as $header) {
                $body['Headers'][] = [
                    'Name'  => $header[0],
                    'Value' => $header[1]
                ];
            }
            $this->log('logEmailOperation', 'added_custom_headers', [
                'custom_headers' => $body['Headers']
            ]);
        }

        $this->log('logEmailOperation', 'final_request_body', [
            'body' => $body
        ]);

        // Log complete email details before sending
        $this->log('logEmailSend',
            $body['From'],
            $body['To'],
            $body['Subject'],
            $body,
            isset($body['Headers']) ? $body['Headers'] : [],
            isset($body['Attachments']) ? $body['Attachments'] : []
        );

        // Handle apostrophes in email address From names by escaping them for the Postmark API.
        $from_regex = "/(\"From\": \"[a-zA-Z\\d]+)*[\\\\]{2,}'/";

        $args = array(
            'headers' => $this->getRequestHeaders(),
            'body'    => preg_replace($from_regex, "'", wp_json_encode($body), 1)
        );

        $this->log('logApiRequest', $this->url, 'POST', $args['headers'], $args['body'], [
            'function' => __FUNCTION__
        ]);

        $response = wp_remote_post($this->url, $args);

        if (is_wp_error($response)) {
            $returnResponse = new \WP_Error($response->get_error_code(), $response->get_error_message(), $response->get_error_messages());
            $this->log('logError', __CLASS__, __FUNCTION__, 'WP_Error in wp_remote_post', [
                'wp_error' => $returnResponse
            ]);
        } else {
            $responseBody    = wp_remote_retrieve_body($response);
            $responseCode    = wp_remote_retrieve_response_code($response);
            $responseHeaders = wp_remote_retrieve_headers($response);

            $this->log('logApiResponse', $this->url, $responseCode, $responseHeaders, $responseBody, [
                'function' => __FUNCTION__
            ]);

            $isOKCode = $responseCode == $this->emailSentCode;

            $responseBody = \json_decode($responseBody, true);

            if ($isOKCode) {
                $returnResponse = [
                    'id'      => Arr::get($responseBody, 'MessageID'),
                    'message' => Arr::get($responseBody, 'Message')
                ];
                $this->log('logEmailOperation', 'send_success', [
                    'response'      => $returnResponse,
                    'response_body' => $responseBody
                ]);
            } else {
                $returnResponse = new \WP_Error($responseCode, Arr::get($responseBody, 'Message', 'Unknown Error'), $responseBody);
                $this->log('logError', __CLASS__, __FUNCTION__, 'API Error Response', [
                    'response_code' => $responseCode,
                    'error_message' => Arr::get($responseBody, 'Message', 'Unknown Error'),
                    'response_body' => $responseBody,
                    'wp_error'      => $returnResponse
                ]);
            }
        }

        $this->response = $returnResponse;

        $result = $this->handleResponse($this->response);
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    public function setSettings($settings) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'settings_keys' => array_keys($settings)
        ]);

        if ($settings['key_store'] == 'wp_config') {
            $settings['api_key'] = defined('FLUENTMAIL_POSTMARK_API_KEY') ? FLUENTMAIL_POSTMARK_API_KEY : '';
            $this->log('logEmailOperation', 'settings_from_wp_config', [
                'api_key_defined' => defined('FLUENTMAIL_POSTMARK_API_KEY')
            ]);
        }

        $this->settings = $settings;

        $this->log('logPostmarkSettings', $settings);
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, null, microtime(true) - $startTime);

        return $this;
    }

    protected function getReplyTo() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        if ($replyTo = $this->getParam('headers.reply-to')) {
            $replyTo = reset($replyTo);
            $result  = $replyTo['email'];
            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
            return $result;
        }

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, null, microtime(true) - $startTime);
        return null;
    }

    protected function getTo() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        $result = $this->getRecipients($this->getParam('to'));
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    protected function getCarbonCopy() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        $result = $this->getRecipients($this->getParam('headers.cc'));
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    protected function getBlindCarbonCopy() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        $result = $this->getRecipients($this->getParam('headers.bcc'));
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    protected function getRecipients($recipients) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'recipient_count' => count($recipients)
        ]);

        $array = array_map(function ($recipient) {
            return isset($recipient['name'])
            ? $recipient['name'] . ' <' . $recipient['email'] . '>'
            : $recipient['email'];
        }, $recipients);

        $result = implode(', ', $array);
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
        return $result;
    }

    protected function getAttachments() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'attachment_count' => count($this->getParam('attachments'))
        ]);

        $data = [];

        foreach ($this->getParam('attachments') as $attachment) {
            $file = false;

            try {
                if (is_file($attachment[0]) && is_readable($attachment[0])) {
                    $fileName = basename($attachment[0]);
                    $file     = file_get_contents($attachment[0]);
                    $this->log('logEmailOperation', 'attachment_read', [
                        'filename' => $fileName,
                        'size'     => strlen($file)
                    ]);
                }
            } catch (\Exception $e) {
                $file = false;
                $this->log('logError', __CLASS__, __FUNCTION__, 'Failed to read attachment', [
                    'filename'  => $attachment[0],
                    'exception' => $e->getMessage()
                ]);
            }

            if ($file === false) {
                continue;
            }

            $mimeType       = $this->determineMimeContentRype($attachment[0]);
            $attachmentData = [
                'Name'        => $fileName,
                'Content'     => base64_encode($file),
                'ContentType' => $mimeType
            ];

            $data[] = $attachmentData;
        }

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $data, microtime(true) - $startTime);
        return $data;
    }

    protected function getRequestHeaders() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        $headers = [
            'Accept'                  => 'application/json',
            'Content-Type'            => 'application/json',
            'X-Postmark-Server-Token' => $this->getSetting('api_key')
        ];

        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $headers, microtime(true) - $startTime);
        return $headers;
    }

    protected function determineMimeContentRype($filename) {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'filename' => basename($filename)
        ]);

        if (function_exists('mime_content_type')) {
            $result = mime_content_type($filename);
            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
            return $result;
        } elseif (function_exists('finfo_open')) {
            $finfo     = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $filename);
            finfo_close($finfo);
            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $mime_type, microtime(true) - $startTime);
            return $mime_type;
        } else {
            $result = 'application/octet-stream';
            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
            return $result;
        }
    }
}
