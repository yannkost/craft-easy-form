<?php

namespace yannkost\easyform\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * easy-form settings
 */
class Settings extends Model
{
    public bool $enableSpamProtectionByDefault = true;

    /** Load the bundled front-end stylesheet (form-render.css) when rendering forms. */
    public bool $includeDefaultStyles = true;

    // ── CAPTCHA credentials (per provider) ───────────────────────────────
    // Secrets may reference an env var, e.g. $TURNSTILE_SECRET.
    public string $turnstileSiteKey = '';
    public string $turnstileSecret = '';
    public string $recaptchaV3SiteKey = '';
    public string $recaptchaV3Secret = '';
    public float $recaptchaV3ScoreThreshold = 0.5;
    public string $recaptchaV2SiteKey = '';
    public string $recaptchaV2Secret = '';

    public string $defaultSuccessMessage = 'Thank you for your submission!';
    public string $defaultNotificationEmail = '';
    public ?int $submissionRetentionDays = null;

    /** @var string Upload storage mode: 'asset' (Craft Asset) or 'filesystem' (raw path). */
    public string $uploadMode = 'asset';

    // Asset mode
    public string $uploadVolumeUid = '';
    public string $uploadSubfolder = '';

    // Filesystem mode
    public string $uploadFilesystemPath = '@webroot/form-uploads';
    public string $uploadBaseUrl = '@web/form-uploads';

    /** @var bool Organise filesystem uploads into dated Y/m subfolders within the path above. */
    public bool $uploadDateSubfolders = true;

    public int $maxFileSize = 10;

    /** @var int Default combined size (MB) of all files in a single file field. 0 = no combined limit. */
    public int $maxTotalUploadSize = 0;

    /** @var int Max size (MB) of a single uploaded file attached to a notification email. */
    public int $maxAttachmentSize = 10;

    public string $blockedEmailDomains = '';

    /**
     * @var string Newline/comma-separated keywords. A submission whose field
     * values contain any of these (case-insensitive) is rejected (see
     * $silentlyRejectBlocked for how).
     */
    public string $blockedKeywords = '';

    /**
     * @var bool How blocked email-domain / blocked-keyword submissions are handled.
     * true  (default): silently filed as spam — the sender sees a normal success
     *                  response, so they can't probe the block list.
     * false: rejected up front with a visible message (see $blockedSubmissionMessages).
     */
    public bool $silentlyRejectBlocked = true;

    /**
     * @var array Per-site rejection message shown when $silentlyRejectBlocked is
     * off, keyed by site handle: ['default' => '…', 'fr' => '…']. Kept deliberately
     * generic so it never reveals which rule matched.
     */
    public array $blockedSubmissionMessages = [];

    // ── Privacy ──────────────────────────────────────────────────────────
    public bool $storeIpAddresses = true;

    /** @var string How a stored IP is processed: full | anonymized | hashed */
    public string $ipStorageMode = 'full';

    /**
     * @var bool When a CAPTCHA verification request fails (provider/network
     * error), allow the submission through (true) or reject it (false).
     */
    public bool $captchaFailOpen = true;

    /**
     * @var bool Allow webhooks to post to private/reserved/loopback hosts.
     * Off by default to prevent SSRF; enable only for trusted internal targets.
     */
    public bool $allowPrivateWebhookHosts = false;

    // ── Uninstall ──────────────────────────────────────────────────────────
    /**
     * @var bool When the plugin is uninstalled, also delete every uploaded file
     * (filesystem-mode files and asset-mode Assets) attached to a submission.
     * Off by default — uninstalling drops the form/submission tables but never
     * destroys uploaded files unless this is explicitly enabled.
     */
    public bool $deleteUploadedFilesOnUninstall = false;

    /**
     * Extensions that are never accepted for filesystem uploads, regardless of
     * any per-field allow list.
     */
    public const BLOCKED_UPLOAD_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py',
        'jsp', 'asp', 'aspx', 'htaccess', 'htm', 'html', 'shtml', 'svg',
    ];

    /**
     * Resolve a setting value, expanding any $ENV_VAR reference.
     */
    public function resolve(string $value): string
    {
        return (string) (App::parseEnv($value) ?? '');
    }

    public function getUploadMode(): string
    {
        return $this->uploadMode === 'filesystem' ? 'filesystem' : 'asset';
    }

    /**
     * Process a request IP for storage according to the privacy settings.
     *
     * - storeIpAddresses off → null
     * - full → unchanged
     * - anonymized → last octet (IPv4) / last 80 bits (IPv6) zeroed
     * - hashed → salted SHA-256 (not reversible)
     */
    public function processIpAddress(?string $ip): ?string
    {
        if (!$this->storeIpAddresses || $ip === null || $ip === '') {
            return null;
        }

        return match ($this->ipStorageMode) {
            'none' => null,
            'hashed' => substr(hash('sha256', $ip . '|' . Craft::$app->getConfig()->getGeneral()->securityKey), 0, 64),
            'anonymized' => $this->anonymizeIp($ip),
            default => $ip,
        };
    }

    /**
     * Zero the host portion of an IP address (GA-style).
     */
    public function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin !== false) {
                // Keep the first 48 bits (6 bytes), zero the rest.
                $masked = substr($bin, 0, 6) . str_repeat("\0", strlen($bin) - 6);
                return inet_ntop($masked) ?: $ip;
            }
        }
        return $ip;
    }

    /**
     * Resolved absolute directory for filesystem uploads (alias-expanded).
     */
    public function getResolvedFilesystemPath(): string
    {
        return rtrim(Craft::getAlias($this->uploadFilesystemPath ?: '@webroot/form-uploads'), '/');
    }

    /**
     * Resolved public base URL for filesystem uploads (alias-expanded).
     */
    public function getResolvedBaseUrl(): string
    {
        return rtrim(Craft::getAlias($this->uploadBaseUrl ?: '@web/form-uploads'), '/');
    }
    
    /**
     * Get blocked email domains as an array
     */
    public function getBlockedEmailDomainsArray(): array
    {
        if (empty($this->blockedEmailDomains)) {
            return [];
        }
        
        // Split by newlines, trim whitespace, filter empty lines, convert to lowercase
        $domains = array_filter(
            array_map('trim', explode("\n", $this->blockedEmailDomains)),
            fn($domain) => !empty($domain)
        );
        
        return array_map('strtolower', $domains);
    }

    /**
     * Get blocked keywords as an array (split on newlines or commas, trimmed,
     * empties removed). Case is preserved; matching is done case-insensitively.
     */
    public function getBlockedKeywordsArray(): array
    {
        $raw = trim($this->blockedKeywords);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: []),
            fn($keyword) => $keyword !== ''
        ));
    }

    /**
     * Resolve the visible rejection message for a site handle (used only when
     * $silentlyRejectBlocked is off), falling back to the 'default' entry and
     * then a generic translated string.
     */
    public function getBlockedSubmissionMessageForSite(?string $siteHandle): string
    {
        $messages = $this->blockedSubmissionMessages;
        foreach ([$siteHandle, 'default'] as $key) {
            if ($key !== null && !empty($messages[$key])) {
                return (string) $messages[$key];
            }
        }

        return Craft::t('easy-form', 'Your submission could not be accepted.');
    }

    /**
     * Get the upload volume
     */
    public function getUploadVolume()
    {
        if (empty($this->uploadVolumeUid)) {
            return null;
        }
        
        return Craft::$app->volumes->getVolumeByUid($this->uploadVolumeUid);
    }
}
