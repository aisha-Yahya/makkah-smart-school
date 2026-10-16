import json
import sqlite3
import urllib.request
import urllib.error
import datetime

FIREBASE_PROJECT_ID = "makkah-smart-school"
FIRESTORE_BASE_URL = f"https://firestore.googleapis.com/v1/projects/{FIREBASE_PROJECT_ID}/databases/(default)/documents"

class SchoolProFirebaseService:
    def __init__(self, project_id=FIREBASE_PROJECT_ID):
        self.project_id = project_id
        self.base_url = f"https://firestore.googleapis.com/v1/projects/{self.project_id}/databases/(default)/documents"

    def _python_to_firestore_value(self, val):
        if val is None:
            return {"nullValue": None}
        elif isinstance(val, bool):
            return {"booleanValue": val}
        elif isinstance(val, int):
            return {"integerValue": str(val)}
        elif isinstance(val, float):
            return {"doubleValue": val}
        elif isinstance(val, dict):
            fields = {k: self._python_to_firestore_value(v) for k, v in val.items()}
            return {"mapValue": {"fields": fields}}
        elif isinstance(val, list):
            values = [self._python_to_firestore_value(x) for x in val]
            return {"arrayValue": {"values": values}}
        else:
            return {"stringValue": str(val)}

    def _dict_to_firestore_doc(self, py_dict):
        fields = {}
        for k, v in py_dict.items():
            fields[k] = self._python_to_firestore_value(v)
        return {"fields": fields}

    def sync_to_firestore(self, sqlite_path, progress_callback=None, log_callback=None):
        def log(msg):
            if log_callback:
                log_callback(msg)

        def update_progress(pct, text=""):
            if progress_callback:
                progress_callback(pct, text)

        log("🔥 بدء رفع البيانات من SQLite إلى Firebase Firestore السحابية...")
        update_progress(5, "الاتصال بـ Firebase...")

        conn = sqlite3.connect(sqlite_path)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()

        cursor.execute("SELECT * FROM students ORDER BY student_id ASC")
        students = cursor.fetchall()
        total_students = len(students)

        log(f"تم العثور على {total_students} طالب لرفهم إلى Firebase.")

        uploaded_count = 0
        for idx, s in enumerate(students):
            st_dict = dict(s)
            st_id = st_dict["student_id"]
            doc_id = str(st_dict.get("student_code") or st_id)

            # Account summary
            cursor.execute("SELECT * FROM student_account_summary WHERE student_id = ? ORDER BY id DESC LIMIT 1", (st_id,))
            acc_row = cursor.fetchone()
            acc_summary = dict(acc_row) if acc_row else {}

            # Transactions
            cursor.execute("SELECT * FROM financial_transactions WHERE student_id = ? ORDER BY trans_id ASC", (st_id,))
            trans_rows = [dict(r) for r in cursor.fetchall()]

            # Grades
            cursor.execute("SELECT * FROM student_grades WHERE student_id = ? ORDER BY grade_id ASC", (st_id,))
            grades_rows = [dict(r) for r in cursor.fetchall()]

            doc_payload = {
                "student_info": st_dict,
                "account_summary": acc_summary,
                "financial_transactions": trans_rows,
                "grades": grades_rows,
                "last_updated": datetime.datetime.now().isoformat()
            }

            firestore_doc = self._dict_to_firestore_doc(doc_payload)
            json_bytes = json.dumps(firestore_doc, ensure_ascii=False).encode("utf-8")

            # Patch document in Firestore: PUT/PATCH
            doc_url = f"{self.base_url}/students/{doc_id}"
            req = urllib.request.Request(
                doc_url,
                data=json_bytes,
                headers={"Content-Type": "application/json"},
                method="PATCH"
            )

            try:
                with urllib.request.urlopen(req, timeout=15) as resp:
                    uploaded_count += 1
            except Exception as e:
                log(f"تنبيه أثناء رفع الطالب {doc_id} إلى Firebase: {e}")

            if idx % 10 == 0 or idx == total_students - 1:
                pct = 10 + int(((idx + 1) / total_students) * 85)
                update_progress(pct, f"رفع الطلاب لسحابة Firebase ({idx + 1}/{total_students})...")

        conn.close()

        update_progress(100, "اكتمل الرفع إلى Firebase بنجاح! 🔥")
        log(f"🎉 تم بنجاح رفع {uploaded_count} من أصل {total_students} طالباً إلى Firebase Firestore.")

        return {
            "success": True,
            "total_students": total_students,
            "uploaded_count": uploaded_count
        }
