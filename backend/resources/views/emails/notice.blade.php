<x-mail::message>
# {{ $title }}

{{ $body }}

<x-mail::button :url="$url">
{{ $action }}
</x-mail::button>

Email ini dikirim otomatis oleh {{ config('attendance.instance_name', 'Kartu Anggota') }}.
</x-mail::message>
