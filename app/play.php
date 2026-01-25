<?php

// Copyright 2021-2025 SnehTV, Inc.
// Licensed under MIT (https://github.com/mitthu786/TS-JioTV/blob/main/LICENSE)
// Created By : TechieSneh

error_reporting(0);
include "functions.php";

$live_url_lower = '';
$ext_id = $_GET['ext'] ?? '';

$cid = '';
$id = '';
$name = '';
$live_url = '';
$player_type = 'hls';
$image_url = '';

function ts_ext_starts_with($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    if ($needle === '') return true;
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function ts_ext_fetch_url($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: TS-JioTV", "Accept-Encoding: gzip, deflate"]);

    if (ts_ext_starts_with($url, 'https://')) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function ts_ext_parse_m3u_https_channels($m3u_content)
{
    $lines = preg_split("/\r\n|\n|\r/", (string) $m3u_content);
    $channels = [];

    $pending_extinf = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (ts_ext_starts_with($line, '#EXTINF:')) {
            $pending_extinf = $line;
            continue;
        }

        if ($line[0] === '#') {
            continue;
        }

        if ($pending_extinf === '') {
            continue;
        }

        $stream_url = $line;
        $extinf = $pending_extinf;
        $pending_extinf = '';

        if (!ts_ext_starts_with($stream_url, 'https://')) {
            continue;
        }

        $name = '';
        $comma_pos = strrpos($extinf, ',');
        if ($comma_pos !== false) {
            $name = trim(substr($extinf, $comma_pos + 1));
        }

        $attrs = [];
        if (preg_match_all('/([A-Za-z0-9_-]+)="([^"]*)"/', $extinf, $matches, PREG_SET_ORDER)) {
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

        $ext_channel_id = 'ext_' . substr(sha1($stream_url), 0, 16);

        $channels[] = [
            'channel_id' => $ext_channel_id,
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

if (!empty($ext_id)) {
    $cache_file = __DIR__ . '/assets/data/external_channels.json';
    $channels = [];
    if (file_exists($cache_file)) {
        $channels = json_decode(file_get_contents($cache_file), true) ?? [];
    }

    $selected = null;
    $external_m3u_url = 'https://atanuroy22.github.io/iptv/output/all.m3u';

    $lookup = function ($list) use ($ext_id) {
        if (!is_array($list)) return null;
        foreach ($list as $ch) {
            $ch_id = (string) ($ch['channel_id'] ?? ($ch['id'] ?? ''));
            if ($ch_id !== '' && $ch_id === (string) $ext_id) {
                return $ch;
            }
        }
        return null;
    };

    $selected = $lookup($channels);

    if (!$selected) {
        $m3u = ts_ext_fetch_url($external_m3u_url);
        if (!empty($m3u)) {
            $parsed = ts_ext_parse_m3u_https_channels($m3u);
            if (!empty($parsed)) {
                $channels = $parsed;
                @file_put_contents($cache_file, json_encode($channels, JSON_UNESCAPED_SLASHES), LOCK_EX);
                $selected = $lookup($channels);
            }
        }
    }

    if ($selected) {
        $name = (string) ($selected['channel_name'] ?? 'Live');
        $live_url = (string) ($selected['streamUrl'] ?? '');
        $image_url = (string) ($selected['logoUrl'] ?? '');

        $live_url_lower = strtolower($live_url);
        if (substr($live_url_lower, -5) === '.m3u8') {
            $player_type = 'hls';
        } elseif (substr($live_url_lower, -4) === '.mp4' || strpos($live_url_lower, '.mp4?') !== false) {
            $player_type = 'mp4';
        } else {
            $player_type = 'hls';
        }
    } else {
        http_response_code(404);
        exit('Channel not found');
    }
} else {
$data = null;
$hex = '';

// Determine data source
if (isset($_GET['data'])) {
    $hex = $_GET['data'];
} else {
    $parts = explode('_', $_SERVER['REQUEST_URI']);
    if (isset($parts[1])) {
        $hex = $parts[1];
    }
}

// Decode and parse data
if (!empty($hex)) {
    $decoded = hex2bin($hex);
    $data = explode('=?=', $decoded);
}

$cid = $data[0] ?? '';
$id = $data[1] ?? '';
$name = str_replace('_', ' ', $cid);

// Set live URL
$live_url = (isApache())
    ? "ts_live_$id.m3u8"
    : "live.php?id=$id&e=.m3u8";
    $player_type = 'hls';
    $image_url = 'https://jiotv.catchup.cdn.jio.com/dare_images/images/' . $cid . '.png';
}

$safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safe_image_url = htmlspecialchars($image_url, ENT_QUOTES, 'UTF-8');
$safe_live_url = htmlspecialchars($live_url, ENT_QUOTES, 'UTF-8');

?>

<html lang="en">

<head>
    <title><?= $safe_name; ?> | JioTV +</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="description" content="ENJOY LIVE TV">
    <meta name="keywords" content="LIVETV, SPORTS, MOVIES, MUSIC">
    <meta name="author" content="TSNEH">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="stylesheet" href="https://cdn.plyr.io/3.6.2/plyr.css" />
    <link rel="stylesheet" href="assets/css/player.css" />
    <script src="https://cdn.plyr.io/3.6.3/plyr.js"></script>
    <link rel="shortcut icon" type="image/x-icon" href="https://i.ibb.co/BcjC6R8/jiotv.png">
    <script type="text/javascript" src="https://content.jwplatform.com/libraries/IDzF9Zmk.js"></script>

</head>

<body>
    <style>
        html {
            background: #000;
            margin: 0;
            padding: 0;
        }

        #myElement {
            position: relative;
            width: 100% !important;
            height: 100% !important;
        }

        @keyframes blur-text {
            0% {
                filter: blur(0px);
            }

            100% {
                filter: blur(4px);
            }
        }
    </style>
    <video id="myElement"></video>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', () => {
            const playerConfig = {
                title: '<?= $safe_name ?>',
                description: "SnehTV",
                image: '<?= $safe_image_url ?>',
                skin: {
                    name: "netflix"
                },
                aspectratio: '16:9',
                width: '100%',
                mute: false,
                autostart: true,
                file: "<?= $safe_live_url ?>",
                type: "<?= $player_type ?>",
                captions: {
                    color: '#fff',
                    fontSize: 16,
                    backgroundOpacity: 0
                }
            };
            const player = jwplayer("myElement").setup(playerConfig);
        });
    </script>
</body>

</html>
