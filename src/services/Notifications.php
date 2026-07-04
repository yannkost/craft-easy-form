<?php

namespace yannkost\easyform\services;

use cebe\markdown\GithubMarkdown;
use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use craft\mail\Message;
use craft\web\View;
use yii\base\Component;
use yannkost\easyform\models\Submission;
use yannkost\easyform\models\Form;
use yannkost\easyform\EasyForm;

/**
 * Notifications service
 */
class Notifications extends Component
{
    /**
     * Sends email notifications for a submission
     *
     * @param Submission $submission
     * @return bool
     */
    public function sendSubmissionNotification(Submission $submission): bool
    {
        $form = $submission->getForm();
        if (!$form || empty($form->notificationSettings)) {
            // Nothing configured — a successful no-op, not a failure.
            EasyForm::debug("No notification settings found for form {$submission->formId}");
            return true;
        }

        $settings = $form->getNotificationSettingsArray();

        // Normalize to array of notifications
        if (isset($settings['recipients'])) {
            $settings = [$settings];
        }

        EasyForm::debug("Processing " . count($settings) . " notifications for submission {$submission->id}");

        $data = $submission->getFlatValues();
        $failures = 0;

        // The submission's site — used to honor the per-site enable toggle below.
        $siteHandle = null;
        if ($submission->siteId) {
            $siteHandle = Craft::$app->sites->getSiteById($submission->siteId)?->handle;
        }

        foreach ($settings as $index => $notification) {
            if (empty($notification['enabled'])) {
                EasyForm::debug("Notification " . ($notification['name'] ?? $index) . " is disabled");
                continue;
            }

            // Per-site switch: send unless this site is explicitly turned off.
            // Legacy notifications have no siteEnabled key, so they default to on.
            if ($siteHandle !== null && empty($notification['siteEnabled'][$siteHandle] ?? '1')) {
                EasyForm::debug("Notification " . ($notification['name'] ?? $index) . " is disabled for site '$siteHandle'");
                continue;
            }

            if (!$this->shouldSend($notification, $data, $siteHandle)) {
                EasyForm::debug("Notification " . ($notification['name'] ?? $index) . " skipped by its conditions");
                continue;
            }

            // processNotification returns false only on genuine send failures;
            // misconfiguration (no valid recipients) is handled gracefully.
            if (!$this->processNotification($submission, $form, $notification, $data)) {
                $failures++;
            }
        }

        return $failures === 0;
    }

    /**
     * Queues one job per applicable notification for a submission.
     *
     * Pushing a job per notification (rather than one job that loops them all)
     * means a transient failure on one notification retries only that one — a
     * whole-batch retry would re-send every notification that already succeeded.
     * Enabled/per-site/condition gating is evaluated here so no-op jobs aren't
     * queued; the submission data is immutable, so the result won't change by
     * the time the job runs.
     */
    public function queueForSubmission(Submission $submission): void
    {
        $form = $submission->getForm();
        if (!$form || empty($form->notificationSettings)) {
            return;
        }

        $settings = $form->getNotificationSettingsArray();
        if (isset($settings['recipients'])) {
            $settings = [$settings];
        }

        $data = $submission->getFlatValues();

        $siteHandle = null;
        if ($submission->siteId) {
            $siteHandle = Craft::$app->sites->getSiteById($submission->siteId)?->handle;
        }

        foreach ($settings as $index => $notification) {
            if (empty($notification['enabled'])) {
                continue;
            }
            if ($siteHandle !== null && empty($notification['siteEnabled'][$siteHandle] ?? '1')) {
                continue;
            }
            if (!$this->shouldSend($notification, $data, $siteHandle)) {
                continue;
            }

            Craft::$app->getQueue()->push(new \yannkost\easyform\jobs\SendNotificationJob([
                'submissionId' => $submission->id,
                'notificationIndex' => (int) $index,
            ]));
        }
    }

    /**
     * Sends a specific notification by index
     *
     * @param Submission $submission
     * @param int $notificationIndex
     * @param string|null $recipientOverride
     * @return bool
     */
    public function sendSingleNotification(Submission $submission, int $notificationIndex, ?string $recipientOverride = null): bool
    {
        $form = $submission->getForm();
        if (!$form || empty($form->notificationSettings)) {
            // Nothing to send — a permanent no-op, not a transient failure, so
            // the queue must not retry it.
            EasyForm::debug("No notification settings for form {$submission->formId}; skipping notification #{$notificationIndex}.");
            return true;
        }

        $settings = $form->getNotificationSettingsArray();

        // Normalize
        if (isset($settings['recipients'])) {
            $settings = [$settings];
        }

        if (!isset($settings[$notificationIndex])) {
            EasyForm::log("Notification #{$notificationIndex} no longer exists for form {$form->id}; skipping.", 'warning');
            return true;
        }

        $notification = $settings[$notificationIndex];
        $data = $submission->getFlatValues();

        return $this->processNotification($submission, $form, $notification, $data, $recipientOverride);
    }

    /**
     * Render a submitted value as a flat string for the default email, handling
     * uploaded-file values (filesystem array shape or asset IDs) without crashing.
     */
    private function formatValueForEmail(mixed $value): string
    {
        if (!is_array($value)) {
            return (string) $value;
        }

        // Filesystem-mode uploaded files → list the original filenames.
        if (($value['storage'] ?? '') === 'filesystem' && isset($value['files'])) {
            $names = array_map(static fn($f) => is_array($f) ? ($f['filename'] ?? 'file') : (string) $f, $value['files']);
            return implode(', ', $names);
        }

        // Any other array (multi-value fields, asset IDs) → flatten scalars.
        $flat = [];
        array_walk_recursive($value, function ($v) use (&$flat) {
            if (is_scalar($v)) {
                $flat[] = (string) $v;
            }
        });
        return implode(', ', $flat);
    }

    /**
     * Attach a submission's uploaded files to the message, skipping any single
     * file larger than the configured max attachment size.
     */
    private function attachSubmissionFiles(Message $message, Form $form, Submission $submission): void
    {
        $settings = EasyForm::getInstance()->getSettings();
        $maxBytes = max(0, (int) $settings->maxAttachmentSize) * 1024 * 1024;

        $schema = EasyForm::getInstance()->formSchema;
        $fields = $schema->getAllFields($schema->normalize($form->getFieldLayoutArray()));
        $values = $submission->getValues();

        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }
            $value = $values[$field['handle'] ?? ''] ?? null;
            if (empty($value)) {
                continue;
            }

            // Filesystem-mode shape: ['storage' => 'filesystem', 'files' => [...]].
            if (is_array($value) && ($value['storage'] ?? '') === 'filesystem') {
                foreach ($value['files'] ?? [] as $f) {
                    $abs = $settings->getResolvedFilesystemPath() . '/' . ($f['path'] ?? '');
                    $this->attachFileIfFits($message, $abs, $f['filename'] ?? basename($abs), $f['mimeType'] ?? null, (int) ($f['size'] ?? 0), $maxBytes);
                }
                continue;
            }

            // Asset-mode: a single ID or an array of IDs.
            foreach ((is_array($value) ? $value : [$value]) as $id) {
                if (!is_numeric($id)) {
                    continue;
                }
                $asset = \craft\elements\Asset::find()->id((int) $id)->one();
                if (!$asset) {
                    continue;
                }
                try {
                    $this->attachFileIfFits($message, $asset->getCopyOfFile(), $asset->getFilename(), $asset->getMimeType(), (int) $asset->size, $maxBytes);
                } catch (\Throwable $e) {
                    EasyForm::log("Could not attach asset #{$id}: " . $e->getMessage(), 'warning');
                }
            }
        }
    }

    /**
     * Attach a single file if it exists and is within the size budget.
     */
    private function attachFileIfFits(Message $message, string $path, string $filename, ?string $mimeType, int $size, int $maxBytes): void
    {
        if (!is_file($path)) {
            EasyForm::log("Notification attachment missing on disk: {$path}", 'warning');
            return;
        }

        $size = $size ?: (int) @filesize($path);
        if ($maxBytes > 0 && $size > $maxBytes) {
            EasyForm::log("Skipping attachment '{$filename}' ({$size} bytes) — exceeds max attachment size.", 'info');
            return;
        }

        $options = ['fileName' => $filename];
        if ($mimeType) {
            $options['contentType'] = $mimeType;
        }
        $message->attach($path, $options);
    }

    /**
     * Whether a notification should send given its (optional) conditions and the
     * submitted values. Reuses the field/page condition engine: action 'show'
     * means "send only if rules match", 'hide' means "don't send if they match".
     */
    private function shouldSend(array $notification, array $data, ?string $siteHandle = null): bool
    {
        $conditions = $notification['conditions'] ?? null;
        if (empty($conditions) || empty($conditions['action']) || empty($conditions['rules'])) {
            return true;
        }

        return EasyForm::getInstance()->conditionEvaluator->isVisible(['conditions' => $conditions], $data, $siteHandle);
    }

    /**
     * Parse a comma-separated email list into validated addresses. Supports
     * `{handle}` / `{field[handle]}` placeholders resolved from the submission.
     *
     * @param string|array $raw
     * @return string[]
     */
    private function parseEmailList($raw, Submission $submission): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = array_filter(array_map('trim', explode(',', (string) $raw)));
        }

        $out = [];
        foreach ($parts as $recipient) {
            if (preg_match('/^\{(?:field\[)?(\w+)\]?\}$/', $recipient, $m)) {
                $value = $submission->getFieldValue($m[1]);
                if ($value && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $out[] = $value;
                }
            } elseif (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $out[] = $recipient;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Resolve a {handle} / {field[handle]} dynamic recipient to a valid email,
     * or null — logging precisely WHY it didn't resolve (no such field / empty /
     * not an email) so a misconfiguration is visible in the plugin log rather
     * than failing silently.
     */
    private function resolveDynamicRecipient($form, Submission $submission, string $handle, string $notificationName): ?string
    {
        if ($form && method_exists($form, 'getAnyFieldByHandle') && !$form->getAnyFieldByHandle($handle)) {
            EasyForm::log("Notification '{$notificationName}': recipient placeholder references unknown field handle '{$handle}'.", 'warning');
            return null;
        }
        $value = $submission->getFieldValue($handle);
        if ($value === null || $value === '' || $value === []) {
            EasyForm::log("Notification '{$notificationName}': recipient field '{$handle}' was empty for this submission.", 'warning');
            return null;
        }
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $shown = is_scalar($value) ? (string) $value : gettype($value);
            EasyForm::log("Notification '{$notificationName}': recipient field '{$handle}' is not a valid email address (got: {$shown}).", 'warning');
            return null;
        }
        return $value;
    }

    /**
     * Processes a single notification configuration
     */
    private function processNotification(Submission $submission, $form, array $notification, array $data, ?string $recipientOverride = null): bool
    {
        $recipients = $notification['recipients'] ?? [];

        EasyForm::debug("Processing notification '" . ($notification['name'] ?? 'unnamed') . "'");

        // Override recipients if provided
        if ($recipientOverride) {
            $recipients = [$recipientOverride];
        }

        // Parse recipients (support {handle} for dynamic emails)
        $parsedRecipients = [];
        foreach ($recipients as $recipient) {
            if (preg_match('/^\{(?:field\[)?(\w+)\]?\}$/', $recipient, $matches)) {
                $handle = $matches[1];
                $email = $this->resolveDynamicRecipient($form, $submission, $handle, $notification['name'] ?? 'unknown');
                if ($email !== null) {
                    $parsedRecipients[] = $email;
                }
            } elseif (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $parsedRecipients[] = $recipient;
            } else {
                EasyForm::log("Invalid recipient email: $recipient", 'warning');
            }
        }

        if (empty($parsedRecipients)) {
            // Misconfiguration, not a transient failure — log a warning and
            // succeed gracefully so the queue job does not retry forever.
            EasyForm::log("No valid recipients for notification " . ($notification['name'] ?? 'unknown'), 'warning');
            return true;
        }

        // Prepare variables for template
        $variables = [
            'submission' => $submission,
            'form' => $form,
            'data' => $data,
        ];

        try {
            // Determine template path
            $template = $notification['template'] ?? '';
            $siteContent = '';
            $useTwig = false;
            
            // Determine site handle
            $siteHandle = null;
            if ($submission->siteId) {
                $site = Craft::$app->sites->getSiteById($submission->siteId);
                if ($site) {
                    $siteHandle = $site->handle;
                }
            }
            
            // Expose the resolved site to Twig templates so they can render
            // site-aware (translated) field labels via form.resolveFieldLabel().
            $variables['siteHandle'] = $siteHandle;

            // Check for site-specific settings
            if ($siteHandle) {
                $useTwig = !empty($notification['siteUseTwig'][$siteHandle]);
                
                if ($useTwig) {
                    if (!empty($notification['siteTemplates'][$siteHandle])) {
                        $template = $notification['siteTemplates'][$siteHandle];
                        EasyForm::log("Using site-specific template '$template' for site '$siteHandle'", 'info');
                    }
                } else {
                    $siteContent = $notification['siteContent'][$siteHandle] ?? '';
                }
            }

            $actualTemplate = 'Default'; // Default value for logging
            $htmlBody = '';

            if (!empty($siteContent) && !$useTwig) {
                // Render Custom Content. The notification's contentFormat decides how
                // the author's text is treated (simple text / Markdown / raw HTML);
                // tokens are always expanded with escaped values either way.
                $format = $notification['contentFormat'] ?? 'simple';
                $htmlBody = $this->parseContent($siteContent, $submission, $form, $siteHandle, $format);
                $actualTemplate = 'Custom Content (' . ($siteHandle ?: 'General') . ', ' . $format . ')';
            } elseif ($template) {
                // Switch to site template mode to find user templates
                $oldMode = Craft::$app->view->getTemplateMode();
                Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);

                try {
                    if (Craft::$app->view->doesTemplateExist($template)) {
                        $htmlBody = Craft::$app->view->renderTemplate($template, $variables);
                        $actualTemplate = $template;
                    } else {
                        EasyForm::log("Notification template '$template' not found for form {$form->handle}", 'warning');
                        $htmlBody = $this->renderDefaultEmail($variables);
                        $actualTemplate = 'Default (Fallback)';
                    }
                } finally {
                    Craft::$app->view->setTemplateMode($oldMode);
                }
            } else {
                $htmlBody = $this->renderDefaultEmail($variables);
            }

            // Create Message
            $message = new Message();
            $message->setSubject($notification['subject'] ?? 'New form submission');
            $message->setHtmlBody($htmlBody);
            
            // Set Sender — fall back to the system mail settings (Craft 5 has no
            // systemSettings component; use App::mailSettings()).
            $mailSettings = App::mailSettings();
            $fromEmail = !empty($notification['senderEmail']) ? $notification['senderEmail'] : App::parseEnv($mailSettings->fromEmail);
            $fromName = !empty($notification['senderName']) ? $notification['senderName'] : App::parseEnv($mailSettings->fromName);

            if (!$fromEmail) {
                EasyForm::log("No sender email configured in form settings or system settings", 'error');
                return false;
            }

            $message->setFrom([$fromEmail => $fromName]);

            // Set Reply-To — validate/resolve like recipients so a placeholder
            // (e.g. {email}) or a typo can't throw at send time and permanently
            // fail every notification for this form.
            if (!empty($notification['replyTo'])) {
                $replyTo = $this->parseEmailList($notification['replyTo'], $submission);
                if (!empty($replyTo)) {
                    $message->setReplyTo($replyTo);
                } else {
                    EasyForm::log("Reply-To '{$notification['replyTo']}' resolved to no valid address; skipping.", 'warning');
                }
            }

            // CC / BCC (comma-separated, with {handle} dynamic support).
            $cc = $this->parseEmailList($notification['cc'] ?? '', $submission);
            if ($cc) {
                $message->setCc($cc);
            }
            $bcc = $this->parseEmailList($notification['bcc'] ?? '', $submission);
            if ($bcc) {
                $message->setBcc($bcc);
            }

            // Attach uploaded files (opt-in, with a global size guard).
            if (!empty($notification['attachFiles'])) {
                $this->attachSubmissionFiles($message, $form, $submission);
            }

            $sentCount = 0;
            // Send to all parsed recipients
            foreach ($parsedRecipients as $email) {
                $message->setTo($email);
                
                $logDetails = sprintf(
                    "Subject: %s, To: %s, From: %s, Reply-To: %s, Template: %s",
                    $notification['subject'] ?? 'New form submission',
                    $email,
                    "$fromName <$fromEmail>",
                    !empty($notification['replyTo']) ? $notification['replyTo'] : 'None',
                    $actualTemplate
                );

                if (Craft::$app->mailer->send($message)) {
                    EasyForm::log("Notification sent successfully. [$logDetails]", 'info');
                    $sentCount++;
                } else {
                    EasyForm::log("Failed to send notification. [$logDetails]", 'error');
                }
            }
            
            return $sentCount > 0;

        } catch (\Throwable $e) {
            EasyForm::log("Failed to send notification '{$notification['name']}' for submission {$submission->id}: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Parses content with placeholders
     */
    private function parseContent(string $content, Submission $submission, Form $form, ?string $siteHandle = null, string $format = 'simple'): string
    {
        $format = in_array($format, ['simple', 'markdown', 'html'], true) ? $format : 'simple';

        // Pre-process the author's literal text per format. Token braces aren't
        // HTML-special, so they survive escaping and are expanded below.
        if ($format === 'html') {
            // Raw HTML authored in the CP — passed through untouched.
            // (Newlines are insignificant; the author controls the markup.)
        } elseif ($format === 'markdown') {
            // Escape so author text can't inject HTML; Markdown still formats it.
            $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        } else { // simple
            $content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'), false);
        }

        // Escape a resolved value/label, optionally bolding it. Newlines inside a
        // value become <br> except in Markdown mode, where the Markdown pass
        // (enableNewlines) handles them.
        $brValues = $format !== 'markdown';
        $fmt = function (string $s, bool $bold) use ($brValues): string {
            $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            if ($brValues) {
                $s = nl2br($s, false);
            }
            return $bold ? "<strong>{$s}</strong>" : $s;
        };
        // A token's optional "|b" flag (group 2 of the regexes below) means "bold".
        $isBold = fn($m) => !empty($m[2]);

        // {field[handle]} / {field[handle]|b} — value (builder or frontend field).
        $content = preg_replace_callback('/\{field\[(\w+)\](\|b)?\}/', function($matches) use ($submission, $form, $siteHandle, $fmt, $isBold) {
            $handle = $matches[1];
            $val = $submission->getFieldValue($handle);
            $field = $form->getAnyFieldByHandle($handle);
            return $fmt($this->getDisplayValue($val, $field, $siteHandle), $isBold($matches));
        }, $content);

        // {label[handle]} / {label[handle]|b} — site-aware label.
        $content = preg_replace_callback('/\{label\[(\w+)\](\|b)?\}/', function($matches) use ($form, $siteHandle, $fmt, $isBold) {
            return $fmt($form->resolveFieldLabel($matches[1], $siteHandle), $isBold($matches));
        }, $content);

        // {combo[handle]} -> Label (on its own line) + Value. |b bolds the label.
        $content = preg_replace_callback('/\{combo\[(\w+)\](\|b)?\}/', function($matches) use ($submission, $form, $siteHandle, $fmt, $isBold) {
            $handle = $matches[1];
            $field = $form->getAnyFieldByHandle($handle);
            if (!$field) return $matches[0];

            $label = $fmt($form->resolveFieldLabel($handle, $siteHandle), $isBold($matches));
            $valueStr = $fmt($this->getDisplayValue($submission->getFieldValue($handle), $field, $siteHandle), false);

            return "{$label}<br>{$valueStr}<br>";
        }, $content);

        // {comboInline[handle]} -> Label: Value (one line). |b bolds the label.
        $content = preg_replace_callback('/\{comboInline\[(\w+)\](\|b)?\}/', function($matches) use ($submission, $form, $siteHandle, $fmt, $isBold) {
            $handle = $matches[1];
            $field = $form->getAnyFieldByHandle($handle);
            if (!$field) return $matches[0];

            $bold = $isBold($matches);
            $label = htmlspecialchars($form->resolveFieldLabel($handle, $siteHandle), ENT_QUOTES, 'UTF-8');
            $labelHtml = $bold ? "<strong>{$label}:</strong>" : "{$label}:";
            $valueStr = $fmt($this->getDisplayValue($submission->getFieldValue($handle), $field, $siteHandle), false);

            return "{$labelHtml} {$valueStr}<br>";
        }, $content);

        // {table[h1,h2,…]} -> a Label | Value table of the listed fields;
        // bare {table} -> every (non-presentational) field. Builder order.
        $content = preg_replace_callback('/\{table(?:\[([a-zA-Z0-9_,]*)\])?\}/', function($matches) use ($submission, $form, $siteHandle) {
            $requested = array_values(array_filter(explode(',', $matches[1] ?? ''), fn($h) => $h !== ''));
            return $this->renderFieldTable($submission, $form, $siteHandle, $requested);
        }, $content);

        // Markdown mode: format the (token-expanded) source. Single newlines become
        // line breaks, and images are stripped — they're disallowed in emails.
        if ($format === 'markdown') {
            $parser = new GithubMarkdown();
            $parser->html5 = true;
            $parser->enableNewlines = true;
            $content = $parser->parse($content);
            $content = preg_replace('/<img\b[^>]*>/i', '', $content);
        }

        return $content;
    }

    /**
     * Helper to parse options from different formats (Array, JSON, or "value:label" string)
     */
    private function parseOptions($options): array
    {
        if (is_array($options)) {
            return $options;
        }
        
        if (is_string($options)) {
            $decoded = Json::decodeIfJson($options);
            if (is_array($decoded)) {
                return $decoded;
            }
            
            // Parse newline separated value:label string
            $parsedOpts = [];
            $lines = preg_split('/\r\n|\r|\n/', $options);
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                
                $parts = explode(':', $line, 2);
                if (count($parts) >= 2) {
                    $parsedOpts[] = [
                        'value' => trim($parts[0]),
                        'label' => trim($parts[1])
                    ];
                } else {
                    $val = trim($line);
                    $parsedOpts[] = [
                        'value' => $val,
                        'label' => $val
                    ];
                }
            }
            return $parsedOpts;
        }
        
        return [];
    }

    /**
     * Helper to get display value for fields
     */
    /**
     * Render a Label | Value HTML table for the submission. $requested is a list
     * of field handles (empty = every non-presentational field). Rows follow the
     * builder's field order. Uses inline styles so it survives email clients.
     *
     * @param string[] $requested
     */
    private function renderFieldTable(Submission $submission, Form $form, ?string $siteHandle, array $requested): string
    {
        $schema = EasyForm::getInstance()->formSchema;
        $fields = $schema->getAllFields($schema->normalize($form->getFieldLayoutArray()));

        $rows = '';
        foreach ($fields as $field) {
            $handle = $field['handle'] ?? '';
            if ($handle === '' || $schema->isPresentationalType($field['type'] ?? '')) {
                continue;
            }
            if (!empty($requested) && !in_array($handle, $requested, true)) {
                continue;
            }
            $label = htmlspecialchars($form->resolveFieldLabel($handle, $siteHandle), ENT_QUOTES, 'UTF-8');
            $value = nl2br(htmlspecialchars(
                $this->getDisplayValue($submission->getFieldValue($handle), $field, $siteHandle),
                ENT_QUOTES,
                'UTF-8'
            ), false);
            $rows .= '<tr>'
                . '<td style="padding:8px 12px;border:1px solid #e0e0e0;background:#f7f7f7;font-weight:bold;text-align:left;vertical-align:top;">' . $label . '</td>'
                . '<td style="padding:8px 12px;border:1px solid #e0e0e0;vertical-align:top;">' . ($value !== '' ? $value : '&mdash;') . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '';
        }
        return '<table style="border-collapse:collapse;border:1px solid #e0e0e0;margin:8px 0;width:100%;max-width:600px;font-size:14px;">'
            . $rows . '</table>';
    }

    private function getDisplayValue($value, ?array $field, ?string $siteHandle): string
    {
        if (!$field) {
            // formatValueForEmail flattens file/nested arrays safely (a plain
            // implode would throw "Array to string conversion" on a file value).
            return $this->formatValueForEmail($value);
        }

        $type = $field['type'] ?? '';

        if (!in_array($type, ['select', 'checkboxes'])) {
            return $this->formatValueForEmail($value);
        }

        // Get options
        $options = $this->parseOptions($field['options'] ?? []);
        
        // Check site options
        if ($siteHandle && !empty($field['siteOptions'][$siteHandle])) {
            $siteOpts = $this->parseOptions($field['siteOptions'][$siteHandle]);
            
            if (!empty($siteOpts)) {
                $options = $siteOpts;
            }
        }
        
        // Helper to find label
        $findLabel = function($val) use ($options) {
            foreach ($options as $opt) {
                // Ensure we compare strings to avoid type mismatch issues
                // Check if opt is array/object
                $optVal = is_array($opt) ? ($opt['value'] ?? '') : '';
                $optLabel = is_array($opt) ? ($opt['label'] ?? '') : '';
                
                if ((string)$optVal === (string)$val) {
                    // If label is empty, return value (fallback 1)
                    return (string)($optLabel !== '' ? $optLabel : $val);
                }
            }
            // Value not found in options, return value (fallback 2)
            return (string)$val;
        };

        if (is_array($value)) {
            $labels = array_map($findLabel, $value);
            return implode(', ', $labels);
        }

        return $findLabel($value);
    }

    /**
     * Renders a default email body if no template is specified
     */
    private function renderDefaultEmail(array $variables): string
    {
        $submission = $variables['submission'];
        $form = $variables['form'];
        $data = $variables['data'];
        
        // Resolve the submission's site for site-aware labels.
        $siteHandle = null;
        if ($submission->siteId) {
            $site = Craft::$app->sites->getSiteById($submission->siteId);
            $siteHandle = $site?->handle;
        }

        $html = "<h1>New submission for {$form->name}</h1>";
        $html .= "<ul>";

        $rows = [];
        foreach ($form->getFields() as $field) {
            $rows[] = $field['handle'];
        }
        // Include frontend fields that opt into notifications.
        foreach ($form->getFrontendFields() as $field) {
            if (!empty($field['notifications'])) {
                $rows[] = $field['handle'];
            }
        }

        foreach ($rows as $handle) {
            $label = $form->resolveFieldLabel($handle, $siteHandle);
            $value = $this->formatValueForEmail($data[$handle] ?? '');
            $html .= "<li><strong>" . htmlspecialchars($label) . ":</strong> " . htmlspecialchars($value) . "</li>";
        }

        $html .= "</ul>";

        return $html;
    }
}
