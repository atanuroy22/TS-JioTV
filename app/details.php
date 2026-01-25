<?php
// * Copyright 2021-2025 SnehTV, Inc.
// * Licensed under MIT (https://github.com/mitthu786/TS-JioTV/blob/main/LICENSE)
// * Created By : TechieSneh

error_reporting(0);
include "functions.php";

$ext_id = $_GET['ext'] ?? '';
$is_external = !empty($ext_id);

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

$data = null;
if (isset($_GET['data'])) {
  $hex = $_GET['data'];
} else {
  $parts = explode('_', $_SERVER['REQUEST_URI']);
  if (isset($parts[1])) {
    $hex = $parts[1];
  } else {
    $hex = '';
  }
}

if (isApache()) {
  $url_host = "play_";
  $cp_url_host = "catchup/cp_";
} else {
  $url_host = "play.php?data=";
  $cp_url_host = "catchup/cp.php?data=";
}

if (!empty($hex)) {
  $decoded = hex2bin($hex);
  $data = explode('=?=', $decoded);
}

$cid = $data[0] ?? '';
$id = $data[1] ?? '';
$name = strtoupper(str_replace('_', ' ', $cid));
$image = 'https://jiotv.catchup.cdn.jio.com/dare_images/images/' . $cid . '.png';
$c = $data[2] ?? false;

$file_path = 'assets/data/credskey.jtv';
$file_exists = file_exists($file_path);

$watch_href = $url_host . bin2hex($cid . '=?=' . $id);

if ($is_external) {
  $external_cache_file = __DIR__ . '/assets/data/external_channels.json';
  $channels = [];
  if (file_exists($external_cache_file)) {
    $channels = json_decode(file_get_contents($external_cache_file), true) ?? [];
  }

  $selected = null;
  if (is_array($channels)) {
    foreach ($channels as $ch) {
      if (($ch['channel_id'] ?? '') === $ext_id) {
        $selected = $ch;
        break;
      }
    }
  }

  if (!$selected) {
    $external_m3u_url = 'https://atanuroy22.github.io/iptv/output/all.m3u';
    $m3u = ts_ext_fetch_url($external_m3u_url);
    if (!empty($m3u)) {
      $parsed = ts_ext_parse_m3u_https_channels($m3u);
      if (!empty($parsed)) {
        $channels = $parsed;
        @file_put_contents($external_cache_file, json_encode($channels, JSON_UNESCAPED_SLASHES), LOCK_EX);
        foreach ($channels as $ch) {
          if (($ch['channel_id'] ?? '') === $ext_id) {
            $selected = $ch;
            break;
          }
        }
      }
    }
  }

  if (!$selected) {
    http_response_code(404);
    exit('Channel not found');
  }

  $cid = (string) ($selected['channel_id'] ?? $ext_id);
  $id = $cid;
  $name = strtoupper((string) ($selected['channel_name'] ?? 'Live'));
  $image = (string) ($selected['logoUrl'] ?? 'https://ik.imagekit.io/techiesneh/tv_logo/jtv-plus_TMaGGk6N0.png');
  $c = false;
  $watch_href = 'play.php?ext=' . urlencode($cid);
  $file_exists = true;
}

$safe_name = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
$safe_image = htmlspecialchars((string) $image, ENT_QUOTES, 'UTF-8');
$safe_watch_href = htmlspecialchars((string) $watch_href, ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $safe_name ?> | JioTV+ </title>
  <link rel="icon" href="https://ik.imagekit.io/techiesneh/tv_logo/jtv-plus_TMaGGk6N0.png" type="image/png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <script src="https://code.iconify.design/2/2.1.2/iconify.min.js"></script>
  <style>
    * {
      animation: none !important;
      transition: none !important;
    }

    .glass-effect {
      background: rgba(17, 24, 39, 0.8);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .gradient-text {
      background: linear-gradient(45deg, #8B5CF6, #EC4899);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .date-card {
      background: rgba(31, 41, 55, 0.6);
    }

    .date-card:hover {
    }
  </style>
</head>

<body class="bg-gray-900 text-gray-100">
  <header class="bg-gray-800 shadow-xl">
    <div class="container mx-auto flex justify-between items-center p-4">
      <div data-aos="fade-right">
        <img src="https://ik.imagekit.io/techiesneh/tv_logo/jtv-plus_TMaGGk6N0.png" alt="JIOTV+" class="h-12">
      </div>
      <!-- In Header Section -->
      <div id="userButtons" class="flex gap-2" data-aos="fade-left">
        <button onclick="window.location.href='../'" class="p-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 rounded-lg transition-all group relative" id="loginButton">
          <span class="iconify text-xl" data-icon="mdi:home"></span>
          <span class="sr-only">Home</span>
        </button>

        <button class="p-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 rounded-lg transition-all group relative" id="refreshButton">
          <span class="iconify text-xl" data-icon="mdi:reload"></span>
          <span class="sr-only">Refresh</span>
        </button>

        <!--
        <button class="p-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 rounded-lg transition-all group relative" id="logoutButton">
          <span class="iconify text-xl" data-icon="mdi:logout"></span>
          <span class="sr-only">Logout</span>
        </button>
        -->
        <button id="logoutButton" type="button" class="hidden" aria-hidden="true" tabindex="-1"></button>
      </div>
    </div>
  </header>


  <main class="container mx-auto pt-24 pb-12 px-4">
    <div class="glass-effect rounded-2xl p-4 md:p-8 mb-6 md:mb-8 mx-2 md:mx-0" data-aos="fade-up">
      <div class="text-center space-y-4 md:space-y-6">
        <img src="<?= $safe_image ?>" alt="<?= $safe_name ?>"
          class="w-24 h-24 md:w-32 md:h-32 mx-auto rounded-xl mb-4 md:mb-6 shadow-lg">

        <h2 class="text-2xl md:text-3xl font-bold gradient-text mb-2 md:mb-4">
          <?= $safe_name ?>
        </h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4 justify-items-center">

          <?php
          date_default_timezone_set('Asia/Kolkata');

          $currentDate = date('d-M-Y');
          $currentTime = date('h:i A');
          ?>

          <div class="flex items-center space-x-2 text-sm md:text-base">
            <span class="iconify" data-icon="mdi:calendar"></span>
            <span><?= $currentDate ?></span>
          </div>
          <div class="flex items-center space-x-2 text-sm md:text-base">
            <span class="iconify" data-icon="mdi:clock-time-four"></span>
            <span id="live-clock"><?= $currentTime ?></span>
          </div>
        </div>

        <!-- <p class="text-gray-300 text-xs md:text-sm line-clamp-3 md:line-clamp-4 px-2 md:px-0">
          Description
        </p> -->

        <a href="<?= $safe_watch_href ?>"
          class="inline-block w-full sm:w-auto px-4 py-2 md:px-8 md:py-3 
                  bg-gradient-to-r from-purple-600 to-pink-600 
                  hover:from-purple-700 hover:to-pink-700 
                  rounded-lg font-medium text-sm md:text-base 
                  transition-all transform hover:scale-105"
          data-aos="zoom-in">
          Watch Live
        </a>
      </div>
    </div>

    <!-- Catchup Section -->
    <?php if ($c == true): ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6" data-aos="fade-up">
        <?php
        $daysToShow = 7;
        $timezone = new DateTimeZone('Asia/Kolkata');
        $currentDate = new DateTime('now', $timezone);

        for ($i = 0; $i >= -$daysToShow; $i--):
          $date = clone $currentDate;
          $date->modify("$i day");

          $formats = [
            'day' => 'd',
            'month' => 'F',
            'year' => 'Y',
            'weekday' => 'l'
          ];

          $dateComponents = [];
          foreach ($formats as $key => $format) {
            $dateComponents[$key] = $date->format($format);
          }

          $dataToEncode = implode('=?=', [$cid, $id, $i]);
          $cp_link = bin2hex($dataToEncode);
        ?>
          <div class="date-card rounded-xl p-6 text-center hover:scale-[1.02] transition-transform">
            <div class="mb-4">
              <div class="text-4xl font-bold text-purple-400"><?= htmlspecialchars($dateComponents['day']) ?></div>
              <div class="text-gray-400 text-sm mt-2">
                <div><?= htmlspecialchars($dateComponents['weekday']) ?></div>
                <div><?= htmlspecialchars($dateComponents['month']) ?> <?= htmlspecialchars($dateComponents['year']) ?></div>
              </div>
            </div>
            <a href="<?= htmlspecialchars($cp_url_host . $cp_link) ?>"
              class="inline-block w-full sm:w-auto px-4 py-2 bg-purple-800 hover:bg-purple-700 rounded-lg transition-colors">
              Watch Catchup
            </a>
          </div>
        <?php endfor; ?>
      </div>
    <?php else: ?>
      <div class="glass-effect rounded-2xl p-8 text-center" data-aos="fade-up">
        <div class="text-2xl gradient-text font-bold mb-4">Catchup Not Available</div>
        <p class="text-gray-400">This channel doesn't support catchup viewing</p>
      </div>
    <?php endif; ?>
  </main>

  <!-- Login Modal -->
  <?php if (!$file_exists): ?>
    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-75 backdrop-blur-sm flex items-center justify-center">
      <div class="glass-effect rounded-xl p-6 max-w-md w-full transform transition-all" data-aos="zoom-in">
        <h2 class="text-2xl font-bold gradient-text mb-4">🔐 Login Required</h2>
        <p class="text-gray-400 mb-6">Sign in to access premium content</p>
        <div class="flex justify-end gap-2">
          <a href="login"
            class="px-6 py-2 bg-gradient-to-r from-purple-600 to-pink-600 rounded-lg hover:opacity-90 transition-opacity">
            Continue to Login
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- LogOut Modal -->
  <div id="logoutModal" class="hidden fixed inset-0 bg-black bg-opacity-75 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full border border-gray-700 transform transition-all" data-aos="zoom-in">
      <h2 class="text-2xl font-bold mb-4 gradient-text">🚪 Confirm Logout</h2>
      <p class="text-gray-400 mb-6">
        Are you sure you want to logout? You'll need to login again to access premium content.
      </p>
      <div class="flex justify-end gap-2">
        <button class="px-6 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors"
          onclick="closeLogoutModal()">
          Cancel
        </button>
        <button class="px-6 py-2 bg-red-600 hover:bg-red-700 rounded-lg transition-colors"
          onclick="performLogout('dt')">
          Logout
        </button>
      </div>
    </div>
  </div>

  <!-- Refresh Modal -->
  <div id="refreshModal" class="hidden fixed inset-0 bg-black bg-opacity-75 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full border border-gray-700 transform transition-all" data-aos="zoom-in">
      <h2 class="text-2xl font-bold mb-4 gradient-text">🔄 Refresh Authentication</h2>
      <p class="text-gray-400 mb-6">
        Are you sure you want to refresh your authentication? This will update your session credentials.
      </p>
      <div class="flex justify-end gap-2">
        <button class="px-6 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors"
          onclick="closeRefreshModal()">
          Cancel
        </button>
        <button class="px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
          onclick="performRefresh('dt')">
          Refresh
        </button>
      </div>
    </div>
  </div>

  <footer class="bg-gray-800 mt-12 py-4">
    <div class="container mx-auto text-center text-gray-400">
      <p>&copy; 2021-<?= date('Y') ?> Atanu, Inc. All rights reserved.</p>
    </div>
  </footer>

  <script src="assets/js/button.js"></script>
  <script>
    // Real-time clock update
    function updateClock() {
      const timeElement = document.getElementById('live-clock');
      if (timeElement) {
        timeElement.textContent = new Date().toLocaleTimeString();
      }
    }
    setInterval(updateClock, 1000);

    // Update the logout button event listener
    document.getElementById("logoutButton").addEventListener("click", showLogoutModal);

    // Update the refresh button event listener
    document.getElementById("refreshButton").addEventListener("click", showRefreshModal);
  </script>
</body>

</html>
