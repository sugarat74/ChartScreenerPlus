{{-- Email address confirmation, HTML part (auth-email-verification). No
     user-supplied text: the only variable is the signed link. --}}
@extends('mail.layouts.account')

@section('title', __('account_email.verify.heading'))

@section('heading', __('account_email.verify.heading'))

@section('body')
<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('account_email.verify.intro') }}</p>
<p style="margin:0 0 20px;"><a href="{{ $url }}" style="display:inline-block;background:#ffcc00;color:#1a1a1a;border:2px solid #1a1a1a;padding:10px 16px;font-size:14px;font-weight:bold;text-decoration:none;">{{ __('account_email.verify.button') }}</a></p>
<p style="margin:0 0 4px;font-size:13px;line-height:1.5;color:#444444;">{{ __('account_email.verify.fallback') }}</p>
<p style="margin:0 0 16px;font-size:12px;line-height:1.5;word-break:break-all;"><a href="{{ $url }}" style="color:#1a1a1a;">{{ $url }}</a></p>
<p style="margin:0;font-size:13px;line-height:1.5;color:#444444;">{{ __('account_email.verify.expires') }}</p>
@endsection
