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

function ts_xtream_first_csv_value($value)
{
    $value = trim((string) $value);
    if ($value === '') return '';
    if (strpos($value, ',') === false) return $value;
    return trim(explode(',', $value, 2)[0]);
}

function ts_xtream_auth_ok($username, $password)
{
    $cfg = $GLOBALS['config'] ?? [];
    $expected_user = trim((string)($cfg['xtream']['username'] ?? ''));
    $expected_pass = trim((string)($cfg['xtream']['password'] ?? ''));

    if ($expected_user === '' && $expected_pass === '') {
        return true;
    }

    return hash_equals($expected_user, (string) $username) && hash_equals($expected_pass, (string) $password);
}

// Generate a unique filename for the M3U playlist
$jio_fname = 'TS-JioTV_' . md5(time() . 'JioTV') . '.m3u';

$forwarded_proto = ts_xtream_first_csv_value($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
$protocol = ($forwarded_proto === 'https' || $forwarded_proto === 'http')
    ? ($forwarded_proto . '://')
    : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://');

$forwarded_host = ts_xtream_first_csv_value($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
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
$jio_base = rtrim($jio_path, '/') . '/';
$host_only = $host_jio;
$port = '';
if (strpos($host_jio, ':') !== false) {
    [$host_only, $port] = array_pad(explode(':', $host_jio, 2), 2, '');
}
if ($port === '') {
    $port = ($protocol === 'https://') ? '443' : '80';
}

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

$api = $_GET['api'] ?? '';
if ($api === 'raw') {
    $raw_username = $_GET['username'] ?? '';
    $raw_password = $_GET['password'] ?? '';
    if (!ts_xtream_auth_ok($raw_username, $raw_password)) {
        http_response_code(401);
        exit('Unauthorized');
    }

    $stream_id = (string)($_GET['id'] ?? ($_GET['stream_id'] ?? ''));
    if ($stream_id === '') {
        http_response_code(400);
        exit('Missing id');
    }

    if (ts_starts_with($stream_id, 'ext_')) {
        $selected = null;
        foreach ($external_channels as $ch) {
            if ((string)($ch['channel_id'] ?? '') === $stream_id) {
                $selected = $ch;
                break;
            }
        }
        $direct = (string)($selected['streamUrl'] ?? '');
        if ($direct !== '' && ts_starts_with($direct, 'https://')) {
            header('Location: ' . $direct, true, 302);
            exit;
        }
        http_response_code(404);
        exit('Not found');
    }

    $haystack = getJioTvData($stream_id);
    if (empty($haystack->code) || (int)$haystack->code !== 200) {
        refresh_token();
        $haystack = getJioTvData($stream_id);
    }

    if (empty($haystack->code) || (int)$haystack->code !== 200) {
        http_response_code(502);
        exit('Upstream error');
    }

    $target = (string)($haystack->result ?? '');
    if (!ts_starts_with($target, 'http://') && !ts_starts_with($target, 'https://')) {
        http_response_code(502);
        exit('Invalid upstream url');
    }

    header('Location: ' . $target, true, 302);
    exit;
}

$xtream_username = $_GET['username'] ?? '';
$xtream_password = $_GET['password'] ?? '';
$xtream_auth = ts_xtream_auth_ok($xtream_username, $xtream_password);

if ($api === 'xtream_xmltv') {
    header('Location: https://avkb.short.gy/jioepg.xml.gz', true, 302);
    exit;
}

if ($api === 'xtream_live') {
    if (!$xtream_auth) {
        http_response_code(401);
        exit('Unauthorized');
    }

    $stream_id = (string)($_GET['stream_id'] ?? '');

    if ($stream_id !== '' && ts_starts_with($stream_id, 'ext_')) {
        $selected = null;
        foreach ($external_channels as $ch) {
            if ((string)($ch['channel_id'] ?? '') === $stream_id) {
                $selected = $ch;
                break;
            }
        }
        $direct = (string)($selected['streamUrl'] ?? '');
        if ($direct !== '' && ts_starts_with($direct, 'https://')) {
            header('Location: ' . $direct, true, 302);
            exit;
        }
        http_response_code(404);
        exit('Not found');
    }

    $raw = (string)($_GET['raw'] ?? '');
    if ($raw === '1' || $raw === 'true') {
        $haystack = getJioTvData($stream_id);
        if (empty($haystack->code) || (int)$haystack->code !== 200) {
            refresh_token();
            $haystack = getJioTvData($stream_id);
        }
        if (!empty($haystack->code) && (int)$haystack->code === 200 && !empty($haystack->result)) {
            header('Location: ' . (string)$haystack->result, true, 302);
            exit;
        }
        http_response_code(502);
        exit('Upstream error');
    }

    $target = isApache()
        ? ($jio_base . 'ts_live_' . urlencode($stream_id) . '.m3u8')
        : ($jio_base . 'live.php?id=' . urlencode($stream_id) . '&e=.m3u8');
    header('Location: ' . $target, true, 302);
    exit;
}

if ($api === 'xtream' || $api === 'xtream_get') {
    $server_info = [
        'url' => $host_only,
        'port' => (string) $port,
        'https_port' => '443',
        'server_protocol' => rtrim($protocol, '://'),
        'rtmp_port' => '0',
        'timestamp_now' => time(),
        'time_now' => gmdate('Y-m-d H:i:s'),
    ];

    $auth_payload = [
        'user_info' => [
            'username' => (string) $xtream_username,
            'password' => (string) $xtream_password,
            'message' => $xtream_auth ? '' : 'Invalid credentials',
            'auth' => $xtream_auth ? 1 : 0,
            'status' => $xtream_auth ? 'Active' : 'Disabled',
            'exp_date' => null,
            'is_trial' => 0,
            'active_cons' => 0,
            'created_at' => time(),
            'max_connections' => 1,
            'allowed_output_formats' => ['m3u8', 'ts'],
        ],
        'server_info' => $server_info,
    ];

    if (!$xtream_auth) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($auth_payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($api === 'xtream_get') {
        $type = $_GET['type'] ?? '';
        if ($type === '' || $type === 'm3u' || $type === 'm3u_plus') {
            $jio_channels = json_decode($json_data, true) ?? [];
            if (!is_array($jio_channels)) $jio_channels = [];

            header("Content-Type: application/vnd.apple.mpegurl");
            header("Content-Disposition: inline; filename=$jio_fname");

            $xtream_base = $jio_base;
            $playlist = '#EXTM3U x-tvg-url="https://avkb.short.gy/jioepg.xml.gz"' . PHP_EOL;

            foreach ($jio_channels as $channel) {
                $channel_id = (string)($channel['channel_id'] ?? '');
                $channel_name = (string)($channel['channel_name'] ?? '');
                if ($channel_id === '' || $channel_name === '') continue;

                $playlist .= sprintf(
                    '#EXTINF:-1 tvg-id="%s" tvg-name="%s" group-title="%s" tvg-logo="%s"%s,%s',
                    htmlspecialchars($channel_id, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($channel_name, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string)($channel['channelCategoryId'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string)($channel['logoUrl'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    ($channel['isCatchupAvailable'] ?? '') === "True" ? ' catchup-days="7" catchup="auto"' : '',
                    htmlspecialchars($channel_name, ENT_QUOTES, 'UTF-8')
                ) . PHP_EOL;

                $playlist .= $xtream_base . 'live/' . rawurlencode((string) $xtream_username) . '/' . rawurlencode((string) $xtream_password) . '/' . rawurlencode($channel_id) . '.m3u8' . PHP_EOL . PHP_EOL;
            }

            if (!empty($external_channels)) {
                foreach ($external_channels as $channel) {
                    $channel_id = (string)($channel['channel_id'] ?? '');
                    $channel_name = (string)($channel['channel_name'] ?? '');
                    $stream_url = (string)($channel['streamUrl'] ?? '');
                    if ($channel_id === '' || $channel_name === '' || !ts_starts_with($stream_url, 'https://')) continue;

                    $playlist .= sprintf(
                        '#EXTINF:-1 tvg-id="%s" tvg-name="%s" group-title="%s" tvg-logo="%s",%s',
                        htmlspecialchars($channel_id, ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($channel_name, ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars((string)($channel['channelCategoryId'] ?? 'EXTRA'), ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars((string)($channel['logoUrl'] ?? ''), ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($channel_name, ENT_QUOTES, 'UTF-8')
                    ) . PHP_EOL;

                    $playlist .= $xtream_base . 'live/' . rawurlencode((string) $xtream_username) . '/' . rawurlencode((string) $xtream_password) . '/' . rawurlencode($channel_id) . '.m3u8' . PHP_EOL . PHP_EOL;
                }
            }

            echo $playlist;
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($auth_payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    $action = $_GET['action'] ?? '';
    if ($action === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($auth_payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    $jio_channels = json_decode($json_data, true) ?? [];
    if (!is_array($jio_channels)) $jio_channels = [];
    $merged_channels = array_values(array_merge($jio_channels, is_array($external_channels) ? $external_channels : []));

    if ($action === 'get_live_categories') {
        $cat_map = [];
        foreach ($merged_channels as $ch) {
            $cat = trim((string)($ch['channelCategoryId'] ?? ''));
            if ($cat === '') $cat = 'Uncategorized';
            $cat_map[$cat] = true;
        }

        $out = [];
        $i = 1;
        foreach (array_keys($cat_map) as $cat_name) {
            $out[] = [
                'category_id' => (string) $i,
                'category_name' => (string) $cat_name,
                'parent_id' => 0,
            ];
            $i++;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'get_live_streams') {
        $cat_ids = [];
        $i = 1;
        foreach ($merged_channels as $ch) {
            $cat = trim((string)($ch['channelCategoryId'] ?? ''));
            if ($cat === '') $cat = 'Uncategorized';
            if (!isset($cat_ids[$cat])) {
                $cat_ids[$cat] = (string) $i;
                $i++;
            }
        }

        $out = [];
        $num = 1;
        foreach ($merged_channels as $ch) {
            $channel_id = (string)($ch['channel_id'] ?? '');
            $name = (string)($ch['channel_name'] ?? '');
            if ($channel_id === '' || $name === '') continue;

            $cat = trim((string)($ch['channelCategoryId'] ?? ''));
            if ($cat === '') $cat = 'Uncategorized';

            $is_catchup = ((string)($ch['isCatchupAvailable'] ?? 'False')) === 'True';

            $out[] = [
                'num' => $num,
                'name' => $name,
                'stream_type' => 'live',
                'stream_id' => $channel_id,
                'stream_icon' => (string)($ch['logoUrl'] ?? ''),
                'epg_channel_id' => $channel_id,
                'category_id' => $cat_ids[$cat] ?? '0',
                'added' => (string) time(),
                'custom_sid' => '',
                'tv_archive' => $is_catchup ? 1 : 0,
                'tv_archive_duration' => $is_catchup ? 7 : 0,
                'direct_source' => ts_starts_with($channel_id, 'ext_')
                    ? (string)($ch['streamUrl'] ?? '')
                    : ($jio_base . 'live/' . rawurlencode((string) $xtream_username) . '/' . rawurlencode((string) $xtream_password) . '/' . rawurlencode($channel_id) . '.m3u8'),
            ];
            $num++;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'get_vod_categories' || $action === 'get_series_categories' || $action === 'get_vod_streams' || $action === 'get_series' || $action === 'get_series_info') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($auth_payload, JSON_UNESCAPED_SLASHES);
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
