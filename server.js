const http = require("http");
const fs = require("fs/promises");
const path = require("path");

const PORT = Number(process.env.PORT || 3000);
const ROOT_DIR = __dirname;
const PUBLIC_DIR = path.join(ROOT_DIR, "public");
const SONGS_DIR = path.join(ROOT_DIR, "songs");

const AUDIO_TYPES = new Map([
  [".aac", "audio/aac"],
  [".aiff", "audio/aiff"],
  [".flac", "audio/flac"],
  [".m4a", "audio/mp4"],
  [".mp3", "audio/mpeg"],
  [".ogg", "audio/ogg"],
  [".opus", "audio/ogg"],
  [".wav", "audio/wav"],
  [".webm", "audio/webm"]
]);

const STATIC_TYPES = new Map([
  [".css", "text/css; charset=utf-8"],
  [".html", "text/html; charset=utf-8"],
  [".js", "text/javascript; charset=utf-8"],
  [".json", "application/json; charset=utf-8"],
  [".svg", "image/svg+xml"]
]);

function send(res, status, body, headers = {}) {
  res.writeHead(status, headers);
  res.end(body);
}

function safeJoin(baseDir, requestPath) {
  const resolvedPath = path.resolve(baseDir, requestPath);
  const relativePath = path.relative(baseDir, resolvedPath);

  if (relativePath.startsWith("..") || path.isAbsolute(relativePath)) {
    return null;
  }

  return resolvedPath;
}

async function listSongs() {
  await fs.mkdir(SONGS_DIR, { recursive: true });
  const entries = await fs.readdir(SONGS_DIR, { withFileTypes: true });

  return entries
    .filter((entry) => entry.isFile())
    .filter((entry) => AUDIO_TYPES.has(path.extname(entry.name).toLowerCase()))
    .map((entry) => ({
      name: entry.name,
      url: `/songs/${encodeURIComponent(entry.name)}`,
      downloadUrl: `/download/${encodeURIComponent(entry.name)}`
    }))
    .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: "base" }));
}

async function serveStatic(req, res, pathname) {
  const staticPath = pathname === "/" ? "index.html" : pathname.replace(/^\/+/, "");
  const filePath = safeJoin(PUBLIC_DIR, staticPath);

  if (!filePath) {
    send(res, 403, "Forbidden");
    return;
  }

  try {
    const file = await fs.readFile(filePath);
    const contentType = STATIC_TYPES.get(path.extname(filePath).toLowerCase()) || "application/octet-stream";
    send(res, 200, file, { "Content-Type": contentType });
  } catch (error) {
    if (error.code === "ENOENT" || error.code === "EISDIR") {
      send(res, 404, "Not found");
      return;
    }

    throw error;
  }
}

async function serveSong(res, encodedName, asDownload) {
  const name = decodeURIComponent(encodedName);
  const ext = path.extname(name).toLowerCase();

  if (!AUDIO_TYPES.has(ext)) {
    send(res, 415, "Unsupported audio type");
    return;
  }

  const filePath = safeJoin(SONGS_DIR, name);
  if (!filePath) {
    send(res, 403, "Forbidden");
    return;
  }

  try {
    const file = await fs.readFile(filePath);
    const headers = {
      "Content-Type": AUDIO_TYPES.get(ext),
      "Content-Length": file.length,
      "Accept-Ranges": "bytes"
    };

    if (asDownload) {
      headers["Content-Disposition"] = `attachment; filename="${name.replaceAll('"', "")}"`;
    }

    send(res, 200, file, headers);
  } catch (error) {
    if (error.code === "ENOENT") {
      send(res, 404, "Not found");
      return;
    }

    throw error;
  }
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);

    if (url.pathname === "/api/songs") {
      const songs = await listSongs();
      send(res, 200, JSON.stringify({ songs }), {
        "Cache-Control": "no-store",
        "Content-Type": "application/json; charset=utf-8"
      });
      return;
    }

    if (url.pathname.startsWith("/songs/")) {
      await serveSong(res, url.pathname.slice("/songs/".length), false);
      return;
    }

    if (url.pathname.startsWith("/download/")) {
      await serveSong(res, url.pathname.slice("/download/".length), true);
      return;
    }

    await serveStatic(req, res, url.pathname);
  } catch (error) {
    console.error(error);
    send(res, 500, "Internal server error");
  }
});

server.listen(PORT, () => {
  console.log(`Mix listener running at http://localhost:${PORT}`);
  console.log(`Drop audio files into: ${SONGS_DIR}`);
});
