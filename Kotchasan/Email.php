<?php
namespace Kotchasan;

/**
 * Kotchasan Email Class
 *
 * This class provides methods for sending emails with various configurations.
 * It supports both PHP's mail function and PHPMailer for sending emails.
 *
 * @package Kotchasan
 */
class Email extends \Kotchasan\KBase
{
    /**
     * @var mixed Error information for email sending
     */
    protected $error;

    /**
     * Check if there is an error in email sending.
     *
     * @return bool True if there is an error, false if the email was sent successfully
     */
    public function error()
    {
        return empty($this->error) ? false : true;
    }

    /**
     * Get the error message of the email sending.
     *
     * @return string Error message. If there is no error, an empty string is returned.
     */
    public function getErrorMessage()
    {
        return empty($this->error) ? '' : implode("\n", $this->error);
    }

    /**
     * Send an email with custom details.
     *
     * @param string $mailto   Email address(es) of the recipient(s). Can be multiple addresses separated by commas.
     * @param string $replyto  Email address for the reply-to field. If empty, the noreply_email address will be used.
     * @param string $subject  Email subject.
     * @param string $msg      Email content. HTML is supported.
     * @param string $cc       Email address(es) to be included in the CC field. Can be multiple addresses separated by commas.
     * @param string $bcc      Email address(es) to be included in the BCC field. Can be multiple addresses separated by commas.
     *
     * @return static
     */
    public static function send($mailto, $replyto, $subject, $msg, $cc = '', $bcc = '')
    {
        $obj = new static();
        $obj->error = [];

        $charset = empty(self::$cfg->email_charset) ? 'utf-8' : strtolower(self::$cfg->email_charset);

        if (empty($replyto)) {
            $replyto = [strip_tags(self::$cfg->web_title), self::$cfg->noreply_email];
        } elseif (preg_match('/^(.*)<(.*?)>$/', $replyto, $match)) {
            $replyto = [strip_tags($match[1]), (empty($match[2]) ? $match[1] : $match[2])];
        } else {
            $replyto = [$replyto, $replyto];
        }

        if ($charset != 'utf-8') {
            $subject = iconv('utf-8', $charset, $subject);
            $msg = iconv('utf-8', $charset, $msg);
            $replyto[0] = iconv('utf-8', $charset, $replyto[0]);
        }

        $msg = preg_replace(['/<\?/', '/\?>/'], ['&lt;?', '?&gt;'], $msg);

        if (empty(self::$cfg->email_use_phpMailer)) {
            // Send email using PHP's mail() function
            if (!self::phpMail($charset, $mailto, $replyto, $subject, $msg, $cc, $bcc)) {
                $obj->error['Unable to send mail'] = Language::get('Unable to send mail');
            }
        } else {
            // Send email using PHPMailer
            $mail = self::mailer($charset);

            $mail->AddReplyTo($replyto[1], $replyto[0]);

            if ($mail->ValidateAddress(self::$cfg->noreply_email)) {
                $mail->SetFrom(self::$cfg->noreply_email, strip_tags(self::$cfg->web_title));
            }

            $mail->Subject = $subject;
            $mail->MsgHTML(preg_replace('/(<br([\s\/]{0,})>)/', "$1\r\n", $msg));
            $mail->AltBody = strip_tags($msg);

            // Each recipient gets its own message; CC and BCC get one copy, with the first message
            $copies = true;
            foreach (explode(',', $mailto) as $email) {
                $email = trim($email);
                if (preg_match('/^(.*)<(.*)>$/', $email, $match)) {
                    if ($mail->validateAddress(trim($match[2]))) {
                        $mail->addAddress(trim($match[2]), trim(strip_tags($match[1])));
                    }
                } elseif ($mail->validateAddress($email)) {
                    $mail->addAddress($email);
                }

                if ($copies && $cc != '') {
                    foreach (explode(',', $cc) as $cc_email) {
                        if ($mail->validateAddress(trim($cc_email))) {
                            $mail->addCC(trim($cc_email));
                        }
                    }
                }

                if ($copies && $bcc != '') {
                    foreach (explode(',', $bcc) as $bcc_email) {
                        if ($mail->validateAddress(trim($bcc_email))) {
                            $mail->addBCC(trim($bcc_email));
                        }
                    }
                }
                $copies = false;

                $err = $mail->send();

                if ($err === false) {
                    $obj->error[$mail->ErrorInfo] = strip_tags($mail->ErrorInfo);
                }

                $mail->clearAddresses();
                $mail->clearCCs();
                $mail->clearBCCs();
            }
        }

        return $obj;
    }


    /**
     * A PHPMailer instance configured from the email_* settings (transport, login,
     * encryption, server and timeouts), ready for recipients and content.
     *
     * @param string $charset
     *
     * @return \PHPMailer
     */
    public static function mailer($charset = 'utf-8')
    {
        include_once VENDOR_DIR.'PHPMailer/class.phpmailer.php';

        $mail = new \PHPMailer();

        if (self::$cfg->email_use_phpMailer == 1) {
            // Send messages using SMTP
            $mail->isSMTP();
        } else {
            // Send messages using PHP's mail() function
            $mail->isMail();
        }

        $mail->CharSet = $charset;
        $mail->IsHTML();
        $mail->SMTPAuth = empty(self::$cfg->email_SMTPAuth) ? false : true;

        if ($mail->SMTPAuth) {
            $mail->Username = self::$cfg->email_Username;
            $mail->Password = self::$cfg->email_Password;
        }

        // Encryption does not depend on authentication: a relay without a login may still require STARTTLS
        $mail->SMTPSecure = self::smtpSecure(self::$cfg->email_SMTPSecure ?? '');

        if (!empty(self::$cfg->email_Host)) {
            $mail->Host = self::$cfg->email_Host;
        }

        if (!empty(self::$cfg->email_Port)) {
            $mail->Port = self::$cfg->email_Port;
        }

        // Don't let an unreachable mail server hold the request for PHPMailer's default 300 seconds:
        // Timeout covers the connection and each read, Timelimit the wait for each SMTP reply
        $timeout = isset(self::$cfg->email_Timeout) ? (int) self::$cfg->email_Timeout : 15;
        $mail->Timeout = $timeout > 0 ? $timeout : 15;
        $mail->getSMTPInstance()->Timelimit = $mail->Timeout;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        return $mail;
    }

    /**
     * Normalise the email_SMTPSecure setting to a PHPMailer SMTPSecure value.
     *
     * - 'tls' or 'starttls': STARTTLS — connect in plain text and upgrade (usually port 587)
     * - 'ssl': implicit TLS from the first byte (SMTPS, usually port 465)
     * - anything else: no forced encryption; PHPMailer still upgrades with STARTTLS
     *   when the server offers it (SMTPAutoTLS)
     *
     * @param string $value
     *
     * @return string '', 'tls' or 'ssl'
     */
    public static function smtpSecure($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'starttls') {
            return 'tls';
        }

        return in_array($value, ['tls', 'ssl'], true) ? $value : '';
    }

    /**
     * Send with PHP's mail() function.
     *
     * Each address in $mailto gets its own message, as in the PHPMailer branch, so
     * recipients don't see each other; CC and BCC go with the first message only.
     * From is "web_title <noreply_email>" with noreply_email as the envelope sender,
     * names and the subject are RFC 2047 encoded, and the body is a base64
     * multipart/alternative (text + HTML), so non-ASCII text and long lines arrive intact.
     *
     * @param string $charset  Lowercase charset of $subject and $msg
     * @param string $mailto   Recipients, comma separated, "email" or "Name <email>"
     * @param array  $replyto  [name, email]
     * @param string $subject
     * @param string $msg      HTML content
     * @param string $cc
     * @param string $bcc
     *
     * @return bool True when every address was valid and every message was accepted for delivery
     */
    private static function phpMail($charset, $mailto, array $replyto, $subject, $msg, $cc, $bcc)
    {
        // PHP 8 needs CRLF between headers; older PHP on Unix hands the message to sendmail as is
        $eol = (PHP_VERSION_ID >= 80000 || stripos(PHP_OS, 'WIN') === 0) ? "\r\n" : PHP_EOL;

        $recipients = self::formatAddresses($mailto, $charset, $eol);
        if (empty($recipients)) {
            return false;
        }
        // An invalid address is reported (as in the PHPMailer branch) after the valid ones are sent
        $success = count($recipients) === count(array_filter(array_map('trim', explode(',', $mailto)), 'strlen'));

        $headers = ['MIME-Version: 1.0'];
        $params = '';
        $noreply = (string) self::$cfg->noreply_email;
        if (filter_var($noreply, FILTER_VALIDATE_EMAIL)) {
            $name = strip_tags((string) self::$cfg->web_title);
            if ($charset != 'utf-8') {
                $name = iconv('utf-8', $charset, $name);
            }
            $headers[] = 'From: '.self::formatAddress($noreply, $name, $charset, $eol);
            // Envelope sender: bounces come back to the site and SPF checks its domain, not the web server user
            if (preg_match('/^[a-zA-Z0-9._+\-]+@[a-zA-Z0-9.\-]+$/', $noreply)) {
                $params = '-f'.$noreply;
            }
        }
        if (filter_var($replyto[1], FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: '.self::formatAddress($replyto[1], $replyto[0], $charset, $eol);
        }

        $boundary = 'b1_'.bin2hex(random_bytes(16));
        $headers[] = 'Content-Type: multipart/alternative; boundary="'.$boundary.'"';
        $text = strip_tags($msg);
        if ($charset == 'utf-8') {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $body = '';
        foreach (['text/plain' => $text, 'text/html' => $msg] as $type => $content) {
            $body .= '--'.$boundary.$eol;
            $body .= 'Content-Type: '.$type.'; charset='.strtoupper($charset).$eol;
            $body .= 'Content-Transfer-Encoding: base64'.$eol.$eol;
            $body .= chunk_split(base64_encode($content), 76, $eol);
        }
        $body .= '--'.$boundary.'--'.$eol;

        $subject = self::encodeHeader($subject, $charset, $eol);
        $copies = [
            'Cc' => self::formatAddresses($cc, $charset, $eol),
            'Bcc' => self::formatAddresses($bcc, $charset, $eol)
        ];
        foreach ($recipients as $to) {
            $extra = $headers;
            foreach ($copies as $field => $addresses) {
                if (!empty($addresses)) {
                    $extra[] = $field.': '.implode(', ', $addresses);
                }
            }
            $copies = [];
            if (!@mail($to, $subject, $body, implode($eol, $extra), $params)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Valid addresses of a comma separated list, ready for a header.
     *
     * @param string $list    "email" or "Name <email>", comma separated
     * @param string $charset
     * @param string $eol     Line ending used to fold a long encoded name
     *
     * @return array
     */
    private static function formatAddresses($list, $charset, $eol)
    {
        $result = [];
        foreach (explode(',', (string) $list) as $item) {
            $item = trim($item);
            if (preg_match('/^(.*)<(.*)>$/', $item, $match)) {
                $name = trim($match[1]);
                $email = trim($match[2]);
            } else {
                $name = '';
                $email = $item;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if ($name !== '' && $charset != 'utf-8') {
                    $name = iconv('utf-8', $charset, $name);
                }
                $result[] = self::formatAddress($email, $name, $charset, $eol);
            }
        }

        return $result;
    }

    /**
     * "Name <email>" with the name encoded, or just the email when there is no name.
     *
     * @param string $email   A validated address
     * @param string $name
     * @param string $charset
     * @param string $eol     Line ending used to fold a long encoded name
     *
     * @return string
     */
    private static function formatAddress($email, $name, $charset, $eol)
    {
        $name = trim(strip_tags((string) $name));
        if ($name === '' || $name === $email) {
            return $email;
        }

        return self::encodeHeader($name, $charset, $eol, true).' <'.$email.'>';
    }

    /**
     * Header text as RFC 2047 encoded-words when it is not plain ASCII.
     * CR and LF are removed so the value cannot add header lines.
     *
     * @param string $text
     * @param string $charset
     * @param string $eol    Line ending used to fold between encoded-words
     * @param bool   $phrase True for a display name: plain ASCII with specials is quoted
     *
     * @return string
     */
    private static function encodeHeader($text, $charset, $eol, $phrase = false)
    {
        $text = str_replace(["\r", "\n"], '', (string) $text);
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            if ($phrase && preg_match('/[()<>\[\]:;@\\\\,."]/', $text)) {
                return '"'.addcslashes($text, '"\\').'"';
            }

            return $text;
        }

        // At most 45 bytes per word (60 base64 characters) keeps each word under 75 characters,
        // split on character boundaries so no multi-byte character is cut in half
        $chars = $charset == 'utf-8' ? preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) : false;
        if ($chars === false) {
            $chars = str_split($text);
        }
        $words = [];
        $chunk = '';
        foreach ($chars as $char) {
            if ($chunk !== '' && strlen($chunk.$char) > 45) {
                $words[] = '=?'.strtoupper($charset).'?B?'.base64_encode($chunk).'?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        $words[] = '=?'.strtoupper($charset).'?B?'.base64_encode($chunk).'?=';

        return implode($eol.' ', $words);
    }
}
