@echo off
chcp 65001 >nul
title أداة مزامنة سكول برو - مدارس مكة الأهلية الحديثة
cd /d "C:\Users\LENOVO\Desktop\makkah-smart-school\school_pro_sync_tool"
"C:\Users\LENOVO\.local\bin\uv.exe" run --with pyodbc --with requests --with pillow python main.py
