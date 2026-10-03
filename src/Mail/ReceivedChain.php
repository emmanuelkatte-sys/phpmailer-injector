<?php

declare(strict_types=1);

namespace Warship\Injector\Mail;

use Warship\Injector\Config\Config;

/**
 * 智能中继链路拓扑伪装生成器 (对齐 GUI rcvd_preview.py)
 */
final class ReceivedChain
{
    private static function formatRfc2822Jst(\DateTimeImmutable $d): string
    {
        $jst = new \DateTimeZone('+09:00');
        $d = $d->setTimezone($jst);
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $w = (int) $d->format('w');
        $m = (int) $d->format('n');
        return sprintf(
            '%s, %02d %s %d %02d:%02d:%02d +0900 (JST)',
            $days[$w],
            (int) $d->format('d'),
            $months[$m],
            (int) $d->format('Y'),
            (int) $d->format('H'),
            (int) $d->format('i'),
            (int) $d->format('s')
        );
    }

    private static function detectMxType(string $rcptDomain): string
    {
        $d = strtolower(trim($rcptDomain));
        if (str_contains($d, '@')) {
            $parts = explode('@', $d);
            $d = end($parts);
        }
        foreach (['docomo.ne.jp', 'spmode.ne.jp', 'mopera.net', 'biglobe.ne.jp', 'plala.or.jp', 'ocn.ne.jp'] as $x) {
            if (str_contains($d, $x)) {
                return 'carrier_docomo';
            }
        }
        foreach (['ezweb.ne.jp', 'au.com', 'uqmobile.jp', 'dion.ne.jp'] as $x) {
            if (str_contains($d, $x)) {
                return 'carrier_kddi';
            }
        }
        foreach (['softbank.ne.jp', 'i.softbank.jp', 'vodafone.ne.jp', 'ymobile.ne.jp', 'yahoo.co.jp'] as $x) {
            if (str_contains($d, $x)) {
                return 'carrier_softbank';
            }
        }
        foreach (['gmail.com', 'googlemail.com', 'icloud.com'] as $x) {
            if (str_contains($d, $x)) {
                return 'aws_tokyo';
            }
        }
        foreach (['outlook.com', 'outlook.jp', 'hotmail.com', 'office365.com'] as $x) {
            if (str_contains($d, $x)) {
                return 'enterprise';
            }
        }
        if (str_ends_with($d, '.jp') || str_ends_with($d, '.co.jp')) {
            return 'japan_idc';
        }
        return 'enterprise';
    }

    /**
     * @return array{0: string, 1: string} [ip, host]
     */
    private static function getPublicNode(string $targetType, string $dom): array
    {
        switch ($targetType) {
            case 'carrier_docomo':
                $subnets = ['210.150.', '211.125.', '210.140.', '203.138.'];
                $sub = $subnets[random_int(0, count($subnets) - 1)];
                $ip = sprintf('%s%d.%d', $sub, random_int(10, 230), random_int(1, 254));
                $host = sprintf('mail-gw%02d.%s', random_int(1, 16), $dom);
                return [$ip, $host];

            case 'carrier_kddi':
                $subnets = ['106.187.', '118.159.', '202.214.'];
                $sub = $subnets[random_int(0, count($subnets) - 1)];
                $ip = sprintf('%s%d.%d', $sub, random_int(10, 230), random_int(1, 254));
                $host = sprintf('telehouse-relay%02d.%s', random_int(1, 16), $dom);
                return [$ip, $host];

            case 'carrier_softbank':
                $subnets = ['101.110.', '126.140.', '126.240.'];
                $sub = $subnets[random_int(0, count($subnets) - 1)];
                $ip = sprintf('%s%d.%d', $sub, random_int(10, 230), random_int(1, 254));
                if ($sub === '101.110.') {
                    $host = sprintf('imsa%04d.mailsv.softbank.jp', random_int(4001, 4099));
                } else {
                    $host = sprintf('ty3-core%02d.%s', random_int(1, 16), $dom);
                }
                return [$ip, $host];

            case 'aws_tokyo':
                $subnets = ['52.68.', '54.64.', '13.112.', '13.230.'];
                $b = $subnets[random_int(0, count($subnets) - 1)];
                $o3 = random_int(10, 230);
                $o4 = random_int(1, 254);
                $ip = sprintf('%s%d.%d', $b, $o3, $o4);
                $bDash = str_replace('.', '-', $b);
                $host = sprintf('ec2-%s%d-%d.ap-northeast-1.compute.amazonaws.com', $bDash, $o3, $o4);
                return [$ip, $host];

            case 'japan_idc':
                $subnets = ['133.242.', '160.16.', '153.120.'];
                $sub = $subnets[random_int(0, count($subnets) - 1)];
                $ip = sprintf('%s%d.%d', $sub, random_int(10, 230), random_int(1, 254));
                $host = sprintf('mail-dc%02d.%s', random_int(1, 16), $dom);
                return [$ip, $host];

            default: // enterprise
                $subnets = ['210.150.', '106.187.', '202.214.', '133.242.'];
                $sub = $subnets[random_int(0, count($subnets) - 1)];
                $ip = sprintf('%s%d.%d', $sub, random_int(10, 230), random_int(1, 254));
                $host = sprintf('mailgw%02d.%s', random_int(1, 16), $dom);
                return [$ip, $host];
        }
    }

    /**
     * @return array{0: string, 1: string} [ip, host]
     */
    private static function getInternalNode(string $dom, string $style): array
    {
        $ip = sprintf('10.%d.%d.%d', random_int(10, 240), random_int(1, 254), random_int(1, 254));
        $roles = ['app-node', 'worker', 'core-worker', 'job-runner', 'dispatch', 'mail-backend'];
        $role = $roles[random_int(0, count($roles) - 1)];
        $num = sprintf('%02d', random_int(1, 32));

        $st = strtolower(trim($style));
        switch ($st) {
            case 'japan_telecom':
                $suf = '.tokyo.internal.jp';
                break;
            case 'cloud_vpc':
                $suf = '.ap-northeast-1.internal';
                break;
            case 'enterprise':
                $suf = '.corp.internal';
                break;
            case 'bound_domain':
                $suf = sprintf('.internal.%s', $dom);
                break;
            default:
                $sufs = ['.tokyo.internal.jp', '.internal', '.vpc.internal', sprintf('.internal.%s', $dom)];
                $suf = $sufs[random_int(0, count($sufs) - 1)];
                break;
        }

        return [$ip, sprintf('%s%s%s', $role, $num, $suf)];
    }

    /**
     * 生成 1~2 跳 Received 邮件头内容列表（不含 "Received: " 前缀）
     *
     * @return list<string>
     */
    public static function generate(Config $cfg, string $fromAddress, string $toEmail): array
    {
        $chainType = strtolower(trim($cfg->rcvdChainType));
        if ($chainType === '' || $chainType === 'smart_auto') {
            $chainType = 'smart_auto';
        }

        $hopsCount = max(1, min(2, $cfg->rcvdChainHops));
        if (in_array($chainType, ['mobile_client', 'official_std', 'standard_relay'], true)) {
            $hopsCount = 1;
        }

        $dom = $cfg->headerMessageIdFullDomain ?: '';
        if ($dom === '') {
            $parts = explode('@', $fromAddress, 2);
            $dom = $parts[1] ?? 'marketing.co.jp';
        }

        $effectiveType = $chainType === 'smart_auto' ? self::detectMxType($toEmail) : $chainType;

        $id1 = strtoupper(bin2hex(random_bytes(5)));
        $id2 = strtoupper(bin2hex(random_bytes(5)));

        $now = new \DateTimeImmutable('now');
        $date1 = self::formatRfc2822Jst($now);
        $date2 = self::formatRfc2822Jst($now->sub(new \DateInterval(sprintf('PT%dS', random_int(2, 15)))));

        $forPart = $toEmail !== '' ? sprintf(' for <%s>', $toEmail) : '';

        // 自定义中继模板
        if ($chainType === 'custom' && trim($cfg->rcvdChainCustom) !== '') {
            [$cIp, $cHost] = self::getPublicNode($effectiveType, $dom);
            $rendered = $cfg->rcvdChainCustom;
            $rendered = str_replace('{client_ip}', $cIp, $rendered);
            $rendered = str_replace('{client_host}', $cHost, $rendered);
            $rendered = str_replace('{sub_host}', $cHost, $rendered);
            $rendered = str_replace('{domain}', $dom, $rendered);
            $rendered = str_replace('{id}', $id1, $rendered);
            $rendered = str_replace('{for_part}', $forPart, $rendered);
            $rendered = str_replace('{to}', $toEmail, $rendered);
            $rendered = str_replace('{date}', $date1, $rendered);

            $lines = [];
            foreach (preg_split('/\r?\n/', $rendered) as $line) {
                $l = trim((string) $line);
                if ($l !== '') {
                    if (str_starts_with(strtolower($l), 'received:')) {
                        $l = trim(substr($l, 9));
                    }
                    $lines[] = $l;
                }
            }
            return $lines;
        }

        // 移动端单跳链路 (iPhone / Android)
        if ($chainType === 'mobile_client') {
            $helos = ['smtpclient.apple', 'iphone.local', 'iphone.lan', 'android-mail', 'mail-client'];
            $helo = $helos[random_int(0, count($helos) - 1)];
            if (random_int(0, 99) < 70) {
                $ip = sprintf('100.%d.%d.%d', random_int(64, 127), random_int(0, 255), random_int(1, 254));
            } else {
                $ip = sprintf('192.168.%d.%d', random_int(0, 255), random_int(1, 254));
            }
            return [sprintf('from %s ([%s]) by %s with ESMTPSA id %s%s; %s', $helo, $ip, $dom, $id1, $forPart, $date1)];
        }

        // 官方标准外网单跳
        if ($chainType === 'official_std' || $chainType === 'standard_relay') {
            return [sprintf('from [127.0.0.1] (localhost [127.0.0.1]) by %s with ESMTPSA id %s%s; %s', $dom, $id1, $forPart, $date1)];
        }

        // IP 网段策略
        $ipPool = strtolower(trim($cfg->rcvdChainIpPool));
        if ($ipPool === '') {
            $ipPool = 'smart_pool';
        }
        $usePublic = ($ipPool === 'japan_public') || ($ipPool === 'smart_pool' && in_array($effectiveType, ['carrier_docomo', 'carrier_softbank', 'carrier_kddi', 'aws_tokyo', 'japan_idc', 'enterprise'], true));
        $isHybrid = ($ipPool === 'hybrid_mix') || ($ipPool === 'smart_pool' && $hopsCount === 2);

        [$pubIp, $pubHost] = self::getPublicNode($effectiveType, $dom);
        [$intIp, $intHost] = self::getInternalNode($dom, $cfg->rcvdChainDomainStyle);

        // MTA 引擎指纹
        $sw1 = 'with ESMTPS';
        $sw2 = 'with ESMTPA';
        $mta = strtolower(trim($cfg->rcvdChainMtaFlavor));
        switch ($mta) {
            case 'postfix':
                $sw1 = '(Postfix) with ESMTPS';
                $sw2 = '(Postfix) with ESMTPA';
                break;
            case 'sendmail':
                $sw1 = '(8.15.2/8.15.2) with ESMTP';
                $sw2 = 'with ESMTPA';
                break;
            case 'cisco':
                $sw1 = '(Cisco ESA 14.2) with ESMTP';
                $sw2 = 'with ESMTPA';
                break;
            case 'smart_match':
                if ($effectiveType === 'carrier_kddi') {
                    $sw1 = '(8.15.2/8.15.2) with ESMTP';
                    $sw2 = 'with ESMTPA';
                } elseif ($effectiveType === 'carrier_softbank') {
                    $sw1 = 'with ESMTPS';
                    $sw2 = 'with ESMTPA';
                } else {
                    $sw1 = '(Postfix) with ESMTPS';
                    $sw2 = '(Postfix) with ESMTPA';
                }
                break;
            case 'pure_rfc':
            default:
                $sw1 = 'with ESMTPS';
                $sw2 = 'with ESMTPA';
                break;
        }

        $lines = [];

        switch ($effectiveType) {
            case 'carrier_docomo':
                $cIp = ($usePublic || $isHybrid) ? $pubIp : $intIp;
                $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $pubHost, $cIp, $dom, $sw1, $id1, $forPart, $date1);
                if ($hopsCount === 2) {
                    $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $intHost, $intIp, $pubHost, $sw2, $id2, $forPart, $date2);
                }
                break;

            case 'carrier_kddi':
                $cIp = ($usePublic || $isHybrid) ? $pubIp : $intIp;
                $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $pubHost, $cIp, $dom, $sw1, $id1, $forPart, $date1);
                if ($hopsCount === 2) {
                    $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $intHost, $intIp, $pubHost, $sw2, $id2, $forPart, $date2);
                }
                break;

            case 'carrier_softbank':
                $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $pubHost, $pubIp, $dom, $sw1, $id1, $forPart, $date1);
                if ($hopsCount === 2) {
                    $lines[] = sprintf(
                        "from %s by %s with ESMTP id <%s.WORB.%d.%s@mailsv.softbank.jp>%s; %s",
                        $intHost,
                        $pubHost,
                        $now->format('YmdHis'),
                        random_int(10000, 99999),
                        $pubHost,
                        $forPart,
                        $date2
                    );
                }
                break;

            case 'aws_tokyo':
                $lines[] = sprintf("from %s (%s [%s]) by mail.%s %s id %s%s; %s", $pubHost, $pubHost, $pubIp, $dom, $sw1, $id1, $forPart, $date1);
                if ($hopsCount === 2) {
                    $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $intHost, $intIp, $pubHost, $sw2, $id2, $forPart, $date2);
                }
                break;

            case 'japan_idc':
                $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $pubHost, $pubIp, $dom, $sw1, $id1, $forPart, $date1);
                if ($hopsCount === 2) {
                    $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $intHost, $intIp, $pubHost, $sw2, $id2, $forPart, $date2);
                }
                break;

            default: // enterprise
                $cIp = $usePublic ? $pubIp : $intIp;
                $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $pubHost, $cIp, $dom, $sw1, $id1, $forPart, $date1);
                if ($hopsCount === 2) {
                    $lines[] = sprintf("from %s ([%s]) by %s %s id %s%s; %s", $intHost, $intIp, $pubHost, $sw2, $id2, $forPart, $date2);
                }
                break;
        }

        return $lines;
    }
}
