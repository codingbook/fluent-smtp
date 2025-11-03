<?php

namespace FluentMail\App\Services\Mailer\Providers\Smtp;

use FluentMail\App\Services\Mailer\BaseHandler;
use FluentMail\App\Services\Mailer\Providers\Smtp\ValidatorTrait;
use FluentMail\Includes\Support\Arr;

class Handler extends BaseHandler {
    use ValidatorTrait;

    private $logger;

    private function log($method, ...$args) {
        if ($this->logger && method_exists($this->logger, $method)) {
            call_user_func_array([$this->logger, $method], $args);
        }
    }

    public function __construct() {
        parent::__construct();

        // Initialize logger only if available
        if (class_exists('FluentMail\App\Services\Mailer\Providers\Smtp\Logger')) {
            $this->logger = Logger::getInstance();
        }
    }

    public function send() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__, [
            'existing_row_id' => $this->existing_row_id,
            'is_fallback'     => !empty($this->existing_row_id)
        ]);

        // Log fallback activation if this is a fallback attempt
        if (!empty($this->existing_row_id)) {
            $this->log('logInfo', 'FALLBACK ACTIVATED - SMTP Provider', [
                'existing_row_id'   => $this->existing_row_id,
                'reason'            => 'Primary connection failed, using configured fallback SMTP connection',
                'fallback_settings' => $this->settings
            ]);
        }

        if ($this->preSend()) {
            if ($this->getSetting('auto_tls') == 'no') {
                $this->phpMailer->SMTPAutoTLS = false;
                $this->log('logEmailOperation', 'auto_tls_disabled', [
                    'SMTPAutoTLS' => false
                ]);
            }
            $result = $this->postSend();
            $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $result, microtime(true) - $startTime);
            return $result;
        }

        $error = new \WP_Error(422, __('Something went wrong!', 'fluent-smtp'), []);
        $this->log('logError', __CLASS__, __FUNCTION__, 'Pre-send failed', [
            'wp_error'    => $error,
            'is_fallback' => !empty($this->existing_row_id)
        ]);
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, $error, microtime(true) - $startTime);
        return $this->handleResponse($error);
    }

    protected function postSend() {
        $startTime = microtime(true);
        $this->log('logFunctionEntry', __CLASS__, __FUNCTION__);

        try {
            $this->phpMailer->isSMTP();
            $this->log('logEmailOperation', 'set_smtp_mode', [
                'mode' => 'SMTP'
            ]);

            $host       = $this->getSetting('host');
            $port       = $this->getSetting('port');
            $encryption = $this->getSetting('encryption');
            $auth       = $this->getSetting('auth') == 'yes';

            $this->phpMailer->Host = $host;
            $this->phpMailer->Port = $port;

            $this->log('logSmtpConnection', $host, $port, $encryption, $auth);

            if ($auth) {
                $this->phpMailer->SMTPAuth = true;
                $username                  = $this->getSetting('username');
                $this->phpMailer->Username = $username;
                $this->phpMailer->Password = $this->getSetting('password');

                $this->log('logSmtpAuth', $username);
            }

            if ($encryption != 'none') {
                $this->phpMailer->SMTPSecure = $encryption;
            }

            // Enable SMTP debug logging
            if ($this->logger) {
                $this->phpMailer->SMTPDebug   = 2; // Enable verbose debug output
                $this->phpMailer->Debugoutput = function ($str, $level) {
                    $this->logger->addSmtpDebugMessage($str);
                };
            }

            $fromEmail = $this->phpMailer->From;
            $fromName  = $this->phpMailer->FromName ?? '';

            $this->log('logEmailOperation', 'sender_setup', [
                'original_from' => $fromEmail,
                'from_name'     => $fromName
            ]);

            if ($this->isForcedEmail() && !fluentMailIsListedSenderEmail($fromEmail)) {
                $fromEmail = $this->getSetting('sender_email');
                $this->log('logEmailOperation', 'forced_sender_email', [
                    'new_from' => $fromEmail
                ]);
            }

            if (isset($this->phpMailer->FromName)) {
                $fromName = $this->phpMailer->FromName;

                if (
                    $this->getSetting('force_from_name') == 'yes' &&
                    $customFrom = $this->getSetting('sender_name')
                ) {
                    $fromName = $customFrom;
                    $this->log('logEmailOperation', 'forced_sender_name', [
                        'new_from_name' => $fromName
                    ]);
                }

                $this->phpMailer->setFrom($fromEmail, $fromName);
            } else {
                $this->phpMailer->setFrom($fromEmail);
            }

            $recipients = [];
            foreach ($this->getParam('to') as $to) {
                if (isset($to['name'])) {
                    $this->phpMailer->addAddress($to['email'], $to['name']);
                    $recipients[] = ['email' => $to['email'], 'name' => $to['name'], 'type' => 'to'];
                } else {
                    $this->phpMailer->addAddress($to['email']);
                    $recipients[] = ['email' => $to['email'], 'type' => 'to'];
                }
            }

            foreach ($this->getParam('headers.reply-to') as $replyTo) {
                if (isset($replyTo['name'])) {
                    $this->phpMailer->addReplyTo($replyTo['email'], $replyTo['name']);
                    $recipients[] = ['email' => $replyTo['email'], 'name' => $replyTo['name'], 'type' => 'reply-to'];
                } else {
                    $this->phpMailer->addReplyTo($replyTo['email']);
                    $recipients[] = ['email' => $replyTo['email'], 'type' => 'reply-to'];
                }
            }

            foreach ($this->getParam('headers.cc') as $cc) {
                if (isset($cc['name'])) {
                    $this->phpMailer->addCC($cc['email'], $cc['name']);
                    $recipients[] = ['email' => $cc['email'], 'name' => $cc['name'], 'type' => 'cc'];
                } else {
                    $this->phpMailer->addCC($cc['email']);
                    $recipients[] = ['email' => $cc['email'], 'type' => 'cc'];
                }
            }

            foreach ($this->getParam('headers.bcc') as $bcc) {
                if (isset($bcc['name'])) {
                    $this->phpMailer->addBCC($bcc['email'], $bcc['name']);
                    $recipients[] = ['email' => $bcc['email'], 'name' => $bcc['name'], 'type' => 'bcc'];
                } else {
                    $this->phpMailer->addBCC($bcc['email']);
                    $recipients[] = ['email' => $bcc['email'], 'type' => 'bcc'];
                }
            }

            $attachments = [];
            if ($attachmentsParam = $this->getParam('attachments')) {
                foreach ($attachmentsParam as $attachment) {
                    $this->phpMailer->addAttachment($attachment[0], $attachment[7]);
                    $attachments[] = [
                        'path' => $attachment[0],
                        'name' => $attachment[7] ?? basename($attachment[0])
                    ];
                }
            }

            $contentType = $this->getParam('headers.content-type');
            if ($contentType == 'text/html' || $contentType == 'multipart/alternative') {
                $this->phpMailer->isHTML(true);
            }

            $subject = $this->getSubject();
            $body    = $this->getParam('message');
            $altBody = null;

            $this->phpMailer->Subject = $subject;
            $this->phpMailer->Body    = $body;

            if ($contentType == 'multipart/alternative') {
                $altBody                      = $this->getParam('alt_body');
                $this->phpMailer->AltBody     = $altBody;
                $this->phpMailer->ContentType = 'multipart/alternative';
            }

            // Log complete email details before sending
            $this->log('logEmailSend',
                ['email' => $fromEmail, 'name' => $fromName],
                $recipients,
                $subject,
                $this->getParam('headers'),
                $body,
                $altBody
            );

            $this->log('logEmailOperation', 'email_details', [
                'content_type'     => $contentType,
                'has_attachments'  => !empty($attachments),
                'attachment_count' => count($attachments),
                'attachments'      => $attachments,
                'body_encoding'    => $this->phpMailer->Encoding ?? 'unknown'
            ]);

            $this->phpMailer->send();

            $returnResponse = [
                'response' => 'OK'
            ];

            $this->log('logEmailOperation', 'send_success', [
                'response' => $returnResponse
            ]);

        } catch (\Exception $e) {
            $returnResponse = new \WP_Error(422, $e->getMessage(), []);
            $this->log('logError', __CLASS__, __FUNCTION__, $e->getMessage(), [
                'exception'         => $e,
                'smtp_debug_output' => $this->logger ? $this->logger->getSmtpDebugOutput() : []
            ]);
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

        if (Arr::get($settings, 'key_store') == 'wp_config') {
            $settings['username'] = defined('FLUENTMAIL_SMTP_USERNAME') ? FLUENTMAIL_SMTP_USERNAME : '';
            $settings['password'] = defined('FLUENTMAIL_SMTP_PASSWORD') ? FLUENTMAIL_SMTP_PASSWORD : '';

            $this->log('logEmailOperation', 'settings_from_wp_config', [
                'username_defined' => defined('FLUENTMAIL_SMTP_USERNAME'),
                'password_defined' => defined('FLUENTMAIL_SMTP_PASSWORD')
            ]);
        }

        $this->settings = $settings;

        $this->log('logPhpmailerSettings', $settings);
        $this->log('logFunctionExit', __CLASS__, __FUNCTION__, null, microtime(true) - $startTime);

        return $this;
    }
}
