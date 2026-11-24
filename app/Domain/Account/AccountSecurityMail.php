<?php

namespace App\Domain\Account;

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

final class AccountSecurityMail
{
    public function isAvailable(): bool
    {
        if (! config('account-security.mail_enabled') || config('mail.default') !== 'smtp') {
            return false;
        }

        $origin = (string) config('app.url');
        $parts = parse_url($origin);
        $scheme = $parts['scheme'] ?? null;
        if (! filter_var($origin, FILTER_VALIDATE_URL)
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || ($scheme !== 'https' && ($scheme !== 'http' || ! app()->environment('local', 'testing')))) {
            return false;
        }

        $smtp = config('mail.mailers.smtp', []);
        try {
            if (isset($smtp['url'])) {
                $smtp = (new ConfigurationUrlParser)->parseConfiguration($smtp);
                $smtp['transport'] = $smtp['driver'] ?? null;
            }
        } catch (InvalidArgumentException) {
            return false;
        }

        $from = $smtp['from']['address'] ?? config('mail.from.address');

        return ($smtp['transport'] ?? null) === 'smtp'
            && filled($smtp['host'] ?? null)
            && in_array($smtp['scheme'] ?? null, [null, 'smtp', 'smtps'], true)
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && strcasecmp($from, 'hello@example.com') !== 0
            && blank($smtp['to']['address'] ?? config('mail.to.address'));
    }

    public function urls(): UrlGenerator
    {
        $origin = (string) config('app.url');
        $generator = clone URL::getFacadeRoot();
        $generator->setRequest(Request::create($origin));
        $generator->useOrigin($origin);
        $generator->forceScheme(parse_url($origin, PHP_URL_SCHEME));

        return $generator;
    }
}
