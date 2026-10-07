@extends('errors.layout')

{{-- The platform's refusals say why (see the policies); that reason is shown here. --}}
@section('code', '403')
@section('title', 'This is not open to you')
@section('message', $exception->getMessage() !== '' && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : 'Your role does not give access to this page. If you think it should, ask one of the Administrators.')
