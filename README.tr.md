# ImageMan

**Profesyonel Laravel görsel yükleme, işleme ve çoklu disk yönetim paketi.**

[![Son Sürüm](https://img.shields.io/packagist/v/ibrahim-kaya/imageman.svg?style=flat-square)](https://packagist.org/packages/ibrahim-kaya/imageman)
[![Toplam İndirme](https://img.shields.io/packagist/dt/ibrahim-kaya/imageman.svg?style=flat-square)](https://packagist.org/packages/ibrahim-kaya/imageman)
[![Testler](https://img.shields.io/github/actions/workflow/status/ibrahim-kaya/ImageMan/tests.yml?label=tests&style=flat-square)](https://github.com/ibrahim-kaya/ImageMan/actions)
[![Lisans](https://img.shields.io/packagist/l/ibrahim-kaya/imageman.svg?style=flat-square)](LICENSE)

> 🇬🇧 [For English documentation see README.md](README.md)

---

## Özellikler

- **WebP & AVIF dönüştürme** — Yüklenen her görseli otomatik olarak WebP veya AVIF formatına çevirir
- **Çoklu boyut varyantları** — Thumbnail, medium, large ve özel boyut ön tanımlarını tek seferde üretir
- **Çoklu disk desteği** — `local`, `s3`, `ftp`, `sftp`, GCS ve diğer Flysystem sürücüleri arasında kolayca geçiş yapın
- **Tekrar eden görsel tespiti** — SHA-256 hash ile yinelenen dosyaları yakala; `reuse`, `throw` veya `allow` modları
- **Filigran (Watermark)** — Logo veya metin filigranlarını konum ve opaklık ayarlarıyla uygula
- **LQIP yer tutucuları** — Yumuşak lazy-loading geçişleri için base64 bulanık yer tutucu üret
- **EXIF temizleme** — GPS koordinatları ve cihaz bilgilerini kaldırarak kullanıcı gizliliğini koru
- **CDN entegrasyonu** — Imgix, Cloudinary, ImageKit ve Cloudflare Images için hazır URL üreticiler
- **Olay sistemi** — `ImageUploaded`, `ImageProcessed`, `ImageDeleted` olaylarıyla reaktif iş akışları
- **Kuyruk desteği** — Hızlı HTTP yanıtları için görsel işlemeyi arka plan kuyruğuna at
- **HasImages trait** — Herhangi bir Eloquent modeline ekleyerek anında yükleme/getirme/silme desteği
- **Artisan komutları** — `imageman:regenerate`, `imageman:clean`, `imageman:convert`
- **Blade direktifleri** — `@image`, `@responsiveImage`, `@lazyImage`
- **API Resource** — JSON API yanıtları için hazır `ImageResource`
- **Filament v3** — Filament yönetim paneli için form bileşeni ve tablo kolonu
- **Laravel Nova** — Nova yönetim paneli için özel field
- **Akıcı API** — Okunabilir zincirleme metodlar

---

## Gereksinimler

| Bağımlılık | Sürüm |
|---|---|
| PHP | ^8.1 |
| Laravel | ^10.0 \| ^11.0 |
| Intervention Image | ^3.0 |

Opsiyonel (yönetim paneli entegrasyonları):
- `filament/filament` ^3.0
- `laravel/nova` ^4.0

---

## Kurulum

**1. Composer ile yükleyin:**

```bash
composer require ibrahim-kaya/imageman
```

**2. Config dosyasını yayınlayın:**

```bash
php artisan vendor:publish --tag=imageman-config
```

**3. Migration'ı yayınlayın ve çalıştırın:**

```bash
php artisan vendor:publish --tag=imageman-migrations
php artisan migrate
```

Veritabanınızda `imageman_images` tablosu oluşturulacaktır.

---

## Yapılandırma

Yayınlandıktan sonra `config/imageman.php` dosyasını düzenleyin. Her seçenek dosya içinde satır içi yorum satırıyla açıklanmıştır. Temel ayarlar:

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `disk` | `local` | Varsayılan disk (env: `IMAGEMAN_DISK`) |
| `path` | `images` | Depolama klasörü (env: `IMAGEMAN_PATH`) |
| `format` | `webp` | Çıktı formatı: `webp`, `avif`, `jpeg`, `original` |
| `webp_quality` | `80` | WebP kodlama kalitesi (1–100) |
| `avif_quality` | `70` | AVIF kodlama kalitesi (1–100) |
| `default_sizes` | `['thumbnail','medium']` | Her yüklemede otomatik üretilecek boyutlar |
| `detect_duplicates` | `true` | SHA-256 ile yinelenen görsel tespiti |
| `on_duplicate` | `reuse` | `reuse` / `throw` / `allow` |
| `queue` | `false` | Görsel işlemeyi kuyruğa at |
| `url_generator` | `default` | `default`, `imgix`, `cloudinary`, `imagekit`, `cloudflare` |
| `generate_lqip` | `true` | Bulanık yer tutucu data URI üret |
| `strip_exif` | `true` | Gizlilik için EXIF verisini temizle |

---

## Temel Kullanım

### Görsel yükleme

```php
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;

// HTTP isteğinden
$image = ImageMan::upload($request->file('photo'))->save();

// Uzak URL'den
$image = ImageMan::uploadFromUrl('https://example.com/photo.jpg')->save();

echo $image->url('medium');     // Medium varyant için genel URL
echo $image->url('thumbnail');  // Thumbnail URL
echo $image->url();             // Ana görsel URL
```

### Getirme ve silme

```php
$image = ImageMan::find(1);      // ?Image döner
$image = ImageMan::get(1);       // Image döner, bulunamazsa ImageNotFoundException fırlatır

ImageMan::destroy(1);            // DB kaydını ve disk dosyalarını siler
$image->delete();                // Aynısı, model üzerinden
```

---

### URL'den Yükleme

`uploadFromUrl()` metodu uzak görseli indirir ve normal yüklemeyle aynı işlem hattından geçirir (WebP dönüştürme, boyutlandırma, filigran vb.):

```php
// Temel kullanım
$image = ImageMan::uploadFromUrl('https://example.com/photo.jpg')->save();

// Tüm akıcı seçeneklerle
$image = ImageMan::uploadFromUrl('https://cdn.example.com/banner.png', timeoutSeconds: 60)
    ->disk('s3')
    ->collection('uzak')
    ->sizes(['thumbnail', 'medium', 'large'])
    ->format('avif')
    ->watermark()
    ->meta(['alt' => 'Uzak banner'])
    ->save();

// HasImages trait üzerinden — UploadedFile yerine URL string'i geçin
$post->uploadImage('https://example.com/photo.jpg', 'galeri');
$user->uploadImage('https://example.com/avatar.png', 'avatarlar', ['disk' => 's3', 'timeout' => 45]);
```

**Hata yönetimi:**

```php
use IbrahimKaya\ImageMan\Exceptions\UrlFetchException;

try {
    $image = ImageMan::uploadFromUrl($url)->save();
} catch (UrlFetchException $e) {
    // URL erişilemez, 2xx dışı yanıt veya yanıt görsel değil
    Log::error('Uzak görsel indirilemedi', ['url' => $url, 'sebep' => $e->getMessage()]);
}
```

---

## Akıcı API Referansı

`save()` dışındaki tüm metodlar `$this` döndürür (zincir yapısı).

| Metod | Açıklama |
|---|---|
| `->disk('s3')` | Depolama diskini değiştir |
| `->collection('galeri')` | Koleksiyon adını belirle |
| `->sizes(['thumbnail','large'])` | Hangi boyut ön tanımlarının üretileceğini seç |
| `->withOriginal()` | Orijinal dosyayı da sakla |
| `->for($model)` | Eloquent modelle ilişkilendir |
| `->meta(['alt' => '…'])` | Meta veri ekle (alt metin, başlık vb.) |
| `->format('avif')` | Bu yükleme için çıktı formatını geçersiz kıl |
| `->filename('fotograf')` | Özel dosya adı (slugify edilir, uzantı gerekmez) |
| `->inDirectory('urunler')` | Özel bir alt klasöre kaydet |
| `->noUuid()` | UUID klasörünü kaldır, sabit ve öngörülebilir yol oluştur |
| `->watermark()` | Filigranı etkinleştir |
| `->noWatermark()` | Bu yükleme için filigranı devre dışı bırak |
| `->watermarkImage($path)` | Belirli bir görsel dosyasını filigran olarak kullan |
| `->watermarkText('© 2024')` | Metin filigranı kullan |
| `->withLqip()` | LQIP üretimini etkinleştir |
| `->withoutLqip()` | LQIP üretimini devre dışı bırak |
| `->replaceExisting()` | Kaydettikten sonra koleksiyondaki eski görselleri sil |
| `->keepExisting()` | Koleksiyon tekil olsa bile eski görselleri koru |
| `->maxSize(2048)` | Maksimum dosya boyutu (KB) |
| `->minWidth(400)` | Minimum genişlik (px) |
| `->maxWidth(2000)` | Maksimum genişlik (px) |
| `->aspectRatio('16/9')` | En-boy oranı zorunlu kıl |
| `->save()` | İşlemi çalıştır ve `Image` modeli döndür |

---

## Boyut Varyantları

`config/imageman.php` dosyasında adlandırılmış ön tanımlar belirleyin:

```php
'sizes' => [
    'thumbnail' => ['width' => 150,  'height' => 150,  'fit' => 'cover'],
    'medium'    => ['width' => 800,  'height' => 600,  'fit' => 'contain'],
    'large'     => ['width' => 1920, 'height' => 1080, 'fit' => 'contain'],
    'hero'      => ['width' => 2560, 'height' => 1440, 'fit' => 'cover'],
],
```

Adıyla erişin:

```php
$image->url('thumbnail');   // Thumbnail URL
$image->url('hero');        // Hero URL
$image->path('medium');     // Depolama yolu
$image->variants();         // ['thumbnail' => ['path' => …, 'width' => 150, …], …]
$image->srcset();           // "url 150w, url 800w, url 1920w"
```

**`fit` seçenekleri:**
- `cover` — Tam boyutu doldurmak için kırpar (köşeler kesilebilir)
- `contain` — Orantıyı koruyarak sığdırır, boşluk bırakabilir
- `fill` / `stretch` — Tam boyuta uzatır (orantı bozulabilir)

---

## Disk Yönetimi

Yapılandırma değiştirmeden yükleme başına disk seçin:

```php
// S3'e yükle
$image = ImageMan::upload($file)->disk('s3')->save();

// FTP'ye yükle
$image = ImageMan::upload($file)->disk('ftp')->save();

// Bir sonraki yükleme için disk geçersiz kılma
ImageMan::disk('s3')->upload($file)->save();
```

Hedef diskin `config/filesystems.php` dosyasında tanımlı olduğundan emin olun.

---

## Özel Dosya Adı ve Klasör

### Özel dosya adı

Kaydedilen dosyanın adını belirleyin. Uzantıyı yazmaya gerek yok — çıktı formatına göre otomatik eklenir:

```php
$gorsel = ImageMan::upload($dosya)
    ->filename('urun-hero')
    ->save();
// → images/{uuid}/urun-hero.webp
// → images/{uuid}/urun-hero_thumbnail.webp
```

Türkçe karakterler ve boşluklar otomatik olarak slug'a çevrilir:

```php
->filename('Ürün Fotoğrafı')  // → urun-fotografı
```

### Özel klasör

Görselleri temel yolu değiştirmeden özel bir alt klasöre gruplayın:

```php
$gorsel = ImageMan::upload($dosya)
    ->inDirectory('urunler/telefonlar')
    ->filename('iphone-16')
    ->save();
// → images/urunler/telefonlar/{uuid}/iphone-16.webp
```

Her klasör segmenti otomatik olarak slugify edilir:

```php
->inDirectory('Ürün Görselleri')  // → urun-gorselleri
```

### `->noUuid()` ile sabit yol

Varsayılan olarak her yükleme çakışmaları önlemek için UUID klasörü altında saklanır. `->noUuid()` çağrısıyla bu klasörü kaldırabilir, her zaman aynı URL'de kalan sabit bir yol elde edebilirsiniz — profil fotoğrafı ve kapak görseli gibi senaryolar için idealdir:

```php
$gorsel = ImageMan::upload($dosya)
    ->inDirectory('kullanicilar/' . $kullanici->id)
    ->filename('avatar')
    ->noUuid()
    ->save();
// → images/kullanicilar/42/avatar.webp  (her zaman aynı URL)
```

> **Not:** `noUuid()` kullanıldığında, aynı yola tekrar yükleme yapılırsa mevcut dosya **sessizce üzerine yazılır**. Eski veritabanı kaydını da temizlemek için `->replaceExisting()` veya singleton koleksiyonlarla birlikte kullanın.

### Yol kombinasyonları

| `inDirectory()` | `noUuid()` | `filename()` | Sonuç |
|---|---|---|---|
| ✗ | ✗ | ✗ | `images/{uuid}/{uuid}.webp` |
| `'urunler'` | ✗ | ✗ | `images/urunler/{uuid}/{uuid}.webp` |
| `'urunler'` | ✗ | `'iphone'` | `images/urunler/{uuid}/iphone.webp` |
| `'kullanicilar/42'` | ✓ | `'avatar'` | `images/kullanicilar/42/avatar.webp` |
| `'urunler'` | ✓ | ✗ | `images/urunler/{uuid}.webp` |

---

## WebP & AVIF Dönüştürme

`.env` veya config dosyasında global olarak değiştirin:

```env
IMAGEMAN_DISK=s3
```

```php
// config/imageman.php
'format' => 'avif',   // Her şeyi AVIF'e çevir
```

Yükleme başına:

```php
$image = ImageMan::upload($file)->format('avif')->save();
$image = ImageMan::upload($file)->format('original')->save(); // Dönüştürme yapma
```

---

## Filigran (Watermark)

### Global config

Tüm yüklemeler için varsayılan filigranı `config/imageman.php` içinde ayarlayın:

```php
'watermark' => [
    'enabled'  => true,
    'type'     => 'image',                          // 'image' veya 'text'
    'path'     => storage_path('app/watermark.png'),
    'text'     => null,
    'position' => 'bottom-right',                   // Konum
    'opacity'  => 50,                               // Opaklık (0–100)
    'padding'  => 15,                               // Kenardan uzaklık (px)
],
```

### Tek yükleme: görsel filigran

Config dosyasına dokunmadan tek bir yükleme için filigran görselini değiştirin:

```php
$gorsel = ImageMan::upload($dosya)
    ->watermarkImage(storage_path('app/logo.png'))
    ->save();

// Tüm seçeneklerle:
$gorsel = ImageMan::upload($dosya)
    ->watermarkImage(
        path:     storage_path('app/logo.png'),
        position: 'bottom-right', // top-left | top-center | top-right
                                   // center-left | center | center-right
                                   // bottom-left | bottom-center | bottom-right
        opacity:  40,              // 0 (görünmez) – 100 (tam opak)
        padding:  15,              // Kenardan uzaklık (px)
    )
    ->save();
```

### Tek yükleme: metin filigran

```php
$gorsel = ImageMan::upload($dosya)
    ->watermarkText('© 2024 Şirketim')
    ->save();

// Tüm seçeneklerle:
$gorsel = ImageMan::upload($dosya)
    ->watermarkText(
        text:     '© 2024 Şirketim',
        position: 'bottom-center',
        opacity:  70,
        padding:  12,
    )
    ->save();
```

### Tek yükleme için aç / kapat

```php
// Yalnızca bu yükleme için etkinleştir (config'deki path/text kullanılır)
$gorsel = ImageMan::upload($dosya)->watermark()->save();

// Yalnızca bu yükleme için devre dışı bırak (config'de enabled: true olsa bile)
$gorsel = ImageMan::upload($dosya)->noWatermark()->save();
```

### Öncelik kuralları

| Çağrılan metod | Sonuç |
|---|---|
| `->watermarkImage($path)` | Filigranı açar, verilen görseli kullanır |
| `->watermarkText($text)` | Filigranı açar, verilen metni kullanır |
| `->watermark()` | Filigranı açar, config'deki tip/yol/metin korunur |
| `->noWatermark()` | Bu yükleme için filigranı kapatır |
| *(hiçbiri)* | Tamamen `config/imageman.php` ayarına göre davranır |

---

## Tekrar Eden Görsel Tespiti

Aynı dosya iki kez yüklendiğinde ImageMan SHA-256 hash kontrolü yapar:

```php
// config/imageman.php
'detect_duplicates' => true,
'on_duplicate'      => 'reuse',  // 'reuse' | 'throw' | 'allow'
```

| Mod | Davranış |
|---|---|
| `reuse` | Mevcut Image modelini döndürür. Yeni dosya depolanmaz. |
| `throw` | Mevcut görsele referansla `DuplicateImageException` fırlatır. |
| `allow` | Her zaman yeni kayıt oluşturur. |

İstisnayı yakalayın:

```php
use IbrahimKaya\ImageMan\Exceptions\DuplicateImageException;

try {
    $image = ImageMan::upload($file)->save();
} catch (DuplicateImageException $e) {
    $mevcut = $e->existingImage();
    return response()->json(['mesaj' => 'Tekrar eden görsel', 'gorsel' => $mevcut]);
}
```

---

## Tekil Koleksiyonlar (Singleton Collections)

Bir **tekil koleksiyon**, model örneği başına yalnızca bir görsel barındırır. Bu koleksiyona yeni bir görsel yüklendiğinde, aynı modeldeki önceki görseller yeni görsel başarıyla kaydedildikten **sonra** otomatik olarak silinir — bu sayede geçiş sırasında model hiçbir zaman görselsiz kalmaz.

### Seçenek 1 — Config tabanlı (her zaman tekil)

Koleksiyon adlarını `config/imageman.php` dosyasına ekleyin:

```php
'singleton_collections' => ['profile_pic', 'avatar', 'cover'],
```

Bu koleksiyonlara yapılan her yükleme öncekini otomatik olarak değiştirir — ekstra metot çağrısı gerekmez:

```php
// İlk yükleme
$user->uploadImage($request->file('photo'), 'profile_pic');

// İkinci yükleme — bu kaydedildikten sonra ilk görsel otomatik silinir
$user->uploadImage($request->file('photo'), 'profile_pic');
```

### Seçenek 2 — `->replaceExisting()` ile tek seferlik

Fluent metodu kullanarak yalnızca tek bir yükleme için etkinleştirin:

```php
$gorsel = ImageMan::upload($dosya)
    ->for($kullanici)
    ->collection('avatar')
    ->replaceExisting()
    ->save();
```

### Seçenek 3 — `HasImages` trait ile `replace` seçeneği

```php
// Açık değiştirme
$user->uploadImage($dosya, 'profile_pic', ['replace' => true]);

// Açık koruma (profile_pic singleton_collections'da olsa bile)
$user->uploadImage($dosya, 'profile_pic', ['replace' => false]);
```

### Geçersiz kılma: Tekil koleksiyonda birden fazla görsel tutmak

Config'de listelenmiş olsa bile otomatik değiştirmeyi devre dışı bırakmak için `->keepExisting()` kullanın:

```php
// profile_pic singleton_collections'da ama bu sefer ikisini de tutmak istiyoruz
$gorsel = ImageMan::upload($dosya)
    ->for($kullanici)
    ->collection('profile_pic')
    ->keepExisting()
    ->save();
```

### Nasıl çalışır

| Senaryo | Sonuç |
|---|---|
| `replaceExisting()` + model atanmış | Koleksiyondaki eski görseller yeni kayıt sonrası silinir |
| `keepExisting()` | Config ne olursa olsun eski görseller korunur |
| `replaceExisting()` + **model yok** | Sessizce görmezden gelinir (silinecek kapsam yok) |
| Aynı modelde farklı koleksiyon | Dokunulmaz |
| Farklı model örneğinde aynı koleksiyon | Dokunulmaz |

> **Güvenlik garantisi:** Eski görseller yalnızca yeni görsel tamamen diske yazıldıktan ve veritabanı kaydı işlendikten *sonra* silinir. İşleme bu noktadan önce başarısız olursa eski görsel sağlam kalır.

---

## LQIP & Lazy Loading

Bulanık yer tutucuya erişin:

```php
$image->lqip();  // "data:image/webp;base64,/9j/4AAQ…"
```

`@lazyImage` direktifi ile Blade şablonunda kullanın:

```blade
@lazyImage($post->image->id, 'large', ['class' => 'lazyload w-full'])
```

Çıktı:

```html
<img
    src="data:image/webp;base64,…"
    data-src="https://…/images/abc.webp"
    class="lazyload w-full"
    loading="lazy"
    alt="…"
>
```

Bulanıktan-keskin geçişi için front-end'e [lazysizes](https://github.com/aFarkas/lazysizes) ekleyin.

---

## EXIF Temizleme

Varsayılan olarak etkin. Depolama öncesinde GPS koordinatları, cihaz modeli ve tüm EXIF meta verilerini kaldırır:

```php
// config/imageman.php
'strip_exif' => true,
```

---

## Yükleme Doğrulaması

Config'de global kurallar belirleyin:

```php
'validation' => [
    'max_size'      => 10240,   // 10 MB
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
],
```

Yükleme başına akıcı şekilde uygulayın:

```php
use IbrahimKaya\ImageMan\Exceptions\ValidationException;

try {
    $image = ImageMan::upload($request->file('photo'))
        ->maxSize(5120)         // Maksimum 5 MB
        ->minWidth(400)         // En az 400px genişlik
        ->maxWidth(4096)
        ->aspectRatio('16/9')   // Geniş ekran zorunlu
        ->save();
} catch (ValidationException $e) {
    return back()->withErrors($e->errors());
}
```

---

## Model Entegrasyonu (HasImages Trait)

Herhangi bir Eloquent modeline trait'i ekleyin:

```php
use IbrahimKaya\ImageMan\Traits\HasImages;

class User extends Model
{
    use HasImages;
}

class Post extends Model
{
    use HasImages;
}
```

### Kullanılabilir metodlar

```php
// Yükleme
$user->uploadImage($request->file('avatar'), 'avatarlar');
$post->uploadImage($request->file('foto'), 'galeri', ['sizes' => ['thumbnail', 'large']]);

// Getirme
$user->getImage('avatarlar');        // → ?Image (en son)
$post->getImages('galeri');          // → Collection<Image>
$post->getAllImages();               // → Koleksiyon adına göre gruplu Collection
$user->hasImage('avatarlar');        // → bool

// Eloquent ilişkileri
$user->images;                       // Tüm görseller (morphMany)
$user->image;                        // En son varsayılan görsel (morphOne)

// Silme
$post->deleteImages('galeri');       // Tüm galeri görsellerini + dosyaları sil
$post->deleteImages('*');            // Tüm koleksiyonları sil
```

---

## Olay Sistemi

`EventServiceProvider` dosyanızda ImageMan olaylarını dinleyin:

```php
use IbrahimKaya\ImageMan\Events\ImageUploaded;
use IbrahimKaya\ImageMan\Events\ImageProcessed;
use IbrahimKaya\ImageMan\Events\ImageDeleted;

protected $listen = [
    ImageUploaded::class  => [YuklemebildirimiGonder::class],
    ImageProcessed::class => [CdnOnbellegiIsit::class],
    ImageDeleted::class   => [AramaTemizle::class],
];
```

Olay verileri:

```php
// ImageUploaded
$event->image;   // Image modeli
$event->model;   // İlişkili Eloquent modeli (veya null)

// ImageProcessed
$event->image;    // Image modeli (varyantlar dolu)
$event->variants; // ['thumbnail' => ['path' => …], …]

// ImageDeleted
$event->imageId;  // Eski birincil anahtar
$event->disk;     // Disk adı
$event->paths;    // Silinen dosya yolları dizisi
```

---

## Kuyruk İşleme

Hızlı HTTP yanıtları için arka plan işlemeyi etkinleştirin:

```php
// config/imageman.php
'queue'            => true,
'queue_connection' => 'redis',
'queue_name'       => 'images',
```

Worker'ı başlatın:

```bash
php artisan queue:work --queue=images
```

Kuyruk etkin olduğunda `save()` hemen DB kaydı oluşturur ve `ProcessImageJob`'u kuyruğa atar. Varyantlar iş tamamlandığında hazır olur — ne zaman hazır olduklarını öğrenmek için `ImageProcessed` olayını dinleyin.

---

## Parçalı Yükleme (Chunk Upload)

PHP'nin `upload_max_filesize` sınırından büyük dosyaları, tarayıcıda küçük parçalara bölüp sunucuya göndererek yükleyin. Tüm parçalar birleştirildikten sonra standart ImageUploader pipeline'ından geçer (WebP/AVIF dönüşümü, boyut varyantları, watermark, LQIP, kuyruk vb.).

### Chunk rotalarını etkinleştirme

Chunk rotaları varsayılan olarak etkindir (`config('imageman.chunks.enabled') = true`). Chunk desteği açıkken her zaman yüklenir — `register_routes` ayarından bağımsızdır.

Chunk endpoint'lerine kimlik doğrulama middleware eklemek için:

```php
// config/imageman.php
'chunks' => [
    'enabled'    => true,
    'middleware' => ['auth:sanctum'],
    // ...
],
```

### JavaScript Yardımcısı

Paketlenmiş `ImageManUploader` sınıfını `public/` dizinine yayınlayın:

```bash
php artisan vendor:publish --tag=imageman-js
# → public/vendor/imageman/imageman-uploader.js
```

Dosya UMD formatındadır — düz `<script>` etiketi, ES modülü veya CommonJS `require()` olarak kullanılabilir.

#### Script etiketi

```html
<script src="/vendor/imageman/imageman-uploader.js"></script>
<script>
const uploader = new ImageManUploader({
    endpoint:    '/imageman/chunks',
    collection:  'galeri',
    chunkSize:   2 * 1024 * 1024,   // Parça başına 2 MB (opsiyonel)
    concurrency: 3,                  // Paralel parça yükleme sayısı
    headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
    onProgress: (pct) => console.log(pct + '%'),
    onComplete: (uploadId, imageId) => console.log('Tamamlandı! image_id:', imageId),
    onError:    (err)  => console.error('Yükleme başarısız:', err),
});

document.querySelector('input[type=file]').addEventListener('change', (e) => {
    uploader.upload(e.target.files[0]);
});
</script>
```

#### ES modülü (Vite / webpack)

```js
import ImageManUploader from '/vendor/imageman/imageman-uploader.js';

const uploader = new ImageManUploader({ endpoint: '/imageman/chunks', ... });
```

#### CommonJS (Node / eski paket yöneticileri)

```js
const ImageManUploader = require('./public/vendor/imageman/imageman-uploader');
```

### Kaldığı yerden devam etme (Resume)

Yükleme başlarken dönen `upload_id`'yi `localStorage`'a kaydedin. Sayfa yenilense veya tarayıcı kapansa bile eksik parçalar yeniden gönderilir:

```js
uploader.resume(savedUploadId, file);
```

### Yüklemeyi iptal etme

```js
uploader.abort(); // DELETE /imageman/chunks/{id} gönderir ve dosyaları temizler
```

### HTTP API referansı

| Metod    | URL                                  | Açıklama                                |
|----------|--------------------------------------|-----------------------------------------|
| `POST`   | `/imageman/chunks/initiate`          | Yeni oturum başlat, `upload_id` al      |
| `POST`   | `/imageman/chunks/{id}`              | Tek bir parça yükle (multipart)         |
| `GET`    | `/imageman/chunks/{id}/status`       | Birleştirme durumunu sorgula            |
| `DELETE` | `/imageman/chunks/{id}`              | İptal et ve parça dosyalarını sil       |

**Initiate istek gövdesi** (JSON veya form-data):

| Alan            | Tip     | Zorunlu | Açıklama                                              |
|-----------------|---------|---------|-------------------------------------------------------|
| `filename`      | string  | evet    | Orijinal dosya adı                                    |
| `mime_type`     | string  | evet    | MIME tipi (`allowed_mimes` listesinde olmalı)         |
| `total_size`    | integer | evet    | Toplam dosya boyutu (byte)                            |
| `total_chunks`  | integer | evet    | Dosyanın bölündüğü parça sayısı                       |
| `collection`    | string  | hayır   | Hedef koleksiyon (varsayılan: `"default"`)            |
| `disk`          | string  | hayır   | Hedef depolama diski                                  |
| `meta`          | object  | hayır   | İsteğe bağlı üst veri                                |
| `imageable_type`| string  | hayır   | Polimorfik bağlantı için Eloquent model FQCN          |
| `imageable_id`  | integer | hayır   | Eloquent model birincil anahtar                       |

**Durum yanıt alanları:** `status`, `received_chunks`, `missing_chunks`, `total_chunks`, `image_id`, `error_message`.

Durum değerleri: `uploading` → `assembling` → `processing` (kuyruk) → `complete` / `failed`.

### Kuyrukta birleştirme

Config'de `assemble_on_queue` ayarını yapın (veya `imageman.queue`'dan miras alın):

```php
'chunks' => [
    'assemble_on_queue' => true,  // AssembleChunksJob kuyruğa atılır
],
```

JS yardımcısı `status === 'complete'` olana kadar otomatik olarak sorgular.

### Yarıda kalan oturumları temizleme

Parça dosyaları `storage/app/imageman_chunks/{upload_id}/` altında saklanır. Terk edilmiş oturumları temizlemek için:

```bash
php artisan imageman:clean-chunks                 # 24 saatten eski oturumları sil
php artisan imageman:clean-chunks --dry-run       # Sadece önizleme
php artisan imageman:clean-chunks --older-than=48 # Özel TTL (saat)
php artisan imageman:clean-chunks --status=failed # Sadece başarısız oturumlar
```

`App\Console\Kernel`'de günlük çalıştırın:

```php
$schedule->command('imageman:clean-chunks')->daily()->withoutOverlapping();
```

---

## Artisan Komutları

### Varyantları yeniden oluştur

Yeni boyut ön tanımı ekledikten sonra mevcut görseller için çalıştırın:

```bash
php artisan imageman:regenerate
php artisan imageman:regenerate --size=hero            # Sadece 'hero' ön tanımı
php artisan imageman:regenerate --collection=galeri    # Sadece galeri görselleri
php artisan imageman:regenerate --disk=s3              # Sadece S3 görselleri
```

### Sahipsiz görselleri temizle

Herhangi bir modelle ilişkilendirilmemiş görselleri kaldırın:

```bash
php artisan imageman:clean              # Onay sorar
php artisan imageman:clean --dry-run    # Sadece önizleme (silme yok)
php artisan imageman:clean --older-than=30  # 30 günden eski görseller
```

### Toplu format dönüştürme

`config.format` değiştirildikten sonra mevcut görselleri dönüştürün:

```bash
php artisan imageman:convert --format=webp
php artisan imageman:convert --format=avif
php artisan imageman:convert --format=webp --dry-run
```

---

## CDN Entegrasyonu

### Imgix

```php
// config/imageman.php
'url_generator' => 'imgix',
'imgix' => [
    'domain'   => env('IMGIX_DOMAIN'),    // örn. 'sitem.imgix.net'
    'sign_key' => env('IMGIX_SIGN_KEY'),  // opsiyonel
],
```

### Cloudinary

```php
'url_generator' => 'cloudinary',
'cloudinary' => [
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'api_key'    => env('CLOUDINARY_API_KEY'),
    'api_secret' => env('CLOUDINARY_API_SECRET'),
],
```

### ImageKit

```php
'url_generator' => 'imagekit',
'imagekit' => [
    'url_endpoint' => env('IMAGEKIT_URL_ENDPOINT'),
    'public_key'   => env('IMAGEKIT_PUBLIC_KEY'),
    'private_key'  => env('IMAGEKIT_PRIVATE_KEY'),
],
```

### Cloudflare Images

```php
'url_generator' => 'cloudflare',
'cloudflare' => [
    'account_id' => env('CF_IMAGES_ACCOUNT_ID'),
    'api_token'  => env('CF_IMAGES_API_TOKEN'),
],
```

### Özel URL Üretici

`UrlGeneratorContract`'ı implemente edin ve sınıfınıza referans verin:

```php
'url_generator' => \App\ImageMan\OzelUrlUreticim::class,
```

---

## Blade Direktifleri

### @image — Tek varyant

```blade
@image($post->image->id, 'medium', ['class' => 'rounded-xl', 'alt' => 'Gönderi fotoğrafı'])
```

Çıktı:

```html
<img src="https://…/abc.webp" width="800" height="600" class="rounded-xl" alt="Gönderi fotoğrafı" loading="lazy">
```

### @responsiveImage — srcset

```blade
@responsiveImage($post->image->id, [
    'sizes' => '(max-width: 768px) 100vw, 800px',
    'class' => 'w-full',
])
```

Çıktı:

```html
<img
    src="https://…/abc_medium.webp"
    srcset="https://…/abc_thumbnail.webp 150w, https://…/abc_medium.webp 800w, https://…/abc_large.webp 1920w"
    sizes="(max-width: 768px) 100vw, 800px"
    width="800" height="600"
    class="w-full"
    loading="lazy"
    alt="…"
>
```

### @lazyImage — LQIP lazy load

```blade
@lazyImage($post->image->id, 'large', ['class' => 'lazyload'])
```

`src` alanında LQIP yer tutucusu, `data-src` alanında tam görsel URL'si olan bir `<img>` etiketi üretir. [lazysizes](https://github.com/aFarkas/lazysizes) ile birlikte kullanın.

---

## API Resource

Tutarlı JSON yapısı döndürmek için controller'larda kullanın:

```php
use IbrahimKaya\ImageMan\Resources\ImageResource;

// Tek görsel
return ImageResource::make($image);

// Koleksiyon
return ImageResource::collection(Image::all());
```

Örnek JSON çıktısı:

```json
{
    "id": 1,
    "url": "https://cdn.example.com/images/abc.webp",
    "variants": {
        "thumbnail": "https://cdn.example.com/images/abc_thumbnail.webp",
        "medium":    "https://cdn.example.com/images/abc_medium.webp"
    },
    "lqip": "data:image/webp;base64,UklGRk…",
    "srcset": "https://… 150w, https://… 800w",
    "width": 1920,
    "height": 1080,
    "size": 204800,
    "mime_type": "image/webp",
    "original_filename": "hero-foto.jpg",
    "collection": "galeri",
    "disk": "s3",
    "meta": { "alt": "Hero fotoğrafı" },
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
}
```

---

## Filament v3 Entegrasyonu

Panel provider'ınızda eklentiyi kaydedin:

```php
use IbrahimKaya\ImageMan\Integrations\Filament\FilamentImageManPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(FilamentImageManPlugin::make());
}
```

### Form bileşeni

```php
use IbrahimKaya\ImageMan\Integrations\Filament\Forms\ImageManUpload;

ImageManUpload::make('foto')
    ->collection('avatarlar')
    ->disk('s3')
    ->sizes(['thumbnail', 'medium'])
```

### Tablo kolonu

```php
use IbrahimKaya\ImageMan\Integrations\Filament\Tables\ImageManColumn;

ImageManColumn::make('foto')
    ->collection('avatarlar')
    ->size('thumbnail')
    ->circular()
```

---

## Laravel Nova Entegrasyonu

```php
use IbrahimKaya\ImageMan\Integrations\Nova\ImageManField;

public function fields(NovaRequest $request): array
{
    return [
        ImageManField::make('Fotoğraf')
            ->collection('avatarlar')
            ->disk('s3')
            ->size('medium'),
    ];
}
```

---

## Özel Disk Route'ları

Private diskte depolanan görselleri Laravel uygulamanız üzerinden sunmak için opsiyonel route'ları etkinleştirin:

```php
// config/imageman.php
'register_routes'    => true,
'route_prefix'       => 'imageman',
'route_middleware'   => ['auth'],
```

İki endpoint kullanılabilir hale gelir:

| Route | Açıklama |
|---|---|
| `GET /imageman/{id}/{variant?}` | Görseli PHP üzerinden proxy olarak sun |
| `GET /imageman/{id}/{variant?}/sign` | İmzalı geçici URL'ye yönlendir (S3/GCS) |

---

## Geçici İmzalı URL'ler

Private diskler (S3, GCS, Azure Blob) için:

```php
// Config'deki varsayılan TTL (imageman.signed_url_ttl, varsayılan: 60 dakika)
$url = $image->temporaryUrl();

// Özel TTL (dakika)
$url = $image->temporaryUrl(30);

// Belirli varyant
$url = $image->temporaryUrl(15, 'medium');
```

---

## Test

Paket, Orchestra Testbench tabanlı test takımıyla birlikte gelir:

```bash
composer install
./vendor/bin/phpunit
```

### Kendi uygulamanızda test

`Storage::fake()` ve sahte yükleme dosyası kullanın:

```php
use Illuminate\Support\Facades\Storage;
use IbrahimKaya\ImageMan\ImageManFacade as ImageMan;

public function test_kullanici_avatar_yukleyebilir(): void
{
    Storage::fake('s3');

    $kullanici = User::factory()->create();
    $file = \Illuminate\Http\UploadedFile::fake()->image('avatar.jpg', 200, 200);

    $image = ImageMan::upload($file)
        ->for($kullanici)
        ->collection('avatarlar')
        ->disk('s3')
        ->save();

    $this->assertNotNull($image);
    $this->assertSame('avatarlar', $image->collection);
    $this->assertSame('s3', $image->disk);
    Storage::disk('s3')->assertExists($image->directory . '/' . $image->filename);
}
```

---

## Katkıda Bulunma

Pull request'ler memnuniyetle karşılanır. Lütfen:

1. Repository'yi fork'layın ve `main`'den bir branch oluşturun.
2. Değişikliğinizi kapsayan testler yazın.
3. `./vendor/bin/phpunit` komutunun hata vermediğinden emin olun.
4. PSR-12 kod standardını takip edin.
5. Değişikliğin net bir açıklamasıyla pull request gönderin.

---

## Lisans

MIT Lisansı. Detaylar için [LICENSE](LICENSE) dosyasına bakın.
