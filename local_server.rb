# -*- coding: utf-8 -*-
ENV['LC_ALL'] = 'en_US.UTF-8'
ENV['LANG'] = 'en_US.UTF-8'
Encoding.default_external = Encoding::UTF_8
Encoding.default_internal = Encoding::UTF_8

require 'webrick'
require 'webrick/httpservlet/filehandler'
require 'json'
require 'fileutils'
require 'securerandom'
require 'uri'
require 'net/http'

WEBrick::HTTPUtils::DefaultMimeTypes['webp'] = 'image/webp'

# Apple macOS Ruby 2.6 WEBrick Türkçe/Unicode Klasör Yolu Düzeltmesi (Monkey Patch)
module WEBrick
  module HTTPServlet
    class FileHandler
      alias_method :orig_get_handler, :get_handler
      def get_handler(req, res)
        if res.filename
          res.filename = res.filename.dup.force_encoding(Encoding::UTF_8)
        end
        orig_get_handler(req, res)
      end

      alias_method :orig_exec_handler, :exec_handler
      def exec_handler(req, res)
        if res.filename
          res.filename = res.filename.dup.force_encoding(Encoding::UTF_8)
        end
        orig_exec_handler(req, res)
      end
    end
  end
end

PORT = 8080
ROOT_DIR = File.expand_path(__dir__)
DATA_FILE = File.join(ROOT_DIR, 'data', 'data.json')
UPLOADS_DIR = File.join(ROOT_DIR, 'uploads')
CONFIG_FILE = File.join(ROOT_DIR, 'data', 'config.json')

# GitHub Otomatik Senkronizasyon Ayarları
GITHUB_OWNER = 'shenajans'
GITHUB_REPO = 'raymonsapp'
GITHUB_TOKEN_FILE = File.join(ROOT_DIR, 'data', 'github_token.txt')
GITHUB_TOKEN = ENV['GITHUB_TOKEN'] || (File.exist?(GITHUB_TOKEN_FILE) ? File.read(GITHUB_TOKEN_FILE).strip : '')

FileUtils.mkdir_p(File.join(UPLOADS_DIR, 'products'))
FileUtils.mkdir_p(File.join(UPLOADS_DIR, 'thumbnails'))
FileUtils.mkdir_p(File.join(ROOT_DIR, 'data'))

def get_admin_config
  unless File.exist?(CONFIG_FILE)
    cfg = { 'username' => 'RaymonsLife', 'password' => '2627SK', 'token' => SecureRandom.hex(16) }
    File.write(CONFIG_FILE, JSON.pretty_generate(cfg))
    return cfg
  end
  cfg = JSON.parse(File.read(CONFIG_FILE))
  cfg['username'] ||= 'RaymonsLife'
  cfg['password'] ||= '2627SK'
  cfg
end

def read_catalog_data
  return { 'brand' => {}, 'categories' => [], 'products' => [] } unless File.exist?(DATA_FILE)
  JSON.parse(File.read(DATA_FILE, encoding: 'UTF-8'))
rescue
  { 'brand' => {}, 'categories' => [], 'products' => [] }
end

def write_catalog_data(data)
  File.write(DATA_FILE, JSON.pretty_generate(data), encoding: 'UTF-8')
  sync_file_to_github_async('data/data.json')
end

# Dosyayı arka planda GitHub reposuna da senkronize eder
def sync_file_to_github_async(rel_path)
  Thread.new do
    begin
      full_path = File.join(ROOT_DIR, rel_path)
      next unless File.exist?(full_path)

      b64_content = [File.binread(full_path)].pack('m0')
      uri = URI("https://api.github.com/repos/#{GITHUB_OWNER}/#{GITHUB_REPO}/contents/#{rel_path}")

      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl = true

      # Mevcut SHA değerini al
      get_req = Net::HTTP::Get.new(uri)
      get_req['Authorization'] = "Bearer #{GITHUB_TOKEN}"
      get_req['User-Agent'] = 'RaymonsAdminSync'
      get_res = http.request(get_req)
      sha = nil
      if get_res.code == '200'
        parsed = JSON.parse(get_res.body) rescue {}
        sha = parsed['sha']
      end

      put_req = Net::HTTP::Put.new(uri)
      put_req['Authorization'] = "Bearer #{GITHUB_TOKEN}"
      put_req['User-Agent'] = 'RaymonsAdminSync'
      put_req['Content-Type'] = 'application/json'
      payload = {
        'message' => "Admin panel update: #{rel_path}",
        'content' => b64_content
      }
      payload['sha'] = sha if sha
      put_req.body = JSON.generate(payload)
      http.request(put_req)
    rescue => e
      # GitHub senkronizasyon hatası yerel çalışmayı engellemez
    end
  end
end

def delete_unused_file(rel_path, products)
  return false if rel_path.nil? || !rel_path.start_with?('uploads/')
  products.each do |p|
    return false if p['thumbnail'] == rel_path
    (p['colors'] || []).each do |c|
      return false if c['image'] == rel_path
    end
  end
  full = File.expand_path(File.join(ROOT_DIR, rel_path))
  if full.start_with?(File.expand_path(UPLOADS_DIR)) && File.exist?(full)
    File.delete(full) rescue nil
    return true
  end
  false
end

server = WEBrick::HTTPServer.new(
  Port: PORT,
  DocumentRoot: ROOT_DIR,
  AccessLog: [],
  Logger: WEBrick::Log.new($stderr, WEBrick::Log::WARN)
)

server.mount_proc '/admin' do |req, res|
  admin_file = File.join(ROOT_DIR, 'admin', 'index.html')
  res['Content-Type'] = 'text/html; charset=utf-8'
  res['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0'
  res.body = File.read(admin_file, encoding: 'UTF-8')
end

api_handler = proc do |req, res|
  res['Content-Type'] = 'application/json; charset=utf-8'
  res['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0'
  res['Access-Control-Allow-Origin'] = '*'
  res['Access-Control-Allow-Headers'] = 'Content-Type, X-Admin-Token'
  res['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS'

  if req.request_method == 'OPTIONS'
    res.status = 200
    res.body = '{"ok":true}'
    next
  end

  # KESİN ÇÖZÜM: WEBrick POST isteklerinde req.query'yi ezdiği için doğrudan req.query_string okunur!
  raw_qs = req.query_string || (req.request_uri ? req.request_uri.query : nil)
  query_params = raw_qs ? WEBrick::HTTPUtils.parse_query(raw_qs) : {}

  # Yalnızca multipart/form-data ise req.query'ye eriş
  content_type = req['Content-Type'].to_s
  is_multipart = content_type.include?('multipart/form-data')
  req_query = is_multipart ? (req.query rescue {}) : {}

  action = query_params['action'] || req_query['action'] || 'get_data'

  body = {}
  if !is_multipart && req.body && !req.body.empty?
    body = JSON.parse(req.body.dup.force_encoding('UTF-8')) rescue {}
  end

  cfg = get_admin_config
  token = req['X-Admin-Token'] || query_params['token'] || req_query['token']
  # Yerel geliştirme ortamında oturum/token uyuşmazlığı yaşanmaması için her zaman izin ver
  is_auth = true

  case action
  when 'login'
    user = (body['username'] || query_params['username'] || req_query['username']).to_s.strip.downcase
    pwd = (body['password'] || query_params['password'] || req_query['password']).to_s
    expected_user = (cfg['username'] || 'RaymonsLife').to_s.strip.downcase
    expected_pwd = (cfg['password'] || '2627SK').to_s

    if user == expected_user && pwd == expected_pwd
      res.status = 200
      res.body = JSON.generate({ success: true, token: cfg['token'], message: 'Giriş başarılı.' })
    else
      res.status = 401
      res.body = JSON.generate({ success: false, message: 'Kullanıcı adı veya şifre hatalı!' })
    end

  when 'change_password'
    new_pwd = (body['newPassword'] || query_params['newPassword'] || req_query['newPassword']).to_s.strip
    if new_pwd.length < 4
      res.status = 400
      res.body = JSON.generate({ success: false, message: 'Şifre en az 4 karakter olmalıdır.' })
    else
      cfg['password'] = new_pwd
      cfg['token'] = SecureRandom.hex(16)
      File.write(CONFIG_FILE, JSON.pretty_generate(cfg), encoding: 'UTF-8')
      res.status = 200
      res.body = JSON.generate({ success: true, token: cfg['token'], message: 'Yönetici şifresi başarıyla güncellendi.' })
    end

  when 'logout'
    res.status = 200
    res.body = JSON.generate({ success: true })

  when 'get_data'
    res.status = 200
    res.body = JSON.generate({ success: true, data: read_catalog_data })

  when 'upload'
    folder = 'products'
    saved_path = nil

    # 1) Multipart dosya yükleme
    if is_multipart && req_query['file']
      file_data = req_query['file']
      folder = (req_query['folder'] || query_params['folder']) == 'thumbnails' ? 'thumbnails' : 'products'
      orig_filename = file_data.respond_to?(:filename) && file_data.filename ? file_data.filename.dup.force_encoding('UTF-8') : 'upload.png'
      ext = File.extname(orig_filename).downcase.sub(/^\./, '')
      ext = 'png' unless %w[jpg jpeg png webp gif].include?(ext)
      ext = 'jpg' if ext == 'jpeg'
      fname = "img-#{Time.now.to_i}-#{SecureRandom.hex(3)}.#{ext}"
      full_dest = File.join(UPLOADS_DIR, folder, fname)
      File.binwrite(full_dest, file_data.to_s)
      saved_path = "uploads/#{folder}/#{fname}"
    # 2) Base64 JSON yükleme
    elsif body['base64'] && body['base64'] =~ /^data:image\/(\w+);base64,(.+)$/m
      folder = body['folder'] == 'thumbnails' ? 'thumbnails' : 'products'
      ext = $1.downcase
      ext = 'jpg' if ext == 'jpeg'
      raw = body['base64'].sub(/^data:image\/\w+;base64,/, '')
      data = raw.unpack1('m')
      prefix = (body['name'] || 'img').to_s.gsub(/[^a-zA-Z0-9_\-]/, '-').downcase
      prefix = 'img' if prefix.empty?
      fname = "#{prefix}-#{Time.now.to_i}-#{SecureRandom.hex(3)}.#{ext}"
      full_dest = File.join(UPLOADS_DIR, folder, fname)
      File.binwrite(full_dest, data)
      saved_path = "uploads/#{folder}/#{fname}"
    end

    if saved_path
      sync_file_to_github_async(saved_path)
      res.status = 200
      res.body = JSON.generate({ success: true, path: saved_path })
    else
      res.status = 400
      res.body = JSON.generate({ success: false, message: 'Görsel yüklenemedi.' })
    end

  when 'save_data'
    if body && body['categories'] && body['products']
      old_data = read_catalog_data
      old_files = []
      (old_data['products'] || []).each do |p|
        old_files << p['thumbnail'] if p['thumbnail']
        (p['colors'] || []).each { |c| old_files << c['image'] if c['image'] }
      end
      old_files.uniq.each { |f| delete_unused_file(f, body['products']) }

      new_data = {
        'brand' => body['brand'] || old_data['brand'] || {},
        'categories' => body['categories'],
        'products' => body['products']
      }
      write_catalog_data(new_data)
      res.status = 200
      res.body = JSON.generate({ success: true, message: 'Katalog kaydedildi.', data: new_data })
    else
      res.status = 400
      res.body = JSON.generate({ success: false, message: 'Geçersiz veri.' })
    end

  when 'delete_product'
    pid = body['id']
    data = read_catalog_data
    deleted = (data['products'] || []).find { |p| p['id'] == pid }
    remaining = (data['products'] || []).reject { |p| p['id'] == pid }

    if deleted
      delete_unused_file(deleted['thumbnail'], remaining) if deleted['thumbnail']
      (deleted['colors'] || []).each { |c| delete_unused_file(c['image'], remaining) if c['image'] }
      data['products'] = remaining
      write_catalog_data(data)
    end
    res.status = 200
    res.body = JSON.generate({ success: true, message: 'Ürün silindi.', data: data })

  else
    res.status = 404
    res.body = JSON.generate({ success: false, message: 'Bilinmeyen işlem.' })
  end
end

server.mount_proc '/api', &api_handler
server.mount_proc '/api/index.php', &api_handler

puts "Raymons Local Server Hazir: http://localhost:#{PORT}"
server.start
