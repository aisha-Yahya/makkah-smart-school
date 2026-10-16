import os
import sys
import re
import sqlite3
import pyodbc
from datetime import datetime

class SchoolProDbService:
    def __init__(self, mdf_path=r"D:\Friends Soft2\School Pro\DB\sdb.mdf", server=r".\SQLEXPRESS"):
        self.mdf_path = mdf_path
        self.server = server

    def find_existing_mdf(self):
        candidate_paths = [
            self.mdf_path,
            r"D:\Friends Soft\School Pro\sdb",
            r"D:\Friends Soft\School Pro\sdb.mdf",
            r"D:\Friends Soft\School Pro\DB\sdb.mdf",
            r"D:\Friends Soft\DB\sdb.mdf",
            r"D:\Friends Soft2\School Pro\DB\sdb.mdf",
            r"C:\Friends Soft\School Pro\sdb",
            r"C:\Friends Soft\School Pro\DB\sdb.mdf",
            r"E:\Friends Soft\School Pro\DB\sdb.mdf",
        ]
        for p in candidate_paths:
            if p and os.path.exists(p):
                return p
        return self.mdf_path

    def get_connection(self):
        """
        Dynamically connects to the active live School Pro SQL Server database on any machine.
        """
        active_mdf = self.find_existing_mdf()
        
        conn_candidates = [
            f"DRIVER={{SQL Server}};SERVER={self.server};AttachDbFilename={active_mdf};Integrated Security=True;User Instance=True;",
            f"DRIVER={{SQL Server}};SERVER={self.server};AttachDbFilename={active_mdf};Integrated Security=True;",
            f"DRIVER={{SQL Server}};SERVER={self.server};Database=sdb;Trusted_Connection=yes;",
            f"DRIVER={{SQL Server}};SERVER={self.server};Database=SchoolProDB;Trusted_Connection=yes;",
            f"DRIVER={{SQL Server}};SERVER=.;Database=sdb;Trusted_Connection=yes;",
            f"DRIVER={{SQL Server}};SERVER=localhost;Database=sdb;Trusted_Connection=yes;",
            f"DRIVER={{SQL Server}};SERVER={self.server};Database=sdb_branch2;Trusted_Connection=yes;",
        ]

        err_log = os.path.expandvars(r'%LOCALAPPDATA%\Microsoft\Microsoft SQL Server Data\SQLEXPRESS\error.log')
        if os.path.exists(err_log):
            try:
                with open(err_log, 'r', encoding='utf-16le', errors='ignore') as f:
                    text = f.read()
                    matches = re.findall(r'\[\s*(\\\\\.\\pipe\\[^\s\]]+)\s*\]', text)
                    if matches:
                        pipe_name = matches[-1]
                        conn_candidates.insert(0, f"DRIVER={{SQL Server}};SERVER=np:{pipe_name};Trusted_Connection=yes;")
            except Exception:
                pass

        last_err = None
        for cs in conn_candidates:
            try:
                conn = pyodbc.connect(cs, timeout=3)
                cur = conn.cursor()
                try:
                    cur.execute(f"USE [{active_mdf}]")
                except Exception:
                    pass
                cur.execute("SELECT COUNT(*) FROM student_data")
                cur.fetchone()
                return conn
            except Exception as e:
                last_err = e

        raise Exception(f"تعذر الاتصال بقاعدة بيانات سكول برو: {str(last_err)}")

    def test_connection(self):
        try:
            conn = self.get_connection()
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM student_data")
            count = cursor.fetchone()[0]
            conn.close()
            return True, f"تم الاتصال بقاعدة البيانات الحية بنجاح! عدد الطلاب المسجلين: {count}"
        except Exception as e:
            return False, f"فشل الاتصال: {str(e)}"

    def export_to_sqlite(self, target_sqlite_path, student_limit=0, progress_callback=None, log_callback=None):
        def log(msg):
            if log_callback:
                log_callback(msg)

        def update_progress(pct, status_text=""):
            if progress_callback:
                progress_callback(pct, status_text)

        log("بدء الاتصال بقاعدة بيانات سكول برو الحية...")
        update_progress(5, "جاري الاتصال بقاعدة البيانات...")

        temp_sqlite_path = target_sqlite_path + ".tmp"
        if os.path.exists(temp_sqlite_path):
            try:
                os.remove(temp_sqlite_path)
            except Exception:
                pass

        conn = self.get_connection()
        cursor = conn.cursor()

        lite_conn = sqlite3.connect(temp_sqlite_path)
        lite_cursor = lite_conn.cursor()

        update_progress(10, "إنشاء جداول SQLite والفهارس...")
        log("إنشاء هيكل جداول الطلاب والمعلمين والحسابات...")

        # 1. Master Catalogs
        lite_cursor.execute("CREATE TABLE classes (class_id INTEGER PRIMARY KEY, class_name TEXT NOT NULL, class_order INTEGER);")
        lite_cursor.execute("CREATE TABLE academic_years (year_id INTEGER PRIMARY KEY, year_name TEXT NOT NULL, hijri_note TEXT, is_current INTEGER DEFAULT 0);")
        lite_cursor.execute("CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT NOT NULL, branch_code TEXT);")
        lite_cursor.execute("CREATE TABLE fee_types (type_id INTEGER PRIMARY KEY, type_name TEXT NOT NULL, recurrence TEXT, cost_type INTEGER);")
        lite_cursor.execute("CREATE TABLE subjects (subject_id INTEGER PRIMARY KEY, subject_name TEXT NOT NULL, subject_order INTEGER);")
        lite_cursor.execute("""
        CREATE TABLE school_info (
            school_id TEXT PRIMARY KEY, school_name TEXT NOT NULL, address TEXT, 
            phone TEXT, city TEXT, manager_title TEXT, manager_name TEXT, email TEXT
        );""")
        lite_cursor.execute("CREATE TABLE school_notifications (notification_id INTEGER PRIMARY KEY, title TEXT NOT NULL, message_text TEXT NOT NULL, date_sent TEXT);")
        lite_cursor.execute("""
        CREATE TABLE fee_installments_plan (
            plan_id INTEGER PRIMARY KEY AUTOINCREMENT, class_id INTEGER, class_name TEXT,
            fee_type_name TEXT, installment_order INTEGER, percentage INTEGER, start_date TEXT,
            due_date TEXT, academic_year TEXT
        );""")

        # 2. Students & Enrollments
        lite_cursor.execute("""
        CREATE TABLE students (
            student_id INTEGER PRIMARY KEY, student_code TEXT NOT NULL, full_name TEXT NOT NULL,
            current_class_id INTEGER, current_class_name TEXT, branch_id INTEGER, branch_name TEXT,
            academic_year TEXT, gender TEXT, birth_date TEXT, birth_place TEXT, registration_date TEXT,
            phone1 TEXT, phone2 TEXT, mobile TEXT, address TEXT, guardian_name TEXT, guardian_job TEXT,
            mother_name TEXT, previous_school TEXT, status TEXT DEFAULT 'مستمر'
        );""")

        lite_cursor.execute("""
        CREATE TABLE student_enrollments (
            enrollment_id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER NOT NULL,
            registration_code TEXT NOT NULL, class_id INTEGER, class_name TEXT,
            branch_id INTEGER, branch_name TEXT, year_id INTEGER, academic_year TEXT,
            seat_number REAL, is_stopped INTEGER DEFAULT 0,
            FOREIGN KEY(student_id) REFERENCES students(student_id)
        );""")

        # 3. Student Financials
        lite_cursor.execute("""
        CREATE TABLE student_account_summary (
            id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER NOT NULL, student_name TEXT NOT NULL,
            class_name TEXT, academic_year TEXT, total_fees REAL DEFAULT 0, total_paid REAL DEFAULT 0,
            remaining_balance REAL DEFAULT 0, payment_status TEXT, last_payment_date TEXT,
            FOREIGN KEY(student_id) REFERENCES students(student_id)
        );""")

        lite_cursor.execute("""
        CREATE TABLE financial_transactions (
            trans_id INTEGER PRIMARY KEY, student_id INTEGER NOT NULL, registration_code TEXT NOT NULL,
            trans_type TEXT NOT NULL, acc_type_code INTEGER, fee_type_id INTEGER, fee_type_name TEXT,
            amount REAL NOT NULL, receipt_no TEXT, trans_date TEXT, due_date TEXT, academic_year TEXT,
            month_no INTEGER, notes TEXT, FOREIGN KEY(student_id) REFERENCES students(student_id)
        );""")

        lite_cursor.execute("""
        CREATE TABLE receipt_vouchers (
            receipt_id INTEGER PRIMARY KEY, manual_receipt_no INTEGER, trans_id INTEGER,
            student_id INTEGER, student_name TEXT, amount REAL NOT NULL, receipt_date TEXT,
            fee_description TEXT, academic_year TEXT, notes TEXT, FOREIGN KEY(student_id) REFERENCES students(student_id)
        );""")

        # 4. Student Grades
        lite_cursor.execute("""
        CREATE TABLE student_grades (
            grade_id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER NOT NULL, registration_code TEXT NOT NULL,
            student_name TEXT NOT NULL, class_name TEXT, subject_id INTEGER, subject_name TEXT NOT NULL,
            term_no INTEGER, term_name TEXT, term_work_score REAL, final_exam_score REAL, total_score REAL,
            max_score REAL DEFAULT 100, grade_estimate TEXT, academic_year TEXT,
            FOREIGN KEY(student_id) REFERENCES students(student_id)
        );""")

        # 5. Teacher / Employee Tables
        lite_cursor.execute("""
        CREATE TABLE employees (
            emp_id INTEGER PRIMARY KEY, emp_code TEXT NOT NULL, full_name TEXT NOT NULL,
            job_title TEXT, job_type INTEGER, address TEXT, phone1 TEXT, mobile TEXT,
            gender TEXT, birth_date TEXT, registration_date TEXT, national_id TEXT,
            status TEXT DEFAULT 'نشط', notes TEXT
        );""")

        lite_cursor.execute("""
        CREATE TABLE employee_account_types (
            at_no INTEGER PRIMARY KEY, type_name TEXT NOT NULL,
            category TEXT NOT NULL, is_credit INTEGER NOT NULL
        );""")

        lite_cursor.execute("""
        CREATE TABLE employee_financial_transactions (
            trans_id INTEGER PRIMARY KEY, emp_id INTEGER NOT NULL, emp_code TEXT NOT NULL,
            emp_name TEXT NOT NULL, job_title TEXT, type_id INTEGER NOT NULL,
            type_name TEXT NOT NULL, category TEXT NOT NULL, is_credit INTEGER NOT NULL,
            amount REAL NOT NULL, receipt_no TEXT, trans_date TEXT, academic_year TEXT,
            year_id INTEGER, month_no INTEGER, month_name TEXT, notes TEXT,
            FOREIGN KEY(emp_id) REFERENCES employees(emp_id)
        );""")

        lite_cursor.execute("""
        CREATE TABLE employee_account_summary (
            id INTEGER PRIMARY KEY AUTOINCREMENT, emp_id INTEGER NOT NULL, emp_name TEXT NOT NULL,
            job_title TEXT, academic_year TEXT, year_id INTEGER, month_no INTEGER, month_name TEXT,
            total_salary REAL DEFAULT 0, total_bonuses REAL DEFAULT 0, total_advances REAL DEFAULT 0,
            total_deductions REAL DEFAULT 0, net_payable REAL DEFAULT 0, last_trans_date TEXT,
            FOREIGN KEY(emp_id) REFERENCES employees(emp_id)
        );""")

        # Indexes
        lite_cursor.execute("CREATE INDEX idx_student_code ON students(student_code);")
        lite_cursor.execute("CREATE INDEX idx_trans_student ON financial_transactions(student_id);")
        lite_cursor.execute("CREATE INDEX idx_trans_year ON financial_transactions(academic_year);")
        lite_cursor.execute("CREATE INDEX idx_grades_student ON student_grades(student_id);")
        lite_cursor.execute("CREATE INDEX idx_summary_student ON student_account_summary(student_id);")
        lite_cursor.execute("CREATE INDEX idx_emp_code ON employees(emp_code);")
        lite_cursor.execute("CREATE INDEX idx_emp_trans_id ON employee_financial_transactions(emp_id);")
        lite_cursor.execute("CREATE INDEX idx_emp_trans_year ON employee_financial_transactions(academic_year);")
        lite_cursor.execute("CREATE INDEX idx_emp_trans_month ON employee_financial_transactions(month_no);")
        lite_cursor.execute("CREATE INDEX idx_emp_summary_id ON employee_account_summary(emp_id);")

        update_progress(20, "تصدير الدلائل والصفوف والسنوات...")
        log("تصدير الدلائل (الصفوف، المواد، السنوات، بيانات المدرسة)...")

        # Classes
        cursor.execute("SELECT cl_no, cl_ar_nm, cl_order FROM classes")
        for r in cursor.fetchall():
            lite_cursor.execute("INSERT INTO classes VALUES (?, ?, ?)", (r[0], r[1] or "", r[2]))

        # Years
        cursor.execute("SELECT y_no, y_p1, y_note, default_year FROM [year]")
        for r in cursor.fetchall():
            lite_cursor.execute("INSERT INTO academic_years VALUES (?, ?, ?, ?)", (r[0], r[1] or "", r[2] or "", r[3] or 0))

        # Branches
        cursor.execute("SELECT br_no, br_ar_nm, br_en_nm FROM branch")
        for r in cursor.fetchall():
            lite_cursor.execute("INSERT INTO branches VALUES (?, ?, ?)", (r[0], r[1] or "", r[2] or ""))

        # Fee Types
        cursor.execute("SELECT at_no, at_nm, at_cost_type, at_type FROM acc_type")
        for r in cursor.fetchall():
            lite_cursor.execute("INSERT INTO fee_types VALUES (?, ?, ?, ?)", (r[0], r[1] or "", r[2] or "", r[3]))

        # Subjects
        cursor.execute("SELECT sub_no, sub_ar_nm, sub_order FROM subjects")
        for r in cursor.fetchall():
            lite_cursor.execute("INSERT INTO subjects VALUES (?, ?, ?)", (r[0], r[1] or "", r[2]))

        # School Info
        cursor.execute("SELECT s_id, s_nm, s_address, s_phone, s_city, s_man_typ, s_man_nm, s_mail FROM school_seting")
        r = cursor.fetchone()
        if r:
            lite_cursor.execute("INSERT INTO school_info VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                (r[0] or "", r[1] or "", r[2] or "", r[3] or "", r[4] or "", r[5] or "", r[6] or "", r[7] or ""))

        # Notifications
        cursor.execute("SELECT m_no, m_nm, m_text FROM student_mesg")
        for r in cursor.fetchall():
            lite_cursor.execute("INSERT INTO school_notifications VALUES (?, ?, ?, ?)", (r[0], r[1] or "", r[2] or "", "2026-09-30"))

        # Installments
        cursor.execute("""
        SELECT s.as_no, s.cl_no, c.cl_ar_nm, at.at_nm, s.as_order, s.as_rati, 
               CONVERT(varchar(10), s.as_date1, 120), CONVERT(varchar(10), s.as_date2, 120), y.y_p1
        FROM acc_seting s
        LEFT JOIN classes c ON s.cl_no = c.cl_no
        LEFT JOIN acc_type at ON s.at_no = at.at_no
        LEFT JOIN [year] y ON s.y_no = y.y_no
        """)
        for r in cursor.fetchall():
            lite_cursor.execute("INSERT INTO fee_installments_plan VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                (r[0], r[1], r[2] or "", r[3] or "", r[4], r[5], r[6] or "", r[7] or "", r[8] or ""))

        update_progress(35, "تصدير بيانات الطلاب وسجلات القيد...")
        log("تصدير بطاقات الطلاب وسجلات القيد لجميع المراحل...")

        cursor.execute("""
        SELECT 
            sd.st_no, sd.st_ar_nm, sd.st_sex, CONVERT(varchar(10), sd.st_prth_date, 120),
            sd.st_prth_loc, CONVERT(varchar(10), sd.st_reg_date, 120), sd.st_ph_no1, sd.st_ph_no2,
            sd.st_mobile, sd.st_adrs, sd.st_partner_job, sd.st_mother_nm, sd.st_old_sch,
            sm.mo_st_no, sm.cl_no, c.cl_ar_nm, sm.br_no, b.br_ar_nm, sm.y_no, y.y_p1,
            sm.set_no1, sm.st_stop, ROW_NUMBER() OVER(PARTITION BY sd.st_no ORDER BY sm.y_no DESC, sm.mo_st_no DESC) as is_latest
        FROM student_data sd
        INNER JOIN student_move sm ON sd.st_no = sm.st_no
        LEFT JOIN classes c ON sm.cl_no = c.cl_no
        LEFT JOIN branch b ON sm.br_no = b.br_no
        LEFT JOIN [year] y ON sm.y_no = y.y_no
        ORDER BY sd.st_no, sm.y_no DESC;
        """)

        student_master = {}
        enrollment_count = 0
        for r in cursor.fetchall():
            enrollment_count += 1
            st_id = r[0]
            lite_cursor.execute("""
            INSERT INTO student_enrollments (
                student_id, registration_code, class_id, class_name, branch_id, branch_name,
                year_id, academic_year, seat_number, is_stopped
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (st_id, r[13], r[14], r[15] or "", r[16], r[17] or "", r[18], r[19] or "", r[20], 1 if r[21] else 0))

            if st_id not in student_master or r[22] == 1:
                gender_str = "ذكر" if r[2] == 1 else "أنثى"
                student_master[st_id] = (
                    st_id, r[13], r[1] or "", r[14], r[15] or "", r[16], r[17] or "", r[19] or "",
                    gender_str, r[3] or "", r[4] or "", r[5] or "", str(r[6]) if r[6] else "",
                    str(r[7]) if r[7] else "", str(r[8]) if r[8] else "", r[9] or "", r[10] or "",
                    r[10] or "", r[11] or "", r[12] or "", "مستمر" if not r[21] else "موقف"
                )

        for s in student_master.values():
            lite_cursor.execute("""
            INSERT INTO students VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, s)

        update_progress(55, "تصدير كافة الحركات المالية للطلاب وسندات السداد...")
        log("تصدير الحركات المالية للطلاب وسندات القبض...")

        # Financial Transactions
        cursor.execute("""
        SELECT a.acc_no, sm.st_no, a.mo_st_no, a.acc_type, a.at_no, at.at_nm, a.acc_cost,
               a.cnst_menual_no, CONVERT(varchar(10), a.acc_date, 120), CONVERT(varchar(10), a.acc_date2, 120),
               y.y_p1, a.mnth_no
        FROM account a
        INNER JOIN student_move sm ON a.mo_st_no = sm.mo_st_no
        LEFT JOIN acc_type at ON a.at_no = at.at_no
        LEFT JOIN [year] y ON a.y_no = y.y_no
        ORDER BY a.acc_no ASC;
        """)

        trans_count = 0
        for tr in cursor.fetchall():
            trans_count += 1
            trans_type = "استحقاق رسوم" if tr[3] == 2 else "سداد رسوم / مقبوضات"
            amount = float(tr[6]) if tr[6] is not None else 0.0
            lite_cursor.execute("""
            INSERT INTO financial_transactions VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (tr[0], tr[1], tr[2], trans_type, tr[3], tr[4], tr[5] or "", amount, tr[7] or "", tr[8] or "", tr[9] or "", tr[10] or "", tr[11], ""))

        # Account Summaries
        cursor.execute("""
        SELECT sm.st_no, sd.st_ar_nm, c.cl_ar_nm, y.y_p1,
               ISNULL(SUM(CASE WHEN a.acc_type = 2 THEN a.acc_cost ELSE 0 END), 0) AS total_fees,
               ISNULL(SUM(CASE WHEN a.acc_type = 1 THEN a.acc_cost ELSE 0 END), 0) AS total_paid,
               MAX(CONVERT(varchar(10), a.acc_date, 120)) as last_pay_date
        FROM student_data sd
        INNER JOIN student_move sm ON sd.st_no = sm.st_no
        INNER JOIN account a ON sm.mo_st_no = a.mo_st_no
        LEFT JOIN classes c ON sm.cl_no = c.cl_no
        LEFT JOIN [year] y ON a.y_no = y.y_no
        WHERE a.at_no = 1
        GROUP BY sm.st_no, sd.st_ar_nm, c.cl_ar_nm, y.y_p1;
        """)

        for row in cursor.fetchall():
            tot_f = float(row[4])
            tot_p = float(row[5])
            rem = tot_f - tot_p
            status = "خالص" if rem <= 0 and tot_f > 0 else ("متبقي عليه" if rem > 0 else ("لا توجد رسوم" if tot_f == 0 else "دائن"))
            lite_cursor.execute("""
            INSERT INTO student_account_summary (student_id, student_name, class_name, academic_year, total_fees, total_paid, remaining_balance, payment_status, last_payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (row[0], row[1] or "", row[2] or "", row[3] or "", tot_f, tot_p, rem, status, row[6] or ""))

        # Receipts
        cursor.execute("""
        SELECT c.cn_m, c.cnst_menual_no, c.acc_no, sm.st_no, c.cn_nm, c.cn_cost, c.cn_date, c.cn_ditle, y.y_p1, c.cn_note
        FROM const c
        LEFT JOIN account a ON c.acc_no = a.acc_no
        LEFT JOIN student_move sm ON a.mo_st_no = sm.mo_st_no
        LEFT JOIN [year] y ON c.y_no = y.y_no;
        """)
        receipt_count = 0
        for rc in cursor.fetchall():
            receipt_count += 1
            lite_cursor.execute("""
            INSERT INTO receipt_vouchers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (rc[0], rc[1], rc[2], rc[3], rc[4] or "", float(rc[5]) if rc[5] is not None else 0.0, rc[6] or "", rc[7] or "", rc[8] or "", rc[9] or ""))

        # Grades
        update_progress(70, "تصدير نتائج واختبارات الطلاب...")
        cursor.execute("""
        SELECT fd.fn_no, sm.st_no, fd.mo_st_no, sd.st_ar_nm, c.cl_ar_nm, s.sub_no, s.sub_ar_nm,
               fd.term_no, fd.trm_work_deg, fd.fn_deg,
               (ISNULL(fd.trm_work_deg, 0) + ISNULL(fd.fn_deg, 0)) as total_score,
               ISNULL(sp.sub_deg_max, 100) as max_score, y.y_p1
        FROM final_deg fd
        INNER JOIN student_move sm ON fd.mo_st_no = sm.mo_st_no
        INNER JOIN student_data sd ON sm.st_no = sd.st_no
        LEFT JOIN classes c ON sm.cl_no = c.cl_no
        LEFT JOIN study_plan sp ON fd.pln_no = sp.pln_no
        LEFT JOIN subjects s ON sp.sub_no = s.sub_no
        LEFT JOIN [year] y ON sm.y_no = y.y_no
        ORDER BY fd.fn_no ASC;
        """)

        grades_count = 0
        for g in cursor.fetchall():
            grades_count += 1
            t_work = float(g[8]) if g[8] is not None else 0.0
            f_exam = float(g[9]) if g[9] is not None else 0.0
            total = float(g[10]) if g[10] is not None else 0.0
            max_sc = float(g[11]) if g[11] is not None else 100.0
            term_name = "الفصل الأول" if g[7] == 1 else "الفصل الثاني"
            pct = (total / max_sc) * 100.0 if max_sc > 0 else 0
            est = "ممتاز" if pct >= 90 else ("جيد جداً" if pct >= 80 else ("جيد" if pct >= 65 else ("مقبول" if pct >= 50 else "ضعيف")))
            lite_cursor.execute("""
            INSERT INTO student_grades (student_id, registration_code, student_name, class_name, subject_id, subject_name, term_no, term_name, term_work_score, final_exam_score, total_score, max_score, grade_estimate, academic_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (g[1], g[2], g[3] or "", g[4] or "", g[5], g[6] or "", g[7], term_name, t_work, f_exam, total, max_sc, est, g[12] or ""))

        # 5. TEACHERS / EMPLOYEES
        update_progress(80, "تصدير بطاقات المعلمين والموظفين...")
        log("تصدير بيانات المعلمين وسجلات الوظائف...")

        cursor.execute("""
        SELECT 
            e.emp_no, e.emp_auto, e.emp_ar_nm, j.job_nm, e.job_type,
            e.emp_adrs, e.emp_ph_no1, e.emp_mobile, e.emp_sex,
            CONVERT(varchar(10), e.emp_prth_date, 120), CONVERT(varchar(10), e.emp_reg_date, 120),
            e.emp_natio_no, e.emp_stop, e.emp_note
        FROM emp_data e
        LEFT JOIN job j ON e.job_no = j.job_no
        ORDER BY e.emp_no ASC;
        """)
        emp_count = 0
        for emp in cursor.fetchall():
            emp_count += 1
            gender_str = "ذكر" if emp[8] == 1 else "أنثى"
            status_str = "موقف" if emp[12] else "نشط"
            lite_cursor.execute("""
            INSERT INTO employees VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                emp[0], emp[1] or str(emp[0]), emp[2] or "", emp[3] or "مدرس", emp[4] or 1,
                emp[5] or "", str(emp[6]) if emp[6] else "", str(emp[7]) if emp[7] else "",
                gender_str, emp[9] or "", emp[10] or "", emp[11] or "", status_str, emp[13] or ""
            ))

        # Employee Account Types
        cursor.execute("SELECT at_no, at_nm, at_type FROM acc_type_emp")
        for r in cursor.fetchall():
            at_no, at_nm, at_type = r[0], r[1], r[2]
            is_credit = 1 if at_type == 1 else 0
            if "راتب" in at_nm:
                cat = "راتب"
            elif "سلف" in at_nm:
                cat = "سلفة"
            elif "خصم" in at_nm or "تأخير" in at_nm or "غياب" in at_nm:
                cat = "خصم"
            elif "حافز" in at_nm or "مكافأ" in at_nm or "مكافئة" in at_nm:
                cat = "مكافأة / حافز"
            elif "إضافي" in at_nm or "اضافي" in at_nm:
                cat = "إضافي"
            elif is_credit == 1:
                cat = "استحقاق آخر"
            else:
                cat = "استقطاع آخر"

            lite_cursor.execute("INSERT INTO employee_account_types VALUES (?, ?, ?, ?)",
                             (at_no, at_nm or "", cat, is_credit))

        update_progress(90, "تصدير حركات وسلف وخصومات المعلمين...")
        log("تصدير الحركات المالية للمعلمين (السلف، الخصومات، الرواتب، السندات)...")

        cursor.execute("""
        SELECT 
            v.acc_no, v.mo_st_no, v.st_no, v.st_ar_nm, v.cl_ar_nm,
            v.at_no, v.at_nm, v.acc_type, v.acc_cost, v.cnst_menual_no,
            CONVERT(varchar(10), v.acc_date, 120), y.y_p1, v.y_no, v.mnth_no, v.mnth_ar_nm
        FROM viewAccount_emp v
        LEFT JOIN [year] y ON v.y_no = y.y_no
        ORDER BY v.acc_no ASC;
        """)
        emp_trans_count = 0
        for et in cursor.fetchall():
            emp_trans_count += 1
            acc_no = et[0]
            emp_id = et[1]
            emp_code = et[2] or str(emp_id)
            emp_name = et[3] or ""
            job_title = et[4] or "مدرس"
            type_id = et[5]
            type_name = et[6] or ""
            acc_type = et[7]
            is_credit = 1 if acc_type == 1 else 0
            amount = float(et[8]) if et[8] is not None else 0.0
            receipt_no = str(et[9]) if et[9] and str(et[9]) != "0" else ""
            trans_date = et[10] or ""
            academic_year = et[11] or ""
            year_id = et[12]
            month_no = et[13]
            month_name = et[14] or ""

            if "راتب" in type_name:
                cat = "راتب"
            elif "سلف" in type_name:
                cat = "سلفة"
            elif "خصم" in type_name or "تأخير" in type_name or "غياب" in type_name:
                cat = "خصم"
            elif "حافز" in type_name or "مكافأ" in type_name or "مكافئة" in type_name:
                cat = "مكافأة / حافز"
            elif "إضافي" in type_name or "اضافي" in type_name:
                cat = "إضافي"
            elif is_credit == 1:
                cat = "استحقاق آخر"
            else:
                cat = "استقطاع آخر"

            lite_cursor.execute("""
            INSERT INTO employee_financial_transactions VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                acc_no, emp_id, emp_code, emp_name, job_title, type_id,
                type_name, cat, is_credit, amount, receipt_no, trans_date,
                academic_year, year_id, month_no, month_name, ""
            ))

        # Summaries
        lite_cursor.execute("""
        INSERT INTO employee_account_summary (
            emp_id, emp_name, job_title, academic_year, year_id, month_no, month_name,
            total_salary, total_bonuses, total_advances, total_deductions, net_payable, last_trans_date
        )
        SELECT 
            emp_id, emp_name, job_title, academic_year, year_id, month_no, month_name,
            SUM(CASE WHEN category = 'راتب' THEN amount ELSE 0 END) as total_salary,
            SUM(CASE WHEN category IN ('مكافأة / حافز', 'إضافي', 'استحقاق آخر') THEN amount ELSE 0 END) as total_bonuses,
            SUM(CASE WHEN category = 'سلفة' THEN amount ELSE 0 END) as total_advances,
            SUM(CASE WHEN category IN ('خصم', 'استقطاع آخر') THEN amount ELSE 0 END) as total_deductions,
            SUM(CASE WHEN is_credit = 1 THEN amount ELSE -amount END) as net_payable,
            MAX(trans_date) as last_trans_date
        FROM employee_financial_transactions
        GROUP BY emp_id, academic_year, month_no;
        """)

        lite_conn.commit()
        lite_conn.close()
        conn.close()

        # Swap temp file to target
        if os.path.exists(target_sqlite_path):
            try:
                os.remove(target_sqlite_path)
            except Exception:
                pass
        import shutil
        shutil.move(temp_sqlite_path, target_sqlite_path)

        file_size_kb = os.path.getsize(target_sqlite_path) / 1024.0
        update_progress(100, "اكتملت المزامنة بنجاح!")
        log(f"تمت المزامنة بنجاح! حجم الملف: {file_size_kb:.1f} KB")
        log(f"الطلاب: {len(student_master)} | حركات الطلاب: {trans_count} | المعلمين: {emp_count} | حركات المعلمين: {emp_trans_count}")

        return {
            "success": True,
            "students_count": len(student_master),
            "enrollments_count": enrollment_count,
            "transactions_count": trans_count,
            "receipts_count": receipt_count,
            "grades_count": grades_count,
            "employees_count": emp_count,
            "emp_transactions_count": emp_trans_count,
            "file_size_kb": file_size_kb,
            "file_path": target_sqlite_path
        }
