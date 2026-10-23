{{-- Operator test email, plain-text part (alerts-email-golive, `php artisan mail:test`). --}}
@extends('mail.layouts.account-text')

@section('heading')
{!! __('account_email.test.heading') !!}
@endsection

@section('body')
{!! __('account_email.test.intro') !!}

{!! __('account_email.test.site') !!} {!! $siteUrl !!}
@endsection

@section('ignore')
{!! __('account_email.test.ignore') !!}
@endsection

@section('why')
{!! __('account_email.test.why') !!}
@endsection
