<?php

namespace Omnimail;

use Omnimail\Exception\InvalidRequestException;
use Omnimail\Exception\UnauthorizedException;
use Psr\Log\LoggerInterface;
use SendGrid\Email as SendGridEmail;
use SendGrid\Content;
use SendGrid\Mail;
use SendGrid\Attachment as SendGridAttachment;
use SendGrid\Personalization;
use SendGrid\Response;

class Sendgrid implements EmailSenderInterface
{
    private $apiKey;
    private $logger;

    /**
     * @param string $apiKey
     * @param LoggerInterface|null $logger
     */
    public function __construct($apiKey, LoggerInterface $logger = null)
    {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
    }

    public function send(EmailInterface $email)
    {
        $content = null;
        if ($email->getHtmlBody()) {
            $content = new Content("text/html", $email->getHtmlBody());
        } elseif ($email->getTextBody()) {
            $content = new Content("text/plain", $email->getTextBody());
        }

        $mail = new Mail();
        $mail->setFrom($this->mapEmail($email->getFrom()));
        $mail->setSubject($email->getSubject());
        $mail->addContent($content);

        $personalization = new Personalization();

        foreach ($email->getTos() as $recipient) {
            $personalization->addTo($this->mapEmail($recipient));
        }

        if ($email->getReplyTos()) {
            foreach ($email->getReplyTos() as $recipient) {
                $mail->setReplyTo($this->mapEmail($recipient));
                break; // only one reply to
            }
        }

        if ($email->getCcs()) {
            foreach ($email->getCcs() as $recipient) {
                $personalization->addCc($this->mapEmail($recipient));
            }
        }

        if ($email->getBccs()) {
            foreach ($email->getBccs() as $recipient) {
                $personalization->addBcc($this->mapEmail($recipient));
            }
        }

        if ($email->getAttachements()) {
            foreach ($email->getAttachements() as $attachement) {
                $finalAttachment = new SendGridAttachment();
                $finalAttachment->setType($attachement->getMimeType());
                $finalAttachment->setFilename($attachement->getName());
                if (!$attachement->getPath() && $attachement->getContent()) {
                    $finalAttachment->setContent(base64_encode($attachement->getContent()));
                } elseif ($attachement->getPath()) {
                    $finalAttachment->setContent(base64_encode(file_get_contents($attachement->getPath())));
                }
                $mail->addAttachment($finalAttachment);
            }
        }

        $mail->addPersonalization($personalization);
        $sg = new \SendGrid($this->apiKey);
        /** @var Response $response */
        $response = $sg->client->mail()->send()->post($mail);

        if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
            if ($this->logger) {
                $this->logger->info("Email sent: '{$email->getSubject()}'", $email);
            }
        } else {
            $content = json_decode($response->body(), true);
            $error = null;
            if (isset($content['errors']) && is_array($content['errors']) && isset($content['errors'][0])) {
                $error = $content['errors'][0]['message'];
            }
            switch ($response->statusCode()) {
                case 401:
                    if ($this->logger) {
                        $this->logger->info("Email error: 'unauthorized'", $email);
                    }
                    throw new UnauthorizedException($error);
                default:
                    if ($this->logger) {
                        $this->logger->info("Email error: 'invalid request'", $email);
                    }
                    throw new InvalidRequestException($error);
            }
        }
    }

    /**
     * @param $email
     * @return Email
     */
    private function mapEmail($email)
    {
        return new SendGridEmail(isset($email['name']) ? $email['name'] : null, $email['email']);
    }
}
