<?php
/**
 * Agency Analyzer Pro - API Backend (v3.0)
 * 
 * 使用方法:
 * 1. agency_analyzer_config.php にAPIキーを設定
 * 2. このファイルと同じディレクトリに配置
 * 3. agency_analyzer_app.html からアクセス
 * 
 * v3.0 新機能:
 * - 2段階検索対応（クイック検索 → 詳細検索）
 * - 履歴削除API（cache_delete, cache_delete_bulk）
 */

// タイムアウト設定（504エラー対策）
set_time_limit(120); // 120秒
ini_set('max_execution_time', 120);

// CORSヘッダー
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 設定ファイル読み込み
$configFile = __DIR__ . '/agency_analyzer_config.php';
if (file_exists($configFile)) {
    require_once $configFile;
} else {
    // デフォルト設定
    $GEMINI_API_KEY = "";
    $PERPLEXITY_API_KEY = "";
    $OPENAI_API_KEY = "";
    $YOUTUBE_API_KEY = "";
    $RAPIDAPI_KEY = "";
    $SPOTIFY_CLIENT_ID = "";
    $SPOTIFY_CLIENT_SECRET = "";
    $CACHE_DIR = __DIR__ . '/cache_talent';
    $CACHE_TTL = [
        'profile' => 30 * 24 * 3600,
        'sns' => 7 * 24 * 3600,
        'activity' => 7 * 24 * 3600,
        'tokuten' => 7 * 24 * 3600,
        'goods_detail' => 14 * 24 * 3600,
        'fanservice' => 14 * 24 * 3600,
        'contact' => 30 * 24 * 3600,
        'news_all' => 1 * 24 * 3600,
        'live' => 7 * 24 * 3600,
        'release' => 7 * 24 * 3600,
        'goods' => 14 * 24 * 3600,
        'fanclub' => 14 * 24 * 3600,
        'online' => 7 * 24 * 3600,
        'limista' => 7 * 24 * 3600,
        'news' => 1 * 24 * 3600,
        'parsed' => 7 * 24 * 3600,
    ];
}

// キャッシュディレクトリ作成
if (!file_exists($CACHE_DIR)) {
    mkdir($CACHE_DIR, 0755, true);
}

// キャッシュヘルパー関数
function getCacheKey($talentName, $agencyName) {
    return md5($talentName . '|' . $agencyName);
}

function getCachePath($cacheKey) {
    global $CACHE_DIR;
    return $CACHE_DIR . '/' . $cacheKey . '.json';
}

function loadCache($talentName, $agencyName) {
    $cacheKey = getCacheKey($talentName, $agencyName);
    $cachePath = getCachePath($cacheKey);
    
    if (!file_exists($cachePath)) {
        return null;
    }
    
    $data = json_decode(file_get_contents($cachePath), true);
    return $data;
}

function saveCache($talentName, $agencyName, $data) {
    $cacheKey = getCacheKey($talentName, $agencyName);
    $cachePath = getCachePath($cacheKey);
    
    // 既存のキャッシュとマージ
    $existing = loadCache($talentName, $agencyName) ?: [];
    $merged = array_merge($existing, $data);
    $merged['_updatedAt'] = time();
    $merged['_talentName'] = $talentName;
    $merged['_agencyName'] = $agencyName;
    
    file_put_contents($cachePath, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return true;
}

function isCacheValid($cache, $key) {
    global $CACHE_TTL;
    
    if (!$cache || !isset($cache[$key]) || !isset($cache[$key . '_time'])) {
        return false;
    }
    
    $ttl = $CACHE_TTL[$key] ?? 7 * 24 * 3600;
    $age = time() - $cache[$key . '_time'];
    
    return $age < $ttl;
}

// タイムアウト対策
set_time_limit(300); // 5分
ini_set('max_execution_time', 300);

// === Backend: API Proxy ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json; charset=UTF-8");
    
    $input = json_decode(file_get_contents('php://input'), true);
    $target = $input['target'] ?? '';
    $payload = $input['payload'] ?? [];

    function send_response($data, $code = 200) {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }
    
    // キャッシュ取得API
    if ($target === 'cache_get') {
        $talentName = $payload['talentName'] ?? '';
        $agencyName = $payload['agencyName'] ?? '';
        
        if (empty($talentName)) {
            send_response(["error" => "talentName required"], 400);
        }
        
        $cache = loadCache($talentName, $agencyName);
        
        if (!$cache) {
            send_response(["found" => false, "cache" => null]);
        }
        
        // 各キーの有効性をチェック
        $validKeys = [];
        $expiredKeys = [];
        foreach (array_keys($GLOBALS['CACHE_TTL']) as $key) {
            if (isCacheValid($cache, $key)) {
                $validKeys[] = $key;
            } else {
                $expiredKeys[] = $key;
            }
        }
        
        send_response([
            "found" => true,
            "cache" => $cache,
            "validKeys" => $validKeys,
            "expiredKeys" => $expiredKeys,
            "age" => isset($cache['_updatedAt']) ? time() - $cache['_updatedAt'] : null
        ]);
    }
    
    // キャッシュ保存API
    if ($target === 'cache_save') {
        $talentName = $payload['talentName'] ?? '';
        $agencyName = $payload['agencyName'] ?? '';
        $data = $payload['data'] ?? [];
        
        if (empty($talentName)) {
            send_response(["error" => "talentName required"], 400);
        }
        
        // タイムスタンプを追加
        $dataWithTime = [];
        foreach ($data as $key => $value) {
            $dataWithTime[$key] = $value;
            $dataWithTime[$key . '_time'] = time();
        }
        
        $result = saveCache($talentName, $agencyName, $dataWithTime);
        send_response(["success" => $result]);
    }
    
    // キャッシュ統計API
    if ($target === 'cache_stats') {
        $files = glob($CACHE_DIR . '/*.json');
        $totalSize = 0;
        $count = count($files);
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        send_response([
            "count" => $count,
            "totalSize" => $totalSize,
            "totalSizeMB" => round($totalSize / 1024 / 1024, 2),
            "cacheDir" => $CACHE_DIR
        ]);
    }
    
    // キャッシュクリアAPI
    if ($target === 'cache_clear') {
        $talentName = $payload['talentName'] ?? '';
        $agencyName = $payload['agencyName'] ?? '';
        
        if ($talentName) {
            // 特定のタレントのキャッシュを削除
            $cacheKey = getCacheKey($talentName, $agencyName);
            $cachePath = getCachePath($cacheKey);
            if (file_exists($cachePath)) {
                unlink($cachePath);
                send_response(["success" => true, "deleted" => 1]);
            }
            send_response(["success" => true, "deleted" => 0]);
        } else {
            // 全キャッシュを削除
            $files = glob($CACHE_DIR . '/*.json');
            foreach ($files as $file) {
                unlink($file);
            }
            send_response(["success" => true, "deleted" => count($files)]);
        }
    }
    
    // キャッシュ一覧取得API（検索履歴）
    if ($target === 'cache_list') {
        $search = $payload['search'] ?? '';
        $agencyFilter = $payload['agency'] ?? '';
        $limit = $payload['limit'] ?? 100;
        
        $files = glob($CACHE_DIR . '/*.json');
        $list = [];
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) continue;
            
            $talentName = $data['_talentName'] ?? '';
            $agencyName = $data['_agencyName'] ?? '';
            $updatedAt = $data['_updatedAt'] ?? 0;
            
            // フィルタリング
            if ($search && stripos($talentName, $search) === false && stripos($agencyName, $search) === false) {
                continue;
            }
            if ($agencyFilter && stripos($agencyName, $agencyFilter) === false) {
                continue;
            }
            
            // 基本情報を抽出
            $basic = $data['basic'] ?? [];
            $sns = $data['sns'] ?? [];
            $fans = $data['fans'] ?? [];
            
            $list[] = [
                'talentName' => $talentName,
                'agencyName' => $agencyName,
                'updatedAt' => $updatedAt,
                'updatedAtFormatted' => $updatedAt ? date('Y-m-d H:i', $updatedAt) : '',
                'age' => round((time() - $updatedAt) / 86400, 1), // 日数
                'hasProfile' => !empty($data['profile']),
                'hasSNS' => !empty($data['sns']),
                'hasLive' => !empty($data['live']),
                'hasRelease' => !empty($data['release']),
                'summary' => [
                    'name' => $basic['name'] ?? $talentName,
                    'genre' => $basic['genre'] ?? '',
                    'twitter' => $sns['twitter']['followers'] ?? '',
                    'youtube' => $sns['youtube']['subscribers'] ?? '',
                    'fansGender' => $fans['gender'] ?? '',
                    'fansAge' => $fans['age'] ?? ''
                ]
            ];
        }
        
        // 更新日時順にソート
        usort($list, function($a, $b) {
            return $b['updatedAt'] - $a['updatedAt'];
        });
        
        // リミット適用
        $list = array_slice($list, 0, $limit);
        
        // 事務所一覧を抽出
        $agencies = array_unique(array_filter(array_column($list, 'agencyName')));
        sort($agencies);
        
        send_response([
            "count" => count($list),
            "list" => $list,
            "agencies" => array_values($agencies)
        ]);
    }
    
    // キャッシュから詳細データ取得API
    if ($target === 'cache_get_full') {
        $talentName = $payload['talentName'] ?? '';
        $agencyName = $payload['agencyName'] ?? '';
        
        if (empty($talentName)) {
            send_response(["error" => "talentName required"], 400);
        }
        
        $cache = loadCache($talentName, $agencyName);
        
        if (!$cache) {
            send_response(["found" => false]);
        }
        
        // パース済みデータがあればそれを返す、なければ生データを返す
        send_response([
            "found" => true,
            "data" => $cache,
            "talentName" => $talentName,
            "agencyName" => $agencyName
        ]);
    }
    
    // キャッシュ個別削除
    if ($target === 'cache_delete') {
        $talentName = $payload['talentName'] ?? '';
        $agencyName = $payload['agencyName'] ?? '';
        
        if (empty($talentName)) {
            send_response(["error" => "talentName required"], 400);
        }
        
        $cacheKey = getCacheKey($talentName, $agencyName);
        $cachePath = getCachePath($cacheKey);
        
        if (file_exists($cachePath)) {
            unlink($cachePath);
            send_response([
                "success" => true,
                "deleted" => true,
                "talentName" => $talentName,
                "agencyName" => $agencyName
            ]);
        } else {
            send_response([
                "success" => true,
                "deleted" => false,
                "message" => "Cache not found"
            ]);
        }
    }
    
    // キャッシュ複数削除
    if ($target === 'cache_delete_bulk') {
        $items = $payload['items'] ?? [];
        
        if (empty($items) || !is_array($items)) {
            send_response(["error" => "items array required"], 400);
        }
        
        $deleted = 0;
        $notFound = 0;
        
        foreach ($items as $item) {
            $talentName = $item['talentName'] ?? '';
            $agencyName = $item['agencyName'] ?? '';
            
            if (empty($talentName)) continue;
            
            $cacheKey = getCacheKey($talentName, $agencyName);
            $cachePath = getCachePath($cacheKey);
            
            if (file_exists($cachePath)) {
                unlink($cachePath);
                $deleted++;
            } else {
                $notFound++;
            }
        }
        
        send_response([
            "success" => true,
            "deleted" => $deleted,
            "notFound" => $notFound,
            "total" => count($items)
        ]);
    }
    
    // デバッグ用: テスト接続
    if ($target === 'test') {
        send_response(["status" => "ok", "message" => "POST request received"]);
    }
    
    // デバッグ用: APIキー設定確認
    if ($target === 'check_keys') {
        send_response([
            "gemini" => !empty($GEMINI_API_KEY) ? "設定済み (" . substr($GEMINI_API_KEY, 0, 8) . "...)" : "未設定",
            "perplexity" => !empty($PERPLEXITY_API_KEY) ? "設定済み (" . substr($PERPLEXITY_API_KEY, 0, 8) . "...)" : "未設定",
            "openai" => !empty($OPENAI_API_KEY) ? "設定済み (" . substr($OPENAI_API_KEY, 0, 8) . "...)" : "未設定",
            "youtube" => !empty($YOUTUBE_API_KEY) ? "設定済み (" . substr($YOUTUBE_API_KEY, 0, 8) . "...)" : "未設定",
            "rapidapi" => !empty($RAPIDAPI_KEY) ? "設定済み (" . substr($RAPIDAPI_KEY, 0, 8) . "...)" : "未設定",
            "spotify" => !empty($SPOTIFY_CLIENT_ID) ? "設定済み (" . substr($SPOTIFY_CLIENT_ID, 0, 8) . "...)" : "未設定",
            "cd_api" => "利用可能（APIキー不要）",
            "cache" => file_exists($CACHE_DIR) ? "有効 (" . count(glob($CACHE_DIR . '/*.json')) . "件)" : "無効"
        ]);
    }
    
    // Spotify API
    if ($target === 'spotify') {
        if (empty($SPOTIFY_CLIENT_ID) || empty($SPOTIFY_CLIENT_SECRET)) {
            send_response(["success" => false, "skipped" => true, "message" => "Spotify API未設定"]);
            exit;
        }
        
        $artistName = $payload['artist'] ?? '';
        $artistId = $payload['artistId'] ?? '';
        
        // アクセストークンを取得
        $tokenUrl = "https://accounts.spotify.com/api/token";
        $tokenCh = curl_init($tokenUrl);
        curl_setopt($tokenCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($tokenCh, CURLOPT_POST, true);
        curl_setopt($tokenCh, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
        curl_setopt($tokenCh, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($SPOTIFY_CLIENT_ID . ':' . $SPOTIFY_CLIENT_SECRET),
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        $tokenResponse = curl_exec($tokenCh);
        curl_close($tokenCh);
        
        $tokenData = json_decode($tokenResponse, true);
        if (empty($tokenData['access_token'])) {
            send_response([
                "success" => false,
                "error" => ["message" => "Spotifyトークン取得失敗"],
                "debug" => $tokenData
            ]);
            exit;
        }
        $accessToken = $tokenData['access_token'];
        
        // アーティストIDがない場合は検索
        if (empty($artistId) && !empty($artistName)) {
            $searchUrl = "https://api.spotify.com/v1/search?" . http_build_query([
                'q' => $artistName,
                'type' => 'artist',
                'limit' => 5,
                'market' => 'JP'
            ]);
            
            $searchCh = curl_init($searchUrl);
            curl_setopt($searchCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($searchCh, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken
            ]);
            $searchResponse = curl_exec($searchCh);
            curl_close($searchCh);
            
            $searchData = json_decode($searchResponse, true);
            
            if (!empty($searchData['artists']['items'])) {
                $artistId = $searchData['artists']['items'][0]['id'];
                $candidates = array_map(function($a) {
                    return [
                        'id' => $a['id'],
                        'name' => $a['name'],
                        'followers' => $a['followers']['total'] ?? 0,
                        'popularity' => $a['popularity'] ?? 0,
                        'genres' => $a['genres'] ?? []
                    ];
                }, $searchData['artists']['items']);
            } else {
                send_response([
                    "success" => false,
                    "error" => ["message" => "アーティストが見つかりません"],
                    "artist" => $artistName
                ]);
                exit;
            }
        }
        
        if (empty($artistId)) {
            send_response(["error" => ["message" => "artist または artistId が必要です"]], 400);
            exit;
        }
        
        // アーティスト詳細を取得
        $artistUrl = "https://api.spotify.com/v1/artists/" . $artistId;
        $artistCh = curl_init($artistUrl);
        curl_setopt($artistCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($artistCh, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        $artistResponse = curl_exec($artistCh);
        curl_close($artistCh);
        
        $artistData = json_decode($artistResponse, true);
        
        // トップトラックを取得
        $topTracksUrl = "https://api.spotify.com/v1/artists/{$artistId}/top-tracks?market=JP";
        $topTracksCh = curl_init($topTracksUrl);
        curl_setopt($topTracksCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($topTracksCh, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        $topTracksResponse = curl_exec($topTracksCh);
        curl_close($topTracksCh);
        
        $topTracksData = json_decode($topTracksResponse, true);
        
        if (!empty($artistData['id'])) {
            $topTracks = [];
            if (!empty($topTracksData['tracks'])) {
                foreach (array_slice($topTracksData['tracks'], 0, 5) as $track) {
                    $topTracks[] = [
                        'name' => $track['name'],
                        'album' => $track['album']['name'] ?? '',
                        'release_date' => $track['album']['release_date'] ?? '',
                        'popularity' => $track['popularity'] ?? 0,
                        'preview_url' => $track['preview_url'] ?? null,
                        'external_url' => $track['external_urls']['spotify'] ?? ''
                    ];
                }
            }
            
            send_response([
                "success" => true,
                "id" => $artistData['id'],
                "name" => $artistData['name'],
                "followers" => $artistData['followers']['total'] ?? 0,
                "popularity" => $artistData['popularity'] ?? 0,
                "genres" => $artistData['genres'] ?? [],
                "images" => $artistData['images'] ?? [],
                "external_url" => $artistData['external_urls']['spotify'] ?? '',
                "top_tracks" => $topTracks,
                "candidates" => $candidates ?? null
            ]);
            exit;
        } else {
            send_response([
                "success" => false,
                "error" => ["message" => "アーティスト情報取得失敗"],
                "debug" => $artistData
            ]);
            exit;
        }
    }
    
    // CD情報検索API (mi-im.com)
    if ($target === 'cd_search') {
        $artist = $payload['artist'] ?? '';
        
        if (empty($artist)) {
            send_response(["error" => ["message" => "artist パラメータが必要です"]], 400);
            exit;
        }
        
        $url = "https://lab.mi-im.com/api/boss/search?artist=" . urlencode($artist);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            send_response(["error" => ["message" => "cURL Error: " . $curlError]], 500);
            exit;
        }
        
        $data = json_decode($response, true);
        
        if (is_array($data)) {
            // 発売日で降順ソート
            usort($data, function($a, $b) {
                $dateA = $a['releasedate'] ?? $a['release_date'] ?? $a['releaseDate'] ?? $a['release'] ?? $a['発売日'] ?? '';
                $dateB = $b['releasedate'] ?? $b['release_date'] ?? $b['releaseDate'] ?? $b['release'] ?? $b['発売日'] ?? '';
                return strcmp($dateB, $dateA);
            });
            
            // データを整形
            $formatted = array_map(function($item) {
                $releaseDate = $item['releasedate'] ?? $item['release_date'] ?? $item['releaseDate'] ?? $item['release'] ?? $item['発売日'] ?? '';
                $promotion = $item['promotion'] ?? '';
                if (strpos($promotion, '/') !== false) {
                    $promotion = explode('/', $promotion)[0];
                }
                return [
                    'title' => $item['title'] ?? '',
                    'promotion' => trim($promotion),
                    'price' => $item['price'] ?? '',
                    'release_date' => $releaseDate,
                    'hinban' => $item['hinban'] ?? '',
                    'jan' => $item['jan'] ?? '',
                    'media' => $item['media'] ?? ''
                ];
            }, $data);
            
            send_response([
                "success" => true,
                "count" => count($formatted),
                "artist" => $artist,
                "items" => $formatted
            ]);
            exit;
        } else {
            send_response([
                "success" => false,
                "error" => ["message" => "データが取得できませんでした"],
                "artist" => $artist,
                "raw" => $data
            ]);
            exit;
        }
    }
    
    // Twitter API (RapidAPI - Twitter API by Alexander Vikhorev)
    if ($target === 'twitter') {
        if (empty($RAPIDAPI_KEY)) send_response(["error" => ["message" => "RapidAPI Keyが設定されていません"]], 500);
        
        $username = $payload['username'] ?? '';
        $searchQuery = $payload['searchQuery'] ?? '';
        
        // ユーザー名でユーザー情報を取得
        if ($username) {
            $username = ltrim($username, '@');
            
            // Twitter API by Alexander Vikhorev - UserByScreenName
            $url = "https://twitter-api45.p.rapidapi.com/screenname.php?screenname=" . urlencode($username);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'x-rapidapi-host: twitter-api45.p.rapidapi.com',
                'x-rapidapi-key: ' . $RAPIDAPI_KEY
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                send_response(["error" => ["message" => "cURL Error: " . $curlError]], 500);
                exit;
            }
            
            $data = json_decode($response, true);
            
            // レスポンス形式を解析
            if (isset($data['id']) || isset($data['rest_id'])) {
                send_response([
                    "success" => true,
                    "id" => $data['id'] ?? $data['rest_id'] ?? null,
                    "username" => $data['screen_name'] ?? $data['username'] ?? $username,
                    "name" => $data['name'] ?? null,
                    "followers_count" => $data['followers_count'] ?? $data['sub_count'] ?? null,
                    "following_count" => $data['friends_count'] ?? $data['following_count'] ?? null,
                    "tweet_count" => $data['statuses_count'] ?? null,
                    "description" => $data['description'] ?? null,
                    "verified" => $data['verified'] ?? $data['blue_verified'] ?? false,
                    "profile_image" => $data['profile_image_url_https'] ?? $data['avatar'] ?? null
                ]);
                exit;
            } else {
                send_response([
                    "success" => false,
                    "error" => ["message" => "ユーザーが見つかりません (username: @$username)"],
                    "debug" => $data,
                    "httpCode" => $httpCode
                ]);
                exit;
            }
        }
        // 検索クエリでユーザーを検索
        elseif ($searchQuery) {
            // Twitter API - Search
            $url = "https://twitter-api45.p.rapidapi.com/search.php?query=" . urlencode($searchQuery) . "&search_type=People";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'x-rapidapi-host: twitter-api45.p.rapidapi.com',
                'x-rapidapi-key: ' . $RAPIDAPI_KEY
            ]);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                send_response(["error" => ["message" => "cURL Error: " . $curlError]], 500);
                exit;
            }
            
            $data = json_decode($response, true);
            
            // 検索結果からユーザーを抽出
            $users = [];
            $timeline = $data['timeline'] ?? $data['users'] ?? $data['results'] ?? [];
            
            if (is_array($timeline)) {
                foreach ($timeline as $item) {
                    $user = $item['user'] ?? $item;
                    if (isset($user['screen_name']) || isset($user['username'])) {
                        $users[] = [
                            "id" => $user['id'] ?? $user['rest_id'] ?? null,
                            "username" => $user['screen_name'] ?? $user['username'] ?? null,
                            "name" => $user['name'] ?? null,
                            "followers_count" => $user['followers_count'] ?? $user['sub_count'] ?? null,
                            "verified" => $user['verified'] ?? $user['blue_verified'] ?? false,
                            "description" => $user['description'] ?? null
                        ];
                    }
                }
            }
            
            if (!empty($users)) {
                // 最もフォロワー数が多いユーザーを選択
                usort($users, function($a, $b) {
                    return ($b['followers_count'] ?? 0) - ($a['followers_count'] ?? 0);
                });
                $topUser = $users[0];
                send_response([
                    "success" => true,
                    "id" => $topUser['id'],
                    "username" => $topUser['username'],
                    "name" => $topUser['name'],
                    "followers_count" => $topUser['followers_count'],
                    "verified" => $topUser['verified'],
                    "description" => $topUser['description'],
                    "candidates" => array_slice($users, 0, 5)
                ]);
                exit;
            } else {
                send_response([
                    "success" => false,
                    "error" => ["message" => "ユーザーが見つかりません"],
                    "searchQuery" => $searchQuery,
                    "debug" => $data
                ]);
                exit;
            }
        }
        else {
            send_response(["error" => ["message" => "username または searchQuery が必要です"]], 400);
            exit;
        }
    }
    
    // YouTube Data API v3
    if ($target === 'youtube') {
        if (empty($YOUTUBE_API_KEY)) send_response(["error" => ["message" => "YouTube API Keyが設定されていません"]], 500);
        
        $channelId = $payload['channelId'] ?? '';
        $handle = $payload['handle'] ?? '';
        $username = $payload['username'] ?? '';
        $searchQuery = $payload['searchQuery'] ?? '';
        
        // デバッグログ
        error_log("YouTube API called: channelId=$channelId, handle=$handle, username=$username, searchQuery=$searchQuery");
        
        // 方法1: タレント名で検索
        if ($searchQuery) {
            $searchUrl = "https://www.googleapis.com/youtube/v3/search?" . http_build_query([
                'part' => 'snippet',
                'q' => $searchQuery . ' 公式',
                'type' => 'channel',
                'maxResults' => 3,
                'key' => $YOUTUBE_API_KEY
            ]);
            $ch = curl_init($searchUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $searchResponse = curl_exec($ch);
            curl_close($ch);
            $searchData = json_decode($searchResponse, true);
            
            if (!empty($searchData['items'])) {
                $channelId = $searchData['items'][0]['snippet']['channelId'] ?? '';
            }
            
            if (empty($channelId)) {
                send_response([
                    "success" => false,
                    "error" => ["message" => "チャンネルが見つかりません"],
                    "searchQuery" => $searchQuery,
                    "debug" => $searchData
                ]);
                exit;
            }
        }
        // 方法2: ハンドル名(@xxx)から取得
        elseif ($handle) {
            $handle = ltrim($handle, '@');
            $url = "https://www.googleapis.com/youtube/v3/channels?part=statistics,snippet&forHandle=" . urlencode($handle) . "&key=" . $YOUTUBE_API_KEY;
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($response, true);
            
            if (isset($data['items'][0])) {
                $item = $data['items'][0];
                send_response([
                    "success" => true,
                    "channelId" => $item['id'],
                    "title" => $item['snippet']['title'] ?? '',
                    "subscriberCount" => $item['statistics']['subscriberCount'] ?? null,
                    "videoCount" => $item['statistics']['videoCount'] ?? null,
                    "viewCount" => $item['statistics']['viewCount'] ?? null,
                    "hiddenSubscriberCount" => $item['statistics']['hiddenSubscriberCount'] ?? false
                ]);
                exit;
            } else {
                send_response([
                    "success" => false,
                    "error" => $data['error'] ?? ["message" => "チャンネルが見つかりません (handle: $handle)"],
                    "debug" => $data
                ]);
                exit;
            }
        }
        // 方法3: ユーザー名から取得
        elseif ($username) {
            $url = "https://www.googleapis.com/youtube/v3/channels?part=statistics,snippet&forUsername=" . urlencode($username) . "&key=" . $YOUTUBE_API_KEY;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($response, true);
            
            if (isset($data['items'][0])) {
                $item = $data['items'][0];
                send_response([
                    "success" => true,
                    "channelId" => $item['id'],
                    "title" => $item['snippet']['title'] ?? '',
                    "subscriberCount" => $item['statistics']['subscriberCount'] ?? null,
                    "videoCount" => $item['statistics']['videoCount'] ?? null,
                    "viewCount" => $item['statistics']['viewCount'] ?? null,
                    "hiddenSubscriberCount" => $item['statistics']['hiddenSubscriberCount'] ?? false
                ]);
                exit;
            } else {
                send_response([
                    "success" => false,
                    "error" => $data['error'] ?? ["message" => "チャンネルが見つかりません (username: $username)"],
                    "debug" => $data
                ]);
                exit;
            }
        }
        // パラメータなしの場合
        elseif (empty($channelId)) {
            send_response(["error" => ["message" => "channelId, handle, username, または searchQuery が必要です"]], 400);
            exit;
        }
        
        // channelIdがある場合（searchQueryで取得した場合を含む）
        if ($channelId) {
            $url = "https://www.googleapis.com/youtube/v3/channels?part=statistics,snippet&id=" . urlencode($channelId) . "&key=" . $YOUTUBE_API_KEY;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($response, true);
            
            if (isset($data['items'][0])) {
                $item = $data['items'][0];
                send_response([
                    "success" => true,
                    "channelId" => $item['id'],
                    "title" => $item['snippet']['title'] ?? '',
                    "subscriberCount" => $item['statistics']['subscriberCount'] ?? null,
                    "videoCount" => $item['statistics']['videoCount'] ?? null,
                    "viewCount" => $item['statistics']['viewCount'] ?? null,
                    "hiddenSubscriberCount" => $item['statistics']['hiddenSubscriberCount'] ?? false
                ]);
                exit;
            } else {
                send_response([
                    "success" => false,
                    "error" => $data['error'] ?? ["message" => "チャンネルが見つかりません (channelId: $channelId)"],
                    "debug" => $data
                ]);
                exit;
            }
        }
    }

    if ($target === 'gemini') {
        if (empty($GEMINI_API_KEY)) send_response(["error" => ["message" => "Gemini API Keyが設定されていません"]], 500);
        // 安定版モデルを使用
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $GEMINI_API_KEY;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // cURLエラーチェック
        if ($curlError) {
            send_response(["error" => ["message" => "cURL Error: " . $curlError]], 500);
        }
        
        http_response_code($httpCode);
        echo $response;
        exit;
    } elseif ($target === 'perplexity') {
        if (empty($PERPLEXITY_API_KEY)) send_response(["error" => ["message" => "Perplexity API Keyが設定されていません"]], 500);
        $url = "https://api.perplexity.ai/chat/completions";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $PERPLEXITY_API_KEY]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2分タイムアウト
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            send_response(["error" => ["message" => "cURL Error: " . $curlError]], 500);
        }
        
        http_response_code($httpCode);
        echo $response;
        exit;
    } elseif ($target === 'openai') {
        if (empty($OPENAI_API_KEY)) send_response(["error" => ["message" => "OpenAI API Keyが設定されていません"]], 500);
        $url = "https://api.openai.com/v1/chat/completions";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $OPENAI_API_KEY]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2分タイムアウト
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            send_response(["error" => ["message" => "cURL Error: " . $curlError]], 500);
        }
        
        http_response_code($httpCode);
        echo $response;
        exit;
    }
    send_response(["error" => ["message" => "Unknown target"]], 400);
}
?>
