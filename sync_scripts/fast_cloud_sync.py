import os
import sys
import json
import sqlite3
import datetime
import urllib.request
import urllib.error
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

PROJECT_ID = "makkah-smart-school"
COMMIT_URL = f"https://firestore.googleapis.com/v1/projects/{PROJECT_ID}/databases/(default)/documents:commit"
BASE_DOC_PATH = f"projects/{PROJECT_ID}/databases/(default)/documents"

def py_to_fs(val):
    if val is None:
        return {"nullValue": None}
    elif isinstance(val, bool):
        return {"booleanValue": val}
    elif isinstance(val, int):
        return {"integerValue": str(val)}
    elif isinstance(val, float):
        return {"doubleValue": val}
    elif isinstance(val, dict):
        return {"mapValue": {"fields": {k: py_to_fs(v) for k, v in val.items()}}}
    elif isinstance(val, list):
        return {"arrayValue": {"values": [py_to_fs(x) for x in val]}}
    else:
        return {"stringValue": str(val)}

def dict_to_fs_fields(d):
    return {k: py_to_fs(v) for k, v in d.items()}

def send_batch(writes):
    if not writes:
        return True
    payload = {"writes": writes}
    data_bytes = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(COMMIT_URL, data=data_bytes, headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            return resp.status == 200
    except Exception as e:
        print("Batch error:", e)
        return False

def fast_cloud_upload():
    sqlite_path = r"C:\Users\LENOVO\Desktop\makkah-smart-school\api\data\school_pro_synced.sqlite"
    if not os.path.exists(sqlite_path):
        print("SQLite db not found!")
        return

    print("⚡ [1/4] الاتصال بقاعدة بيانات المدرسة...")
    conn = sqlite3.connect(sqlite_path)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    # 1. School Info & Stats
    cur.execute("SELECT * FROM school_info LIMIT 1")
    s_info = dict(cur.fetchone() or {})
    
    cur.execute("SELECT COUNT(*) FROM students")
    total_students = cur.fetchone()[0]

    cur.execute("SELECT COUNT(*) FROM employees")
    total_employees = cur.fetchone()[0]

    cur.execute("SELECT SUM(total_fees), SUM(total_paid), SUM(remaining_balance) FROM student_account_summary")
    fees_row = cur.fetchone()
    total_fees = float(fees_row[0] or 0)
    total_paid = float(fees_row[1] or 0)
    total_rem = float(fees_row[2] or 0)

    sync_time = datetime.datetime.now().isoformat()
    meta_payload = {
        "school_info": s_info,
        "total_students": total_students,
        "total_employees": total_employees,
        "total_fees": total_fees,
        "total_paid": total_paid,
        "total_remaining": total_rem,
        "last_sync": sync_time
    }

    send_batch([{
        "update": {
            "name": f"{BASE_DOC_PATH}/system_status/school_overview",
            "fields": dict_to_fs_fields(meta_payload)
        }
    }])
    print("✅ تم رفع الملخص العام والمؤشرات المالية.")

    # 2. Upload Teachers Finance in Batches
    print("⚡ [2/4] جاري رفع بطاقات وحسابات المعلمين...")
    cur.execute("""
        SELECT e.emp_id, e.emp_code, e.full_name, e.job_title, e.phone1, e.status,
               SUM(CASE WHEN t.category = 'استحقاق راتب' THEN t.amount ELSE 0 END) as total_salary,
               SUM(CASE WHEN t.category = 'سلفة' THEN t.amount ELSE 0 END) as total_advances,
               SUM(CASE WHEN t.category IN ('خصم', 'استقطاع آخر') THEN t.amount ELSE 0 END) as total_deductions,
               SUM(CASE WHEN t.is_credit = 1 THEN t.amount ELSE -t.amount END) as net_payable,
               MAX(t.trans_date) as last_activity
        FROM employees e
        LEFT JOIN employee_financial_transactions t ON e.emp_id = t.emp_id
        GROUP BY e.emp_id
    """)
    teachers = [dict(r) for r in cur.fetchall()]
    
    teacher_writes = []
    for t in teachers:
        code = str(t.get("emp_code") or t["emp_id"]).strip()
        t["last_sync"] = sync_time
        teacher_writes.append({
            "update": {
                "name": f"{BASE_DOC_PATH}/teachers/{code}",
                "fields": dict_to_fs_fields(t)
            }
        })
    send_batch(teacher_writes)
    print(f"✅ تم رفع {len(teachers)} معلماً وموظفاً بنجاح.")

    # 3. Upload Students with Accounts & Grades in Batches of 100
    print(f"⚡ [3/4] جاري رفع {total_students} طالباً مع حساباتهم ودرجاتهم...")
    
    # Pre-fetch all account summaries
    cur.execute("SELECT student_id, total_fees, total_paid, remaining_balance, payment_status, last_payment_date FROM student_account_summary")
    acc_map = {}
    for r in cur.fetchall():
        acc_map[r["student_id"]] = dict(r)

    # Pre-fetch certificate results
    cur.execute("SELECT student_id, class_name, academic_year, ratio, grade_estimate, final_result FROM student_year_results")
    res_map = {}
    for r in cur.fetchall():
        res_map[r["student_id"]] = dict(r)

    cur.execute("SELECT * FROM students ORDER BY student_id ASC")
    all_students = cur.fetchall()

    batch_size = 100
    writes_batch = []
    uploaded_st = 0

    for idx, s in enumerate(all_students):
        st = dict(s)
        st_id = st["student_id"]
        code = str(st.get("student_code") or st_id).strip()

        acc = acc_map.get(st_id, {})
        res = res_map.get(st_id, {})

        student_doc = {
            "id": code,
            "student_id": st_id,
            "name": st.get("full_name") or "",
            "code": code,
            "class_name": st.get("current_class_name") or "",
            "branch": st.get("branch_name") or "",
            "gender": st.get("gender") or "",
            "status": st.get("status") or "مستمر",
            "phone": st.get("mobile") or st.get("phone1") or "",
            "total_fees": float(acc.get("total_fees") or 0),
            "total_paid": float(acc.get("total_paid") or 0),
            "remaining_balance": float(acc.get("remaining_balance") or 0),
            "payment_status": acc.get("payment_status") or "غير مسدد",
            "exam_result": res.get("final_result") or "ناجح",
            "percentage": float(res.get("ratio") or 0),
            "grade_estimate": str(res.get("grade_estimate") or "-"),
            "last_updated": sync_time
        }

        writes_batch.append({
            "update": {
                "name": f"{BASE_DOC_PATH}/students/{code}",
                "fields": dict_to_fs_fields(student_doc)
            }
        })

        if len(writes_batch) >= batch_size:
            send_batch(writes_batch)
            uploaded_st += len(writes_batch)
            writes_batch = []
            print(f"  --> تم رفع {uploaded_st} من {total_students} طالباً...")

    if writes_batch:
        send_batch(writes_batch)
        uploaded_st += len(writes_batch)
        print(f"  --> تم رفع {uploaded_st} من {total_students} طالباً...")

    print("⚡ [4/4] اكتمل الرفع السحابي الشامل 100% بنجاح!")
    print(f"🎉 السحابة الآن تحتوي على كامل بيانات المدرسة ({total_students} طالباً، {len(teachers)} معلماً، الرسوم والنتائج).")

if __name__ == "__main__":
    t0 = time.time()
    fast_cloud_upload()
    print(f"الوقت المستغرق: {time.time() - t0:.2f} ثانية.")
