<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * MIME 树（有 HTML + 纯文本 + CID 时）:
 *   multipart/alternative
 *     ├── text/plain
 *     └── multipart/related
 *           ├── text/html
 *           └── image/*（内嵌）
 * 有普通附件时 mixed 再包一层。
 * 内联 part：Content-Disposition: inline（无 filename），Content-ID，Content-Type 保留 name=。
 */
final class WarshipMailer extends PHPMailer
{
    public ?ArcSealer $arcSealer = null;
    /** @var string standard|alternative|full（树结构不再随 full 翻转） */
    public string $mimeMode = 'standard';
    public bool $shuffleHeaders = false;
    public string $headerEncoding = 'base64';

    public function encodeHeader($str, $position = 'text')
    {
        $mode = strtolower(trim($this->headerEncoding));
        if ($mode === '7bit' || $mode === '8bit') {
            return trim(static::normalizeBreaks($str));
        }
        if ($mode === 'quoted-printable' || $mode === 'qp') {
            if ($this->has8bitChars($str)) {
                $charset = $this->CharSet ?: 'UTF-8';
                $maxlen = static::MAX_LINE_LENGTH - (8 + strlen($charset));
                $encoded = $this->encodeQ($str, $position);
                $encoded = $this->wrapText($encoded, $maxlen, true);
                $encoded = str_replace('=' . static::$LE, "\n", trim($encoded));
                $encoded = preg_replace('/^(.*)$/m', ' =?' . $charset . "?Q?\\1?=", $encoded);
                return trim(static::normalizeBreaks($encoded));
            }
            return $str;
        }
        if ($mode === 'base64' || $mode === 'b') {
            if ($this->has8bitChars($str)) {
                $charset = $this->CharSet ?: 'UTF-8';
                $maxlen = static::MAX_LINE_LENGTH - (8 + strlen($charset));
                if ($this->hasMultiBytes($str)) {
                    $encoded = $this->base64EncodeWrapMB($str, "\n");
                } else {
                    $encoded = base64_encode($str);
                    $maxlen -= $maxlen % 4;
                    $encoded = trim(chunk_split($encoded, $maxlen, "\n"));
                }
                $encoded = preg_replace('/^(.*)$/m', ' =?' . $charset . "?B?\\1?=", $encoded);
                return trim(static::normalizeBreaks($encoded));
            }
            return $str;
        }
        return parent::encodeHeader($str, $position);
    }

    public function createHeader()
    {
        if ($this->shuffleHeaders && !empty($this->CustomHeader)) {
            $received = [];
            $unsub = [];
            $others = [];
            foreach ($this->CustomHeader as $h) {
                $name = strtolower(trim((string)($h[0] ?? '')));
                if ($name === 'received') {
                    $received[] = $h;
                } elseif ($name === 'list-unsubscribe' || $name === 'list-unsubscribe-post') {
                    $unsub[] = $h;
                } else {
                    $others[] = $h;
                }
            }
            shuffle($others);
            $this->CustomHeader = array_merge($received, $others, $unsub);
        }
        return parent::createHeader();
    }

    public function preSend()
    {
        $ok = parent::preSend();
        if (!$ok || $this->arcSealer === null) {
            return $ok;
        }
        $header = (string) $this->MIMEHeader;
        $body = (string) $this->MIMEBody;
        $prefix = $this->arcSealer->prependHeaders($header . $body, (string) $this->Sender);
        if ($prefix !== '') {
            $this->MIMEHeader = $prefix . $header;
        }
        return $ok;
    }

    public function setBoundaries()
    {
        $this->uniqueid = $this->generateId();
        $this->boundary[1] = MimeBoundary::generate(1, $this->uniqueid);
        $this->boundary[2] = MimeBoundary::generate(2, $this->uniqueid);
        $this->boundary[3] = MimeBoundary::generate(3, $this->uniqueid);
    }

    public function createBody()
    {
        if ($this->message_type !== 'alt_inline' && $this->message_type !== 'alt_inline_attach') {
            return parent::createBody();
        }

        $this->setBoundaries();
        $this->setWordWrap();
        [$bodyEncoding, $bodyCharSet, $altBodyEncoding, $altBodyCharSet] = $this->partEncodings();

        if ($this->message_type === 'alt_inline') {
            $body = $this->buildAlternativeWrappingRelated(
                $this->boundary[1],
                $this->boundary[2],
                $bodyEncoding,
                $bodyCharSet,
                $altBodyEncoding,
                $altBodyCharSet,
            );
        } else {
            $body = $this->buildMixedAlternativeRelated(
                $bodyEncoding,
                $bodyCharSet,
                $altBodyEncoding,
                $altBodyCharSet,
            );
        }

        if ($this->isError()) {
            $body = '';
            if ($this->exceptions) {
                throw new MailException(self::lang('empty_message'), self::STOP_CRITICAL);
            }
        }

        return $body;
    }

    protected function attachAll($disposition_type, $boundary)
    {
        $raw = parent::attachAll($disposition_type, $boundary);
        if ($disposition_type !== 'inline' || $raw === '') {
            return $raw;
        }

        $stripped = preg_replace(
            '/Content-Disposition:\s*inline;\s*filename=(?:"(?:\\\\.|[^"\\\\])*"|[^\r\n]+)/i',
            'Content-Disposition: inline',
            $raw
        );

        return is_string($stripped) ? $stripped : $raw;
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function partEncodings(): array
    {
        $bodyEncoding = $this->Encoding;
        $bodyCharSet = $this->CharSet;
        if ($this->UseSMTPUTF8) {
            $bodyEncoding = static::ENCODING_8BIT;
        } elseif (static::ENCODING_8BIT === $bodyEncoding && !$this->has8bitChars($this->Body)) {
            $bodyEncoding = static::ENCODING_7BIT;
            $bodyCharSet = static::CHARSET_ASCII;
        }
        if (static::ENCODING_BASE64 !== $this->Encoding && static::hasLineLongerThanMax($this->Body)) {
            $bodyEncoding = static::ENCODING_QUOTED_PRINTABLE;
        }

        $altBodyEncoding = $this->Encoding;
        $altBodyCharSet = $this->CharSet;
        if (static::ENCODING_8BIT === $altBodyEncoding && !$this->has8bitChars($this->AltBody)) {
            $altBodyEncoding = static::ENCODING_7BIT;
            $altBodyCharSet = static::CHARSET_ASCII;
        }
        if (static::ENCODING_BASE64 !== $altBodyEncoding && static::hasLineLongerThanMax($this->AltBody)) {
            $altBodyEncoding = static::ENCODING_QUOTED_PRINTABLE;
        }

        return [$bodyEncoding, $bodyCharSet, $altBodyEncoding, $altBodyCharSet];
    }

    /** alternative(plain + related(html+CID)) */
    private function buildAlternativeWrappingRelated(
        string $altBoundary,
        string $relBoundary,
        string $bodyEncoding,
        string $bodyCharSet,
        string $altBodyEncoding,
        string $altBodyCharSet,
    ): string {
        $body = $this->getBoundary($altBoundary, $altBodyCharSet, static::CONTENT_TYPE_PLAINTEXT, $altBodyEncoding);
        $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
        $body .= static::$LE;

        $body .= $this->textLine('--' . $altBoundary);
        $body .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_RELATED . ';');
        $body .= $this->textLine(' boundary="' . $relBoundary . '";');
        $body .= $this->textLine(' type="' . static::CONTENT_TYPE_TEXT_HTML . '"');
        $body .= static::$LE;

        $body .= $this->getBoundary($relBoundary, $bodyCharSet, static::CONTENT_TYPE_TEXT_HTML, $bodyEncoding);
        $body .= $this->encodeString($this->Body, $bodyEncoding);
        $body .= static::$LE;
        $body .= $this->relatedInlineParts($relBoundary);
        $body .= static::$LE;
        $body .= $this->endBoundary($altBoundary);

        return $body;
    }

    /** mixed 包 alternative(plain + related(html+CID)) + 普通附件 */
    private function buildMixedAlternativeRelated(
        string $bodyEncoding,
        string $bodyCharSet,
        string $altBodyEncoding,
        string $altBodyCharSet,
    ): string {
        $body = $this->textLine('--' . $this->boundary[1]);
        $body .= $this->headerLine('Content-Type', static::CONTENT_TYPE_MULTIPART_ALTERNATIVE . ';');
        $body .= $this->textLine(' boundary="' . $this->boundary[2] . '"');
        $body .= static::$LE;
        $body .= $this->buildAlternativeWrappingRelated(
            $this->boundary[2],
            $this->boundary[3],
            $bodyEncoding,
            $bodyCharSet,
            $altBodyEncoding,
            $altBodyCharSet,
        );
        $body .= $this->attachAll('attachment', $this->boundary[1]);

        return $body;
    }

    private function relatedInlineParts(string $relBoundary): string
    {
        $inline = $this->attachAll('inline', $relBoundary);
        if ($inline !== '') {
            return $inline;
        }

        return $this->endBoundary($relBoundary);
    }
}
