/**
 * Makkah Smart School - Service Worker (Offline First Engine)
 * خادم الخدمة للعمل الكامل أوف لاين بدون اتصال بالإنترنت
 */

const CACHE_NAME = 'makkah-offline-cache-v2';

const STATIC_ASSETS = [
  './',
  './index.html',
  './fees.html',
  './my_finance.html',
  './teachers_finance.html',
  './default_classes.js',
  './offline_storage_engine.js',
  './manifest.json',
  './logo.png',
  './makkah-logo.png',
  './school_logo.ico',
  './icon-192.png',
  './icon-512.png',
  './icon-maskable-192.png',
  './icon-maskable-512.png',
  'https://cdn.tailwindcss.com',
  'https://unpkg.com/lucide@latest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[SW] جاري حفظ الملفات الأساسية للعمل أوف لاين...');
      return cache.addAll(STATIC_ASSETS.filter(url => !url.startsWith('http')));
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((k) => {
          if (k !== CACHE_NAME) {
            console.log('[SW] مسح الذاكرة القديمة:', k);
            return caches.delete(k);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  // لا نعترض طلبات API السحابية كـ Firebase أو الذكاء الاصطناعي
  if (event.request.url.includes('firebaseio.com') || 
      event.request.url.includes('generativelanguage.googleapis.com') ||
      event.request.url.includes('firestore.googleapis.com')) {
    return;
  }

  // إستراتيجية Network First مع العودة للكاش أوف لاين
  event.respondWith(
    fetch(event.request)
      .then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200 && event.request.method === 'GET') {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }
        return networkResponse;
      })
      .catch(() => {
        // في حال انقطاع الإنترنت، يتم جلب الصفحة من الكاش المحلي أوف لاين فوراً
        return caches.match(event.request).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse;
          }
          // إذا كان الطلب لصفحة HTML ولم توجد بالكاش، نرجع الصفحة الرئيسية
          if (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html')) {
            return caches.match('./index.html');
          }
        });
      })
  );
});
