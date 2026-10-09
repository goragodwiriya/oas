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
            $emails = [$mailto];
            if ($cc != '') {
                $emails[] = $cc;
            }
            if ($bcc != '') {
                $emails[] = $bcc;
            }

            $headers = "MIME-Version: 1.0\r\n";
            $headers .= 'Content-type: text/html; charset='.strtoupper($charset)."\r\n";
            $headers .= 'From: '.$replyto[0]."\r\n";
            $headers .= "Reply-to: $replyto[1]\r\n";

            if (!@mail(implode(',', $emails), $subject, $msg, $headers)) {
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

            foreach (explode(',', $mailto) as $email) {
                if (preg_match('/^(.*)<(.*)>$/', $email, $match)) {
                    if ($mail->validateAddress($match[2])) {
                        $mail->addAddress($match[2], strip_tags($match[1]));
                    }
                } elseif ($mail->validateAddress($email)) {
                    $mail->addAddress($email);
                }

                if ($cc != '') {
                    foreach (explode(',', $cc) as $cc_email) {
                        if ($mail->validateAddress($cc_email)) {
                            $mail->addCC($cc_email);
                        }
                    }
                }

                if ($bcc != '') {
                    foreach (explode(',', $bcc) as $bcc_email) {
                        if ($mail->validateAddress($bcc_email)) {
                            $mail->addBCC($bcc_email);
                        }
                    }
                }

                $err = $mail->send();

                if ($err === false) {
                    $obj->error[$mail->ErrorInfo] = strip_tags($mail->ErrorInfo);
                }

                $mail->clearAddresses();
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
}
