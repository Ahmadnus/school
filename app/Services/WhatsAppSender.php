<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * يرسل رسالة واتساب نصّية عبر بوابة WAHA (WhatsApp HTTP API).
 *
 * البوابة خادمٌ مستقلّ مربوطٌ برقمٍ واحد عبر رمز QR، ويكفيها نداء HTTP
 * واحد لكل رسالة — فلا حزمة ولا SDK.
 *
 * الإعداد في config/services.php → 'whatsapp'. ما لم يُضبط العنوان والمفتاح
 * معاً فكلّ نداءٍ لا يفعل شيئاً، كما في {@see FcmSender}.
 */
class WhatsAppSender
{
    public static function isConfigured(): bool
    {
        return filled(config('services.whatsapp.url')) && filled(config('services.whatsapp.key'));
    }

    /** `true` إن قبلت البوابة الرسالة. */
    public static function send(string $phone, string $countryCode, string $text): bool
    {
        if (! self::isConfigured()) {
            return false;
        }

        $chatId = self::chatIdFor($phone, $countryCode);
        if ($chatId === null) {
            Log::warning('WhatsApp send skipped: unusable phone', ['phone' => $phone]);

            return false;
        }

        $response = Http::baseUrl(rtrim(config('services.whatsapp.url'), '/'))
            ->withHeaders(['X-Api-Key' => config('services.whatsapp.key')])
            ->acceptJson()
            ->timeout(15)
            ->post('/api/sendText', [
                'session' => config('services.whatsapp.session'),
                'chatId' => $chatId,
                'text' => $text,
            ]);

        if ($response->failed()) {
            Log::warning('WhatsApp send failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * يحوّل الرقم المخزَّن إلى معرّف محادثة واتساب: `963955123456@c.us`.
     *
     * الرقم الدوليّ (`+` أو `00`) يُؤخذ كما هو، والمحليّ (`0955…`) يُسقط
     * صفره ويُسبق برمز دولة المدرسة — الصيغتان اللتان تقبلهما
     * {@see \App\Rules\PhoneNumber}.
     */
    public static function chatIdFor(string $phone, string $countryCode): ?string
    {
        $digits = preg_replace('/[\s\-().\/]/', '', $phone);

        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } else {
            $digits = $countryCode.ltrim($digits, '0');
        }

        return preg_match('/^[1-9][0-9]{6,14}$/', $digits) ? $digits.'@c.us' : null;
    }
}
