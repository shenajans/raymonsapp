# raymons.life — Mobil Öncelikli (Mobile-First) İnteraktif Ürün Kataloğu ve Yönetim Paneli

Bu proje, `Mobil TAsarım.png` referans tasarımına birebir sadık kalınarak geliştirilmiş, tek sayfa (**One-Page / SPA**) mimarisinde çalışan mobil öncelikli interaktif ürün kataloğu ve şifre korumalı **Yönetim Paneli (`/admin`)** uygulamasıdır.

## 1. Proje Klasör Yapısı

```text
Raymons APP/
├── index.html                  # Kullanıcı Önyüzü (100dvh, Sabit Üst Görsel + Kaydırılabilir Orta + Sabit Alt Footer)
├── admin/
│   └── index.html              # Şifre Korumalı Yönetim Paneli (/admin)
├── api/
│   └── index.php               # PHP Backend API (Görsel Yükleme/Silme ve data.json Kayıt Servisi)
├── server.js                   # Node.js Backend API (Alternatif Node.js Sunucuları İçin Sıfır Bağımlılıklı Sunucu)
├── package.json                # Node.js proje tanım dosyası
├── .htaccess                   # Apache/LiteSpeed yönlendirme (/admin) ve önbellek/dosya boyutu ayarları
├── .gitignore                  # Git üzerinde gizlenecek güvenlik/sistem dosyaları
├── data/
│   └── data.json               # Kurulum gerektirmeyen JSON Veritabanı (Kategoriler, Ürünler, Renkler)
├── assets/
│   ├── logo-header.png         # Üst sabit alan raymons.life logosu
│   ├── logo-footer.png         # Alt sabit alan marka ikonu
│   └── qr-code.png             # Alt sabit alan Linktree QR kodu
└── uploads/
    ├── products/               # Renk seçeneklerine ait yüksek çözünürlüklü ürün fotoğrafları
    └── thumbnails/             # Ürün kartlarındaki küçük kapak (thumbnail) fotoğrafları
```

---

## 2. Öne Çıkan Özellikler

1. **Sabit Üst Alan (Fixed Top Section):**
   - En üstte ortalanmış `raymons.life` logosu ve hemen altında `9:16` dikey dikdörtgen formatta **Ana Ürün Görseli** yer alır. Orta alan aşağı kaydırılsa dahi üst görsel alanı ekranda sabit kalır.
2. **Kaydırılabilir Orta Alan (Scrollable Content Section):**
   - **Kategori Butonları:** 2 satırlı grid yapısında (`Kazak`, `Mont`, `Ceket`, `Kot/Jeans`, `Pantolon`, `Gömlek`, `Takım`, `Eşofman`, `Yelek`), aktif kategori `#696858` dolgulu, diğerleri açık çerçevelidir.
   - **3 Sütunlu Ürün Kartları:** Solunda küçük ürün görseli (thumbnail), sağında kalın ürün kodu (`S2011`), altında alt başlık (`Sweatshirt Kazak`) ve sağ alt köşede `>>` ikonu yer alır.
   - **Renk Seçenekleri Alanı:** Seçili ürüne ait renk varyasyonları hap (pill) butonlar halinde (solunda yuvarlak hex renk ikonu, sağında renk adı) listelenir. Renge tıklandığında üstteki ana fotoğraf sayfa yenilenmeden yumuşak geçişle (`cross-fade`) güncellenir.
3. **Sabit Alt Alan (Fixed Bottom Footer):**
   - Sol tarafta marka ikonu ve `www.raymonslife.com`, ortada Instagram (`/ raymons.officiall`), sağda QR kod ve `www.linktr.ee/raymonsofficial` sabit olarak yer alır.
4. **Şifre Korumalı Admin Paneli (`/admin`):**
   - Varsayılan ilk kurulum şifresi: `raymons2026` (Panel içerisinden değiştirilebilir).
   - Kategori ekleme, isim düzenleme, yukarı/aşağı sıralama ve silme.
   - Ürün ekleme, düzenleme, kapak fotoğrafı yükleme ve **ürün silindiğinde `/uploads` klasöründeki fotoğraflarını otomatik temizleme**.
   - Sınırsız sayıda **Renk Adı + Renk Kodu (Color Picker) + Ana Ürün Fotoğrafı (Drag & Drop)** eşleştirmesi.

---

## 3. GitHub'a Yükleme ve Kendi Sunucunuza (Domaininize) Taşıma Rehberi

### Adım 1: Projeyi GitHub'a Yükleme
Terminalinizi proje klasöründe açıp aşağıdaki komutları sırasıyla çalıştırın:

```bash
git init
git add .
git commit -m "Raymons.life interaktif katalog ve admin paneli v1.0"
git branch -M main
git remote add origin https://github.com/KULLANICI_ADINIZ/REPO_ADINIZ.git
git push -u origin main
```

### Adım 2: Kendi Sunucunuza (cPanel / Plesk / Linux Hosting veya VPS) Taşıma

#### Seçenek A: PHP Destekli Standart Web Sunucusu (cPanel, Plesk, DirectAdmin - Önerilen)
1. GitHub'daki proje dosyalarınızı sunucunuzun `public_html` (veya `httpdocs`) ana dizinine yükleyin (Git Clone veya ZIP olarak).
2. Görsellerin ve `data.json` dosyasının yazılabilmesi için aşağıdaki iki klasöre **yazma izni (`755` veya `775`)** verildiğinden emin olun:
   - `/uploads` (ve altındaki `/uploads/products`, `/uploads/thumbnails`)
   - `/data` (ve içindeki `/data/data.json`)
3. Tarayıcınızdan `https://alanadiniz.com` adresine girerek kataloğu, `https://alanadiniz.com/admin` adresine girerek yönetim panelini kullanmaya başlayabilirsiniz.
4. İlk girişte `raymons2026` şifresiyle giriş yapıp sol menüdeki **"Admin Şifresini Değiştir"** bölümünden kendi özel şifrenizi belirleyin.

#### Seçenek B: Node.js Sunucusu (VPS / Cloud / PM2)
Eğer sunucunuzda PHP yerine Node.js kullanmak isterseniz:
1. Dosyaları sunucuya çekin ve başlatın (Harici `npm install` paketi bile gerektirmez, yerleşik Node modülleriyle çalışır):
   ```bash
   node server.js
   ```
2. Kalıcı olarak arka planda çalıştırmak için `pm2` kullanabilirsiniz:
   ```bash
   pm2 start server.js --name "raymons-catalog"
   ```
