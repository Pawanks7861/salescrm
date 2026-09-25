@extends('errors.layout')

@section('code', '503')
@section('title', 'Down for maintenance')
@section('message', 'The CRM is being updated. Please check back in a few minutes.')

@section('actions')
    <a class="btn primary" href="{{ url('/') }}">Try again</a>
@endsection
