<?php

declare(strict_types=1);

namespace Warship\Injector\Config;

final class Config
{
    public string $jobId = '';
    public string $jobIdPrefix = 'job';

    public string $senderFromAddress = '';
    public string $senderFromName = '';
    public string $senderEnvelopeFrom = '';
    /** @var list<string> */
    public array $senderDisplayNames = [];
    public string $senderDisplayNameMode = 'sequential';

    public bool $smtpEnabled = true;
    public string $smtpHost = '127.0.0.1';
    public int $smtpPort = 587;
    public bool $smtpUseAuth = true;
    public string $smtpUsername = '';
    public string $smtpPassword = '';
    public bool $smtpUseTls = false;
    /** STARTTLS | SSL | empty (infer from use_tls / port 465) */
    public string $smtpSecurity = '';
    public bool $smtpSkipVerify = true;
    public int $smtpTimeout = 30;
    public int $smtpMaxConn = 5;
    public string $timezone = 'Asia/Tokyo';
    /** pmta | haraka；仅 haraka 走进箱补头，pmta 保持原样 */
    public string $mtaType = 'pmta';

    public int $workers = 1;
    public int $shardIndex = 0;
    public int $shardCount = 1;
    public string $configPath = '';
    public int $rateLimit = 0;
    public int $minIntervalMs = 0;
    public int $maxIntervalMs = 0;
    public int $retryCount = 0;
    public int $progressIntervalSec = 1;

    public string $emailSubject = '';
    /** @var list<string> */
    public array $emailSubjects = [];
    public string $emailSubjectMode = 'sequential';
    public string $emailTemplatePath = '';
    /** @var list<string> */
    public array $emailTemplatePaths = [];
    public string $emailTemplateMode = 'sequential';
    public string $emailCharset = 'UTF-8';
    public bool $emailConvertTxtToHtml = false;
    public string $emailMimeMode = 'standard';
    public bool $emailIncludeTextPart = false;
    public string $emailCidInboxMode = 'html';
    /** @var list<array{cid:string,path:string,content_type:string}> */
    public array $emailEmbeddedImages = [];

    public string $encodingCharset = 'UTF-8';
    public string $encodingHeader = 'base64';
    public string $encodingBody = 'base64';
    public string $encodingTransfer = 'base64';
    public bool $encodingDisableCharsetConvert = false;
    public bool $randomEncoding = false;

    public string $recipientsFile = '';
    public int $recipientsSkipLines = 0;
    public string $recipientsDelimiter = ',';
    public bool $recipientsHasHeader = true;

    public string $progressFile = '';
    public string $resultFile = '';
    public string $errorFile = '';

    /** @var array<string, mixed> */
    public array $globalVariables = [];

    public float $amountMin = 0.0;
    public float $amountMax = 0.0;
    public bool $amountUseSeparator = false;
    /** @var array<string, string> name => file path */
    public array $customVariableFiles = [];
    /** @var array<string, string> name => sequential|random */
    public array $customVariableModes = [];

    public bool $headersEnabled = true;
    public bool $headerMessageId = true;
    public bool $headerMessageIdRandomAll = false;
    /** @var list<string> */
    public array $headerMessageIdStyles = [];
    public string $headerMessageIdDomainMode = 'subdomain';
    public string $headerMessageIdMainDomain = '';
    public string $headerMessageIdFullDomain = '';
    public string $headerClientProfile = '';
    public bool $headerReplyTo = false;
    public string $headerReplyToMode = 'from_address';
    public bool $headerReturnPath = false;
    public string $headerReturnPathMode = 'from_address';
    public bool $headerXMailer = false;
    public bool $headerUserAgent = false;
    public bool $headerMobileClient = false;
    public bool $headerReceived = false;
    public bool $headerContentLanguage = false;
    public string $headerContentLangValue = 'ja';
    public bool $headerAcceptLanguage = false;
    public string $headerAcceptLanguageValue = 'ja';
    public bool $headerImportance = false;
    public string $headerImportanceValue = '随机选择';
    public bool $headerShuffleOrder = true;
    public bool $headerListUnsubscribe = false;
    public string $unsubscribeHost = '';
    public string $unsubscribePath = '/unsubscribe';
    public string $unsubscribeKey = '';
    public string $unsubscribeQueryParam = 'email';
    public bool $unsubscribeUseDecimal = false;
    public bool $injectBodyUnsubscribe = false;
    public string $unsubscribeFooterStyle = 'standard';
    public bool $rcvdChainEnable = false;
    public string $rcvdChainType = 'smart_auto';
    public string $rcvdChainIpMode = 'dynamic';
    public string $rcvdChainIpPool = 'smart_pool';
    public int $rcvdChainHops = 1;
    public string $rcvdChainMtaFlavor = 'dynamic';
    public string $rcvdChainDomainStyle = 'dynamic';
    public string $rcvdChainCustom = '';
    public bool $rcvdChainStripAuthResults = true;
    public bool $rcvdChainStripReceived = true;
    public bool $rcvdChainStripClientIp = true;
    public string $headerXPriorityValue = '随机选择';
    public string $customHeadersText = '';
    public bool $customFromEnabled = false;
    public string $customFromMode = 'sequential';
    /** @var list<string> */
    public array $customFromEmails = [];
    /** @var list<string> */
    public array $customFromDomains = [];
    public bool $fromAddressRandomPrefix = false;
    public bool $displayNameNewline = false;

    public string $recipientDisplayMode = 'only_email';
    public string $recipientHonorific = 'sama';
    /** @var list<string> */
    public array $recipientCustomPhrases = [];

    public bool $qrCodeEnabled = false;
    public string $qrCodeUrl = 'https://example.com/verify?id={RANDOM_6}&u={EMAIL}';
    public int $qrCodeSize = 200;

    public bool $postlinkEnabled = false;
    public string $postlinkSecretKey = '7L0LENuQc4No52BixiLarNlhAtB4Q9Ya';
    public string $postlinkUrlTemplate = 'https://{DOMAIN}/jump.php?token={TOKEN}&s={RANDOM_4}';
    public int $postlinkRandomDigits = 4;
    /** @var list<string> */
    public array $postlinkDomains = [];

    public bool $attachmentsEnabled = false;
    public string $attachmentsMode = 'sequential';
    /** @var list<string> */
    public array $attachmentFiles = [];
    public bool $attachmentCustomEnabled = false;
    public bool $attachmentCustomBinaryMode = true;
    public string $attachmentCustomNameMode = 'random';
    public int $attachmentCustomNameMin = 8;
    public int $attachmentCustomNameMax = 8;
    public string $attachmentCustomFixedName = '';
    public string $attachmentCustomSuffixMode = 'random';
    public int $attachmentCustomSuffixMin = 4;
    public int $attachmentCustomSuffixMax = 6;
    public string $attachmentCustomFixedSuffix = '';
    public int $attachmentCustomContentBytesMin = 1;
    public int $attachmentCustomContentBytesMax = 1024;

    public bool $bccEnabled = false;
    public string $bccFilePath = '';
    public int $bccPerEmail = 1;

    public bool $zeroWidthEnabled = true;
    public bool $zeroWidthSubject = false;
    public bool $zeroWidthDisplayName = false;
    public bool $zeroWidthTemplate = true;
    public string $zeroWidthKeywords = '';

    public bool $reverseBidiSubject = false;
    public bool $reverseBidiDisplayName = false;
    public bool $reverseBidiTemplate = false;

    public bool $imageNoiseEnabled = false;

    public bool $htmlMutatorEnabled = false;
    public bool $htmlMutatorCssJitter = true;
    public bool $htmlMutatorInjectAttrs = true;
    public bool $htmlMutatorTagSwap = true;
    public bool $htmlMutatorPreserveTables = true;

    public bool $backtestEnabled = false;
    public int $backtestFrequency = 2000;
    public string $backtestEmail = '';

    public bool $loggingSaveLog = true;
    public bool $loggingSaveFailed = true;
    public string $loggingDirectory = 'logs';

    public bool $arcEnabled = false;
    public string $arcDomain = '';
    public string $arcSelector = '';
    public string $arcPrivateKeyPath = '';
    public string $arcPrivateKeyPem = '';
    /** @var list<string> */
    public array $dkimSignHeaders = [];

    public function isHaraka(): bool
    {
        return strtolower(trim($this->mtaType)) === 'haraka';
    }

    public function backtestActive(): bool
    {
        return $this->backtestEnabled
            && $this->backtestFrequency >= 1
            && $this->backtestEmail !== ''
            && filter_var($this->backtestEmail, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function shouldInsertAfterIndex(int $index): bool
    {
        return $this->backtestActive() && (($index + 1) % $this->backtestFrequency) === 0;
    }

    public function expectedBacktestCount(int $mainCount, int $shardIndex = 0, int $shardCount = 1): int
    {
        if (!$this->backtestActive() || $mainCount < $this->backtestFrequency) {
            return 0;
        }
        $maxK = intdiv($mainCount, $this->backtestFrequency);
        $shards = max(1, $shardCount);
        if ($shards <= 1) {
            return $maxK;
        }
        $n = 0;
        $shard = min(max(0, $shardIndex), $shards - 1);
        for ($k = 1; $k <= $maxK; $k++) {
            if ((($k * $this->backtestFrequency - 1) % $shards) === $shard) {
                $n++;
            }
        }
        return $n;
    }
}
