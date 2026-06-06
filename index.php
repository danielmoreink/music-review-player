<?php
$songsDir = __DIR__ . DIRECTORY_SEPARATOR . 'songs';
$songsUrl = 'songs/';
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

if (!is_dir($songsDir)) {
    mkdir($songsDir, 0755, true);
}

$songs = [];
$entries = scandir($songsDir);

if ($entries !== false) {
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $filePath = $songsDir . DIRECTORY_SEPARATOR . $entry;
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

        if (is_file($filePath) && in_array($extension, $audioTypes, true)) {
            $createdAt = filectime($filePath);
            $modifiedAt = filemtime($filePath);

            $songs[] = [
                'name' => $entry,
                'createdAt' => $createdAt !== false ? $createdAt : $modifiedAt,
            ];
        }
    }
}

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

      <?php if (count($songs) === 0): ?>
        <section class="empty">
          <h2>No audio files yet</h2>
          <p>Add files to the <code>songs</code> folder, then reload this page.</p>
        </section>
      <?php else: ?>
        <section class="songs" aria-label="Audio files">
          <?php foreach ($songs as $index => $song): ?>
            <?php
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
      const songsContainer = document.querySelector(".songs");

      if (songsContainer) {
        const storageKey = `mix-listener-order:${location.pathname}`;
        let draggedSong = null;
        let activePointerId = null;

        function songRows() {
          return Array.from(songsContainer.querySelectorAll(".song"));
        }

        function saveOrder() {
          const order = songRows().map((row) => row.dataset.song);
          localStorage.setItem(storageKey, JSON.stringify(order));
        }

        function updateNumbers() {
          songRows().forEach((row, index) => {
            row.querySelector(".track-number").textContent = String(index + 1).padStart(2, "0");
          });
        }

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

        songsContainer.addEventListener("pointermove", (event) => {
          if (!draggedSong || event.pointerId !== activePointerId) {
            return;
          }

          event.preventDefault();
          moveDraggedSong(event.clientY);
        });

        songsContainer.addEventListener("pointerup", (event) => {
          if (event.pointerId === activePointerId) {
            stopDragging();
          }
        });

        songsContainer.addEventListener("pointercancel", (event) => {
          if (event.pointerId === activePointerId) {
            stopDragging();
          }
        });

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

        songsContainer.addEventListener("pause", (event) => {
          if (event.target.tagName !== "AUDIO" || !event.target.ended) {
            return;
          }

          const row = event.target.closest(".song");

          if (row) {
            row.classList.remove("active");
          }
        }, true);

        songsContainer.addEventListener("ended", (event) => {
          const row = event.target.closest(".song");

          if (row) {
            row.classList.remove("active");
          }
        }, true);

        restoreOrder();
      }
    </script>
  </body>
</html>
