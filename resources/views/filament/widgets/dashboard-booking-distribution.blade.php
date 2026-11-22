@if ($rows === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyMessage }}</p>
@else
    @php($largestCount = max(array_column($rows, 'count')))

    <ul class="grid gap-y-4">
        @foreach ($rows as $row)
            <li class="grid gap-y-2">
                <div class="flex items-center justify-between gap-4 text-sm">
                    <span class="break-words text-gray-950 dark:text-white">
                        {{ $row['id'] === null ? ($unassignedLabel ?? $unnamedLabel) : ($row['name'] ?? $unnamedLabel) }}
                    </span>
                    <span class="font-semibold text-gray-950 dark:text-white">
                        {{ $row['count'] }}
                    </span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true">
                    <div
                        class="h-full rounded-full bg-primary-500"
                        style="width: {{ 100 * $row['count'] / $largestCount }}%"
                    ></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
