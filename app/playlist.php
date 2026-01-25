<?php

// Copyright 2021-2025 SnehTV, Inc.
// Licensed under MIT (https://github.com/mitthu786/TS-JioTV/blob/main/LICENSE)
// Created By: TechieSneh

error_reporting(0);
include "functions.php";

function ts_starts_with($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    if ($needle === '') return true;
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function ts_fetch_url($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: TS-JioTV", "Accept-Encoding: gzip, deflate"]);

    if (ts_starts_with($url, 'https://')) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function parse_m3u_https_channels($m3u_content)
{
    $lines = preg_split("/\r\n|\n|\r/", (string) $m3u_content);
    $channels = [];

    $pending = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (ts_starts_with($line, '#EXTINF:')) {
            $pending = [
                'extinf' => $line,
            ];
            continue;
        }

        if ($line[0] === '#') {
            continue;
        }

        if ($pending === null) {
            continue;
        }

        $stream_url = $line;
        $pending_extinf = $pending['extinf'];
        $pending = null;

        if (!ts_starts_with($stream_url, 'https://')) {
            continue;
        }

        $name = '';
        $comma_pos = strrpos($pending_extinf, ',');
        if ($comma_pos !== false) {
            $name = trim(substr($pending_extinf, $comma_pos + 1));
        }

        $attrs = [];
        if (preg_match_all('/([A-Za-z0-9_-]+)="([^"]*)"/', $pending_extinf, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs[strtolower($m[1])] = $m[2];
            }
        }

        $tvg_name = $attrs['tvg-name'] ?? '';
        $tvg_logo = $attrs['tvg-logo'] ?? '';
        $group_title = $attrs['group-title'] ?? '';
        $lang = $attrs['tvg-language'] ?? '';

        $final_name = $tvg_name !== '' ? $tvg_name : $name;
        if ($final_name === '') {
            continue;
        }

        $ext_id = 'ext_' . substr(sha1($stream_url), 0, 16);

        $channels[] = [
            'channel_id' => $ext_id,
            'channel_name' => $final_name,
            'channelCategoryId' => $group_title !== '' ? $group_title : 'HTTPS',
            'channelLanguageId' => $lang !== '' ? $lang : 'Unknown',
            'isCatchupAvailable' => 'False',
            'isHD' => 'False',
            'logoUrl' => $tvg_logo !== '' ? $tvg_logo : 'https://ik.imagekit.io/techiesneh/tv_logo/jtv-plus_TMaGGk6N0.png',
            'sourceType' => 'ext',
            'streamUrl' => $stream_url,
        ];
    }

    return $channels;
}

// Generate a unique filename for the M3U playlist
$jio_fname = 'TS-JioTV_' . md5(time() . 'JioTV') . '.m3u';

$forwarded_proto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
if ($forwarded_proto !== '' && strpos($forwarded_proto, ',') !== false) {
    $forwarded_proto = trim(explode(',', $forwarded_proto, 2)[0]);
}
$protocol = ($forwarded_proto === 'https' || $forwarded_proto === 'http')
    ? ($forwarded_proto . '://')
    : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://');

$forwarded_host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
if ($forwarded_host !== '' && strpos($forwarded_host, ',') !== false) {
    $forwarded_host = trim(explode(',', $forwarded_host, 2)[0]);
}
$host_jio = $forwarded_host !== '' ? $forwarded_host : trim((string)($_SERVER['HTTP_HOST'] ?? ''));

if ($host_jio === '') {
    $host_jio = getHostByName(php_uname('n'));
}

if (strpos($host_jio, ':') === false) {
    $server_port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    if ($server_port !== 0 && $server_port !== 80 && $server_port !== 443) {
        $host_jio .= ':' . $server_port;
    }
}

$jio_path = $protocol . $host_jio . str_replace(" ", "%20", str_replace(basename($_SERVER['PHP_SELF']), '', $_SERVER['PHP_SELF']));

// Decode the URL and fetch the JSON data
$url = "==gbvNnauEGdhR2bpp2L2R3bpp2LnBXZ2R3LvlmLiVHa0l2ZuYDO3UHa0RXat9yL6MHc0RHa";
$remote_url = base64_decode(strrev($url));
$cache_file = __DIR__ . '/assets/data/jiodata.json';
$cache_ttl_seconds = 21600;

$json_data = null;
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl_seconds) {
    $json_data = file_get_contents($cache_file);
}

if (empty($json_data)) {
    $fresh_data = ts_fetch_url($remote_url);

    if (!empty($fresh_data) && json_decode($fresh_data, true) !== null) {
        $json_data = $fresh_data;
        @file_put_contents($cache_file, $fresh_data, LOCK_EX);
    } elseif (file_exists($cache_file)) {
        $json_data = file_get_contents($cache_file);
    }
}

$external_m3u_url = 'https://atanuroy22.github.io/iptv/output/all.m3u';
$external_cache_file = __DIR__ . '/assets/data/external_channels.json';
$external_channels = [];

if (file_exists($external_cache_file) && (time() - filemtime($external_cache_file)) < $cache_ttl_seconds) {
    $external_cached = file_get_contents($external_cache_file);
    $external_channels = json_decode($external_cached, true) ?? [];
}

if (empty($external_channels)) {
    $m3u = ts_fetch_url($external_m3u_url);
    if (!empty($m3u)) {
        $parsed = parse_m3u_https_channels($m3u);
        if (!empty($parsed)) {
            $external_channels = $parsed;
            @file_put_contents($external_cache_file, json_encode($external_channels, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    }
}

$debug = $_GET['debug'] ?? '';
if ($debug === 'ext') {
    header('Content-Type: application/json; charset=utf-8');
    $sample = '';
    if (isset($m3u) && !empty($m3u)) {
        $sample = substr($m3u, 0, 400);
    }
    echo json_encode([
        'external_url' => $external_m3u_url,
        'cache_file' => $external_cache_file,
        'cache_file_exists' => file_exists($external_cache_file),
        'cache_file_bytes' => file_exists($external_cache_file) ? filesize($external_cache_file) : 0,
        'fetched_bytes' => isset($m3u) ? strlen((string) $m3u) : 0,
        'parsed_count' => is_array($external_channels) ? count($external_channels) : 0,
        'm3u_sample' => $sample,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$format = $_GET['format'] ?? '';
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $jio_channels = json_decode($json_data, true) ?? [];
    if (!is_array($jio_channels)) $jio_channels = [];

    $merged = array_values(array_merge($jio_channels, is_array($external_channels) ? $external_channels : []));
    echo json_encode($merged, JSON_UNESCAPED_SLASHES);
    exit;
}

// Set HTTP headers
header("Content-Type: application/vnd.apple.mpegurl");
header("Content-Disposition: inline; filename=$jio_fname");

$json = json_decode($json_data, true);

// Start generating the M3U data
$jio_data = '#EXTM3U x-tvg-url="https://avkb.short.gy/jioepg.xml.gz"' . PHP_EOL;

// Add the JioTV channels to the M3U data
if ($json !== null) {
    foreach ($json as $channel) {
        $channel_id = htmlspecialchars($channel['channel_id'], ENT_QUOTES, 'UTF-8');
        $channel_name = htmlspecialchars($channel['channel_name'], ENT_QUOTES, 'UTF-8');
        $channel_category = htmlspecialchars($channel['channelCategoryId'], ENT_QUOTES, 'UTF-8');
        $channel_language = htmlspecialchars($channel['channelLanguageId'], ENT_QUOTES, 'UTF-8');
        $logo_url = htmlspecialchars($channel['logoUrl'], ENT_QUOTES, 'UTF-8');

        if (isApache()) {
            $catchup_source = htmlspecialchars($jio_path . 'catchup/ts_catchup_' . urlencode($channel['channel_id']) . '_${catchup-id}_${start}_${stop}.m3u8', ENT_QUOTES, 'UTF-8');
            $stream_url = $jio_path . 'ts_live_' . urlencode($channel['channel_id']) . '.m3u8';
        } else {
            $catchup_source = $jio_path . 'catchup/cpapi.php?id=' . urlencode($channel['channel_id']) . '&srno=${catchup-id}&begin={start}&end=${stop}&e=.m3u8';
            $stream_url = $jio_path . 'live.php?id=' . urlencode($channel['channel_id']) . '&e=.m3u8';
        }

        $jio_data .= sprintf(
            '#EXTINF:-1 tvg-id="%s" tvg-name="%s" tvg-type="%s" group-title="TS-JioTV %s" tvg-language="%s" tvg-logo="%s"%s,%s',
            $channel_id,
            $channel_name,
            $channel_category,
            $channel_category,
            $channel_language,
            $logo_url,
            $channel['isCatchupAvailable'] == "True" ? ' catchup-days="7" catchup="auto" catchup-source="' . $catchup_source . '"' : '',
            $channel_name
        ) . PHP_EOL;

        $jio_data .= $stream_url . PHP_EOL . PHP_EOL;
    }
}

if (!empty($external_channels)) {
    foreach ($external_channels as $channel) {
        $channel_id = htmlspecialchars($channel['channel_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $channel_name = htmlspecialchars($channel['channel_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $channel_category = htmlspecialchars($channel['channelCategoryId'] ?? 'HTTPS', ENT_QUOTES, 'UTF-8');
        $channel_language = htmlspecialchars($channel['channelLanguageId'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        $logo_url = htmlspecialchars($channel['logoUrl'] ?? '', ENT_QUOTES, 'UTF-8');
        $stream_url = $channel['streamUrl'] ?? '';

        if ($channel_id === '' || $channel_name === '' || !ts_starts_with((string) $stream_url, 'https://')) {
            continue;
        }

        $jio_data .= sprintf(
            '#EXTINF:-1 tvg-id="%s" tvg-name="%s" tvg-type="%s" group-title="EXTRA %s" tvg-language="%s" tvg-logo="%s",%s',
            $channel_id,
            $channel_name,
            $channel_category,
            $channel_category,
            $channel_language,
            $logo_url,
            $channel_name
        ) . PHP_EOL;
        $jio_data .= $stream_url . PHP_EOL . PHP_EOL;
    }
}

// Print the M3U data
echo $jio_data;
