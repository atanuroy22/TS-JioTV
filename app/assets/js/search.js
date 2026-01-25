(function () {
  const charactersList = document.getElementById("charactersList");
  const searchBar = document.getElementById("searchBar");
  const genreFilter = document.getElementById("genreFilter");
  const langFilter = document.getElementById("langFilter");
  const catchupFilter = document.getElementById("catchupFilter");

  if (
    !charactersList ||
    !searchBar ||
    !genreFilter ||
    !langFilter ||
    !catchupFilter
  ) {
    return;
  }

  const detailsBase =
    typeof url_host === "string" && url_host.length > 0
      ? url_host
      : "app/details.php?data=";
  const dataUrl = "app/playlist.php?format=json";
  const cacheKey = "tsjiotv_channels_cache_v1";

  let allChannels = [];
  let debounceTimer = null;

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function toHex(text) {
    const bytes = new TextEncoder().encode(text);
    let hex = "";
    for (let i = 0; i < bytes.length; i++) {
      hex += bytes[i].toString(16).padStart(2, "0");
    }
    return hex;
  }

  function parseCidFromLogoUrl(logoUrl) {
    if (!logoUrl) return "";
    const urlPart = String(logoUrl).split("?")[0];
    const parts = urlPart.split("/");
    const filename = parts[parts.length - 1] || "";
    return filename.replace(/\.png$/i, "");
  }

  function normalizeChannel(raw) {
    const name = String(raw.channel_name ?? "");
    const genre = String(raw.channelCategoryId ?? "");
    const lang = String(raw.channelLanguageId ?? "");
    const isCatchup = String(raw.isCatchupAvailable ?? "") === "True";
    const logoUrl = String(raw.logoUrl ?? "");
    const cid = parseCidFromLogoUrl(logoUrl) || name.replace(/\s+/g, "_");
    const id = raw.channel_id;
    const sourceType = String(raw.sourceType ?? "jio");
    const streamUrl = String(raw.streamUrl ?? "");

    return {
      id,
      name,
      nameLower: name.toLowerCase(),
      genre,
      genreLower: genre.toLowerCase(),
      lang,
      langLower: lang.toLowerCase(),
      isCatchup,
      cid,
      logoUrl,
      sourceType,
      streamUrl,
    };
  }

  function setSelectOptions(selectEl, placeholder, values) {
    const options = [`<option value="">${escapeHtml(placeholder)}</option>`];
    for (const value of values) {
      options.push(
        `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`
      );
    }
    selectEl.innerHTML = options.join("");
  }

  function buildFiltersFromChannels(channels) {
    const genreSet = new Set();
    const langSet = new Set();

    for (const ch of channels) {
      if (ch.genre) genreSet.add(ch.genre);
      if (ch.lang) langSet.add(ch.lang);
    }

    const genres = Array.from(genreSet).sort((a, b) => a.localeCompare(b));
    const langs = Array.from(langSet).sort((a, b) => a.localeCompare(b));

    setSelectOptions(genreFilter, "🎭 GENRE", genres);
    setSelectOptions(langFilter, "🌐 LANGUAGE", langs);
  }

  function applyFilters() {
    const q = searchBar.value.trim().toLowerCase();
    const selectedGenre = genreFilter.value.trim().toLowerCase();
    const selectedLang = langFilter.value.trim().toLowerCase();
    const catchupMode = catchupFilter.value;

    let result = allChannels;

    if (selectedGenre)
      result = result.filter((c) => c.genreLower === selectedGenre);
    if (selectedLang)
      result = result.filter((c) => c.langLower === selectedLang);

    if (catchupMode === "y") result = result.filter((c) => c.isCatchup);
    if (catchupMode === "n") result = result.filter((c) => !c.isCatchup);

    if (q)
      result = result.filter(
        (c) => c.nameLower.includes(q) || c.cid.toLowerCase().includes(q)
      );

    return result;
  }

  function render(channels) {
    if (!channels.length) {
      charactersList.innerHTML =
        '<div class="col-span-full text-center text-gray-400 py-12">No channels found</div>';
      return;
    }

    const parts = new Array(channels.length);

    for (let i = 0; i < channels.length; i++) {
      const ch = channels[i];
      const catchupFlag = ch.isCatchup ? "1" : "0";
      const isExternal = ch.sourceType === "ext";
      const href = isExternal
        ? `app/details.php?ext=${encodeURIComponent(String(ch.id))}`
        : `${detailsBase}${toHex(`${ch.cid}=?=${ch.id}=?=${catchupFlag}`)}`;
      const badge = ch.isCatchup
        ? '<span class="absolute top-2 right-2 text-[10px] px-2 py-1 rounded-full bg-purple-700/80 text-white">Catchup</span>'
        : isExternal
        ? '<span class="absolute top-2 right-2 text-[10px] px-2 py-1 rounded-full bg-blue-700/80 text-white">HTTPS</span>'
        : '<span class="absolute top-2 right-2 text-[10px] px-2 py-1 rounded-full bg-gray-700/80 text-white">Live</span>';

      parts[i] = `
        <a href="${escapeHtml(
          href
        )}" class="relative bg-gray-800 rounded-xl overflow-hidden border border-gray-700 block">
          ${badge}
          <div class="p-3 flex flex-col items-center gap-2">
            <div class="w-20 h-20 rounded-xl bg-gray-700 flex items-center justify-center overflow-hidden">
              <img src="${escapeHtml(ch.logoUrl)}" alt="${escapeHtml(
        ch.name
      )}" class="w-full h-full object-contain" loading="lazy" decoding="async">
            </div>
            <div class="text-center text-sm font-semibold leading-snug line-clamp-2">${escapeHtml(
              ch.name
            )}</div>
          </div>
        </a>
      `;
    }

    charactersList.innerHTML = parts.join("");
  }

  function updateView() {
    render(applyFilters());
  }

  function debounceUpdate() {
    if (debounceTimer) window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(updateView, 120);
  }

  function loadCachedChannels() {
    try {
      const raw = localStorage.getItem(cacheKey);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return null;
      return parsed;
    } catch {
      return null;
    }
  }

  function saveCachedChannels(rawChannels) {
    try {
      localStorage.setItem(cacheKey, JSON.stringify(rawChannels));
    } catch {
      return;
    }
  }

  async function fetchChannels() {
    const res = await fetch(dataUrl, { cache: "no-store" });
    if (!res.ok) throw new Error("Failed to load channels");
    const data = await res.json();
    if (!Array.isArray(data)) throw new Error("Invalid channel data");
    return data;
  }

  function setChannels(rawChannels) {
    allChannels = rawChannels.map(normalizeChannel);
    buildFiltersFromChannels(allChannels);
    updateView();
  }

  async function init() {
    const cached = loadCachedChannels();
    if (cached) {
      setChannels(cached);
    } else {
      charactersList.innerHTML =
        '<div class="col-span-full text-center text-gray-400 py-12">Loading channels...</div>';
    }

    try {
      const fresh = await fetchChannels();
      saveCachedChannels(fresh);
      if (!cached || fresh.length !== cached.length) {
        setChannels(fresh);
      }
    } catch {
      if (!cached) {
        charactersList.innerHTML =
          '<div class="col-span-full text-center text-gray-400 py-12">Failed to load channels</div>';
      }
    }
  }

  searchBar.addEventListener("input", debounceUpdate);
  genreFilter.addEventListener("change", updateView);
  langFilter.addEventListener("change", updateView);
  catchupFilter.addEventListener("change", updateView);

  init();
})();
