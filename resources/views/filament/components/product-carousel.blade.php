@php
    // In Filament ViewEntry, the evaluated state from getStateUsing() is available via $getRecord() or $getState()
    $state = $getState() ?? [];
    $images = is_array($state) ? $state : [];
@endphp

@if (empty($images))
    <span class="text-gray-500">Sem imagens</span>
@else
    @php
        $firstImage = $images[0];
    @endphp
    <div x-data="{ mainImage: '{{ addslashes($firstImage) }}' }" class="flex flex-col gap-4 py-4 w-full">
        <!-- Main Image -->
        <div class="w-full flex justify-center bg-gray-50 dark:bg-gray-900 rounded-xl overflow-hidden shadow-sm border border-gray-200 dark:border-gray-800"
            style="height: 400px;">
            <img :src="mainImage" class="h-full object-contain transition-opacity duration-300" />
        </div>

        <!-- Thumbnails -->
        <div class="flex flex-wrap gap-2 w-full justify-start items-center">
            @foreach ($images as $img)
                @php
                    $imgSafe = addslashes($img);
                @endphp
                <div @click="mainImage = '{{ $imgSafe }}'"
                    class="h-20 w-20 rounded-md cursor-pointer border-2 transition-all overflow-hidden bg-white dark:bg-gray-900 flex items-center justify-center"
                    :class="mainImage === '{{ $imgSafe }}' ? 'border-primary-500 shadow-md scale-105' : 'border-transparent hover:border-gray-300'">
                    <img src="{{ $img }}" class="h-full w-full object-contain" />
                </div>
            @endforeach
        </div>
    </div>
@endif
