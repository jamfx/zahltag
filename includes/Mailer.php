<?php
declare(strict_types=1);

/**
 * Mailer – wraps SMTP (via socket), sendmail, and Brevo REST API.
 * No external library required beyond native PHP.
 */
class Mailer
{
    private string  $method;
    private array   $cfg;
    private string  $fromEmail;
    private string  $fromName;
    private ?string $lastError = null;

    private function __construct(array $cfg)
    {
        $this->method    = $cfg['method']     ?? 'sendmail';
        $this->fromEmail = $cfg['from_email'] ?? '';
        $this->fromName  = $cfg['from_name']  ?? '';
        $this->cfg       = $cfg;
    }

    public static function fromSettings(): self
    {
        $cfg = [];
        try {
            $row = db()->query('SELECT * FROM email_settings LIMIT 1')->fetch();
            if ($row) {
                $cfg = [
                    'method'     => $row['method']     ?? 'sendmail',
                    'from_email' => $row['from_email'] ?? '',
                    'from_name'  => $row['from_name']  ?? '',
                    'smtp_host'  => $row['smtp_host']  ?? '',
                    'smtp_port'  => (int)($row['smtp_port'] ?? 587),
                    'smtp_user'  => $row['smtp_user']  ?? '',
                    'smtp_pass'  => $row['smtp_pass']  ?? '',
                    'smtp_encryption' => $row['smtp_encryption'] ?? 'tls',
                    'brevo_api_key'   => $row['brevo_api_key']   ?? '',
                ];
            }
        } catch (Throwable) {}
        return new self($cfg);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Send an HTML email. Falls back to text/plain if no HTML.
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $this->lastError = null;

        if ($textBody === '') {
            $textBody = strip_tags($htmlBody);
        }

        return match ($this->method) {
            'smtp'     => $this->sendSmtp($to, $subject, $htmlBody, $textBody),
            'brevo'    => $this->sendBrevo($to, $subject, $htmlBody, $textBody),
            default    => $this->sendMail($to, $subject, $htmlBody, $textBody),
        };
    }

    // ── sendmail ──────────────────────────────────────────────────────────────

    private function sendMail(string $to, string $subject, string $html, string $text): bool
    {
        $boundary  = '====Zahltag_' . bin2hex(random_bytes(8)) . '====';
        $fromField = $this->fromName
            ? '"' . addslashes($this->fromName) . '" <' . $this->fromEmail . '>'
            : $this->fromEmail;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "From: {$fromField}\r\n";
        $headers .= "X-Mailer: Zahltag\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($text) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($html) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $result = @mail(
            $to,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $body,
            $headers
        );

        if (!$result) {
            $this->lastError = 'mail() returned false';
        }
        return $result;
    }

    // ── SMTP (plain socket, supports STARTTLS and SSL) ────────────────────────

    private function sendSmtp(string $to, string $subject, string $html, string $text): bool
    {
        $host       = $this->cfg['smtp_host'] ?? '';
        $port       = $this->cfg['smtp_port'] ?? 587;
        $user       = $this->cfg['smtp_user'] ?? '';
        $pass       = $this->cfg['smtp_pass'] ?? '';
        $encryption = $this->cfg['smtp_encryption'] ?? 'tls';

        if (!$host) {
            $this->lastError = 'SMTP host not configured';
            return false;
        }

        $socketHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;

        $sock = @fsockopen($socketHost, (int)$port, $errno, $errstr, 15);
        if (!$sock) {
            $this->lastError = "SMTP connect failed ({$errno}): {$errstr}";
            return false;
        }
        stream_set_timeout($sock, 15);

        try {
            $this->smtpRead($sock); // 220 greeting

            $this->smtpSend($sock, 'EHLO ' . (gethostname() ?: 'localhost'));
            $ehloResp = $this->smtpRead($sock);

            if ($encryption === 'tls') {
                $this->smtpSend($sock, 'STARTTLS');
                $this->smtpRead($sock); // 220
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = 'STARTTLS failed';
                    fclose($sock);
                    return false;
                }
                $this->smtpSend($sock, 'EHLO ' . (gethostname() ?: 'localhost'));
                $ehloResp = $this->smtpRead($sock);
            }

            if ($user && $pass) {
                $this->smtpSend($sock, 'AUTH LOGIN');
                $this->smtpRead($sock); // 334
                $this->smtpSend($sock, base64_encode($user));
                $this->smtpRead($sock); // 334
                $this->smtpSend($sock, base64_encode($pass));
                $resp = $this->smtpRead($sock); // 235
                if (!str_starts_with($resp, '235')) {
                    $this->lastError = 'SMTP authentication failed: ' . $resp;
                    fclose($sock);
                    return false;
                }
            }

            $fromField = $this->fromName
                ? '"' . addslashes($this->fromName) . '" <' . $this->fromEmail . '>'
                : $this->fromEmail;

            $this->smtpSend($sock, 'MAIL FROM:<' . $this->fromEmail . '>');
            $this->smtpRead($sock);
            $this->smtpSend($sock, 'RCPT TO:<' . $to . '>');
            $resp = $this->smtpRead($sock);
            if (!str_starts_with($resp, '25')) {
                $this->lastError = 'RCPT rejected: ' . $resp;
                fclose($sock);
                return false;
            }

            $this->smtpSend($sock, 'DATA');
            $this->smtpRead($sock); // 354

            $boundary = '====Zahltag_' . bin2hex(random_bytes(8)) . '====';
            $msg  = "From: {$fromField}\r\n";
            $msg .= "To: {$to}\r\n";
            $msg .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $msg .= "X-Mailer: Zahltag\r\n";
            $msg .= "\r\n";
            $msg .= "--{$boundary}\r\n";
            $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $msg .= quoted_printable_encode($text) . "\r\n";
            $msg .= "--{$boundary}\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $msg .= quoted_printable_encode($html) . "\r\n";
            $msg .= "--{$boundary}--\r\n";
            $msg .= ".\r\n";

            fwrite($sock, $msg);
            $resp = $this->smtpRead($sock);
            if (!str_starts_with($resp, '25')) {
                $this->lastError = 'Message rejected: ' . $resp;
                fclose($sock);
                return false;
            }

            $this->smtpSend($sock, 'QUIT');
            fclose($sock);
            return true;

        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            @fclose($sock);
            return false;
        }
    }

    private function smtpSend(mixed $sock, string $cmd): void
    {
        fwrite($sock, $cmd . "\r\n");
    }

    private function smtpRead(mixed $sock): string
    {
        $resp = '';
        while (!feof($sock)) {
            $line  = fgets($sock, 512);
            $resp .= $line;
            // Multi-line responses: "250-..." continues, "250 " ends
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return trim($resp);
    }

    // ── Brevo REST API ────────────────────────────────────────────────────────

    private function sendBrevo(string $to, string $subject, string $html, string $text): bool
    {
        $apiKey = $this->cfg['brevo_api_key'] ?? '';
        if (!$apiKey) {
            $this->lastError = 'Brevo API key not configured';
            return false;
        }

        $payload = json_encode([
            'sender'      => ['name' => $this->fromName, 'email' => $this->fromEmail],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ], JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'api-key: ' . $apiKey,
                    'Accept: application/json',
                ]),
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
        $httpCode = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) {
                    $httpCode = (int)$m[1];
                }
            }
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        $this->lastError = 'Brevo API error ' . $httpCode . ': ' . ($response ?: '(no body)');
        return false;
    }
}
