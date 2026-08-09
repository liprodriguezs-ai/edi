@extends('module::layouts.master')

@section('content')
    <h1>Hello World</h1>

    <p>Module: {!! config('module.name') !!}</p>
@endsection
