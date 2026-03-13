<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../php/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : null;

function fileUrl($relPath, $subDir)
{
    if (!$relPath)
        return null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $root = rtrim(str_replace('/api', '', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return "$scheme://$host$root/uploads/$subDir/" . basename($relPath);
}


function formatMovie($row)
{
    return [
        'movie_id' => (int) $row['MovieID'],
        'title' => $row['Title'],
        'release_year' => (int) $row['ReleaseYear'],
        'synopsis' => $row['Synopsis'] ?? '',
        'rating' => (float) ($row['Rating'] ?? 0),
        'thumbnail_url' => fileUrl($row['ThumbnailPath'], 'thumbnails'),
        'video_url' => fileUrl($row['VideoPath'], 'videos'),
        'has_video' => !empty($row['VideoPath']),
        'genres' => $row['Genres'] ? explode(',', $row['Genres']) : [],
        'categories' => $row['Categories'] ? explode(',', $row['Categories']) : [],
    ];
}

$baseSQL = "
    SELECT m.MovieID, m.Title, m.ReleaseYear, m.Synopsis, m.ThumbnailPath, m.VideoPath, m.Rating,
           GROUP_CONCAT(DISTINCT g.GenreName ORDER BY g.GenreName SEPARATOR ',') AS Genres,
           GROUP_CONCAT(DISTINCT c.CategoryName ORDER BY c.CategoryName SEPARATOR ',') AS Categories
    FROM Movie m
    LEFT JOIN MovieGenre mg    ON m.MovieID = mg.MovieID
    LEFT JOIN Genre g          ON mg.GenreID = g.GenreID
    LEFT JOIN MovieCategory mc ON m.MovieID = mc.MovieID
    LEFT JOIN Category c       ON mc.CategoryID = c.CategoryID
";

if ($method === 'GET') {
    if ($id) {
        $stmt = $conn->prepare($baseSQL . " WHERE m.MovieID = ? GROUP BY m.MovieID");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo $row ? json_encode(['status' => 'success', 'data' => formatMovie($row)]) : json_encode(['status' => 'error', 'message' => 'Not found.']);
        exit;
    }
    if (isset($_GET['search'])) {
        $q = '%' . $conn->real_escape_string($_GET['search']) . '%';
        $stmt = $conn->prepare($baseSQL . " WHERE m.Title LIKE ? GROUP BY m.MovieID ORDER BY m.Title");
        $stmt->bind_param("s", $q);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'count' => count($rows), 'data' => array_map('formatMovie', $rows)]);
        exit;
    }
    if (isset($_GET['category'])) {
        $cat = '%' . $conn->real_escape_string($_GET['category']) . '%';
        $stmt = $conn->prepare($baseSQL . " WHERE m.MovieID IN (SELECT mc2.MovieID FROM MovieCategory mc2 JOIN Category c2 ON mc2.CategoryID=c2.CategoryID WHERE c2.CategoryID = ?) GROUP BY m.MovieID ORDER BY m.Title");
        $cid = (int) $_GET['category'];
        $stmt->bind_param("i", $cid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'count' => count($rows), 'data' => array_map('formatMovie', $rows)]);
        exit;
    }
    if (isset($_GET['genre'])) {
        $g = '%' . $conn->real_escape_string($_GET['genre']) . '%';
        $stmt = $conn->prepare($baseSQL . " WHERE m.MovieID IN (SELECT mg2.MovieID FROM MovieGenre mg2 JOIN Genre g2 ON mg2.GenreID=g2.GenreID WHERE g2.GenreName LIKE ?) GROUP BY m.MovieID ORDER BY m.Title");
        $stmt->bind_param("s", $g);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'count' => count($rows), 'data' => array_map('formatMovie', $rows)]);
        exit;
    }
    if (isset($_GET['recommended'])) {
        $rows = $conn->query($baseSQL . " GROUP BY m.MovieID ORDER BY m.Rating DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'data' => array_map('formatMovie', $rows)]);
        exit;
    }
    // Recently watched — deduplicated by movie, ordered by most recent WatchDate
    // One row per movie max, most recently watched at the front
    if (isset($_GET['recently_watched'])) {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if (!$uid) { echo json_encode(['status' => 'success', 'data' => []]); exit; }
        $stmt = $conn->prepare(
            "SELECT m.MovieID, m.Title, m.ReleaseYear, m.Synopsis, m.ThumbnailPath, m.VideoPath, m.Rating,
                    GROUP_CONCAT(DISTINCT g.GenreName ORDER BY g.GenreName SEPARATOR ',') AS Genres,
                    GROUP_CONCAT(DISTINCT c.CategoryName ORDER BY c.CategoryName SEPARATOR ',') AS Categories,
                    MAX(vh.WatchDate) AS LastWatched,
                    MAX(vh.WatchDuration) AS WatchDuration,
                    MAX(vh.UserRating) AS UserRating
             FROM ViewingHistory vh
             JOIN Movie m ON vh.MovieID = m.MovieID
             LEFT JOIN MovieGenre mg    ON m.MovieID = mg.MovieID
             LEFT JOIN Genre g          ON mg.GenreID = g.GenreID
             LEFT JOIN MovieCategory mc ON m.MovieID = mc.MovieID
             LEFT JOIN Category c       ON mc.CategoryID = c.CategoryID
             WHERE vh.UserID = ?
             GROUP BY m.MovieID, m.Title, m.ReleaseYear, m.Synopsis, m.ThumbnailPath, m.VideoPath, m.Rating
             ORDER BY LastWatched DESC
             LIMIT 20"
        );
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $data = array_map(function($row) {
            $m = formatMovie($row);
            $m['last_watched'] = $row['LastWatched'];
            $m['watch_duration'] = (int)$row['WatchDuration'];
            $m['user_rating'] = (int)$row['UserRating'];
            return $m;
        }, $rows);
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }
    // Real-time rating poll — returns just the current rating for a single movie
    if (isset($_GET['rating']) && $id) {
        $stmt = $conn->prepare("SELECT Rating FROM Movie WHERE MovieID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo json_encode(['status' => 'success', 'rating' => $row ? (float)$row['Rating'] : 0]);
        exit;
    }
    $rows = $conn->query($baseSQL . " GROUP BY m.MovieID ORDER BY m.MovieID DESC")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['status' => 'success', 'count' => count($rows), 'data' => array_map('formatMovie', $rows)]);
    exit;
}

if ($method === 'POST') {
    $title = $_POST['title'] ?? '';
    $year = (int) ($_POST['release_year'] ?? 0);
    $synopsis = $_POST['synopsis'] ?? '';
    $rating = (float) ($_POST['rating'] ?? 0);
    if (!$title || !$year) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'title and release_year required.']);
        exit;
    }
    $err = '';
    $thumbName = handleUpload('thumbnail', UPLOAD_THUMB_DIR, ALLOWED_IMAGE_TYPES, MAX_IMAGE_SIZE, $err);
    if ($err) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $err]);
        exit;
    }
    $err = '';
    $videoName = handleUpload('video', UPLOAD_VIDEO_DIR, ALLOWED_VIDEO_TYPES, MAX_VIDEO_SIZE, $err);
    if ($err) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $err]);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO Movie (Title,ReleaseYear,Synopsis,ThumbnailPath,VideoPath,Rating) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("sisssd", $title, $year, $synopsis, $thumbName, $videoName, $rating);
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        foreach ((array) ($_POST['genre_ids'] ?? []) as $gid) {
            $gs = $conn->prepare("INSERT IGNORE INTO MovieGenre(MovieID,GenreID) VALUES(?,?)");
            $gs->bind_param("ii", $newId, $gid);
            $gs->execute();
        }
        foreach ((array) ($_POST['category_ids'] ?? []) as $cid) {
            $cs = $conn->prepare("INSERT IGNORE INTO MovieCategory(MovieID,CategoryID) VALUES(?,?)");
            $cs->bind_param("ii", $newId, $cid);
            $cs->execute();
        }
        http_response_code(201);
        echo json_encode(['status' => 'success', 'message' => 'Created.', 'movie_id' => $newId]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
    exit;
}

if ($method === 'DELETE') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'id required.']);
        exit;
    }
    $row = $conn->query("SELECT ThumbnailPath,VideoPath FROM Movie WHERE MovieID=$id")->fetch_assoc();
    if ($row) {
        if ($row['ThumbnailPath'])
            @unlink(UPLOAD_THUMB_DIR . basename($row['ThumbnailPath']));
        if ($row['VideoPath'])
            @unlink(UPLOAD_VIDEO_DIR . basename($row['VideoPath']));
    }
    $stmt = $conn->prepare("DELETE FROM Movie WHERE MovieID=?");
    $stmt->bind_param("i", $id);
    $stmt->execute() ? print (json_encode(['status' => 'success'])) : print (json_encode(['status' => 'error', 'message' => $conn->error]));
    exit;
}
http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
