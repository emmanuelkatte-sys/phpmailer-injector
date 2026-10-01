<?php

declare(strict_types=1);

namespace Warship\Injector\Variable;

use Warship\Injector\Config\Config;
use Warship\Injector\Recipient\Recipient;
use Warship\Injector\Util\DomainHelper;

final class Processor
{
    private int $templateIndex = 0;
    private int $subjectIndex = 0;
    private int $displayNameIndex = 0;
    private int $attachmentIndex = 0;
    /** @var array<string, list<string>> */
    private array $customVars = [];
    /** @var array<string, string> */
    private array $customVarModes = [];
    /** @var array<string, int> */
    private array $customVarIndex = [];
    /** @var array<string, string>|null */
    private ?array $senderContext = null;

    public function __construct(private readonly Config $cfg)
    {
        foreach ($cfg->customVariableFiles as $name => $path) {
            $values = self::readLines($path);
            if ($values !== []) {
                $key = strtolower($name);
                $this->customVars[$key] = $values;
                $this->customVarIndex[$key] = 0;
            }
        }
        foreach ($cfg->customVariableModes as $name => $mode) {
            $key = strtolower(trim((string) $name));
            if ($key === '') {
                continue;
            }
            $m = strtolower(trim((string) $mode));
            $this->customVarModes[$key] = ($m === 'seq' || $m === 'sequential') ? 'sequential' : 'random';
        }
    }

    public function process(string $content, Recipient $recipient): string
    {
        if ($content === '') {
            return $content;
        }

        $content = $this->processTimeVariables($content);
        $content = $this->processBraceAliases($content);
        $content = $this->processRecipientVariables($content, $recipient);
        $content = $this->processSenderVariables($content);
        $content = $this->processGlobalVariables($content);
        $content = $this->processRandomVariables($content);
        $content = $this->processAmountVariables($content);
        $content = $this->processIpVariables($content);
        $content = $this->processUuidHashVariables($content);
        $content = $this->processCustomVariables($content, $recipient);
        $content = $this->processConditionalVariables($content, $recipient);
        $content = self::processSpintax($content);

        return $content;
    }

    public function getNextTemplatePath(): string
    {
        $paths = $this->cfg->emailTemplatePaths;
        if ($paths === []) {
            return $this->cfg->emailTemplatePath;
        }
        if ($this->cfg->emailTemplateMode === 'sequential') {
            $path = $paths[$this->templateIndex % count($paths)];
            $this->templateIndex++;
            return $path;
        }
        return $paths[random_int(0, count($paths) - 1)];
    }

    public function getNextSubject(): string
    {
        $subjects = $this->cfg->emailSubjects;
        if ($subjects === []) {
            return $this->cfg->emailSubject;
        }
        if ($this->cfg->emailSubjectMode === 'sequential') {
            $subject = $subjects[$this->subjectIndex % count($subjects)];
            $this->subjectIndex++;
            return $subject;
        }
        return $subjects[random_int(0, count($subjects) - 1)];
    }

    public function getNextDisplayName(): string
    {
        $names = $this->cfg->senderDisplayNames;
        if ($names === []) {
            return $this->cfg->senderFromName;
        }
        if ($this->cfg->senderDisplayNameMode === 'sequential') {
            $name = $names[$this->displayNameIndex % count($names)];
            $this->displayNameIndex++;
            return $name;
        }
        return $names[random_int(0, count($names) - 1)];
    }

    public function getNextAttachmentIndex(int $total): int
    {
        if ($total <= 0) {
            return 0;
        }
        $idx = $this->attachmentIndex % $total;
        $this->attachmentIndex++;
        return $idx;
    }

    private function processTimeVariables(string $content): string
    {
        $jst = new \DateTimeZone('Asia/Tokyo');
        $now = new \DateTimeImmutable('now', $jst);
        $tomorrow = $now->modify('+1 day');
        $yesterday = $now->modify('-1 day');
        $afterTomorrow = $now->modify('+2 days');
        $thirdDay = $now->modify('+3 days');
        $lastWeek = $now->modify('-7 days');
        $weekdays = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];

        $map = [
            '{DATE_TIME}' => $now->format('Y-m-d H:i:s'),
            '{DATE}' => $now->format('Y/m/d'),
            '{TIME}' => $now->format('H:i:s'),
            '{YEAR}' => $now->format('Y'),
            '{MONTH}' => $now->format('m'),
            '{DAY}' => $now->format('d'),
            '{HOUR}' => $now->format('H'),
            '{MINUTE}' => $now->format('i'),
            '{SECOND}' => $now->format('s'),
            '{TIMESTAMP}' => (string) $now->getTimestamp(),
            '{DATE_JP}' => $now->format('Y年m月d日'),
            '{DATE_CN}' => $now->format('Y年n月j日'),
            '{DATE_KANJI}' => $now->format('Y年m月d日'),
            '{DATE_FULLWIDTH}' => self::toFullwidthDigits($now->format('Y年m月d日')),
            '{TOMORROW_DATE}' => $tomorrow->format('Y/m/d'),
            '{TOMORROW_TIME}' => $tomorrow->format('H:i:s'),
            '{TOMORROW_DATE_KANJI}' => $tomorrow->format('Y年m月d日'),
            '{YESTERDAY_DATE}' => $yesterday->format('Y/m/d'),
            '{YESTERDAY_TIME}' => $yesterday->format('H:i:s'),
            '{YESTERDAY_DATE_KANJI}' => $yesterday->format('Y年m月d日'),
            '{YESTERDAY_DATE_KANJI_FULLWIDTH}' => self::toFullwidthDigits($yesterday->format('Y年m月d日')),
            '{AFTER_TOMORROW_DATE}' => $afterTomorrow->format('Y/m/d'),
            '{AFTER_TOMORROW_TIME}' => $afterTomorrow->format('H:i:s'),
            '{AFTER_TOMORROW_DATE_KANJI}' => $afterTomorrow->format('Y年m月d日'),
            '{THIRD_DAY_DATE}' => $thirdDay->format('Y/m/d'),
            '{THIRD_DAY_TIME}' => $thirdDay->format('H:i:s'),
            '{THIRD_DAY_DATE_KANJI}' => $thirdDay->format('Y年m月d日'),
            '{LAST_WEEK_DATE}' => $lastWeek->format('Y/m/d'),
            '{LAST_WEEK_TIME}' => $lastWeek->format('H:i:s'),
            '{LAST_WEEK_DATE_KANJI}' => $lastWeek->format('Y年m月d日'),
            '{LAST_WEEK_DATE_KANJI_FULLWIDTH}' => self::toFullwidthDigits($lastWeek->format('Y年m月d日')),
            '{WEEKDAY}' => $weekdays[(int) $now->format('w')],
            '{WEEKDAY_EN}' => $now->format('l'),
            '{WEEKNUMBER}' => $now->format('W'),
            '{ISO8601}' => $now->format(\DateTimeInterface::ATOM),
            '{LOGIN_TIME}' => $now->format('Y/m/d H:i'),
            '{LOGIN_TIME_ISO}' => $now->format('Y-m-d H:i:s'),
            '{LOGIN_TIME_KANJI}' => $now->format('Y年m月d日 H:i'),
            '{LOGIN_TIME_FULLWIDTH_KANJI}' => self::toFullwidthDigits($now->format('Y年m月d日 H:i')),
            '{LOGIN_LOCATION}' => self::randomLoginLocation(),
            '{LOGIN_DEVICE}' => self::randomLoginDevice(),
            '{FANHAO}' => self::randomFanhao(),
            '{SHUZI}' => (string) random_int(11111, 99999),
            '{RANDNUM}' => (string) random_int(111, 999),
            '{RANDOM_4}' => self::randomAlnumString(4),
            '{RANDOM_6}' => self::randomAlnumString(6),
            '{RANDOM_12}' => self::randomAlnumString(12),
            '{RANDOM_24}' => self::randomAlnumString(24),
            '{RANDSTRING}' => self::randomAlnumString(12),
            '{24RANDSTRING24S}' => self::randomAlnumString(24),
            '{64RANDSTRING64S}' => self::randomAlnumString(64),
            '{RANDOM_COMMENT}' => '<!-- ' . self::randomAlnumString(8) . ' -->',
            '{RANDOM_STYLE_COMMENT}' => '/* ' . self::randomAlnumString(6) . ' */',
            '{RANDOM_ID}' => 'id="id_' . self::randomAlnumString(6) . '"',
            '{RANDOM_CLASS}' => 'class="c_' . self::randomAlnumString(6) . '"',
            '{RANDOM_MARGIN}' => random_int(0, 2) . 'px',
            '{RANDOM_PADDING}' => random_int(0, 1) . 'px',
            '{RANDOM_OPACITY}' => '0.' . random_int(98, 99),
            '{RANDOM_LETTER_SPACING}' => sprintf('%.2fpx', (random_int(-10, 10) / 100.0)),
            '{RANDOM_LINE_HEIGHT}' => sprintf('%.2f', (random_int(135, 145) / 100.0)),
            '{RANDOM_FONT_SIZE_ADJ}' => (string) random_int(-1, 1),
            '{RANDOM_COLOR_ADJ}' => sprintf('#%02x%02x%02x', random_int(51, 68), random_int(51, 68), random_int(51, 68)),
            '{RANDOM_BG_ADJ}' => sprintf('#%02x%02x%02x', random_int(248, 252), random_int(248, 252), random_int(248, 252)),
            '{RANDOM_BORDER_ADJ}' => sprintf('#%02x%02x%02x', random_int(230, 240), random_int(230, 240), random_int(230, 240)),
            '{RANDOM_WHITESPACE}' => str_repeat(' ', random_int(0, 3)),
            '{RANDOM_NEWLINE}' => "\n",
            '{RANDOM_INVISIBLE_CHAR}' => '&#8203;',
            '{INVISIBLE_CHAR}' => '&#8203;',
        ];

        foreach ($map as $key => $value) {
            $content = str_ireplace($key, $value, $content);
            if (str_starts_with($key, '{') && str_ends_with($key, '}')) {
                $inner = substr($key, 1, -1);
                $content = str_ireplace('{{' . $inner . '}}', $value, $content);
            }
        }

        // 兼容 4.py 中的 % 前缀与 [...] 格式变量
        $percentVars = [
            '%time' => $now->format('H:i:s'),
            '%date' => $now->format('Y/m/d'),
            '%year' => $now->format('Y'),
            '%month' => $now->format('m'),
            '%day' => $now->format('d'),
            '%hour' => $now->format('H'),
            '%minute' => $now->format('i'),
            '%second' => $now->format('s'),
            '%weekday' => $now->format('l'),
            '%iso8601' => $now->format(\DateTimeInterface::ATOM),
            '%randstring' => self::randomAlnumString(12),
            '%24randstring24s' => self::randomAlnumString(24),
            '%64randstring64s' => self::randomAlnumString(64),
            '%fanhao' => self::randomFanhao(),
            '%shuzi' => (string) random_int(700000, 999999),
            '%randnum' => (string) random_int(111, 999),
            '%tomorrow_time' => $tomorrow->format('H:i:s'),
            '%tomorrow_date' => $tomorrow->format('Y/m/d'),
            '%1_date' => $tomorrow->format('Y/m/d'),
            '[RAND_TEXT-MIX_20_30]' => self::randomAlnumString(19),
            '[loglog23]' => self::randomAlnumString(8),
            '[loglog24]' => self::randomAlnumString(8),
            '[loglog25]' => self::randomAlnumString(8),
        ];
        foreach ($percentVars as $key => $value) {
            $content = str_replace($key, $value, $content);
        }

        return $content;
    }

    /** 双花括号变量：{{rand_N}} / {{zw_文本}} / {{date}} */
    private function processBraceAliases(string $content): string
    {
        $now = new \DateTimeImmutable();
        $content = str_replace('{{datetime}}', $now->format('Y-m-d H:i:s'), $content);
        $content = str_replace('{{date}}', $now->format('Y-m-d'), $content);
        $content = str_replace('{{time}}', $now->format('H:i:s'), $content);

        $patterns = [
            '/\{\{num_(\d+)\}\}/' => 'num',
            '/\{\{rand_(\d+)\}\}/' => 'alnum',
            '/\{\{upper_num_(\d+)\}\}/' => 'upper_num',
            '/\{\{lower_num_(\d+)\}\}/' => 'lower_num',
            '/\{\{upper_(\d+)\}\}/' => 'upper',
            '/\{\{lower_(\d+)\}\}/' => 'lower',
        ];
        foreach ($patterns as $regex => $type) {
            $content = preg_replace_callback($regex, static function (array $m) use ($type): string {
                return self::randomString(max(1, (int) $m[1]), $type);
            }, $content) ?? $content;
        }

        $content = preg_replace_callback('/\{\{zw_(.+?)\}\}/u', static function (array $m): string {
            $chars = preg_split('//u', $m[1], -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars) || $chars === []) {
                return $m[1];
            }
            return implode("\u{200B}", $chars);
        }, $content) ?? $content;

        return $content;
    }

    private function processRecipientVariables(string $content, Recipient $r): string
    {
        $email = $r->email;
        $parts = explode('@', $email, 2);
        $user = $parts[0];
        $domain = $parts[1] ?? '';

        $honorific = self::resolveHonorific($this->cfg->recipientHonorific, $this->cfg->recipientCustomPhrases);

        $map = [
            '{TO_EMAIL}' => $email,
            '{EMAIL}' => $email,
            '{RECEIVER_EMAIL}' => $email,
            '{RECIPIENT_EMAIL}' => $email,
            '{RECEIVER_ADDRESS}' => $email,
            '[RECEIVER_ADDRESS]' => $email,
            '{TO_USER}' => $user,
            '{USERNAME}' => $user,
            '{TO_DOMAIN}' => $domain,
            '{TO_NAME}' => $r->name,
            '{NAME}' => $r->name,
            '{HONORIFIC}' => $honorific,
            '{RECEIVER_NAME}' => $r->name,
            '{TO_FIRST}' => $r->firstName,
            '{TO_LAST}' => $r->lastName,
            '{TO_USER_UPPER}' => strtoupper($user),
            '{TO_USER_LOWER}' => strtolower($user),
            '{TO_USER_CAP}' => self::capitalizeFirst($user),
            '{TO_NAME_UPPER}' => strtoupper($r->name),
            '{TO_NAME_LOWER}' => strtolower($r->name),
        ];

        foreach ($map as $key => $value) {
            $content = str_ireplace($key, $value, $content);
            if (str_starts_with($key, '{') && str_ends_with($key, '}')) {
                $inner = substr($key, 1, -1);
                $content = str_ireplace('{{' . $inner . '}}', $value, $content);
            }
        }

        foreach ($r->customFields as $key => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $owned = strtolower((string) $key);
            if (isset($this->customVars[$owned]) && ($this->customVarModes[$owned] ?? '') !== 'sequential') {
                continue;
            }
            $patterns = [
                '{CUSTOM:' . $key . ':seq}',
                '{CUSTOM:' . $key . ':random}',
                '{CUSTOM:' . strtoupper($key) . ':seq}',
                '{CUSTOM:' . strtoupper($key) . ':random}',
                '{CUSTOM:' . strtolower($key) . ':seq}',
                '{CUSTOM:' . strtolower($key) . ':random}',
                '{' . $key . '}',
                '{' . strtoupper($key) . '}',
                '{' . strtolower($key) . '}',
                '{CUSTOM:' . $key . '}',
                '{CUSTOM:' . strtoupper($key) . '}',
                '{CUSTOM:' . strtolower($key) . '}',
                '{TO_' . strtoupper($key) . '}',
                '{to_' . strtolower($key) . '}',
            ];
            foreach ($patterns as $pattern) {
                $content = str_replace($pattern, $value, $content);
            }
        }

        return $content;
    }

    public function setSenderContext(string $fromAddress): void
    {
        $parts = explode('@', $fromAddress, 2);
        $user = ($parts[0] ?? '') !== '' ? $parts[0] : 'info';
        $domain = $parts[1] ?? 'localhost';
        $rootDomain = DomainHelper::extractRootDomain($domain);
        $subdomain = (str_contains($domain, '.')) ? explode('.', $domain)[0] : $domain;

        $this->senderContext = [
            '{FROM_EMAIL}' => $fromAddress,
            '{SENDER_EMAIL}' => $fromAddress,
            '{FROM_USER}' => $user,
            '{SENDER_USER}' => $user,
            '{FROM_DOMAIN}' => $domain,
            '{SENDER_DOMAIN}' => $domain,
            '{smtp_domain}' => $domain,
            '{FROM_MAIN_DOMAIN}' => $rootDomain,
            '{FROM_ROOT_DOMAIN}' => $rootDomain,
            '{MAIN_DOMAIN}' => $rootDomain,
            '{ROOT_DOMAIN}' => $rootDomain,
            '{SENDER_MAIN_DOMAIN}' => $rootDomain,
            '{SENDER_ROOT_DOMAIN}' => $rootDomain,
            '{SUBDOMAIN}' => $subdomain,
            '{FROM_NAME}' => $this->cfg->senderFromName,
        ];
    }

    private function processSenderVariables(string $content): string
    {
        if ($this->senderContext === null) {
            $this->setSenderContext($this->cfg->senderFromAddress);
        }
        foreach ($this->senderContext as $key => $value) {
            $content = str_ireplace($key, (string) $value, $content);
        }
        return $content;
    }

    private function processGlobalVariables(string $content): string
    {
        foreach ($this->cfg->globalVariables as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $val = (string) $value;
            $content = str_replace('{' . $key . '}', $val, $content);
            $content = str_replace('{' . strtoupper((string) $key) . '}', $val, $content);
            $content = str_replace('{' . strtolower((string) $key) . '}', $val, $content);
        }
        return $content;
    }

    private function processRandomVariables(string $content): string
    {
        $patterns = [
            '/\{RANDOM(?::(\d+)(?:-(\d+))?)?\}/i' => 'alnum',
            '/\{RANDOM_LOWER(?::(\d+)(?:-(\d+))?)?\}/i' => 'lower',
            '/\{RANDOM_UPPER(?::(\d+)(?:-(\d+))?)?\}/i' => 'upper',
            '/\{RANDOM_NUM(?::(\d+)(?:-(\d+))?)?\}/i' => 'num',
            '/\{RANDOM_LOWER_NUM(?::(\d+)(?:-(\d+))?)?\}/i' => 'lower_num',
            '/\{RANDOM_UPPER_NUM(?::(\d+)(?:-(\d+))?)?\}/i' => 'upper_num',
            '/\{RANDOM_HEX(?::(\d+)(?:-(\d+))?)?\}/i' => 'hex',
            '/\{RANDOM_CN(?::(\d+)(?:-(\d+))?)?\}/i' => 'cn',
            '/\{RANDOM_JP(?::(\d+)(?:-(\d+))?)?\}/i' => 'jp',
            '/\{RANDOM_HIRA(?::(\d+)(?:-(\d+))?)?\}/i' => 'hira',
            '/\{RANDOM_KATA(?::(\d+)(?:-(\d+))?)?\}/i' => 'kata',
        ];

        foreach ($patterns as $regex => $type) {
            $content = preg_replace_callback($regex, function (array $m) use ($type): string {
                $len = self::parseLength($m);
                return self::randomString($len, $type);
            }, $content) ?? $content;
        }

        return $content;
    }

    private function processAmountVariables(string $content): string
    {
        $cfg = $this->cfg;

        $content = preg_replace_callback('/\{AMOUNT\}/i', function () use ($cfg): string {
            return self::formatAmount($cfg->amountMin, $cfg->amountMax, 0, $cfg->amountUseSeparator, '');
        }, $content) ?? $content;

        $content = preg_replace_callback('/\{AMOUNT:(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)(?::(\d+))?\}/i', function (array $m) use ($cfg): string {
            $min = (float) $m[1];
            $max = (float) $m[2];
            $decimals = isset($m[3]) ? (int) $m[3] : 0;
            return self::formatAmount($min, $max, $decimals, $cfg->amountUseSeparator, '');
        }, $content) ?? $content;

        $content = preg_replace_callback('/\{AMOUNT_JP:(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)\}/i', function (array $m): string {
            return self::formatAmount((float) $m[1], (float) $m[2], 0, true, '¥');
        }, $content) ?? $content;

        $content = preg_replace_callback('/\{AMOUNT_CN:(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)(?::(\d+))?\}/i', function (array $m): string {
            $decimals = isset($m[3]) ? (int) $m[3] : 2;
            return self::formatAmount((float) $m[1], (float) $m[2], $decimals, true, '￥');
        }, $content) ?? $content;

        $content = preg_replace_callback('/\{AMOUNT_USD:(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)(?::(\d+))?\}/i', function (array $m): string {
            $decimals = isset($m[3]) ? (int) $m[3] : 2;
            return self::formatAmount((float) $m[1], (float) $m[2], $decimals, true, '$');
        }, $content) ?? $content;

        return $content;
    }

    private function processIpVariables(string $content): string
    {
        $content = preg_replace_callback('/\{RANDOM_IP\}/i', fn () => self::randomIp([[1, 9], [11, 126], [128, 169], [171, 191], [193, 223]]), $content) ?? $content;
        $content = preg_replace_callback('/\{RANDOM_IP_CN\}/i', fn () => self::randomIp([[116, 117], [119, 120], [121, 122], [222, 223]]), $content) ?? $content;
        $content = preg_replace_callback('/\{RANDOM_IP_JP\}/i', fn () => self::randomIp([[133, 134], [150, 151], [157, 158], [202, 203]]), $content) ?? $content;
        $content = preg_replace_callback('/\{RANDOM_IP_US\}/i', fn () => self::randomIp([[64, 65], [66, 67], [69, 70], [98, 99]]), $content) ?? $content;
        return $content;
    }

    private function processUuidHashVariables(string $content): string
    {
        $content = preg_replace_callback('/\{UUID\}/i', fn () => self::uuid(), $content) ?? $content;
        $content = preg_replace_callback('/\{UUID_SHORT\}/i', fn () => str_replace('-', '', self::uuid()), $content) ?? $content;
        $content = preg_replace_callback('/\{MD5:([^}]+)\}/i', fn (array $m) => md5($m[1]), $content) ?? $content;
        $content = preg_replace_callback('/\{MD5_SHORT:([^}]+)\}/i', fn (array $m) => substr(md5($m[1]), 0, 8), $content) ?? $content;
        return $content;
    }

    private function processCustomVariables(string $content, Recipient $r): string
    {
        return preg_replace_callback('/\{CUSTOM:(\w+)(?::(random|seq))?\}/i', function (array $m) use ($r): string {
            $name = strtolower($m[1]);
            $mode = strtolower($m[2] ?? '');
            if ($mode === '') {
                $mode = ($this->customVarModes[$name] ?? '') === 'sequential' ? 'seq' : 'random';
            }
            $values = $this->customVars[$name] ?? [];
            if ($values === []) {
                return '';
            }
            if ($mode === 'seq') {
                $idx = max(0, $r->index);
                return $values[$idx % count($values)];
            }
            return $values[random_int(0, count($values) - 1)];
        }, $content) ?? $content;
    }

    private function processConditionalVariables(string $content, Recipient $r): string
    {
        $email = $r->email;
        $parts = explode('@', $email, 2);
        $user = $parts[0];
        $domain = $parts[1] ?? '';

        return preg_replace_callback('/\{IF:(\w+)=([^:]+):([^:]*):([^}]*)\}/i', function (array $m) use ($email, $user, $domain, $r): string {
            $field = strtoupper($m[1]);
            $compare = $m[2];
            $trueVal = $m[3];
            $falseVal = $m[4];
            $actual = match ($field) {
                'TO_DOMAIN' => $domain,
                'TO_USER' => $user,
                'TO_EMAIL' => $email,
                'TO_NAME' => $r->name,
                default => '',
            };
            return strcasecmp($actual, $compare) === 0 ? $trueVal : $falseVal;
        }, $content) ?? $content;
    }

    /** @param list<string> $m */
    private static function parseLength(array $m): int
    {
        $length = 8;
        if (!empty($m[1])) {
            $min = (int) $m[1];
            if (!empty($m[2])) {
                $max = (int) $m[2];
                $length = $max > $min ? random_int($min, $max) : $min;
            } else {
                $length = $min;
            }
        }
        return max(1, $length);
    }

    private static function randomString(int $length, string $type): string
    {
        $chars = match ($type) {
            'lower' => 'abcdefghijklmnopqrstuvwxyz',
            'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'num' => '0123456789',
            'lower_num' => 'abcdefghijklmnopqrstuvwxyz0123456789',
            'upper_num' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
            'hex' => '0123456789abcdef',
            default => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        };

        if ($type === 'cn') {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= mb_chr(random_int(0x4E00, 0x9FFF), 'UTF-8');
            }
            return $out;
        }

        if (in_array($type, ['jp', 'hira', 'kata'], true)) {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                if ($type === 'hira') {
                    $out .= mb_chr(random_int(0x3040, 0x309F), 'UTF-8');
                } elseif ($type === 'kata') {
                    $out .= mb_chr(random_int(0x30A0, 0x30FF), 'UTF-8');
                } elseif (random_int(0, 1) === 0) {
                    $out .= mb_chr(random_int(0x3040, 0x309F), 'UTF-8');
                } else {
                    $out .= mb_chr(random_int(0x30A0, 0x30FF), 'UTF-8');
                }
            }
            return $out;
        }

        $result = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        return $result;
    }

    /** @param list<array{int,int}> $ranges */
    private static function randomIp(array $ranges): string
    {
        $range = $ranges[array_rand($ranges)];
        $first = random_int($range[0], $range[1]);
        return sprintf('%d.%d.%d.%d', $first, random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s-%s-%s-%s-%s', str_split(bin2hex($data), 4));
    }

    private static function formatAmount(float $min, float $max, int $decimals, bool $separator, string $prefix): string
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        $amount = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
        $formatted = $decimals > 0 ? number_format($amount, $decimals, '.', '') : (string) (int) round($amount);
        if ($separator) {
            $parts = explode('.', $formatted, 2);
            $parts[0] = number_format((float) $parts[0], 0, '.', ',');
            $formatted = implode('.', $parts);
        }
        return $prefix . $formatted;
    }

    private static function capitalizeFirst(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_strtolower(mb_substr($s, 1));
    }

    /** @return list<string> */
    private static function readLines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    public static function toFullwidthDigits(string $s): string
    {
        $half = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $full = ['０', '１', '２', '３', '４', '５', '６', '７', '８', '９'];
        return str_replace($half, $full, $s);
    }

    public static function randomLoginLocation(): string
    {
        $cities = [
            'Tokyo, Japan',
            'Osaka, Japan',
            'Nagoya, Japan',
            'Fukuoka, Japan',
            'Sapporo, Japan',
            'Yokohama, Japan',
            'Kyoto, Japan',
            'Kobe, Japan',
        ];
        return $cities[array_rand($cities)];
    }

    public static function randomLoginDevice(): string
    {
        if (random_int(1, 100) <= 55) {
            $desktops = [
                'Windows 10 / Chrome 124',
                'Windows 11 / Chrome 126',
                'Windows 11 / Edge 125',
                'macOS 14.4 / Safari 17.4',
                'macOS 13.6 / Chrome 125',
            ];
            return $desktops[array_rand($desktops)];
        }
        $mobiles = [
            'iPhone 15 Pro (iOS 17.4) / Safari',
            'iPhone 14 (iOS 16.6) / Safari',
            'Pixel 8 (Android 14) / Chrome',
            'Galaxy S24 (Android 14) / Chrome',
            'Sony Xperia 1 V (Android 14) / Chrome',
        ];
        return $mobiles[array_rand($mobiles)];
    }

    public static function randomFanhao(): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return $letters[random_int(0, 25)] . $letters[random_int(0, 25)];
    }

    public static function randomAlnumString(int $length): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /** @param list<string> $customList */
    public static function resolveHonorific(string $type, array $customList = []): string
    {
        if (!empty($customList)) {
            return $customList[array_rand($customList)];
        }
        $t = strtolower(trim($type));
        return match ($t) {
            'sama', '様' => '様',
            'sensei', '先生' => '先生',
            'onchu', '御中' => '御中',
            'dono', '殿' => '殿',
            'san', 'さん' => 'さん',
            'random' => ['様', '先生', '御中', '殿', 'さん'][random_int(0, 4)],
            default => '様',
        };
    }

    public static function processSpintax(string $text): string
    {
        if (!str_contains($text, '|')) {
            return $text;
        }
        $pattern = '/\{([^{}]+?\|[^{}]*?)\}/';
        $maxIterations = 20;
        while ($maxIterations-- > 0 && preg_match($pattern, $text, $matches)) {
            $options = explode('|', $matches[1]);
            $picked = $options[array_rand($options)];
            $text = substr_replace($text, $picked, strpos($text, $matches[0]), strlen($matches[0]));
        }
        return $text;
    }
}
