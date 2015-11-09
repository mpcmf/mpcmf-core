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
                $payloadStr = http_build_query($payload);
                if(strlen($payloadStr) > 0) {
                    $result['url'] .= "?{$payloadStr}";
                }
                break;
            default:
                $result['method'] = 'POST';
                $payloadStr = http_build_query($payload);
                if(strlen($payloadStr) > 0) {
                    $result['payload'] = $payloadStr;
                }
                break;
        }
        return $result;
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
     *
     * @return string
     */
    public static function removeSession($url = '')
    {
        $parsed = parse_url($url);
        if(!isset($parsed['query'])) {
            return $url;
        }
        parse_str($parsed['query'], $query);
        foreach ($query as $paramName => $value) {
            $strUppedParam = strtoupper($paramName);
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
                    unset($query[$paramName]);
                    break;
                default:
                    break;
            }
        }
        $queryString = http_build_query($query);
        if (empty($queryString)) {
            unset($parsed['query']);
        } else {
            $parsed['query'] = $queryString;
        }
        if(function_exists('http_build_url')) {
            $url = http_build_url($url, [
                'query' => $query
            ], HTTP_URL_JOIN_QUERY);
        } else {
            $url = self::unParseUrl($parsed);
        }

        return $url;
    }
}