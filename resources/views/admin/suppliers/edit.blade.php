@extends('admin.layout')

@section('title', 'Редактирование поставщика')

@section('content')
<h1 class="text-2xl font-bold mb-4">Редактирование: {{ $supplier->commercial_name }}</h1>

<div class="bg-white rounded-lg shadow p-6">
    <form method="POST" action="{{ route('admin.suppliers.update', $supplier) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('admin.suppliers._form')
    </form>
</div>
@endsection
