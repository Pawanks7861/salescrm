@extends('errors.layout')

@section('code', '419')
@section('title', 'Session expired')
@section('message', 'Your session expired for security reasons. Please reload the page and try again.')

@section('actions')
    <a class="btn primary" href="{{ url('/') }}">Reload</a>
@endsection
