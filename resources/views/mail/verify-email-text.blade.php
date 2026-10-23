{{-- Email address confirmation, plain-text part (auth-email-verification). --}}
@extends('mail.layouts.account-text')

@section('heading')
{!! __('account_email.verify.heading') !!}
@endsection

@section('body')
{!! __('account_email.verify.intro') !!}

{!! __('account_email.verify.button') !!}: {!! $url !!}

{!! __('account_email.verify.expires') !!}
@endsection
