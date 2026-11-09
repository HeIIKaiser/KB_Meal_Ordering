@extends('errors.layout')

@section('code', '419')
@section('title', 'Stránka vypršala')
@section('message', 'Stránka bola otvorená príliš dlho. Načítajte ju znova a akciu zopakujte.')
@section('action')
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}">Načítať znova</a>
@endsection
