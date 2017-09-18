<?php
/**
 * @filesource Kotchasan/Email.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\Language;

/**
 * Email function
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Email extends \Kotchasan\Model
{
  protected $error;

  /**
   * ฟังก์ชั่นส่งเมล์แบบกำหนดรายละเอียดเอง
   *
   * @param string $mailto ที่อยู่อีเมล์ผู้รับ  คั่นแต่ละรายชื่อด้วย ,
   * @param string $replyto ที่อยู่อีเมล์สำหรับการตอบกลับจดหมาย ถ้าระบุเป็นค่าว่างจะใช้ที่อยู่อีเมล์จาก noreply_email
   * @param string $subject หัวข้อจดหมาย
   * @param string $msg รายละเอียดของจดหมาย (รองรับ HTML)
   * @return \static
   */
  public static function send($mailto, $replyto, $subject, $msg)
  {
    // create class
    $obj = new static;
    $obj->error = array();
    // send email
    $charset = empty(self::$cfg->email_charset) ? 'utf-8' : strtolower(self::$cfg->email_charset);
    if (empty($replyto)) {
      $replyto = array(self::$cfg->noreply_email, strip_tags(self::$cfg->web_title));
    } elseif (preg_match('/^(.*)<(.*?)>$/', $replyto, $match)) {
      $replyto = array($match[1], (empty($match[2]) ? $match[1] : $match[2]));
    } else {
      $replyto = array($replyto, $replyto);
    }
    if ($charset != 'utf-8') {
      $subject = iconv('utf-8', $charset, $subject);
      $msg = iconv('utf-8', $charset, $msg);
      $replyto[1] = iconv('utf-8', $charset, $replyto[1]);
    }
    $msg = preg_replace(array('/<\?/', '/\?>/'), array('&lt;?', '?&gt;'), $msg);
    if (empty(self::$cfg->email_use_phpMailer)) {
      // ส่งอีเมล์ด้วยฟังก์ชั่นของ PHP
      foreach (explode(',', $mailto) as $email) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=".strtoupper($charset)."\r\n";
        $headers .= "From: ".strip_tags($replyto[1])."\r\n";
        $headers .= "Reply-to: $replyto[0]\r\n";
        if (!@mail($email, $subject, $msg, $headers)) {
          $obj->error['Unable to send mail'] = Language::get('Unable to send mail');
        }
      }
    } else {
      // ส่งอีเมล์ด้วย PHPMailer
      include_once VENDOR_DIR.'PHPMailer/class.phpmailer.php';
      // Create a new PHPMailer instance
      $mail = new \PHPMailer;
      // Tell PHPMailer to use SMTP
      $mail->isSMTP();
      // charset
      $mail->CharSet = $charset;
      // use html
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
      $mail->AddReplyTo($replyto[0], $replyto[1]);
      if ($mail->ValidateAddress(self::$cfg->noreply_email)) {
        $mail->SetFrom(self::$cfg->noreply_email, strip_tags(self::$cfg->web_title));
      }
      // subject
      $mail->Subject = $subject;
      // message
      $mail->MsgHTML(preg_replace('/(<br([\s\/]{0,})>)/', "$1\r\n", $msg));
      $mail->AltBody = strip_tags($msg);
      foreach (explode(',', $mailto) as $email) {
        if (preg_match('/^(.*)<(.*)>$/', $email, $match)) {
          if ($mail->ValidateAddress($match[1])) {
            $mail->AddAddress($match[1], $match[2]);
          }
        } else {
          if ($mail->ValidateAddress($email)) {
            $mail->AddAddress($email, $email);
          }
        }
        if (false === $mail->send()) {
          $obj->error[$mail->ErrorInfo] = $mail->ErrorInfo;
        }
        $mail->clearAddresses();
      }
    }
    return $obj;
  }

  /**
   * ตรวจสอบว่ามีข้อผิดพลาดในการส่งอีเมล์หรือไม่
   *
   * @return boolean คืนค่า true ถ้ามี error, false ถ้าส่งอีเมล์สำเร็จ
   */
  public function error()
  {
    return empty($this->error) ? false : true;
  }

  /**
   * คืนค่าข้อผิดพลาดการส่งอีเมล์
   *
   * @return string ถ้าไม่มีข้อผิดพลาดคืนค่าข้อความว่าง
   */
  public function getErrorMessage()
  {
    return empty($this->error) ? '' : implode("\n", $this->error);
  }
}
