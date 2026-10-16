/**
 * Makkah Smart School - Enterprise Offline Storage Engine
 * محرك التخزين المحلي والبيانات الضخمة أوف لاين - مدارس مكة الأهلية الحديثة
 *
 * حل جذري لمشكلة محدودية المساحة أوف لاين (5MB LocalStorage Quota Limit):
 * 1. يعتمد على IndexedDB لتوفير سعة تخزينية عملاقة تصل إلى عشرات الجيجابايت (GBs) على جهاز المعلم/المدرسة أوف لاين.
 * 2. يمنع انهيار النظام عند امتلاء LocalStorage (Safe Quota Handling).
 * 3. يطلب تثبيت التخزين الدائم (Persistent Storage) لحماية البيانات من الحذف التلقائي من قبل المتصفح.
 * 4. يتيح مراقبة حية للمساحة المتبقية والمستخدمة عبر navigator.storage.estimate.
 */

(function (window) {
  'use strict';

  const DB_NAME = 'MakkahSmartSchoolOfflineDB';
  const DB_VERSION = 1;
  const STORE_NAME = 'school_kv_store';

  let dbPromise = null;

  function openDatabase() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve, reject) => {
      if (!window.indexedDB) {
        console.warn('[OfflineStorage] IndexedDB غير مدعوم في هذا المتصفح. سيتم الاعتماد على LocalStorage.');
        return resolve(null);
      }

      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onupgradeneeded = function (event) {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          db.createObjectStore(STORE_NAME, { keyPath: 'key' });
        }
      };

      request.onsuccess = function (event) {
        resolve(event.target.result);
      };

      request.onerror = function (event) {
        console.error('[OfflineStorage] فشل فتح قاعدة بيانات IndexedDB:', event.target.error);
        resolve(null);
      };
    });

    return dbPromise;
  }

  const MakkahOfflineEngine = {
    // 1. حفظ مفتاح وقيمة في IndexedDB (لا يوجد حد 5 ميجا، يدعم مئات الميجابايت والجيجابايت)
    async setItem(key, value) {
      try {
        const db = await openDatabase();
        if (!db) return false;

        return new Promise((resolve, reject) => {
          const transaction = db.transaction([STORE_NAME], 'readwrite');
          const store = transaction.objectStore(STORE_NAME);
          const req = store.put({ key: key, value: value, updatedAt: Date.now() });

          req.onsuccess = () => resolve(true);
          req.onerror = (e) => {
            console.error('[OfflineStorage] خطأ أثناء الحفظ في IndexedDB:', e.target.error);
            resolve(false);
          };
        });
      } catch (err) {
        console.error('[OfflineStorage] Exception in setItem:', err);
        return false;
      }
    },

    // 2. استرجاع مفتاح وقيمة من IndexedDB
    async getItem(key) {
      try {
        const db = await openDatabase();
        if (!db) return null;

        return new Promise((resolve) => {
          const transaction = db.transaction([STORE_NAME], 'readonly');
          const store = transaction.objectStore(STORE_NAME);
          const req = store.get(key);

          req.onsuccess = () => {
            if (req.result && req.result.value !== undefined) {
              resolve(req.result.value);
            } else {
              resolve(null);
            }
          };

          req.onerror = () => resolve(null);
        });
      } catch (err) {
        console.error('[OfflineStorage] Exception in getItem:', err);
        return null;
      }
    },

    // 3. حذف مفتاح من IndexedDB
    async removeItem(key) {
      try {
        const db = await openDatabase();
        if (!db) return false;

        return new Promise((resolve) => {
          const transaction = db.transaction([STORE_NAME], 'readwrite');
          const store = transaction.objectStore(STORE_NAME);
          const req = store.delete(key);
          req.onsuccess = () => resolve(true);
          req.onerror = () => resolve(false);
        });
      } catch (err) {
        return false;
      }
    },

    // 4. الحفظ الآمن المشترك (Safe Hybrid Save):
    // يحفظ في LocalStorage مع حماية من انهيار الـ QuotaExceededError، ويحفظ تزامناً في IndexedDB العملاق
    safeLocalStorageSet(key, valueString) {
      // حفظ متزامن في IndexedDB
      this.setItem(key, valueString).catch(err => {
        console.warn('[OfflineStorage] تعذر الحفظ في IndexedDB:', err);
      });

      // حفظ في LocalStorage مع اعتراض الخطأ إن زادت البيانات عن 5 ميجابايت
      try {
        localStorage.setItem(key, valueString);
        return true;
      } catch (err) {
        if (err.name === 'QuotaExceededError' || err.code === 22 || err.code === 1014) {
          console.warn(`[OfflineStorage] تنبيه: تم تجاوز حد LocalStorage (5MB). تم الحفظ بأمان في قاعدة بيانات IndexedDB العملاقة للمفتاح: ${key}`);
          // نترك المفتاح في IndexedDB بحيث لا يفقد المستخدم أي بيانات إطلاقاً
          return false;
        } else {
          console.error('[OfflineStorage] خطأ أثناء الحفظ في LocalStorage:', err);
          return false;
        }
      }
    },

    // 5. استرجاع آمن هجين (يبحث في LocalStorage أولاً، فإن لم يجد أو كانت البيانات ضخمة يجلبها من IndexedDB)
    async safeGetItem(key) {
      const localVal = localStorage.getItem(key);
      if (localVal !== null) return localVal;

      // محاولة الجلب من IndexedDB
      const idbVal = await this.getItem(key);
      if (idbVal !== null) {
        // إعادة حفظ نسخة مخففة في LocalStorage إن أمكن
        try {
          localStorage.setItem(key, idbVal);
        } catch (e) { /* تجاهل خطأ المساحة */ }
        return idbVal;
      }
      return null;
    },

    // 6. تقدير مساحة التخزين المستهلكة والمتاحة للمدرسة
    async getStorageEstimate() {
      if (navigator.storage && navigator.storage.estimate) {
        try {
          const estimate = await navigator.storage.estimate();
          const usageMB = (estimate.usage / (1024 * 1024)).toFixed(2);
          const quotaGB = (estimate.quota / (1024 * 1024 * 1024)).toFixed(2);
          const percent = ((estimate.usage / estimate.quota) * 100).toFixed(2);

          let isPersisted = false;
          if (navigator.storage.persisted) {
            isPersisted = await navigator.storage.persisted();
          }

          return {
            supported: true,
            usageBytes: estimate.usage,
            quotaBytes: estimate.quota,
            usageMB: parseFloat(usageMB),
            quotaGB: parseFloat(quotaGB),
            percent: parseFloat(percent),
            isPersisted: isPersisted
          };
        } catch (e) {
          console.warn('[OfflineStorage] يتعذر حساب مساحة التخزين:', e);
        }
      }

      // حساب تقريبي لـ LocalStorage
      let totalBytes = 0;
      for (let x in localStorage) {
        if (localStorage.hasOwnProperty(x)) {
          totalBytes += ((localStorage[x].length + x.length) * 2);
        }
      }
      const usageMB = (totalBytes / (1024 * 1024)).toFixed(2);
      return {
        supported: false,
        usageMB: parseFloat(usageMB),
        quotaGB: 0.05, // 50MB
        percent: ((totalBytes / (50 * 1024 * 1024)) * 100).toFixed(2),
        isPersisted: false
      };
    },

    // 7. طلب الحفظ الدائم من المتصفح (Persistent Storage)
    async requestPersistence() {
      if (navigator.storage && navigator.storage.persist) {
        try {
          const isPersisted = await navigator.storage.persist();
          console.log(`[OfflineStorage] حالة التخزين الدائم: ${isPersisted ? 'مفعل ومحمي 🔒' : 'عادي'}`);
          return isPersisted;
        } catch (e) {
          console.warn('[OfflineStorage] تعذر طلب التخزين الدائم:', e);
          return false;
        }
      }
      return false;
    },

    // 8. المزامنة التلقائية لجميع بيانات المدرسة إلى IndexedDB عند بدء التشغيل
    async autoMigrateLocalStorage() {
      const keysToMigrate = [
        'makkah_schools_classes_v2027',
        'makkah_schools_students_db_v2027',
        'makkah_schools_teachers_db_v2027',
        'makkah_schools_oral_grades_v1',
        'makkah_schools_oral_audit_logs_v1',
        'makkah_schools_saved_lessons_v1',
        'makkah_student_lesson_chats_v1'
      ];

      for (const k of keysToMigrate) {
        try {
          const val = localStorage.getItem(k);
          if (val) {
            await this.setItem(k, val);
          } else {
            // إذا كان المفتاح غير موجود في LocalStorage لكن موجود في IndexedDB، نستعيده
            const idbVal = await this.getItem(k);
            if (idbVal) {
              try {
                localStorage.setItem(k, idbVal);
              } catch (e) {
                console.warn(`[OfflineStorage] المفتاح ${k} محفوظ في IndexedDB ولا يمكن نسخه لـ LocalStorage لكبر حجمه.`);
              }
            }
          }
        } catch (e) {
          console.error(`[OfflineStorage] خطأ أثناء مزامنة المفتاح ${k}:`, e);
        }
      }
      console.log('[OfflineStorage] اكتمل فحص ومزامنة البيانات مع IndexedDB بنجاح ✅');
    }
  };

  // تشغيل التهيئة التلقائية
  openDatabase().then(() => {
    MakkahOfflineEngine.autoMigrateLocalStorage();
    MakkahOfflineEngine.requestPersistence();
  });

  window.MakkahOfflineEngine = MakkahOfflineEngine;
})(window);
