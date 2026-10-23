<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/** Send Chartiko's transactional mail through Brevo without an SMTP credential. */
final class BrevoTransport extends AbstractTransport
{
    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        if ($email->getAttachments() !== []) {
            throw new TransportException('Brevo transport does not support attachments.');
        }

        $from = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();
        $payload = [
            'sender' => $this->address($from),
            'to' => array_map($this->address(...), $email->getTo()),
            'subject' => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
        ];

        if ($email->getReplyTo() !== []) {
            $payload['replyTo'] = $this->address($email->getReplyTo()[0]);
        }

        foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $field => $addresses) {
            if ($addresses !== []) {
                $payload[$field] = array_map($this->address(...), $addresses);
            }
        }

        // The digest's one-click unsubscribe headers must survive the API hop.
        foreach (['List-Unsubscribe', 'List-Unsubscribe-Post'] as $name) {
            if ($header = $email->getHeaders()->get($name)) {
                $payload['headers'][$name] = $header->getBodyAsString();
            }
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['api-key' => $this->apiKey, 'Accept' => 'application/json'])
                ->post('https://api.brevo.com/v3/smtp/email', $payload);
        } catch (Throwable) {
            // Never include the provider response: it can contain an address or link.
            throw new TransportException('Brevo API connection failed.');
        }

        if ($response->status() !== 201) {
            throw new TransportException('Brevo API rejected the message (HTTP '.$response->status().').', $response->status());
        }

        if ($id = $response->json('messageId')) {
            $message->setMessageId($id);
        }
    }

    private function address(Address $address): array
    {
        $result = ['email' => $address->getAddress()];
        $result['contactPixelTrackingConsent'] = false;
        if ($address->getName() !== '') {
            $result['name'] = $address->getName();
        }

        return $result;
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
