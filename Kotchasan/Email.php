<?php
/**
 * @filesource Kotchasan/Email.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Email class for sending emails.
 *
 * @see https://www.kotchasan.com/
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
        $obj = new static;
        $obj->error = [];

        $charset = empty(self::$cfg->email_charset) ? 'utf-8' : strtolower(self::$cfg->email_charset);

        if (empty($replyto)) {
            $replyto = array(strip_tags(self::$cfg->web_title), self::$cfg->noreply_email);
        } elseif (preg_match('/^(.*)<(.*?)>$/', $replyto, $match)) {
            $replyto = array(strip_tags($match[1]), (empty($match[2]) ? $match[1] : $match[2]));
        } else {
            $replyto = array($replyto, $replyto);
        }

        if ($charset != 'utf-8') {
            $subject = iconv('utf-8', $charset, $subject);
            $msg = iconv('utf-8', $charset, $msg);
            $replyto[0] = iconv('utf-8', $charset, $replyto[0]);
        }

        $msg = preg_replace(array('/<\?/', '/\?>/'), array('&lt;?', '?&gt;'), $msg);

        if (empty(self::$cfg->email_use_phpMailer)) {
            // Send email using PHP's mail() function
            $emails = array($mailto);
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
                $mail->SMTPSecure = self::$cfg->email_SMTPSecure;
            }

            if (!empty(self::$cfg->email_Host)) {
                $mail->Host = self::$cfg->email_Host;
            }

            if (!empty(self::$cfg->email_Port)) {
                $mail->Port = self::$cfg->email_Port;
            }

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

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
}
