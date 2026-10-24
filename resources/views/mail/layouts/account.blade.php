{{-- Shared frame of the account emails, HTML part (auth-email-verification;
     reused by auth-password-reset). Same look as the alert digest; no images,
     no application tracking; Brevo adds the disclosed opening pixel. Sections: `title`, `heading`, `body`, optional `ignore` and `why`
     (default: the account-creation lines). Needs `$privacyUrl`. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>@yield('title')</title>
</head>
<body style="margin:0;padding:0;background:#f4f1ea;color:#1a1a1a;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:2px solid #1a1a1a;">
<tr><td style="padding:16px 20px;border-bottom:2px solid #1a1a1a;">
<span style="display:inline-block;background:#ffcc00;border:2px solid #1a1a1a;padding:2px 8px;font-weight:bold;font-size:14px;">CHARTIKO</span>
</td></tr>
<tr><td style="padding:20px;">
<h1 style="margin:0 0 12px;font-size:20px;">@yield('heading')</h1>
@yield('body')
</td></tr>
<tr><td style="padding:16px 20px;border-top:2px solid #1a1a1a;font-size:12px;line-height:1.5;color:#444444;">
<p style="margin:0 0 8px;">@yield('ignore', __('account_email.layout.ignore'))</p>
<p style="margin:0 0 8px;">@yield('why', __('account_email.layout.why'))</p>
<p style="margin:0;"><a href="{{ $privacyUrl }}" style="color:#1a1a1a;">{{ __('account_email.layout.privacy') }}</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
