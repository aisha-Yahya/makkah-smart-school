import os
import sys
import json
import threading
import sqlite3
import urllib.request
import urllib.error
from datetime import datetime

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

import tkinter as tk
from tkinter import ttk, messagebox, filedialog

try:
    from PIL import Image, ImageTk
    HAS_PIL = True
except ImportError:
    HAS_PIL = False

from db_service import SchoolProDbService
from api_service import SchoolProApiService
from firebase_service import SchoolProFirebaseService

CONFIG_FILE = os.path.join(os.path.dirname(__file__), "config.json")
DEFAULT_MDF = r"C:\Users\LENOVO\Desktop\مجلد جديد\قاعدة نظام الفرع الثاني\DB\sdb.mdf"
DEFAULT_SQLITE = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "school_pro_mobile.db"))
DEFAULT_API_URL = "http://127.0.0.1:8000/api/sync.php"
DEFAULT_API_KEY = "makkah_sync_secret_2027"

class SchoolProSyncApp(tk.Tk):
    def __init__(self):
        super().__init__()

        self.title("مدارس مكة الأهلية الحديثة • نظام المزامنة والربط المباشر مع سكول برو")
        self.geometry("1020x760")
        self.minsize(920, 680)

        # Set Window Icons
        self.setup_icons()

        # Config
        self.config_data = self.load_config()

        # Services
        self.db_service = SchoolProDbService(
            mdf_path=self.config_data.get("mdf_path", DEFAULT_MDF),
            server=self.config_data.get("server", r".\SQLEXPRESS")
        )
        self.api_service = SchoolProApiService()
        self.firebase_service = SchoolProFirebaseService()

        # Styling
        self.setup_styles()

        # Build UI
        self.build_ui()

        # Initial Background Checks
        self.after(400, self.initial_background_checks)

    def setup_icons(self):
        ico_path = os.path.join(os.path.dirname(__file__), "school_logo.ico")
        if os.path.exists(ico_path):
            try:
                self.iconbitmap(ico_path)
            except Exception:
                pass

        # Load PhotoImage for header
        self.header_logo_img = None
        if HAS_PIL:
            for fname in ("school_logo.png", "school_logo.jpg"):
                img_path = os.path.join(os.path.dirname(__file__), fname)
                if os.path.exists(img_path):
                    try:
                        pil_img = Image.open(img_path)
                        resample_mode = getattr(Image, "Resampling", Image).LANCZOS if hasattr(Image, "Resampling") else Image.ANTIALIAS
                        pil_img = pil_img.resize((54, 54), resample_mode)
                        self.header_logo_img = ImageTk.PhotoImage(pil_img)
                        break
                    except Exception:
                        pass

    def load_config(self):
        data = {
            "mdf_path": DEFAULT_MDF,
            "server": r".\SQLEXPRESS",
            "sqlite_path": DEFAULT_SQLITE,
            "student_limit": "الكل (All)",
            "api_url": DEFAULT_API_URL,
            "api_key": DEFAULT_API_KEY,
            "batch_size": "25",
            "last_sync_time": "لم تتم بعد"
        }
        if os.path.exists(CONFIG_FILE):
            try:
                with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                    loaded = json.load(f)
                    data.update(loaded)
            except Exception:
                pass
        return data

    def save_config(self):
        self.config_data["mdf_path"] = self.mdf_var.get()
        self.config_data["server"] = self.server_var.get()
        self.config_data["sqlite_path"] = self.sqlite_var.get()
        self.config_data["student_limit"] = self.limit_var.get()
        self.config_data["api_url"] = self.api_url_var.get()
        self.config_data["api_key"] = self.api_key_var.get()
        self.config_data["batch_size"] = self.batch_size_var.get()
        try:
            with open(CONFIG_FILE, "w", encoding="utf-8") as f:
                json.dump(self.config_data, f, ensure_ascii=False, indent=2)
        except Exception:
            pass

    def setup_styles(self):
        self.style = ttk.Style(self)
        self.style.theme_use("clam")

        # Fonts
        self.font_title = ("Segoe UI", 12, "bold")
        self.font_header = ("Segoe UI", 10, "bold")
        self.font_body = ("Segoe UI", 9)
        self.font_mono = ("Consolas", 9)

        # Palette
        self.col_primary = "#0F172A"       # Slate 900
        self.col_brand = "#1E3A8A"         # Deep Navy Blue
        self.col_accent = "#2563EB"        # Royal Blue
        self.col_success = "#059669"       # Emerald Green
        self.col_success_hover = "#047857"
        self.col_bg = "#F8FAFC"            # Slate 50
        self.col_card = "#FFFFFF"
        self.col_border = "#E2E8F0"
        self.col_text = "#0F172A"
        self.col_subtext = "#64748B"

        self.configure(bg=self.col_bg)

        self.style.configure(".", background=self.col_bg, foreground=self.col_text, font=self.font_body)
        self.style.configure("TNotebook", background=self.col_bg, borderwidth=0)
        self.style.configure("TNotebook.Tab", padding=[20, 10], font=("Segoe UI", 10, "bold"), background="#E2E8F0", foreground="#334155")
        self.style.map("TNotebook.Tab",
                       background=[("selected", self.col_brand)],
                       foreground=[("selected", "#FFFFFF")])

        # Treeview styling
        self.style.configure("Treeview.Heading", font=("Segoe UI", 9, "bold"), background="#F1F5F9", foreground=self.col_primary)
        self.style.configure("Treeview", font=("Segoe UI", 9), rowheight=28)
        self.style.map("Treeview", background=[("selected", "#E0E7FF")], foreground=[("selected", "#1E1B4B")])

    def build_ui(self):
        # 1. Top Branded Header Bar
        header_frame = tk.Frame(self, bg=self.col_primary, height=78)
        header_frame.pack(fill="x", side="top")

        # Right side: Logo & School Titles
        right_container = tk.Frame(header_frame, bg=self.col_primary)
        right_container.pack(side="right", padx=18, pady=10)

        # Logo display
        if self.header_logo_img:
            lbl_logo = tk.Label(right_container, image=self.header_logo_img, bg="#FFFFFF", bd=1, relief="solid")
            lbl_logo.pack(side="right", padx=(12, 0))
        else:
            lbl_logo_fallback = tk.Label(right_container, text="🏫", font=("Segoe UI", 24), bg=self.col_primary, fg="#FFFFFF")
            lbl_logo_fallback.pack(side="right", padx=(12, 0))

        title_box = tk.Frame(right_container, bg=self.col_primary)
        title_box.pack(side="right", fill="y", padx=5)

        tk.Label(
            title_box,
            text="مدارس مكة الأهلية الحديثة",
            font=("Segoe UI", 14, "bold"),
            bg=self.col_primary,
            fg="#FFFFFF",
            anchor="e"
        ).pack(anchor="e")

        tk.Label(
            title_box,
            text="أداة المزامنة والربط المباشر مع سكول برو • فرع قحزة (School Pro Live Sync)",
            font=("Segoe UI", 9),
            bg=self.col_primary,
            fg="#94A3B8",
            anchor="e"
        ).pack(anchor="e")

        # Left side: Live Status Badges
        left_container = tk.Frame(header_frame, bg=self.col_primary)
        left_container.pack(side="left", padx=18, pady=16)

        self.lbl_web_status = tk.Label(
            left_container,
            text="المنصة المحلية: جاري الفحص...",
            font=("Segoe UI", 9, "bold"),
            bg="#1E293B",
            fg="#94A3B8",
            padx=10,
            pady=4,
            bd=1,
            relief="solid"
        )
        self.lbl_web_status.pack(side="left", padx=4)

        self.lbl_db_status = tk.Label(
            left_container,
            text="سكول برو: جاري الفحص...",
            font=("Segoe UI", 9, "bold"),
            bg="#1E293B",
            fg="#94A3B8",
            padx=10,
            pady=4,
            bd=1,
            relief="solid"
        )
        self.lbl_db_status.pack(side="left", padx=4)

        # 2. Main Notebook Tabs
        self.notebook = ttk.Notebook(self)
        self.notebook.pack(fill="both", expand=True, padx=15, pady=10)

        self.tab_sync = ttk.Frame(self.notebook, padding=12)
        self.tab_export = ttk.Frame(self.notebook, padding=12)
        self.tab_preview = ttk.Frame(self.notebook, padding=12)
        self.tab_logs = ttk.Frame(self.notebook, padding=12)

        self.notebook.add(self.tab_sync, text=" ⚡ المزامنة الفورية مع المنصة ")
        self.notebook.add(self.tab_export, text=" 💾 تصدير قاعدة بيانات SQLite ")
        self.notebook.add(self.tab_preview, text=" 👥 استعراض وسجل الطلاب المالي ")
        self.notebook.add(self.tab_logs, text=" 📜 سجل العمليات الفني (Logs) ")

        # Build each tab
        self.build_sync_tab()
        self.build_export_tab()
        self.build_preview_tab()
        self.build_logs_tab()

        # 3. Bottom Footer Status Bar
        footer_frame = tk.Frame(self, bg="#E2E8F0", height=34)
        footer_frame.pack(fill="x", side="bottom")

        self.progress_bar = ttk.Progressbar(footer_frame, orient="horizontal", mode="determinate", length=260)
        self.progress_bar.pack(side="left", padx=15, pady=6)

        self.lbl_status = tk.Label(
            footer_frame,
            text="جاهز للعمل بنجاح",
            font=("Segoe UI", 9, "bold"),
            bg="#E2E8F0",
            fg="#1E293B"
        )
        self.lbl_status.pack(side="left", padx=10, pady=6)

        lbl_footer_info = tk.Label(
            footer_frame,
            text="مدارس مكة الأهلية الحديثة • سكول برو لايف 2026-2027م",
            font=("Segoe UI", 8),
            bg="#E2E8F0",
            fg="#64748B"
        )
        lbl_footer_info.pack(side="right", padx=15, pady=6)

    # -------------------------------------------------------------
    # TAB 1: SYNC TO WEB PLATFORM & MOBILE APP
    # -------------------------------------------------------------
    def build_sync_tab(self):
        # Card 1: One-Click Quick Action Hero Banner
        card_hero = tk.LabelFrame(
            self.tab_sync,
            text=" 🚀 المزامنة المباشرة مع المنصة وتطبيق الموبايل ",
            font=self.font_title,
            bg=self.col_card,
            fg=self.col_brand,
            padx=18,
            pady=16
        )
        card_hero.pack(fill="x", pady=6)

        desc_lbl = tk.Label(
            card_hero,
            text="تقوم هذه العملية بقراءة أحدث بيانات الطلاب وحسابات الرسوم وسندات القبض والدرجات من سكول برو،\nوتحديث قاعدة بيانات المنصة السحابية فوراً بنقرة زر واحدة دون أي تعقيد.",
            font=("Segoe UI", 10),
            bg=self.col_card,
            fg="#475569",
            justify="right",
            anchor="e"
        )
        desc_lbl.pack(fill="x", pady=(0, 14))

        hero_actions = tk.Frame(card_hero, bg=self.col_card)
        hero_actions.pack(fill="x")

        self.btn_full_sync = tk.Button(
            hero_actions,
            text="🔄 بدء المزامنة الشاملة الآن (سكول برو ⟷ المنصة)",
            font=("Segoe UI", 12, "bold"),
            bg=self.col_success,
            fg="#FFFFFF",
            activebackground=self.col_success_hover,
            activeforeground="#FFFFFF",
            padx=24,
            pady=12,
            bd=0,
            cursor="hand2",
            relief="flat",
            command=self.start_full_sync_thread
        )
        self.btn_full_sync.pack(side="right", padx=6)

        btn_test_api = tk.Button(
            hero_actions,
            text="⚡ فحص الاتصال بالمنصة",
            font=("Segoe UI", 10, "bold"),
            bg="#F1F5F9",
            fg=self.col_text,
            padx=16,
            pady=11,
            bd=1,
            relief="solid",
            cursor="hand2",
            command=self.test_api_connection
        )
        btn_test_api.pack(side="right", padx=6)

        btn_firebase_sync = tk.Button(
            hero_actions,
            text="☁️ رفع للسحاب أونلاين (Firebase)",
            font=("Segoe UI", 10, "bold"),
            bg="#0284C7",
            fg="#FFFFFF",
            activebackground="#0369A1",
            activeforeground="#FFFFFF",
            padx=16,
            pady=11,
            bd=0,
            cursor="hand2",
            command=self.start_firebase_sync_thread
        )
        btn_firebase_sync.pack(side="right", padx=6)

        btn_export_json = tk.Button(
            hero_actions,
            text="📦 تصدير حمولة JSON (للمعاينة)",
            font=("Segoe UI", 9),
            bg="#F8FAFC",
            fg="#475569",
            padx=14,
            pady=11,
            bd=1,
            relief="solid",
            cursor="hand2",
            command=self.export_json_payload
        )
        btn_export_json.pack(side="right", padx=6)

        # Card 2: Live Sync Statistics Dashboard
        card_metrics = tk.LabelFrame(
            self.tab_sync,
            text=" 📊 مؤشرات وبيانات المزامنة الحالية ",
            font=self.font_header,
            bg=self.col_card,
            fg=self.col_brand,
            padx=15,
            pady=12
        )
        card_metrics.pack(fill="x", pady=6)

        m_grid = tk.Frame(card_metrics, bg=self.col_card)
        m_grid.pack(fill="x", pady=4)

        # Metric 1: Students
        b1 = tk.Frame(m_grid, bg="#EFF6FF", padx=12, pady=10, bd=1, relief="solid")
        b1.pack(side="right", fill="both", expand=True, padx=4)
        tk.Label(b1, text="إجمالي الطلاب المسجلين", font=("Segoe UI", 9, "bold"), bg="#EFF6FF", fg="#1E40AF").pack()
        self.lbl_metric_students = tk.Label(b1, text="1,137 طالب", font=("Segoe UI", 13, "bold"), bg="#EFF6FF", fg="#0F172A")
        self.lbl_metric_students.pack(pady=2)

        # Metric 2: Transactions
        b2 = tk.Frame(m_grid, bg="#ECFDF5", padx=12, pady=10, bd=1, relief="solid")
        b2.pack(side="right", fill="both", expand=True, padx=4)
        tk.Label(b2, text="سندات القبض والحركات", font=("Segoe UI", 9, "bold"), bg="#ECFDF5", fg="#065F46").pack()
        self.lbl_metric_trans = tk.Label(b2, text="14,995 حركة", font=("Segoe UI", 13, "bold"), bg="#ECFDF5", fg="#0F172A")
        self.lbl_metric_trans.pack(pady=2)

        # Metric 3: Grades
        b3 = tk.Frame(m_grid, bg="#F5F3FF", padx=12, pady=10, bd=1, relief="solid")
        b3.pack(side="right", fill="both", expand=True, padx=4)
        tk.Label(b3, text="سجلات الدرجات والمواد", font=("Segoe UI", 9, "bold"), bg="#F5F3FF", fg="#5B21B6").pack()
        self.lbl_metric_grades = tk.Label(b3, text="2,117 نتيجة", font=("Segoe UI", 13, "bold"), bg="#F5F3FF", fg="#0F172A")
        self.lbl_metric_grades.pack(pady=2)

        # Metric 4: Last Sync
        b4 = tk.Frame(m_grid, bg="#F8FAFC", padx=12, pady=10, bd=1, relief="solid")
        b4.pack(side="right", fill="both", expand=True, padx=4)
        tk.Label(b4, text="توقيت آخر مزامنة", font=("Segoe UI", 9, "bold"), bg="#F8FAFC", fg="#475569").pack()
        self.lbl_metric_last_sync = tk.Label(b4, text=self.config_data.get("last_sync_time", "جاهز للمزامنة"), font=("Segoe UI", 11, "bold"), bg="#F8FAFC", fg="#059669")
        self.lbl_metric_last_sync.pack(pady=2)

        # Card 3: Server & API Advanced Connection Settings (Clean, Organized)
        card_api_conf = tk.LabelFrame(
            self.tab_sync,
            text=" ⚙️ إعدادات الربط بالخادم والـ API (Server Endpoint Settings) ",
            font=self.font_header,
            bg=self.col_card,
            fg=self.col_brand,
            padx=15,
            pady=12
        )
        card_api_conf.pack(fill="x", pady=6)

        # Row 1: URL
        r1 = tk.Frame(card_api_conf, bg=self.col_card)
        r1.pack(fill="x", pady=3)
        tk.Label(r1, text="رابط الـ API:", width=22, anchor="e", font=self.font_header, bg=self.col_card).pack(side="right")
        self.api_url_var = tk.StringVar(value=self.config_data.get("api_url", DEFAULT_API_URL))
        tk.Entry(r1, textvariable=self.api_url_var, width=54, font=self.font_body).pack(side="right", padx=6)
        tk.Button(r1, text="الافتراضي", command=lambda: self.api_url_var.set(DEFAULT_API_URL), font=("Segoe UI", 8)).pack(side="right", padx=2)

        # Row 2: Token
        r2 = tk.Frame(card_api_conf, bg=self.col_card)
        r2.pack(fill="x", pady=3)
        tk.Label(r2, text="مفتاح الأمان (API Key):", width=22, anchor="e", font=self.font_header, bg=self.col_card).pack(side="right")
        self.api_key_var = tk.StringVar(value=self.config_data.get("api_key", DEFAULT_API_KEY))
        tk.Entry(r2, textvariable=self.api_key_var, width=54, font=self.font_body).pack(side="right", padx=6)

        # Row 3: Batch size & Quick Presets
        r3 = tk.Frame(card_api_conf, bg=self.col_card)
        r3.pack(fill="x", pady=3)
        tk.Label(r3, text="حجم الحزمة (Batch):", width=22, anchor="e", font=self.font_header, bg=self.col_card).pack(side="right")
        self.batch_size_var = tk.StringVar(value=self.config_data.get("batch_size", "25"))
        ttk.Combobox(r3, textvariable=self.batch_size_var, values=["10", "25", "50", "100"], width=10, state="readonly").pack(side="right", padx=6)
        tk.Label(r3, text="طالب / طلب HTTP", bg=self.col_card, fg="#64748B").pack(side="right", padx=(0, 20))

        tk.Button(r3, text="💾 حفظ الإعدادات", command=self.save_config_with_notice, font=("Segoe UI", 9, "bold"), bg="#E2E8F0").pack(side="right")

    # -------------------------------------------------------------
    # TAB 2: EXPORT TO SQLITE DATABASE
    # -------------------------------------------------------------
    def build_export_tab(self):
        # Step 1: Source MDF
        c1 = tk.LabelFrame(self.tab_export, text=" 1️⃣ مصدر بيانات سكول برو (SQL Server Database) ", font=self.font_header, bg=self.col_card, fg=self.col_brand, padx=15, pady=12)
        c1.pack(fill="x", pady=6)

        r1 = tk.Frame(c1, bg=self.col_card)
        r1.pack(fill="x", pady=3)
        tk.Label(r1, text="ملف سكول برو (MDF):", width=22, anchor="e", font=self.font_header, bg=self.col_card).pack(side="right")
        self.mdf_var = tk.StringVar(value=self.config_data.get("mdf_path", DEFAULT_MDF))
        tk.Entry(r1, textvariable=self.mdf_var, width=56).pack(side="right", padx=6)
        tk.Button(r1, text="تحديد...", command=self.browse_mdf, width=8).pack(side="right")

        r2 = tk.Frame(c1, bg=self.col_card)
        r2.pack(fill="x", pady=3)
        tk.Label(r2, text="السيرفر (Instance):", width=22, anchor="e", font=self.font_header, bg=self.col_card).pack(side="right")
        self.server_var = tk.StringVar(value=self.config_data.get("server", r".\SQLEXPRESS"))
        tk.Entry(r2, textvariable=self.server_var, width=22).pack(side="right", padx=6)
        tk.Button(r2, text="🔍 فحص الاتصال بسكول برو", command=self.test_db_connection, font=("Segoe UI", 9, "bold"), bg="#E2E8F0").pack(side="right", padx=10)

        # Step 2: Destination SQLite
        c2 = tk.LabelFrame(self.tab_export, text=" 2️⃣ خيارات تصدير قاعدة بيانات SQLite للتطبيق ", font=self.font_header, bg=self.col_card, fg=self.col_brand, padx=15, pady=12)
        c2.pack(fill="x", pady=6)

        r3 = tk.Frame(c2, bg=self.col_card)
        r3.pack(fill="x", pady=3)
        tk.Label(r3, text="مسار ملف SQLite الناتج:", width=22, anchor="e", font=self.font_header, bg=self.col_card).pack(side="right")
        self.sqlite_var = tk.StringVar(value=self.config_data.get("sqlite_path", DEFAULT_SQLITE))
        tk.Entry(r3, textvariable=self.sqlite_var, width=56).pack(side="right", padx=6)
        tk.Button(r3, text="تحديد...", command=self.browse_sqlite, width=8).pack(side="right")

        r4 = tk.Frame(c2, bg=self.col_card)
        r4.pack(fill="x", pady=3)
        tk.Label(r4, text="عدد الطلاب للتصدير:", width=22, anchor="e", font=self.font_header, bg=self.col_card).pack(side="right")
        self.limit_var = tk.StringVar(value=self.config_data.get("student_limit", "الكل (All)"))
        limit_cb = ttk.Combobox(r4, textvariable=self.limit_var, values=["الكل (All)", "50", "100", "200", "500"], width=14, state="readonly")
        limit_cb.pack(side="right", padx=6)

        # Action: Export Button
        act_box = tk.Frame(self.tab_export, bg=self.col_bg)
        act_box.pack(fill="x", pady=10)

        self.btn_export = tk.Button(
            act_box,
            text="💾 تصدير قاعدة بيانات SQLite الآن (للموبايل)",
            font=("Segoe UI", 11, "bold"),
            bg=self.col_accent,
            fg="#FFFFFF",
            padx=20,
            pady=10,
            bd=0,
            cursor="hand2",
            command=self.start_export_thread
        )
        self.btn_export.pack(side="right", padx=6)

        # Summary / Output
        self.card_export_stats = tk.LabelFrame(self.tab_export, text=" 📊 تفاصيل ونتائج التصدير ", font=self.font_header, bg=self.col_card, fg=self.col_brand, padx=15, pady=12)
        self.card_export_stats.pack(fill="both", expand=True, pady=6)

        self.lbl_stats_content = tk.Label(
            self.card_export_stats,
            text="جاهز للتصدير. اضغط على الزر أعلاه لتصدير أحدث بيانات سكول برو إلى ملف SQLite مستقل لتطبيق الموبايل.",
            font=self.font_body,
            bg=self.col_card,
            justify="right",
            anchor="ne"
        )
        self.lbl_stats_content.pack(fill="both", expand=True)

    # -------------------------------------------------------------
    # TAB 3: DATA PREVIEW & STUDENT EXPLORER
    # -------------------------------------------------------------
    def build_preview_tab(self):
        ctrl_frame = tk.Frame(self.tab_preview, bg=self.col_card, padx=12, pady=10, bd=1, relief="solid")
        ctrl_frame.pack(fill="x", pady=(0, 8))

        tk.Button(
            ctrl_frame,
            text="🔄 تحديث الجدول",
            font=("Segoe UI", 9, "bold"),
            command=self.load_students_preview,
            bg=self.col_brand,
            fg="#FFFFFF",
            padx=14,
            pady=5,
            bd=0,
            cursor="hand2"
        ).pack(side="right", padx=6)

        tk.Label(ctrl_frame, text="ابحث بالاسم أو رقم القيد:", font=self.font_header, bg=self.col_card).pack(side="right", padx=6)
        self.search_var = tk.StringVar()
        if hasattr(self.search_var, "trace_add"):
            self.search_var.trace_add("write", lambda *args: self.filter_preview())
        else:
            self.search_var.trace("w", lambda *args: self.filter_preview())
        tk.Entry(ctrl_frame, textvariable=self.search_var, width=28, font=self.font_body).pack(side="right", padx=6)

        self.lbl_preview_count = tk.Label(ctrl_frame, text="عدد الطلاب: 0", font=("Segoe UI", 9, "bold"), bg=self.col_card, fg="#64748B")
        self.lbl_preview_count.pack(side="left", padx=10)

        # Treeview
        tree_frame = tk.Frame(self.tab_preview)
        tree_frame.pack(fill="both", expand=True)

        columns = ("id", "code", "name", "class", "fees", "paid", "balance", "status")
        self.tree = ttk.Treeview(tree_frame, columns=columns, show="headings", selectmode="browse")

        self.tree.heading("id", text="م")
        self.tree.heading("code", text="رقم القيد")
        self.tree.heading("name", text="اسم الطالب الرباعي")
        self.tree.heading("class", text="الصف والشعبة")
        self.tree.heading("fees", text="إجمالي الرسوم (ريال)")
        self.tree.heading("paid", text="المسدد (ريال)")
        self.tree.heading("balance", text="المتبقي (ريال)")
        self.tree.heading("status", text="الموقف المالي")

        self.tree.column("id", width=45, anchor="center")
        self.tree.column("code", width=95, anchor="center")
        self.tree.column("name", width=250, anchor="e")
        self.tree.column("class", width=120, anchor="center")
        self.tree.column("fees", width=120, anchor="e")
        self.tree.column("paid", width=120, anchor="e")
        self.tree.column("balance", width=120, anchor="e")
        self.tree.column("status", width=110, anchor="center")

        scrollbar = ttk.Scrollbar(tree_frame, orient="vertical", command=self.tree.yview)
        self.tree.configure(yscrollcommand=scrollbar.set)

        self.tree.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")

        self.tree.bind("<Double-1>", self.on_student_double_click)

    # -------------------------------------------------------------
    # TAB 4: TECHNICAL LOGS
    # -------------------------------------------------------------
    def build_logs_tab(self):
        ctrl = tk.Frame(self.tab_logs, bg=self.col_bg)
        ctrl.pack(fill="x", pady=4)

        tk.Button(ctrl, text="مسح السجل", command=self.clear_logs, width=12, font=("Segoe UI", 9)).pack(side="right", padx=4)
        tk.Button(ctrl, text="نسخ السجل", command=self.copy_logs, width=12, font=("Segoe UI", 9)).pack(side="right", padx=4)

        self.txt_logs = tk.Text(self.tab_logs, font=self.font_mono, bg="#0F172A", fg="#F8FAFC", wrap="word", padx=10, pady=10)
        scroll = ttk.Scrollbar(self.tab_logs, orient="vertical", command=self.txt_logs.yview)
        self.txt_logs.configure(yscrollcommand=scroll.set)

        self.txt_logs.pack(side="left", fill="both", expand=True)
        scroll.pack(side="right", fill="y")

        self.log("تم تشغيل أداة مزامنة مدارس مكة الأهلية الحديثة بنجاح.")

    # -------------------------------------------------------------
    # BACKGROUND CHECKS & LOGIC
    # -------------------------------------------------------------
    def initial_background_checks(self):
        threading.Thread(target=self._check_services_bg, daemon=True).start()
        # Also load preview if sqlite exists
        if os.path.exists(self.sqlite_var.get()):
            self.load_students_preview()

    def _check_services_bg(self):
        # 1. Test SQL Server / MDF
        self.db_service.mdf_path = self.mdf_var.get()
        self.db_service.server = self.server_var.get()
        self.db_service.conn_str = (
            f"Driver={{ODBC Driver 11 for SQL Server}};"
            f"Server={self.db_service.server};"
            f"AttachDbFilename={self.db_service.mdf_path};"
            f"Initial Catalog=SchoolProDB;"
            f"Trusted_Connection=yes;"
            f"User Instance=true;"
        )
        ok_db, msg_db = self.db_service.test_connection()
        if ok_db:
            self.lbl_db_status.config(text="سكول برو: متصل ✅", bg="#059669", fg="#FFFFFF")
            self.log("فحص الاتصال: قاعدة بيانات سكول برو متصلة وجاهزة.")
        else:
            self.lbl_db_status.config(text="سكول برو: غير متصل ❌", bg="#DC2626", fg="#FFFFFF")
            self.log(f"تنبيه فحص الاتصال بسكول برو: {msg_db}")

        # 2. Test Local Web Platform
        url = self.api_url_var.get().strip()
        key = self.api_key_var.get().strip()
        ok_web, msg_web = self.api_service.test_connection(url, key)
        if ok_web:
            self.lbl_web_status.config(text="المنصة المحلية: متصلة 🟢", bg="#059669", fg="#FFFFFF")
            self.log("فحص الاتصال: خادم المنصة المحلية (PHP API) متصل ومستعد.")
        else:
            self.lbl_web_status.config(text="المنصة المحلية: غير متصلة ⚪", bg="#475569", fg="#FFFFFF")

    def log(self, message):
        timestamp = datetime.now().strftime("%H:%M:%S")
        line = f"[{timestamp}] {message}\n"
        if hasattr(self, "txt_logs"):
            self.txt_logs.insert("end", line)
            self.txt_logs.see("end")

    def clear_logs(self):
        self.txt_logs.delete("1.0", "end")

    def copy_logs(self):
        self.clipboard_clear()
        self.clipboard_append(self.txt_logs.get("1.0", "end"))
        messagebox.showinfo("تم النسخ", "تم نسخ سجل العمليات إلى الحافظة بنجاح.")

    def save_config_with_notice(self):
        self.save_config()
        messagebox.showinfo("تم الحفظ", "تم حفظ إعدادات الربط والـ API بنجاح.")

    def browse_mdf(self):
        p = filedialog.askopenfilename(filetypes=[("SQL Server MDF", "*.mdf"), ("All Files", "*.*")])
        if p:
            self.mdf_var.set(p)
            self.save_config()

    def browse_sqlite(self):
        p = filedialog.asksaveasfilename(defaultextension=".db", filetypes=[("SQLite DB", "*.db"), ("All Files", "*.*")])
        if p:
            self.sqlite_var.set(p)
            self.save_config()

    def test_db_connection(self):
        self.lbl_status.config(text="جاري فحص الاتصال بسكول برو...")
        self.save_config()
        self.db_service.mdf_path = self.mdf_var.get()
        self.db_service.server = self.server_var.get()
        self.db_service.conn_str = (
            f"Driver={{ODBC Driver 11 for SQL Server}};"
            f"Server={self.db_service.server};"
            f"AttachDbFilename={self.db_service.mdf_path};"
            f"Initial Catalog=SchoolProDB;"
            f"Trusted_Connection=yes;"
            f"User Instance=true;"
        )
        ok, msg = self.db_service.test_connection()
        if ok:
            messagebox.showinfo("الاتصال ناجح", f"قاعدة بيانات سكول برو متصلة وجاهزة بنجاح:\n{self.mdf_var.get()}")
            self.lbl_db_status.config(text="سكول برو: متصل ✅", bg="#059669", fg="#FFFFFF")
        else:
            messagebox.showerror("فشل الاتصال", msg)
            self.lbl_db_status.config(text="سكول برو: غير متصل ❌", bg="#DC2626", fg="#FFFFFF")
        self.lbl_status.config(text="جاهز للعمل")

    def test_api_connection(self):
        url = self.api_url_var.get().strip()
        key = self.api_key_var.get().strip()
        if not url:
            messagebox.showwarning("تنبيه", "يرجى إدخال رابط الـ API أولاً.")
            return

        self.lbl_status.config(text="جاري فحص اتصال الـ API...")
        ok, msg = self.api_service.test_connection(url, key)
        if ok:
            messagebox.showinfo("حالة خادم المنصة", msg)
            self.lbl_web_status.config(text="المنصة المحلية: متصلة 🟢", bg="#059669", fg="#FFFFFF")
        else:
            messagebox.showerror("خطأ في الاتصال بالمنصة", msg)
            self.lbl_web_status.config(text="المنصة المحلية: غير متصلة ⚪", bg="#475569", fg="#FFFFFF")
        self.lbl_status.config(text="جاهز للعمل")

    def export_json_payload(self):
        sqlite_p = self.sqlite_var.get()
        if not os.path.exists(sqlite_p):
            messagebox.showwarning("تنبيه", "ملف SQLite غير موجود حالياً. يرجى التصدير أولاً.")
            return

        save_p = filedialog.asksaveasfilename(
            defaultextension=".json",
            filetypes=[("JSON Files", "*.json")],
            initialfile="school_pro_sync_payload.json"
        )
        if save_p:
            try:
                cnt = self.api_service.save_payload_to_json(sqlite_p, save_p)
                self.log(f"تم تصدير ملف JSON بنجاح لـ {cnt} طالب إلى: {save_p}")
                messagebox.showinfo("تم التصدير", f"تم تجهيز ملف حمولة الـ API لـ {cnt} طالباً بنجاح:\n{save_p}")
            except Exception as e:
                messagebox.showerror("خطأ", f"تعذر استخراج ملف JSON: {e}")

    # -------------------------------------------------------------
    # FULL AUTOMATED SYNC (EXPORT + SYNC IN ONE CLICK)
    # -------------------------------------------------------------
    def start_full_sync_thread(self):
        url = self.api_url_var.get().strip()
        key = self.api_key_var.get().strip()

        if not url:
            messagebox.showwarning("تنبيه", "يرجى تحديد رابط الـ API للمنصة للبدء بالمزامنة.")
            return

        self.save_config()
        self.btn_full_sync.config(state="disabled")
        self.btn_export.config(state="disabled")

        threading.Thread(target=self._run_full_sync, daemon=True).start()

    def _run_full_sync(self):
        try:
            url = self.api_url_var.get().strip()
            key = self.api_key_var.get().strip()
            target_sqlite = self.sqlite_var.get()
            batch_sz = int(self.batch_size_var.get())

            def on_progress(pct, text):
                self.progress_bar["value"] = pct
                self.lbl_status.config(text=text)

            # Step 1: Export from MDF to SQLite if needed or always to get freshest data
            self.log("🚀 المرحلة 1: استخراج أحدث بيانات الطلاب والحسابات من سكول برو...")
            on_progress(5, "استخراج البيانات من سكول برو...")

            export_res = self.db_service.export_to_sqlite(
                target_sqlite_path=target_sqlite,
                student_limit=0, # All
                progress_callback=lambda p, t: on_progress(int(p * 0.4), t),
                log_callback=self.log
            )

            # Update Metrics
            st_count = export_res['students_count']
            tr_count = export_res['transactions_count']
            gr_count = export_res['grades_count']

            self.lbl_metric_students.config(text=f"{st_count:,} طالب")
            self.lbl_metric_trans.config(text=f"{tr_count:,} حركة")
            self.lbl_metric_grades.config(text=f"{gr_count:,} نتيجة")

            # Step 2: Push to API
            self.log("🌐 المرحلة 2: إرسال البيانات المجمعة إلى واجهة الـ API للمنصة...")
            on_progress(45, "إرسال البيانات إلى المنصة...")

            sync_res = self.api_service.sync_to_api(
                api_url=url,
                api_key=key,
                sqlite_path=target_sqlite,
                batch_size=batch_sz,
                progress_callback=lambda p, t: on_progress(40 + int(p * 0.6), t),
                log_callback=self.log
            )

            now_str = datetime.now().strftime("%Y-%m-%d %I:%M %p")
            self.config_data["last_sync_time"] = now_str
            self.lbl_metric_last_sync.config(text=now_str)
            self.save_config()

            # Refresh table
            self.load_students_preview()

            msg = (
                f"🎉 تمت المزامنة بنجاح تام بنسبة 100%!\n\n"
                f"👥 عدد الطلاب المُزمنين: {sync_res['total_students']} طالباً\n"
                f"💰 الحركات وسندات القبض: {tr_count:,} حركة\n"
                f"🏆 درجات ونتائج المواد: {gr_count:,} نتيجة مادة\n"
                f"📦 الدفعات المرسلة بنجاح: {sync_res['batches_sent']} حزمة\n\n"
                f"البيانات متاحة الآن فوراً في المنصة وصفحة الرسوم وتطبيق الموبايل."
            )
            messagebox.showinfo("اكتملت المزامنة بنجاح", msg)

        except Exception as e:
            self.log(f"خطأ أثناء المزامنة: {str(e)}")
            messagebox.showerror("خطأ في المزامنة", f"حدث خطأ أثناء المزامنة:\n{str(e)}")
        finally:
            self.btn_full_sync.config(state="normal")
            self.btn_export.config(state="normal")
            self.progress_bar["value"] = 0
            self.lbl_status.config(text="جاهز للعمل")

    def start_firebase_sync_thread(self):
        target_sqlite = self.sqlite_var.get()
        if not os.path.exists(target_sqlite):
            messagebox.showwarning("تنبيه", "يرجى إجراء المزامنة المحلية أو التصدير أولاً لتجهيز قاعدة البيانات.")
            return

        self.btn_full_sync.config(state="disabled")
        self.lbl_status.config(text="جاري الرفع إلى Firebase Firestore...")
        threading.Thread(target=self._run_firebase_sync, daemon=True).start()

    def _run_firebase_sync(self):
        try:
            target_sqlite = self.sqlite_var.get()
            def on_progress(pct, text):
                self.progress_bar["value"] = pct
                self.lbl_status.config(text=text)

            res = self.firebase_service.sync_to_firestore(
                sqlite_path=target_sqlite,
                progress_callback=on_progress,
                log_callback=self.log
            )
            messagebox.showinfo("تم الرفع السحابي", f"🎉 تم رفع {res['uploaded_count']} طالباً بنجاح إلى Firebase Firestore!\nيمكن الآن للمنصة والطلاب الوصول للدرجات والرسوم أونلاين.")
        except Exception as e:
            self.log(f"خطأ أثناء الرفع إلى Firebase: {e}")
            messagebox.showerror("خطأ في Firebase", f"حدث خطأ أثناء الرفع السحابي:\n{e}")
        finally:
            self.btn_full_sync.config(state="normal")
            self.progress_bar["value"] = 0
            self.lbl_status.config(text="جاهز للعمل")

    # -------------------------------------------------------------
    # EXPORT TO SQLITE
    # -------------------------------------------------------------
    def start_export_thread(self):
        self.save_config()
        self.btn_export.config(state="disabled")
        self.btn_full_sync.config(state="disabled")
        threading.Thread(target=self._run_export, daemon=True).start()

    def _run_export(self):
        try:
            limit_str = self.limit_var.get()
            limit = 0 if "الكل" in limit_str else int(limit_str)
            target = self.sqlite_var.get()

            def on_progress(pct, text):
                self.progress_bar["value"] = pct
                self.lbl_status.config(text=text)

            result = self.db_service.export_to_sqlite(
                target_sqlite_path=target,
                student_limit=limit,
                progress_callback=on_progress,
                log_callback=self.log
            )

            stats_msg = (
                f"✅ تم اكتمال التصدير بنجاح!\n\n"
                f"📁 مسار الملف: {result['file_path']}\n"
                f"📦 حجم الملف: {result['file_size_kb']:.1f} KB\n\n"
                f"👥 عدد الطلاب المُصدّرين: {result['students_count']} طالب\n"
                f"📝 سجلات القيد والفصول: {result['enrollments_count']} قيد\n"
                f"💰 الحركات المالية والرسوم: {result['transactions_count']} حركة\n"
                f"🧾 سندات وإيصالات القبض: {result['receipts_count']} سند\n"
                f"🏆 درجات ونتائج الاختبارات: {result['grades_count']} نتيجة مادة\n"
            )

            self.lbl_stats_content.config(text=stats_msg)
            messagebox.showinfo("تم التصدير بنجاح", f"تم إنشاء وتحديث قاعدة بيانات SQLite بنجاح:\n{target}")

            self.load_students_preview()

        except Exception as e:
            self.log(f"خطأ أثناء التصدير: {str(e)}")
            messagebox.showerror("خطأ", f"حدث خطأ أثناء التصدير:\n{str(e)}")
        finally:
            self.btn_export.config(state="normal")
            self.btn_full_sync.config(state="normal")
            self.progress_bar["value"] = 0
            self.lbl_status.config(text="جاهز للعمل")

    # -------------------------------------------------------------
    # DATA PREVIEW
    # -------------------------------------------------------------
    def load_students_preview(self):
        sqlite_p = self.sqlite_var.get()
        if not os.path.exists(sqlite_p):
            return

        for row in self.tree.get_children():
            self.tree.delete(row)

        try:
            conn = sqlite3.connect(sqlite_p)
            cursor = conn.cursor()
            cursor.execute("""
            SELECT s.student_id, s.student_code, s.full_name, s.current_class_name,
                   COALESCE(sum.total_fees, 0), COALESCE(sum.total_paid, 0),
                   COALESCE(sum.remaining_balance, 0), COALESCE(sum.payment_status, 'بدون')
            FROM students s
            LEFT JOIN student_account_summary sum ON s.student_id = sum.student_id
            ORDER BY s.student_id ASC;
            """)
            self.all_students_data = cursor.fetchall()
            conn.close()

            for idx, r in enumerate(self.all_students_data, 1):
                self.tree.insert("", "end", values=(
                    idx, r[1], r[2], r[3],
                    f"{r[4]:,.0f}", f"{r[5]:,.0f}", f"{r[6]:,.0f}", r[7]
                ))

            self.lbl_preview_count.config(text=f"عدد الطلاب: {len(self.all_students_data):,} طالب")
            self.log(f"تم تحديث معاينة الطلاب: {len(self.all_students_data)} طالب.")
        except Exception as e:
            self.log(f"خطأ في معاينة البيانات: {e}")

    def filter_preview(self):
        q = self.search_var.get().strip().lower()
        if not hasattr(self, "all_students_data"):
            return

        for row in self.tree.get_children():
            self.tree.delete(row)

        count = 0
        for idx, r in enumerate(self.all_students_data, 1):
            if not q or q in str(r[1]).lower() or q in str(r[2]).lower() or q in str(r[3]).lower():
                self.tree.insert("", "end", values=(
                    count + 1, r[1], r[2], r[3],
                    f"{r[4]:,.0f}", f"{r[5]:,.0f}", f"{r[6]:,.0f}", r[7]
                ))
                count += 1
        self.lbl_preview_count.config(text=f"النتائج المطابقة: {count:,} طالب")

    def on_student_double_click(self, event):
        item = self.tree.selection()
        if not item:
            return
        vals = self.tree.item(item[0], "values")
        st_code = vals[1]
        st_name = vals[2]

        sqlite_p = self.sqlite_var.get()
        conn = sqlite3.connect(sqlite_p)
        cursor = conn.cursor()

        cursor.execute("SELECT student_id, current_class_name, branch_name FROM students WHERE student_code = ?", (st_code,))
        r = cursor.fetchone()
        if not r:
            conn.close()
            return
        st_id = r[0]
        st_class = f"{r[1] or ''} - الشعبة {r[2] or 'أ'}"

        win = tk.Toplevel(self)
        win.title(f"الملف المالي والأكاديمي: {st_name}")
        win.geometry("780x560")
        win.configure(bg=self.col_bg)

        # Set dialog icon if available
        ico_path = os.path.join(os.path.dirname(__file__), "school_logo.ico")
        if os.path.exists(ico_path):
            try:
                win.iconbitmap(ico_path)
            except Exception:
                pass

        top_h = tk.Frame(win, bg=self.col_brand, padx=15, pady=12)
        top_h.pack(fill="x")
        tk.Label(top_h, text=f"📋 كشف حساب وسندات الطالب: {st_name}", font=("Segoe UI", 12, "bold"), bg=self.col_brand, fg="#FFFFFF").pack(anchor="e")
        tk.Label(top_h, text=f"رقم القيد: {st_code} • {st_class} • مدارس مكة الأهلية الحديثة", font=("Segoe UI", 9), bg=self.col_brand, fg="#93C5FD").pack(anchor="e")

        nb = ttk.Notebook(win)
        nb.pack(fill="both", expand=True, padx=12, pady=10)

        # Tab 1: Financial Transactions
        tab_f = ttk.Frame(nb, padding=8)
        nb.add(tab_f, text="💰 سندات القبض والحركات المالية")

        tree_f = ttk.Treeview(tab_f, columns=("type", "fee", "amt", "rec", "date"), show="headings")
        tree_f.heading("type", text="نوع الحركة")
        tree_f.heading("fee", text="البند")
        tree_f.heading("amt", text="المبلغ (ريال)")
        tree_f.heading("rec", text="رقم السند")
        tree_f.heading("date", text="التاريخ")
        tree_f.column("type", width=120, anchor="center")
        tree_f.column("fee", width=140, anchor="e")
        tree_f.column("amt", width=110, anchor="center")
        tree_f.column("rec", width=90, anchor="center")
        tree_f.column("date", width=110, anchor="center")
        tree_f.pack(fill="both", expand=True)

        cursor.execute("SELECT trans_type, fee_type_name, amount, receipt_no, trans_date FROM financial_transactions WHERE student_id = ? ORDER BY trans_id DESC", (st_id,))
        for row in cursor.fetchall():
            tree_f.insert("", "end", values=(row[0], row[1], f"{row[2]:,.0f}", row[3] or "-", row[4]))

        # Tab 2: Grades
        tab_g = ttk.Frame(nb, padding=8)
        nb.add(tab_g, text="🏆 كشف الدرجات والنتائج")

        tree_g = ttk.Treeview(tab_g, columns=("sub", "term", "work", "exam", "tot", "est"), show="headings")
        tree_g.heading("sub", text="المادة")
        tree_g.heading("term", text="الفصل")
        tree_g.heading("work", text="محصلة أعمال")
        tree_g.heading("exam", text="اختبار نهائي")
        tree_g.heading("tot", text="المجموع")
        tree_g.heading("est", text="التقدير")
        tree_g.column("sub", width=160, anchor="e")
        tree_g.column("term", width=100, anchor="center")
        tree_g.column("work", width=90, anchor="center")
        tree_g.column("exam", width=90, anchor="center")
        tree_g.column("tot", width=90, anchor="center")
        tree_g.column("est", width=90, anchor="center")
        tree_g.pack(fill="both", expand=True)

        cursor.execute("SELECT subject_name, term_name, term_work_score, final_exam_score, total_score, grade_estimate FROM student_grades WHERE student_id = ?", (st_id,))
        for row in cursor.fetchall():
            tree_g.insert("", "end", values=row)

        conn.close()

if __name__ == "__main__":
    app = SchoolProSyncApp()
    app.mainloop()
