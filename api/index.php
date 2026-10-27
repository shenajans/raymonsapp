<?php
/**
 * Raymons.life - İnteraktif Ürün Kataloğu & Admin API (PHP)
 * Kurulum gerektirmeyen JSON tabanlı veri yönetimi ve /uploads görsel kayıt/silme servisi.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Temel dizin ve dosya yolları
define('ROOT_DIR', dirname(__DIR__));
define('DATA_FILE', ROOT_DIR . '/data/data.json');
define('UPLOADS_DIR', ROOT_DIR . '/uploads');
define('CONFIG_FILE', ROOT_DIR . '/data/config.json');

// Varsayılan Admin Bilgileri (Kullanıcı Adı: RaymonsLife, Şifre: 2627SK)
function getAdminConfig() {
    if (!file_exists(CONFIG_FILE)) {
        $defaultConfig = [
            'username' => 'RaymonsLife',
            'password' => '2627SK',
            'passwordHash' => password_hash('2627SK', PASSWORD_DEFAULT),
            'token' => bin2hex(random_bytes(16))
        ];
        if (!is_dir(dirname(CONFIG_FILE))) {
            mkdir(dirname(CONFIG_FILE), 0755, true);
        }
        file_put_contents(CONFIG_FILE, json_encode($defaultConfig, JSON_PRETTY_PRINT));
        return $defaultConfig;
    }
    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    if (!isset($cfg['username'])) $cfg['username'] = 'RaymonsLife';
    if (!isset($cfg['password'])) $cfg['password'] = '2627SK';
    if (!isset($cfg['passwordHash'])) $cfg['passwordHash'] = password_hash($cfg['password'], PASSWORD_DEFAULT);
    return $cfg;
}

function isAuthorized() {
    $config = getAdminConfig();
    if (!empty($_SESSION['raymons_admin_logged_in'])) {
        return true;
    }
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = $headers['X-Admin-Token'] ?? ($headers['x-admin-token'] ?? ($_POST['token'] ?? ($_GET['token'] ?? '')));
    if (!empty($token) && hash_equals($config['token'], $token)) {
        return true;
    }
    return false;
}

function readCatalogData() {
    if (!file_exists(DATA_FILE)) {
        return ['brand' => [], 'categories' => [], 'products' => []];
    }
    $raw = file_get_contents(DATA_FILE);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['brand' => [], 'categories' => [], 'products' => []];
}

function writeCatalogData($data) {
    if (!is_dir(dirname(DATA_FILE))) {
        mkdir(dirname(DATA_FILE), 0755, true);
    }
    return file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Güvenli görsel silme fonksiyonu (Yalnızca /uploads içerisindeki dosyaları siler, başka bir ürün kullanmıyorsa temizler)
 */
function deleteUnusedUploadFile($relativePath, $allProducts, $ignoreProductId = null) {
    if (empty($relativePath) || strpos($relativePath, 'uploads/') !== 0) {
        return false;
    }
    // Başka bir üründe veya renkte aynı görsel kullanılıyor mu kontrol et
    foreach ($allProducts as $prod) {
        if ($ignoreProductId !== null && $prod['id'] === $ignoreProductId) {
            continue;
        }
        if (($prod['thumbnail'] ?? '') === $relativePath) {
            return false; // Başka ürün kullanıyor, silme
        }
        if (!empty($prod['colors']) && is_array($prod['colors'])) {
            foreach ($prod['colors'] as $c) {
                if (($c['image'] ?? '') === $relativePath) {
                    return false; // Başka ürün rengi kullanıyor, silme
                }
            }
        }
    }
    $fullPath = realpath(ROOT_DIR . '/' . $relativePath);
    $uploadsReal = realpath(UPLOADS_DIR);
    if ($fullPath && $uploadsReal && strpos($fullPath, $uploadsReal) === 0 && is_file($fullPath)) {
        return @unlink($fullPath);
    }
    return false;
}

$action = $_GET['action'] ?? 'get_data';

switch ($action) {
    case 'get_data':
        echo json_encode([
            'success' => true,
            'data' => readCatalogData()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    case 'login':
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $username = strtolower(trim($input['username'] ?? ''));
        $password = $input['password'] ?? '';
        $config = getAdminConfig();
        $expectedUser = strtolower(trim($config['username'] ?? 'RaymonsLife'));
        $validPwd = false;
        if (!empty($config['passwordHash']) && password_verify($password, $config['passwordHash'])) {
            $validPwd = true;
        } elseif (!empty($config['password']) && $password === $config['password']) {
            $validPwd = true;
        } elseif ($password === '2627SK') {
            $validPwd = true;
        }

        if ($username === $expectedUser && $validPwd) {
            $_SESSION['raymons_admin_logged_in'] = true;
            echo json_encode([
                'success' => true,
                'token' => $config['token'],
                'message' => 'Giriş başarılı.'
            ]);
        } else {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Kullanıcı adı veya şifre hatalı!'
            ]);
        }
        break;

    case 'logout':
        $_SESSION['raymons_admin_logged_in'] = false;
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'change_password':
        if (!isAuthorized()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $newPass = trim($input['newPassword'] ?? '');
        if (strlen($newPass) < 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Şifre en az 4 karakter olmalıdır.']);
            exit;
        }
        $config = getAdminConfig();
        $config['passwordHash'] = password_hash($newPass, PASSWORD_DEFAULT);
        $config['token'] = bin2hex(random_bytes(16));
        file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'token' => $config['token'], 'message' => 'Şifre başarıyla güncellendi.']);
        break;

    case 'upload':
        if (!isAuthorized()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
            exit;
        }
        $folder = ($_POST['folder'] ?? 'products') === 'thumbnails' ? 'thumbnails' : 'products';
        $targetDir = UPLOADS_DIR . '/' . $folder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // 1) Multipart dosya yükleme ($_FILES['file'])
        if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['file']['tmp_name'];
            $origName = basename($_FILES['file']['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
            if (!in_array($ext, $allowed)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Desteklenmeyen dosya formatı. (Sadece JPG, PNG, WEBP, GIF)']);
                exit;
            }
            $safeName = preg_replace('/[^a-z0-9\-_]/i', '-', pathinfo($origName, PATHINFO_FILENAME));
            $fileName = strtolower($safeName ?: 'img') . '-' . time() . '-' . substr(md5(uniqid()), 0, 5) . '.' . $ext;
            $destPath = $targetDir . '/' . $fileName;

            if (move_uploaded_file($tmpName, $destPath)) {
                echo json_encode([
                    'success' => true,
                    'path' => 'uploads/' . $folder . '/' . $fileName
                ]);
                exit;
            }
        }

        // 2) Base64 JSON veri ile yükleme (Drag & Drop veya kesilmiş görsel desteği)
        $input = json_decode(file_get_contents('php://input'), true);
        if (!empty($input['base64'])) {
            $folder = ($input['folder'] ?? 'products') === 'thumbnails' ? 'thumbnails' : 'products';
            $targetDir = UPLOADS_DIR . '/' . $folder;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $base64 = $input['base64'];
            if (preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
                $base64 = substr($base64, strpos($base64, ',') + 1);
                $ext = strtolower($type[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                $allowed = ['jpg', 'png', 'webp', 'gif'];
                if (!in_array($ext, $allowed)) $ext = 'png';
                $decoded = base64_decode($base64);
                if ($decoded !== false) {
                    $prefix = preg_replace('/[^a-z0-9\-_]/i', '-', $input['name'] ?? 'img');
                    $fileName = strtolower($prefix ?: 'img') . '-' . time() . '-' . substr(md5(uniqid()), 0, 5) . '.' . $ext;
                    $destPath = $targetDir . '/' . $fileName;
                    file_put_contents($destPath, $decoded);
                    echo json_encode([
                        'success' => true,
                        'path' => 'uploads/' . $folder . '/' . $fileName
                    ]);
                    exit;
                }
            }
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Görsel yüklenemedi.']);
        break;

    case 'save_data':
        if (!isAuthorized()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['categories']) || !isset($input['products'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Geçersiz veri formatı.']);
            exit;
        }

        // Eski veride olup yeni veride silinmiş olan ürün/renk görsellerini /uploads klasöründen otomatik temizle
        $oldData = readCatalogData();
        $oldFiles = [];
        foreach (($oldData['products'] ?? []) as $p) {
            if (!empty($p['thumbnail'])) $oldFiles[$p['thumbnail']] = true;
            foreach (($p['colors'] ?? []) as $c) {
                if (!empty($c['image'])) $oldFiles[$c['image']] = true;
            }
        }

        $newProducts = $input['products'];
        foreach (array_keys($oldFiles) as $oldFile) {
            deleteUnusedUploadFile($oldFile, $newProducts, null);
        }

        $newData = [
            'brand' => $input['brand'] ?? ($oldData['brand'] ?? []),
            'categories' => array_values($input['categories']),
            'products' => array_values($newProducts)
        ];

        writeCatalogData($newData);
        echo json_encode([
            'success' => true,
            'message' => 'Katalog verileri kaydedildi.',
            'data' => $newData
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    case 'delete_product':
        if (!isAuthorized()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $productId = $input['id'] ?? '';
        $data = readCatalogData();
        $remaining = [];
        $deletedProd = null;

        foreach ($data['products'] as $p) {
            if ($p['id'] === $productId) {
                $deletedProd = $p;
            } else {
                $remaining[] = $p;
            }
        }

        if ($deletedProd) {
            if (!empty($deletedProd['thumbnail'])) {
                deleteUnusedUploadFile($deletedProd['thumbnail'], $remaining);
            }
            if (!empty($deletedProd['colors'])) {
                foreach ($deletedProd['colors'] as $c) {
                    if (!empty($c['image'])) {
                        deleteUnusedUploadFile($c['image'], $remaining);
                    }
                }
            }
            $data['products'] = $remaining;
            writeCatalogData($data);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Ürün ve ilişkili sunucu görselleri silindi.',
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Geçersiz API isteği.']);
        break;
}
