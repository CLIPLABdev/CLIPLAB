<?php
declare(strict_types=1);
namespace App\Communications;
use App\Contracts\Mailer; use InvalidArgumentException;
final class EmailTestSendService {public function __construct(private Mailer $mailer){} public function send(string $adminEmail,string $recipient,string $subject,string $html):void{$adminEmail=mb_strtolower(trim($adminEmail));$recipient=mb_strtolower(trim($recipient));if(filter_var($adminEmail,FILTER_VALIDATE_EMAIL)===false||!hash_equals($adminEmail,$recipient)||str_contains($subject,"\r")||str_contains($subject,"\n"))throw new InvalidArgumentException('Test recipient is invalid.');$this->mailer->send($recipient,$subject,$html);}}
