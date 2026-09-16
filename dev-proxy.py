"""بروكسي تطوير يجمع Laravel وReverb خلف منفذ واحد.

السبب: ngrok المجاني يتيح نفقاً واحداً، بينما التطبيق يحتاج خدمتين —
واجهة REST على ٨٠٠٠ وسوكِت Reverb على ٨٠٨٠. بدون هذا يتصل الجهاز الحقيقي
بـ `10.0.2.2` (عنوان محاكي أندرويد) فلا يصل شيء لحظي أبداً.

التوجيه بالمسار:
    /app/…  و /apps/…   ->  Reverb   (بروتوكول Pusher: السوكِت والنشر)
    ما عداه               ->  Laravel

يعمل على مستوى البايتات: يقرأ سطر الطلب الأول ليقرّر الوجهة ثم يمرّر
الاتصال كما هو في الاتجاهين. لذلك تعمل ترقية WebSocket من تلقائها — بعد
المصافحة لا يبقى إلا تدفّق بايتات لا شأن للبروكسي بمحتواه.

للتطوير فقط: لا TLS ولا حدود ولا تسجيل. في الإنتاج يقوم بهذا الدور خادم
عكسي حقيقي (nginx/Caddy).
"""

import socket
import sys
import threading

LISTEN = ("127.0.0.1", 9000)
LARAVEL = ("127.0.0.1", 8000)
REVERB = ("127.0.0.1", 8080)

# مسارات Reverb في بروتوكول Pusher.
REVERB_PREFIXES = (b"/app/", b"/apps/")


def pipe(src: socket.socket, dst: socket.socket) -> None:
    """يمرّر البايتات في اتجاه واحد حتى يُغلق الطرف."""
    try:
        while True:
            data = src.recv(65536)
            if not data:
                break
            dst.sendall(data)
    except OSError:
        pass
    finally:
        # إغلاق نصف الاتصال يُعلم الطرف الآخر بالنهاية بدل تركه معلّقاً.
        for s in (src, dst):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass


def handle(client: socket.socket) -> None:
    try:
        # يكفي سطر الطلب الأول لمعرفة الوجهة؛ الباقي يُمرَّر كما هو.
        head = b""
        while b"\r\n" not in head:
            chunk = client.recv(4096)
            if not chunk:
                client.close()
                return
            head += chunk
            if len(head) > 65536:
                break

        path = head.split(b" ", 2)[1] if head.count(b" ") >= 2 else b"/"
        target = REVERB if path.startswith(REVERB_PREFIXES) else LARAVEL

        upstream = socket.create_connection(target)
        upstream.sendall(head)

        threading.Thread(target=pipe, args=(client, upstream), daemon=True).start()
        pipe(upstream, client)
    except OSError as e:
        print(f"proxy: {e}", file=sys.stderr)
        try:
            client.close()
        except OSError:
            pass


def main() -> None:
    server = socket.socket()
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(LISTEN)
    server.listen(128)
    print(f"proxy on {LISTEN[0]}:{LISTEN[1]} -> laravel {LARAVEL[1]}, reverb {REVERB[1]}")

    while True:
        client, _ = server.accept()
        threading.Thread(target=handle, args=(client,), daemon=True).start()


if __name__ == "__main__":
    main()
