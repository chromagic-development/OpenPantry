<?php
// Outbound email for OpenPantry (currently: reorder-reminder notifications).
//
// op_send_mail() sends one message and returns ['ok'=>bool, 'error'=>string].
// Two backends, chosen by whether an SMTP host is configured in Settings:
//
//   * SMTP  — a minimal, dependency-free SMTP client (fsockopen + AUTH LOGIN
//             + optional SSL/STARTTLS). Use this when your host blocks the
//             local mail transport or you want authenticated, deliverable
//             mail from a real mailbox (recommended on shared cPanel hosting).
//   * mail()— PHP's built-in transport (local sendmail/exim). Used when no
//             SMTP host is set. Simple, but messages may land in spam.
//
// Settings keys (all under Settings → Email Notifications):
//   smtp_host, smtp_port, smtp_security ('' | 'ssl' | 'tls'),
//   smtp_user, smtp_pass (encrypted at rest), smtp_from, smtp_from_name.
//
// No external libraries / Composer — this file is self-contained so it works
// on a bare PHP install the same way the rest of OpenPantry does.

require_once __DIR__ . '/db.php'; // setting()

// Split a comma/semicolon-separated address list into valid and invalid parts.
// Valid addresses are de-duplicated, preserving order. Used both to validate
// the admin_email setting and to fan a message out to several recipients.
function op_split_recipients(string $s): array {
    $valid = []; $invalid = [];
    foreach (preg_split('/[,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $t = trim($t);
        if ($t === '') continue;
        if (filter_var($t, FILTER_VALIDATE_EMAIL)) $valid[strtolower($t)] = $t; // dedupe
        else $invalid[] = $t;
    }
    return ['valid' => array_values($valid), 'invalid' => $invalid];
}

// The valid recipient addresses parsed out of a list string.
function op_parse_recipients(string $s): array {
    return op_split_recipients($s)['valid'];
}

// Send an email. $to may be a single address or a comma/semicolon-separated
// list — every valid address receives the message. $opts: ['html' => bool].
function op_send_mail(string $to, string $subject, string $body, array $opts = []): array {
    $recipients = op_parse_recipients($to);
    if (!$recipients) {
        return ['ok' => false, 'error' => 'No valid recipient email address.'];
    }

    $host     = trim((string)setting('smtp_host', ''));
    $isHtml   = !empty($opts['html']);
    $fromName = trim((string)setting('smtp_from_name', ''));
    if ($fromName === '') $fromName = trim((string)(setting('food_pantry_name', '') ?? '')) ?: 'OpenPantry';
    $from = trim((string)setting('smtp_from', ''));
    if ($from === '') $from = trim((string)setting('smtp_user', ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) $from = $recipients[0]; // last resort

    if ($host === '') {
        return op_mail_via_phpmail($recipients, $subject, $body, $from, $fromName, $isHtml);
    }
    return op_mail_via_smtp($recipients, $subject, $body, $from, $fromName, $isHtml);
}

// RFC 2047 encode a header value if it contains non-ASCII bytes.
function op_mail_encode_header(string $s): string {
    if (preg_match('/[^\x20-\x7E]/', $s)) {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
    return $s;
}

// Build the common RFC 5322 headers shared by both backends (To/Subject/Date
// are added by each backend, since mail() supplies its own).
function op_mail_build_message(string $from, string $fromName, bool $isHtml): array {
    $ctype   = $isHtml ? 'text/html' : 'text/plain';
    $fromHdr = $fromName !== ''
        ? op_mail_encode_header($fromName) . ' <' . $from . '>'
        : $from;
    return [
        'From'                      => $fromHdr,
        'Reply-To'                  => $from,
        'MIME-Version'              => '1.0',
        'Content-Type'              => $ctype . '; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];
}

// ── PHP mail() backend ──────────────────────────────────────────────────────
function op_mail_via_phpmail(array $recipients, string $subject, string $body,
                             string $from, string $fromName, bool $isHtml): array {
    if (!function_exists('mail')) {
        return ['ok' => false, 'error' => 'PHP mail() is disabled and no SMTP host is configured.'];
    }
    $headers = op_mail_build_message($from, $fromName, $isHtml);
    $hdrLines = [];
    foreach ($headers as $k => $v) $hdrLines[] = $k . ': ' . $v;
    // mail() accepts a comma-separated recipient list in the To field.
    $to = implode(', ', $recipients);
    $ok = @mail($to, op_mail_encode_header($subject), $body,
                implode("\r\n", $hdrLines), '-f' . $from);
    return $ok
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => 'PHP mail() returned failure (check the server mail log).'];
}

// ── Minimal SMTP backend ────────────────────────────────────────────────────
function op_mail_via_smtp(array $recipients, string $subject, string $body,
                          string $from, string $fromName, bool $isHtml): array {
    $host = trim((string)setting('smtp_host', ''));
    $sec  = strtolower(trim((string)setting('smtp_security', 'tls'))); // '', 'ssl', 'tls'
    $port = (int)trim((string)setting('smtp_port', ''));
    if ($port <= 0) $port = ($sec === 'ssl') ? 465 : 587;
    $user = trim((string)setting('smtp_user', ''));
    $pass = (string)setting('smtp_pass', '');

    // On shared/cPanel hosting the mail server's TLS certificate is usually
    // issued for the *server* hostname, not for mail.<yourdomain>, so strict
    // verification fails with an opaque connect error. The "Allow mismatched /
    // self-signed certificate" option relaxes that (still encrypted, just not
    // identity-verified). Default keeps verification on.
    $insecure  = (setting('smtp_insecure', '0') === '1');
    $sslOpts   = $insecure
        ? ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
        : ['verify_peer' => true,  'verify_peer_name' => true,
           'peer_name'   => $host, 'SNI_enabled' => true];

    $timeout   = 15;
    $transport = ($sec === 'ssl') ? 'ssl://' : '';

    // Capture the real warning text (e.g. the TLS handshake reason) that the
    // stream functions emit, since stream_socket_client's $errstr is often
    // blank on an SSL failure.
    $sslErr = '';
    set_error_handler(function ($no, $str) use (&$sslErr) {
        $sslErr = preg_replace('/^stream_socket_client\(\):\s*/', '', $str);
        return true; // swallow — we surface it ourselves below
    });
    $errno = 0; $errstr = '';
    $fp = stream_socket_client(
        $transport . $host . ':' . $port,
        $errno, $errstr, $timeout,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => $sslOpts])
    );
    restore_error_handler();
    if (!$fp) {
        $detail = trim($errstr) !== '' ? trim($errstr) : trim($sslErr);
        if ($detail === '') $detail = 'no further detail — often a blocked port or a TLS certificate mismatch';
        return ['ok' => false, 'error' => "Could not connect to {$host}:{$port} ({$detail})"];
    }
    stream_set_timeout($fp, $timeout);

    // Conversation helpers. Each returns the response, or sets $err and returns
    // null on an unexpected reply code so the caller can bail cleanly.
    $err = null;
    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // Multiline replies: "250-..." continues, "250 ..." ends.
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $line, $expect) use ($fp, $read, &$err) {
        if ($err !== null) return null;
        if ($line !== '') fwrite($fp, $line . "\r\n");
        $resp = $read();
        $code = (int)substr($resp, 0, 3);
        $ok = is_array($expect) ? in_array($code, $expect, true) : ($code === $expect);
        if (!$ok) {
            $err = 'SMTP error after "' . trim($line) . '": ' . trim($resp);
            return null;
        }
        return $resp;
    };

    $ehloName = (function () use ($from) {
        $d = substr(strrchr($from, '@') ?: '', 1);
        return $d !== '' ? $d : (gethostname() ?: 'localhost');
    })();

    $cmd('', 220);                                   // server greeting
    $cmd('EHLO ' . $ehloName, 250);

    if ($sec === 'tls' && $err === null) {           // STARTTLS upgrade
        $cmd('STARTTLS', 220);
        if ($err === null) {
            // Apply the same verify/insecure choice to the upgraded channel.
            stream_context_set_option($fp, ['ssl' => $sslOpts]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            $sslErr = '';
            set_error_handler(function ($no, $str) use (&$sslErr) { $sslErr = $str; return true; });
            $ok = stream_socket_enable_crypto($fp, true, $crypto);
            restore_error_handler();
            if (!$ok) {
                $err = 'STARTTLS negotiation failed'
                     . (trim($sslErr) !== '' ? ': ' . trim($sslErr) : '')
                     . '. If this is a host certificate-name mismatch, enable '
                     . '"Allow mismatched / self-signed certificate".';
            } else {
                $cmd('EHLO ' . $ehloName, 250);      // re-EHLO over the TLS channel
            }
        }
    }

    if ($user !== '' && $err === null) {             // AUTH LOGIN
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode($user), 334);
        $cmd(base64_encode($pass), 235);
    }

    $cmd('MAIL FROM:<' . $from . '>', 250);
    foreach ($recipients as $rcpt) {          // one envelope recipient per address
        $cmd('RCPT TO:<' . $rcpt . '>', [250, 251]);
    }
    $cmd('DATA', 354);

    if ($err === null) {
        $headers = op_mail_build_message($from, $fromName, $isHtml);
        $headers = ['Date' => date('r'),
                    'To' => implode(', ', $recipients),
                    'Subject' => op_mail_encode_header($subject)] + $headers;
        $msg = '';
        foreach ($headers as $k => $v) $msg .= $k . ': ' . $v . "\r\n";
        $msg .= "\r\n";
        // Normalize line endings and dot-stuff per RFC 5321 §4.5.2.
        $bodyCrlf = preg_replace('/\r\n|\r|\n/', "\r\n", $body);
        $bodyCrlf = preg_replace('/^\./m', '..', $bodyCrlf);
        $msg .= $bodyCrlf;
        $cmd($msg . "\r\n.", 250);
    }

    $cmd('QUIT', [221]);                             // best-effort; ignore code
    fclose($fp);

    return $err === null
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => $err];
}
