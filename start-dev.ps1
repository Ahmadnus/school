# تشغيل بيئة التطوير كاملة بأمر واحد.
#
# التطبيق على جهاز حقيقي يحتاج أربع قطع تعمل معاً:
#   Laravel  :8000  واجهة REST
#   Reverb   :8080  السوكِت اللحظي (الرسائل والإشعارات)
#   proxy    :9000  يجمعهما خلف منفذ واحد — ngrok المجاني يتيح نفقاً واحداً
#   ngrok           ينشر 9000 على الإنترنت ليصله الهاتف
#
# نقص أيٍّ منها يعني: لا رسائل لحظية ولا إشعارات.
#
#   powershell -ExecutionPolicy Bypass -File start-dev.ps1

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$ngrok = "C:\Users\Blankdiff\Desktop\New folder (6)\ngrok-v3-stable-windows-amd64\ngrok.exe"

Write-Host "إيقاف ما قد يكون عالقاً من تشغيل سابق..." -ForegroundColor DarkGray
Get-Process ngrok -ErrorAction SilentlyContinue | Stop-Process -Force

function Start-Piece($name, $file, $args, $wd) {
    Write-Host "تشغيل $name..." -ForegroundColor Cyan
    Start-Process -FilePath $file -ArgumentList $args -WorkingDirectory $wd -WindowStyle Minimized
}

Start-Piece "Laravel (8000)" "php" "artisan serve --host=127.0.0.1 --port=8000" $root
Start-Piece "Reverb (8080)"  "php" "artisan reverb:start --host=127.0.0.1 --port=8080" $root
Start-Piece "Proxy (9000)"   "python" "dev-proxy.py" $root

Start-Sleep -Seconds 4
Start-Piece "ngrok" $ngrok "http 9000" $root
Start-Sleep -Seconds 6

# الرابط العام يُقرأ من واجهة ngrok المحلية.
try {
    $url = (Invoke-RestMethod "http://127.0.0.1:4040/api/tunnels").tunnels[0].public_url
    Write-Host ""
    Write-Host "  الرابط العام: $url" -ForegroundColor Green
    Write-Host ""
    Write-Host "  إن اختلف عن الرابط المبنيّ داخل الـ APK، يلزم إعادة البناء:" -ForegroundColor Yellow
    Write-Host "    flutter build apk --release --dart-define=API_BASE_URL=$url/api"
} catch {
    Write-Host "تعذّرت قراءة رابط ngrok — راجع نافذته." -ForegroundColor Red
}
