{{-- Operator test email, HTML part (alerts-email-golive, `php artisan mail:test`).
     No user-supplied text and no images: one plain site link and the privacy link. --}}
@extends('mail.layouts.account')

@section('title', __('account_email.test.heading'))

@section('heading', __('account_email.test.heading'))

@section('body')
<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ __('account_email.test.intro') }}</p>
<p style="margin:0;font-size:14px;line-height:1.5;">{{ __('account_email.test.site') }} <a href="{{ $siteUrl }}" style="color:#1a1a1a;">{{ $siteUrl }}</a></p>
@endsection

@section('ignore', __('account_email.test.ignore'))

@section('why', __('account_email.test.why'))
