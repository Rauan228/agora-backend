@extends('admin.layout')

@section('title', 'Новый поставщик')

@section('content')
<h1 class="text-2xl font-bold mb-4">Новый поставщик</h1>

<div class="bg-white rounded-lg shadow p-6">
    <form method="POST" action="{{ route('admin.suppliers.store') }}" enctype="multipart/form-data">
        @include('admin.suppliers._form')
    </form>
</div>
@endsection
