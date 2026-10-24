@extends('mail.layouts.account-text')

@section('heading')
{!! __('account_email.reset.heading') !!}
@endsection

@section('body')
{!! __('account_email.reset.intro') !!}

{!! __('account_email.reset.button') !!}: {!! $url !!}

{!! __('account_email.reset.expires') !!}
@endsection

@section('ignore')
{!! __('account_email.reset.ignore') !!}
@endsection

@section('why')
{!! __('account_email.reset.why') !!}
@endsection
