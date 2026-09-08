import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            /*
             * Inter (Latin) and IBM Plex Sans Arabic are a matched pairing —
             * comparable x-height and weight axis — not a default stack with
             * Arabic bolted on. Both are self-hosted at build time rather than
             * pulled from a CDN at runtime: one less third party, and no
             * cross-origin request on a first paint from a phone on mobile data.
             *
             * Both load on both locales: the wordmark stays Latin under RTL, and
             * the language switcher renders the other language in its own script.
             */
            fonts: [
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                }),
                /*
                 * `subsets` is not optional here. The provider defaults to the
                 * latin subset, which ships @font-face rules whose unicode-range
                 * excludes U+0600–06FF — so every Arabic glyph silently falls
                 * back to a system font and the pairing never actually loads.
                 */
                bunny('IBM Plex Sans Arabic', {
                    weights: [400, 500, 600, 700],
                    subsets: ['arabic', 'latin'],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
