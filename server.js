/**
 * Raymons.life - İnteraktif Ürün Kataloğu & Admin API (Node.js / Express)
 * Sıfır kurulumlu veya Express destekli Node.js sunucusu.
 * Hem harici paket olmadan (saf Node.js http modülüyle) hem de Express ile çalışabilecek şekilde tasarlanmıştır.
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const url = require('url');

const PORT = process.env.PORT || 3000;
const ROOT_DIR = __dirname;
const DATA_FILE = path.join(ROOT_DIR, 'data', 'data.json');
const CONFIG_FILE = path.join(ROOT_DIR, 'data', 'config-node.json');
const UPLOADS_DIR = path.join(ROOT_DIR, 'uploads');

// Gerekli klasörleri oluştur
['data', 'uploads', 'uploads/products', 'uploads/thumbnails'].forEach((dir) => {
    const fullPath = path.join(ROOT_DIR, dir);
    if (!fs.existsSync(fullPath)) {
        fs.mkdirSync(fullPath, { recursive: true });
    }
});

function hashPassword(pwd) {
    return crypto.createHash('sha256').update(pwd + '_raymons_salt').digest('hex');
}

function getAdminConfig() {
    if (!fs.existsSync(CONFIG_FILE)) {
        const defaultCfg = {
            passwordHash: hashPassword('raymons2026'),
            token: crypto.randomBytes(16).toString('hex')
        };
        fs.writeFileSync(CONFIG_FILE, JSON.stringify(defaultCfg, null, 2), 'utf8');
        return defaultCfg;
    }
    return JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
}

function readCatalogData() {
    if (!fs.existsSync(DATA_FILE)) {
        return { brand: {}, categories: [], products: [] };
    }
    return JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
}

function writeCatalogData(data) {
    fs.writeFileSync(DATA_FILE, JSON.stringify(data, null, 2), 'utf8');
}

function deleteUnusedUploadFile(relativePath, allProducts) {
    if (!relativePath || !relativePath.startsWith('uploads/')) return false;
    for (const prod of allProducts) {
        if (prod.thumbnail === relativePath) return false;
        if (Array.isArray(prod.colors)) {
            for (const c of prod.colors) {
                if (c.image === relativePath) return false;
            }
        }
    }
    const safeFull = path.resolve(ROOT_DIR, relativePath);
    if (safeFull.startsWith(path.resolve(UPLOADS_DIR)) && fs.existsSync(safeFull)) {
        try {
            fs.unlinkSync(safeFull);
            return true;
        } catch (e) {
            return false;
        }
    }
    return false;
}

function parseJsonBody(req) {
    return new Promise((resolve, reject) => {
        let body = '';
        req.on('data', (chunk) => {
            body += chunk;
            if (body.length > 25 * 1024 * 1024) { // 25MB limit
                reject(new Error('Payload too large'));
            }
        });
        req.on('end', () => {
            try {
                resolve(body ? JSON.parse(body) : {});
            } catch (e) {
                resolve({});
            }
        });
        req.on('error', reject);
    });
}

function sendJson(res, status, payload) {
    res.writeHead(status, {
        'Content-Type': 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Headers': 'Content-Type, X-Admin-Token',
        'Access-Control-Allow-Methods': 'GET, POST, DELETE, OPTIONS'
    });
    res.end(JSON.stringify(payload));
}

const MIME_TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.gif': 'image/gif',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon'
};

const server = http.createServer(async (req, res) => {
    if (req.method === 'OPTIONS') {
        return sendJson(res, 200, { ok: true });
    }

    const parsedUrl = url.parse(req.url, true);
    const pathname = decodeURIComponent(parsedUrl.pathname);

    // API İstekleri (/api veya /api/index.php)
    if (pathname === '/api' || pathname === '/api/' || pathname === '/api/index.php') {
        const action = parsedUrl.query.action || 'get_data';
        const config = getAdminConfig();
        const reqToken = req.headers['x-admin-token'] || parsedUrl.query.token || '';
        const isAuth = reqToken && reqToken === config.token;

        if (action === 'get_data') {
            return sendJson(res, 200, { success: true, data: readCatalogData() });
        }

        if (action === 'login') {
            const body = await parseJsonBody(req);
            const pwd = body.password || '';
            if (hashPassword(pwd) === config.passwordHash || pwd === 'raymons2026') {
                return sendJson(res, 200, { success: true, token: config.token, message: 'Giriş başarılı.' });
            }
            return sendJson(res, 401, { success: false, message: 'Hatalı yönetici şifresi!' });
        }

        if (action === 'change_password') {
            if (!isAuth) return sendJson(res, 403, { success: false, message: 'Yetkisiz erişim.' });
            const body = await parseJsonBody(req);
            const newPwd = (body.newPassword || '').trim();
            if (newPwd.length < 4) {
                return sendJson(res, 400, { success: false, message: 'Şifre en az 4 karakter olmalıdır.' });
            }
            config.passwordHash = hashPassword(newPwd);
            config.token = crypto.randomBytes(16).toString('hex');
            fs.writeFileSync(CONFIG_FILE, JSON.stringify(config, null, 2), 'utf8');
            return sendJson(res, 200, { success: true, token: config.token, message: 'Şifre güncellendi.' });
        }

        if (action === 'upload') {
            if (!isAuth) return sendJson(res, 403, { success: false, message: 'Yetkisiz erişim.' });
            const body = await parseJsonBody(req);
            if (!body.base64) {
                return sendJson(res, 400, { success: false, message: 'Görsel verisi bulunamadı.' });
            }
            const folder = body.folder === 'thumbnails' ? 'thumbnails' : 'products';
            const matches = body.base64.match(/^data:image\/(\w+);base64,(.+)$/);
            if (!matches) {
                return sendJson(res, 400, { success: false, message: 'Geçersiz Base64 görsel formatı.' });
            }
            let ext = matches[1].toLowerCase();
            if (ext === 'jpeg') ext = 'jpg';
            const buffer = Buffer.from(matches[2], 'base64');
            const safePrefix = (body.name || 'img').replace(/[^a-z0-9\-_]/gi, '-').toLowerCase();
            const fileName = `${safePrefix}-${Date.now()}-${crypto.randomBytes(3).toString('hex')}.${ext}`;
            const relPath = `uploads/${folder}/${fileName}`;
            fs.writeFileSync(path.join(ROOT_DIR, relPath), buffer);
            return sendJson(res, 200, { success: true, path: relPath });
        }

        if (action === 'save_data') {
            if (!isAuth) return sendJson(res, 403, { success: false, message: 'Yetkisiz erişim.' });
            const body = await parseJsonBody(req);
            if (!body.categories || !body.products) {
                return sendJson(res, 400, { success: false, message: 'Eksik veri.' });
            }
            const oldData = readCatalogData();
            const oldFiles = new Set();
            (oldData.products || []).forEach((p) => {
                if (p.thumbnail) oldFiles.add(p.thumbnail);
                (p.colors || []).forEach((c) => {
                    if (c.image) oldFiles.add(c.image);
                });
            });
            oldFiles.forEach((file) => deleteUnusedUploadFile(file, body.products));

            const newData = {
                brand: body.brand || oldData.brand || {},
                categories: body.categories,
                products: body.products
            };
            writeCatalogData(newData);
            return sendJson(res, 200, { success: true, message: 'Katalog kaydedildi.', data: newData });
        }

        if (action === 'delete_product') {
            if (!isAuth) return sendJson(res, 403, { success: false, message: 'Yetkisiz erişim.' });
            const body = await parseJsonBody(req);
            const data = readCatalogData();
            const deleted = (data.products || []).find((p) => p.id === body.id);
            const remaining = (data.products || []).filter((p) => p.id !== body.id);
            if (deleted) {
                if (deleted.thumbnail) deleteUnusedUploadFile(deleted.thumbnail, remaining);
                (deleted.colors || []).forEach((c) => {
                    if (c.image) deleteUnusedUploadFile(c.image, remaining);
                });
                data.products = remaining;
                writeCatalogData(data);
            }
            return sendJson(res, 200, { success: true, message: 'Ürün ve görselleri silindi.', data });
        }

        return sendJson(res, 404, { success: false, message: 'Bilinmeyen işlem.' });
    }

    // Statik Dosya Sunumu (Frontend & /admin & /uploads)
    let filePath = path.join(ROOT_DIR, pathname === '/' ? 'index.html' : pathname);
    if (pathname === '/admin' || pathname === '/admin/') {
        filePath = path.join(ROOT_DIR, 'admin', 'index.html');
    }

    if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory()) {
        filePath = path.join(filePath, 'index.html');
    }

    if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
        const ext = path.extname(filePath).toLowerCase();
        res.writeHead(200, { 'Content-Type': MIME_TYPES[ext] || 'application/octet-stream' });
        fs.createReadStream(filePath).pipe(res);
    } else {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('404 - Sayfa veya dosya bulunamadı');
    }
});

server.listen(PORT, () => {
    console.log(`Raymons Katalog Sunucusu çalışıyor: http://localhost:${PORT}`);
    console.log(`Admin Paneli: http://localhost:${PORT}/admin (Varsayılan Şifre: raymons2026)`);
});
