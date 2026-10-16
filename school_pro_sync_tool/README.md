# 🎓 أداة سكول برو لتصدير SQLite والمزامنة السحابية (School Pro Sync & Export Tool)

أداة سطح مكتب تم تطويرها لربط برنامج الإدارة المدرسية **School Pro (سكول برو)** مع تطبيقات الهواتف الذكية (Mobile Apps) للطلبة وأولياء الأمور.

---

## 🚀 كيفية تشغيل الأداة
يمكنك تشغيل الأداة مباشرة وبضغطة زر واحدة:
* اضغط نقراً مزدوجاً على الملف: **`تشغيل_أداة_سكول_برو.bat`** (الموجود في المجلد الرئيسي أو داخل مجلد الأداة).
* أو عبر موجه الأوامر (Terminal):
  ```cmd
  cd "C:\Users\LENOVO\.gemini\antigravity\scratch\school_pro_sync_tool"
  run_tool.bat
  ```

---

## 🌟 مميزات الأداة

### 1. 💾 تصدير قاعدة بيانات SQLite لتطبيق الموبايل (Offline Mobile DB)
- استخراج بيانات الطلاب، الحسابات المالية، وسندات القبض، والدرجات من قاعدة بيانات سكول برو الأصلية (`sdb.mdf`).
- إنشاء قاعدة بيانات SQLite حديثة مفهرسة وجاهزة للعمل داخل تطبيقات **Flutter** أو **React Native** بدون إنترنت.
- إمكانية تحديد عدد الطلاب المراد تصديرهم (50، 100، 200، 500، أو جميع الطلاب).
- شريط تقدم مباشر وسجل تفصيلي للأحداث.

### 2. 🌐 مزامنة سحابية مباشرة عبر الـ Backend API (Cloud Live Sync)
- إرسال البيانات المجمعة من سكول برو إلى الخادم السحابي للتطبيق عبر طلبات `HTTP POST` بصيغة JSON.
- إمكانية تحديد رابط الـ Endpoint ومفتاح المصادقة (`Bearer Token` / `API Key`).
- دعم الإرسال المجزأ على دفعات (`Batches`) لتجنب الضغط على السيرفر (مثلاً 25 طالب في كل دفعة).
- زر فحص اتصال سريع للتحقق من جهوزية الخادم (`Test Connection`).
- **زر معاينة وحفظ حمولة JSON التجريبية (`Mock Payload`)**: يتيح استخراج ملف JSON كامل لتقديمه لمطوري الـ Backend أو اختباره في Postman قبل إطلاق السيرفر الفعلي.

### 3. 👥 استعراض كشوفات الطلاب داخل الأداة
- جدول تفاعلي بالطلاب المصدرين وأرصدتهم المالية وحالة السداد.
- ميزة البحث السريع بالاسم أو رقم القيد.
- النقر المزدوج على أي طالب يفتح **بطاقة تفصيلية** تحتوي على:
  - سجل الحركات المالية التفصيلية وأرقام السندات والتواريخ.
  - شهادة الدرجات لجميع المواد موزعة حسب الفصل الأول والثاني والتقديرات.

---

## 📡 مواصفات واجهة الـ API البرمجية (Backend Contract)

عند بناء خادم الـ API الخاص بتطبيق الموبايل (Laravel / Node.js / Django / ASP.NET)، يجب أن يطابق المعايير التالية:

### 1. نقطة التحقق من الصحة (Health Check):
- **Method:** `GET`
- **URL:** `https://your-api.com/api/v1/sync`
- **Header:** `Authorization: Bearer <API_KEY>`
- **Response:** `200 OK`

### 2. نقطة استلام ومزامنة البيانات (Sync Endpoint):
- **Method:** `POST`
- **URL:** `https://your-api.com/api/v1/sync`
- **Headers:**
  ```http
  Content-Type: application/json; charset=utf-8
  Authorization: Bearer <API_KEY>
  ```
- **هيكل حمولة الـ JSON (Payload Structure):**
  ```json
  {
    "sync_timestamp": "2026-09-29T12:00:00.000",
    "batch_index": 1,
    "total_batches": 4,
    "batch_size": 25,
    "school_info": {
      "school_id": "0076",
      "school_name": "مدارس مكة الأهلية الحديثة",
      "address": "اب قحزة - خلف فندق رافال",
      "phone": "770467894-777222938-04-531548"
    },
    "students": [
      {
        "student_info": {
          "student_id": 352,
          "student_code": "0352014013",
          "full_name": "اسم الطالب الكامل",
          "current_class_name": "الثالث ثانوي علمي",
          "branch_name": "أ",
          "gender": "ذكر",
          "status": "مستمر"
        },
        "account_summary": {
          "total_fees": 95000.0,
          "total_paid": 95000.0,
          "remaining_balance": 0.0,
          "payment_status": "خالص"
        },
        "financial_transactions": [
          {
            "trans_id": 733,
            "trans_type": "استحقاق رسوم",
            "fee_type_name": "رسوم دراسيه",
            "amount": 95000.0,
            "receipt_no": "0",
            "trans_date": "2020-10-21"
          },
          {
            "trans_id": 734,
            "trans_type": "سداد رسوم / مقبوضات",
            "fee_type_name": "رسوم دراسيه",
            "amount": 10000.0,
            "receipt_no": "358",
            "trans_date": "2020-10-21"
          }
        ],
        "grades": [
          {
            "subject_name": "القرآن الكريم",
            "term_name": "الفصل الأول",
            "term_work_score": 20.0,
            "final_exam_score": 30.0,
            "total_score": 50.0,
            "max_score": 100.0,
            "grade_estimate": "مقبول"
          }
        ]
      }
    ]
  }
  ```

---

## 🛠️ هيكل ملفات الأداة
```text
school_pro_sync_tool/
│
├── main.py                     # الواجهة الرسومية الرئيسية (Tkinter GUI)
├── db_service.py               # خدمة الاتصال بـ SQL Server وبناء قاعدة SQLite
├── api_service.py              # خدمة تجهيز وإرسال دفعات البيانات للـ API
├── mock_api_server.py          # سيرفر تجريبي محلي لاختبار المزامنة قبل إطلاق الـ Backend
├── run_tool.bat                # مشغل الأداة لنظام ويندوز
├── config.json                 # ملف حفظ الإعدادات تلقائياً
└── README.md                   # دليل الاستخدام والتوثيق
```
