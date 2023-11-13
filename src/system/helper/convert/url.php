<?php

namespace mpcmf\system\helper\convert;

/**
 * URL normalization class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @author Oleg Andreev <ustrets@gmail.com>
 *
 * @package mpcmf\system\helper
 */
class url
{
    const BUILD_QUERY_REPLACE_HACK_STR = 'blahblahstrangehttpqueryparamvalue';

    /**
     * @param string $baseUrl
     * @param string $link
     *
     * @return string
     */
    public static function getAbsoluteUrl($baseUrl, $link)
    {
        if (empty($link)) {
            return $baseUrl;
        }
        $parsedLink = parse_url($link);
        $result = [];

        if (!empty($parsedLink['scheme'])) {
            return $link;
        }
        $parsedBase = parse_url($baseUrl);
        $parsedLink['scheme'] = isset($parsedBase['scheme']) ? $parsedBase['scheme'] : null;
        if (isset($parsedLink['host'])) {
            return self::unParseUrl($parsedLink);
        }
        $result['scheme'] = isset($parsedBase['scheme']) ? $parsedBase['scheme'] : null;
        $result['user'] = isset($parsedBase['user']) ? $parsedBase['user'] : null;
        $result['password'] = isset($parsedBase['password']) ? $parsedBase['password'] : null;
        $result['host'] = isset($parsedBase['host']) ? $parsedBase['host'] : null;
        if (isset($parsedLink['path'])) {
            $linkPath = explode('/', $parsedLink['path']);
            $patterns = [
                'file' => '/[^\/]*$/ui',
                'folder' => '/[^\/]*\/[^\/]*$/ui'
            ];
            $resultPath = isset($parsedBase['path']) ? $parsedBase['path'] : '';
            foreach ($linkPath as $key => $item) {
                switch ($item) {
                    case '..':
                        $resultPath = preg_replace($patterns['folder'], '', $resultPath);
                        break;
                    case '.':
                        $resultPath = preg_replace($patterns['file'], '', $resultPath);
                        break;
                    case '':
                        if ($key == 0) {
                            $resultPath = '/';
                        }
                        break;
                    default:
                        $resultPath = preg_replace($patterns['file'], '', $resultPath);
                        $resultPath .= $item;
                        if ($key !== count($linkPath) - 1) {
                            $resultPath .= '/';
                        }
                        break;
                }
            }
            $result['path'] = $resultPath;
        } else {
            $result['path'] = isset($parsedBase['path']) ? $parsedBase['path'] : null;
        }
        if (empty($result['path']) || mb_substr($result['path'], 0, 1) !== '/') {
            $result['path'] = "/{$result['path']}";
        }
        if (isset($parsedLink['query'])) {
            $result['query'] = $parsedLink['query'];
        } elseif (isset($parsedLink['fragment']) && !isset($parsedLink['path'])) {
            $result['query'] = isset($parsedBase['query']) ? $parsedBase['query'] : null;
        }
        if (isset($parsedLink['fragment'])) {
            $result['fragment'] = $parsedLink['fragment'];
        }

        return self::unParseUrl($result);
    }

    /**
     * @param $parsed_url
     *
     * @author thomas@gielfeldt.com from http://www.php.net/manual/ru/function.parse-url.php
     *
     * @return string
     */
    public static function unParseUrl($parsed_url)
    {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';

        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) && !empty($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "{$pass}@" : '';

        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) && !empty($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) && !empty($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        return "{$scheme}{$user}{$pass}{$host}{$port}{$path}{$query}{$fragment}";
    }

    /**
     * @param string $url
     *
     * @return string
     */
    public static function urlEncode($url)
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['path'])) {
            return $url;
        }
        //http://php.net/manual/en/function.urlencode.php#97969
        $parsedUrl['path'] = str_replace(['%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B', '%5D'], ['!', '*', "'", '(', ')', ';', ':', '@', '&', '=', '+', '$', ',', '/', '?', '%', '#', '[', ']'], urlencode($parsedUrl['path']));

        return self::unParseUrl($parsedUrl);
    }

    /**
     * @param        $url
     * @param null|array   $params
     * @param string $method
     *
     * @return array
     */
    public static function buildUrl($url, $params = null, $method = 'GET')
    {
        $result = [
            'url' => '',
            'method' => '',
            'payload' => ''
        ];

        $payload = [];

        $result['url'] = trim($url);

        if (is_array($params)) {
            $payload = array_merge($payload, $params);
        }
        switch(strtoupper($method)) {
            case 'GET':
                $result['method'] = 'GET';
                $parsed = parse_url($result['url']);
                if(isset($parsed['query'])) {
                    parse_str($parsed['query'], $parsedQuery);
                    if(is_array($parsedQuery)) {
                        $payload = $parsedQuery;
                    }
                    unset($parsed['query']);
                }
                $result['url'] = self::unParseUrl($parsed);
                $payloadStr = self::httpBuildQuery($payload);
                if(strlen($payloadStr) > 0) {
                    $result['url'] .= "?{$payloadStr}";
                }
                break;
            default:
                $result['method'] = 'POST';
                $payloadStr = self::httpBuildQuery($payload);
                if(strlen($payloadStr) > 0) {
                    $result['payload'] = $payloadStr;
                }
                break;
        }
        return $result;
    }

    public static function httpBuildQuery($query_data)
    {
        array_walk_recursive($query_data, function(&$item) {
            if($item === null || $item === '') {
                $item = self::BUILD_QUERY_REPLACE_HACK_STR;
            }
        });

        $queryStr = http_build_query($query_data);

        return str_replace('=' . self::BUILD_QUERY_REPLACE_HACK_STR, '', $queryStr);
    }

    /**
     * Get base host for url
     *
     * @example [this("http://www.asd.example.com/asdfasdf?asds=123", false)] asd.example.com
     * @example [this("http://www.asd.example.com/asdfasdf?asds=123", false)] example.com
     * @param string $url
     * @param bool   $cutSubdomains
     * @return bool|string
     */
    public static function getBaseHost($url, $cutSubdomains = false)
    {
        $base_host = parse_url($url, PHP_URL_HOST);
        if (!$base_host) {
            return false;
        }
        if (mb_strpos($base_host, 'www.') === 0) {
            $base_host = mb_substr($base_host, 4);
        }
        if ($cutSubdomains) {
            $base_host_array = explode('.', $base_host);
            $zone = array_pop($base_host_array);
            $domain = array_pop($base_host_array);
            $base_host = "{$domain}.{$zone}";
        }

        return $base_host;
    }

    public static function getBaseDomain($domain)
    {
        $domainZone = self::getDomainZone($domain);
        if($domainZone === $domain) {

            return null;
        }
        $domain = preg_replace('/^(?:[^\:]+\:\/\/)?([^\/\#\?\:]+).*$/', '$1', $domain);
        $domainWoZone = str_replace(".{$domainZone}", '', $domain);

        if(mb_strpos($domainWoZone, '.') !== false) {
            $domainWoZone = mb_substr($domainWoZone, strrpos($domainWoZone, '.') + 1);
        }

        return "{$domainWoZone}.{$domainZone}";
    }

    /**
     * Clean up url
     *
     * @param string $url
     * @param bool   $removeAuth   Remove authorization
     * @param bool   $removePath   Remove path
     * @param bool   $removeQuery  Remove query params
     * @param bool   $entityDecode Decode htmlentity query params
     * @return string Result
     */
    public static function cleanUp($url, $removeAuth = false, $removePath = false, $removeQuery = false, $entityDecode = false)
    {
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['host'], $parsed_url['scheme'])) {
            return false;
        }
        $new_url = "{$parsed_url['scheme']}://";
        if (!$removeAuth && isset($parsed_url['user'])) {
            $new_url .= $parsed_url['user'];
            if (isset($parsed_url['pass'])) {
                $new_url .= ":{$parsed_url['pass']}";
            }
            $new_url .= '@';
        }
        $new_url .= $parsed_url['host'];
        if (!empty($parsed_url['port'])) {
            $new_url .= ":{$parsed_url['port']}";
        }
        if (!$removePath) {
            if (!isset($parsed_url['path'])) {
                $parsed_url['path'] = '/';
            }
            $new_url .= $parsed_url['path'];
        }
        if (!$removeQuery && isset($parsed_url['query'])) {
            if ($entityDecode) {
                $new_url .= '?' . html_entity_decode($parsed_url['query']);
            } else {
                $new_url .= "?{$parsed_url['query']}";
            }
        }

        return $new_url;
    }

    /**
     * Parse url expression
     *
     * @param        $url
     * @param string $mod
     *
     * @return string
     */
    public static function parseUrlExpression($url, $mod = 'ui')
    {
        $replacements = [
            'pre' => [
                '/\$\(STRING\)/' => '$(LSD)$(STR)$(SPC)$(RSD)$(PLUS)',
                '/\$\(EXT_STRING\)/' => '$(LSD)$(STR)$(NUM)$(SPC).%:_-$(RSD)$(PLUS)',
                '/\$\(FLOAT\)/' => '$(INT)$(LSD).$(PIPE),$(RSD)$(INT)',
                '/\$\(AZ09SPC\)/' => '$(LSD)$(STR)$(NUM)$(SPC)$(RSD)$(PLUS)',
                '/\$\(ALL\)/' => '$(DOT)$(STAR)',
                '/\$\(STR\)/' => 'a$(MINUS)zA$(MINUS)Zа$(MINUS)яА$(MINUS)ЯёЁ',
                '/\$\(INT\)/' => '$(NUM)$(PLUS)',
            ],
            'markers' => [
                '/\$\(LSD\)/' => '__URL__LSD__',
                '/\$\(RSD\)/' => '__URL__RSD__',
                '/\$\(LD\)/' => '__URL__LD__',
                '/\$\(RD\)/' => '__URL__RD__',
                '/\$\(DOT\)/' => '__URL__DOT__',
                '/\$\(QM\)/' => '__URL__QM__',
                '/\$\(EM\)/' => '__URL__EM__',
                '/\$\(MINUS\)/' => '__URL__MINUS__',
                '/\$\(PLUS\)/' => '__URL__PLUS__',
                '/\$\(STAR\)/' => '__URL__STAR__',
                '/\$\(PIPE\)/' => '__URL__PIPE__',
                '/\$\(SPC\)/' => '__URL__SPACE__',
                '/\$\(NUM\)/' => '__URL__NUM__',
            ],
            'ending' => [
                '/\$$/' => '__URL__DOLLAR__',
            ],
            'post' => [
                '/__URL__LSD__/' => '[',
                '/__URL__RSD__/' => ']',
                '/__URL__LD__/' => '(',
                '/__URL__RD__/' => ')',
                '/__URL__DOT__/' => '.',
                '/__URL__QM__/' => '?',
                '/__URL__EM__/' => '!',
                '/__URL__MINUS__/' => '-',
                '/__URL__PLUS__/' => '+',
                '/__URL__STAR__/' => '*',
                '/__URL__PIPE__/' => '|',
                '/__URL__SPACE__/' => '\\s',
                '/__URL__NUM__/' => '\\d',
                '/__URL__DOLLAR__/' => '$',
            ]
        ];

        $url = preg_replace(array_keys($replacements['pre']), array_values($replacements['pre']), $url);
        $url = preg_replace(array_keys($replacements['markers']), array_values($replacements['markers']), $url);
        $url = preg_replace(array_keys($replacements['ending']), array_values($replacements['ending']), $url);
        $url = preg_quote($url, '/');
        $url = preg_replace(array_keys($replacements['post']), array_values($replacements['post']), $url);

        return "/{$url}/{$mod}";
    }

    /**
     * Remove session identifier from url
     *
     * @param string $url
     * @param bool   $sortParams
     * @param array|null  $subdomainVariants - ['www', 't', 'm']
     *
     * @return string
     */
    public static function removeSession($url = '', $sortParams = false, array $subdomainVariants = null)
    {
        $parsed = parse_url($url);

        if(is_array($subdomainVariants) && isset($parsed['host'])) {
            $parsed['host'] = self::getCleanDomain($parsed['host'], $subdomainVariants);

            $url = self::unParseUrl($parsed);
        }

        if(!isset($parsed['query'])) {

            return $url;
        }
        parse_str($parsed['query'], $query);
        foreach ($query as $paramName => $value) {
            $strUppedParam = strtoupper($paramName);
            foreach (self::$utmTags as $tag) {
                if(strpos($strUppedParam, $tag) === 0) {
                    unset($query[$paramName]);
                    continue 2;
                }
            }
            switch ($strUppedParam) {
                case 'SPLIT_NG':
                case 'R':
                case 'TRACK':
                case 'SESSIONID':
                case 'SESSION_ID':
                case 'SESSION-ID':
                case 'SESSION-KEY':
                case 'SESSIONKEY':
                case 'SESSID':
                case 'SSID':
                case 'S_ID':
                case 'PHPSESSID':
                case 'JSESSIONID':
                case 'JSESSION_ID':
                case 'JSESSID':
                case 'J-SESSIONID':
                case 'JSID':
                case 'J-SID':
                case 'JID':
                case 'XID':
                case 'X_ID':
                case 'X-ID':
                case 'ASPSESSIONID':
                case 'ASPSESSION-ID':
                case 'SID':
                case 'S':
                case 'C-ID':
                case 'CFID':
                case 'CFTOKEN':
                case 'CUST-ID':
                case 'SECID':
                case 'SECURE':
                case 'SECUREID':
                case 'PRINT':
                case 'LIMITED':
                case 'OE':
                case 'OH':
                case 'TKN':
                case 'AID':
                case 'PLC':
                case 'CFS':
                case 'FEATURE':
                case 'SUB_CONFIRMATION':
                case 'VIEW_AS':
                case 'FBCLID':
                case 'IGSHID':
                case 'REF':
                case 'USP':
                case 'TS':
                case 'RID':
                case 'SSL':
                case 'SIGN':
                case 'PARTNER':
                case 'SOCIAL':
                case 'SECDATA':
                case 'SMID':
                case 'SMTYP':
                case '_UNIQUE_ID':
                case 'CMP':
                case 'REFERRER':
                case 'OCID':
                case 'CLID':
                case 'STID':
                    unset($query[$paramName]);
                    break;
                default:
                    break;
            }
        }
        if($sortParams) {
            ksort($query, SORT_ASC);
        }

        $queryString = self::httpBuildQuery($query);
        if (empty($queryString)) {
            unset($parsed['query']);
        } else {
            $parsed['query'] = $queryString;
        }
        $url = self::unParseUrl($parsed);

        return $url;
    }

    /**
     * @param $url
     * @param $excludeParams
     *
     * @return string
     */
    public static function excludeParamsFromUrl($url, $excludeParams)
    {
        if (!is_array($excludeParams)) {
            error_log('[ERROR]' . __METHOD__ . 'Exclude params must be array!!! ' . gettype($excludeParams) . 'given!' );

            return $url;
        }
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['query'])) {
            return $url;
        }
        parse_str($parsedUrl['query'], $parsedQuery);
        foreach ($excludeParams as $param) {
            if (isset($parsedQuery[$param])) {
                unset($parsedQuery[$param]);
            }
        }
        $parsedUrl['query'] = self::httpBuildQuery($parsedQuery);

        return self::unParseUrl($parsedUrl);
    }

    public static function isDomainZone($domain)
    {
        return isset(self::$domainZones['.' . mb_strtolower($domain)]);
    }

    public static function getDomainZone($domain)
    {
        $domainToCheck = preg_replace('/^(?:[^\:]+\:\/\/)?([^\/\#\?\:]+).*$/', '$1', $domain);

        while(!self::isDomainZone($domainToCheck) && ($offset = mb_strpos($domainToCheck, '.'))) {
            $domainToCheck = mb_substr($domainToCheck, $offset + 1);
        }

        return $domainToCheck;
    }

    public static function getDomainVariants($domain, $includeWww = true)
    {
        $variants = [];

        $domain = trim($domain);
        if(empty($domain)) {

            return $variants;
        }

        $domain = preg_replace('/^(?:[^\:]+\:\/\/)?([^\/\#\?\:]+).*$/', '$1', $domain);
        $fullDomain = strpos($domain, 'www.') === 0 ? substr($domain, 4) : $domain;

        $domainToCheck = $fullDomain;
        while(!url::isDomainZone($domainToCheck) && mb_strpos($domainToCheck, '.')) {
            $variants[] = $domainToCheck;
            if($includeWww) {
                $variants[] = "www.{$domainToCheck}";
            }
            $domainToCheck = mb_substr($domainToCheck, mb_strpos($domainToCheck, '.') + 1);
        }

        return $variants;
    }

    public static function getUrlVariants($url, $includeWww = true)
    {
        $urlVariants = [];

        $parsed = parse_url($url);

        if(!isset($parsed['scheme'], $parsed['host'])) {

            return $urlVariants;
        }

        $urlScheme = isset($parsed['scheme']) ? $parsed['scheme'] : null;
        if($urlScheme === null) {

            return $urlVariants;
        }

        $isSecure = mb_strtolower($parsed['scheme']) === 'https';

        $urlDomain = $parsed['host'];

        $domainVariants = self::getDomainVariants($urlDomain, $includeWww);

        foreach($domainVariants as $domainVariant) {
            $urlVariant = str_replace("://{$urlDomain}", "://{$domainVariant}", $url);
            $urlVariants[] = $urlVariant;
            if($isSecure) {
                $urlVariants[] = preg_replace('/^https\:/ui', 'http:', $urlVariant);
            } else {
                $urlVariants[] = preg_replace('/^http\:/ui', 'https:', $urlVariant);
            }
        }

        return $urlVariants;
    }

    public static function getUrlVariantsCustom($url, array $subdomainVariants = ['www', 't', 'm'])
    {
        $urlVariants = [];

        $parsed = parse_url($url);

        if(!isset($parsed['scheme'], $parsed['host'])) {

            return $urlVariants;
        }

        $urlScheme = isset($parsed['scheme']) ? $parsed['scheme'] : null;
        if($urlScheme === null) {

            return $urlVariants;
        }

        $isSecure = mb_strtolower($parsed['scheme']) === 'https';

        $urlDomain = $parsed['host'];

        $cleanDomain = self::getCleanDomain($urlDomain, $subdomainVariants);




        $urlVariant = str_replace("://{$urlDomain}", "://{$cleanDomain}", $url);
        $urlVariants[] = $urlVariant;
        if($isSecure) {
            $urlVariants[] = preg_replace('/^https\:/ui', 'http:', $urlVariant);
        } else {
            $urlVariants[] = preg_replace('/^http\:/ui', 'https:', $urlVariant);
        }

        foreach($subdomainVariants as $subdomain) {
            $urlVariant = str_replace("://{$urlDomain}", "://{$subdomain}.{$cleanDomain}", $url);
            $urlVariants[] = $urlVariant;

            if($isSecure) {
                $urlVariants[] = preg_replace('/^https\:/ui', 'http:', $urlVariant);
            } else {
                $urlVariants[] = preg_replace('/^http\:/ui', 'https:', $urlVariant);
            }
        }


        return $urlVariants;
    }

    public static function getUniversalHash($url, array $subdomainVariants = ['www', 't', 'm'])
    {
        return md5(self::getUniversalLink($url, $subdomainVariants));
    }

    public static function getUniversalLink($url, array $subdomainVariants = ['www', 't', 'm'])
    {
        $urlDomain = preg_replace('/^(?:[^\:]+\:\/\/)?([^\/\#\?\:]+).*$/', '$1', $url);

        $cleanDomain = self::getCleanDomain($urlDomain, $subdomainVariants);

        $cleanLink = preg_replace('/^(?:[^\:]+\:\/\/)?(?:[^\/\#\?\:]+)(.*)$/', "//{$cleanDomain}\$1", self::removeSession($url, true));
        $parsed = parse_url("schemehack:{$cleanLink}");
        if(empty($parsed['path'])) {
            $parsed['path'] = '/';
        }
        $cleanLink = self::unParseUrl($parsed);
        $cleanLink = preg_replace('/^schemehack\:/', '', $cleanLink);

        return $cleanLink;
    }

    public static function getCleanDomain($urlDomain, array $subdomainVariants = ['www', 't', 'm'])
    {
        $cleanDomain = $urlDomain;
        if ($urlDomain !== self::getBaseDomain($urlDomain)) {
            foreach ($subdomainVariants as $subdomain) {
                if (strpos($cleanDomain, "{$subdomain}.") === 0) {
                    $cleanDomain = preg_replace('/^' . preg_quote($subdomain, '/') . '\./ui', '', $cleanDomain);
                }
            }
        }

        return mb_strtolower($cleanDomain);
    }

    protected static $domainZones = [
        '.ab.ca' => true,
        '.abkhazia.su' => true,
        '.ac' => true,
        '.ac.at' => true,
        '.ac.be' => true,
        '.ac.ci' => true,
        '.ac.cn' => true,
        '.ac.fj' => true,
        '.ac.gn' => true,
        '.ac.il' => true,
        '.ac.im' => true,
        '.ac.in' => true,
        '.ac.ir' => true,
        '.ac.jp' => true,
        '.ac.ke' => true,
        '.ac.kr' => true,
        '.ac.me' => true,
        '.ac.mw' => true,
        '.ac.ni' => true,
        '.ac.nz' => true,
        '.ac.pg' => true,
        '.ac.pr' => true,
        '.ac.se' => true,
        '.ac.sz' => true,
        '.ac.tj' => true,
        '.ac.uk' => true,
        '.ac.vn' => true,
        '.ad' => true,
        '.ad.jp' => true,
        '.adm.br' => true,
        '.adv.br' => true,
        '.adygeya.ru' => true,
        '.adygeya.su' => true,
        '.ae' => true,
        '.aero' => true,
        '.af' => true,
        '.ag' => true,
        '.agr.br' => true,
        '.agro.pl' => true,
        '.ah.cn' => true,
        '.ai' => true,
        '.aichi.jp' => true,
        '.aktyubinsk.su' => true,
        '.ak.us' => true,
        '.al' => true,
        '.aland.fi' => true,
        '.al.us' => true,
        '.am' => true,
        '.an' => true,
        '.ao' => true,
        '.aq' => true,
        '.ar' => true,
        '.arkhangelsk.su' => true,
        '.armenia.su' => true,
        '.arq.br' => true,
        '.art.br' => true,
        '.art.pl' => true,
        '.arts.ro' => true,
        '.ar.us' => true,
        '.as' => true,
        '.a.se' => true,
        '.ashgabad.su' => true,
        '.asia' => true,
        '.asn.au' => true,
        '.asso.fr' => true,
        '.at' => true,
        '.at.ua' => true,
        '.au' => true,
        '.auto.pl' => true,
        '.av.tr' => true,
        '.aw' => true,
        '.ax' => true,
        '.az' => true,
        '.azerbaijan.su' => true,
        '.az.us' => true,
        '.ba' => true,
        '.balashov.su' => true,
        '.bashkiria.ru' => true,
        '.bashkiria.su' => true,
        '.bb' => true,
        '.bbs.tr' => true,
        '.bc.ca' => true,
        '.bd' => true,
        '.bd.se' => true,
        '.be' => true,
        '.bel.tr' => true,
        '.beskidy.pl' => true,
        '.bf' => true,
        '.bg' => true,
        '.bh' => true,
        '.bi' => true,
        '.bialystok.pl' => true,
        '.bieszczady.pl' => true,
        '.bio.br' => true,
        '.bir.ru' => true,
        '.biz' => true,
        '.biz.at' => true,
        '.biz.az' => true,
        '.biz.bb' => true,
        '.biz.cy' => true,
        '.biz.dk' => true,
        '.biz.et' => true,
        '.biz.id' => true,
        '.biz.ki' => true,
        '.biz.mv' => true,
        '.biz.mw' => true,
        '.biz.ni' => true,
        '.biz.nr' => true,
        '.biz.pl' => true,
        '.biz.pr' => true,
        '.biz.tj' => true,
        '.biz.tr' => true,
        '.biz.ua' => true,
        '.biz.vn' => true,
        '.biz.zm' => true,
        '.bj' => true,
        '.bj.cn' => true,
        '.bm' => true,
        '.bn' => true,
        '.bo' => true,
        '.bolt.hu' => true,
        '.br' => true,
        '.brand.se' => true,
        '.bryansk.su' => true,
        '.bs' => true,
        '.bt' => true,
        '.bukhara.su' => true,
        '.bv.nl' => true,
        '.bw' => true,
        '.by' => true,
        '.bydgoszcz.pl' => true,
        '.bytom.pl' => true,
        '.bz' => true,
        '.bzh' => true,
        '.ca' => true,
        '.cat' => true,
        '.ca.us' => true,
        '.cbg.ru' => true,
        '.cc' => true,
        '.cci.fr' => true,
        '.cd' => true,
        '.cf' => true,
        '.cg' => true,
        '.ch' => true,
        '.chiba.jp' => true,
        '.chimkent.su' => true,
        '.ci' => true,
        '.cieszyn.pl' => true,
        '.cim.br' => true,
        '.ck' => true,
        '.ck.ua' => true,
        '.cl' => true,
        '.cloudnet.se' => true,
        '.club.tw' => true,
        '.cm' => true,
        '.cn' => true,
        '.cnt.br' => true,
        '.cn.ua' => true,
        '.co' => true,
        '.co.ag' => true,
        '.co.at' => true,
        '.co.bb' => true,
        '.co.bi' => true,
        '.co.ca' => true,
        '.co.cc' => true,
        '.co.ci' => true,
        '.co.ck' => true,
        '.co.cl' => true,
        '.co.cm' => true,
        '.co.cz' => true,
        '.co.dk' => true,
        '.co.gl' => true,
        '.co.gy' => true,
        '.co.hu' => true,
        '.co.id' => true,
        '.co.il' => true,
        '.co.in' => true,
        '.co.ir' => true,
        '.co.jp' => true,
        '.co.ke' => true,
        '.co.kr' => true,
        '.com' => true,
        '.co.ma' => true,
        '.com.ac' => true,
        '.com.af' => true,
        '.com.ar' => true,
        '.com.au' => true,
        '.com.bd' => true,
        '.com.bi' => true,
        '.com.bm' => true,
        '.com.bo' => true,
        '.com.br' => true,
        '.com.bs' => true,
        '.com.ci' => true,
        '.com.cn' => true,
        '.com.co' => true,
        '.com.cw' => true,
        '.com.de' => true,
        '.com.dm' => true,
        '.com.do' => true,
        '.co.me' => true,
        '.com.ec' => true,
        '.com.eg' => true,
        '.com.es' => true,
        '.com.et' => true,
        '.com.fj' => true,
        '.com.fr' => true,
        '.co.mg' => true,
        '.com.gh' => true,
        '.com.gi' => true,
        '.com.gl' => true,
        '.com.gn' => true,
        '.com.gp' => true,
        '.com.gr' => true,
        '.com.gt' => true,
        '.com.gy' => true,
        '.com.hk' => true,
        '.com.hn' => true,
        '.com.ht' => true,
        '.com.im' => true,
        '.com.io' => true,
        '.com.iq' => true,
        '.com.is' => true,
        '.com.kh' => true,
        '.com.km' => true,
        '.com.kp' => true,
        '.com.kw' => true,
        '.com.lc' => true,
        '.com.lr' => true,
        '.com.ly' => true,
        '.com.mg' => true,
        '.com.mm' => true,
        '.com.ms' => true,
        '.com.mw' => true,
        '.com.mx' => true,
        '.com.my' => true,
        '.com.nf' => true,
        '.com.ng' => true,
        '.com.nr' => true,
        '.com.pe' => true,
        '.com.pf' => true,
        '.com.pg' => true,
        '.com.ph' => true,
        '.com.pk' => true,
        '.com.pl' => true,
        '.com.pr' => true,
        '.com.pt' => true,
        '.com.re' => true,
        '.com.ro' => true,
        '.com.ru' => true,
        '.com.rw' => true,
        '.com.sa' => true,
        '.com.se' => true,
        '.com.sg' => true,
        '.com.sl' => true,
        '.com.so' => true,
        '.com.st' => true,
        '.com.tj' => true,
        '.com.tm' => true,
        '.com.to' => true,
        '.com.tr' => true,
        '.com.tw' => true,
        '.com.ua' => true,
        '.com.ug' => true,
        '.com.uy' => true,
        '.com.ve' => true,
        '.com.vi' => true,
        '.com.vn' => true,
        '.com.ws' => true,
        '.co.nl' => true,
        '.co.no' => true,
        '.co.nz' => true,
        '.coop' => true,
        '.coop.br' => true,
        '.co.pl' => true,
        '.co.pn' => true,
        '.co.pw' => true,
        '.co.st' => true,
        '.co.sz' => true,
        '.co.th' => true,
        '.co.tj' => true,
        '.co.tm' => true,
        '.co.tz' => true,
        '.co.ua' => true,
        '.co.uk' => true,
        '.co.us' => true,
        '.co.uz' => true,
        '.co.ve' => true,
        '.co.vi' => true,
        '.co.za' => true,
        '.cq.cn' => true,
        '.cr' => true,
        '.cri.nz' => true,
        '.cs' => true,
        '.c.se' => true,
        '.ct.us' => true,
        '.cu' => true,
        '.cv' => true,
        '.cv.ua' => true,
        '.cw' => true,
        '.cx' => true,
        '.cy' => true,
        '.cz' => true,
        '.czest.pl' => true,
        '.dagestan.ru' => true,
        '.dagestan.su' => true,
        '.dc.us' => true,
        '.de' => true,
        '.dep.no' => true,
        '.de.us' => true,
        '.dj' => true,
        '.dk' => true,
        '.dm' => true,
        '.dni.us' => true,
        '.dn.ua' => true,
        '.do' => true,
        '.dp.ua' => true,
        '.dr.tr' => true,
        '.d.se' => true,
        '.dz' => true,
        '.east-kazakhstan.su' => true,
        '.ec' => true,
        '.ed.jp' => true,
        '.edu' => true,
        '.edu.ac' => true,
        '.edu.ar' => true,
        '.edu.au' => true,
        '.edu.bi' => true,
        '.edu.bm' => true,
        '.edu.br' => true,
        '.edu.ci' => true,
        '.edu.cn' => true,
        '.edu.co' => true,
        '.edu.cw' => true,
        '.edu.dm' => true,
        '.edu.es' => true,
        '.edu.et' => true,
        '.edu.gi' => true,
        '.edu.gl' => true,
        '.edu.gn' => true,
        '.edu.gp' => true,
        '.edu.gr' => true,
        '.edu.gy' => true,
        '.edu.hn' => true,
        '.edu.in' => true,
        '.edu.is' => true,
        '.edu.it' => true,
        '.edu.ki' => true,
        '.edu.km' => true,
        '.edu.kn' => true,
        '.edu.kp' => true,
        '.edu.krd' => true,
        '.edu.lc' => true,
        '.edu.lr' => true,
        '.edu.me' => true,
        '.edu.mg' => true,
        '.edu.ml' => true,
        '.edu.mw' => true,
        '.edu.mx' => true,
        '.edu.my' => true,
        '.edu.nr' => true,
        '.edu.pf' => true,
        '.edu.pl' => true,
        '.edu.pn' => true,
        '.edu.pr' => true,
        '.edu.pt' => true,
        '.edu.rw' => true,
        '.edu.sb' => true,
        '.edu.sc' => true,
        '.edu.sg' => true,
        '.edu.st' => true,
        '.edu.tj' => true,
        '.edu.tm' => true,
        '.edu.tr' => true,
        '.edu.tw' => true,
        '.edu.ua' => true,
        '.edu.vc' => true,
        '.edu.vn' => true,
        '.edu.vu' => true,
        '.edu.ws' => true,
        '.ee' => true,
        '.eg' => true,
        '.elblag.pl' => true,
        '.elk.pl' => true,
        '.eng.br' => true,
        '.er' => true,
        '.es' => true,
        '.e.se' => true,
        '.es.kr' => true,
        '.esp.br' => true,
        '.et' => true,
        '.etc.br' => true,
        '.eti.br' => true,
        '.eu' => true,
        '.exnet.su' => true,
        '.fed.us' => true,
        '.fh.se' => true,
        '.fhsk.se' => true,
        '.fi' => true,
        '.firm.in' => true,
        '.firm.ro' => true,
        '.fj' => true,
        '.fj.cn' => true,
        '.fk' => true,
        '.fl.us' => true,
        '.fm' => true,
        '.fm.br' => true,
        '.fo' => true,
        '.fot.br' => true,
        '.fr' => true,
        '.f.se' => true,
        '.fukuoka.jp' => true,
        '.g12.br' => true,
        '.ga' => true,
        '.game.tw' => true,
        '.ga.us' => true,
        '.gc.ca' => true,
        '.gd' => true,
        '.gdansk.pl' => true,
        '.gda.pl' => true,
        '.gd.cn' => true,
        '.gdynia.pl' => true,
        '.ge' => true,
        '.gedo.se' => true,
        '.geek.nz' => true,
        '.gen.in' => true,
        '.gen.nz' => true,
        '.gen.tr' => true,
        '.georgia.su' => true,
        '.gf' => true,
        '.gg' => true,
        '.gh' => true,
        '.gh.cn' => true,
        '.gi' => true,
        '.gl' => true,
        '.gliwice.pl' => true,
        '.glogow.pl' => true,
        '.gm' => true,
        '.gn' => true,
        '.gniezno.pl' => true,
        '.gob.ar' => true,
        '.gob.cl' => true,
        '.gob.es' => true,
        '.gob.hn' => true,
        '.gob.mx' => true,
        '.go.jp' => true,
        '.go.ke' => true,
        '.go.kr' => true,
        '.gouv.fr' => true,
        '.gov' => true,
        '.gov.ac' => true,
        '.gov.ar' => true,
        '.gov.as' => true,
        '.gov.au' => true,
        '.gov.bo' => true,
        '.gov.br' => true,
        '.gov.bs' => true,
        '.gov.cd' => true,
        '.gov.ck' => true,
        '.gov.cl' => true,
        '.gov.cn' => true,
        '.gov.co' => true,
        '.gov.cu' => true,
        '.gov.cx' => true,
        '.gov.et' => true,
        '.gov.fj' => true,
        '.gov.gn' => true,
        '.gov.gr' => true,
        '.gov.ie' => true,
        '.gov.il' => true,
        '.gov.in' => true,
        '.gov.ir' => true,
        '.gov.is' => true,
        '.gov.km' => true,
        '.gov.kp' => true,
        '.gov.lr' => true,
        '.gov.lt' => true,
        '.gov.me' => true,
        '.gov.mm' => true,
        '.gov.mr' => true,
        '.gov.my' => true,
        '.gov.nr' => true,
        '.gov.pg' => true,
        '.gov.pl' => true,
        '.gov.pn' => true,
        '.gov.pt' => true,
        '.gov.sg' => true,
        '.gov.st' => true,
        '.gov.sx' => true,
        '.gov.tj' => true,
        '.govt.nz' => true,
        '.gov.tr' => true,
        '.gov.tw' => true,
        '.gov.ua' => true,
        '.gov.uk' => true,
        '.gov.vn' => true,
        '.gov.ws' => true,
        '.gp' => true,
        '.gq' => true,
        '.gr' => true,
        '.gr.jp' => true,
        '.grozny.ru' => true,
        '.grozny.su' => true,
        '.gs' => true,
        '.gs.cn' => true,
        '.g.se' => true,
        '.gt' => true,
        '.gu' => true,
        '.gv.at' => true,
        '.gw' => true,
        '.gx.cn' => true,
        '.gy' => true,
        '.gz.cn' => true,
        '.ha.cn' => true,
        '.hb.cn' => true,
        '.health.nz' => true,
        '.he.cn' => true,
        '.hi.cn' => true,
        '.hiroshima.jp' => true,
        '.hi.us' => true,
        '.hk' => true,
        '.hk.cn' => true,
        '.hl.cn' => true,
        '.hm' => true,
        '.hn' => true,
        '.hn.cn' => true,
        '.hokkaido.jp' => true,
        '.ho.ua' => true,
        '.hr' => true,
        '.h.se' => true,
        '.hs.kr' => true,
        '.ht' => true,
        '.hu' => true,
        '.ia.us' => true,
        '.id' => true,
        '.id.au' => true,
        '.idf.il' => true,
        '.id.ir' => true,
        '.id.us' => true,
        '.idv.tw' => true,
        '.ie' => true,
        '.if.ua' => true,
        '.iki.fi' => true,
        '.il' => true,
        '.il.us' => true,
        '.im' => true,
        '.imb.br' => true,
        '.in' => true,
        '.ind.br' => true,
        '.indie.se' => true,
        '.ind.in' => true,
        '.inf.br' => true,
        '.info' => true,
        '.info.at' => true,
        '.info.hu' => true,
        '.info.ke' => true,
        '.info.pl' => true,
        '.info.ro' => true,
        '.info.tr' => true,
        '.info.vn' => true,
        '.int' => true,
        '.int.ar' => true,
        '.int.az' => true,
        '.int.bo' => true,
        '.int.ci' => true,
        '.int.co' => true,
        '.int.is' => true,
        '.int.la' => true,
        '.int.lk' => true,
        '.int.mv' => true,
        '.int.mw' => true,
        '.int.ni' => true,
        '.int.pt' => true,
        '.int.rw' => true,
        '.int.tj' => true,
        '.int.tt' => true,
        '.int.ve' => true,
        '.int.vn' => true,
        '.in.ua' => true,
        '.in.us' => true,
        '.io' => true,
        '.io.ua' => true,
        '.iq' => true,
        '.ir' => true,
        '.is' => true,
        '.isa.us' => true,
        '.i.se' => true,
        '.it' => true,
        '.its.me' => true,
        '.i.ua' => true,
        '.ivanovo.su' => true,
        '.iwi.nz' => true,
        '.jambyl.su' => true,
        '.jaworzno.pl' => true,
        '.je' => true,
        '.jgora.pl' => true,
        '.jl.cn' => true,
        '.jm' => true,
        '.jo' => true,
        '.jobs' => true,
        '.jor.br' => true,
        '.jp' => true,
        '.js.cn' => true,
        '.jx.cn' => true,
        '.k12.il' => true,
        '.k12.tr' => true,
        '.kalisz.pl' => true,
        '.kalmykia.ru' => true,
        '.kalmykia.su' => true,
        '.kaluga.su' => true,
        '.karacol.su' => true,
        '.karaganda.su' => true,
        '.karelia.su' => true,
        '.karpacz.pl' => true,
        '.katowice.pl' => true,
        '.ke' => true,
        '.kg' => true,
        '.kg.kr' => true,
        '.kh' => true,
        '.khakassia.su' => true,
        '.kharkov.ua' => true,
        '.kh.ua' => true,
        '.ki' => true,
        '.kids.us' => true,
        '.kiev.ua' => true,
        '.kingly.se' => true,
        '.kiwi.nz' => true,
        '.km' => true,
        '.km.ua' => true,
        '.kn' => true,
        '.kolobrzeg.pl' => true,
        '.konin.pl' => true,
        '.kp' => true,
        '.kr' => true,
        '.krakow.pl' => true,
        '.krasnodar.su' => true,
        '.krd' => true,
        '.kr.ua' => true,
        '.k.se' => true,
        '.ks.ua' => true,
        '.ks.us' => true,
        '.kurgan.su' => true,
        '.kustanai.ru' => true,
        '.kustanai.su' => true,
        '.kw' => true,
        '.ky' => true,
        '.kyoto.jp' => true,
        '.ky.us' => true,
        '.kz' => true,
        '.la' => true,
        '.la.us' => true,
        '.lb' => true,
        '.lc' => true,
        '.lebork.pl' => true,
        '.legnica.pl' => true,
        '.lenug.su' => true,
        '.lg.jp' => true,
        '.lg.ua' => true,
        '.li' => true,
        '.lk' => true,
        '.ln.cn' => true,
        '.lnu.se' => true,
        '.lomza.pl' => true,
        '.lr' => true,
        '.ls' => true,
        '.l.se' => true,
        '.lt' => true,
        '.ltd.cy' => true,
        '.ltd.gi' => true,
        '.ltd.hk' => true,
        '.ltd.lk' => true,
        '.ltd.uk' => true,
        '.lu' => true,
        '.lubin.pl' => true,
        '.lukow.pl' => true,
        '.lv' => true,
        '.lviv.ua' => true,
        '.ly' => true,
        '.ma' => true,
        '.malbork.pl' => true,
        '.malopolska.pl' => true,
        '.mangyshlak.su' => true,
        '.maori.nz' => true,
        '.marine.ru' => true,
        '.ma.us' => true,
        '.mazowsze.pl' => true,
        '.mazury.pl' => true,
        '.mb.ca' => true,
        '.mc' => true,
        '.md' => true,
        '.md.us' => true,
        '.me' => true,
        '.med.br' => true,
        '.media.pl' => true,
        '.med.pl' => true,
        '.me.ke' => true,
        '.me.uk' => true,
        '.me.us' => true,
        '.mg' => true,
        '.mh' => true,
        '.mielec.pl' => true,
        '.mielno.pl' => true,
        '.mil' => true,
        '.mil.ac' => true,
        '.mil.ae' => true,
        '.mil.al' => true,
        '.mil.ar' => true,
        '.mil.az' => true,
        '.mil.ba' => true,
        '.mil.bo' => true,
        '.mil.by' => true,
        '.mil.cl' => true,
        '.mil.cn' => true,
        '.mil.co' => true,
        '.mil.eg' => true,
        '.mil.ge' => true,
        '.mil.gh' => true,
        '.mil.gt' => true,
        '.mil.hn' => true,
        '.mil.in' => true,
        '.mil.iq' => true,
        '.mil.jo' => true,
        '.mil.kg' => true,
        '.mil.km' => true,
        '.mil.kr' => true,
        '.mil.kz' => true,
        '.mil.mg' => true,
        '.mil.mv' => true,
        '.mil.my' => true,
        '.mil.ng' => true,
        '.mil.ni' => true,
        '.mil.no' => true,
        '.mil.nz' => true,
        '.mil.pe' => true,
        '.mil.qa' => true,
        '.mil.rw' => true,
        '.mil.st' => true,
        '.mil.sy' => true,
        '.mil.tj' => true,
        '.mil.tm' => true,
        '.mil.to' => true,
        '.mil.tr' => true,
        '.mil.tw' => true,
        '.mil.tz' => true,
        '.mil.uy' => true,
        '.mil.vc' => true,
        '.mil.za' => true,
        '.mil.zm' => true,
        '.mi.us' => true,
        '.mk' => true,
        '.mk.ua' => true,
        '.ml' => true,
        '.mm' => true,
        '.mn' => true,
        '.mn.us' => true,
        '.mo' => true,
        '.mobi' => true,
        '.mo.cn' => true,
        '.mordovia.ru' => true,
        '.mordovia.su' => true,
        '.mo.us' => true,
        '.mp' => true,
        '.mq' => true,
        '.mr' => true,
        '.ms' => true,
        '.m.se' => true,
        '.ms.kr' => true,
        '.msk.ru' => true,
        '.msk.su' => true,
        '.ms.us' => true,
        '.mt' => true,
        '.mt.us' => true,
        '.mu' => true,
        '.muni.il' => true,
        '.murmansk.su' => true,
        '.mus.br' => true,
        '.mv' => true,
        '.mw' => true,
        '.mx' => true,
        '.my' => true,
        '.mytis.ru' => true,
        '.mz' => true,
        '.na' => true,
        '.nalchik.ru' => true,
        '.nalchik.su' => true,
        '.name' => true,
        '.name.my' => true,
        '.name.tr' => true,
        '.name.vn' => true,
        '.navoi.su' => true,
        '.nb.ca' => true,
        '.nc' => true,
        '.nc.us' => true,
        '.nd.us' => true,
        '.ne' => true,
        '.ne.jp' => true,
        '.ne.ke' => true,
        '.ne.kr' => true,
        '.ne.pw' => true,
        '.net' => true,
        '.net.ac' => true,
        '.net.ae' => true,
        '.net.af' => true,
        '.net.ag' => true,
        '.net.ai' => true,
        '.net.ar' => true,
        '.net.au' => true,
        '.net.az' => true,
        '.net.ba' => true,
        '.net.bb' => true,
        '.net.bh' => true,
        '.net.bm' => true,
        '.net.br' => true,
        '.net.bs' => true,
        '.net.bt' => true,
        '.net.ci' => true,
        '.net.cm' => true,
        '.net.cn' => true,
        '.net.co' => true,
        '.net.cu' => true,
        '.net.cw' => true,
        '.net.cy' => true,
        '.net.dm' => true,
        '.net.dz' => true,
        '.net.et' => true,
        '.net.ge' => true,
        '.net.gg' => true,
        '.net.gl' => true,
        '.net.gn' => true,
        '.net.gp' => true,
        '.net.gr' => true,
        '.net.gy' => true,
        '.net.hn' => true,
        '.net.ht' => true,
        '.net.il' => true,
        '.net.im' => true,
        '.net.in' => true,
        '.net.iq' => true,
        '.net.ir' => true,
        '.net.is' => true,
        '.net.jo' => true,
        '.net.ki' => true,
        '.net.kn' => true,
        '.net.ky' => true,
        '.net.kz' => true,
        '.net.la' => true,
        '.net.lk' => true,
        '.net.lr' => true,
        '.net.lv' => true,
        '.net.me' => true,
        '.net.ml' => true,
        '.net.mm' => true,
        '.net.mo' => true,
        '.net.mu' => true,
        '.net.mw' => true,
        '.net.mx' => true,
        '.net.my' => true,
        '.net.nf' => true,
        '.net.nr' => true,
        '.net.nz' => true,
        '.net.om' => true,
        '.net.pa' => true,
        '.net.pg' => true,
        '.net.pl' => true,
        '.net.pn' => true,
        '.net.pr' => true,
        '.net.ps' => true,
        '.net.pt' => true,
        '.net.ru' => true,
        '.net.rw' => true,
        '.net.sb' => true,
        '.net.sc' => true,
        '.net.sd' => true,
        '.net.sg' => true,
        '.net.sl' => true,
        '.net.so' => true,
        '.net.st' => true,
        '.net.sy' => true,
        '.net.tj' => true,
        '.net.tm' => true,
        '.net.tn' => true,
        '.net.to' => true,
        '.net.tr' => true,
        '.net.tw' => true,
        '.net.ua' => true,
        '.net.uk' => true,
        '.net.uz' => true,
        '.net.vc' => true,
        '.net.vi' => true,
        '.net.vn' => true,
        '.net.vu' => true,
        '.net.ws' => true,
        '.net.zm' => true,
        '.ne.us' => true,
        '.nf' => true,
        '.nf.ca' => true,
        '.ng' => true,
        '.nhs.uk' => true,
        '.nh.us' => true,
        '.ni' => true,
        '.nic.in' => true,
        '.nieruchomosci.pl' => true,
        '.nj.us' => true,
        '.nl' => true,
        '.nl.ca' => true,
        '.nm.cn' => true,
        '.nm.us' => true,
        '.no' => true,
        '.nom.co' => true,
        '.nom.es' => true,
        '.north-kazakhstan.su' => true,
        '.nov.ru' => true,
        '.nov.su' => true,
        '.np' => true,
        '.nr' => true,
        '.ns.ca' => true,
        '.n.se' => true,
        '.nsn.us' => true,
        '.ns.se' => true,
        '.nsw.au' => true,
        '.nt.ca' => true,
        '.nt.ro' => true,
        '.nu' => true,
        '.nu.ca' => true,
        '.nv.us' => true,
        '.nx.cn' => true,
        '.nysa.pl' => true,
        '.ny.us' => true,
        '.nz' => true,
        '.obninsk.su' => true,
        '.odo.br' => true,
        '.od.ua' => true,
        '.oh.us' => true,
        '.okinawa.jp' => true,
        '.ok.us' => true,
        '.olkusz.pl' => true,
        '.olsztyn.pl' => true,
        '.om' => true,
        '.on.ca' => true,
        '.opole.pl' => true,
        '.or.at' => true,
        '.org' => true,
        '.org.ac' => true,
        '.org.ag' => true,
        '.org.ai' => true,
        '.org.ar' => true,
        '.org.au' => true,
        '.org.bi' => true,
        '.org.bm' => true,
        '.org.br' => true,
        '.org.bs' => true,
        '.org.ci' => true,
        '.org.ck' => true,
        '.org.cn' => true,
        '.org.co' => true,
        '.org.cu' => true,
        '.org.cw' => true,
        '.org.dm' => true,
        '.org.es' => true,
        '.org.et' => true,
        '.org.fj' => true,
        '.org.gi' => true,
        '.org.gl' => true,
        '.org.gn' => true,
        '.org.gp' => true,
        '.org.gr' => true,
        '.org.hn' => true,
        '.org.hu' => true,
        '.org.il' => true,
        '.org.in' => true,
        '.org.iq' => true,
        '.org.ir' => true,
        '.org.is' => true,
        '.org.km' => true,
        '.org.kn' => true,
        '.org.kp' => true,
        '.org.lr' => true,
        '.org.me' => true,
        '.org.mg' => true,
        '.org.ms' => true,
        '.org.mu' => true,
        '.org.mx' => true,
        '.org.my' => true,
        '.org.nr' => true,
        '.org.nz' => true,
        '.org.pf' => true,
        '.org.pg' => true,
        '.org.pl' => true,
        '.org.pn' => true,
        '.org.pr' => true,
        '.org.pt' => true,
        '.org.ro' => true,
        '.org.ru' => true,
        '.org.sc' => true,
        '.org.se' => true,
        '.org.sg' => true,
        '.org.sl' => true,
        '.org.so' => true,
        '.org.st' => true,
        '.org.sz' => true,
        '.org.tj' => true,
        '.org.tm' => true,
        '.org.to' => true,
        '.org.tr' => true,
        '.org.tw' => true,
        '.org.ua' => true,
        '.org.uk' => true,
        '.org.uz' => true,
        '.org.vc' => true,
        '.org.vi' => true,
        '.org.vn' => true,
        '.org.vu' => true,
        '.org.ws' => true,
        '.or.jp' => true,
        '.or.ke' => true,
        '.or.kr' => true,
        '.or.us' => true,
        '.osaka.jp' => true,
        '.o.se' => true,
        '.oslo.no' => true,
        '.ostroleka.pl' => true,
        '.oz.au' => true,
        '.pa' => true,
        '.parliament.nz' => true,
        '.pa.us' => true,
        '.pe' => true,
        '.pe.ca' => true,
        '.pe.kr' => true,
        '.penza.su' => true,
        '.per.sg' => true,
        '.pf' => true,
        '.pg' => true,
        '.ph' => true,
        '.pila.pl' => true,
        '.pisz.pl' => true,
        '.pk' => true,
        '.pl' => true,
        '.plc.uk' => true,
        '.pl.ua' => true,
        '.pm' => true,
        '.pn' => true,
        '.pokrovsk.su' => true,
        '.pol.tr' => true,
        '.pomorskie.pl' => true,
        '.pomorze.pl' => true,
        '.poznan.pl' => true,
        '.ppg.br' => true,
        '.pp.ru' => true,
        '.pp.se' => true,
        '.pp.ua' => true,
        '.pr' => true,
        '.press.se' => true,
        '.priv.at' => true,
        '.priv.no' => true,
        '.pro' => true,
        '.pro.br' => true,
        '.prom.ua' => true,
        '.pro.vn' => true,
        '.pr.us' => true,
        '.ps' => true,
        '.psc.br' => true,
        '.p.se' => true,
        '.pt' => true,
        '.pulawy.pl' => true,
        '.pw' => true,
        '.py' => true,
        '.pyatigorsk.ru' => true,
        '.qa' => true,
        '.qc.ca' => true,
        '.qh.cn' => true,
        '.qld.au' => true,
        '.radom.pl' => true,
        '.re' => true,
        '.re.kr' => true,
        '.res.in' => true,
        '.ri.us' => true,
        '.ro' => true,
        '.rs' => true,
        '.ru' => true,
        '.rv.ua' => true,
        '.rw' => true,
        '.rybnik.pl' => true,
        '.rzeszow.pl' => true,
        '.sa' => true,
        '.sa.au' => true,
        '.saitama.jp' => true,
        '.sanok.pl' => true,
        '.sb' => true,
        '.sc' => true,
        '.sc.cn' => true,
        '.sch.ir' => true,
        '.school.nz' => true,
        '.sc.ke' => true,
        '.sc.kr' => true,
        '.sc.us' => true,
        '.sd' => true,
        '.sd.cn' => true,
        '.sd.us' => true,
        '.se' => true,
        '.seoul.kr' => true,
        '.sg' => true,
        '.sh' => true,
        '.sh.cn' => true,
        '.shop.hu' => true,
        '.shop.pl' => true,
        '.si' => true,
        '.siedlce.pl' => true,
        '.sj' => true,
        '.sk' => true,
        '.sk.ca' => true,
        '.sklep.pl' => true,
        '.sl' => true,
        '.slask.pl' => true,
        '.slupsk.pl' => true,
        '.slu.se' => true,
        '.sm' => true,
        '.sn' => true,
        '.sn.cn' => true,
        '.so' => true,
        '.sochi.su' => true,
        '.sosnowiec.pl' => true,
        '.spb.ru' => true,
        '.spb.su' => true,
        '.sr' => true,
        '.srv.br' => true,
        '.s.se' => true,
        '.st' => true,
        '.stargard.pl' => true,
        '.store.ro' => true,
        '.su' => true,
        '.suwalki.pl' => true,
        '.sv' => true,
        '.swidnica.pl' => true,
        '.swinoujscie.pl' => true,
        '.sx' => true,
        '.sx.cn' => true,
        '.sy' => true,
        '.sz' => true,
        '.szczecin.pl' => true,
        '.szkola.pl' => true,
        '.tashkent.su' => true,
        '.tc' => true,
        '.td' => true,
        '.tel' => true,
        '.termez.su' => true,
        '.te.ua' => true,
        '.tf' => true,
        '.tg' => true,
        '.th' => true,
        '.tj' => true,
        '.tj.cn' => true,
        '.tk' => true,
        '.tl' => true,
        '.tm' => true,
        '.tmeeting.se' => true,
        '.tm.fr' => true,
        '.tm.ro' => true,
        '.tm.se' => true,
        '.tn' => true,
        '.tn.us' => true,
        '.to' => true,
        '.togliatti.su' => true,
        '.tokyo.jp' => true,
        '.tp' => true,
        '.tr' => true,
        '.travel' => true,
        '.travel.pl' => true,
        '.troitsk.su' => true,
        '.t.se' => true,
        '.tselinograd.su' => true,
        '.tt' => true,
        '.tula.su' => true,
        '.tur.ar' => true,
        '.tur.br' => true,
        '.turystyka.pl' => true,
        '.tuva.su' => true,
        '.tv' => true,
        '.tv.br' => true,
        '.tv.tr' => true,
        '.tw' => true,
        '.tw.cn' => true,
        '.tx.us' => true,
        '.tychy.pl' => true,
        '.tz' => true,
        '.ua' => true,
        '.ug' => true,
        '.uk' => true,
        '.us' => true,
        '.u.se' => true,
        '.ustka.pl' => true,
        '.ut.us' => true,
        '.uy' => true,
        '.uz' => true,
        '.uz.ua' => true,
        '.va' => true,
        '.va.us' => true,
        '.vc' => true,
        '.ve' => true,
        '.vet.br' => true,
        '.vg' => true,
        '.vgs.no' => true,
        '.vi' => true,
        '.vic.au' => true,
        '.vladikavkaz.ru' => true,
        '.vladikavkaz.su' => true,
        '.vladimir.ru' => true,
        '.vladimir.su' => true,
        '.vn' => true,
        '.vn.ua' => true,
        '.vologda.su' => true,
        '.vt.us' => true,
        '.vu' => true,
        '.walbrzych.pl' => true,
        '.warszawa.pl' => true,
        '.wa.us' => true,
        '.waw.pl' => true,
        '.web.tr' => true,
        '.wf' => true,
        '.wi.us' => true,
        '.wloclawek.pl' => true,
        '.wroclaw.pl' => true,
        '.wroc.pl' => true,
        '.ws' => true,
        '.w.se' => true,
        '.wv.us' => true,
        '.wy.us' => true,
        '.xj.cn' => true,
        '.xn--p1ai' => true,
        '.x.se' => true,
        '.xxx' => true,
        '.xz.cn' => true,
        '.ye' => true,
        '.yk.ca' => true,
        '.yn.cn' => true,
        '.y.se' => true,
        '.yt' => true,
        '.yu' => true,
        '.za' => true,
        '.zgora.pl' => true,
        '.zj.cn' => true,
        '.zm' => true,
        '.zp.ua' => true,
        '.zr' => true,
        '.z.se' => true,
        '.zt.ua' => true,
        '.zw' => true,
        '.рф' => true,
    ];

    protected static $utmTags = [
        'UTM_',
        '_NC',
        'AFF_',
        'ONELINK_',
        'APPSEARCH_',
        '_SHORTURL',
        'REF_',
    ];
}