<div class="space-y-3 text-sm">
    @if ($record->error_message)
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">
            <span class="font-semibold">{{ $record->error_code }}:</span>
            {{ $record->error_message }}
        </div>
    @endif

    @forelse ($record->attempts as $attempt)
        <div class="border-s-2 border-gray-200 ps-3 dark:border-gray-700">
            <p class="font-medium text-gray-900 dark:text-white">
                {{ $attempt->created_at->format('Y-m-d H:i:s') }}
                · {{ $attempt->channel }}
                · {{ __('notifications.provider_statuses.'.$attempt->status) }}
            </p>
            @if ($attempt->provider_message_id)
                <p class="text-gray-600 dark:text-gray-300">{{ __('notifications.settings.provider_id') }}: {{ $attempt->provider_message_id }}</p>
            @endif
            @if ($attempt->error_code || $attempt->error_message)
                <p class="text-gray-600 dark:text-gray-300">{{ $attempt->error_code }} {{ $attempt->error_message }}</p>
            @endif
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">{{ __('notifications.settings.no_attempts') }}</p>
    @endforelse
</div>
