{{-- Daily alert email, HTML part (alerts-email-digest). No images, no tracking. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('alert_email.heading', ['date' => $digest->date]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f1ea;color:#1a1a1a;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:2px solid #1a1a1a;">
<tr><td style="padding:16px 20px;border-bottom:2px solid #1a1a1a;">
<span style="display:inline-block;background:#ffcc00;border:2px solid #1a1a1a;padding:2px 8px;font-weight:bold;font-size:14px;">CHARTIKO</span>
</td></tr>
<tr><td style="padding:20px;">
<h1 style="margin:0 0 8px;font-size:20px;">{{ __('alert_email.heading', ['date' => $digest->date]) }}</h1>
<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('alert_email.intro') }}</p>
@foreach ($digest->sections as $section)
<h2 style="margin:20px 0 8px;font-size:16px;">{{ $section['title'] }}</h2>
<ul style="margin:0;padding:0 0 0 18px;font-size:14px;line-height:1.6;">
@foreach ($section['items'] as $item)
<li><a href="{{ $item['url'] }}" style="color:#1a1a1a;font-weight:bold;">{{ $item['ticker'] }}</a>@if ($item['name']) <span style="color:#555555;">{{ $item['name'] }}</span>@endif — {{ $item['label'] }}</li>
@endforeach
@if ($section['more'] > 0)
<li style="list-style:none;color:#555555;">{{ __('alert_email.more', ['count' => $section['more']]) }}</li>
@endif
</ul>
@endforeach
<p style="margin:24px 0 8px;font-size:14px;"><a href="{{ $inboxUrl }}" style="color:#1a1a1a;font-weight:bold;">{{ __('alert_email.open_inbox') }}</a></p>
<p style="margin:0;font-size:14px;"><a href="{{ $portalUrl }}" style="color:#1a1a1a;">{{ __('alert_email.manage') }}</a></p>
</td></tr>
<tr><td style="padding:16px 20px;border-top:2px solid #1a1a1a;font-size:12px;line-height:1.5;color:#444444;">
<p style="margin:0 0 8px;">{{ __('alert_email.disclaimer') }}</p>
<p style="margin:0 0 8px;">{{ __('alert_email.why') }}</p>
<p style="margin:0;"><a href="{{ $unsubscribeUrl }}" style="color:#1a1a1a;">{{ __('alert_email.unsubscribe') }}</a> · <a href="{{ $privacyUrl }}" style="color:#1a1a1a;">{{ __('alert_email.privacy') }}</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
