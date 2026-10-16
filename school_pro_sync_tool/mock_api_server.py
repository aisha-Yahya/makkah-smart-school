import sys
import json
from http.server import HTTPServer, BaseHTTPRequestHandler

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

class MockSyncHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.end_headers()
        resp = {
            "status": "online",
            "message": "خادم مزامنة سكول برو متاح وجاهز لاستقبال البيانات",
            "version": "1.0"
        }
        self.wfile.write(json.dumps(resp, ensure_ascii=False).encode("utf-8"))

    def do_POST(self):
        content_length = int(self.headers.get("Content-Length", 0))
        post_data = self.rfile.read(content_length)

        try:
            payload = json.loads(post_data.decode("utf-8"))
            batch_idx = payload.get("batch_index", 1)
            total_batches = payload.get("total_batches", 1)
            students = payload.get("students", [])
            print(f"[Mock Server] استلام الحزمة {batch_idx}/{total_batches} تحتوي على {len(students)} طالب بنجاح.")

            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.end_headers()
            resp = {
                "status": "success",
                "message": f"تمت معالجة الحزمة {batch_idx} بنجاح",
                "received_students": len(students)
            }
            self.wfile.write(json.dumps(resp, ensure_ascii=False).encode("utf-8"))
        except Exception as e:
            self.send_response(400)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.end_headers()
            self.wfile.write(json.dumps({"status": "error", "error": str(e)}).encode("utf-8"))

def run_server(port=8000):
    server_address = ("127.0.0.1", port)
    httpd = HTTPServer(server_address, MockSyncHandler)
    print(f"[Mock Server] يعمل على: http://127.0.0.1:{port}/api/v1/sync")
    print("اضغط Ctrl+C لإيقاف الخادم.")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nتم إيقاف الخادم.")

if __name__ == "__main__":
    run_server()
