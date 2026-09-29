<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use Warship\Injector\Config\Config;
use Warship\Injector\Recipient\Recipient;
use Warship\Injector\Variable\Processor;

final class MailSender
{
    /** @var array<string, string> */
    private array $templateCache = [];
    private int $customFromIndex = 0;
    private string $lastTemplatePath = '';
    /** @var list<string> */
    private array $zeroWidthKeywords;
    private ?PHPMailer $pooled = null;

    public function __construct(
        private readonly Config $cfg,
        private readonly Processor $variables,
    ) {
        $this->zeroWidthKeywords = ZeroWidth::parseKeywords($cfg->zeroWidthKeywords);
        if ($cfg->timezone !== '') {
            date_default_timezone_set($cfg->timezone);
        }
    }

    public function __destruct()
    {
        $this->dropPooled();
    }

    public function send(Recipient $recipient, ?string $overrideTo = null): void
    {
        $keepAlive = $this->useKeepAlive();
        $mail = $this->acquireMailer($keepAlive);
        $to = $overrideTo ?? $recipient->email;

        $displayName = $this->variables->getNextDisplayName();
        $subject = $this->variables->getNextSubject();
        $fromAddress = $this->resolveFromAddress();

        $displayName = $this->variables->process($displayName, $recipient);
        $subject = $this->variables->process($subject, $recipient);
        $fromAddress = $this->variables->process($fromAddress, $recipient);

        if ($this->cfg->displayNameNewline) {
            $displayName = str_replace('\r\n', "\r\n", $displayName);
        }

        if ($this->cfg->reverseBidiDisplayName) {
            if ($this->zeroWidthKeywords !== []) {
                $displayName = ReverseBidi::applyAtKeywordsEveryTwoChars($displayName, $this->zeroWidthKeywords, false);
            }
            if (!ReverseBidi::hasRtlOverride($displayName)) {
                $displayName = ReverseBidi::obfuscateText($displayName);
            } else {
                $displayName = ReverseBidi::finalizeHeaderField($displayName);
            }
        } elseif ($this->cfg->zeroWidthEnabled && $this->cfg->zeroWidthDisplayName) {
            if ($this->zeroWidthKeywords !== []) {
                $displayName = ZeroWidth::insertAtKeywords($displayName, $this->zeroWidthKeywords, false);
            }
            if (!ZeroWidth::hasZeroWidth($displayName)) {
                $displayName = ZeroWidth::insertIntoText($displayName);
            }
        } else {
            if (ReverseBidi::hasRtlOverride($displayName)) {
                $displayName = ReverseBidi::finalizeHeaderField($displayName);
            }
        }

        if ($this->cfg->reverseBidiSubject) {
            if ($this->zeroWidthKeywords !== []) {
                $subject = ReverseBidi::applyAtKeywordsEveryTwoChars($subject, $this->zeroWidthKeywords, false);
            }
            if (!ReverseBidi::hasRtlOverride($subject)) {
                $subject = ReverseBidi::obfuscateText($subject);
            } else {
                $subject = ReverseBidi::finalizeHeaderField($subject);
            }
        } elseif ($this->cfg->zeroWidthEnabled && $this->cfg->zeroWidthSubject) {
            if ($this->zeroWidthKeywords !== []) {
                $subject = ZeroWidth::insertAtKeywords($subject, $this->zeroWidthKeywords, false);
            }
            if (!ZeroWidth::hasZeroWidth($subject)) {
                $subject = ZeroWidth::insertIntoText($subject);
            }
        } else {
            if (ReverseBidi::hasRtlOverride($subject)) {
                $subject = ReverseBidi::finalizeHeaderField($subject);
            }
        }
        if ($recipient->isBacktest) {
            $subject = self::prefixBacktestSubject($subject);
        }

        $html = $this->buildHtmlBody($recipient);
        $html = $this->variables->process($html, $recipient);
        $html = HtmlMutator::apply($html, $this->cfg, $to . "\0" . $subject);
        $html = $this->applyTemplateObfuscation($html, true);
        $html = InlineCid::syncReferences($html, $this->inlineImageCatalog());
        $html = InlineCid::sanitizeReferences($html);

        if ($this->cfg->fromAddressRandomPrefix) {
            $atPos = strrpos($fromAddress, '@');
            if ($atPos !== false) {
                $origDomain = substr($fromAddress, $atPos + 1);
                $fromAddress = self::generateRandomEmailLocalPart() . '@' . $origDomain;
            }
        }

        $charset = $this->cfg->encodingCharset ?: $this->cfg->emailCharset ?: 'UTF-8';
        $mail->CharSet = $charset;
        $enc = $this->cfg->encodingBody ?: $this->cfg->encodingTransfer;
        if ($this->cfg->randomEncoding) {
            $enc = random_int(0, 1) === 1 ? 'quoted-printable' : 'base64';
        }
        $mail->Encoding = $this->mapEncoding($enc);
        $mail->Hostname = $this->rootDomainFromAddress($fromAddress);
        $mail->XMailer = HarakaCompat::resolveXMailer($this->cfg);
        $mail->headerEncoding = $this->cfg->encodingHeader;

        [$subject, $html] = $this->convertCharset($subject, $html, $charset);

        $mail->setFrom($fromAddress, $displayName, false);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $this->applyMimeBody($mail, $html, $recipient);

        $this->applyDeliveryHeaders($mail, $recipient, $fromAddress, $to, $subject);
        $this->applyEmbeddedImages($mail);
        $this->applyAttachments($mail);

        if (!$this->cfg->headerReturnPath || empty($mail->Sender)) {
            $envelope = $this->cfg->senderEnvelopeFrom ?: $fromAddress;
            $mail->Sender = $this->variables->process($envelope, $recipient);
        }

        $attempts = max(1, $this->cfg->retryCount + 1);
        $lastError = '';
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $mail->send();
                if (!$keepAlive) {
                    $this->closeMailer($mail);
                }
                return;
            } catch (MailException $e) {
                $lastError = $mail->ErrorInfo ?: $e->getMessage();
                $wasPooled = $this->pooled === $mail;
                $this->dropPooled();
                if (!$wasPooled) {
                    $this->closeMailer($mail);
                }
                if ($i + 1 < $attempts) {
                    usleep(200_000);
                    $mail = $this->acquireMailer($keepAlive);
                    $mail->CharSet = $charset;
                    $mail->Encoding = $this->mapEncoding($enc);
                    $mail->Hostname = $this->rootDomainFromAddress($fromAddress);
                    $mail->XMailer = HarakaCompat::resolveXMailer($this->cfg);
                    $mail->headerEncoding = $this->cfg->encodingHeader;
                    $mail->setFrom($fromAddress, $displayName, false);
                    $mail->addAddress($to);
                    $mail->Subject = $subject;
                    $this->applyMimeBody($mail, $html, $recipient);
                    $this->applyDeliveryHeaders($mail, $recipient, $fromAddress, $to, $subject);
                    $this->applyEmbeddedImages($mail);
                    $this->applyAttachments($mail);
                    $mail->Sender = $this->variables->process($envelope, $recipient);
                }
            }
        }

        throw new \RuntimeException($lastError !== '' ? $lastError : 'SMTP send failed');
    }

    private function useKeepAlive(): bool
    {
        return $this->cfg->smtpEnabled && $this->cfg->smtpMaxConn > 0;
    }

    private function acquireMailer(bool $keepAlive): PHPMailer
    {
        if ($keepAlive && $this->pooled !== null) {
            $this->resetMailer($this->pooled);
            return $this->pooled;
        }

        $mail = $this->createMailer($keepAlive);
        if ($keepAlive) {
            $this->pooled = $mail;
        }
        return $mail;
    }

    private function resetMailer(PHPMailer $mail): void
    {
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearCustomHeaders();
        $mail->clearReplyTos();
        $mail->Body = '';
        $mail->AltBody = '';
        $mail->Subject = '';
        $mail->MessageID = '';
        $mail->Sender = '';
    }

    private function dropPooled(): void
    {
        if ($this->pooled === null) {
            return;
        }
        $this->closeMailer($this->pooled);
        $this->pooled = null;
    }

    private function closeMailer(PHPMailer $mail): void
    {
        try {
            $mail->smtpClose();
        } catch (\Throwable) {
        }
    }

    private function createMailer(bool $keepAlive): PHPMailer
    {
        $mail = new WarshipMailer(true);
        $mail->arcSealer = ArcSealer::fromConfig($this->cfg);
        $mail->CharSet = $this->cfg->encodingCharset ?: $this->cfg->emailCharset;
        $mail->Encoding = $this->mapEncoding($this->cfg->encodingBody ?: $this->cfg->encodingTransfer);
        $mail->XMailer = HarakaCompat::resolveXMailer($this->cfg);
        $mail->headerEncoding = $this->cfg->encodingHeader;
        $mail->Timeout = $this->cfg->smtpTimeout > 0 ? $this->cfg->smtpTimeout : 30;
        $mail->SMTPDebug = 0;

        if ($this->cfg->smtpEnabled) {
            $mail->isSMTP();
            $mail->Host = $this->cfg->smtpHost;
            $mail->Port = $this->cfg->smtpPort;
            $mail->SMTPAuth = $this->cfg->smtpUseAuth || $this->cfg->isHaraka() || $this->smtpLooksLikeHaraka();
            $mail->Username = $this->cfg->smtpUsername !== '' ? $this->cfg->smtpUsername : $this->cfg->senderFromAddress;
            $mail->Password = $this->cfg->smtpPassword;
            $mail->SMTPKeepAlive = $keepAlive;
            $this->applySmtpSecurity($mail);
        }

        return $mail;
    }

    private function applySmtpSecurity(PHPMailer $mail): void
    {
        $security = strtoupper(trim($this->cfg->smtpSecurity));
        if ($security === '') {
            if ($this->cfg->smtpUseTls) {
                $security = 'STARTTLS';
            } elseif ($this->cfg->smtpPort === 465) {
                $security = 'SSL';
            }
        }

        if ($security === 'STARTTLS') {
            $mail->SMTPAutoTLS = false;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($security === 'SSL' || $security === 'SMTPS') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        if (!$this->cfg->smtpSkipVerify) {
            return;
        }

        $crypto = 0;
        if (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLS_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        $ssl = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
        if ($crypto !== 0) {
            $ssl['crypto_method'] = $crypto;
        }
        $mail->SMTPOptions = ['ssl' => $ssl];
    }

    /** YAML 写成 pmta 但 587 仍是 Haraka 时，用欢迎语补 AUTH。 */
    private function smtpLooksLikeHaraka(): bool
    {
        $host = $this->cfg->smtpHost !== '' ? $this->cfg->smtpHost : '127.0.0.1';
        $port = $this->cfg->smtpPort > 0 ? $this->cfg->smtpPort : 587;
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($fp === false) {
            return false;
        }
        stream_set_timeout($fp, 2);
        $banner = (string) fgets($fp, 512);
        fclose($fp);
        return stripos($banner, 'Haraka') !== false;
    }

    // applyHarakaInboxHeaders has been superseded by applyDeliveryHeaders()

    /** @return array{0:string,1:string} */
    private function convertCharset(string $subject, string $html, string $targetCharset): array
    {
        $needConvert = $targetCharset !== ''
            && strcasecmp($targetCharset, 'UTF-8') !== 0
            && !$this->cfg->encodingDisableCharsetConvert
            && function_exists('mb_convert_encoding');
        if (!$needConvert) {
            return [$subject, $html];
        }
        return [
            (string) mb_convert_encoding($subject, $targetCharset, 'UTF-8'),
            (string) mb_convert_encoding($html, $targetCharset, 'UTF-8'),
        ];
    }

    private function rootDomainFromAddress(string $fromAddress): string
    {
        $parts = explode('@', $fromAddress, 2);
        $host = strtolower(trim($parts[1] ?? ''));
        if ($host === '') {
            return 'localhost';
        }
        $labels = explode('.', $host);
        if (count($labels) >= 2) {
            return $labels[count($labels) - 2] . '.' . $labels[count($labels) - 1];
        }
        return $host;
    }

    private function mapEncoding(string $enc): string
    {
        return match (strtolower($enc)) {
            'quoted-printable', 'qp' => PHPMailer::ENCODING_QUOTED_PRINTABLE,
            '7bit' => PHPMailer::ENCODING_7BIT,
            '8bit' => PHPMailer::ENCODING_8BIT,
            default => PHPMailer::ENCODING_BASE64,
        };
    }

    private function resolveFromAddress(): string
    {
        if (!$this->cfg->customFromEnabled || $this->cfg->customFromEmails === []) {
            return $this->cfg->senderFromAddress;
        }

        $emails = $this->cfg->customFromEmails;
        if ($this->cfg->customFromMode === 'sequential') {
            $email = $emails[$this->customFromIndex % count($emails)];
            $this->customFromIndex++;
            return $email;
        }
        return $emails[random_int(0, count($emails) - 1)];
    }

    private function buildHtmlBody(Recipient $recipient): string
    {
        $path = $this->variables->getNextTemplatePath();
        $this->lastTemplatePath = $path;
        if (!isset($this->templateCache[$path])) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException("Cannot read template: {$path}");
            }
            $this->templateCache[$path] = $content;
        }

        $html = $this->templateCache[$path];
        if ($this->cfg->emailConvertTxtToHtml && !str_contains(strtolower($html), '<html')) {
            $html = self::textToHtml($html, $this->cfg->emailCharset);
        }
        return $html;
    }

    /**
     * MIME 树:
     *   HTML + 纯文本 + CID — alternative(plain + related(html+内联图))
     *   HTML + 纯文本       — alternative(plain + html)
     *   仅 HTML + CID       — 仍补 plain，走上面第一棵树
     *   有普通附件          — mixed 最外层
     */
    private function applyMimeBody(PHPMailer $mail, string $html, Recipient $recipient): void
    {
        $mode = strtolower(trim($this->cfg->emailMimeMode));
        if ($mode === '') {
            $mode = 'standard';
        }
        if ($mail instanceof WarshipMailer) {
            $mail->mimeMode = $mode;
        }
        $hasCid = $this->cfg->emailEmbeddedImages !== [] || $this->willEmbedAttachmentImages();
        $useAlternative = $mode === 'alternative' || $mode === 'full' || $hasCid;
        $plain = $this->loadTextTemplate($recipient);
        if (trim($plain) === '' && $useAlternative) {
            $plain = $this->htmlToPlainText($html);
        }
        $usePlainPart = trim($plain) !== '' && ($useAlternative || $this->cfg->emailIncludeTextPart);

        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $usePlainPart ? $this->maybeConvertCharset($plain) : '';
    }

    /**
     * 不剥已有倒序/零宽；只在原文上对仍明文存在的关键字套混淆。
     */
    private function applyTemplateObfuscation(string $text, bool $isHtml): string
    {
        if ($this->cfg->reverseBidiTemplate) {
            if ($this->zeroWidthKeywords === []) {
                return ReverseBidi::normalizeControlsRawUnicode($text);
            }
            return ReverseBidi::applyEveryTwoCharsToBodyText($text, $isHtml, $this->zeroWidthKeywords);
        }
        if ($this->cfg->zeroWidthEnabled && $this->cfg->zeroWidthTemplate) {
            return ZeroWidth::insertAtKeywords($text, $this->zeroWidthKeywords, $isHtml);
        }
        return $text;
    }

    private function loadTextTemplate(Recipient $recipient): string
    {
        $textPath = self::deriveTextTemplatePath($this->lastTemplatePath);
        if ($textPath === '' || !is_file($textPath)) {
            return '';
        }
        $raw = file_get_contents($textPath);
        if ($raw === false || trim($raw) === '') {
            return '';
        }
        $text = $this->variables->process($raw, $recipient);
        $text = $this->applyTemplateObfuscation($text, false);
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private static function deriveTextTemplatePath(string $htmlPath): string
    {
        $htmlPath = trim($htmlPath);
        if ($htmlPath === '') {
            return '';
        }
        $ext = pathinfo($htmlPath, PATHINFO_EXTENSION);
        if ($ext === '') {
            return $htmlPath . '.txt';
        }
        return substr($htmlPath, 0, -strlen($ext)) . 'txt';
    }

    private function applyDeliveryHeaders(PHPMailer $mail, Recipient $recipient, string $fromAddress, string $to, string $subject): void
    {
        if (!$this->cfg->headersEnabled) {
            return;
        }

        if ($this->cfg->headerMessageId) {
            if ($this->cfg->isHaraka()) {
                $mail->MessageID = '';
            } else {
                $domain = $this->cfg->headerMessageIdDomainMode === 'main'
                    ? $this->cfg->headerMessageIdMainDomain
                    : $this->cfg->headerMessageIdFullDomain;
                if ($domain === '') {
                    $parts = explode('@', $fromAddress, 2);
                    $domain = $parts[1] ?? 'localhost';
                }
                $id = MessageId::generate($this->cfg, $domain);
                $mail->MessageID = '<' . $id . '>';
            }
        }

        if ($this->cfg->headerReplyTo) {
            $reply = $this->resolveHeaderAddress($this->cfg->headerReplyToMode, $fromAddress);
            $mail->addReplyTo($this->variables->process($reply, $recipient));
        }

        if ($this->cfg->headerReturnPath) {
            $returnPath = $this->resolveHeaderAddress($this->cfg->headerReturnPathMode, $fromAddress);
            $returnPath = $this->variables->process($returnPath, $recipient);
            $mail->Sender = $returnPath;
            $mail->addCustomHeader('Return-Path', '<' . $returnPath . '>');
        }

        if ($this->cfg->headerXMailer) {
            $mail->XMailer = HarakaCompat::resolveXMailer($this->cfg);
        }

        if ($this->cfg->headerUserAgent) {
            $profile = strtolower(trim($this->cfg->headerClientProfile));
            $ua = match ($profile) {
                'thunderbird' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:115.0) Gecko/20100101 Thunderbird/115.8.0',
                'apple_mail' => 'Mac OS X Mail (16.0)',
                'iphone' => 'iOS/16.6 (20G75) Mobile/15E148',
                default => 'Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.14326; Pro)',
            };
            $mail->addCustomHeader('User-Agent', $ua);
        }

        if ($this->cfg->headerXPriority) {
            $mail->addCustomHeader('X-Priority', HarakaCompat::xPriority($this->cfg));
        }

        if ($this->cfg->headerImportance) {
            $mail->addCustomHeader('Importance', HarakaCompat::importance($this->cfg));
        }

        if ($this->cfg->headerAcceptLanguage) {
            $mail->addCustomHeader('Accept-Language', HarakaCompat::acceptLanguage($this->cfg));
        }

        if ($this->cfg->headerContentLanguage) {
            $mail->addCustomHeader('Content-Language', HarakaCompat::contentLanguage($this->cfg));
        }

        if ($this->cfg->headerListUnsubscribe) {
            $unsub = HarakaCompat::listUnsubscribeValue($this->cfg, $to, $fromAddress);
            if ($unsub !== '') {
                $mail->addCustomHeader('List-Unsubscribe', $unsub);
                $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        }

        if ($this->cfg->headerReceived) {
            $recLine = HarakaCompat::receivedLine($this->cfg, $fromAddress);
            if ($recLine !== '') {
                $mail->addCustomHeader('Received', $recLine);
            }
        }

        foreach ($this->parseCustomHeaders($this->cfg->customHeadersText) as [$name, $value]) {
            $mail->addCustomHeader(
                $this->variables->process($name, $recipient),
                $this->variables->process($value, $recipient)
            );
        }

        if ($mail instanceof WarshipMailer) {
            $mail->shuffleHeaders = $this->cfg->headerShuffleOrder;
        }
    }

    private function resolveHeaderAddress(string $mode, string $fromAddress): string
    {
        $domain = $this->cfg->headerMessageIdMainDomain ?: (explode('@', $fromAddress, 2)[1] ?? 'localhost');
        return match ($mode) {
            'random_prefix' => self::generateRandomEmailLocalPart() . '@' . $domain,
            default => $fromAddress,
        };
    }

    private static function generateRandomEmailLocalPart(): string
    {
        $prefixes = [
            'noreply', 'no-reply', 'info', 'support', 'admin', 'contact',
            'service', 'notification', 'alert', 'mail', 'system', 'notice',
            'account', 'security', 'verify', 'update', 'billing', 'help',
        ];
        $prefix = $prefixes[random_int(0, count($prefixes) - 1)];
        $suffixLen = random_int(0, 2) === 0 ? 4 : 6;
        $suffix = strtolower(substr(bin2hex(random_bytes(4)), 0, $suffixLen));
        return $prefix . $suffix;
    }

    /** @return list<array{0:string,1:string}> */
    private function parseCustomHeaders(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name !== '') {
                $out[] = [$name, $value];
            }
        }
        return $out;
    }

    private function applyEmbeddedImages(PHPMailer $mail): void
    {
        foreach ($this->cfg->emailEmbeddedImages as $img) {
            $path = $img['path'];
            $bytes = $this->readFileBytes($path);
            if ($bytes === null) {
                continue;
            }
            $filename = basename($path);
            $cid = InlineCid::fromFileName($filename);
            $mail->addStringEmbeddedImage(
                $this->applyImageNoise($bytes, $filename),
                $cid,
                $filename,
                PHPMailer::ENCODING_BASE64,
                $img['content_type']
            );
        }
    }

    private function applyAttachments(PHPMailer $mail): void
    {
        if ($this->cfg->attachmentsEnabled && $this->cfg->attachmentFiles !== []) {
            $files = $this->cfg->attachmentFiles;
            $idx = $this->variables->getNextAttachmentIndex(count($files));
            $path = $files[$idx];
            $bytes = $this->readFileBytes($path);
            if ($bytes !== null) {
                $filename = basename($path);
                $bytes = $this->applyImageNoise($bytes, $filename);
                if ($this->isImageFilename($filename) && !$this->isAlreadyEmbedded($path, $filename)) {
                    $mail->addStringEmbeddedImage(
                        $bytes,
                        InlineCid::fromFileName($filename),
                        $filename,
                        PHPMailer::ENCODING_BASE64,
                        $this->imageContentType($filename),
                    );
                } elseif (!$this->isImageFilename($filename)) {
                    $mail->addStringAttachment($bytes, $filename);
                }
            }
        }

        if ($this->cfg->attachmentCustomEnabled) {
            $this->addGeneratedAttachment($mail);
        }
    }

    private function addGeneratedAttachment(PHPMailer $mail): void
    {
        $binary = $this->cfg->attachmentCustomBinaryMode;
        $min = max(1, $this->cfg->attachmentCustomContentBytesMin);
        $max = max($min, $this->cfg->attachmentCustomContentBytesMax);
        $size = random_int($min, $max);
        $content = $binary ? random_bytes($size) : $this->randomTextBytes($size);
        $name = $this->generatedAttachmentName();
        $mime = $binary ? 'application/octet-stream' : 'text/plain';
        $mail->addStringAttachment($content, $name, PHPMailer::ENCODING_BASE64, $mime);
    }

    private function generatedAttachmentName(): string
    {
        $suffix = $this->generatedAttachmentSuffix();
        if (strcasecmp($this->cfg->attachmentCustomNameMode, 'fixed') === 0) {
            $fixed = $this->sanitizeAttachPart($this->cfg->attachmentCustomFixedName);
            if ($fixed !== '') {
                return $suffix !== '' ? $fixed . '.' . $suffix : $fixed;
            }
        }
        $min = max(1, $this->cfg->attachmentCustomNameMin);
        $max = max($min, $this->cfg->attachmentCustomNameMax);
        $name = $this->randomAlnum(random_int($min, $max));
        return $suffix !== '' ? $name . '.' . $suffix : $name;
    }

    private function generatedAttachmentSuffix(): string
    {
        if (strcasecmp($this->cfg->attachmentCustomSuffixMode, 'fixed') === 0) {
            $fixed = trim($this->sanitizeAttachPart($this->cfg->attachmentCustomFixedSuffix), '.');
            if ($fixed !== '') {
                return $fixed;
            }
        }
        $min = max(1, $this->cfg->attachmentCustomSuffixMin);
        $max = max($min, $this->cfg->attachmentCustomSuffixMax);
        return $this->randomAlpha(random_int($min, $max));
    }

    private function sanitizeAttachPart(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? $s;
        return trim(str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $s));
    }

    private function randomAlnum(int $len): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    private function randomAlpha(int $len): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    private function randomTextBytes(int $size): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' . " \n";
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $size; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /** 对齐 goMail htmlToPlainText：alternative/full 无 .txt 时从 HTML 生成纯文本。 */
    private function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/div>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/tr>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<li[^>]*>/i', '- ', $text) ?? $text;
        $text = preg_replace('/<hr\s*\/?>/i', "\n━━━━━━━━━━━━\n", $text) ?? $text;
        $text = preg_replace('/<a[^>]+href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $text) ?? $text;
        $text = preg_replace('/<img[^>]+alt=["\']([^"\']*)["\'][^>]*\/?>/i', '[画像: $1]', $text) ?? $text;
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text) ?? $text;
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = str_replace(
            ['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;', '&#39;', '&#x27;', '&yen;', '&copy;', '&reg;'],
            [' ', '&', '<', '>', '"', "'", "'", '¥', '©', '®'],
            $text
        );
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            $lines[$i] = trim($line);
        }
        return trim(implode("\n", $lines));
    }

    private function maybeConvertCharset(string $text): string
    {
        $target = $this->cfg->encodingCharset ?: $this->cfg->emailCharset ?: 'UTF-8';
        if ($text === ''
            || strcasecmp($target, 'UTF-8') === 0
            || $this->cfg->encodingDisableCharsetConvert
            || !function_exists('mb_convert_encoding')
        ) {
            return $text;
        }
        return (string) mb_convert_encoding($text, $target, 'UTF-8');
    }

    private function willEmbedAttachmentImages(): bool
    {
        if (!$this->cfg->attachmentsEnabled || $this->cfg->attachmentFiles === []) {
            return false;
        }
        foreach ($this->cfg->attachmentFiles as $path) {
            if (!is_file($path)) {
                continue;
            }
            $filename = basename($path);
            if ($this->isImageFilename($filename) && !$this->isAlreadyEmbedded($path, $filename)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{cid:string,file_name:string}> */
    private function inlineImageCatalog(): array
    {
        $images = [];
        $seen = [];
        foreach ($this->cfg->emailEmbeddedImages as $img) {
            $path = $img['path'] ?? '';
            if ($path === '') {
                continue;
            }
            $filename = basename($path);
            $cid = InlineCid::fromFileName($filename);
            $key = strtolower($cid);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $images[] = ['cid' => $cid, 'file_name' => $filename];
        }
        if (!$this->cfg->attachmentsEnabled) {
            return $images;
        }
        foreach ($this->cfg->attachmentFiles as $path) {
            if (!is_file($path)) {
                continue;
            }
            $filename = basename($path);
            if (!$this->isImageFilename($filename) || $this->isAlreadyEmbedded($path, $filename)) {
                continue;
            }
            $cid = InlineCid::fromFileName($filename);
            $key = strtolower($cid);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $images[] = ['cid' => $cid, 'file_name' => $filename];
        }
        return $images;
    }

    private function applyImageNoise(string $bytes, string $filename): string
    {
        if (!$this->cfg->imageNoiseEnabled) {
            return $bytes;
        }
        return ImageNoise::apply($bytes, $filename);
    }

    private function readFileBytes(string $path): ?string
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    private function isAlreadyEmbedded(string $path, string $filename): bool
    {
        $cid = InlineCid::fromFileName($filename);
        $norm = str_replace('\\', '/', $path);
        foreach ($this->cfg->emailEmbeddedImages as $img) {
            if (str_replace('\\', '/', $img['path']) === $norm) {
                return true;
            }
            if (strcasecmp(InlineCid::fromFileName($img['path'] ?: $img['cid']), $cid) === 0) {
                return true;
            }
        }
        return false;
    }

    private function isImageFilename(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    private function imageContentType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    private static function textToHtml(string $text, string $charset): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, $charset ?: 'UTF-8');
        $escaped = preg_replace(
            '#https?://[^\s<>"\'`]+#',
            '<a href="$0" target="_blank">$0</a>',
            $escaped
        ) ?? $escaped;
        $escaped = str_replace(["\r\n", "\n"], "<br>\n", $escaped);
        $charset = $charset ?: 'UTF-8';
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="{$charset}">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6;">
{$escaped}
</body>
</html>
HTML;
    }

    private static function prefixBacktestSubject(string $subject): string
    {
        $prefix = '[回测]';
        if (str_starts_with($subject, $prefix)) {
            return $subject;
        }
        return $prefix . $subject;
    }
}
