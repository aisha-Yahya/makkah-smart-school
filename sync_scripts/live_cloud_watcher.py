import os
import sys
import time
import subprocess
import datetime

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

BASE_DIR = r"C:\Users\LENOVO\Desktop\makkah-smart-school"
PYTHON_BIN = r"C:\Users\LENOVO\.local\bin\uv.exe"

def run_sync_and_upload():
    try:
        # 1. Sync live SQL Server to SQLite
        sync_script = os.path.join(BASE_DIR, "sync_scripts", "sync_live_to_sqlite.py")
        subprocess.run([PYTHON_BIN, "run", "--with", "pyodbc", "python", sync_script], capture_output=True, timeout=60)
        
        # 2. Upload latest to Cloud Firestore
        upload_script = os.path.join(BASE_DIR, "sync_scripts", "fast_cloud_sync.py")
        subprocess.run([PYTHON_BIN, "run", "python", upload_script], capture_output=True, timeout=60)
        return True
    except Exception as e:
        print("Watcher error:", e)
        return False

def main():
    print("🔥 بدأ تشغيل مراقب سكول برو اللحظي للسحابة (Real-Time Cloud Watcher)...")
    last_check_hash = ""
    
    while True:
        try:
            # Check if School Pro database file was touched/modified
            db_mdf = r"D:\Friends Soft2\School Pro\DB\sdb.mdf"
            current_mtime = str(os.path.getmtime(db_mdf)) if os.path.exists(db_mdf) else ""
            
            if current_mtime and current_mtime != last_check_hash:
                print(f"[{datetime.datetime.now().strftime('%H:%M:%S')}] تم اكتشاف تعديل جديد في سكول برو! جاري الرفع اللحظي للسحابة...")
                if run_sync_and_upload():
                    last_check_hash = current_mtime
                    print("✅ تمت المزامنة والرفع للسحابة بنجاح!")
        except Exception as e:
            pass
        
        # Check every 20 seconds
        time.sleep(20)

if __name__ == "__main__":
    main()
