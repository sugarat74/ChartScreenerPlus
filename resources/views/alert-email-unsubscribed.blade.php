{{-- Signed unsubscribe page (alerts-email-digest): a confirm button on GET, the result after POST. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $confirmed ? __('alert_email.unsubscribed_title') : __('alert_email.confirm_title') }} · Chartiko</title>
<style>
  :root { color-scheme: light dark; --bg: #f4f1ea; --surface: #ffffff; --ink: #1a1a1a; }
  @media (prefers-color-scheme: dark) { :root { --bg: #161513; --surface: #1f1e1b; --ink: #f2efe8; } }
  body { margin: 0; background: var(--bg); color: var(--ink); font-family: Arial, Helvetica, sans-serif; }
  main { max-width: 560px; margin: 48px auto; padding: 0 16px; }
  .card { background: var(--surface); border: 2px solid var(--ink); padding: 24px; }
  .brand { display: inline-block; background: #ffcc00; color: #1a1a1a; border: 2px solid #1a1a1a; padding: 2px 8px; font-weight: bold; }
  h1 { font-size: 22px; margin: 16px 0 8px; }
  p { line-height: 1.5; }
  a { color: var(--ink); font-weight: bold; }
  button { font: inherit; font-weight: bold; background: #ffcc00; color: #1a1a1a; border: 2px solid #1a1a1a; padding: 8px 16px; cursor: pointer; }
</style>
</head>
<body>
<main>
  <div class="card">
    <span class="brand">CHARTIKO</span>
    @if ($confirmed)
    <h1>{{ __('alert_email.unsubscribed_title') }}</h1>
    <p>{{ __('alert_email.unsubscribed_body') }}</p>
    @else
    <h1>{{ __('alert_email.confirm_title') }}</h1>
    <p>{{ __('alert_email.confirm_body') }}</p>
    <form method="post" action="{{ $action }}">
      <button type="submit">{{ __('alert_email.confirm_button') }}</button>
    </form>
    @endif
    <p><a href="{{ $siteUrl }}">{{ __('alert_email.back_to_site') }}</a></p>
  </div>
</main>
</body>
</html>
