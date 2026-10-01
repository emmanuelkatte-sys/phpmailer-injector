<?php

declare(strict_types=1);

namespace Warship\Injector\Util;

final class DomainHelper
{
    private const TWO_PART_TLDS = [
        'co.jp' => true, 'ne.jp' => true, 'ac.jp' => true, 'go.jp' => true, 'or.jp' => true, 'ed.jp' => true, 'ad.jp' => true, 'gr.jp' => true,
        'com.cn' => true, 'net.cn' => true, 'org.cn' => true, 'gov.cn' => true, 'edu.cn' => true,
        'co.uk' => true, 'org.uk' => true, 'me.uk' => true, 'ltd.uk' => true, 'plc.uk' => true,
        'com.tw' => true, 'org.tw' => true, 'idv.tw' => true,
        'com.hk' => true, 'org.hk' => true, 'edu.hk' => true,
        'com.au' => true, 'net.au' => true, 'org.au' => true,
        'co.kr' => true, 'ne.kr' => true, 're.kr' => true,
        'co.nz' => true, 'net.nz' => true, 'org.nz' => true,
        'com.br' => true, 'net.br' => true,
        'com.sg' => true, 'edu.sg' => true,
    ];

    public static function extractRootDomain(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return 'localhost';
        }
        $parts = explode('.', $host);
        $cnt = count($parts);
        if ($cnt <= 2) {
            return $host;
        }
        $lastTwo = $parts[$cnt - 2] . '.' . $parts[$cnt - 1];
        if (isset(self::TWO_PART_TLDS[$lastTwo]) && $cnt >= 3) {
            return $parts[$cnt - 3] . '.' . $lastTwo;
        }
        return $parts[$cnt - 2] . '.' . $parts[$cnt - 1];
    }
}
