<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications through Firebase Cloud Messaging (HTTP v1).
 *
 * No SDK: the whole protocol is a signed JWT exchanged for an access token,
 * then one POST per device. That keeps the dependency list unchanged and the
 * moving parts visible.
 *
 * Configuration lives in config/services.php → 'fcm'. When the service account
 * file is absent every call is a no-op, so the app runs unchanged before
 * Firebase is wired up.
 */
class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'fcm.access_token';

    /** Delivers one stored notification to every device the user signed in on. */
    public static function send(User $user, Notification $notification): void
    {
        $tokens = DeviceToken::query()
            ->where('user_id', $user->id)
            ->pluck('token', 'id');

        if ($tokens->isEmpty() || ! self::isConfigured()) {
            return;
        }

        $accessToken = self::accessToken();
        if ($accessToken === null) {
            return;
        }

        $projectId = config('services.fcm.project_id');
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $id => $token) {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($url, [
                    'message' => [
                        'token' => $token,
                        // A `notification` block is what makes the OS draw the
                        // alert while the app is closed.
                        'notification' => [
                            'title' => $notification->title,
                            'body' => $notification->body ?? '',
                        ],
                        // `data` reaches the app so it can open the right screen.
                        'data' => [
                            'notification_id' => (string) $notification->id,
                            'type' => (string) $notification->type,
                            'ref_id' => (string) ($notification->ref_id ?? ''),
                            'student_id' => (string) (NotificationTarget::studentIdFor($notification) ?? ''),
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => ['channel_id' => 'kader_realtime'],
                        ],
                        'apns' => [
                            'payload' => ['aps' => ['sound' => 'default']],
                        ],
                    ],
                ]);

            // 404/403 means the token no longer addresses a device: drop it so
            // the table does not fill with dead rows.
            if (in_array($response->status(), [403, 404], true)) {
                DeviceToken::query()->whereKey($id)->delete();

                continue;
            }

            if ($response->failed()) {
                Log::warning('FCM send failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }
        }
    }

    public static function isConfigured(): bool
    {
        return self::rawCredentials() !== null
            && ! empty(config('services.fcm.project_id'));
    }

    /**
     * Key material, from the env var first and the file second.
     *
     * Hosts that build from Git have nowhere to put a credential file, so the
     * whole JSON arrives as one environment variable there; locally the file
     * keeps working unchanged.
     */
    private static function rawCredentials(): ?string
    {
        $json = config('services.fcm.credentials_json');

        if (is_string($json) && trim($json) !== '') {
            return $json;
        }

        $path = config('services.fcm.credentials');

        return is_string($path) && $path !== '' && is_file($path)
            ? (string) file_get_contents($path)
            : null;
    }

    /**
     * Google OAuth2 access token for the service account, cached until shortly
     * before it expires (Google issues them for an hour).
     */
    private static function accessToken(): ?string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function (): ?string {
            $credentials = json_decode((string) self::rawCredentials(), true);

            if (! is_array($credentials) || ! isset($credentials['client_email'], $credentials['private_key'])) {
                Log::warning('FCM credentials file is not a service account key.');

                return null;
            }

            $now = time();
            $claim = [
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $jwt = self::signJwt($claim, $credentials['private_key']);
            if ($jwt === null) {
                return null;
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->failed()) {
                Log::warning('FCM token exchange failed', ['body' => $response->json()]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    /** RS256 JWT, signed with the service account's private key. */
    private static function signJwt(array $claim, string $privateKey): ?string
    {
        $encode = static fn (array $part): string => rtrim(
            strtr(base64_encode(json_encode($part, JSON_UNESCAPED_SLASHES)), '+/', '-_'),
            '=',
        );

        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claim);

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            Log::warning('FCM private key could not be read.');

            return null;
        }

        $signature = '';
        if (! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
