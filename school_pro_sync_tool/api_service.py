import json
import sqlite3
import urllib.request
import urllib.error
import datetime

class SchoolProApiService:
    def __init__(self):
        pass

    def test_connection(self, api_url, api_key=""):
        try:
            req = urllib.request.Request(
                api_url,
                headers={
                    "User-Agent": "SchoolProSyncTool/1.0",
                    "Authorization": f"Bearer {api_key}" if api_key else ""
                },
                method="GET"
            )
            with urllib.request.urlopen(req, timeout=5) as response:
                status_code = response.getcode()
                return True, f"تم الاتصال بنجاح بالخادم! رمز الاستجابة: {status_code}"
        except urllib.error.HTTPError as e:
            # If server returned 404 or 401 or 405, server is still alive
            if e.code in (401, 403):
                return False, f"الخادم موجود ولكن تم رفض المصادقة (رمز {e.code}). تحقق من API Key."
            elif e.code in (200, 201, 404, 405):
                return True, f"الخادم متصل ومتاح (استجاب برمز {e.code})."
            else:
                return False, f"خطأ من الخادم (رمز {e.code}): {e.reason}"
        except urllib.error.URLError as e:
            return False, f"تعذر الوصول للخادم: {str(e.reason)}"
        except Exception as e:
            return False, f"خطأ في الاتصال: {str(e)}"

    def build_payload_from_sqlite(self, sqlite_path, student_limit=None):
        conn = sqlite3.connect(sqlite_path)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()

        # School Info
        cursor.execute("SELECT * FROM school_info LIMIT 1")
        school_row = cursor.fetchone()
        school_info = dict(school_row) if school_row else {}

        # Limit clause
        limit_clause = f"LIMIT {student_limit}" if student_limit else ""
        cursor.execute(f"SELECT * FROM students ORDER BY student_id {limit_clause}")
        students_rows = cursor.fetchall()

        students_payload = []
        for s in students_rows:
            st_id = s["student_id"]

            # Account Summary
            cursor.execute("SELECT * FROM student_account_summary WHERE student_id = ? ORDER BY id DESC LIMIT 1", (st_id,))
            acc_row = cursor.fetchone()
            acc_summary = dict(acc_row) if acc_row else {}

            # Transactions
            cursor.execute("SELECT * FROM financial_transactions WHERE student_id = ? ORDER BY trans_id ASC", (st_id,))
            trans_rows = [dict(r) for r in cursor.fetchall()]

            # Grades
            cursor.execute("SELECT * FROM student_grades WHERE student_id = ? ORDER BY grade_id ASC", (st_id,))
            grades_rows = [dict(r) for r in cursor.fetchall()]

            # Enrollments
            cursor.execute("SELECT * FROM student_enrollments WHERE student_id = ? ORDER BY enrollment_id ASC", (st_id,))
            enroll_rows = [dict(r) for r in cursor.fetchall()]

            students_payload.append({
                "student_info": dict(s),
                "account_summary": acc_summary,
                "financial_transactions": trans_rows,
                "grades": grades_rows,
                "enrollment_history": enroll_rows
            })

        # Notifications
        cursor.execute("SELECT * FROM school_notifications")
        notifications = [dict(r) for r in cursor.fetchall()]

        # Installment Plan
        cursor.execute("SELECT * FROM fee_installments_plan")
        installments = [dict(r) for r in cursor.fetchall()]

        conn.close()

        payload = {
            "sync_version": "1.0",
            "sync_timestamp": datetime.datetime.now().isoformat(),
            "school_info": school_info,
            "students_count": len(students_payload),
            "students": students_payload,
            "school_notifications": notifications,
            "fee_installments_plan": installments
        }

        return payload

    def save_payload_to_json(self, sqlite_path, output_json_path, student_limit=None):
        payload = self.build_payload_from_sqlite(sqlite_path, student_limit)
        with open(output_json_path, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=False, indent=2)
        return len(payload["students"])

    def sync_to_api(self, api_url, api_key, sqlite_path, batch_size=25, progress_callback=None, log_callback=None):
        def log(msg):
            if log_callback:
                log_callback(msg)

        def update_progress(pct, text=""):
            if progress_callback:
                progress_callback(pct, text)

        log("بدء تجميع حزم البيانات من قاعدة SQLite للمزامنة...")
        update_progress(10, "تجميع البيانات...")

        payload = self.build_payload_from_sqlite(sqlite_path)
        all_students = payload["students"]
        total_students = len(all_students)

        log(f"تم تجهيز {total_students} طالباً للإرسال عبر API.")

        # Batch sending
        batches = [all_students[i:i + batch_size] for i in range(0, total_students, batch_size)]
        total_batches = len(batches)
        successful_batches = 0

        for idx, batch in enumerate(batches):
            batch_num = idx + 1
            pct = 15 + int((batch_num / total_batches) * 80)
            update_progress(pct, f"إرسال الحزمة {batch_num} من {total_batches} ({len(batch)} طالب)...")
            log(f"جاري إرسال الحزمة رقم {batch_num} من {total_batches}...")

            batch_payload = {
                "sync_timestamp": payload["sync_timestamp"],
                "school_info": payload["school_info"],
                "batch_index": batch_num,
                "total_batches": total_batches,
                "batch_size": len(batch),
                "students": batch
            }

            json_bytes = json.dumps(batch_payload, ensure_ascii=False).encode("utf-8")

            req = urllib.request.Request(
                api_url,
                data=json_bytes,
                headers={
                    "Content-Type": "application/json; charset=utf-8",
                    "User-Agent": "SchoolProSyncTool/1.0",
                    "Authorization": f"Bearer {api_key}" if api_key else ""
                },
                method="POST"
            )

            try:
                with urllib.request.urlopen(req, timeout=30) as resp:
                    resp_code = resp.getcode()
                    resp_data = resp.read().decode("utf-8")
                    log(f"الحزمة {batch_num} تم قبولها بنجاح (رمز {resp_code}).")
                    successful_batches += 1
            except urllib.error.HTTPError as e:
                err_content = e.read().decode("utf-8", errors="replace")
                log(f"خطأ في الحزمة {batch_num} (رمز {e.code}): {e.reason} - {err_content[:200]}")
                raise Exception(f"فشل إرسال الحزمة {batch_num}: رمز {e.code} - {e.reason}")
            except Exception as e:
                log(f"فشل الاتصال أثناء إرسال الحزمة {batch_num}: {str(e)}")
                raise Exception(f"فشل الاتصال بالـ API: {str(e)}")

        update_progress(100, "اكتملت المزامنة بنجاح! ✅")
        log(f"تمت مزامنة جميع الحزم ({successful_batches}/{total_batches}) وإرسال {total_students} طالباً بنجاح!")
        return {
            "success": True,
            "total_students": total_students,
            "batches_sent": successful_batches
        }
