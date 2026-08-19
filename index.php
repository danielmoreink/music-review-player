<?php
// Server-side setup: this PHP runs before the browser receives the page.
// It finds audio files in /songs and prepares the track list used below.
$songsDir = __DIR__ . DIRECTORY_SEPARATOR . 'songs';
$songsUrl = 'songs/';

// Only files with these extensions will appear on the page.
$audioTypes = [
    'aac',
    'aiff',
    'flac',
    'm4a',
    'mp3',
    'ogg',
    'opus',
    'wav',
    'webm',
];

// Create the songs folder if it does not exist yet.
if (!is_dir($songsDir)) {
    mkdir($songsDir, 0755, true);
}

$songs = [];
$entries = scandir($songsDir);

// Read every file in /songs, ignore folders and unsupported file types,
// and store the filename plus the server-side file timestamp.
if ($entries !== false) {
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $filePath = $songsDir . DIRECTORY_SEPARATOR . $entry;
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

        if (is_file($filePath) && in_array($extension, $audioTypes, true)) {
            // On many webspaces, filectime is the upload/change time, not the original computer creation date.
            $createdAt = filectime($filePath);
            $modifiedAt = filemtime($filePath);

            $songs[] = [
                'name' => $entry,
                'code' => strtolower(base_convert(sprintf('%u', crc32($entry)), 10, 36)),
                'createdAt' => $createdAt !== false ? $createdAt : $modifiedAt,
            ];
        }
    }
}

// Keep the default server order alphabetical before any browser-saved drag order is applied.
usort($songs, function ($a, $b) {
    return strnatcasecmp($a['name'], $b['name']);
});
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Mix Listener</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body>
    <main class="shell">
      <header class="topbar">
        <div>
          <p class="eyebrow">Songs directory</p>
          <h1>Mix Listener <span id="total-playtime" class="total-playtime" aria-live="polite"></span></h1>
        </div>
        <button id="share-order" class="share-order" type="button">Share order</button>
      </header>

      <?php if (count($songs) > 0): ?>
        <section class="player-panel" aria-label="Main audio player">
          <div id="current-track" class="current-track">Choose a track</div>
          <audio id="main-player" controls preload="metadata"></audio>
        </section>
      <?php endif; ?>

      <!-- If PHP did not find any audio files, show a simple empty state. -->
      <?php if (count($songs) === 0): ?>
        <section class="empty">
          <h2>No audio files yet</h2>
          <p>Add files to the <code>songs</code> folder, then reload this page.</p>
        </section>
      <?php else: ?>
        <section class="songs" aria-label="Audio files">
          <!-- PHP creates one card per audio file. -->
          <?php foreach ($songs as $index => $song): ?>
            <?php
              // Escape values before printing them into HTML so filenames cannot break the page.
              $songHref = $songsUrl . rawurlencode($song['name']);
              $songHrefAttr = htmlspecialchars($songHref, ENT_QUOTES, 'UTF-8');
              $songName = htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8');
              $songCode = htmlspecialchars($song['code'], ENT_QUOTES, 'UTF-8');
              $trackNumber = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
              $createdDate = date('Y-m-d H:i', $song['createdAt']);
            ?>
            <article class="song" data-song="<?= $songName ?>" data-code="<?= $songCode ?>" data-src="<?= $songHrefAttr ?>">
              <div class="song-info">
                <button class="drag-handle" type="button" aria-label="Move track" title="Move track">::</button>
                <span class="track-number"><?= $trackNumber ?></span>
                <div class="song-text">
                  <div class="song-title"><?= $songName ?></div>
                  <div class="song-date">Created <?= $createdDate ?></div>
                </div>
              </div>
              <button class="play-track" type="button">Play</button>
              <a class="download" href="<?= $songHrefAttr ?>" download>Download</a>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </main>
    <script>
      // Browser-side behavior starts here. This runs after PHP has rendered the track cards.
      const songsContainer = document.querySelector(".songs");
      const totalPlaytimeEl = document.querySelector("#total-playtime");
      const shareOrderButton = document.querySelector("#share-order");
      const player = document.querySelector("#main-player");
      const currentTrackEl = document.querySelector("#current-track");

      if (songsContainer && player) {
        // The saved order is browser-local. It remembers your arrangement on this device/browser.
        const storageKey = `mix-listener-order:${location.pathname}`;
        let draggedSong = null;
        let activePointerId = null;
        let activeRow = null;
        let autoAdvanceTimer = null;

        // Return all current track cards in their visible top-to-bottom order.
        function songRows() {
          return Array.from(songsContainer.querySelectorAll(".song"));
        }

        // Format seconds as M:SS or H:MM:SS for the headline total.
        function formatDuration(totalSeconds) {
          const roundedSeconds = Math.round(totalSeconds);
          const hours = Math.floor(roundedSeconds / 3600);
          const minutes = Math.floor((roundedSeconds % 3600) / 60);
          const seconds = roundedSeconds % 60;

          if (hours > 0) {
            return `${hours}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
          }

          return `${minutes}:${String(seconds).padStart(2, "0")}`;
        }

        // Sum loaded audio durations and show the total in the headline.
        function updateTotalPlaytime() {
          if (!totalPlaytimeEl) {
            return;
          }

          const rows = songRows();
          const durations = rows.map((row) => Number(row.dataset.duration)).filter(Number.isFinite);

          if (durations.length === 0) {
            totalPlaytimeEl.textContent = "";
            return;
          }

          const totalSeconds = durations.reduce((sum, duration) => sum + duration, 0);
          const stillLoading = durations.length < rows.length;
          totalPlaytimeEl.textContent = stillLoading ? `(${formatDuration(totalSeconds)}+)` : `(${formatDuration(totalSeconds)})`;
        }

        // Load track durations without creating multiple visible players.
        function loadTrackDuration(row) {
          const audio = new Audio();
          audio.preload = "metadata";

          audio.addEventListener("loadedmetadata", () => {
            if (Number.isFinite(audio.duration)) {
              row.dataset.duration = String(audio.duration);
              updateTotalPlaytime();
            }

            audio.removeAttribute("src");
            audio.load();
          }, { once: true });

          audio.src = row.dataset.src;
        }

        // Return the current visible order using each track's short stable code.
        function currentOrder() {
          return songRows().map((row) => row.dataset.code);
        }

        // Save the current visible order in this browser for normal reloads without a shared URL.
        function saveOrder() {
          localStorage.setItem(storageKey, JSON.stringify(currentOrder()));
        }

        // Read a shared order from the URL. Example: ?order=abc123.def456
        function orderFromUrl() {
          const orderParam = new URLSearchParams(location.search).get("order");

          if (!orderParam) {
            return [];
          }

          if (orderParam.trim().startsWith("[")) {
            try {
              const order = JSON.parse(orderParam);
              return Array.isArray(order) ? order.filter((songName) => typeof songName === "string") : [];
            } catch (error) {
              return [];
            }
          }

          return orderParam.split(".").map((code) => code.trim()).filter(Boolean);
        }

        // Build a shareable URL containing the current visible song order.
        function shareUrl() {
          const url = new URL(location.href);
          url.searchParams.set("order", currentOrder().join("."));
          return url.toString();
        }

        // Re-number cards after dragging so numbering always runs from 01 to the final track.
        function updateNumbers() {
          songRows().forEach((row, index) => {
            row.querySelector(".track-number").textContent = String(index + 1).padStart(2, "0");
          });
        }

        // Apply a saved or shared order. New files that are not in that order stay at the end.
        function applyOrder(order) {
          order.forEach((songId) => {
            const row = songsContainer.querySelector(`.song[data-code="${CSS.escape(songId)}"], .song[data-song="${CSS.escape(songId)}"]`);

            if (row) {
              songsContainer.append(row);
            }
          });

          updateNumbers();
        }

        // A shared URL order wins. If there is no URL order, use this browser's saved order.
        function restoreOrder() {
          const sharedOrder = orderFromUrl();

          if (sharedOrder.length > 0) {
            applyOrder(sharedOrder);
            saveOrder();
            return;
          }

          let saved = [];

          try {
            saved = JSON.parse(localStorage.getItem(storageKey) || "[]");
          } catch (error) {
            saved = [];
          }

          applyOrder(Array.isArray(saved) ? saved : []);
        }

        // Find the card that should come after the dragged card for a given pointer Y position.
        function rowAfterPointer(y) {
          const rows = songRows().filter((row) => row !== draggedSong);

          return rows.reduce((closest, row) => {
            const box = row.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;

            if (offset < 0 && offset > closest.offset) {
              return { offset, row };
            }

            return closest;
          }, { offset: Number.NEGATIVE_INFINITY, row: null }).row;
        }

        // Move the dragged card in the DOM and immediately update the visible numbers.
        function moveDraggedSong(y) {
          if (!draggedSong) {
            return;
          }

          const after = rowAfterPointer(y);

          if (after) {
            songsContainer.insertBefore(draggedSong, after);
          } else {
            songsContainer.append(draggedSong);
          }

          updateNumbers();
        }

        // Finish a drag operation, remove drag styling, and persist the new order.
        function stopDragging() {
          if (!draggedSong) {
            return;
          }

          draggedSong.classList.remove("dragging");
          draggedSong = null;
          activePointerId = null;
          updateNumbers();
          saveOrder();
        }

        function setActiveRow(row) {
          activeRow = row;
          songRows().forEach((songRow) => {
            const isActive = songRow === row;
            songRow.classList.toggle("active", isActive);
            songRow.querySelector(".play-track").textContent = isActive && !player.paused ? "Pause" : "Play";
          });

          currentTrackEl.textContent = row ? row.dataset.song : "Choose a track";
        }

        function playRow(row, resetPosition = true) {
          if (!row) {
            return;
          }

          window.clearTimeout(autoAdvanceTimer);
          setActiveRow(row);

          if (player.dataset.code !== row.dataset.code) {
            player.src = row.dataset.src;
            player.dataset.code = row.dataset.code;
            resetPosition = true;
          }

          if (resetPosition) {
            player.currentTime = 0;
          }

          autoAdvanceTimer = window.setTimeout(() => {
            player.play().catch(() => {});
          }, 100);
        }

        function playNextTrack() {
          const rows = songRows();
          const currentIndex = activeRow ? rows.indexOf(activeRow) : -1;
          const nextRow = rows[currentIndex + 1];

          if (nextRow) {
            playRow(nextRow, true);
          } else {
            setActiveRow(null);
            player.removeAttribute("data-code");
          }
        }

        // Start dragging only when the user presses the handle, not the play or download controls.
        songsContainer.addEventListener("pointerdown", (event) => {
          const handle = event.target.closest(".drag-handle");

          if (!handle) {
            return;
          }

          const row = handle.closest(".song");

          if (!row) {
            return;
          }

          event.preventDefault();
          activePointerId = event.pointerId;
          draggedSong = row;
          row.classList.add("dragging");
          handle.setPointerCapture(event.pointerId);
        });

        // While dragging, keep inserting the card before/after nearby cards based on pointer position.
        songsContainer.addEventListener("pointermove", (event) => {
          if (!draggedSong || event.pointerId !== activePointerId) {
            return;
          }

          event.preventDefault();
          moveDraggedSong(event.clientY);
        });

        // Releasing the pointer completes the reorder.
        songsContainer.addEventListener("pointerup", (event) => {
          if (event.pointerId === activePointerId) {
            stopDragging();
          }
        });

        // If the browser cancels the pointer interaction, clean up like a normal drag end.
        songsContainer.addEventListener("pointercancel", (event) => {
          if (event.pointerId === activePointerId) {
            stopDragging();
          }
        });

        // Copy a URL that includes the current top-to-bottom song order.
        if (shareOrderButton) {
          shareOrderButton.addEventListener("click", async () => {
            const url = shareUrl();

            try {
              await navigator.clipboard.writeText(url);
              shareOrderButton.textContent = "Copied";
            } catch (error) {
              prompt("Copy this link:", url);
            }

            window.setTimeout(() => {
              shareOrderButton.textContent = "Share order";
            }, 1800);
          });
        }

        songsContainer.addEventListener("click", (event) => {
          const button = event.target.closest(".play-track");

          if (!button) {
            return;
          }

          const row = button.closest(".song");

          if (row === activeRow && !player.paused) {
            player.pause();
            return;
          }

          playRow(row, row !== activeRow);
        });

        player.addEventListener("play", () => {
          if (activeRow) {
            setActiveRow(activeRow);
          }
        });

        player.addEventListener("pause", () => {
          if (!player.ended && activeRow) {
            setActiveRow(activeRow);
          }
        });

        player.addEventListener("ended", playNextTrack);

        // Restore your saved order after all functions and listeners are ready.
        restoreOrder();
        updateTotalPlaytime();
        songRows().forEach(loadTrackDuration);
      }
    </script>
  </body>
</html>
