@extends('errors.layout')

{{-- PHP refused the whole request before the platform saw it: the files sent were larger than the server accepts. --}}
@section('code', '413')
@section('title', 'The file was too large')
@section('message', \App\Support\UploadLimit::tooBig().' Go back and choose a smaller file.')
