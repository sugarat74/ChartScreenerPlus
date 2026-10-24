{{-- Password recovery, HTML part. The shared account frame includes privacy. --}}
@extends('mail.layouts.account')

@section('title', __('account_email.reset.heading'))
@section('heading', __('account_email.reset.heading'))
@section('ignore', __('account_email.reset.ignore'))
@section('why', __('account_email.reset.why'))

@section('body')
<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('account_email.reset.intro') }}</p>
<p style="margin:0 0 20px;"><a href="{{ $url }}" style="display:inline-block;background:#ffcc00;color:#1a1a1a;border:2px solid #1a1a1a;padding:10px 16px;font-size:14px;font-weight:bold;text-decoration:none;">{{ __('account_email.reset.button') }}</a></p>
<p style="margin:0 0 4px;font-size:13px;line-height:1.5;color:#444444;">{{ __('account_email.reset.fallback') }}</p>
<p style="margin:0 0 16px;font-size:12px;line-height:1.5;word-break:break-all;"><a href="{{ $url }}" style="color:#1a1a1a;">{{ $url }}</a></p>
<p style="margin:0;font-size:13px;line-height:1.5;color:#444444;">{{ __('account_email.reset.expires') }}</p>
@endsection
