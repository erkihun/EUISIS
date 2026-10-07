<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * Check, from this server, that the configured mail server accepts us.
 *
 * Verification codes are sent while the visitor waits, and some screens say
 * "sent" whatever the outcome (the public ID checker must not reveal which
 * contacts an employee holds). When codes do not arrive, this shows the
 * settings actually in effect — System Settings › Email over .env — and the
 * mail server's own answer. It connects and signs in; it sends nothing,
 * unless --to is given.
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check {--to= : Also send a test message to this address}';

    protected $description = 'Connect to the configured mail server and report its answer (sends nothing without --to)';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $smtp = (array) config('mail.mailers.smtp');

        $this->table(['Setting', 'In effect'], [
            ['Mailer', $mailer],
            ['Host', (string) ($smtp['host'] ?? '')],
            ['Port', (string) ($smtp['port'] ?? '')],
            ['Connection', ($smtp['scheme'] ?? 'smtp') === 'smtps' ? 'smtps (TLS from the start)' : 'smtp'.(($smtp['auto_tls'] ?? true) ? ' (STARTTLS when offered)' : ' (no encryption)')],
            ['Username', filled($smtp['username'] ?? null) ? 'set' : 'not set'],
            ['From', (string) config('mail.from.address')],
            ['Timeout', ($smtp['timeout'] ?? 'none').' s'],
        ]);

        if ($mailer !== 'smtp') {
            $this->warn("The mailer is \"{$mailer}\", so nothing goes to a mail server.".($mailer === 'log' ? ' Messages are written to the log file.' : ''));

            return self::FAILURE;
        }

        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        if (! $transport instanceof EsmtpTransport) {
            $this->error('The SMTP mailer did not produce an SMTP transport.');

            return self::FAILURE;
        }

        try {
            $transport->start();
            $this->info('Connected and signed in to the mail server.');
            $transport->stop();
        } catch (Throwable $exception) {
            $this->error('The mail server could not be used: '.$exception->getMessage());
            $this->line('Usual causes: the port is blocked by this server\'s firewall or host, the port and encryption do not match (465 = SSL; 587 or 25 = TLS), or the username or password is wrong.');

            return self::FAILURE;
        }

        $to = $this->option('to');

        if (is_string($to) && $to !== '') {
            try {
                Mail::raw('This is a test message from '.config('app.name').'. If you received it, email delivery works.', function ($message) use ($to): void {
                    $message->to($to)->subject(config('app.name').' test email');
                });
                $this->info("Test message accepted by the mail server for {$to}. Check that it arrives (and the spam folder).");
            } catch (Throwable $exception) {
                $this->error('The test message was refused: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
