{{-- Daily alert email, plain-text part (alerts-email-digest). --}}
{!! __('alert_email.heading', ['date' => $digest->date]) !!}

{!! __('alert_email.intro') !!}
@foreach ($digest->sections as $section)

{!! $section['title'] !!}
@foreach ($section['items'] as $item)
- {!! $item['ticker'] !!}{!! $item['name'] ? ' '.$item['name'] : '' !!} — {!! $item['label'] !!}
  {!! $item['url'] !!}
@endforeach
@if ($section['more'] > 0)
  {!! __('alert_email.more', ['count' => $section['more']]) !!}
@endif
@endforeach

{!! __('alert_email.open_inbox') !!}: {!! $inboxUrl !!}
{!! __('alert_email.manage') !!}: {!! $portalUrl !!}

--
{!! __('alert_email.disclaimer') !!}
{!! __('alert_email.why') !!}
{!! __('alert_email.unsubscribe') !!}: {!! $unsubscribeUrl !!}
{!! __('alert_email.privacy') !!}: {!! $privacyUrl !!}
