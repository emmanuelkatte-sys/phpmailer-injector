<?php

declare(strict_types=1);

namespace Warship\Injector\Config;

use Symfony\Component\Yaml\Yaml;

final class Loader
{
    public static function load(string $path): Config
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        /** @var array<string, mixed> $raw */
        $raw = Yaml::parseFile($path);
        if (!is_array($raw)) {
            throw new \RuntimeException('Invalid YAML config');
        }

        $cfg = new Config();

        $sender = self::arr($raw, 'sender');
        $cfg->senderFromAddress = self::str($sender, 'from_address');
        $cfg->senderFromName = self::str($sender, 'from_name');
        $cfg->senderEnvelopeFrom = self::str($sender, 'envelope_from', $cfg->senderFromAddress);
        $cfg->senderDisplayNames = self::strList($sender, 'display_names');
        $cfg->senderDisplayNameMode = self::str($sender, 'display_name_mode', 'sequential');

        $cfg->mtaType = strtolower(self::str($raw, 'mta_type', 'pmta'));
        if ($cfg->mtaType === 'zone-mta' || $cfg->mtaType === 'zonepmta') {
            $cfg->mtaType = 'zonemta';
        }
        if (!in_array($cfg->mtaType, ['haraka', 'zonemta', 'pmta'], true)) {
            $cfg->mtaType = 'pmta';
        }

        $smtp = self::arr($raw, 'smtp');
        $cfg->smtpEnabled = self::bool($smtp, 'enabled', true);
        $cfg->smtpHost = self::str($smtp, 'host', '127.0.0.1');
        $cfg->smtpPort = self::int($smtp, 'port', 587);
        $cfg->smtpUseAuth = self::bool($smtp, 'use_auth', true);
        $cfg->smtpUsername = self::str($smtp, 'username');
        $cfg->smtpPassword = self::str($smtp, 'password');
        if ($cfg->isHaraka()) {
            $cfg->smtpUseAuth = true;
            if (trim($cfg->smtpUsername) === '') {
                $cfg->smtpUsername = $cfg->senderFromAddress;
            }
        }
        $cfg->smtpUseTls = self::bool($smtp, 'use_tls', false);
        $cfg->smtpSecurity = strtoupper(self::str($smtp, 'security'));
        $cfg->smtpSkipVerify = self::bool($smtp, 'skip_verify', true);
        $cfg->smtpTimeout = self::int($smtp, 'timeout', 30);
        $cfg->smtpMaxConn = max(0, self::int($smtp, 'max_conn', 5));
        $cfg->timezone = self::str($raw, 'timezone');
        if ($cfg->timezone === '') {
            $cfg->timezone = self::str(self::arr($raw, 'email'), 'timezone', 'Asia/Tokyo');
        }
        if ($cfg->timezone === '') {
            $cfg->timezone = 'Asia/Tokyo';
        }

        $perf = self::arr($raw, 'performance');
        $cfg->workers = max(1, self::int($perf, 'workers', 1));
        $cfg->rateLimit = self::int($perf, 'rate_limit', 0);
        $cfg->minIntervalMs = self::int($perf, 'min_interval', self::int($perf, 'interval_min_ms', 0));
        $cfg->maxIntervalMs = self::int($perf, 'max_interval', self::int($perf, 'interval_max_ms', 0));
        $cfg->retryCount = self::int($perf, 'retry_count', 0);
        $cfg->progressIntervalSec = max(1, self::int($perf, 'progress_interval', 1));

        $email = self::arr($raw, 'email');
        $cfg->emailSubject = self::str($email, 'subject');
        $cfg->emailSubjects = self::strList($email, 'subjects');
        $cfg->emailSubjectMode = self::str($email, 'subject_mode', 'sequential');
        $cfg->emailTemplatePath = self::str($email, 'template_path');
        $cfg->emailTemplatePaths = self::strList($email, 'template_paths');
        if ($cfg->emailTemplatePaths === [] && $cfg->emailTemplatePath !== '') {
            $cfg->emailTemplatePaths = [$cfg->emailTemplatePath];
        }
        $cfg->emailTemplateMode = self::str($email, 'template_mode', 'sequential');
        $cfg->emailCharset = self::str($email, 'charset', 'UTF-8');
        $cfg->emailConvertTxtToHtml = self::bool($email, 'convert_txt_to_html', false);
        $cfg->emailMimeMode = self::str($email, 'mime_mode', 'standard');
        $cfg->emailIncludeTextPart = self::bool($email, 'include_text_part', false);
        $cfg->emailCidInboxMode = self::str($email, 'cid_inbox_mode', 'html');
        $cfg->emailEmbeddedImages = self::embeddedImages($email);

        $encoding = self::arr($raw, 'encoding');
        $cfg->encodingCharset = self::str($encoding, 'charset', $cfg->emailCharset);
        $cfg->encodingHeader = self::str($encoding, 'header_encoding', 'base64');
        $cfg->encodingBody = self::str($encoding, 'body_encoding', 'base64');
        $cfg->encodingTransfer = self::str($encoding, 'transfer_encoding', 'base64');
        $cfg->encodingDisableCharsetConvert = self::bool($encoding, 'disable_charset_convert', false);
        $cfg->randomEncoding = self::bool($encoding, 'random_encoding', false);

        $recipients = self::arr($raw, 'recipients');
        $cfg->recipientsFile = self::str($recipients, 'file_path');
        $cfg->recipientsSkipLines = self::int($recipients, 'skip_lines', 0);
        $csv = self::arr($recipients, 'csv');
        $cfg->recipientsDelimiter = self::str($csv, 'delimiter', ',');
        $cfg->recipientsHasHeader = self::bool($csv, 'has_header', true);

        $job = self::arr($raw, 'job');
        $cfg->jobId = self::str($job, 'id');
        $cfg->jobIdPrefix = self::str($job, 'id_prefix', 'job');
        if ($cfg->jobId === '') {
            $cfg->jobId = $cfg->jobIdPrefix . '-' . date('Ymd-His');
        }

        $output = self::arr($raw, 'output');
        $cfg->progressFile = self::str($output, 'progress_file');
        $cfg->resultFile = self::str($output, 'result_file');
        $cfg->errorFile = self::str($output, 'error_file');

        $template = self::arr($raw, 'template');
        $cfg->globalVariables = self::arr($template, 'global_variables');

        $variables = self::arr($raw, 'variables');
        $cfg->amountMin = (float) self::num($variables, 'amount_min', 0);
        $cfg->amountMax = (float) self::num($variables, 'amount_max', 0);
        $cfg->amountUseSeparator = self::bool($variables, 'amount_use_separator', false);
        $cfg->customVariableFiles = self::stringMap(self::arr($variables, 'custom_variable_files'));
        $cfg->customVariableModes = self::stringMap(self::arr($variables, 'custom_variable_modes'));

        $headers = self::arr($raw, 'headers');
        $cfg->headersEnabled = self::bool($headers, 'enabled', true);
        $cfg->headerMessageId = self::bool($headers, 'message_id', true);
        $cfg->headerMessageIdRandomAll = self::bool($headers, 'message_id_random_all', false);
        $cfg->headerMessageIdStyles = self::strList($headers, 'message_id_styles');
        $cfg->headerMessageIdDomainMode = self::str($headers, 'message_id_domain_mode', 'subdomain');
        $cfg->headerMessageIdMainDomain = self::str($headers, 'message_id_main_domain');
        $cfg->headerMessageIdFullDomain = self::str($headers, 'message_id_full_domain');
        $cfg->headerClientProfile = self::str($headers, 'client_profile');
        $cfg->headerReplyTo = self::bool($headers, 'reply_to', false);
        $cfg->headerReplyToMode = self::str($headers, 'reply_to_mode', 'from_address');
        $cfg->headerReturnPath = self::bool($headers, 'return_path', false);
        $cfg->headerReturnPathMode = self::str($headers, 'return_path_mode', 'from_address');
        $cfg->headerXMailer = self::bool($headers, 'x_mailer', false);
        $cfg->headerUserAgent = self::bool($headers, 'user_agent', false);
        $cfg->headerMobileClient = self::bool($headers, 'mobile_client_headers', false);
        $cfg->headerReceived = self::bool($headers, 'received', false);
        $cfg->headerContentLanguage = self::bool($headers, 'content_language', false);
        $cfg->headerContentLangValue = self::str($headers, 'content_language_value', 'ja');
        $cfg->headerAcceptLanguage = self::bool($headers, 'accept_language', false);
        $cfg->headerAcceptLanguageValue = self::str($headers, 'accept_language_value', 'ja');
        $cfg->headerImportance = self::bool($headers, 'importance', false);
        $cfg->headerImportanceValue = self::str($headers, 'importance_value', '随机选择');
        $cfg->headerShuffleOrder = self::bool($headers, 'shuffle_order', true);
        $cfg->headerListUnsubscribe = self::bool($headers, 'list_unsubscribe', false);
        $cfg->unsubscribeHost = self::str($headers, 'unsubscribe_host');
        $cfg->unsubscribePath = self::str($headers, 'unsubscribe_path', '/unsubscribe');
        $cfg->unsubscribeKey = self::str($headers, 'unsubscribe_key');
        $cfg->unsubscribeQueryParam = self::str($headers, 'unsubscribe_query_param', 'email');
        $cfg->unsubscribeUseDecimal = self::bool($headers, 'unsubscribe_use_decimal', false);
        $cfg->injectBodyUnsubscribe = self::bool($headers, 'inject_body_unsubscribe', false);
        $cfg->unsubscribeFooterStyle = self::str($headers, 'unsubscribe_footer_style', 'standard');
        $cfg->rcvdChainEnable = self::bool($headers, 'rcvd_chain_enable', false);
        $cfg->rcvdChainType = self::str($headers, 'rcvd_chain_type', 'smart_auto');
        $cfg->headerXPriority = self::bool($headers, 'x_priority', false);
        $cfg->headerXPriorityValue = self::str($headers, 'x_priority_value', '随机选择');
        $cfg->customHeadersText = self::str($headers, 'custom_headers_text');
        $cfg->customFromEnabled = self::bool($headers, 'custom_from_enabled', false);
        $cfg->customFromMode = self::str($headers, 'custom_from_mode', 'sequential');
        $cfg->customFromEmails = self::strList($headers, 'custom_from_emails');
        $cfg->customFromDomains = self::strList($headers, 'custom_from_domains');
        $cfg->fromAddressRandomPrefix = self::bool($headers, 'from_address_random_prefix', false);
        $cfg->displayNameNewline = self::bool($headers, 'display_name_newline', false);
        $cfg->dkimSignHeaders = self::strList($headers, 'dkim_sign_headers');

        $cfg->recipientDisplayMode = self::str($headers, 'recipient_display_mode', 'only_email');
        $cfg->recipientHonorific = self::str($headers, 'recipient_honorific', 'sama');
        $cfg->recipientCustomPhrases = self::strList($headers, 'recipient_custom_phrases');

        $qrcode = self::arr($raw, 'qrcode');
        $cfg->qrCodeEnabled = self::bool($qrcode, 'enabled', false);
        $cfg->qrCodeUrl = self::str($qrcode, 'url', 'https://example.com/verify?id={RANDOM_6}&u={EMAIL}');
        $cfg->qrCodeSize = max(50, self::int($qrcode, 'size', 200));

        $postlink = self::arr($raw, 'postlink');
        $cfg->postlinkEnabled = self::bool($postlink, 'enabled', false);
        $cfg->postlinkSecretKey = self::str($postlink, 'secret_key', '7L0LENuQc4No52BixiLarNlhAtB4Q9Ya');
        $cfg->postlinkUrlTemplate = self::str($postlink, 'url_template', 'https://{DOMAIN}/jump.php?token={TOKEN}&s={RANDOM_4}');
        $cfg->postlinkRandomDigits = max(1, self::int($postlink, 'random_digits', 4));
        $cfg->postlinkDomains = self::strList($postlink, 'domains');

        $attachments = self::arr($raw, 'attachments');
        $cfg->attachmentsEnabled = self::bool($attachments, 'enabled', false);
        $cfg->attachmentsMode = self::str($attachments, 'mode', 'sequential');
        $cfg->attachmentFiles = self::strList($attachments, 'files');
        $customAtt = self::arr($attachments, 'custom');
        $cfg->attachmentCustomEnabled = self::bool($customAtt, 'enabled', false);
        $cfg->attachmentCustomBinaryMode = self::bool($customAtt, 'binary_mode', true);
        $cfg->attachmentCustomNameMode = self::str($customAtt, 'name_mode', 'random');
        $cfg->attachmentCustomNameMin = self::int($customAtt, 'name_min', 8);
        $cfg->attachmentCustomNameMax = self::int($customAtt, 'name_max', 8);
        $cfg->attachmentCustomFixedName = self::str($customAtt, 'fixed_name');
        $cfg->attachmentCustomSuffixMode = self::str($customAtt, 'suffix_mode', 'random');
        $cfg->attachmentCustomSuffixMin = self::int($customAtt, 'suffix_min', 4);
        $cfg->attachmentCustomSuffixMax = self::int($customAtt, 'suffix_max', 6);
        $cfg->attachmentCustomFixedSuffix = self::str($customAtt, 'fixed_suffix');
        $cfg->attachmentCustomContentBytesMin = self::int($customAtt, 'content_bytes_min', 1);
        $cfg->attachmentCustomContentBytesMax = self::int($customAtt, 'content_bytes_max', 1024);

        $bcc = self::arr($raw, 'bcc');
        $cfg->bccEnabled = self::bool($bcc, 'enabled', false);
        $cfg->bccFilePath = self::str($bcc, 'file_path');
        $cfg->bccPerEmail = max(1, min(100, self::int($bcc, 'per_email', 1)));

        $zeroWidth = self::arr($raw, 'zero_width');
        $cfg->zeroWidthEnabled = self::bool($zeroWidth, 'enabled', true);
        $cfg->zeroWidthSubject = self::bool($zeroWidth, 'subject', false);
        $cfg->zeroWidthDisplayName = self::bool($zeroWidth, 'display_name', false);
        $cfg->zeroWidthTemplate = self::bool($zeroWidth, 'template_content', true);
        $cfg->zeroWidthKeywords = self::str($zeroWidth, 'keywords');

        $reverseBidi = self::arr($raw, 'reverse_bidi');
        $cfg->reverseBidiSubject = self::bool($reverseBidi, 'subject', false) || self::bool($email, 'subject_bidi_reverse', false);
        $cfg->reverseBidiDisplayName = self::bool($reverseBidi, 'display_name', false) || self::bool($sender, 'display_name_bidi_reverse', false);
        $cfg->reverseBidiTemplate = self::bool($reverseBidi, 'template_content', false);

        $imageNoise = self::arr($raw, 'image_noise');
        $cfg->imageNoiseEnabled = self::bool($imageNoise, 'enabled', false);

        $htmlMutator = self::arr($raw, 'html_mutator');
        $cfg->htmlMutatorEnabled = self::bool($htmlMutator, 'enabled', false);
        $cfg->htmlMutatorCssJitter = self::bool($htmlMutator, 'css_jitter', true);
        $cfg->htmlMutatorInjectAttrs = self::bool($htmlMutator, 'inject_attrs', true);
        $cfg->htmlMutatorTagSwap = self::bool($htmlMutator, 'tag_swap', true);
        $cfg->htmlMutatorPreserveTables = self::bool($htmlMutator, 'preserve_tables', true);

        $backtest = self::arr($raw, 'backtest');
        $cfg->backtestEnabled = self::bool($backtest, 'enabled', false);
        $cfg->backtestFrequency = max(1, self::int($backtest, 'frequency', 2000));
        $cfg->backtestEmail = trim(self::str($backtest, 'email'));

        $logging = self::arr($raw, 'logging');
        $cfg->loggingSaveLog = self::bool($logging, 'save_log', true);
        $cfg->loggingSaveFailed = self::bool($logging, 'save_failed', true);
        $cfg->loggingDirectory = self::str($logging, 'directory', 'logs');

        $arc = self::arr($raw, 'arc');
        $cfg->arcEnabled = self::bool($arc, 'enabled', false);
        $cfg->arcDomain = self::str($arc, 'domain');
        $cfg->arcSelector = self::str($arc, 'selector', 'dkim');
        $cfg->arcPrivateKeyPath = self::str($arc, 'private_key_path');
        $cfg->arcPrivateKeyPem = self::str($arc, 'private_key_pem');

        return $cfg;
    }

    /** @param array<string, mixed> $parent */
    private static function arr(array $parent, string $key): array
    {
        $v = $parent[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    /** @param array<string, mixed> $parent */
    private static function str(array $parent, string $key, string $default = ''): string
    {
        $v = $parent[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** @param array<string, mixed> $parent */
    private static function int(array $parent, string $key, int $default = 0): int
    {
        $v = $parent[$key] ?? $default;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @param array<string, mixed> $parent */
    private static function num(array $parent, string $key, int|float $default = 0): int|float
    {
        $v = $parent[$key] ?? $default;
        return is_numeric($v) ? $v + 0 : $default;
    }

    /** @param array<string, mixed> $parent */
    private static function bool(array $parent, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $parent)) {
            return $default;
        }
        $v = $parent[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $v;
    }

    /** @param array<string, mixed> $parent @return list<string> */
    private static function strList(array $parent, string $key): array
    {
        $v = $parent[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item) && (string) $item !== '') {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $parent @return list<array{cid:string,path:string,content_type:string}> */
    private static function embeddedImages(array $parent): array
    {
        $items = $parent['embedded_images'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cid = self::str($item, 'cid');
            $path = self::str($item, 'path');
            if ($cid === '' || $path === '') {
                continue;
            }
            $out[] = [
                'cid' => $cid,
                'path' => $path,
                'content_type' => self::str($item, 'content_type', 'image/png'),
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $map @return array<string, string> */
    private static function stringMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }
}
