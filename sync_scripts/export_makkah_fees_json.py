import os
import sys
import re
import json
import pyodbc
from datetime import datetime

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

def get_db_connection():
    try:
        conn = pyodbc.connect(r'DRIVER={SQL Server};SERVER=.\SQLEXPRESS;Database=sdb_live_branch2;Trusted_Connection=yes;')
        return conn
    except Exception:
        pass
    
    # Try Named pipe
    err_log = os.path.expandvars(r'%LOCALAPPDATA%\Microsoft\Microsoft SQL Server Data\SQLEXPRESS\error.log')
    pipe_name = None
    if os.path.exists(err_log):
        with open(err_log, 'r', encoding='utf-16le', errors='ignore') as f:
            matches = re.findall(r'\[\s*(\\\\\.\\pipe\\[^\s\]]+)\s*\]', f.read())
            if matches:
                pipe_name = matches[-1]
    if pipe_name:
        conn = pyodbc.connect(f"DRIVER={{SQL Server}};SERVER=np:{pipe_name};Trusted_Connection=yes;")
        cur = conn.cursor()
        cur.execute(r"USE [D:\FRIENDS SOFT2\SCHOOL PRO\DB\SDB.MDF]")
        return conn

    raise RuntimeError("Cannot connect to School Pro database.")

def clean_year_key(y_p1):
    """
    Extracts clean year string like '2026-2027' from strings like '2026-2027 م' or '2024-2025'
    """
    match = re.search(r'(\d{4}\s*-\s*\d{4})', y_p1)
    if match:
        return match.group(1).replace(" ", "")
    return y_p1.strip()

def export_all_years_to_json():
    target_json_path = r"C:\Users\LENOVO\Desktop\makkah-smart-school\makkah_fees.json"
    
    print("Connecting to live School Pro database...")
    conn = get_db_connection()
    cur = conn.cursor()

    # 1. Fetch all academic years
    print("Fetching academic years...")
    cur.execute("SELECT y_no, y_p1, y_note, default_year FROM [year] ORDER BY y_no DESC")
    year_rows = cur.fetchall()
    
    years_metadata = []
    y_map = {} # y_no -> clean_key
    for r in year_rows:
        y_no = r[0]
        y_p1 = (r[1] or "").strip()
        y_note = (r[2] or "").strip()
        is_current = bool(r[3])
        clean_key = clean_year_key(y_p1)
        
        y_map[y_no] = {
            "y_no": y_no,
            "raw_name": y_p1,
            "key": clean_key,
            "hijri": y_note,
            "is_current": is_current
        }

    # 2. Fetch all student enrollments with class for each year
    print("Fetching student enrollments per year...")
    cur.execute("""
        SELECT 
            sm.y_no, 
            sm.st_no, 
            sm.mo_st_no, 
            sd.st_ar_nm, 
            ISNULL(c.cl_ar_nm, '') as cl_ar_nm,
            sm.st_stop
        FROM student_move sm
        INNER JOIN student_data sd ON sm.st_no = sd.st_no
        LEFT JOIN classes c ON sm.cl_no = c.cl_no
        ORDER BY sm.y_no DESC, sm.st_no ASC
    """)
    enrollments = cur.fetchall()
    print(f"Total enrollments fetched: {len(enrollments)}")

    # 3. Fetch all accounts grouped by mo_st_no / y_no
    print("Fetching all financial transactions...")
    cur.execute("""
        SELECT 
            a.acc_no,
            a.mo_st_no,
            a.y_no,
            a.acc_type,
            a.acc_cost,
            a.cnst_menual_no,
            CONVERT(varchar(10), a.acc_date, 120) as acc_date,
            at.at_nm,
            c.cn_note,
            c.cn_ditle
        FROM account a
        LEFT JOIN acc_type at ON a.at_no = at.at_no
        LEFT JOIN const c ON a.acc_no = c.acc_no
        WHERE a.at_no = 1
        ORDER BY a.y_no DESC, a.acc_date ASC, a.acc_no ASC
    """)
    all_accounts = cur.fetchall()
    print(f"Total tuition transactions fetched (at_no=1): {len(all_accounts)}")

    # Group accounts by (y_no, mo_st_no)
    trans_by_mo_st = {}
    for a in all_accounts:
        acc_no = a[0]
        mo_st_no = a[1]
        y_no = a[2]
        acc_type = a[3]
        cost = float(a[4]) if a[4] is not None else 0.0
        receipt_no = str(a[5]) if a[5] and str(a[5]) != '0' else ""
        acc_date = a[6] or ""
        at_nm = a[7] or ""
        cn_note = a[8] or ""
        cn_ditle = a[9] or ""

        # Determine note
        note = cn_note if cn_note else (cn_ditle if cn_ditle else at_nm)
        if not note:
            note = "رسوم دراسية" if acc_type == 2 else "سداد رسوم"

        key = (y_no, mo_st_no)
        if key not in trans_by_mo_st:
            trans_by_mo_st[key] = {
                "total_fees": 0.0,
                "total_paid": 0.0,
                "payments": [],
                "fees_breakdown": []
            }

        rec = trans_by_mo_st[key]
        if acc_type == 2:
            rec["total_fees"] += cost
            rec["fees_breakdown"].append({
                "acc_no": acc_no,
                "fee_type": at_nm or "رسوم دراسية",
                "amount": cost,
                "date": acc_date
            })
        elif acc_type == 1:
            rec["total_paid"] += cost
            rec["payments"].append({
                "acc_no": acc_no,
                "receipt_no": receipt_no,
                "amount": cost,
                "date": acc_date,
                "fee_type": at_nm or "رسوم دراسية",
                "note": note
            })

    # Assemble data_by_year
    print("Assembling structured data by academic year...")
    data_by_year = {}
    years_summary = []

    for y_no, y_info in y_map.items():
        clean_key = y_info["key"]
        display_name = f"{clean_key}م"
        if y_info["is_current"]:
            display_name += " (العام الحالي)"

        # Find enrollments for this year
        year_students = []
        year_total_fees = 0.0
        year_total_paid = 0.0

        for en in enrollments:
            if en[0] == y_no:
                st_no = en[1]
                mo_st_no = en[2]
                st_name = (en[3] or "").strip()
                cl_name = (en[4] or "").strip()
                is_stopped = bool(en[5])

                acc_data = trans_by_mo_st.get((y_no, mo_st_no), {
                    "total_fees": 0.0,
                    "total_paid": 0.0,
                    "payments": [],
                    "fees_breakdown": []
                })

                t_fees = acc_data["total_fees"]
                t_paid = acc_data["total_paid"]
                rem = t_fees - t_paid

                year_total_fees += t_fees
                year_total_paid += t_paid

                student_obj = {
                    "st_no": st_no,
                    "mo_st_no": mo_st_no,
                    "st_ar_nm": st_name,
                    "cl_ar_nm": cl_name,
                    "status": "موقف" if is_stopped else "مستمر",
                    "total_fees": round(t_fees, 2),
                    "total_paid": round(t_paid, 2),
                    "remaining": round(rem, 2),
                    "payments_history": acc_data["payments"],
                    "fees_breakdown": acc_data["fees_breakdown"]
                }
                year_students.append(student_obj)

        data_by_year[clean_key] = year_students

        year_stat = {
            "y_no": y_no,
            "year_key": clean_key,
            "year_name": y_info["raw_name"],
            "display_name": display_name,
            "hijri_note": y_info["hijri"],
            "is_current": y_info["is_current"],
            "students_count": len(year_students),
            "total_fees": round(year_total_fees, 2),
            "total_paid": round(year_total_paid, 2),
            "total_remaining": round(year_total_fees - year_total_paid, 2)
        }
        years_summary.append(year_stat)

    final_payload = {
        "metadata": {
            "school_name": "مدارس مكة الأهلية الحديثة",
            "branch": "فرع قحزة",
            "source": "School Pro Live Database (D:\\Friends Soft2)",
            "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "total_academic_years": len(years_summary)
        },
        "years": years_summary,
        "data_by_year": data_by_year
    }

    print(f"Writing JSON to {target_json_path}...")
    with open(target_json_path, "w", encoding="utf-8") as f:
        json.dump(final_payload, f, ensure_ascii=False, indent=2)

    file_size_mb = os.path.getsize(target_json_path) / (1024 * 1024)
    print("\n" + "=" * 60)
    print("SUCCESS: Exported makkah_fees.json successfully!")
    print(f"File Path: {target_json_path} ({file_size_mb:.2f} MB)")
    print(f"Total Academic Years Exported: {len(years_summary)}")
    for ys in years_summary:
        print(f"   • {ys['display_name']} [y_no={ys['y_no']}]: {ys['students_count']} طالب | رسوم: {ys['total_fees']:,.0f} | مسدد: {ys['total_paid']:,.0f} | متبقي: {ys['total_remaining']:,.0f}")
    print("=" * 60 + "\n")

if __name__ == "__main__":
    export_all_years_to_json()
