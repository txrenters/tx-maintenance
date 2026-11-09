@php
    $building = $workOrder->building;
    $address = collect([
        $building?->address,
        $building?->city,
        trim(($building?->state_region ?? '').' '.($building?->postal_code ?? '')),
    ])->filter()->implode(', ');
    $heading = $workOrder->work_order_no ? "WO#{$workOrder->work_order_no} — {$title}" : $title;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $heading }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; line-height: 1.45; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #555; margin: 0 0 2px; }
        hr { border: 0; border-top: 1px solid #ccc; margin: 10px 0 14px; }
        .message { border: 1px solid #ddd; border-left: 4px solid #9ca3af; padding: 7px 9px; margin: 0 0 9px; page-break-inside: avoid; }
        .message.out { border-left-color: #2563eb; background: #f5f8ff; }
        .head { width: 100%; border-collapse: collapse; }
        .head td { padding: 0; vertical-align: top; }
        .head .when { text-align: right; color: #555; font-size: 9.5px; white-space: nowrap; }
        .to { color: #555; font-size: 9.5px; }
        .body { margin-top: 4px; }
        .failed { color: #b91c1c; font-weight: bold; }
        img { display: block; max-width: 240px; max-height: 240px; margin-top: 6px; }
        .file { margin-top: 5px; font-size: 9.5px; color: #333; }
        .empty { color: #777; font-style: italic; }
    </style>
</head>
<body>
    <h1>{{ $heading }}</h1>
    @if ($address)
        <p class="meta">{{ $address }}</p>
    @endif
    @if ($participants->isNotEmpty())
        <p class="meta">Participants: {{ $participants->map(fn ($person) => $person['role'] ? "{$person['name']} ({$person['role']})" : $person['name'])->implode(', ') }}</p>
    @endif
    <p class="meta">{{ $messages->count() }} {{ Str::plural('message', $messages->count()) }} · Downloaded {{ $printedAt->format('M j, Y g:i A') }} (Central)</p>
    <hr>

    @forelse ($messages as $message)
        <div class="message {{ $message['outbound'] ? 'out' : 'in' }}">
            <table class="head">
                <tr>
                    <td><strong>{{ $message['from'] }}</strong></td>
                    <td class="when">{{ $message['sent_at'] }}</td>
                </tr>
            </table>
            <div class="to">
                To: {{ $message['to'] }}
                @if ($message['failed'])
                    <span class="failed">Not delivered ({{ $message['failed'] }})</span>
                @endif
            </div>
            <div class="body">{!! nl2br(e($message['body'])) !!}</div>
            @foreach ($message['images'] as $image)
                <img src="{{ $image }}" alt="Attached image">
            @endforeach
            @foreach ($message['files'] as $file)
                <div class="file">Attachment: {{ $file }}</div>
            @endforeach
        </div>
    @empty
        <p class="empty">No messages in this conversation.</p>
    @endforelse
</body>
</html>
