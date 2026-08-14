<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PadyakanMoCo — Plan a Trip</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
    <div class="min-h-screen flex flex-col md:flex-row">
        <aside class="w-full md:w-96 shrink-0 border-b md:border-b-0 md:border-r border-black/10 dark:border-white/10 p-6 flex flex-col gap-6">
            <div class="flex items-center gap-2">
                <i data-lucide="map-pin" class="w-6 h-6"></i>
                <h1 class="text-lg font-semibold">Sakay.ph</h1>
            </div>

            <form id="trip-form" class="flex flex-col gap-3">
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-foreground-secondary">From</span>
                    <input id="origin-input" type="text" value="SM North EDSA" autocomplete="off"
                        class="rounded-lg border border-black/10 dark:border-white/10 bg-transparent px-3 py-2 text-sm outline-none focus:border-foreground/40">
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-foreground-secondary">To</span>
                    <input id="destination-input" type="text" value="Ayala Avenue, Makati" autocomplete="off"
                        class="rounded-lg border border-black/10 dark:border-white/10 bg-transparent px-3 py-2 text-sm outline-none focus:border-foreground/40">
                </label>
                <button type="submit"
                    class="mt-1 inline-flex items-center justify-center gap-2 rounded-lg bg-foreground text-background px-3 py-2 text-sm font-medium">
                    <i data-lucide="search" class="w-4 h-4"></i>
                    Find route
                </button>
            </form>

            <p id="status-message" class="hidden text-sm rounded-lg border border-black/10 dark:border-white/10 px-3 py-2"></p>

            <div id="results" class="flex flex-col gap-4">
                <ol id="options-list" class="flex flex-col gap-3"></ol>

                <p class="text-xs text-foreground-secondary pt-2 border-t border-black/10 dark:border-white/10">
                    Live from <code>/api/trip-plan</code> — requires OTP to be running with a built graph.
                </p>
            </div>
        </aside>

        <main class="flex-1 relative">
            <div id="map" class="absolute inset-0"></div>
        </main>
    </div>
</body>
</html>
