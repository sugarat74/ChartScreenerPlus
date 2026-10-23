<?php

namespace Tests\Feature;

use App\Mail\Transport\BrevoTransport;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class BrevoTransportTest extends TestCase
{
    public function test_it_sends_both_bodies_and_unsubscribe_headers_without_tracking_consent(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'brevo-123'], 201)]);
        $email = (new Email)
            ->from('Chartiko <no-reply@chartiko.com>')
            ->to('user@example.com')
            ->replyTo('contacto@chartiko.com')
            ->subject('Resumen')
            ->html('<p>Hola</p>')
            ->text('Hola');
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://www.chartiko.com/unsubscribe>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $sent = (new BrevoTransport('test-key'))->send($email);

        $this->assertSame('brevo-123', $sent->getMessageId());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->hasHeader('api-key', 'test-key')
            && $request['sender']['email'] === 'no-reply@chartiko.com'
            && $request['to'][0]['email'] === 'user@example.com'
            && $request['to'][0]['contactPixelTrackingConsent'] === false
            && $request['replyTo']['email'] === 'contacto@chartiko.com'
            && $request['htmlContent'] === '<p>Hola</p>'
            && $request['textContent'] === 'Hola'
            && $request['headers']['List-Unsubscribe'] === '<https://www.chartiko.com/unsubscribe>'
            && $request['headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click');
    }

    public function test_api_failure_does_not_expose_provider_response(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'user@example.com'], 403)]);
        $email = (new Email)->from('no-reply@chartiko.com')->to('user@example.com')->subject('Test')->text('Test');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Brevo API rejected the message (HTTP 403).');
        (new BrevoTransport('test-key'))->send($email);
    }
}
