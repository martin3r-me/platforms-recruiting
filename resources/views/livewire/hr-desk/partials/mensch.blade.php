<div class="text-sm font-semibold text-gray-900">{{ $z['name'] }}</div>
<div class="mt-0.5 flex flex-wrap gap-1">
    @foreach ($z['akten'] as $akte)
        @php
            $aktenKlasse = $akte['aktiv'] ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500 line-through';
        @endphp
        <a href="{{ route('recruiting.employees.show', ['employee' => $akte['id']]) }}" class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold {{ $aktenKlasse }}">{{ $akte['firma'] }}</a>
    @endforeach
</div>
