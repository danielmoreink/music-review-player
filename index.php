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
          <h1>Mix Listener</h1>
        </div>
      </header>

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
              $songName = htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8');
              $trackNumber = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
              $createdDate = date('Y-m-d H:i', $song['createdAt']);
            ?>
            <article class="song" data-song="<?= $songName ?>">
              <div class="song-info">
                <button class="drag-handle" type="button" aria-label="Move track" title="Move track">::</button>
                <span class="track-number"><?= $trackNumber ?></span>
                <div class="song-text">
                  <div class="song-title"><?= $songName ?></div>
                  <div class="song-date">Created <?= $createdDate ?></div>
                </div>
              </div>
              <audio controls preload="metadata" src="<?= $songHref ?>"></audio>
              <a class="download" href="<?= $songHref ?>" download>Download</a>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </main>
    <script>
      // Browser-side behavior starts here. This runs after PHP has rendered the track cards.
      const songsContainer = document.querySelector(".songs");

      if (songsContainer) {
        // The saved order is browser-local. It remembers your arrangement on this device/browser.
        const storageKey = `mix-listener-order:${location.pathname}`;
        let draggedSong = null;
        let activePointerId = null;

        // Return all current track cards in their visible top-to-bottom order.
        function songRows() {
          return Array.from(songsContainer.querySelectorAll(".song"));
        }

        // Save the current visible order using each track's filename as its stable ID.
        function saveOrder() {
          const order = songRows().map((row) => row.dataset.song);
          localStorage.setItem(storageKey, JSON.stringify(order));
        }

        // Re-number cards after dragging so numbering always runs from 01 to the final track.
        function updateNumbers() {
          songRows().forEach((row, index) => {
            row.querySelector(".track-number").textContent = String(index + 1).padStart(2, "0");
          });
        }

        // Apply a previously saved order. New files that are not in localStorage stay at the end.
        function restoreOrder() {
          let saved = [];

          try {
            saved = JSON.parse(localStorage.getItem(storageKey) || "[]");
          } catch (error) {
            saved = [];
          }

          saved.forEach((songName) => {
            const row = songsContainer.querySelector(`.song[data-song="${CSS.escape(songName)}"]`);

            if (row) {
              songsContainer.append(row);
            }
          });

          updateNumbers();
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

        // Start dragging only when the user presses the handle, not the audio controls or download link.
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

        // When one audio player starts, pause every other player and highlight the active card.
        songsContainer.addEventListener("play", (event) => {
          if (event.target.tagName !== "AUDIO") {
            return;
          }

          const activeAudio = event.target;

          songsContainer.querySelectorAll("audio").forEach((audio) => {
            if (audio !== activeAudio) {
              audio.pause();
            }
          });

          songRows().forEach((row) => {
            row.classList.toggle("active", row.contains(activeAudio));
          });
        }, true);

        // If a track reaches the end and pauses, remove the active highlight.
        songsContainer.addEventListener("pause", (event) => {
          if (event.target.tagName !== "AUDIO" || !event.target.ended) {
            return;
          }

          const row = event.target.closest(".song");

          if (row) {
            row.classList.remove("active");
          }
        }, true);

        // Some browsers fire ended separately; this guarantees the highlight is removed.
        songsContainer.addEventListener("ended", (event) => {
          const row = event.target.closest(".song");

          if (row) {
            row.classList.remove("active");
          }
        }, true);

        // Restore your saved order after all functions and listeners are ready.
        restoreOrder();
      }
    </script>
  </body>
</html>
