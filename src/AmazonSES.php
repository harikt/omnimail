<?php

namespace Omnimail;

use Omnimail\Exception\Exception;
use Psr\Log\LoggerInterface;
use SimpleEmailServiceMessage;
use SimpleEmailService;

class AmazonSES implements EmailSenderInterface
{
    const AWS_US_EAST_1 = 'email.us-east-1.amazonaws.com';
    const AWS_US_WEST_2 = 'email.us-west-2.amazonaws.com';
    const AWS_EU_WEST1 = 'email.eu-west-1.amazonaws.com';

    protected $accessKey;
    protected $secretKey;
    protected $host;
    protected $logger;

    public $verifyPeer = true;
	public $verifyHost = true;

    /**
     * @param string $accessKey
     * @param string $secretKey
     * @param string $host
     * @param LoggerInterface|null $logger
     */
    public function __construct($accessKey, $secretKey, $host = self::AWS_US_EAST_1, LoggerInterface $logger = null)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->host = $host;
        $this->logger = $logger;
    }

    public function send(EmailInterface $email)
    {
        $m = new SimpleEmailServiceMessage();
        $m->addTo($this->mapEmails($email->getTos()));
        $m->setFrom($this->mapEmail($email->getFrom()));

        if ($email->getReplyTos()) {
            $m->addReplyTo($this->mapEmails($email->getReplyTos()));
        }

        if ($email->getCcs()) {
            $m->addCC($this->mapEmails($email->getCcs()));
        }

        if ($email->getBccs()) {
            $m->addBCC($this->mapEmails($email->getBccs()));
        }

        $m->setSubject($email->getSubject());
        $m->setMessageFromString($email->getTextBody(), $email->getHtmlBody());

        if ($email->getAttachments()) {
            foreach ($email->getAttachments() as $attachment) {
                if (!$attachment->getPath() && $attachment->getContent()) {
                    $m->addAttachmentFromData(
                        $attachment->getName(),
                        $attachment->getContent(),
                        $attachment->getMimeType(),
                        $attachment->getContentId(),
                        $attachment->getContentId() ? 'inline' : 'attachment'
                    );
                } elseif ($attachment->getPath()) {
                    $m->addAttachmentFromFile(
                        $attachment->getName(),
                        $attachment->getPath(),
                        $attachment->getMimeType(),
                        $attachment->getContentId(),
                        $attachment->getContentId() ? 'inline' : 'attachment'
                    );
                }
            }
        }

        $ses = new SimpleEmailService($this->accessKey, $this->secretKey, $this->host, false);
        $ses->setVerifyPeer($this->verifyPeer);
        $ses->setVerifyHost($this->verifyHost);
        $response = $ses->sendEmail($m, false, false);

        if (is_object($response) && isset($response->error)) {
            $message = isset($response->error['Error']['Message']) ?
                $response->error['Error']['Message'] : $response->error['message'];

            if ($this->logger) {
                $this->logger->error("Error: ", $message);
            }
            throw new Exception("Error: " . $message, 603);
        } elseif (empty($response['MessageId'])) {
            if ($this->logger) {
                $this->logger->error("Email error: Unknown error", $email->toArray());
            }
            throw new Exception('Unknown error', 603);
        } else {
            if ($this->logger) {
                $this->logger->info("Email sent: '{$email->getSubject()}'", $email->toArray());
            }
        }
    }

    /**
     * @param array $emails
     * @return string
     */
    private function mapEmails(array $emails)
    {
        $returnValue = [];
        foreach ($emails as $email) {
            $returnValue[] = $this->mapEmail($email);
        }
        return $returnValue;
    }

    /**
     * @param array $email
     * @return string
     */
    private function mapEmail(array $email)
    {
        return !empty($email['name']) ? "'{$email['name']}' <{$email['email']}>" : $email['email'];
    }
}
