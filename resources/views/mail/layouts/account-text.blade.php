{{-- Shared frame of the account emails, plain-text part (auth-email-verification;
     reused by auth-password-reset). Sections: `heading`, `body`, optional `ignore` and `why`. --}}
@yield('heading')

@yield('body')

--
@hasSection('ignore')
@yield('ignore')
@else
{!! __('account_email.layout.ignore') !!}
@endif
@hasSection('why')
@yield('why')
@else
{!! __('account_email.layout.why') !!}
@endif
{!! __('account_email.layout.privacy') !!}: {!! $privacyUrl !!}
